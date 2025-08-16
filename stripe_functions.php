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
