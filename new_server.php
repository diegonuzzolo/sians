<?php
// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
<<<<<<< HEAD
=======
require 'vendor/autoload.php'; // Per la libreria Symfony YAML
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require 'vendor/autoload.php'; // Symfony YAML

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

<<<<<<< HEAD
// --- NUOVE COSTANTI PER IL VPS ---
define('VPS_USER', 'root'); // Utente SSH sul VPS
define('VPS_IP', '195.231.66.137'); // IP Pubblico del VPS
define('VPS_SSH_KEY', '/var/www/.ssh/id_proxmox'); // Chiave SSH per il VPS (consigliata una chiave dedicata)
define('PUBLIC_INTERFACE', 'eth0'); // Interfaccia pubblica del VPS
define('WIREGUARD_INTERFACE', 'wg0'); // Interfaccia WireGuard del VPS
define('MINECRAFT_BEDROCK_PORT', 19132);
define('START_PUBLIC_PORT', 20000); // Porta pubblica da cui iniziare ad assegnare

// Sicurezza: solo POST
=======
// Sicurezza: Usa solo il metodo POST
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

<<<<<<< HEAD
// Controlla login
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
=======
// Verifica che l'utente sia loggato
$idUtente = $_SESSION['user_id'] ?? 0;
if (!$idUtente) {
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

// --- 1. VALIDAZIONE INPUT ---
<<<<<<< HEAD
$slotId = $_POST['slot_id'] ?? null;
$planCode = $_POST['plan_code'] ?? null;
$serverName = trim($_POST['server_name'] ?? 'mcserver');

if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $serverName)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido."));
    exit;
}
if (!$slotId || !$planCode) {
=======
$idSlot = $_POST['slot_id'] ?? null;
$codicePiano = $_POST['plan_code'] ?? null;
$nomeServer = trim($_POST['server_name'] ?? 'mcserver');

if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $nomeServer)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido. Usa solo lettere, numeri e trattini (3-20 caratteri)."));
    exit;
}

if (!$codicePiano || !$idSlot) {
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    header("Location: dashboard.php?error=" . urlencode("Parametri mancanti."));
    exit;
}

// --- INIZIO TRANSAZIONE ---
$pdo->beginTransaction();
$newPublicPort = 0; // Inizializza la variabile
$vmIp = ''; // Inizializza per averla nello scope del catch

try {
    // --- 2. BLOCCO DELLO SLOT NEL DATABASE ---
    $stmtLock = $pdo->prepare(
        "UPDATE server_slots SET status = 'processing' 
         WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
<<<<<<< HEAD
    $stmtLock->execute([$slotId, $userId]);
=======
    $stmtLock->execute([$idSlot, $idUtente]);

>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    if ($stmtLock->rowCount() === 0) {
        throw new Exception("Slot non disponibile o già in uso.");
    }

<<<<<<< HEAD
    $stmtSlot = $pdo->prepare(
        "SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
         FROM server_slots ss 
         JOIN plans p ON ss.plan_id = p.stripe_price_id 
         WHERE ss.id = ?"
    );
    $stmtSlot->execute([$slotId]);
=======
    // Recupera i dettagli del piano e dello slot
    $stmtSlot = $pdo->prepare("SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
                               FROM server_slots ss 
                               JOIN plans p ON ss.plan_id = p.stripe_price_id 
                               WHERE ss.id = ?");
    $stmtSlot->execute([$idSlot]);
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);
    if (!$slot) throw new Exception("Errore critico: slot non trovato.");

    $piano = [
        'id' => $slot['plan_id'],
        'ram_gb' => $slot['ram_gb'],
        'cpu_cores' => $slot['cpu_cores'],
        'storage_gb' => $slot['storage_gb']
    ];
    $ramMb = $piano['ram_gb'] * 1024;

    // --- 3. ALLOCAZIONE RISORSE ---
    $vmid = getNextFreeVmid($pdo);
<<<<<<< HEAD
    $vmIp = getNextFreePrivateIp($pdo); // Questo sarà l'IP del container nel tunnel WireGuard

    // --- NUOVO: ALLOCAZIONE PORTA PUBBLICA ---
    $stmtPort = $pdo->query("SELECT MAX(public_port) as last_port FROM servers");
    $lastPort = $stmtPort->fetchColumn();
    $newPublicPort = $lastPort ? $lastPort + 1 : START_PUBLIC_PORT;


    // --- 4. INSERIMENTO DB ---
    $stmtServer = $pdo->prepare(
        "INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb, public_port) 
         VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?, ?)"
    );
    $stmtServer->execute([$serverName, $userId, $plan['id'], $slotId, $vmid, $plan['cpu_cores'], $ramMb, $newPublicPort]);
    $serverId = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare(
        "INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) 
         VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)"
    );
    $stmtContainer->execute([$vmid, $ramMb, $plan['storage_gb'], $userId, $serverName, $vmIp, $plan['cpu_cores'], $serverId]);
    $containerId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$containerId, $serverId]);
    $pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$serverId, $slotId]);
    $pdo->prepare(
        "UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1"
    )->execute([$plan['cpu_cores'], $ramMb, $plan['storage_gb']]);


    // --- 5. CREAZIONE CONTAINER SU PROXMOX ---
    $createResult = createLxcContainer($vmid, $plan['ram_gb'], $plan['storage_gb'], $serverName, $vmIp, $plan['cpu_cores']);
    if (!$createResult['success']) throw new Exception("Errore creazione container: " . $createResult['message']);
    
    // Attendi qualche secondo per assicurarti che il container sia completamente avviato prima di procedere
    sleep(5); 
    
    // --- 6. CONFIGURAZIONE PORT FORWARDING SU VPS ---
    $escapedVmIp = escapeshellarg($vmIp);
    $escapedNewPublicPort = escapeshellarg($newPublicPort);
    $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);
