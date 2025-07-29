<?php
require 'config/config.php';

define('SETUP_SERVER_TOKEN', 'la_luna_il_mio_cane_numero_uno'); // ← aggiungi questa riga

$headers = getallheaders();
$authToken = $headers['Authorization'] ?? '';

if ($authToken !== 'Bearer ' . SETUP_SERVER_TOKEN) {
    http_response_code(401);
    echo "Accesso non autorizzato";
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Metodo non permesso";
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$serverId = intval($data['server_id'] ?? 0);
$status = $data['status'] ?? null;
$progress = $data['progress'] ?? null;

if (!$serverId || $status === null || $progress === null) {
    http_response_code(400);
    echo "Parametri mancanti";
    exit;
}

$stmt = $pdo->prepare("UPDATE servers SET status = ?, progress = ? WHERE id = ?");
$stmt->execute([$status, $progress, $serverId]);

echo "OK";
