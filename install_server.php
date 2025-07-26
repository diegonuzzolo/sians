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

// 🔧 Parametri da CLI
$vmIp = $argv[1] ?? null;
$serverId = $argv[2] ?? uniqid("srv");
$typeOrId = $argv[3] ?? null;
$value = $argv[4] ?? null;

if (!$vmIp || !$typeOrId) {
    echo "Uso: php install_server.php <vm_ip> <server_id> <version | modpack> [modpack_id]\n";
    exit(1);
}

$remoteUser = 'diego';
$remoteBaseDir = "/home/diego/server/$serverId";

try {
    if ($typeOrId === 'modpack') {
        $modpackId = intval($value);
        echo "📦 Installazione modpack ID: $modpackId...\n";

        // 🔍 Recupera info modpack dal DB
        $stmt = $pdo->prepare("SELECT name, downloadUrl FROM modpacks WHERE id = ?");
        $stmt->execute([$modpackId]);
        $modpack = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$modpack) throw new Exception("❌ Modpack non trovato nel DB.");

        $url = $modpack['downloadUrl'];
        $modpackName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $modpack['name']);
        $filename = strtolower($modpackName) . ".zip";

        $commands = [
            "mkdir -p $remoteBaseDir",
            "cd $remoteBaseDir",
            "wget -O $filename '$url'",
            "unzip -o $filename -d .",
            "rm $filename",
            "echo 'screen -dmS minecraft java -Xmx10G -Xms10G -jar forge*.jar nogui' > start.sh",
            "echo 'screen -S minecraft -X quit' > stop.sh",
            "chmod +x start.sh stop.sh",
        ];

        $fullCommand = implode(" && ", $commands);
        $sshCommand = "ssh -o StrictHostKeyChecking=no $remoteUser@$vmIp \"$fullCommand\"";

        echo "🚀 Installazione modpack su $vmIp...\n";
        exec($sshCommand, $output, $exitCode);

        if ($exitCode === 0) {
            echo "🎉 Modpack installato su $remoteBaseDir\n";
        } else {
            echo "❌ Errore SSH (exit code $exitCode)\n";
            print_r($output);
        }

    } else {
        $minecraftVersion = $typeOrId;
        echo "🔍 Ottenimento server.jar per versione $minecraftVersion...\n";

        $serverJarUrl = getServerJarUrl($minecraftVersion);
        if (!$serverJarUrl) throw new Exception("❌ Nessun URL trovato per il server.jar");

        echo "✅ URL server.jar: $serverJarUrl\n";

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

        $fullCommand = implode(" && ", $commands);
        $sshCommand = "ssh -o StrictHostKeyChecking=no $remoteUser@$vmIp \"$fullCommand\"";

        echo "🚀 Installazione Vanilla su $vmIp...\n";
        exec($sshCommand, $output, $exitCode);

        if ($exitCode === 0) {
            echo "🎉 Server Vanilla $minecraftVersion installato su $remoteBaseDir\n";
        } else {
            echo "❌ Errore SSH (exit code $exitCode)\n";
            print_r($output);
        }
    }

    // Redirect a tunnel + DNS se chiamato via browser
    if (php_sapi_name() !== 'cli') {
        header("Location: create_tunnel_and_dns.php?server_id=$serverId");
        exit;
    }

} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
