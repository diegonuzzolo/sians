<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Usa l'autoload di Composer, che è lo standard moderno per le librerie PHP
require 'stripe-php/init.php';
require_once "config/config.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// La tua chiave segreta dell'endpoint webhook, la trovi nella dashboard di Stripe
$endpoint_secret = 'whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ'; 
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$payload = @file_get_contents('php://input');

if (!$payload) {
    http_response_code(400);
    exit('Payload vuoto');
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
} catch (\UnexpectedValueException $e) {
    // Payload JSON non valido
    http_response_code(400);
    exit("⚠️ Invalid payload");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Firma non valida
    http_response_code(400);
    exit("⚠️ Invalid signature");
}

function logStripe($msg) {
    file_put_contents("stripe_debug.log", date("[Y-m-d H:i:s] ") . $msg . "\n", FILE_APPEND);
}

/**
 * Tenta di risalire all'ID utente del tuo database usando varie strategie.
 * La più affidabile è client_reference_id.
 */
function resolveUserId(PDO $pdo, ?string $stripeCustomerId, $maybeSession = null, $maybeSubscription = null): ?int {
    // 1) Metodo più affidabile: client_reference_id passato durante la creazione della Checkout Session
    if ($maybeSession && !empty($maybeSession->client_reference_id)) {
        $uid = (int)$maybeSession->client_reference_id;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        if ($stmt->fetchColumn()) {
            // Se l'utente esiste, assicuriamoci che il suo stripe_customer_id sia aggiornato nel nostro DB
            if ($stripeCustomerId) {
                $pdo->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ? AND (stripe_customer_id IS NULL OR stripe_customer_id = '')")
                    ->execute([$stripeCustomerId, $uid]);
            }
            return $uid;
        }
    }

    // 2) Fallback: Cerca l'utente tramite lo stripe_customer_id già salvato nel nostro DB
    if ($stripeCustomerId) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
        $stmt->execute([$stripeCustomerId]);
        $uid = $stmt->fetchColumn();
        if ($uid) return (int)$uid;
    }
    
    // Altri metodi di fallback (come la ricerca per email) potrebbero essere aggiunti qui se necessario

    return null;
}

/**
 * Inserisce o aggiorna una riga nella tabella 'subscriptions'.
 * Usa ON DUPLICATE KEY UPDATE per evitare errori di chiavi duplicate.
 */
function upsertSubscription(PDO $pdo, int $userId, string $customerId, string $subscriptionId, ?string $priceId, ?string $status, ?string $currentPeriodEnd) {
    $stmt = $pdo->prepare("
        INSERT INTO subscriptions
            (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            stripe_price_id = VALUES(stripe_price_id),
            status = VALUES(status),
            current_period_end = VALUES(current_period_end),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $customerId, $subscriptionId, $priceId, $status, $currentPeriodEnd]);
}

/* ------------------- GESTIONE DEGLI EVENTI STRIPE ------------------- */

