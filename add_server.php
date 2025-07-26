<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $postType = $_POST['type'] ?? '';
    $type = strtolower(trim($postType));
    $postVersion = $_POST['version'] ?? '';
    $version = trim($postVersion);
    $postModpackId = $_POST['modpack_id'] ?? null;

    // Solo se il tipo è modpack convertiamo in int
    $modpackId = ($type === 'modpack' && !empty($postModpackId)) ? intval($postModpackId) : null;

    $userId = $_SESSION['user_id'];

    // Validazioni base
    if (empty($name)) {
        $error = "Il nome del server è obbligatorio.";
    } elseif (!in_array($type, ['vanilla', 'modpack', 'bukkit'])) {
        $error = "Tipo server non valido.";
    } elseif ($type === 'vanilla' && empty($version)) {
        $error = "Per i server Vanilla devi selezionare una versione.";
    } elseif ($type === 'modpack') {
        // Validazione modpack
        if (!$modpackId) {
            $error = "Seleziona un modpack valido.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM modpacks WHERE id = ?");
            $stmt->execute([$modpackId]);
            if ($stmt->fetchColumn() == 0) {
                $error = "❌ Modpack con ID $modpackId non trovato.";
            }
        }
    }

    if (!isset($error)) {
        // Trova VM libera
        $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
        $vm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$vm) {
            $error = "❌ Nessuna VM disponibile al momento.";
        } else {
            // Inserisci server nel DB
            $stmt = $pdo->prepare("INSERT INTO servers (name, type, version, modpack_id, user_id, vm_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $version ?: null, $modpackId, $userId, $vm['id']]);
            $serverId = $pdo->lastInsertId();

            // Assegna VM
            $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
            $stmt->execute([$userId, $serverId, $vm['id']]);

            // Avvia installazione
            $escapedServerId = escapeshellarg($serverId);
            $command = "php install_server.php $escapedServerId > /tmp/install_server_$serverId.log 2>&1 &";
            exec($command);

            // Redirect
            header("Location: create_tunnel_and_dns.php?server_id=$serverId");
            exit;
        }
    }
}
?>


<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Crea Server Minecraft</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/add_server.css">
</head>
<body>
<div class="main-container">
  <div class="card-create-server shadow-lg">
    <h1>Crea il tuo Server Minecraft</h1>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
  <div class="mb-3">
    <label for="name">Nome Server</label>
    <input type="text" name="name" class="form-control" required>
  </div>

  <div class="mb-3">
    <label for="type">Tipo</label>
    <select name="type" id="type" class="form-select" required onchange="toggleFields()">
      <option value="vanilla">Vanilla</option>
      <option value="modpack">Modpack</option>
      <option value="bukkit">Bukkit</option>
    </select>
  </div>

  <div id="versionField" class="mb-3">
    <label for="version">Versione Minecraft</label>
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
              "1.8.9", "1.8.8", "1.8.7", "1.8.6", "1.8.5", "1.8.4", "1.8.3", "1.8.2", "1  .8.1", "1.8",
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

  <div id="modpackField" class="mb-3" style="display: none;">
    <label for="modpack_id">Seleziona Modpack</label>
    <select name="modpack_id" class="form-select">
      <?php
      $stmt = $pdo->query("SELECT id, name FROM modpacks ORDER BY name");
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
          echo "<option value=\"{$row['id']}\">" . htmlspecialchars($row['name']) . "</option>";
      }
      ?>
    </select>
  </div>

  <button type="submit" class="btn btn-primary">Crea Server</button>
</form>
  <div class="side-panel">
    <h3>Hai già un server?</h3>
    <a href="dashboard.php" class="btn btn-light btn-lg shadow"><i class="bi bi-house-door"></i> Vai alla Dashboard</a>
    <a href="logout.php" class="btn btn-danger btn-lg shadow"><i class="bi bi-box-arrow-right"></i> Esci</a>
  </div>
</div>
<script>
function toggleFields() {
  const type = document.getElementById('type').value;
  document.getElementById('versionField').style.display = (type === 'vanilla' || type === 'bukkit') ? 'block' : 'none';
  document.getElementById('modpackField').style.display = (type === 'modpack') ? 'block' : 'none';
}
window.addEventListener('DOMContentLoaded', toggleFields);
</script>

  </div>

</body>
</html>
