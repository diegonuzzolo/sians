<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';
require 'config/config.php';

// --- Recupera tutte le subscription dal DB ---
$stmt = $pdo->prepare("
    SELECT sub.id AS subscription_id,
           sub.stripe_customer_id,
           sub.stripe_price_id AS plan_id,
           sub.status AS sub_status,
           u.id AS user_id
    FROM subscriptions sub
    JOIN users u ON u.stripe_customer_id = sub.stripe_customer_id
");
$stmt->execute();
$allSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($allSubscriptions as $sub) {
    $userId = $sub['user_id'];
    $planId = $sub['plan_id'];
    $subscriptionId = $sub['subscription_id'];
    $subStatus = $sub['sub_status'];

    // Controlla se esiste già uno slot per questa subscription
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM server_slots
        WHERE user_id = ? AND plan_id = ? AND stripe_subscription_id = ?
    ");
    $stmtCheck->execute([$userId, $planId, $subscriptionId]);
    $exists = $stmtCheck->fetchColumn();

    if ($subStatus === 'active') {
        // Subscription attiva → crea slot se non esiste
        if (!$exists) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status)
                VALUES (?, ?, ?, 'pending')
            ");
            $stmtInsert->execute([$userId, $planId, $subscriptionId]);
        }
    } else {
        // Subscription non attiva → rimuovi lo slot se esiste
        if ($exists) {
            $stmtDelete = $pdo->prepare("
                DELETE FROM server_slots
                WHERE user_id = ? AND plan_id = ? AND stripe_subscription_id = ?
            ");
            $stmtDelete->execute([$userId, $planId, $subscriptionId]);
        }
    }
}

