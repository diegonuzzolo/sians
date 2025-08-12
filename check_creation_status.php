<?php
session_start();
include("auth_check.php");
require 'config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['creating_server'])) {
    echo json_encode(['error' => 'Nessuna creazione in corso']);
    exit;
}

$vmid = $_SESSION['creating_server']['vmid'];
$serverId = $_SESSION['creating_server']['server_id'];

// Controlla se il container esiste davvero in Proxmox
function proxmoxLogin() {
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = [
        'username' => PROXMOX_USERNAME,
        'password' => PROXMOX_PASSWORD,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        return null;
    }
    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}

function containerExists($vmid, $auth) {
    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/current";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    return isset($json['data']);
}

$auth = proxmoxLogin();
if (!$auth) {
    echo json_encode(['error' => 'Login Proxmox fallito']);
    exit;
}

if (containerExists($vmid, $auth)) {
    // Aggiorna stato server e container a "created"
    $pdo->prepare("UPDATE servers SET status = 'created', updated_at = NOW() WHERE id = ?")->execute([$serverId]);
    $pdo->prepare("UPDATE containers SET status = 'created' WHERE proxmox_containerId = ?")->execute([$vmid]);

    // Rimuovi variabile sessione per evitare ulteriori check
    unset($_SESSION['creating_server']);

    echo json_encode(['created' => true]);
} else {
    echo json_encode(['created' => false]);
}
