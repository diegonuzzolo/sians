<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';

define('SETUP_SERVER_TOKEN', 'la_luna_il_mio_cane_numero_uno');

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
$progress = $data['progress'] ?? null; // può essere null nel caso di solo aggiornamento status

if (!$serverId || $status === null) {
    http_response_code(400);
    echo "Parametri mancanti (server_id o status)";
    exit;
}

try {
    if ($progress !== null) {
        $stmt = $pdo->prepare("UPDATE servers SET status = ?, progress = ? WHERE id = ?");
        $stmt->execute([$status, $progress, $serverId]);
    } else {
        $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
        $stmt->execute([$status, $serverId]);
    }
    echo "OK";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Errore database: " . $e->getMessage();
    exit;
}
