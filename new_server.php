<?php
// Mostra errori per il debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'vendor/autoload.php'; // Per la libreria Symfony YAML
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// Sicurezza: Usa solo il metodo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php?error=" . urlencode("Metodo non valido."));
    exit;
}
csrf_check_or_die();

// Verifica che l'utente sia loggato
$idUtente = $_SESSION['user_id'] ?? 0;
if (!$idUtente) {
    header("Location: login.php?error=" . urlencode("Accesso negato."));
    exit;
}

// --- 1. VALIDAZIONE INPUT ---
$idSlot = $_POST['slot_id'] ?? null;
$codicePiano = $_POST['plan_code'] ?? null;
$nomeServer = trim($_POST['server_name'] ?? 'mcserver');

if (!preg_match('/^[a-zA-Z0-9-]{3,20}$/', $nomeServer)) {
    header("Location: dashboard.php?error=" . urlencode("Nome server non valido. Usa solo lettere, numeri e trattini (3-20 caratteri)."));
    exit;
}

if (!$codicePiano || !$idSlot) {
    header("Location: dashboard.php?error=" . urlencode("Parametri mancanti."));
    exit;
}

$pdo->beginTransaction();

try {
    // --- 2. BLOCCO DELLO SLOT NEL DATABASE ---
    $stmtLock = $pdo->prepare(
        "UPDATE server_slots SET status = 'processing' 
         WHERE id = ? AND user_id = ? AND status = 'pending'"
    );
    $stmtLock->execute([$idSlot, $idUtente]);

    if ($stmtLock->rowCount() === 0) {
        throw new Exception("Slot non disponibile, già in uso o non valido. Riprova.");
    }

    // Recupera i dettagli del piano e dello slot
    $stmtSlot = $pdo->prepare("SELECT ss.*, p.stripe_price_id, p.ram_gb, p.cpu_cores, p.storage_gb, p.id as plan_id 
                               FROM server_slots ss 
                               JOIN plans p ON ss.plan_id = p.stripe_price_id 
                               WHERE ss.id = ?");
    $stmtSlot->execute([$idSlot]);
    $slot = $stmtSlot->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception("Errore critico: impossibile recuperare i dati dello slot dopo il blocco.");
    }

    $piano = [
        'id' => $slot['plan_id'],
        'ram_gb' => $slot['ram_gb'],
        'cpu_cores' => $slot['cpu_cores'],
        'storage_gb' => $slot['storage_gb']
    ];
    $ramMb = $piano['ram_gb'] * 1024;

    // --- 3. ALLOCAZIONE RISORSE ---
    $vmid = getNextFreeVmid($pdo);
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

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Gestione errori e redirect con messaggio flash
    $_SESSION['flash_message'] = [
        'type' => 'error',
        'text' => strpos($e->getMessage(), '1062 Duplicate entry') !== false 
                  ? 'Nome server già esistente. Scegli un nome diverso.' 
                  : 'Creazione fallita: ' . $e->getMessage()
    ];
    header("Location: dashboard.php");
    exit;
}

// --- 7. CONFIGURAZIONE DNS SU CLOUDFLARE ---
try {
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

    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($recordCNAME));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);

    // SRV Record: Specifica per i client Minecraft
    $recordSRV = [
        "type" => "SRV",
        "data" => [
            "service" => "_minecraft", "proto" => "_udp", "name" => $nomeServer,
            "priority" => 1, "weight" => 1, "port" => 19132, "target" => $sottodominio
        ]
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($recordSRV));
    curl_exec($ch);
    curl_close($ch);

} catch (Exception $e) {
    // Il server è stato creato, ma il DNS è fallito
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma DNS non configurato: " . $e->getMessage()));
    exit;
}

// --- 8. Setup software remoto ---
try {
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251";
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";

    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp $remoteTemp\"");

    $remoteExecCommand = "bash -c 'chmod +x {$remoteTemp} && {$remoteTemp} {$containerId} > /root/setup.log 2>&1 &'";
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- {$remoteExecCommand}\"");

} catch (Exception $e) {
    header("Location: dashboard.php?warning=" . urlencode("Server creato, ma il setup software potrebbe essere fallito: " . $e->getMessage()));
    exit;
}


// --- 9. FINALIZZA STATO E REDIRECT ---
$pdo->prepare("UPDATE servers SET status = 'stopped' WHERE id = ?")->execute([$idServer]);
$pdo->prepare("UPDATE server_slots SET server_id = ?, status = 'created' WHERE id = ?")->execute([$idServer, $idSlot]);
$pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id = 1")
    ->execute([$piano['cpu_cores'], $ramMb, $piano['storage_gb']]);

header("Location: dashboard.php?success=" . urlencode("Il tuo nuovo server è stato creato ed è pronto per essere avviato!"));
exit;
?>
