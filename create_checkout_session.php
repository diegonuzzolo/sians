<?php
require 'auth_check.php';
require 'config/config.php';
require 'config/stripe.php';
require 'config/env.php';
require 'includes/csrf.php';
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) { http_response_code(401); die('Auth'); }

$planId = intval($_POST['plan_id'] ?? 0);
if ($planId <= 0) { http_response_code(400); die('Piano non valido'); }

$stmt = $pdo->prepare("SELECT id, code, name, stripe_price_id FROM plans WHERE id=? AND active=1");
$stmt->execute([$planId]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) { http_response_code(404); die('Piano non trovato'); }

$stripe = new \Stripe\StripeClient(env('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'));

$session = $stripe->checkout->sessions->create([
  'mode' => 'subscription',
  'line_items' => [[ 'price' => $plan['stripe_price_id'], 'quantity' => 1 ]],
  'success_url' => env('STRIPE_SUCCESS_URL') . '?session_id={CHECKOUT_SESSION_ID}',
  'cancel_url'  => env('STRIPE_CANCEL_URL'),
  'metadata' => [
    'user_id' => (string)$userId,
    'plan_id' => (string)$plan['id'],
  ],
  'customer_creation' => 'if_required',
]);

header('Content-Type: application/json');
echo json_encode(['url' => $session->url]);
