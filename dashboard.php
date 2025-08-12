<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

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
    <h1 class="mb-5 text-center text-bedrock display-4 fw-bold">🎮 Minecraft Bedrock - La Mia Dashboard</h1>

    <?php if (empty($containers)): ?>
      <div class="alert alert-info text-center fs-5">
        Non hai ancora container assegnati. Contatta l’amministratore.
      </div>
    <?php else: ?>
      <div class="d-flex flex-wrap justify-content-center gap-4">
        <?php foreach ($containers as $c): ?>
          <div class="card server-card shadow-lg text-center p-3" style="width: 280px; border-radius: 15px;">
            <div class="card-header bg-bedrock text-white rounded-top py-3">
              <h4 class="card-title mb-0 text-truncate" title="<?= htmlspecialchars($c['server_name'] ?? 'Server non installato') ?>">
                <?= $c['server_id'] ? htmlspecialchars($c['server_name']) : "<em>Server non installato</em>" ?>
              </h4>
            </div>
            <div class="card-body py-4">
              <p><strong>Tunnel URL:</strong><br>
                <?php if (!empty($c['tunnel_url'])): ?>
                  <a href="<?= htmlspecialchars($c['tunnel_url']) ?>" target="_blank" class="text-decoration-none text-bedrock fw-semibold">
                    <?= htmlspecialchars($c['tunnel_url']) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">Non disponibile</span>
                <?php endif; ?>
              </p>
              <p><strong>CPU Cores:</strong> <?= htmlspecialchars($c['cpu_cores'] ?? '-') ?></p>
              <p><strong>RAM:</strong> <?= round(($c['ram_mb'] ?? 0) / 1024, 2) ?> GB</p>

              <?php if ($c['server_id']): ?>
                <p><strong>Stato:</strong> <span class="status-badge <?= 'status-' . ($c['server_status'] ?? 'stopped') ?>"><?= htmlspecialchars(ucfirst($c['server_status'] ?? 'stopped')) ?></span></p>
                <a href="server.php?id=<?= $c['server_id'] ?>" class="btn btn-bedrock btn-lg w-100 mt-3">Gestisci Server</a>
              <?php else: ?>
                <button class="btn btn-primary btn-install btn-lg w-100 mt-3" data-container-id="<?= $c['container_id'] ?>">Installa Server Bedrock</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
  document.querySelectorAll('.btn-install').forEach(btn => {
    btn.addEventListener('click', async () => {
      const containerId = btn.dataset.containerId;
      btn.disabled = true;
      btn.textContent = 'Installazione in corso...';

      try {
        const resp = await fetch('install_server.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({container_id: containerId})
        });
        const data = await resp.json();

        if (data.success) {
          alert('Installazione avviata con successo!');
          location.reload();
        } else {
          alert('Errore: ' + (data.error || 'Impossibile avviare l\'installazione'));
          btn.disabled = false;
          btn.textContent = 'Installa Server Bedrock';
        }
      } catch (err) {
        alert('Errore di comunicazione col server');
        btn.disabled = false;
        btn.textContent = 'Installa Server Bedrock';
      }
    });
  });
</script>

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
</style>

<?php include("includes/footer.php"); ?>
