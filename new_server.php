<?php
// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dipendenze e controlli iniziali
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';

// Sicurezza: Usa solo il metodo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

// Verifica che l'utente sia loggato
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

// --- 1. Validazione Input ---
$slotId = $_POST['slot_id'] ?? null;
$planCode = $_POST['plan_code'] ?? null;
$serverName = trim($_POST['server_name'] ?? 'mcserver');

if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $serverName)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido. Usa solo lettere, numeri e trattini (3-20 caratteri)."));
    exit;
}

if (!$planCode || !$slotId) {
    header("Location: dashboard.php?error=" . urlencode("Parametri mancanti."));
    exit;
}

$pdo->beginTransaction();

try {
    // --- 2. BLOCCO SLOT ---
    $stmtLock = $pdo->prepare(
        "UPDATE server_slots SET status = 'processing' 
         WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmtLock->execute([$slotId, $userId]);

    if ($stmtLock->rowCount() === 0) {
        throw new Exception("Slot non disponibile, già in uso o non valido. Riprova.");
    }

    $stmtSlot = $pdo->prepare("SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
                               FROM server_slots ss 
                               JOIN plans p ON ss.plan_id = p.stripe_price_id 
                               WHERE ss.id = ?");
    $stmtSlot->execute([$slotId]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception("Errore critico: impossibile recuperare i dati dello slot dopo il blocco.");
    }

    $plan = [
        'id' => $slot['plan_id'],
        'ram_gb' => $slot['ram_gb'],
        'cpu_cores' => $slot['cpu_cores'],
        'storage_gb' => $slot['storage_gb']
    ];
    $ramMb = $plan['ram_gb'] * 1024;

    // --- 3. Allocazione Risorse ---
    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo);

    // --- 4. Inserimento DB ---
    $stmtServer = $pdo->prepare("INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb) 
                                 VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?)");
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb]);
    $serverId = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) 
                                    VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)");
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $vmIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")
        ->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);

    // --- 5. Creazione container su Proxmox ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $vmIp, $plan['cpu_cores']);
    if (!$createResult['success']) {
        throw new Exception("Errore durante la creazione del container in Proxmox: " . $createResult['message']);
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_message'] = [
        'type' => 'error', // 'error', 'success', 'warning'
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false
            ? 'Nome server già esistente. Scegli un nome diverso.'
            : 'Creazione fallita: ' . $e->getMessage()
    ];
    header("Location: dashboard.php");
    exit;

}

// --- 6. Setup DNS su Cloudflare ---
try {
    $subdomain = "{$serverName}.sians.it";

    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    // A Record
    $aRecord = [
        "type" => "A",
        "name" => $serverName,
        "content" => $vmIp,
        "ttl" => 120,
        "proxied" => false
    ];

    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($aRecord));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $respA = curl_exec($ch);

    // SRV Record
    $srvRecord = [
        "type" => "SRV",
        "data" => [
            "service" => "_minecraft",
            "proto" => "_udp",
            "name" => $serverName,
            "priority" => 1,
            "weight" => 1,
            "port" => 19132,
            "target" => $subdomain
        ],
        "ttl" => 120
    ];

    curl_setopt($ch, CURLOPT_URL, "https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvRecord));
    $respSRV = curl_exec($ch);

    curl_close($ch);

} catch (Exception $e) {
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma il DNS non è stato configurato: " . $e->getMessage()));
    exit;
}

// --- 7. Setup software remoto ---
try {
     $sshKey = "/var/www/.ssh/id_rsa_container"; // chiave privata di www-data
    $publicKey = trim(file_get_contents('/var/www/.ssh/id_rsa_container.pub')); 
    $escapedKey = escapeshellarg($publicKey); // escape corretto per shell

    $nodeIp = "192.168.1.251"; // IP nodo Proxmox
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";

    // --- 1. Installa dipendenze nel container ---
    $installCmd = "bash -c \"apt-get update && apt-get install -y curl jq unzip screen\"";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- $installCmd\"");

    // --- 2. Copia script di setup ---
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");

    // --- 3. Copia chiave pubblica nel container e imposta permessi ---
    $sshSetupCmd = "bash -c 'mkdir -p /root/.ssh && chmod 700 /root/.ssh && echo $escapedKey >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- $sshSetupCmd\"");

    // --- 4. Esegui script di setup container ---
    $remoteExecCmd = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- $remoteExecCmd\"");


} catch (Exception $e) {
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma il setup software potrebbe essere fallito: " . $e->getMessage()));
    exit;
}

// --- 8. Finalizza stato ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);

header("Location: dashboard.php?success=" . urlencode("Il tuo nuovo server è stato creato ed è pronto per essere avviato!"));
exit;
?>