<?php
// install_server.php
// Script CLI per avviare installazione server Minecraft via SSH su VM

if (php_sapi_name() !== 'cli') {
    die("Questo script va eseguito da CLI.\n");
}

// Ordine parametri:
// 1) serverId
// 2) type (vanilla/modpack/bukkit)
// 3) versionOrSlug
// 4) downloadUrl (opzionale per modpack)
// 5) installMethod (opzionale per modpack)

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

// Dati SSH statici
$sshUser = 'diego';

try {
    // Connessione al database per recuperare IP della VM assegnata
    require 'config/config.php';

    $stmt = $pdo->prepare("SELECT v.ip FROM servers s JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.id = ?");
    $stmt->execute([$serverId]);
    $vm = $stmt->fetch();

    if (!$vm) {
        throw new Exception("❌ VM non trovata per server ID $serverId.");
    }

    $ip = $vm['ip'];
    echo "✅ Trovato IP VM: $ip\n";

    // Composizione comando remoto
    $remoteCommand = "bash /home/diego/setup_server.sh '$type' '$versionOrSlug' '$downloadUrl' '$installMethod' '$serverId'";

    echo "📡 Eseguo comando remoto su $ip:\n$remoteCommand\n";

    $sshCommand = "ssh -o StrictHostKeyChecking=no $sshUser@$ip \"$remoteCommand\"";

    // Esecuzione
    exec($sshCommand, $output, $resultCode);

    foreach ($output as $line) {
        echo $line . "\n";
    }

    if ($resultCode !== 0) {
        echo "❌ Errore durante l'installazione remota. Codice: $resultCode\n";
        exit(1);
    }

    echo "✅ Installazione completata con successo.\n";
    exit(0);

} catch (Exception $e) {
    echo "❌ Errore: " . $e->getMessage() . "\n";
    exit(1);
}
