<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postServerName = $_POST['server_name'] ?? '';
    $postType = $_POST['type'] ?? 'vanilla';
    $postVersion = $_POST['version'] ?? '';
    $postModpackId = $_POST['modpack_id'] ?? '';

    if (empty($postServerName)) {
        $error = '⚠️ Inserisci un nome per il server.';
    } else {
        // Trova una VM libera
        $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $stmt->execute();
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = '❌ Nessuna VM disponibile.';
        } else {
            $userId = $_SESSION['user_id'];
            $vmId = $vm['id'];
            $vmIp = $vm['ip'];

            // Inserisce nuovo server
            $stmt = $pdo->prepare("INSERT INTO servers (user_id, vm_id, name, type, version_or_modpack_id) VALUES (?, ?, ?, ?, ?)");
            $installVersion = $postType === 'modpack' ? $postModpackId : $postVersion;
            $stmt->execute([$userId, $vmId, $postServerName, $postType, $installVersion]);

            $serverId = $pdo->lastInsertId();

            // Marca la VM come assegnata
            $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?")
                ->execute([$userId, $serverId, $vmId]);

            // Prepara installazione
            $sshUser = 'diego';
            $basePath = "/home/diego/$serverId";
            $sshBase = "ssh -o StrictHostKeyChecking=no $sshUser@$vmIp";

            // Crea cartella base
            exec("$sshBase 'mkdir -p $basePath && chmod 755 $basePath'");

            if ($postType === 'vanilla') {
                $version = escapeshellarg($postVersion);
                $manifest = json_decode(file_get_contents("https://launchermeta.mojang.com/mc/game/version_manifest.json"), true);
                $versionUrl = null;
                foreach ($manifest['versions'] as $v) {
                    if ($v['id'] === $postVersion) {
                        $versionUrl = $v['url'];
                        break;
                    }
                }
                if ($versionUrl) {
                    $detail = json_decode(file_get_contents($versionUrl), true);
                    $jarUrl = $detail['downloads']['server']['url'] ?? '';
                    if ($jarUrl) {
                        $jarUrl = escapeshellarg($jarUrl);
                        exec("$sshBase 'curl -o $basePath/server.jar $jarUrl'");
                    }
                }
            } elseif ($postType === 'modpack') {
                $modpacksJson = json_decode(file_get_contents("/var/www/html/modpacks.json"), true);
                $downloadUrl = null;
                foreach ($modpacksJson as $modpack) {
                    if ($modpack['id'] == intval($postModpackId)) {
                        $downloadUrl = $modpack['downloadUrl'] ?? null;
                        break;
                    }
                }
                if ($downloadUrl) {
                    $zipRemotePath = "$basePath/modpack.zip";
                    exec("$sshBase 'curl -L -o $zipRemotePath $downloadUrl && unzip -o $zipRemotePath -d $basePath && rm $zipRemotePath'");
                }
            } elseif ($postType === 'bukkit') {
                $bukkitUrl = escapeshellarg("https://download.getbukkit.org/craftbukkit/craftbukkit-$postVersion.jar");
                exec("$sshBase 'curl -o $basePath/server.jar $bukkitUrl'");
            }

            // Genera i file necessari
            exec("$sshBase 'echo \"eula=true\" > $basePath/eula.txt'");

            $properties = <<<EOF
motd=Benvenuto nel server $postServerName!
server-port=25565
max-players=20
online-mode=true
level-name=world
EOF;
            exec("$sshBase 'echo " . escapeshellarg($properties) . " > $basePath/server.properties'");

            $startScript = <<<SH
#!/bin/bash
cd "$basePath"
screen -dmS $serverId java -Xmx1024M -Xms1024M -jar server.jar nogui
SH;
            exec("$sshBase 'echo " . escapeshellarg($startScript) . " > $basePath/start.sh && chmod +x $basePath/start.sh'");

            $stopScript = <<<SH
#!/bin/bash
screen -S $serverId -X quit
SH;
            exec("$sshBase 'echo " . escapeshellarg($stopScript) . " > $basePath/stop.sh && chmod +x $basePath/stop.sh'");

            header("Location: dashboard.php");
            exit;
        }
    }
}
?>

<!-- HTML -->
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Crea Server Minecraft</title>
    <script>
        function toggleOptions() {
            const type = document.getElementById('type').value;
            document.getElementById('vanillaOptions').style.display = (type === 'vanilla' || type === 'bukkit') ? 'block' : 'none';
            document.getElementById('modpackOptions').style.display = (type === 'modpack') ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <h1>🛠️ Crea un nuovo server Minecraft</h1>
    <?php if ($error): ?>
        <p style="color:red"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>Nome server: <input type="text" name="server_name" required></label><br><br>

        <label>Tipo:
            <select name="type" id="type" onchange="toggleOptions()" required>
                <option value="vanilla">Vanilla</option>
                <option value="modpack">Modpack</option>
                <option value="bukkit">Bukkit</option>
            </select>
        </label><br><br>

        <div id="vanillaOptions">
            <label>Versione Minecraft:
                <input type="text" name="version" placeholder="es. 1.20.1">
            </label><br><br>
        </div>

        <div id="modpackOptions" style="display:none;">
            <label>Modpack ID:
                <input type="number" name="modpack_id" placeholder="es. 12345">
            </label><br><br>
        </div>

        <button type="submit">Crea server</button>
    </form>
</body>
</html>