switch ($event->type) {

    case 'checkout.session.completed': {
        $session = $event->data->object;
        logStripe("checkout.session.completed: " . $session->id);

        $customerId     = $session->customer ?? null;
        $subscriptionId = $session->subscription ?? null;
        if (!$customerId || !$subscriptionId) break;

        try {
            $sub = \Stripe\Subscription::retrieve($subscriptionId);
        } catch (\Exception $e) {
            logStripe("❗ Retrieve subscription fallita per {$subscriptionId}: " . $e->getMessage());
            break;
        }

        $priceId = $sub->items->data[0]->price->id ?? null;
        $status  = $sub->status ?? null;
        $cpe     = isset($sub->current_period_end) ? date('Y-m-d H:i:s', $sub->current_period_end) : null;

        $userId = resolveUserId($pdo, $customerId, $session, $sub);
        if (!$userId) {
            logStripe("❌ user_id non risolto per sessione {$session->id} e customer {$customerId}");
            break;
        }

        upsertSubscription($pdo, $userId, $customerId, $subscriptionId, $priceId, $status, $cpe);

        // Crea lo slot server solo se la sottoscrizione è in uno stato valido ('active' o 'trialing')
        if ($status === 'active' || $status === 'trialing') {
            $stmt = $pdo->prepare("SELECT id FROM server_slots WHERE stripe_subscription_id = ?");
            $stmt->execute([$subscriptionId]);
            if (!$stmt->fetchColumn()) {
                $stmt = $pdo->prepare(
                    "INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status) VALUES (?, ?, ?, 'pending')"
                );
                $stmt->execute([$userId, $priceId, $subscriptionId]);
                logStripe("✅ Creato nuovo slot pending per user={$userId}, sub={$subscriptionId}, plan={$priceId}");
            }
        }
        break;
    }

    case 'customer.subscription.updated': {
        $sub = $event->data->object;
        logStripe("customer.subscription.updated: " . $sub->id);

        $subscriptionId = $sub->id ?? null;
        $customerId     = $sub->customer ?? null;
        if (!$subscriptionId || !$customerId) break;

        $priceId = $sub->items->data[0]->price->id ?? null;
        $status  = $sub->status ?? null;
        $cpe     = isset($sub->current_period_end) ? date('Y-m-d H:i:s', $sub->current_period_end) : null;

        $userId = resolveUserId($pdo, $customerId, null, $sub);
        if (!$userId) {
            logStripe("❌ user_id non risolto per sub {$subscriptionId} e customer {$customerId}");
            break;
        }

        upsertSubscription($pdo, $userId, $customerId, $subscriptionId, $priceId, $status, $cpe);

        // **LOGICA DI CANCELLAZIONE**: Se lo stato diventa 'canceled', eliminiamo lo slot server.
        if ($status === 'canceled') {
            logStripe("🔥 Subscription {$subscriptionId} è 'canceled'. Rimuovo lo slot server.");
            $stmt = $pdo->prepare("DELETE FROM server_slots WHERE stripe_subscription_id = ?");
            $stmt->execute([$subscriptionId]);
        }
        // **LOGICA DI RIATTIVAZIONE**: Se lo stato torna attivo, ci assicuriamo che lo slot esista
        elseif ($status === 'active' || $status === 'trialing') {
             $stmt = $pdo->prepare("SELECT id FROM server_slots WHERE stripe_subscription_id = ?");
            $stmt->execute([$subscriptionId]);
            if (!$stmt->fetchColumn()) {
                $stmt = $pdo->prepare(
                    "INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status) VALUES (?, ?, ?, 'pending')"
                );
                $stmt->execute([$userId, $priceId, $subscriptionId]);
                logStripe("✅ Creato slot (da riattivazione) per user={$userId}, sub={$subscriptionId}, plan={$priceId}");
            }
        }
        break;
    }

    case 'customer.subscription.deleted': {
        $sub = $event->data->object;
        $subscriptionId = $sub->id ?? null;
        if ($subscriptionId) {
            logStripe("customer.subscription.deleted: $subscriptionId");
            
            // Imposta lo stato a 'canceled' nel nostro DB per coerenza
            $stmt = $pdo->prepare("UPDATE subscriptions SET status='canceled', updated_at = NOW() WHERE stripe_subscription_id=?");
            $stmt->execute([$subscriptionId]);

            // Rimuovi esplicitamente lo slot server associato
            logStripe("🔥 Subscription {$subscriptionId} è stata eliminata. Rimuovo lo slot server.");
            $stmt = $pdo->prepare("DELETE FROM server_slots WHERE stripe_subscription_id = ?");
            $stmt->execute([$subscriptionId]);
        }
        break;
    }

    default:
        // È buona norma non registrare eventi non gestiti per non riempire i log
        // logStripe("Evento non gestito: ".$event->type);
}

// Invia una risposta 200 a Stripe per confermare la ricezione dell'evento
http_response_code(200);
