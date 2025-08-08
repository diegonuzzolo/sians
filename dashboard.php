<?php
include("auth_check.php"); // Controlla login e proprietà server se serve
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Prendiamo i server dell’utente con IP VM
$sql = "SELECT s.id, s.name, s.status, s.type, s.version, s.subdomain, s.tunnel_url, s.progress, 
               v.ip 
        FROM servers s
        LEFT JOIN minecraft_vms v ON s.vm_id = v.id
        WHERE s.user_id = ?
        ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard Minecraft Bedrock</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
  <style>
    body {
      background: linear-gradient(135deg, #1e293b, #0f172a);
      color: #f1f5f9;
      min-height: 100vh;
      padding-top: 3rem;
    }
    .container {
      max-width: 1100px;
    }
    h1 {
      color: #facc15;
      font-weight: 900;
      text-shadow: 2px 2px 8px rgba(250, 204, 21, 0.7);
    }
    .table thead {
      background: #334155;
    }
    .table thead th {
      color: #facc15;
      border: none;
    }
    .table tbody tr {
      cursor: pointer;
      transition: background-color 0.25s ease;
    }
    .table tbody tr:hover {
      background-color: #475569;
    }
    .status-badge {
      font-weight: 600;
      padding: 0.25em 0.75em;
      border-radius: 15px;
      text-transform: capitalize;
      font-size: 0.9rem;
      user-select: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .status-running {
      background-color: #22c55e;
      color: #064e3b;
    }
    .status-stopped {
      background-color: #ef4444;
      color: #7f1d1d;
    }
    .status-installing {
      background-color: #facc15;
      color: #92400e;
    }
    .btn-action {
      min-width: 90px;
    }
    .progress {
      height: 20px;
      border-radius: 12px;
      overflow: hidden;
    }
    .progress-bar {
      background: linear-gradient(90deg, #fbbf24, #f59e0b);
    }
  </style>
</head>
<body>
  <div class="container">
    <h1 class="mb-4">La Mia Dashboard Minecraft Bedrock</h1>
    <?php if (empty($servers)): ?>
      <div class="alert alert-warning text-center fs-5">
        Non hai ancora server Minecraft. <a href="create_server.php" class="alert-link">Crea un nuovo server</a>.
      </div>
    <?php else: ?>
      <table class="table table-dark table-hover align-middle text-center">
        <thead>
          <tr>
            <th>Nome Server</th>
            <th>IP / Dominio</th>
            <th>Versione</th>
            <th>Stato</th>
            <th>Progresso</th>
            <th>Azioni</th>
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
              <td>
                <?php 
                  $status = $srv['status'] ?? 'stopped';
                  $statusClass = match($status) {
                    'running' => 'status-running',
                    'stopped' => 'status-stopped',
                    'installing', 'downloading_mods' => 'status-installing',
                    default => 'status-stopped',
                  };
                  $statusIcon = match($status) {
                    'running' => '<i class="fa-solid fa-circle-check"></i>',
                    'stopped' => '<i class="fa-solid fa-circle-xmark"></i>',
                    'installing', 'downloading_mods' => '<i class="fa-solid fa-spinner fa-spin"></i>',
                    default => '<i class="fa-solid fa-circle-xmark"></i>',
                  };
                ?>
                <span class="status-badge <?= $statusClass ?>"><?= $statusIcon . " " . ucfirst($status) ?></span>
              </td>
              <td style="min-width: 140px;">
                <?php if (in_array($status, ['installing', 'downloading_mods'])): ?>
                  <div class="progress" title="Installazione in corso">
                    <div class="progress-bar" role="progressbar" style="width: <?= intval($srv['progress']) ?>%" aria-valuenow="<?= intval($srv['progress']) ?>" aria-valuemin="0" aria-valuemax="100"><?= intval($srv['progress']) ?>%</div>
                  </div>
                <?php else: ?>
                  <span>-</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === 'running'): ?>
                  <button class="btn btn-sm btn-danger btn-action" onclick="event.stopPropagation(); serverAction(<?= $srv['id'] ?>, 'stop', this)">Ferma</button>
                <?php elseif (in_array($status, ['stopped', 'ready'])): ?>
                  <button class="btn btn-sm btn-success btn-action" onclick="event.stopPropagation(); serverAction(<?= $srv['id'] ?>, 'start', this)">Avvia</button>
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
</body>
</html>
