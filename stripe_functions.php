<?php
function getActiveSubscriptionForUser($stripeCustomerId) {
    $subscriptions = \Stripe\Subscription::all([
        'customer' => $stripeCustomerId,
        'status' => 'all',
        'limit' => 1,
    ]);

    return $subscriptions->data[0] ?? null;
}
