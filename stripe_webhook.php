<?php
// Rinomina questo file in stripe_webhook.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "stripe-php/init.php";
require_once "config/config.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// La tua chiave segreta dell'endpoint webhook
$endpoint_secret = 'whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ'; // Assicurati sia quella corretta
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$payload = @file_get_contents('php://input');

if (!$payload) {
    http_response_code(400);
    exit('Payload vuoto');
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit("⚠️ Payload non valido");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit("⚠️ Firma non valida");
}

function logStripe($msg)
{
    file_put_contents("stripe_debug.log", date("[Y-m-d H:i:s] ") . $msg . "\n", FILE_APPEND);
}

function resolveUserId(PDO $pdo, ?string $stripeCustomerId, $maybeSession = null, $maybeSubscription = null): ?int
{
    // 1) client_reference_id dalla Checkout Session (METODO PREFERITO)
    if ($maybeSession && !empty($maybeSession->client_reference_id)) {
        $uid = (int)$maybeSession->client_reference_id;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        if ($stmt->fetchColumn()) {
            if ($stripeCustomerId) {
                $pdo->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ? AND (stripe_customer_id IS NULL OR stripe_customer_id = '')")
                    ->execute([$stripeCustomerId, $uid]);
            }
            return $uid;
        }
    }

    // 2) lookup diretto da users.stripe_customer_id
    if ($stripeCustomerId) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
        $stmt->execute([$stripeCustomerId]);
        $uid = $stmt->fetchColumn();
        if ($uid) return (int)$uid;
    }

    // 3) match per email del Customer Stripe (FALLBACK)
    if ($stripeCustomerId) {
        try {
            $customer = \Stripe\Customer::retrieve($stripeCustomerId);
            $email = $customer->email ?? null;
            if ($email) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $uid = $stmt->fetchColumn();
                if ($uid) {
                    $pdo->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ? AND (stripe_customer_id IS NULL OR stripe_customer_id = '')")
                        ->execute([$stripeCustomerId, $uid]);
                    return (int)$uid;
                }
            }
        } catch (\Exception $e) {
            logStripe("❗ Impossibile recuperare Customer $stripeCustomerId: " . $e->getMessage());
        }
    }

    return null;
}

function upsertSubscription(PDO $pdo, int $userId, string $customerId, string $subscriptionId, ?string $priceId, ?string $status, ?string $currentPeriodEnd)
{
    $planId = $priceId;

    $stmt = $pdo->prepare("
        INSERT INTO subscriptions
            (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end, plan_id, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            stripe_price_id = VALUES(stripe_price_id),
            status = VALUES(status),
            current_period_end = VALUES(current_period_end),
            plan_id = VALUES(plan_id),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $customerId, $subscriptionId, $priceId, $status, $currentPeriodEnd, $planId]);
}


/* ------------------- GESTIONE EVENTI ------------------- */

