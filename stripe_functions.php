<?php
require 'stripe-php/init.php';

\Stripe\Stripe::setApiKey("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");

function getActiveSubscriptionForUser($stripeCustomerId) {
    $subscriptions = \Stripe\Subscription::all([
        'customer' => $stripeCustomerId,
        'status' => 'all',
        'limit' => 1,
    ]);

    return $subscriptions->data[0] ?? null;
}

function getUserIdByStripeCustomer($customerId) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    return $stmt->fetchColumn() ?: null;
}

function mapPlanId($priceId) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id FROM plans WHERE stripe_price_id = ?");
    $stmt->execute([$priceId]);
    return $stmt->fetchColumn() ?: null;
}
