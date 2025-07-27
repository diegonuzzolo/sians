<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? 'vanilla';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

// Se lo script non è eseguito da CLI, esce silenziosamente
if (php_sapi_name() !== 'cli') {
    return;
}

if ($argc < 5) {
    echo "❌ Parametri insufficienti. Uso: php install_server.php <vmIp> <serverId> <tipo> <versione/modpackId>\n";
    exit(1);
}

$vmIp = $argv[1];
$serverId = $argv[2];
$type = $argv[3];
$versionOrModpack = $argv[4];

$basePath = "/home/diego/$serverId";
$sshUser = 'diego';
$sshBase = "ssh -o StrictHostKeyChecking=no $sshUser@$vmIp";

exec("$sshBase 'mkdir -p $basePath && chmod 755 $basePath'");

if ($type === 'vanilla') {
    echo "🔍 Recupero URL server.jar per Vanilla $versionOrModpack...\n";

    $manifestJson = file_get_contents("https://launchermeta.mojang.com/mc/game/version_manifest.json");
    $manifest = json_decode($manifestJson, true);

    $versionMetaUrl = null;
    foreach ($manifest['versions'] as $v) {
        if ($v['id'] === $versionOrModpack) {
            $versionMetaUrl = $v['url'];
            break;
        }
    }

    if (!$versionMetaUrl) {
        echo "❌ Versione $versionOrModpack non trovata.\n";
        exit(1);
    }

    $versionDetailJson = file_get_contents($versionMetaUrl);
    $versionDetail = json_decode($versionDetailJson, true);
    $jarUrl = $versionDetail['downloads']['server']['url'] ?? null;

    if (!$jarUrl) {
        echo "❌ Nessun server.jar trovato per $versionOrModpack.\n";
        exit(1);
    }

    exec("$sshBase 'curl -o $basePath/server.jar $jarUrl'");

} elseif ($type === 'modpack') {
    $modpacksJson = file_get_contents("/var/www/html/modpacks.json");
    $modpacks = json_decode($modpacksJson, true);
    $downloadUrl = null;

    foreach ($modpacks as $modpack) {
        if ($modpack['id'] == intval($versionOrModpack)) {
            $downloadUrl = $modpack['downloadUrl'] ?? null;
            break;
        }
    }

    if (!$downloadUrl) {
        echo "❌ Modpack con ID $versionOrModpack non trovato o senza URL.\n";
        exit(1);
    }

    $zipRemotePath = "$basePath/modpack.zip";
    exec("$sshBase 'curl -L -o $zipRemotePath $downloadUrl && unzip -o $zipRemotePath -d $basePath && rm $zipRemotePath'");

} elseif ($type === 'bukkit') {
    $bukkitUrl = "https://download.getbukkit.org/craftbukkit/craftbukkit-$versionOrModpack.jar";
    exec("$sshBase 'curl -o $basePath/server.jar $bukkitUrl'");
} else {
    echo "❌ Tipo server non supportato: $type\n";
    exit(1);
}

exec("$sshBase 'echo \"eula=true\" > $basePath/eula.txt'");

$properties = <<<EOF
enable-jmx-monitoring=false
rcon.port=25575
level-seed=
gamemode=survival
enable-command-block=false
enable-query=false
generator-settings=
level-name=world
motd=Benvenuto nel server $serverId!
query.port=25565
pvp=true
generate-structures=true
difficulty=easy
network-compression-threshold=256
max-tick-time=60000
use-native-transport=true
max-players=20
online-mode=true
enable-status=true
allow-flight=false
broadcast-rcon-to-ops=true
view-distance=10
max-build-height=256
server-ip=
allow-nether=true
server-port=25565
enable-rcon=false
sync-chunk-writes=true
op-permission-level=4
prevent-proxy-connections=false
resource-pack=
entity-broadcast-range-percentage=100
rcon.password=
player-idle-timeout=0
debug=false
force-gamemode=false
rate-limit=0
hardcore=false
white-list=false
broadcast-console-to-ops=true
spawn-npcs=true
spawn-animals=true
snooper-enabled=true
function-permission-level=2
text-filtering-config=
spawn-monsters=true
enforce-whitelist=false
resource-pack-sha1=
spawn-protection=16
max-world-size=29999984
EOF;

exec("$sshBase 'echo ".escapeshellarg($properties)." > $basePath/server.properties'");

$startScript = <<<SH
#!/bin/bash
cd "$basePath"
screen -dmS $serverId java -Xmx1024M -Xms1024M -jar server.jar nogui
SH;
exec("$sshBase 'echo ".escapeshellarg($startScript)." > $basePath/start.sh && chmod +x $basePath/start.sh'");

$stopScript = <<<SH
#!/bin/bash
screen -S $serverId -X quit
SH;
exec("$sshBase 'echo ".escapeshellarg($stopScript)." > $basePath/stop.sh && chmod +x $basePath/stop.sh'");

