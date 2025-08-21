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
    // --- 2. Validazione Dati e Slot ---
    $stmtSlot = $pdo->prepare("
        SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id
        FROM server_slots ss 
        JOIN plans p ON ss.plan_id = p.stripe_price_id 
        WHERE ss.id = ? AND ss.user_id = ? AND ss.status = 'pending' AND ss.server_id IS NULL
    ");
    $stmtSlot->execute([$slotId, $userId]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception("Slot non disponibile o non valido.");
    }

    $plan = [
        'id' => $slot['plan_id'],
        'ram_gb' => $slot['ram_gb'],
        'cpu_cores' => $slot['cpu_cores'],
        'storage_gb' => $slot['storage_gb']
    ];
    $ramMb = $plan['ram_gb'] * 1024;

    // --- 3. Allocazione Risorse (VMID e IP) ---
    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo);

    // --- 4. Inserimento Record nel Database ---
    $stmtServer = $pdo->prepare("INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb) VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?)");
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb]);
    $serverId = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)");
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $vmIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);

    // --- 5. Creazione Risorsa Esterna (Proxmox) ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $vmIp, $plan['cpu_cores']);
    if (!$createResult['success']) {
        throw new Exception("Errore durante la creazione del container in Proxmox: " . $createResult['message']);
    }
    
    $pdo->commit();
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: dashboard.php?error=" . urlencode("Creazione fallita: " . $e->getMessage()));
    exit;
}

// --- 6. Creazione Tunnel Cloudflare e Setup Remoto ---
try {
    sleep(4); // Attesa per l'avvio del container

    // Parte A: Creazione del Tunnel Cloudflare
    $tunnelName = "mc-{$vmid}";
    $subdomain = "{$serverName}.sians.it";

    // 1. Crea il tunnel e cattura l'UUID
    $output = [];
    exec("sudo /usr/bin/cloudflared tunnel create {$tunnelName} 2>&1", $output);
    
    $tunnelUuid = '';
    foreach ($output as $line) {
        if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/', $line, $matches)) {
            $tunnelUuid = $matches[1];
            break;
        }
    }
    if (empty($tunnelUuid)) {
        throw new Exception("Impossibile creare il tunnel Cloudflare o estrarre l'UUID.");
    }
    
    // Salva i dati del tunnel nel DB
    $pdo->prepare("UPDATE servers SET tunnel_id = ?, tunnel_uuid = ? WHERE id = ?")
        ->execute([$tunnelName, $tunnelUuid, $serverId]);

    // 2. Crea il file di configurazione
    $configFile = "/root/.cloudflared/{$tunnelName}.yml";
    $configContent = "tunnel: {$tunnelUuid}\n"
                   . "credentials-file: /root/.cloudflared/{$tunnelUuid}.json\n"
                   . "ingress:\n"
                   . "  - service: udp://{$vmIp}:19132\n" // Porta Bedrock
                   . "  - service: http_status:404\n";

    $command = "echo " . escapeshellarg($configContent) . " | sudo /usr/bin/tee {$configFile}";
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Impossibile scrivere il file di configurazione del tunnel. Codice: {$return_var}");
    }

    // 3. Crea il record DNS
    exec("sudo /usr/bin/cloudflared tunnel route dns {$tunnelName} {$subdomain}");

    // 4. Avvia e abilita il servizio
    exec("sudo /usr/bin/systemctl enable --now cloudflared@{$tunnelName}.service");
    
    // Parte B: Setup del server remoto
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251";
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";
    
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");
    
    // **COMANDO DI ESECUZIONE CORRETTO**
    // Usa bash -c per eseguire la sequenza di comandi correttamente all'interno del container
    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

} catch (Exception $e) {
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma il setup del tunnel/software potrebbe essere fallito: " . $e->getMessage()));
    exit;
}

// --- 7. Finalizza lo stato del server ---
// **AGGIORNAMENTO DELLO STATO**
// Imposta lo stato a 'stopped' per indicare che il server è pronto per essere avviato.
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);

// --- 8. Successo ---
header("Location: dashboard.php?success=" . urlencode("Il tuo nuovo server è stato creato ed è pronto per essere avviato!"));
exit;

?>