<?php

// Esegui la logica CLI solo se chiamato da CLI
if (php_sapi_name() === 'cli') {
    // Parametri da CLI
    if ($argc < 5) {
        echo "❌ Parametri insufficienti.\n";
        exit(1);
    }

    $vmIp = $argv[1];
    $serverId = $argv[2];
    $type = $argv[3];
    $versionOrModpack = $argv[4];

    // Percorso base sulla VM
    $basePath = "/home/diego/$serverId";

    // Comando SSH base (user e IP)
    $sshUser = 'diego'; // fisso
    $sshBase = "ssh -o StrictHostKeyChecking=no $sshUser@$vmIp";

    // 1) Crea cartella server
    exec("$sshBase 'mkdir -p $basePath && chmod 755 $basePath'");

    // 2) Scarica jar in base al tipo server
    if ($type === 'vanilla') {
        // Link server.jar versione vanilla (esempio base)
        $jarUrl = "https://launcher.mojang.com/v1/objects/e2010d9bd008e4e017d9de744cf54f4e5cbb6c3e/server.jar";
        exec("$sshBase 'curl -o $basePath/server.jar $jarUrl'");
    } elseif ($type === 'modpack') {
        // Per modpack, scarica da URL specifico da file locale JSON
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
            echo "❌ Modpack con ID $versionOrModpack non trovato o senza URL di download.\n";
            exit(1);
        }
        $zipRemotePath = "$basePath/modpack.zip";
        // Scarica zip, estrai e cancella zip
        exec("$sshBase 'curl -o $zipRemotePath $downloadUrl && unzip -o $zipRemotePath -d $basePath && rm $zipRemotePath'");
    } elseif ($type === 'bukkit') {
        $bukkitUrl = "https://download.getbukkit.org/craftbukkit/craftbukkit-$versionOrModpack.jar";
        exec("$sshBase 'curl -o $basePath/server.jar $bukkitUrl'");
    } else {
        echo "❌ Tipo server non supportato.\n";
        exit(1);
    }

    // 3) Crea eula.txt con eula=true
    $eulaContent = "eula=true\n";
    $eulaEscaped = escapeshellarg($eulaContent);
    exec("$sshBase 'echo $eulaEscaped > $basePath/eula.txt'");

    // 4) Crea server.properties completo (ti allego esempio completo)
    $serverProperties = <<<EOL
#Minecraft server properties
enable-jmx-monitoring=false
rcon.port=25575
level-seed=
gamemode=survival
enable-command-block=false
enable-query=false
generator-settings=
enforce-secure-profile=true
level-name=world
motd=Benvenuto nel server $versionOrModpack!
query.port=25565
pvp=true
generate-structures=true
difficulty=easy
network-compression-threshold=256
max-tick-time=60000
use-native-transport=true
max-players=50
online-mode=true
enable-status=true
allow-flight=false
broadcast-rcon-to-ops=true
view-distance=10
max-build-height=256
server-ip=0.0.0.0
allow-nether=true
server-port=25565
enable-rcon=false
sync-chunk-writes=true
op-permission-level=4
prevent-proxy-connections=false
hide-online-players=false
resource-pack-prompt=
resource-pack=
entity-broadcast-range-percentage=100
simulation-distance=10
rcon.password=
player-idle-timeout=0
force-gamemode=false
rate-limit=0
hardcore=false
white-list=false
broadcast-console-to-ops=true
spawn-npcs=true
spawn-animals=true
function-permission-level=2
text-filtering-config=
spawn-monsters=true
enforce-whitelist=false
resource-pack-sha1=
spawn-protection=16
max-world-size=29999984
EOL;

    $serverPropertiesEscaped = escapeshellarg($serverProperties);
    exec("$sshBase 'echo $serverPropertiesEscaped > $basePath/server.properties'");

    // 5) Crea start.sh
    $startScript = <<<BASH
#!/bin/bash
cd "$basePath"
java -Xmx10G -Xms10G -jar server.jar nogui
BASH;

    $startScriptEscaped = escapeshellarg($startScript);
    exec("$sshBase 'echo $startScriptEscaped > $basePath/start.sh && chmod +x $basePath/start.sh'");

    // 6) Crea stop.sh (esempio con screen)
    $stopScript = <<<BASH
#!/bin/bash
screen -S mc_$serverId -X quit
BASH;

    $stopScriptEscaped = escapeshellarg($stopScript);
    exec("$sshBase 'echo $stopScriptEscaped > $basePath/stop.sh && chmod +x $basePath/stop.sh'");

    // 7) Fine
    echo "✅ Installazione completata con successo per il server $serverId ($type)\n";
    exit(0);
}

// Da qui in poi, codice per la parte web (HTML, ecc.)
?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>Crea Server Minecraft</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet" />
  <style>
    /* layout base */
    body, html {
      height: 100%;
      margin: 0;
      display: flex;
      font-family: Arial, sans-serif;
      background: #f8f9fa;
    }
    .main-container {
      flex: 1;
      display: flex;
      padding: 20px;
      gap: 20px;
    }
    .card-create-server {
      flex: 3;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .side-panel {
      flex: 1;
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      display: flex;
      flex-direction: column;
      justify-content: start;
      gap: 15px;
    }
    .side-panel h3 {
      margin-bottom: 1rem;
    }
  </style>
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

      <div class="mb-4" id="version-group" style="display: <?= ($postType === 'vanilla' || $postType === 'bukkit') ? 'block' : 'none' ?>;">
        <label for="version" class="form-label">Versione Minecraft</label>
        <select name="version" id="version" class="form-select" required>
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

      <div class="mb-4" id="modpack-group" style="display: <?= $postType === 'modpack' ? 'block' : 'none' ?>;">
        <label for="modpack_id" class="form-label">Scegli Modpack</label>
        <select name="modpack_id" id="modpack_id" class="form-select" <?= $postType === 'modpack' ? '' : 'disabled' ?>>
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
      modpackGroup.style.display = "block";
      modpackInput.disabled = false;

      versionGroup.style.display = "none";
      versionInput.disabled = true;
    } else {
      versionGroup.style.display = "block";
      versionInput.disabled = false;

      modpackGroup.style.display = "none";
      modpackInput.disabled = true;
    }
  }

  typeSelect.addEventListener("change", toggleFields);
  toggleFields();
});
</script>

</body>
</html>