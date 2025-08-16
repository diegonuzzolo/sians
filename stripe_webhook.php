<?php
require 'vendor/autoload.php';
require 'config/config.php'; // contiene $pdo e chiavi Stripe

\Stripe\Stripe::setApiKey('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');

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpointSecret = "whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ";

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
} catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit("Webhook error: " . $e->getMessage());
}

switch ($event->type) {
    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        $subscription = $event->data->object;

        $stripeSubId = $subscription->id;
        $stripeCustomerId = $subscription->customer;
        $status = $subscription->status;
        $currentPeriodEnd = date('Y-m-d H:i:s', $subscription->current_period_end);

        // Mapping plan_id dal price_id
        $stripePriceId = $subscription->items->data[0]->price->id;
        $stmt = $pdo->prepare("SELECT id FROM plans WHERE stripe_price_id = ?");
        $stmt->execute([$stripePriceId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        $planId = $plan['id'] ?? null;

        // Mapping user_id dal customer_id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
        $stmt->execute([$stripeCustomerId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $userId = $user['id'] ?? null;

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
            $stmt->execute([
                $userId,
                $planId,
                $stripeCustomerId,
                $stripeSubId,
                $status,
                $currentPeriodEnd
            ]);
        }
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;
        $stripeSubId = $subscription->id;

        $stmt = $pdo->prepare("UPDATE subscriptions SET status='canceled' WHERE stripe_subscription_id=?");
        $stmt->execute([$stripeSubId]);
        break;
}

http_response_code(200);
echo "success";
