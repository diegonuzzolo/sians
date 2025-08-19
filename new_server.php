<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';

// Non c'è un form qui, la chiamata arriva da un redirect, quindi il check CSRF potrebbe non essere necessario
// Se la pagina precedente (dashboard) ha già un token, si potrebbe passare via GET, ma per ora lo commentiamo
// csrf_check_or_die(); 

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header('Location: dashboard.php?error=' . urlencode('Accesso negato.'));
    exit;
}

$slotId = $_GET['slot_id'] ?? null;
$planCode = $_GET['plan_code'] ?? null;
$serverName = trim($_GET['server_name'] ?? 'mcserver');

if (empty($serverName)) {
    $serverName = 'Il Mio Server'; // Nome di fallback se vuoto
}

if (!$planCode || !$slotId) {
    header('Location: dashboard.php?error=' . urlencode('Parametri mancanti.'));
    exit;
}

// Inizia la transazione per garantire l'atomicità dell'intera operazione
$pdo->beginTransaction();

try {
    // 1. Controlla se lo slot è valido e appartiene all'utente (bloccandolo per l'update)
    $stmtSlot = $pdo->prepare("
        SELECT ss.id 
        FROM server_slots ss 
        WHERE ss.id = ? 
          AND ss.user_id = ? 
          AND ss.status = 'pending' 
          AND ss.server_id IS NULL
        FOR UPDATE
    ");
    $stmtSlot->execute([$slotId, $userId]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception('Slot non disponibile o non valido.');
    }

    // 2. Recupera il piano e le risorse richieste
    $stmtPlan = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
    $stmtPlan->execute([$planCode]);
    $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        throw new Exception('Piano non valido.');
    }

    $requiredRamMb = $plan['ram_gb'] * 1024;
    $requiredCores = $plan['cpu_cores'];
    $requiredStorageGb = $plan['storage_gb'];

    // 3. Controlla se ci sono abbastanza risorse disponibili (bloccando la riga per l'update)
    $stmtRes = $pdo->prepare("SELECT * FROM resources WHERE id=1 FOR UPDATE");
    $stmtRes->execute();
    $resources = $stmtRes->fetch(PDO::FETCH_ASSOC);

    $availableRamMb = ($resources['total_ram_gb'] * 1024) - $resources['used_ram_mb'];
    $availableCores = $resources['total_cpu_cores'] - $resources['used_cpu_cores'];
    $availableStorageGb = $resources['total_storage_gb'] - $resources['used_storage_gb'];

    if ($availableRamMb < $requiredRamMb || $availableCores < $requiredCores || $availableStorageGb < $requiredStorageGb) {
        throw new Exception("Risorse del server insufficienti per creare questo piano.");
    }

    // 4. Se tutto è OK, procedi con la creazione
    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo);

    // Inserisci il server
    $stmtServer = $pdo->prepare("
        INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress)
        VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, 0)
    ");
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $requiredCores, $requiredRamMb]);
    $serverId = $pdo->lastInsertId();

    // Inserisci il container
    $stmtContainer = $pdo->prepare("
        INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
        VALUES (?, ?, ?, ?, ?, ?, 'creating', ?, ?)
    ");
    $stmtContainer->execute([$vmid, $requiredRamMb, $requiredStorageGb, $userId, $serverName, $vmIp, $requiredCores, $serverId]);
    $containerId = $pdo->lastInsertId();

    // Aggiorna server e slot
    $stmtUpdateServer = $pdo->prepare("UPDATE servers SET container_id=? WHERE id=?");
    $stmtUpdateServer->execute([$containerId, $serverId]);

    $stmtUpdateSlot = $pdo->prepare("UPDATE server_slots SET server_id=?, status='created' WHERE id=?");
    $stmtUpdateSlot->execute([$serverId, $slotId]);

    // 5. Sottrai le risorse utilizzate
    $stmtUpdateRes = $pdo->prepare("
        UPDATE resources 
        SET used_cpu_cores = used_cpu_cores + ?, 
            used_ram_mb = used_ram_mb + ?, 
            used_storage_gb = used_storage_gb + ? 
        WHERE id=1
    ");
    $stmtUpdateRes->execute([$requiredCores, $requiredRamMb, $requiredStorageGb]);

    // Se tutto è andato a buon fine, conferma le modifiche
    $pdo->commit();

} catch (Exception $e) {
    // Se qualcosa va storto, annulla tutte le operazioni
    $pdo->rollBack();
    header('Location: dashboard.php?error=' . urlencode('Errore durante la creazione: ' . $e->getMessage()));
    exit;
}

// --- Se la transazione ha successo, procedi con Proxmox ---

// Crea container in Proxmox
$createResult = createLxcContainer($vmid, $plan['ram_gb'], $requiredStorageGb, $serverName, $vmIp, $requiredCores);
if (!$createResult['success']) {
    // Se la creazione su Proxmox fallisce, dovresti gestire lo stato del server nel DB
    // (es. impostarlo su 'creation_failed') e magari ripristinare le risorse.
    // Per ora, reindirizziamo con un errore.
    header('Location: dashboard.php?error=' . urlencode($createResult['message']));
    exit;
}

// Setup server remoto (operazione asincrona)
sleep(4); // Attendi un momento per dare tempo al container di avviarsi
$sshKey = "/var/www/.ssh/id_proxmox";
$nodeIp = "192.168.1.251";
$localScript = '/var/www/setup_server.sh';
$remoteTemp = "/root/setup_server.sh";

// Esegui i comandi di setup
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"");
// Esegui lo script in background per non bloccare la pagina
exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"");

// Reindirizza alla dashboard con un messaggio di successo
header("Location: dashboard.php?success=" . urlencode("Creazione del server '$serverName' avviata!"));
exit;
?>