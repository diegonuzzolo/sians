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

$stmt = $pdo->query("SELECT id, name, minecraftVersion FROM modpacks ORDER BY name");
$modpacks = $stmt->fetchAll();
?>

<?php include 'includes/header.php'; ?>

 <div class="main-container">
    <div class="card-create-server">
      <h1>Crea un nuovo server</h1>
      <form action="install_server.php" method="POST">
        <div class="mb-3">
          <label for="server_name" class="form-label">Nome Server</label>
          <input type="text" class="form-control" name="server_name" id="server_name" required>
        </div>

        <div class="mb-3">
          <label for="type" class="form-label">Tipo Server</label>
          <select name="type" id="type" class="form-select" required onchange="toggleFields()">
            <option value="vanilla">Vanilla</option>
            <option value="bukkit">Bukkit/Spigot</option>
            <option value="modpack">Modpack (CurseForge/Modrinth)</option>
          </select>
        </div>

        <div class="mb-3" id="version-group">
          <label for="version" class="form-label">Versione Minecraft</label>
          <input type="text" class="form-control" name="version" id="version" placeholder="es: 1.20.1">
        </div>

        <div class="mb-3" id="modpack-group" style="display: none;">
          <label for="modpack_id" class="form-label">Modpack</label>
          <select name="modpack_id" id="modpack_id" class="form-select">
            <option value="">-- Seleziona un Modpack --</option>
            <?php foreach ($modpacks as $modpack): ?>
              <option value="<?= htmlspecialchars($modpack['id']) ?>">
                <?= htmlspecialchars($modpack['name']) ?>
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
      <h3>Tipi di server</h3>
      <p><strong>Vanilla:</strong> versione base di Minecraft</p>
      <p><strong>Bukkit:</strong> supporta plugin</p>
      <p><strong>Modpack:</strong> installa pacchetti da CurseForge/Modrinth</p>
    </div>
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
  </script>

<?php include 'includes/footer.php'; ?>