// Redirezione SOLO SE eseguito da browser (quindi non qui)

?>


<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>Crea Server Minecraft</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/add_server.css" />
</head>
<body>

<div class="main-container">
  <div class="card-create-server shadow-lg">
    <h1>Crea il tuo Server Minecraft</h1>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="mb-4">
        <label for="server_name" class="form-label">Nome Server</label>
        <input type="text" name="server_name" id="server_name" class="form-control" required
          value="<?= htmlspecialchars($postServerName) ?>" placeholder="Es. AvventuraMagica" />
      </div>

      <div class="mb-4">
        <label for="type" class="form-label">Tipo di Server</label>
        <select name="type" id="type" class="form-select" required>
          <option value="vanilla" <?= $postType === 'vanilla' ? 'selected' : '' ?>>Vanilla</option>
          <option value="bukkit" <?= $postType === 'bukkit' ? 'selected' : '' ?>>Bukkit</option>
          <option value="modpack" <?= $postType === 'modpack' ? 'selected' : '' ?>>Modpack</option>
        </select>
      </div>

      <div class="mb-4" id="version-group">
        <label for="version" class="form-label">Versione Minecraft</label>
        <select name="version" id="version" class="form-select">
          <?php
          $versions = [
            "1.21.8", "1.21.7", "1.21.6", "1.21.5", "1.21.4", "1.21.3", "1.21.2", "1.21.1", "1.21",
            "1.20.6", "1.20.5", "1.20.4", "1.20.3", "1.20.2", "1.20.1", "1.20",
            "1.19.4", "1.19.3", "1.19.2", "1.19.1", "1.19",
            "1.18.2", "1.18.1", "1.18",
            "1.17.1", "1.17",
            "1.16.5", "1.16.4", "1.16.3", "1.16.2", "1.16.1", "1.16",
            "1.15.2", "1.15.1", "1.15",
            "1.14.4", "1.14.3", "1.14.2", "1.14.1", "1.14",
            "1.13.2", "1.13.1", "1.13",
            "1.12.2", "1.12.1", "1.12",
            "1.11.2", "1.11.1", "1.11",
            "1.10.2", "1.10.1", "1.10",
            "1.9.4", "1.9.3", "1.9.2", "1.9.1", "1.9",
            "1.8.9", "1.8.8", "1.8.7", "1.8.6", "1.8.5", "1.8.4", "1.8.3", "1.8.2", "1.8.1", "1.8",
            "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4", "1.7.2"
          ];
          foreach ($versions as $v) {
            $selected = ($postVersion === $v) ? 'selected' : '';
            echo "<option value=\"$v\" $selected>$v</option>";
          }
          ?>
        </select>
      </div>

      <div class="mb-4" id="modpack-group">
        <label for="modpack_id" class="form-label">Scegli Modpack</label>
        <select name="modpack_id" id="modpack_id" class="form-select">
          <option value="">-- Seleziona un Modpack --</option>
          <?php
          $stmt = $pdo->query("SELECT id, name, minecraftVersion FROM modpacks ORDER BY name");
          while ($modpack = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $selected = ($postModpackId == $modpack['id']) ? 'selected' : '';
            $label = htmlspecialchars($modpack['name'] . " (" . $modpack['minecraftVersion'] . ")");
            echo "<option value=\"{$modpack['id']}\" $selected>$label</option>";
          }
          ?>
        </select>
      </div>

      <div class="d-flex justify-content-center gap-3 mt-4">
        <button type="submit" class="btn btn-primary shadow">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary shadow">Annulla</a>
      </div>
    </form>
  </div>

  <div class="side-panel">
    <h3>Hai già un server?</h3>
    <a href="dashboard.php" class="btn btn-light btn-lg shadow d-flex align-items-center gap-2">
      <i class="bi bi-house-door"></i> Vai alla Dashboard
    </a>
    <a href="logout.php" class="btn btn-danger btn-lg shadow d-flex align-items-center gap-2">
      <i class="bi bi-box-arrow-right"></i> Esci
    </a>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const typeSelect = document.getElementById("type");
  const versionGroup = document.getElementById("version-group");
  const modpackGroup = document.getElementById("modpack-group");
  const versionInput = document.getElementById("version");
  const modpackInput = document.getElementById("modpack_id");

  function toggleFields() {
    const selectedType = typeSelect.value;
    if (selectedType === "modpack") {
      versionGroup.style.display = "none";
      versionInput.disabled = true;

      modpackGroup.style.display = "block";
      modpackInput.disabled = false;
    } else {
      versionGroup.style.display = "block";
      versionInput.disabled = false;

      modpackGroup.style.display = "none";
      modpackInput.disabled = true;
    }
  }

  typeSelect.addEventListener("change", toggleFields);
  toggleFields(); // Esegui al primo caricamento
});
</script>

</body>
</html>