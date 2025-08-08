<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('SETUP_SERVER_TOKEN', 'la_luna_il_mio_cane_numero_uno');

// Verifica autenticazione Bearer
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) || $matches[1] !== SETUP_SERVER_TOKEN) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Accesso non autorizzato"]);
    exit;
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Metodo non permesso"]);
    exit;
}

// Decodifica JSON ricevuto
$data = json_decode(file_get_contents("php://input"), true);
$serverId = intval($data['server_id'] ?? 0);
$status = trim($data['status'] ?? '');
$progress = isset($data['progress']) ? intval($data['progress']) : null;

if ($serverId <= 0 || $status === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Parametri mancanti o non validi (server_id o status)"]);
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

    // Stati che mostrano la progress bar
    $showProgressBarStates = ['extracting_mods', 'setting_up', 'diagnosis', 'installing', 'downloading_mods'];

    $showProgressBar = in_array($status, $showProgressBarStates);

    echo json_encode([
        "success" => true,
        "message" => "Stato aggiornato",
        "status" => $status,
        "progress" => $progress,
        "show_progressbar" => $showProgressBar
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Errore database", "details" => $e->getMessage()]);
    exit;
}
