<?php
// update_status_api.php
header('Content-Type: application/json');
require 'config/config.php'; // Qui la connessione PDO $pdo

// --- CONFIGURA IL TOKEN DI AUTENTICAZIONE ---
// Puoi usare un token segreto semplice, ad es. una stringa lunga e casuale
define('API_SECRET_TOKEN', 'AttentoWQuestoÈUnTokenSegreto1234dellaNostra=%hh&ughine567890');

// --- Leggi parametri GET ---
$id = $_GET['id'] ?? null;
$status = $_GET['status'] ?? null;
$token = $_GET['token'] ?? null;

// --- Controllo token ---
if ($token !== API_SECRET_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: token non valido']);
    exit;
}

// --- Validazione base ---
if (!$id || !$status) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametri mancanti']);
    exit;
}

// --- Lista stati ammessi ---
$allowed_statuses = ['installing', 'downloading_mods', 'running', 'stopped', 'error'];

// Controllo stato valido
if (!in_array($status, $allowed_statuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Stato non valido']);
    exit;
}

// --- Aggiorna nel DB ---
try {
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Server non trovato']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $id, 'status' => $status]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore DB: ' . $e->getMessage()]);
    exit;
}
