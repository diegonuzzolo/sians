<?php
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_GET['vmid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'VMID mancante']);
    exit;
}

$vmid = intval($_GET['vmid']);
$node = PROXMOX_NODE;
$host = PROXMOX_HOST;
$tokenId = PROXMOX_API_TOKEN_ID;
$tokenSecret = PROXMOX_API_TOKEN_SECRET;

$url = "$host/api2/json/nodes/$node/qemu/$vmid/status/current";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: PVEAPIToken=$tokenId=$tokenSecret"
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    echo json_encode(['status' => $data['data']['status']]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile ottenere stato VM']);
}
