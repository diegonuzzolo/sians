<?php

session_start();
require 'config/config.php';
include 'auth_check.php';

$userId = $_SESSION['user_id'] ?? null;
$serverId = intval($_GET['server_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ? AND user_id = ?");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(403); // oppure 404 per non rivelare l'esistenza
    exit('Accesso negato o server non trovato');
}


ob_start();
?>

<?php if (in_array($server['status'], ['installing', 'downloading_mods', 'diagnosing'])): ?>
  <div class="progress">
    <div id="progress-bar-<?= $server['id'] ?>" class="progress-bar bg-warning progress-bar-striped progress-bar-animated"
         role="progressbar"
         style="width: <?= (int)$server['progress'] ?>%;"
         aria-valuenow="<?= (int)$server['progress'] ?>" aria-valuemin="0" aria-valuemax="100"
         data-server-id="<?= $server['id'] ?>">
        <?= (int)$server['progress'] ?>%
    </div>
  </div>
<?php endif; ?>

<div class="server-status" id="status-<?= $server['id'] ?>">
    <?= htmlspecialchars($server['status']) ?>
</div>

<?php if (!in_array($server['status'], ['installing', 'downloading_mods', 'diagnosing'])): ?>
  <div class="d-flex justify-content-start gap-2 mt-3">
    <form method="post" action="server_action.php">
        <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
        <input type="hidden" name="proxmox_vmid" value="<?= htmlspecialchars($server['proxmox_vmid']) ?>">
        <button name="action" 
                value="<?= $server['status'] === 'running' ? 'stop' : 'start' ?>"
                class="btn <?= $server['status'] === 'running' ? 'btn-warning' : 'btn-success' ?> action-btn">
            <?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?>
        </button>
    </form>

    <form method="POST" action="delete_server.php" onsubmit="return confirm('Eliminare il server?')">
      <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
      <input type="hidden" name="proxmox_vmid" value="<?= htmlspecialchars($server['proxmox_vmid']) ?>">
      <button type="submit" class="btn btn-danger action-btn">Elimina</button>
    </form>
  </div>
<?php endif; ?>

<?php
echo ob_get_clean();
