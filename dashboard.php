<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

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
  <meta charset="UTF-8">
  <title>Dashboard Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(to right, #0f172a, #1e293b);
      color: #f1f5f9;
      font-family: 'Segoe UI', sans-serif;
    }
    h2 {
      color: #facc15;
      font-weight: 600;
    }
    .server-card {
  background: #0f172a;
  border: 1px solid #334155;
  border-radius: 16px;
  padding: 25px 20px;
  margin-bottom: 25px;
  box-shadow: inset 0 0 10px rgba(51, 65, 85, 0.2), 0 4px 12px rgba(0, 0, 0, 0.3);
  transition: all 0.3s ease-in-out;
  color: #e2e8f0;
  font-size: 1.05rem;
}

.server-card:hover {
  transform: scale(1.02);
  box-shadow: 0 0 25px rgba(250, 204, 21, 0.2);
}

.server-card h5 {
  font-size: 1.3rem;
  font-weight: bold;
  color: #facc15;
}

.server-status {
  font-weight: bold;
  font-size: 1rem;
}

.badge-running {
  background-color: #16a34a;
  color: #fff;
  padding: 6px 12px;
  border-radius: 8px;
}

.badge-stopped {
  background-color: #475569;
  color: #fff;
  padding: 6px 12px;
  border-radius: 8px;
}

.ip-box {
  background-color: #1e293b;
  color: #93c5fd;
  padding: 6px 12px;
  border-radius: 8px;
  display: inline-block;
  font-size: 0.95rem;
  font-family: monospace;
}

.action-btn {
  padding: 10px 16px;
  font-size: 1rem;
  font-weight: 500;
  border-radius: 10px;
  transition: transform 0.2s ease-in-out;
}

.action-btn:hover {
  transform: scale(1.05);
}

.card-create {
  background: linear-gradient(to right, #0ea5e9, #22d3ee);
  color: #0f172a;
  font-weight: bold;
  transition: 0.3s;
  border-radius: 12px;
  font-size: 1.2rem;
  padding: 14px 24px;
}

.card-create:hover {
  background: linear-gradient(to right, #06b6d4, #38bdf8);
  transform: scale(1.04);
}

  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container my-5">

  <?php if (empty($servers)): ?>
  <?php else: ?>
    <div class="row">
      <?php foreach ($servers as $server): ?>
        <div class="col-md-12 col-lg-6">
          <div class="server-card">
            <h5><i class="fa-solid fa-server me-1"></i><?= htmlspecialchars($server['name']) ?></h5>
            <p class="mb-1"><strong>ID VM:</strong> <?= htmlspecialchars($server['proxmox_vmid']) ?></p>
            <p class="mb-1"><strong>IP:</strong> 
              <?php if (!empty($server['tunnel_url'])):
                $url = $server['tunnel_url'];
                $parts = parse_url(str_replace('tcp://', 'tcp://', $url));
                $host = $parts['host'] ?? '';
                $port = $parts['port'] ?? '';
              ?>
                <span class="ip-box"><?= htmlspecialchars($host . ':' . $port) ?></span>
              <?php else: ?>
                <span class="text-muted">Non disponibile</span>
              <?php endif; ?>
            </p>
            <p class="mb-2"><strong>Stato:</strong> 
              <span class="badge <?= $server['status'] === 'running' ? 'badge-running' : 'badge-stopped' ?>">
                <?= $server['status'] === 'running' ? 'Attivo' : 'Spento' ?>
              </span>
            </p>

            <div class="d-flex justify-content-between">
              <form method="post" action="server_action.php">
                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                <button name="action" value="<?= $server['status'] === 'running' ? 'stop' : 'start' ?>"
                        class="btn <?= $server['status'] === 'running' ? 'btn-warning' : 'btn-success' ?> action-btn">
                  <?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?>
                </button>
              </form>

              <form method="POST" action="delete_server.php" onsubmit="return confirm('Eliminare il server?')">
                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                <button type="submit" class="btn btn-danger action-btn">Elimina</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Card per creare nuovo server -->
  <div class="text-center mt-5">
    <?php if ($slotDisponibili > 0): ?>
      <a href="add_server.php" class="btn btn-lg card-create px-5 py-3">
        <i class="fa-solid fa-plus me-2"></i> Crea Nuovo Server
      </a>
    <?php else: ?>
      <p class="text-danger mt-3 fw-bold">❌ Nessun slot disponibile al momento</p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