=======
    $ipVm = getNextFreePrivateIp($pdo);
    $sottodominio = "{$nomeServer}.sians.it";

    // --- 4. INSERIMENTO PRELIMINARE NEL DATABASE ---
    $stmtServer = $pdo->prepare("INSERT INTO servers (name, user_id, plan_id, slot_id, proxmox_containerId, status, type, cpu_cores, ram_mb) 
                                  VALUES (?, ?, ?, ?, ?, 'creating', 'bedrock', ?, ?)");
    $stmtServer->execute([$nomeServer, $idUtente, $piano['id'], $idSlot, $vmid, $piano['cpu_cores'], $ramMb]);
    $idServer = $pdo->lastInsertId();

    $stmtContainer = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id) 
                                     VALUES (?, ?, ?, ?, ?, ?, 'Creating', ?, ?)");
    $stmtContainer->execute([$vmid, $ramMb, $piano['storage_gb'], $idUtente, $nomeServer, $ipVm, $piano['cpu_cores'], $idServer]);
    $idContainer = $pdo->lastInsertId();

    $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?")->execute([$idContainer, $idServer]);

    // --- 5. CREAZIONE CONTAINER SU PROXMOX ---
    $risultatoCreazione = createLxcContainer($vmid, $piano['ram_gb'], $piano['storage_gb'], $nomeServer, $ipVm, $piano['cpu_cores']);
    if (!$risultatoCreazione['success']) {
        throw new Exception("Errore durante la creazione del container in Proxmox: " . $risultatoCreazione['message']);
    }
    sleep(4);
    // --- 6. AGGIORNAMENTO AUTOMATICO CONFIGURAZIONE CLOUDFLARED ---
    try {
        $fileConfigTunnel = '/etc/cloudflared/config.yml';
        
        $config = Yaml::parseFile($fileConfigTunnel);

        $regolaCatchAll = array_pop($config['ingress']);

        $nuovaRegolaIngress = [
            'hostname' => $sottodominio,
            'service' => "udp://{$ipVm}:19132",
        ];
        $config['ingress'][] = $nuovaRegolaIngress;
        
        $config['ingress'][] = $regolaCatchAll;

        $yaml = Yaml::dump($config, 4); 
        file_put_contents($fileConfigTunnel, $yaml);

        exec('sudo systemctl reload cloudflared');

    } catch (Exception $e) {
        throw new Exception("Server creato, ma fallito l'aggiornamento del tunnel: " . $e->getMessage());
    }
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3

    // Regola DNAT: Inoltra la porta pubblica alla porta 19132 del container
    $dnatRule = "iptables -t nat -A PREROUTING -i " . PUBLIC_INTERFACE . " -p udp --dport {$escapedNewPublicPort} -j DNAT --to-destination {$escapedVmIp}:{$minecraftPort}";
    
    // Regola FORWARD: Permetti il passaggio dei pacchetti
    $forwardRule = "iptables -A FORWARD -i " . PUBLIC_INTERFACE . " -o " . WIREGUARD_INTERFACE . " -d {$escapedVmIp} -p udp --dport {$minecraftPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
    
    // Comando per salvare le regole
    $saveRules = "netfilter-persistent save";

    $remoteCommand = escapeshellarg("{$dnatRule} && {$forwardRule} && {$saveRules}");
    $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";

    exec($sshCommand, $output, $return_var);

    if ($return_var !== 0) {
        throw new Exception("Configurazione del firewall sul VPS fallita. Output: " . implode("\n", $output));
    }
    
    // Commit della transazione solo se tutto è andato a buon fine
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // --- Rollback delle regole IPTABLES in caso di fallimento ---
    if ($newPublicPort > 0 && !empty($vmIp)) {
        // Tenta di rimuovere le regole iptables se sono state aggiunte prima del fallimento
        $escapedVmIp = escapeshellarg($vmIp);
        $escapedNewPublicPort = escapeshellarg($newPublicPort);
        $minecraftPort = escapeshellarg(MINECRAFT_BEDROCK_PORT);
        $dnatRule = "iptables -t nat -D PREROUTING -i " . PUBLIC_INTERFACE . " -p udp --dport {$escapedNewPublicPort} -j DNAT --to-destination {$escapedVmIp}:{$minecraftPort}";
        $forwardRule = "iptables -D FORWARD -i " . PUBLIC_INTERFACE . " -o " . WIREGUARD_INTERFACE . " -d {$escapedVmIp} -p udp --dport {$minecraftPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
        $saveRules = "netfilter-persistent save";
        $remoteCommand = escapeshellarg("{$dnatRule} && {$forwardRule} && {$saveRules}");
        $sshCommand = "ssh -i " . VPS_SSH_KEY . " -o StrictHostKeyChecking=no " . VPS_USER . "@" . VPS_IP . " {$remoteCommand}";
        exec($sshCommand); // Esegui senza controllare l'output, è un tentativo di pulizia
    }
