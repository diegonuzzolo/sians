<?php
require 'config/config.php';
require 'auth_check.php';

$vmid = intval($_GET['vmid'] ?? 0);
if ($vmid <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'VMID non valido']);
    exit;
}

// Funzione proxmoxLogin() deve essere definita o inclusa qui
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
    if ($response === false) throw new Exception('Errore CURL login: ' . curl_error($ch));
    curl_close($ch);

    $json = json_decode($response,true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return ['ticket'=>$json['data']['ticket'], 'csrf'=>$json['data']['CSRFPreventionToken']];
}

try {
    $auth = proxmoxLogin();

    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc/$vmid/status/start";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response,true);
    if (isset($json['errors'])) {
        echo json_encode(['success'=>false,'error'=>json_encode($json['errors'])]);
    } else {
        echo json_encode(['success'=>true]);
    }
} catch(Exception $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
