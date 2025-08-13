<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php'; // contiene proxmoxLogin() e funzioni API

$vmid = intval($_GET['vmid'] ?? 0);
if ($vmid <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'invalid', 'error' => 'VMID non valido']);
    exit;
}

try {
    $auth = proxmoxLogin();
    $node = PROXMOX_NODE;

    // Richiesta API Proxmox per info container
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/current";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['status'])) {
        throw new Exception('Risposta API non valida: ' . $response);
    }

    // Stato possibile: stopped, running, starting, etc.
    $status = $json['data']['status'];

    echo json_encode(['status' => $status]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