<<<<<<< HEAD

    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false
            ? 'Nome server già esistente.'
            : 'Creazione fallita: ' . $e->getMessage()
=======
    // Gestione errori e redirect con messaggio flash
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false 
                  ? 'Nome server già esistente. Scegli un nome diverso.' 
                  : 'Creazione fallita: ' . $e->getMessage()
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    ];
    header("Location: dashboard.php");
    exit;
}

// --- 7. CONFIGURAZIONE DNS SU CLOUDFLARE ---
try {
<<<<<<< HEAD
    $subdomain = "{$serverName}.sians.it";
    $headers = ["Authorization: Bearer " . CLOUDFLARE_API_TOKEN, "Content-Type: application/json"];

    // A Record punta al VPS pubblico
    $aRecord = ["type" => "A", "name" => $serverName, "content" => VPS_IP, "ttl" => 120, "proxied" => false];
=======
    $hostnameTunnel = "minecraft-proxy.sians.it"; // Hostname generico che punta al tunnel

    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    // CNAME Record: Fa puntare il sottodominio del server al tunnel
    $recordCNAME = [
        "type" => "CNAME",
        "name" => $nomeServer,
        "content" => $hostnameTunnel,
        "ttl" => 1, // Auto
        "proxied" => true
    ];

>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($recordCNAME));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);

<<<<<<< HEAD
    // SRV Record per Minecraft Bedrock che punta alla porta pubblica
    $srvRecord = [
        "type" => "SRV",
        "data" => [
            "service" => "_minecraft",
            "proto" => "_udp",
            "name" => $serverName,
            "priority" => 1,
            "weight" => 1,
            "port" => $newPublicPort, // Usa la porta pubblica assegnata
            "target" => $subdomain
        ],
        "ttl" => 120
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($srvRecord));
=======
    // SRV Record: Specifica per i client Minecraft
    $recordSRV = [
        "type" => "SRV",
        "data" => [
            "service" => "_minecraft", "proto" => "_udp", "name" => $nomeServer,
            "priority" => 1, "weight" => 1, "port" => 19132, "target" => $sottodominio
        ]
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($recordSRV));
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
    curl_exec($ch);
    curl_close($ch);

} catch (Exception $e) {
<<<<<<< HEAD
    // Non bloccare il processo se il DNS fallisce, ma avvisa l'utente
    $_SESSION['flash_message'] = [
        'type' => 'warning',
        'text' => "Server creato ma configurazione DNS fallita: " . $e->getMessage()
    ];
}


// --- 8. SETUP SOFTWARE REMOTO NEL CONTAINER ---
=======
    // Il server è stato creato, ma il DNS è fallito
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma DNS non configurato: " . $e->getMessage()));
    exit;
}

// --- 8. Setup software remoto ---
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3
try {
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251"; // Nodo Proxmox locale
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";

    // Aggiorna pacchetti e installa software nel container
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");

    // Copia script setup
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");

    // Esegui script in background
    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

} catch (Exception $e) {
     $_SESSION['flash_message'] = [
        'type' => 'warning',
        'text' => "Server creato ma il setup del software remoto potrebbe essere fallito: " . $e->getMessage()
    ];
}

<<<<<<< HEAD
// --- 9. FINALIZZA STATO SERVER ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$serverId]);
=======

// --- 9. FINALIZZA STATO E REDIRECT ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$idServer]);
$pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$idServer, $idSlot]);
$pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")
    ->execute([$piano['cpu_cores'], $ramMb, $piano['storage_gb']]);
>>>>>>> 9c97fad2ee772d683dfc16e639054b39e2960fa3

// Se non c'è già un messaggio di warning, imposta quello di successo
if (!isset($_SESSION['flash_message'])) {
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'text' => "Server creato! Connettiti usando l'indirizzo: {$serverName}.sians.it"
    ];
}

header("Location: dashboard.php");
exit;
?>
