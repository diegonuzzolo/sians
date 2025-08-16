<?php
require 'vendor/autoload.php';
require 'config/config.php'; // contiene $pdo e chiavi Stripe

\Stripe\Stripe::setApiKey('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');

// Percorso log per debug
$logFile = __DIR__ . '/stripe_webhook.log';

// Recupero payload e header
$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpointSecret = "whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ";

// Log iniziale
file_put_contents($logFile, date('Y-m-d H:i:s') . " | Payload: $payload\n", FILE_APPEND);
file_put_contents($logFile, date('Y-m-d H:i:s') . " | SigHeader: $sigHeader\n", FILE_APPEND);

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    file_put_contents($logFile, "Invalid payload: " . $e->getMessage() . "\n", FILE_APPEND);
    exit("Invalid payload");
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    file_put_contents($logFile, "Invalid signature: " . $e->getMessage() . "\n", FILE_APPEND);
    exit("Invalid signature");
}

// Log tipo evento
file_put_contents($logFile, "Event type: " . $event->type . "\n", FILE_APPEND);

switch ($event->type) {
    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        $subscription = $event->data->object;

        $stripeSubId = $subscription->id;
        $stripeCustomerId = $subscription->customer;
        $status = $subscription->status;
        $currentPeriodEnd = date('Y-m-d H:i:s', $subscription->current_period_end);

        // Mapping plan_id
        $stripePriceId = $subscription->items->data[0]->price->id;
        $stmt = $pdo->prepare("SELECT id FROM plans WHERE stripe_price_id = ?");
        $stmt->execute([$stripePriceId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        $planId = $plan ? $plan['id'] : null;

        // Mapping user_id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
        $stmt->execute([$stripeCustomerId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = $user ? $user['id'] : null;

        file_put_contents($logFile, "StripePriceID: $stripePriceId, PlanID: $planId, UserID: $userId\n", FILE_APPEND);

        if ($userId && $planId) {
            $stmt = $pdo->prepare("
                INSERT INTO subscriptions 
                    (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status, current_period_end, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    plan_id=VALUES(plan_id),
                    status=VALUES(status),
                    current_period_end=VALUES(current_period_end)
            ");
            $success = $stmt->execute([
                $userId,
                $planId,
                $stripeCustomerId,
                $stripeSubId,
                $status,
                $currentPeriodEnd
            ]);

            file_put_contents($logFile, "DB insert success: " . ($success ? 'yes' : 'no') . "\n", FILE_APPEND);
            if (!$success) {
                file_put_contents($logFile, "SQL Error: " . implode(", ", $stmt->errorInfo()) . "\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, "Mapping failed: userId or planId is null\n", FILE_APPEND);
        }
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;
        $stripeSubId = $subscription->id;

        $stmt = $pdo->prepare("UPDATE subscriptions SET status='canceled' WHERE stripe_subscription_id=?");
        $stmt->execute([$stripeSubId]);
        file_put_contents($logFile, "Subscription canceled: $stripeSubId\n", FILE_APPEND);
        break;
}

http_response_code(200);
echo "success";
