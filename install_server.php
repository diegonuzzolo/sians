<?php
require 'config/config.php';

function getServerJarUrl($version = null) {
    $manifestUrl = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';
    $manifest = json_decode(file_get_contents($manifestUrl), true);

    if (!$manifest || !isset($manifest['versions'])) {
        throw new Exception("❌ Impossibile ottenere l'elenco delle versioni Minecraft.");
    }

    if (!$version) {
        $version = $manifest['latest']['release'];
    }

    $versionData = array_filter($manifest['versions'], fn($v) => $v['id'] === $version);
    if (empty($versionData)) {
        throw new Exception("❌ Versione Minecraft '$version' non trovata.");
    }

    $versionInfoUrl = array_values($versionData)[0]['url'];
    $versionInfo = json_decode(file_get_contents($versionInfoUrl), true);

    return $versionInfo['downloads']['server']['url'] ?? null;
}

// 🔧 Parametri da CLI o GET
$vmIp = $argv[1];              // 192.168.1.101
$serverId = $argv[2];          // myserver123
$modpackId = $argv[3] ?? null; // opzionale

$remoteUser = 'diego';
$remoteBaseDir = "/home/diego/server";

$db = new PDO('mysql:host=localhost;dbname=minecraft_platform', 'diego', 'Lgu8330Serve6');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    if ($modpackId) {
        // 🎯 Installazione modpack
        $stmt = $db->prepare("SELECT * FROM modpacks WHERE id = :id");
        $stmt->execute([':id' => $modpackId]);
        $modpack = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$modpack) throw new Exception("❌ Modpack con ID $modpackId non trovato.");

        $downloadUrl = $modpack['downloadUrl'];
        $installMethod = $modpack['installMethod'];
        $minecraftVersion = $modpack['minecraftVersion'];
        $forgeVersion = $modpack['forgeVersion'];

        echo "🔍 Installazione modpack: {$modpack['name']} (Minecraft $minecraftVersion)\n";

        $commands = [
            "mkdir -p $remoteBaseDir",
            "cd $remoteBaseDir",
            "wget -O modpack.zip '$downloadUrl'",
            "unzip -o modpack.zip -d .",
            "rm modpack.zip"
        ];

        if ($installMethod === 'forge') {
            $commands[] = "java -jar forge-installer.jar --installServer";
            $startCommand = "screen -dmS minecraft java -Xmx10G -Xms10G -jar forge-$forgeVersion.jar nogui";
        } elseif ($installMethod === 'fabric') {
            $startCommand = "screen -dmS minecraft java -Xmx10G -Xms10G -jar fabric-server-launch.jar nogui";
        } else {
            // fallback
            $startCommand = "screen -dmS minecraft java -Xmx10G -Xms10G -jar server.jar nogui";
        }

        $commands[] = "echo 'eula=true' > eula.txt";
        $commands[] = "echo '$startCommand' > start.sh";
        $commands[] = "echo 'screen -S minecraft -X quit' > stop.sh";
        $commands[] = "chmod +x start.sh stop.sh";

    } else {
        // 🎯 Installazione Vanilla
        $minecraftVersion = $argv[4] ?? null;
        echo "🔍 Installazione Vanilla per versione $minecraftVersion\n";
        $serverJarUrl = getServerJarUrl($minecraftVersion);
        if (!$serverJarUrl) throw new Exception("❌ Nessun URL trovato per il server.jar");

        $serverProperties = <<<EOT
server-port=25565
max-players=50
motd=Server Vanilla $minecraftVersion
enable-command-block=true
level-name=world
online-mode=true
difficulty=1
spawn-monsters=true
spawn-npcs=true
spawn-animals=true
pvp=true
allow-nether=true
max-build-height=256
view-distance=32
white-list=false
generate-structures=true
hardcore=false
enable-rcon=false
gamemode=0
EOT;

        $commands = [
            "mkdir -p $remoteBaseDir",
            "cd $remoteBaseDir",
            "wget -O server.jar '$serverJarUrl'",
            "chmod +x server.jar",
            "echo 'eula=true' > eula.txt",
            "echo " . escapeshellarg($serverProperties) . " > server.properties",
            "echo 'screen -dmS minecraft java -Xmx10G -Xms10G -jar server.jar nogui' > start.sh",
            "echo 'screen -S minecraft -X quit' > stop.sh",
            "chmod +x start.sh stop.sh",
        ];
    }

    $fullCommand = implode(" && ", $commands);
    $sshCommand = "ssh -o StrictHostKeyChecking=no $remoteUser@$vmIp \"$fullCommand\"";

    echo "🚀 Installazione su $vmIp...\n";
    exec($sshCommand, $output, $exitCode);

    if ($exitCode === 0) {
        echo "🎉 Server installato correttamente su $vmIp\n";
    } else {
        echo "❌ Errore SSH (exit code $exitCode)\n";
        print_r($output);
    }

} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}

// 🔁 Reindirizza alla creazione tunnel/DNS se usato via web
if (php_sapi_name() !== 'cli') {
    header("Location: create_tunnel_and_dns.php?server_id=$serverId");
    exit;
}