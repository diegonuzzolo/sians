<?php
if ($argc < 4) {
    echo "❌ Utilizzo: php install_server.php <vm_ip> <server_id> <type> [version/modpack_id]\n";
    exit(1);
}

$vmIp = $argv[1];
$serverId = $argv[2];
$type = $argv[3];
$versionOrModpack = $argv[4] ?? null;

require 'config/config.php';

// Recupera dati server
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo "❌ Server con ID $serverId non trovato.\n";
    exit(1);
}

$sshUser = 'diego'; // utente fisso
$sshTarget = "$sshUser@$vmIp";
$remoteScript = "/home/$sshUser/setup_server.sh";

// Costruzione comando remoto
switch ($type) {
    case 'vanilla':
    case 'bukkit':
        if (!$versionOrModpack) {
            echo "❌ Devi specificare una versione per Vanilla/Bukkit.\n";
            exit(1);
        }
        $version = escapeshellarg($versionOrModpack);
        $escapedType = escapeshellarg($type);
        $escapedServerId = escapeshellarg($serverId);
        $installCmd = "ssh $sshTarget 'bash $remoteScript $escapedType $version $escapedServerId'";
        break;

    case 'modpack':
        if (!is_numeric($versionOrModpack)) {
            echo "❌ Devi specificare un ID numerico valido per il modpack.\n";
            exit(1);
        }
        $modpackId = intval($versionOrModpack);

        // Carica dettagli modpack
        $modpackStmt = $pdo->prepare("SELECT * FROM modpacks WHERE id = ?");
        $modpackStmt->execute([$modpackId]);
        $modpack = $modpackStmt->fetch(PDO::FETCH_ASSOC);

        if (!$modpack) {
            echo "❌ Modpack con ID $modpackId non trovato.\n";
            exit(1);
        }

        $modpackSlug = escapeshellarg($modpack['slug']);
        $downloadUrl = escapeshellarg($modpack['downloadUrl']);
        $installMethod = escapeshellarg($modpack['installMethod']);
        $escapedServerId = escapeshellarg($serverId);

        $installCmd = "ssh $sshTarget 'bash $remoteScript modpack $modpackSlug $downloadUrl $installMethod $escapedServerId'";
        break;

    default:
        echo "❌ Tipo di server non valido: $type\n";
        exit(1);
}

// Debug (solo se necessario)
// echo "[DEBUG] Comando: $installCmd\n";

// Esegui installazione
exec($installCmd, $output, $exitCode);

if ($exitCode === 0) {
    echo "✅ Installazione completata.\n";
    exit(0);
} else {
    echo "❌ Errore durante l'installazione:\n" . implode("\n", $output);
    exit(1);
}






