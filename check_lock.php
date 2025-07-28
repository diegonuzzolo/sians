<?php
require 'config/config.php';

header('Content-Type: application/json');

$stmt = $pdo->query("SELECT id FROM servers WHERE status IN ('installing', 'downloading_mods')");
$installingServers = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
    'installing_ids' => $installingServers
]);
