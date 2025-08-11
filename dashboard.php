<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'Utente';

// Prendiamo i server dell’utente con IP VM e risorse assegnate
$sql = "SELECT s.id, s.name, s.status, s.type, s.version, s.subdomain, s.tunnel_url, s.progress, 
               s.cpu_cores, s.ram_mb, 
               v.ip 
        FROM servers s
        LEFT JOIN containers v ON s.container_id = v.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include("includes/header.php"); ?>
<link rel="stylesheet" href="assets/css/style.css">
<main>

  <div class="container my-4">
    <h1 class="mb-4 text-center text-bedrock">🎮 Minecraft Bedrock - La Mia Dashboard</h1>
    
    <?php if (empty($servers)): ?>
      <div class="alert alert-info text-center fs-5">
        Non hai ancora server Bedrock. <a href="add_server.php" class="alert-link">Crea un nuovo server</a>.
      </div>
    <?php else: ?>
      <table class="table table-dark table-hover align-middle text-center shadow-lg rounded">
        <thead class="table-bedrock">
          <tr>
            <th>🌐 Nome Server</th>
            <th>IP / Dominio</th>
            <th>📦 Versione</th>
            <th>🖥 CPU Cores</th>
            <th>💾 RAM (GB)</th>
            <th>📊 Stato</th>
            <th>⏳ Progresso</th>
            <th>⚙️ Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($servers as $srv): ?>
            <tr onclick="window.location='server.php?id=<?= $srv['id'] ?>'">
              <td><?= htmlspecialchars($srv['name']) ?></td>
              <td>
                <?php 
                  if (!empty($srv['tunnel_url'])) echo htmlspecialchars($srv['tunnel_url']);
                  else if (!empty($srv['subdomain'])) echo htmlspecialchars($srv['subdomain']);
                  else echo htmlspecialchars($srv['ip'] ?? 'Sconosciuto');
                ?>
              </td>
              <td><?= htmlspecialchars($srv['version'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($srv['cpu_cores'] ?? '-') ?></td>
              <td><?= htmlspecialchars(round(($srv['ram_mb'] ?? 0) / 1024, 2)) ?></td>
              <td>
                <?php 
                  $status = $srv['status'] ?? 'stopped';
                  $statusClass = match($status) {
                    'running' => 'status-running-bedrock',
                    'stopped' => 'status-stopped-bedrock',
                    'installing', 'downloading_mods' => 'status-installing-bedrock',
                    default => 'status-stopped-bedrock',
                  };
                  $statusIcon = match($status) {
                    'running' => '<i class="fa-solid fa-play"></i>',
                    'stopped' => '<i class="fa-solid fa-stop"></i>',
                    'installing', 'downloading_mods' => '<i class="fa-solid fa-spinner fa-spin"></i>',
                    default => '<i class="fa-solid fa-stop"></i>',
                  };
                ?>
                <span class="status-badge <?= $statusClass ?>"><?= $statusIcon . " " . ucfirst($status) ?></span>
              </td>
              <td style="min-width: 140px;">
                <?php if (in_array($status, ['installing', 'downloading_mods'])): ?>
                  <div class="progress">
                    <div class="progress-bar progress-bar-bedrock" role="progressbar" style="width: <?= intval($srv['progress']) ?>%" aria-valuenow="<?= intval($srv['progress']) ?>" aria-valuemin="0" aria-valuemax="100">
                      <?= intval($srv['progress']) ?>%
                    </div>
                  </div>
                <?php else: ?>
                  <span>-</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === 'running'): ?>
                  <button class="btn btn-sm btn-bedrock-stop btn-action" onclick="event.stopPropagation(); serverAction(<?= $srv['id'] ?>, 'stop', this)">Ferma</button>
                <?php elseif (in_array($status, ['stopped', 'ready'])): ?>
                  <button class="btn btn-sm btn-bedrock-start btn-action" onclick="event.stopPropagation(); serverAction(<?= $srv['id'] ?>, 'start', this)">Avvia</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-secondary btn-action" disabled>Attendi</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>

<script>
  async function serverAction(serverId, action, btn) {
    btn.disabled = true;
    btn.textContent = action === 'start' ? 'Avviando...' : 'Fermando...';

    try {
      const resp = await fetch('server_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: serverId, action})
      });
      const data = await resp.json();

      if (data.success) {
        alert('Azione completata con successo!');
        location.reload();
      } else {
        alert('Errore: ' + (data.error || 'Impossibile eseguire l\'azione'));
        btn.disabled = false;
        btn.textContent = action === 'start' ? 'Avvia' : 'Ferma';
      }
    } catch (err) {
      alert('Errore di comunicazione col server');
      btn.disabled = false;
      btn.textContent = action === 'start' ? 'Avvia' : 'Ferma';
    }
  }
</script>

<?php include ("includes/footer.php"); ?>

<style>
  /* Colori e stile Bedrock */
  .text-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px black; }
  .table-bedrock { background-color: #003f54; color: white; }
  .status-running-bedrock { color: #00ff00; font-weight: bold; }
  .status-stopped-bedrock { color: #ff5555; font-weight: bold; }
  .status-installing-bedrock { color: #ffaa00; font-weight: bold; }
  .btn-bedrock-start { background-color: #00ff00; color: black; font-weight: bold; }
  .btn-bedrock-stop { background-color: #ff5555; color: white; font-weight: bold; }
  .progress-bar-bedrock { background-color: #00c8ff; font-weight: bold; }
  .status-badge { padding: 4px 8px; border-radius: 6px; display: inline-block; background: rgba(0,0,0,0.4); }
  .table tbody tr:hover { background-color: rgba(0,200,255,0.1); cursor: pointer; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
