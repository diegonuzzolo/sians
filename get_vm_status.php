<?php
$_GET['server_id'] = $_GET['id'] ?? null; // Se usi un parametro diverso da server_id
include("auth_check.php");
header('Content-Type: application/json');

if (!isset($_GET['server_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'server_id mancante']);
    exit;
}

$serverId = intval($_GET['server_id']);
if ($serverId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID non valido']);
    exit;
}

// Recupera la VM collegata al server
$stmt = $pdo->prepare("
    SELECT v.proxmox_vmid
    FROM servers s
    JOIN minecraft_vms v ON s.vm_id = v.id
    WHERE s.id = ?
");
$stmt->execute([$serverId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Server non trovato']);
    exit;
}

$proxmoxVmid = $row['proxmox_vmid'];

$node = PROXMOX_NODE;
$host = rtrim(PROXMOX_HOST, '/');
$tokenId = PROXMOX_API_TOKEN_ID;
$tokenSecret = PROXMOX_API_TOKEN_SECRET;

$url = "$host/api2/json/nodes/$node/qemu/$proxmoxVmid/status/current";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: PVEAPIToken=$tokenId=$tokenSecret"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore nella richiesta API Proxmox']);
    curl_close($ch);
    exit;
}
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if (isset($data['data']['status'])) {
        echo json_encode(['status' => $data['data']['status']]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Risposta API incompleta']);
    }
} else {
    http_response_code($httpCode);
    echo json_encode(['error' => "Impossibile ottenere stato VM, HTTP $httpCode"]);
}
