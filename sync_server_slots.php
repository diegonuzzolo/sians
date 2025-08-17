<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config/config.php';

echo "[".date('Y-m-d H:i:s')."] Inizio sync server slots...\n";

// --- Recupera tutte le subscription attive ---
$stmt = $pdo->prepare("
    SELECT id AS subscription_id,
           stripe_customer_id,
           stripe_price_id AS plan_id
    FROM subscriptions
    WHERE status = 'active'
");
$stmt->execute();
$activeSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$activeSubscriptions) {
    echo "Nessuna subscription attiva trovata.\n";
}

foreach ($activeSubscriptions as $sub) {
    $subscriptionId = $sub['subscription_id'];
    $stripeCustomerId = $sub['stripe_customer_id'];
    $planId = $sub['plan_id'];

    // --- Recupera user_id corrispondente ---
    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE stripe_customer_id COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci");
    $stmtUser->execute([$stripeCustomerId]);
    $userId = $stmtUser->fetchColumn();

    if (!$userId) {
        echo "⚠ Nessun utente trovato per stripe_customer_id={$stripeCustomerId}, subscription_id={$subscriptionId}\n";
        continue; // salta questa subscription
    }

    // --- Controlla se lo slot esiste già ---
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM server_slots
        WHERE user_id = ? AND plan_id = ? AND stripe_subscription_id = ?
    ");
    $stmtCheck->execute([$userId, $planId, $subscriptionId]);
    $exists = $stmtCheck->fetchColumn();

    if (!$exists) {
        // --- Inserisce nuovo slot ---
        $stmtInsert = $pdo->prepare("
            INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmtInsert->execute([$userId, $planId, $subscriptionId]);
        echo "✔ Slot creato per user_id={$userId}, plan_id={$planId}, subscription_id={$subscriptionId}\n";
    } else {
        echo "ℹ Slot già esistente per user_id={$userId}, plan_id={$planId}, subscription_id={$subscriptionId}\n";
    }
}

echo "[".date('Y-m-d H:i:s')."] Sync completato.\n";
