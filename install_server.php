<?php
// install_server.php
if (php_sapi_name() !== 'cli') {
    die("Questo script va eseguito da CLI.\n");
}

if ($argc < 4) {
    echo "Errore: parametri insufficienti.\n";
    echo "Uso: php install_server.php <serverId> <type> <versionOrSlug> [downloadUrl] [installMethod]\n";
    exit(1);
}

$serverId = $argv[1];
$type = $argv[2];
$versionOrSlug = $argv[3];
$downloadUrl = $argv[4] ?? '';
$installMethod = $argv[5] ?? '';

function updateProgress($pdo, $serverId, $progress, $status = null) {
    if ($status) {
        $stmt = $pdo->prepare("UPDATE servers SET progress = ?, status = ? WHERE id = ?");
        $stmt->execute([$progress, $status, $serverId]);
    } else {
        $stmt = $pdo->prepare("UPDATE servers SET progress = ? WHERE id = ?");
        $stmt->execute([$progress, $serverId]);
    }
}

$sshUser = 'diego';

try {
    require 'config/config.php';

    updateProgress($pdo, $serverId, 10, 'installing');

    $stmt = $pdo->prepare("SELECT v.ip FROM servers s JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.id = ?");
    $stmt->execute([$serverId]);
    $vm = $stmt->fetch();

    if (!$vm) {
        throw new Exception("❌ VM non trovata per server ID $serverId.");
    }

    $ip = $vm['ip'];
    echo "✅ Trovato IP VM: $ip\n";

    // Seleziona comando in base al tipo
    switch ($type) {
        case 'vanilla':
        case 'bukkit':
            $remoteCommand = "bash /home/diego/setup_server.sh '$type' '$versionOrSlug' '' '' '$serverId'";
            break;
        case 'modpack':
            $remoteCommand = "bash /home/diego/setup_server.sh 'modpack' '$versionOrSlug' '$downloadUrl' '$installMethod' '$serverId'";
            break;
        default:
            throw new Exception("❌ Tipo server sconosciuto: $type");
    }

    updateProgress($pdo, $serverId, 50);
    echo "📡 Eseguo comando remoto su $ip:\n$remoteCommand\n";

    $sshCommand = "ssh -o StrictHostKeyChecking=no $sshUser@$ip \"$remoteCommand\"";

    exec($sshCommand, $output, $resultCode);

    foreach ($output as $line) {
        echo $line . "\n";
    }

    if ($resultCode !== 0) {
        echo "❌ Errore durante l'installazione remota. Codice: $resultCode\n";
        exit(1);
    }

    echo "✅ Installazione completata con successo.\n";
    updateProgress($pdo, $serverId, 100, 'ready');
    exit(0);

} catch (Exception $e) {
    echo "❌ Errore: " . $e->getMessage() . "\n";
    exit(1);
}
