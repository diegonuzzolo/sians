<?php
// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dipendenze e controlli iniziali
require 'auth_check.php';
require 'config/config.php'; // Contiene PINGGY_CONNECTION_STRING e PINGGY_EXECUTABLE_PATH
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';

// Sicurezza: Usa solo il metodo POST per azioni che modificano lo stato del server
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

// Validazione robusta del nome del server
if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $serverName)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido. Usa solo lettere, numeri e trattini (3-20 caratteri)."));
    exit;
}

if (!$planCode || !$slotId) {
    header("Location: dashboard.php?error=" . urlencode("Parametri mancanti."));
    exit;
}

// Avvia una singola transazione per tutte le operazioni sul database
$pdo->beginTransaction();

try {
    // --- 2. Validazione Dati e Slot ---
    // La query recupera anche i dettagli del piano per efficienza
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

    // --- 3. Allocazione Risorse Interne (VMID e IP) ---
    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo);

    // --- 4. Inserimento Record nel Database ---
    $stmtServer = $pdo->prepare("INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress) VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, 0)");
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb]);
    $serverId = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)");
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $vmIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    // Aggiornamenti atomici
    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);

    // --- 5. Creazione della Risorsa Esterna (Proxmox) ---
    // Eseguita solo se tutte le operazioni sul DB sono valide. Se fallisce, il catch annulla tutto.
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $vmIp, $plan['cpu_cores']);
    if (!$createResult['success']) {
        throw new Exception("Errore durante la creazione del container in Proxmox: " . $createResult['message']);
    }

    // Se tutto è andato a buon fine, conferma le modifiche al database.
    $pdo->commit();

} catch (Exception $e) {
    // Se si verifica un errore in qualsiasi punto del blocco try, annulla tutte le modifiche.
    $pdo->rollBack();
    header("Location: dashboard.php?error=" . urlencode("Creazione fallita: " . $e->getMessage()));
    exit;
}

// --- 6. Setup Remoto e Avvio Tunnel Pinggy ---
// Questa sezione viene eseguita solo se la transazione e la creazione sono riuscite.
try {
    sleep(5); // Attesa di 5 secondi per permettere al container di avviarsi completamente.
    
    // Parte A: Setup del server tramite SSH
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251";
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";
    
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x $remoteTemp && $remoteTemp $containerId > /root/setup.log 2>&1 &\"");

    // Parte B: Avvio del tunnel Pinggy con stringa di connessione SSH
    $pinggyExecutable = PINGGY_EXECUTABLE_PATH . '/pinggy';
    $pinggyConnectionString = PINGGY_CONNECTION_STRING;
    $pinggySubdomain = $serverName . '.mc.sians.it';
    $localIp = $vmIp;
    $localPort = '19132'; // Porta Bedrock. Cambia in '25565' per Java Edition.

    // Costruzione della regola di port forwarding remoto con il sottodominio
    $remoteForwardRule = "{$pinggySubdomain}:0:{$localIp}:{$localPort}";

    $screenSessionName = "pinggy-{$vmid}";
    $pinggyLogFile = "/var/www/logs/pinggy_vmid_{$vmid}.log";

    // Comando completo per avviare pinggy in background tramite screen, con sudo
    $command = "sudo /usr/bin/screen -dmS {$screenSessionName} {$pinggyExecutable} -R {$remoteForwardRule} {$pinggyConnectionString} >> {$pinggyLogFile} 2>&1";
    
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Impossibile avviare il tunnel. Controlla i log di sistema.");
    }
    
} catch (Exception $e) {
    // Se il setup remoto o l'avvio del tunnel falliscono, il server è comunque creato.
    // Invia un avviso all'utente.
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma il setup automatico o il tunnel potrebbero essere falliti."));
    exit;
}

// --- 7. Successo ---
header("Location: dashboard.php?success=" . urlencode("Il tuo nuovo server è stato creato e il tunnel è in fase di attivazione!"));
exit;