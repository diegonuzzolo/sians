<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
require 'includes/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postServerName = trim($_POST['server_name'] ?? '');
    $postType = $_POST['type'] ?? 'vanilla';
    $postVersion = $_POST['version'] ?? '';
    $postModpackId = $_POST['modpack_id'] ?? '';

    if (empty($postServerName) || empty($postType)) {
        $error = "❌ Compila tutti i campi obbligatori.";
    } else {
        // Trova VM libera
$stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    $error = "❌ Nessuna VM disponibile.";
} else {
    $vmIp = $vm['ip'];
    $vmId = $vm['proxmox_vmid']; // <-- QUI usa VMID Proxmox per cartelle
    $userId = $_SESSION['user_id'];

    // Inserisci server nel DB
    $stmt = $pdo->prepare("INSERT INTO servers 
        (name, type, version, vm_id, user_id, modpack_id, proxmox_vmid, subdomain, tunnel_url, status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $stmt->execute([
        $postServerName,
        $postType,
        $postVersion ?: null,
        $vm['id'],
        $userId,
        $postType === 'modpack' ? $postModpackId : null,
        $vmId,
        null,
        null,
        'stopped'
    ]);

    $serverId = $pdo->lastInsertId();

    // Comando per installare server remoto (passa VMID Proxmox come ID cartella)
    $versionOrSlug = ($postType === 'modpack') ? $postModpackId : $postVersion;
    $downloadUrl = ''; // Puoi impostare l'URL del modpack qui se serve
    $installMethod = ''; // es. 'forge' o 'curseforge' se modpack

    $sshCmd = "ssh -i /var/www/html/.ssh/id_rsa -o StrictHostKeyChecking=no diego@$vmIp";

    $installCommand = "$sshCmd 'bash /home/diego/setup_server.sh " . 
        escapeshellarg($postType) . " " .
        escapeshellarg($versionOrSlug) . " " .
        escapeshellarg($downloadUrl) . " " .
        escapeshellarg($installMethod) . " " .
        escapeshellarg($vmId) . "' > /dev/null 2>&1 &";

    exec($installCommand);

    // Assegna VM
    $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?")
        ->execute([$userId, $serverId, $vm['id']]);

    // Redirect con query string
    $queryString = "server_id=$serverId";
    if ($postType === 'modpack') {
        $queryString .= "&modpack_id=" . urlencode($postModpackId);
    } else {
        $queryString .= "&version=" . urlencode($postVersion);
    }

    header("Location: create_tunnel_and_dns.php?$queryString");
    exit;
}
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>Crea Server Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/add_server.css" />
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('type');
    const versionGroup = document.getElementById('version-group');
    const modpackGroup = document.getElementById('modpack-group');
    const versionInput = document.getElementById('version');
    const modpackInput = document.getElementById('modpack_id');

    function toggleFields() {
      console.log("Tipo selezionato:", typeSelect.value);
      if (typeSelect.value === 'modpack') {
        versionGroup.style.display = 'none';
        versionInput.disabled = true;
        modpackGroup.style.display = 'block';
        modpackInput.disabled = false;
      } else {
        versionGroup.style.display = 'block';
        versionInput.disabled = false;
        modpackGroup.style.display = 'none';
        modpackInput.disabled = true;
      }
    }

    typeSelect.addEventListener('change', toggleFields);
    toggleFields();
  });
  </script>
</head>
<body>
  <div class="main-container">
    <div class="card-create-server shadow-lg">
      <h1>Crea il tuo Server Minecraft</h1>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="mb-4">
          <label for="server_name" class="form-label">Nome Server</label>
          <input
            type="text"
            name="server_name"
            id="server_name"
            class="form-control"
            required
            value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>"
            placeholder="Es. AvventuraMagica"
          />
        </div>

        <div class="mb-4">
          <label for="type" class="form-label">Tipo di Server</label>
          <select name="type" id="type" class="form-select" required>
            <option value="vanilla" <?= (($_POST['type'] ?? '') === 'vanilla') ? 'selected' : '' ?>>Vanilla</option>
            <option value="bukkit" <?= (($_POST['type'] ?? '') === 'bukkit') ? 'selected' : '' ?>>Bukkit</option>
            <option value="modpack" <?= (($_POST['type'] ?? '') === 'modpack') ? 'selected' : '' ?>>Modpack</option>
          </select>
        </div>

        <div class="mb-5" id="version-group">
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
                "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4", "1.7.2",
                "1.6.4", "1.6.2", "1.6.1",
                "1.5.2", "1.5.1", "1.5",
                "1.4.7", "1.4.6", "1.4.5", "1.4.4", "1.4.3", "1.4.2",
                "1.3.2", "1.3.1",
                "1.2.5", "1.2.4", "1.2.3", "1.2.2", "1.2.1",
                "1.1", "1.0"
            ];
            foreach ($versions as $v) {
                $selected = ($postVersion === $v) ? 'selected' : '';
                echo "<option value=\"$v\" $selected>$v</option>";
            }
            ?>
          </select>
        </div>

        <div class="mb-4" id="modpack-group" style="display:none;">
          <label for="modpack_id" class="form-label">Scegli Modpack</label>
          <select name="modpack_id" id="modpack_id" class="form-select">
            <option value="">-- Seleziona un Modpack --</option>
            <?php
            $stmt = $pdo->query("SELECT id, name, minecraftVersion FROM modpacks ORDER BY name");
            while ($modpack = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $selected = (($modpack['id'] ?? '') == ($_POST['modpack_id'] ?? '')) ? 'selected' : '';
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
</body>
</html>
