<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Slot disponibili
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Server utente
$stmt = $pdo->prepare("SELECT s.id, s.name, s.status, s.subdomain, vm.proxmox_vmid, vm.ip AS ip_address, vm.hostname, s.tunnel_url
                       FROM servers s
                       JOIN minecraft_vms vm ON s.vm_id = vm.id
                       WHERE s.user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <title>Dashboard - Server Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background-color: #0f172a;
      color: #e2e8f0;
      font-family: 'Segoe UI', sans-serif;
    }
    .server-box {
      background: #1e293b;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }
    .table-dark th {
      background-color: #334155 !important;
      color: #e2e8f0 !important;
    }
    .badge {
      font-size: 0.9em;
    }
    .btn {
      border-radius: 8px;
    }
    .card.bg-light {
      background-color: #1e293b !important;
      border: 1px solid #334155;
    }
    .card-title {
      color: #facc15;
    }
    .btn-success {
      background-color: #22c55e;
      border-color: #22c55e;
    }
    .btn-danger {
      background-color: #ef4444;
      border-color: #ef4444;
    }
    .btn-warning {
      background-color: #f59e0b;
      border-color: #f59e0b;
    }
    .btn:hover {
      filter: brightness(1.1);
    }
    code {
      background: #334155;
      padding: 4px 8px;
      border-radius: 6px;
      color: #f8fafc;
    }
    a.btn {
      font-weight: 500;
    }
  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container my-5">
    <h2 class="mb-4 text-center text-warning">🎮 I tuoi server Minecraft</h2>

    <?php if (empty($servers)): ?>
        <p class="text-muted text-center">Non hai ancora server attivi.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>ID VM</th>
                        <th>IP Tunnel</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr data-vmid="<?= htmlspecialchars($server['proxmox_vmid']) ?>" data-server-id="<?= htmlspecialchars($server['id']) ?>">
                        <td><?= htmlspecialchars($server['name']) ?></td>
                        <td><?= htmlspecialchars($server['proxmox_vmid']) ?></td>
                        <td>
                            <?php if (!empty($server['tunnel_url'])):
                                $url = $server['tunnel_url'];
                                $urlParts = parse_url(str_replace('tcp://', 'tcp://', $url));
                                $host = $urlParts['host'] ?? '';
                                $port = $urlParts['port'] ?? '';
                            ?>
                                <code><?= htmlspecialchars($host . ':' . $port) ?></code>
                            <?php else: ?>
                                <span class="text-muted small">In attesa tunnel</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $server['status'] === 'running' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $server['status'] === 'running' ? 'Attivo' : 'Spento' ?>
                            </span>
                        </td>
                        <td>
                            <!-- Start/Stop -->
                            <form action="server_action.php" method="post" class="d-inline">
                                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                                <button name="action" value="<?= $server['status'] === 'running' ? 'stop' : 'start' ?>"
                                        class="btn btn-sm <?= $server['status'] === 'running' ? 'btn-warning' : 'btn-success' ?>"
                                        title="<?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?> Server">
                                    <?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?>
                                </button>
                            </form>

                            <!-- Delete -->
                            <form method="POST" action="delete_server.php" onsubmit="return confirm('Sei sicuro di voler eliminare questo server?');" class="d-inline">
                                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Elimina Server">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Box per creare un nuovo server -->
<div class="d-flex justify-content-center align-items-center my-4">
    <div class="card bg-light shadow-lg" style="width: 320px;">
        <div class="card-body text-center">
            <h5 class="card-title">+ Nuovo Server</h5>
            <?php if ($slotDisponibili > 0): ?>
                <a href="add_server.php" class="btn btn-success w-100">Crea Nuovo Server</a>
            <?php else: ?>
                <p class="text-danger mt-2">❌ Nessuno slot disponibile</p>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>
