<?php
require 'config/config.php';

/**
 * Login su Proxmox e ritorna ticket e CSRF token
 */
function proxmoxLogin(): array {
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
    if ($response === false) {
        throw new Exception('Errore CURL login: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        throw new Exception('Login Proxmox fallito: ' . $response);
    }

    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}

/**
 * Recupera lo stato corrente di un container LXC
 */
function getContainerStatus(int $vmid, array $auth): string {
    $node = PROXMOX_NODE;
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

    return $json['data']['status'];
}

/**
 * Avvia un container LXC tramite API Proxmox
 */
function startContainer(int $vmid, array $auth): bool {
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/start";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL startContainer: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    return isset($json['data']);
}

/**
 * Ferma un container LXC tramite API Proxmox
 */
function stopContainer(int $vmid, array $auth): bool {
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc/$vmid/status/stop";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL stopContainer: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    return isset($json['data']);
}

/**
 * Crea un container LXC tramite API Proxmox
 */
function createLxcContainer(array $params, array $auth): int {
    $node = PROXMOX_NODE;
    $url = PROXMOX_API_URL . "/nodes/$node/lxc";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL createLxcContainer: ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (isset($json['errors'])) {
        throw new Exception('Errore creazione container: ' . json_encode($json['errors']));
    }

    return $params['vmid'];
}
