<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

$error = '';
$postServerName = $_POST['server_name'] ?? '';
$postType = $_POST['type'] ?? 'vanilla';
$postVersion = $_POST['version'] ?? '';
$postModpackId = $_POST['modpack_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($postServerName)) {
        $error = "Il nome del server è obbligatorio.";
    } else {
        $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $vm = $stmt->fetch();

        if (!$vm) {
            $error = "❌ Nessuna VM disponibile al momento.";
        } else {
            $type = $postType;
            $versionOrSlug = $postVersion;
            $downloadUrl = '';
            $installMethod = '';
            $modpackName = '';

            if ($type === 'modpack') {
                $stmt = $pdo->prepare("SELECT * FROM modpacks WHERE id = ?");
                $stmt->execute([$postModpackId]);
                $modpack = $stmt->fetch();

                if (!$modpack) {
                    $error = "❌ Modpack con ID $postModpackId non trovato.";
                } else {
                    $modpackName = $modpack['name'];
                    $downloadUrl = "https://api.modrinth.com/v2/project/" . $modpack['slug'] . "/version/" . $modpack['version_id'];
                    $installMethod = 'modrinth-fabric';
                    $versionOrSlug = $modpack['minecraftVersion'];
                }
            }

            if (!$error) {
                $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, type, status) VALUES (?, ?, ?, 'installing')");
                $stmt->execute([$_SESSION['user_id'], $postServerName, $type]);
                $serverId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $serverId, $vm['id']]);

                $escapedServerName = escapeshellarg($postServerName);
                $escapedType = escapeshellarg($type);
                $escapedVersionOrSlug = escapeshellarg($versionOrSlug);
                $escapedDownloadUrl = escapeshellarg($downloadUrl);
                $escapedInstallMethod = escapeshellarg($installMethod);
                $escapedServerId = escapeshellarg($serverId);

                $command = "/usr/bin/php install_server.php $escapedServerId $escapedType $escapedVersionOrSlug $escapedDownloadUrl $escapedInstallMethod > /dev/null 2>&1 &";
                exec($command);

                header("Location: create_tunnel_and_dns.php?server_id=$serverId");
                exit;
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Crea un nuovo server Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/add_server.css" rel="stylesheet">
</head>
<body>
  <div class="main-container">
    <div class="card-create-server">
      <h1>Crea un nuovo server</h1>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form action="install_server.php" method="POST">
        <div class="mb-3">
          <label for="server_name" class="form-label">Nome Server</label>
          <input type="text" class="form-control" name="server_name" id="server_name" required>
        </div>

        <div class="mb-3">
          <label for="type" class="form-label">Tipo Server</label>
          <select name="type" id="type" class="form-select" required onchange="toggleFields()">
            <option value="vanilla">Vanilla</option>
            <option value="modpack">Modpack (Fabric - Modrinth)</option>
          </select>
        </div>

        <div class="mb-3" id="version-field">
  <label for="version" class="form-label">Versione Minecraft</label>
  <select class="form-select" id="version" name="version" required>
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
      "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4"
    ];
    foreach ($versions as $v) {
      $selected = (isset($_POST['version']) && $_POST['version'] === $v) ? 'selected' : '';
      echo "<option value=\"$v\" $selected>$v</option>";
    }
    ?>
  </select>
</div>


        <div class="mb-3" id="modpack-group" style="display: none;">
          <label for="modpack_id" class="form-label">Modpack</label>
          <select name="modpack_id" id="modpack_id" class="form-select">
            <option value="">-- Seleziona un Modpack --</option>
            <?php foreach ($modpacks as $modpack): ?>
              <option value="<?= htmlspecialchars($modpack['id']) ?>">
                <?= htmlspecialchars($modpack['name']) ?> (<?= htmlspecialchars($modpack['minecraftVersion']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">Crea Server</button>
        </div>
      </form>
    </div>
<div class="side-panel">
  <a href="dashboard.php" class="btn btn-secondary shadow d-flex align-items-center justify-content-center gap-3 mb-4"
     style="
       width: 100%;
       font-weight: 800;
       font-size: 1.15rem;
       padding: 12px 0;
       border-radius: 40px;
       background: linear-gradient(90deg, #4ade80, #22c55e);
       color: #0f3d00;
       box-shadow: 0 6px 12px rgba(34,197,94,0.5);
       transition: all 0.3s ease;
     "
     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 10px 20px rgba(34,197,94,0.7)';"
     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 6px 12px rgba(34,197,94,0.5)';"
  >
    <i class="bi bi-house-door" style="font-size: 1.5rem;"></i> Torna alla Dashboard
  </a>

  <a href="logout.php" class="btn btn-danger shadow d-flex align-items-center justify-content-center gap-3"
     style="
       width: 100%;
       font-weight: 800;
       font-size: 1.15rem;
       padding: 12px 0;
       border-radius: 40px;
       background: linear-gradient(90deg, #f87171, #ef4444);
       color: #640000;
       box-shadow: 0 6px 12px rgba(239,68,68,0.5);
       transition: all 0.3s ease;
     "
     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 10px 20px rgba(239,68,68,0.7)';"
     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 6px 12px rgba(239,68,68,0.5)';"
  >
    <i class="bi bi-box-arrow-right" style="font-size: 1.5rem;"></i> Esci dall’Account
  </a>
</div>


  <script>
    function toggleFields() {
      const type = document.getElementById('type').value;
      const versionGroup = document.getElementById('version-group');
      const modpackGroup = document.getElementById('modpack-group');

      if (type === 'modpack') {
        versionGroup.style.display = 'none';
        modpackGroup.style.display = 'block';
      } else {
        versionGroup.style.display = 'block';
        modpackGroup.style.display = 'none';
      }
    }

    document.addEventListener('DOMContentLoaded', toggleFields);
  </script>
</body>
</html>