switch ($event->type) {

    // In stripe_webhook.php, sostituisci questo blocco

    case 'checkout.session.completed': {
            $session = $event->data->object;
            logStripe("checkout.session.completed: session_id=" . $session->id);

            $customerId     = $session->customer ?? null;
            $subscriptionId = $session->subscription ?? null;
            if (!$customerId || !$subscriptionId) {
                logStripe("checkout.session.completed ignorato: non è un abbonamento.");
                break;
            }

            try {
                $sub = \Stripe\Subscription::retrieve($subscriptionId);
            } catch (\Exception $e) {
                logStripe("❗ Retrieve subscription fallita: " . $e->getMessage());
                break;
            }

            $priceId = $sub->items->data[0]->price->id ?? null;
            $status  = $sub->status ?? null;
            $cpe     = isset($sub->current_period_end) ? date('Y-m-d H:i:s', $sub->current_period_end) : null;

            $userId = resolveUserId($pdo, $customerId, $session, $sub);
            if (!$userId) {
                logStripe("❌ user_id non risolto per customer=$customerId, session_id=" . $session->id);
                break;
            }

            // 1. Inserisce o aggiorna la sottoscrizione nel DB
            upsertSubscription($pdo, $userId, $customerId, $subscriptionId, $priceId, $status, $cpe);

            // 2. Crea lo slot relativo alla nuova sottoscrizione
            require('sync_server_slots.php');
            break;
        }

    case 'customer.subscription.created':
    case 'customer.subscription.updated': {
            $sub = $event->data->object;
            logStripe("{$event->type}: sub_id=" . $sub->id);

            $subscriptionId = $sub->id ?? null;
            $customerId     = $sub->customer ?? null;
            if (!$subscriptionId || !$customerId) break;

            $priceId = $sub->items->data[0]->price->id ?? null;
            $status  = $sub->status ?? null;
            $cpe     = isset($sub->current_period_end) ? date('Y-m-d H:i:s', $sub->current_period_end) : null;

            $userId = resolveUserId($pdo, $customerId, null, $sub);
            if (!$userId) {
                logStripe("❌ user_id non risolto per customer=$customerId, sub_id=" . $sub->id);
                break;
            }

            upsertSubscription($pdo, $userId, $customerId, $subscriptionId, $priceId, $status, $cpe);
            break;
        }

    case 'customer.subscription.deleted': {
            $sub = $event->data->object;
            $subscriptionId = $sub->id ?? null;
            if (!$subscriptionId) break;

            logStripe("customer.subscription.deleted: Inizio processo per sub_id: $subscriptionId");
            $pdo->beginTransaction();

            try {
                $stmt = $pdo->prepare("
                SELECT ss.id AS slot_id, ss.server_id, p.ram_gb, p.cpu_cores, p.storage_gb
                FROM server_slots ss JOIN plans p ON ss.plan_id = p.stripe_price_id
                WHERE ss.stripe_subscription_id = ?
            ");
                $stmt->execute([$subscriptionId]);
                $slotData = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($slotData) {
                    $serverId = $slotData['server_id'];
                    $slotId = $slotData['slot_id'];

                    // LOGICA CRITICA: Elimina il container su Proxmox
                    logStripe("Simulazione: Chiamata API per eliminare il container del server_id: $serverId");
                    // if ($serverId) {
                    //     require 'includes/proxmox_api.php';
                    //     $result = delete_proxmox_container($serverId);
                    //     if (!$result['success']) {
                    //         throw new Exception("Fallimento eliminazione container Proxmox: " . $result['error']);
                    //     }
                    // }

                    // Libera le risorse
                    $stmtRes = $pdo->prepare(
                        "UPDATE resources SET 
                        used_cpu_cores = GREATEST(0, used_cpu_cores - ?),
                        used_ram_mb = GREATEST(0, used_ram_mb - (? * 1024)),
                        used_storage_gb = GREATEST(0, used_storage_gb - ?)
                    WHERE id = 1"
                    );
                    $stmtRes->execute([$slotData['cpu_cores'], $slotData['ram_gb'], $slotData['storage_gb']]);
                    logStripe("Risorse liberate per server_id=$serverId.");

                    // Elimina i record dal DB
                    if ($serverId) {
                        $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);
                    }
                    $pdo->prepare("DELETE FROM server_slots WHERE id = ?")->execute([$slotId]);
                    logStripe("Record eliminati dal DB per server_id=$serverId e slot_id=$slotId");
                }

                // Aggiorna lo stato dell'abbonamento
                $pdo->prepare("UPDATE subscriptions SET status='canceled', updated_at = NOW() WHERE stripe_subscription_id=?")->execute([$subscriptionId]);

                $pdo->commit();
                logStripe("✅ Processo cancellazione completato per sub_id: $subscriptionId");
            } catch (Exception $e) {
                $pdo->rollBack();
                logStripe("❌ ERRORE durante cancellazione per sub_id: $subscriptionId. Rollback. Errore: " . $e->getMessage());
                http_response_code(500);
                exit($e->getMessage());
            }
            break;
        }

    default:
        logStripe("Evento non gestito: " . $event->type);
}

http_response_code(200);
