<?php
require 'config/config.php';
require 'config/stripe.php';
require 'config/env.php';

$payload = @file_get_contents('php://input');
$sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$whSecret = env('STRIPE_WEBHOOK_SECRET');

try {
  $event = \Stripe\Webhook::constructEvent($payload, $sig, $whSecret);
} catch(\UnexpectedValueException $e) {
  http_response_code(400); exit('Invalid payload');
} catch(\Stripe\Exception\SignatureVerificationException $e) {
  http_response_code(400); exit('Invalid signature');
}

// log
$stmt = $pdo->prepare("INSERT INTO payment_events (user_id, type, payload) VALUES (?, ?, ?)");
$userId = null;
$type = $event->type;
$stmt->execute([$userId, $type, $payload]);

switch ($event->type) {
  case 'checkout.session.completed':
    $session = $event->data->object;
    $userId = intval($session->metadata->user_id ?? 0);
    $planId = intval($session->metadata->plan_id ?? 0);

    // recupera subscription id
    $subId = $session->subscription;
    $customerId = $session->customer;

    // salva subscription
    $ins = $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status) VALUES (?,?,?,?,?)");
    $ins->execute([$userId, $planId, $customerId, $subId, 'incomplete']);

    // PROVISIONING: crea server per l’utente in base al piano
    // - leggi RAM/CPU/Storage da plans
    $p = $pdo->prepare("SELECT ram_gb, cpu_cores, storage_gb FROM plans WHERE id=?");
    $p->execute([$planId]);
    $cfg = $p->fetch(PDO::FETCH_ASSOC);

    if ($cfg) {
        // qui richiami direttamente la tua pipeline di creazione (add_server) in funzione riusabile
        require_once 'services/provision.php'; // crea una funzione provision_bedrock($userId,$cfg)
        try {
            provision_bedrock($pdo, $userId, (int)$cfg['ram_gb'], (int)$cfg['cpu_cores'], (int)$cfg['storage_gb']);
        } catch(Exception $e) {
            // log errori ma rispondi 200 a Stripe
            error_log('Provisioning error: '.$e->getMessage());
        }
    }
    break;

  case 'customer.subscription.updated':
  case 'customer.subscription.created':
  case 'customer.subscription.deleted':
    $sub = $event->data->object;
    $status = $sub->status; // active, past_due, canceled, ...
    $upd = $pdo->prepare("UPDATE subscriptions SET status=?, current_period_end=FROM_UNIXTIME(?) WHERE stripe_subscription_id=?");
    $upd->execute([$status, $sub->current_period_end, $sub->id]);
    // se canceled -> fermare e marcare server come "sospeso"
    if ($type === 'customer.subscription.deleted') {
        // TODO: stop dei container dell’utente
    }
    break;
}

http_response_code(200);
