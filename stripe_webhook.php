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

try {
    if ($endpoint_secret) {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $endpoint_secret
        );
    } else {
        $event = \Stripe\Event::constructFrom(
            json_decode($payload, true)
        );
    }
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit("⚠️ Invalid payload");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit("⚠️ Invalid signature");
}

// Funzione log
function logStripe($msg) {
    file_put_contents("stripe_debug.log", date("[Y-m-d H:i:s] ") . $msg . "\n", FILE_APPEND);
}

switch ($event->type) {

    case 'checkout.session.completed':
        $session = $event->data->object;
        logStripe("Checkout session completed: " . json_encode($session));

        $customerId = $session->customer ?? null;
        $subscriptionId = $session->subscription ?? null;

        if ($customerId && $subscriptionId) {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $planId = $subscription->items->data[0]->price->id ?? null;

            // Inserisci slot
            if ($planId) {
                $stmt = $pdo->prepare("INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status) 
                                        VALUES (?, ?, ?, 'pending')
                                        ON DUPLICATE KEY UPDATE plan_id=VALUES(plan_id), status='pending'");
                // ⚠️ user_id andrebbe mappato: qui serve logica per ricavarlo dal customer Stripe
                $userId = 1; // esempio: sostituisci con lookup in tabella users
                $stmt->execute([$userId, $planId, $subscriptionId]);
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

        if ($subscriptionId && $customerId) {
            // Aggiorna slot
            $stmt = $pdo->prepare("UPDATE server_slots 
                                   SET plan_id=?, status=? 
                                   WHERE stripe_subscription_id=?");
            $stmt->execute([$planId, $status === 'active' ? 'created' : 'pending', $subscriptionId]);
        }
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;
        $subscriptionId = $subscription->id ?? null;
        logStripe("Subscription deleted: " . json_encode($subscription));

        if ($subscriptionId) {
            $stmt = $pdo->prepare("DELETE FROM server_slots WHERE stripe_subscription_id=?");
            $stmt->execute([$subscriptionId]);
        }
        break;

    default:
        logStripe("Evento non gestito: " . $event->type);
}

http_response_code(200);
