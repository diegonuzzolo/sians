<?php
function getServerJarUrl($version = null) {
    $manifestUrl = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';
    $manifest = json_decode(file_get_contents($manifestUrl), true);

    if (!$manifest || !isset($manifest['versions'])) {
        throw new Exception("❌ Impossibile ottenere l'elenco delle versioni Minecraft.");
    }

    // Se la versione non è specificata, prendi la latest release
    if (!$version) {
        $version = $manifest['latest']['release'];
    }

    // Cerca la versione specificata
    $versionData = array_filter($manifest['versions'], fn($v) => $v['id'] === $version);
    if (empty($versionData)) {
        throw new Exception("❌ Versione Minecraft '$version' non trovata.");
    }

    $versionInfoUrl = array_values($versionData)[0]['url'];
    $versionInfo = json_decode(file_get_contents($versionInfoUrl), true);

    return $versionInfo['downloads']['server']['url'] ?? null;
}

// 🔧 Parametri da CLI
$vmIp = $argv[1]; // Es: 192.168.1.101
$serverId = $argv[2] ?? uniqid("srv");
$minecraftVersion = $argv[3] ?? null;

$remoteUser = 'diego';
$remoteBaseDir = "/home/diego/servers/$serverId";

try {
    echo "🔍 Ottenimento server.jar per versione $minecraftVersion...\n";
    $serverJarUrl = getServerJarUrl($minecraftVersion);
    if (!$serverJarUrl) throw new Exception("❌ Nessun URL trovato per il server.jar");

    echo "✅ URL server.jar: $serverJarUrl\n";

    // ✅ Comandi da eseguire sulla VM
    $commands = [
        "mkdir -p $remoteBaseDir",
        "cd $remoteBaseDir",
        "wget -O server.jar '$serverJarUrl'",
        "chmod +x server.jar",
        "echo 'eula=true' > eula.txt",
        "echo 'motd=Server Vanilla $minecraftVersion' > server.properties",
        "echo 'server-port=25565' >> server.properties",
        "echo 'enable-command-block=true' >> server.properties"
    ];

    $fullCommand = implode(" && ", $commands);
    $sshCommand = "ssh -o StrictHostKeyChecking=no $remoteUser@$vmIp \"$fullCommand\"";

    echo "🚀 Installazione su $vmIp...\n";
    exec($sshCommand, $output, $exitCode);

    if ($exitCode === 0) {
        echo "🎉 Server Vanilla $minecraftVersion installato su $remoteBaseDir\n";
    } else {
        echo "❌ Errore SSH (exit code $exitCode)\n";
        print_r($output);
    }

} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
