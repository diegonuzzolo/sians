<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Inizio sync server slots...\n";

// --- Recupera tutte le subscription attive ---
$stmt = $pdo->prepare("
    SELECT user_id,
           stripe_subscription_id,
           stripe_price_id AS plan_id
    FROM subscriptions
    WHERE status = 'active' AND user_id IS NOT NULL
");
$stmt->execute();
$activeSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$activeSubscriptions) {
    echo "Nessuna subscription attiva trovata.\n";
    exit; // Esce se non ci sono subscription da processare
}

// --- Prepara la query di inserimento una sola volta fuori dal ciclo ---
$stmtInsert = $pdo->prepare("
    INSERT INTO server_slots (user_id, plan_id, stripe_subscription_id, status)
    VALUES (?, ?, ?, 'pending')
");

$createdCount = 0;
foreach ($activeSubscriptions as $sub) {
    try {
        // --- Esegue l'inserimento per ogni subscription ---
        if ($stmtInsert->execute([$sub['user_id'], $sub['plan_id'], $sub['stripe_subscription_id']])) {
            echo "✔ Tentativo di inserimento per user_id={$sub['user_id']}, subscription_id={$sub['stripe_subscription_id']}\n";
            $createdCount++;
        }
    } catch (PDOException $e) {
        // Gestisce l'errore di duplicato (codice 23000 per SQL Integrity Constraint Violation)
        if ($e->getCode() == '23000') {
            echo "i Slot già esistente per user_id={$sub['user_id']}, subscription_id={$sub['stripe_subscription_id']} (ignorato)\n";
        } else {
            // Se l'errore è diverso, lo mostra
            echo "❌ Errore DB per user_id={$sub['user_id']}: " . $e->getMessage() . "\n";
        }
    }
}

echo "--- Risultato ---\n";
echo "Elaborate: " . count($activeSubscriptions) . " sottoscrizioni attive.\n";
echo "Operazioni di inserimento tentate: " . $createdCount . ".\n";
echo "[" . date('Y-m-d H:i:s') . "] Sync completato.\n";