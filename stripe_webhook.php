<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("stripe-php/init.php");
require_once("config/config.php");

// Imposta API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Secret del webhook (da Stripe CLI o Dashboard)
$endpoint_secret = 'whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ';
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$payload = @file_get_contents('php://input');
if (!$payload) {
    http_response_code(400);
    exit('Payload vuoto');
}

$data = json_decode($payload, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit('JSON non valido: ' . json_last_error_msg());
}

try {
    if ($endpoint_secret) {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $endpoint_secret
        );
    } else {
        $event = \Stripe\Event::constructFrom($data);
    }
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit("⚠️ Invalid payload");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit("⚠️ Invalid signature");
}

// Funzione per loggare debug
function logStripe($msg) {
    file_put_contents("stripe_debug.log", date("[Y-m-d H:i:s] ") . $msg . "\n", FILE_APPEND);
}

// Gestione eventi
switch ($event->type) {

    case 'checkout.session.completed':
        $session = $event->data->object;

        logStripe("Checkout session completed: " . json_encode($session));

        $customerId = $session->customer ?? null;
        $subscriptionId = $session->subscription ?? null;
        $clientReferenceId = $session->client_reference_id ?? null; 
        // client_reference_id → utile per sapere quale user ha fatto checkout

        if ($customerId && $subscriptionId) {
            // Recupera la subscription da Stripe
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            $planId = $subscription->items->data[0]->price->id ?? null;
            $status = $subscription->status ?? null;
            $currentPeriodEnd = isset($subscription->current_period_end)
                ? date('Y-m-d H:i:s', $subscription->current_period_end)
                : null;

            // Trova user: o da client_reference_id (se lo usi), o da stripe_customer_id
            $userId = null;
            if ($clientReferenceId) {
                $userId = $clientReferenceId; // se hai passato l'user_id in checkout
            } else {
                $stmtUser = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
                $stmtUser->execute([$customerId]);
                $userId = $stmtUser->fetchColumn();
            }

            // Aggiorna user con stripe_customer_id se manca
            if ($userId && $customerId) {
                $stmt = $pdo->prepare("UPDATE users SET stripe_customer_id = ? 
                                       WHERE id = ? AND (stripe_customer_id IS NULL OR stripe_customer_id = '')");
                $stmt->execute([$customerId, $userId]);
            }

            if ($planId && $userId) {
                $stmt = $pdo->prepare("INSERT INTO subscriptions 
                    (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        stripe_price_id=VALUES(stripe_price_id),
                        status=VALUES(status),
                        current_period_end=VALUES(current_period_end)");
                $stmt->execute([$userId, $customerId, $subscriptionId, $planId, $status, $currentPeriodEnd]);
            } else {
                logStripe("❌ Nessun user_id trovato per customer=$customerId (skip insert)");
            }
        }
        break;

    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        $subscription = $event->data->object;

        logStripe("Subscription update: " . json_encode($subscription));

        $subscriptionId = $subscription->id ?? null;
        $customerId = $subscription->customer ?? null;
        $status = $subscription->status ?? null;
        $planId = $subscription->items->data[0]->price->id ?? null;
        $currentPeriodEnd = isset($subscription->current_period_end)
            ? date('Y-m-d H:i:s', $subscription->current_period_end)
            : null;

        if ($subscriptionId && $customerId) {
            $stmtUser = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
            $stmtUser->execute([$customerId]);
            $userId = $stmtUser->fetchColumn();

            if ($userId) {
                // Assicura che user abbia customerId
                $stmt = $pdo->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?");
                $stmt->execute([$customerId, $userId]);

                $stmt = $pdo->prepare("INSERT INTO subscriptions 
                    (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        stripe_price_id=VALUES(stripe_price_id),
                        status=VALUES(status),
                        current_period_end=VALUES(current_period_end)");
                $stmt->execute([$userId, $customerId, $subscriptionId, $planId, $status, $currentPeriodEnd]);
            } else {
                logStripe("❌ Nessun user_id trovato per customer=$customerId (skip insert)");
            }
        }
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;
        $subscriptionId = $subscription->id ?? null;

        logStripe("Subscription deleted: " . json_encode($subscription));

        if ($subscriptionId) {
            $stmt = $pdo->prepare("UPDATE subscriptions SET status='canceled' WHERE stripe_subscription_id=?");
            $stmt->execute([$subscriptionId]);
        }
        break;

    default:
        logStripe("Evento non gestito: " . $event->type);
}

http_response_code(200);
