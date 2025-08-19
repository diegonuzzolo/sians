<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../vendor/autoload.php'; // composer require stripe/stripe-php
\Stripe\Stripe::setApiKey(env('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'));
