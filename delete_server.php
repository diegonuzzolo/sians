<?php
// Mostra errori
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php'; 
require_once 'includes/csrf.php';

// Sicurezza
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

// Verifica login
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

$serverId = $_POST['server_id'] ?? null;
if (!$serverId) {
    header("Location: dashboard.php?error=" . urlencode("Server non specificato."));
    exit;
}

try {
    // Recupera i dati del server
    $stmt = $pdo->prepare("SELECT s.id, s.name, c.ip 
                           FROM servers s 
                           JOIN containers c ON c.server_id = s.id 
                           WHERE s.id = ? AND s.user_id = ?");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        throw new Exception("Server non trovato o non autorizzato.");
    }

    $serverName = $server['name'];
    $subdomain  = "{$serverName}.sians.it";

    // --- Rimozione record DNS ---
    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    // 1. Trova i record DNS (A e SRV) con quel nome
    $searchUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name={$subdomain}";
    $ch = curl_init($searchUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($resp, true);

    if (!empty($result['result'])) {
        foreach ($result['result'] as $record) {
            $recordId = $record['id'];
            $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $recordId;

            $ch = curl_init($deleteUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // 2. Elimina anche il record SRV (_minecraft._udp.subdomain)
    $srvSearch = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records?name=_minecraft._udp.{$subdomain}";
    $ch = curl_init($srvSearch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);

    $resultSrv = json_decode($resp, true);
    if (!empty($resultSrv['result'])) {
        foreach ($resultSrv['result'] as $record) {
            $recordId = $record['id'];
            $deleteUrl = "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records/" . $recordId;

            $ch = curl_init($deleteUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // --- Elimina il server dal DB ---
    $pdo->prepare("DELETE FROM containers WHERE server_id = ?")->execute([$serverId]);
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$serverId]);

    header("Location: dashboard.php?success=" . urlencode("Server e DNS rimossi correttamente!"));
    exit;

} catch (Exception $e) {
    header("Location: dashboard.php?error=" . urlencode("Errore eliminazione server: " . $e->getMessage()));
    exit;
}
