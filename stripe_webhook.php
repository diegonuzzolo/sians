<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("stripe-php/init.php");
require_once("config/config.php");

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

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

// Funzione per ricavare user_id dal customer_id
function getUserIdFromCustomer($pdo, $customerId) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    return $stmt->fetchColumn();
}

// Gestione eventi
switch ($event->type) {

    case 'checkout.session.completed':
        $session = $event->data->object;
        logStripe("Checkout session completed: " . json_encode($session));

        $customerId = $session->customer ?? null;
        $subscriptionId = $session->subscription ?? null;

        if ($customerId && $subscriptionId) {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);

            $planId = $subscription->items->data[0]->price->id ?? null;
            $status = $subscription->status ?? null;
            $currentPeriodEnd = isset($subscription->current_period_end)
                ? date('Y-m-d H:i:s', $subscription->current_period_end)
                : null;

            // Trova user_id dal customer_id
            $userId = getUserIdFromCustomer($pdo, $customerId);

            if ($userId && $planId) {
                $stmt = $pdo->prepare("INSERT INTO subscriptions 
                    (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        stripe_price_id=VALUES(stripe_price_id),
                        status=VALUES(status),
                        current_period_end=VALUES(current_period_end)");
                $stmt->execute([$userId, $customerId, $subscriptionId, $planId, $status, $currentPeriodEnd]);
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
            $userId = getUserIdFromCustomer($pdo, $customerId);

            if ($userId) {
                $stmt = $pdo->prepare("INSERT INTO subscriptions 
                    (user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id, status, current_period_end)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        stripe_price_id=VALUES(stripe_price_id),
                        status=VALUES(status),
                        current_period_end=VALUES(current_period_end)");
                $stmt->execute([$userId, $customerId, $subscriptionId, $planId, $status, $currentPeriodEnd]);
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
