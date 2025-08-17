<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'stripe-php/init.php';
require 'config/config.php';

echo "[".date('Y-m-d H:i:s')."] Inizio sync server slots...\n";

// --- Recupera tutte le subscription attive e gli user_id ---
$stmt = $pdo->prepare("
    SELECT sub.id AS subscription_id,
           sub.stripe_customer_id,
           sub.stripe_price_id AS plan_id,
           u.id AS user_id
    FROM subscriptions sub
    JOIN users u 
      ON u.stripe_customer_id COLLATE utf8mb4_general_ci = sub.stripe_customer_id COLLATE utf8mb4_general_ci
    WHERE sub.status = 'active'
");
$stmt->execute();
$activeSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$activeSubscriptions) {
    echo "Nessuna subscription attiva trovata.\n";
}

foreach ($activeSubscriptions as $sub) {
    $userId = $sub['user_id'];
    $planId = $sub['plan_id'];
    $subscriptionId = $sub['subscription_id'];

    // Controlla se lo slot esiste già
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM server_slots
        WHERE user_id = ? AND plan_id = ? AND stripe_subscription_id = ?
    ");
    $stmtCheck->execute([$userId, $planId, $subscriptionId]);
    $exists = $stmtCheck->fetchColumn();

    if (!$exists) {
        // Inserisce nuovo slot
        $stmtInsert = $pdo->prepare("
            INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmtInsert->execute([$userId, $planId, $subscriptionId]);
        echo "✔ Slot creato per user_id={$userId}, plan_id={$planId}\n";
    } else {
        echo "ℹ Slot già esistente per user_id={$userId}, plan_id={$planId}\n";
    }
}

echo "[".date('Y-m-d H:i:s')."] Sync completato.\n";
