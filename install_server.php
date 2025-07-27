<?php
if ($argc < 4) {
    echo "❌ Utilizzo: php install_server.php <vm_ip> <server_id> <type> [version/modpack_id]\n";
    exit(1);
}

$vmIp = $argv[1];
$serverId = $argv[2];
$type = $argv[3];
$versionOrModpack = $argv[4] ?? null;

if (!filter_var($vmIp, FILTER_VALIDATE_IP)) {
    echo "❌ IP VM non valido: $vmIp\n";
    exit(1);
}

require 'config/config.php';

$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    echo "❌ Server con ID $serverId non trovato.\n";
    exit(1);
}

$sshUser = 'diego';
$sshTarget = "$sshUser@$vmIp";
$remoteScript = "/home/$sshUser/setup_server.sh";

switch ($type) {
    case 'vanilla':
    case 'bukkit':
        if (!$versionOrModpack) {
            echo "❌ Devi specificare una versione per Vanilla/Bukkit.\n";
            exit(1);
        }

        $params = [
            escapeshellarg($remoteScript),
            escapeshellarg($type),
            escapeshellarg($versionOrModpack),
            escapeshellarg($serverId)
        ];
        $installCmd = "ssh $sshTarget " . implode(' ', $params);
        break;

    case 'modpack':
        if (!is_numeric($versionOrModpack)) {
            echo "❌ Devi specificare un ID numerico valido per il modpack.\n";
            exit(1);
        }

        $modpackId = intval($versionOrModpack);
        $modpackStmt = $pdo->prepare("SELECT * FROM modpacks WHERE id = ?");
        $modpackStmt->execute([$modpackId]);
        $modpack = $modpackStmt->fetch(PDO::FETCH_ASSOC);

        if (!$modpack) {
            echo "❌ Modpack con ID $modpackId non trovato.\n";
            exit(1);
        }

        $params = [
            escapeshellarg($remoteScript),
            escapeshellarg('modpack'),
            escapeshellarg($modpack['slug']),
            escapeshellarg($modpack['downloadUrl']),
            escapeshellarg($modpack['installMethod']),
            escapeshellarg($serverId)
        ];
        $installCmd = "ssh $sshTarget " . implode(' ', $params);
        break;

    default:
        echo "❌ Tipo di server non valido: $type\n";
        exit(1);
}

echo "➡️  Eseguo: $installCmd\n";

exec($installCmd, $output, $exitCode);

if ($exitCode === 0) {
    echo "✅ Installazione completata.\n";
    exit(0);
} else {
    echo "❌ Errore durante l'installazione (exit code $exitCode):\n";
    echo implode("\n", $output) . "\n";
    exit(1);
}
