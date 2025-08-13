<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$totalServers = (int)$stmt->fetchColumn();

if ($totalServers === 0) {
    // L'utente non ha ancora creato server
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ? AND status IN ('running', 'installed', 'ready')");
    $stmt->execute([$userId]);
    $readyCount = (int)$stmt->fetchColumn();

    if ($readyCount === 0) {
        header("Location: install_wait.php");
        exit;
    }
}

$sql = "SELECT c.id AS container_id, c.cpu_cores, c.ram_mb, c.assigned_server_id,
               s.id AS server_id, s.name AS server_name, s.status AS server_status, s.tunnel_url
        FROM containers c
        LEFT JOIN servers s ON s.container_id = c.id
        WHERE c.assigned_user_id = ?
        ORDER BY c.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include("includes/header.php"); ?>
<link rel="stylesheet" href="assets/css/style.css">

<main>
  <div class="container my-5">
    <h1 class="mb-5 text-center text-bedrock display-4 fw-bold">🎮 La Mia Dashboard</h1>

    <?php if (empty($containers)): ?>
      <div class="alert alert-info text-center fs-5">
        Non hai ancora server. Creane uno nuovo.
      </div>
    <?php else: ?>
      <div class="d-flex flex-wrap justify-content-center gap-4">
        <?php foreach ($containers as $c): ?>
          <?php
            $serverExists = !empty($c['server_id']);
            $status = $c['server_status'] ?? null;
          ?>
          <div class="card server-card shadow-lg text-center p-3" style="width: 280px; border-radius: 15px;">
            <div class="card-header bg-bedrock text-white rounded-top py-3">
              <h4 class="card-title mb-0 text-truncate" title="<?= htmlspecialchars($c['server_name'] ?? 'Container vuoto') ?>">
                <?= $serverExists ? htmlspecialchars($c['server_name']) : "<em>Container vuoto</em>" ?>
              </h4>
            </div>
            <div class="card-body py-4">
              <p><strong>IP</strong><br>
                <?php if ($serverExists && !empty($c['tunnel_url'])): ?>
                  <a href="<?= htmlspecialchars($c['tunnel_url']) ?>" target="_blank" class="text-decoration-none text-bedrock fw-semibold">
                    <?= htmlspecialchars($c['tunnel_url']) ?>
                  </a>
                <?php else: ?>
                  <span>Non disponibile</span>
                <?php endif; ?>
              </p>
              <p><strong>CPU Cores:</strong> <?= htmlspecialchars($c['cpu_cores'] ?? '-') ?></p>
              <p><strong>RAM:</strong> <?= round(($c['ram_mb'] ?? 0) / 1024, 2) ?> GB</p>

              <p><strong>Stato:</strong> 
                <span class="status-badge <?= 'status-' . ($status ?? 'not_installed') ?>">
                  <?= htmlspecialchars(ucfirst($status ?? 'Non installato')) ?>
                </span>
              </p>
              <a href="server.php?id=<?= $c['server_id'] ?? 0 ?>" class="btn btn-bedrock btn-lg w-100 mt-3">Gestisci Server</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<style>
  .bg-bedrock { background-color: #003f54 !important; }
  .text-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px black; }
  .btn-bedrock {
    background-color: #00c8ff;
    color: black;
    font-weight: 700;
    transition: background-color 0.3s ease;
  }
  .btn-bedrock:hover {
    background-color: #0099cc;
    color: white;
  }
  .server-card {
    background: #001f2d;
    color: white;
    cursor: default;
    user-select: none;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
  }
  .server-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 8px 24px rgba(0, 200, 255, 0.5);
  }
  .status-running { color: #00ff00; font-weight: 700; }
  .status-stopped { color: #ff5555; font-weight: 700; }
  .status-installing { color: #ffaa00; font-weight: 700; }
  .status-not_installed { color: #cccccc; font-weight: 700; font-style: italic; }
</style>

<?php include("includes/footer.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
