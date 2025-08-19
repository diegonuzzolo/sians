<?php
require 'config/config.php';

// Controllo token di sicurezza
$headers = getallheaders();
$bearer = $headers['Authorization'] ?? '';
$expected = 'ecb6843d7a4c262ce25055f80ac747d0878aa2435b20b9fb0bf0270e95cfc621';
if ($bearer !== $expected) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Token non valido']);
    exit;
}

// Riceve POST: server_id e nuovo status
$data = json_decode(file_get_contents('php://input'), true);
$serverId = intval($data['server_id'] ?? 0);
$status = trim($data['status'] ?? '');

if ($serverId && $status) {
    $stmt = $pdo->prepare("UPDATE containers SET status = ? WHERE id = ?");
    $stmt->execute([$status, $serverId]);
    echo json_encode(['success'=>true,'status'=>$status]);
} else {
    echo json_encode(['success'=>false,'message'=>'Parametri mancanti']);
}
