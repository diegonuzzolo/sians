<?php
require 'config/config.php';

$serverId = intval($_GET['server_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    http_response_code(404);
    exit('Not found');
}

ob_start();
?>

<?php if ($server['status'] === 'installing' || $server['status'] === 'downloading_mods'): ?>
  <div class="progress">
    <div id="progress-bar-<?= $server['id'] ?>" class="progress-bar bg-warning progress-bar-striped" role="progressbar"
        style="width: <?= $server['progress'] ?>%;" 
        aria-valuenow="<?= $server['progress'] ?>" aria-valuemin="0" aria-valuemax="100"
        data-server-id="<?= $server['id'] ?>">
        <?= $server['progress'] ?>%
    </div>
  </div>
<?php endif; ?>

<div class="server-status" id="status-<?= $server['id'] ?>">
    <?= htmlspecialchars($server['status']) ?>
</div>

<?php if ($server['status'] !== 'installing' && $server['status'] !== 'downloading_mods'): ?>
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
