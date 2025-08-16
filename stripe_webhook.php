<?php
require 'config/config.php';
require 'vendor/autoload.php';

\Stripe\Stripe::setApiKey('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');

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['whsec_N6FUKDFe7lZcDLjnpNhUskUkBsSsrQAQ'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $stripeWebhookSecret
    );
} catch(\UnexpectedValueException $e) {
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

switch ($event->type) {
    case 'customer.subscription.created':
    case 'customer.subscription.updated':
        $subscription = $event->data->object;

        $stmt = $pdo->prepare("
            INSERT INTO subscriptions (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status, current_period_end, created_at)
            VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), NOW())
            ON DUPLICATE KEY UPDATE
                status=VALUES(status),
                current_period_end=VALUES(current_period_end)
        ");
        $stmt->execute([
            getUserIdByStripeCustomer($subscription->customer), // funzione che mappa lo user_id
            mapPlanId($subscription->items->data[0]->price->id), // funzione che mappa il piano
            $subscription->customer,
            $subscription->id,
            $subscription->status,
            $subscription->current_period_end
        ]);
        break;

    case 'customer.subscription.deleted':
        $subscription = $event->data->object;

        $stmt = $pdo->prepare("
            UPDATE subscriptions SET status='canceled'
            WHERE stripe_subscription_id=?
        ");
        $stmt->execute([$subscription->id]);
        break;
}

http_response_code(200);
?>
