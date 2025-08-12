
<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Prendi i container associati all'utente e info server se esiste
$sql = "SELECT c.id AS container_id, c.private_ip, c.hostname, c.cpu_cores, c.ram_mb, c.assigned_server_id,
               s.id AS server_id, s.name AS server_name, s.status AS server_status
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
  <div class="container my-4">
    <h1 class="mb-4 text-center text-bedrock">🎮 Minecraft Bedrock - La Mia Dashboard</h1>

    <?php if (empty($containers)): ?>
      <div class="alert alert-info text-center fs-5">
        Non hai ancora container assegnati. Contatta l’amministratore.
      </div>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($containers as $c): ?>
          <div class="col-md-6 col-lg-4">
            <div class="card shadow-lg">
              <div class="card-header bg-bedrock text-white">
                <h5 class="card-title mb-0"><?= htmlspecialchars($c['hostname']) ?></h5>
              </div>
              <div class="card-body">
                <p><strong>IP privato:</strong> <?= htmlspecialchars($c['private_ip'] ?? '-') ?></p>
                <p><strong>CPU Cores:</strong> <?= htmlspecialchars($c['cpu_cores'] ?? '-') ?></p>
                <p><strong>RAM:</strong> <?= round(($c['ram_mb'] ?? 0) / 1024, 2) ?> GB</p>

                <?php if ($c['server_id']): ?>
                  <p><strong>Server:</strong> <?= htmlspecialchars($c['server_name'] ?? '-') ?></p>
                  <p><strong>Stato:</strong> <?= htmlspecialchars($c['server_status'] ?? '-') ?></p>
                  <a href="server.php?id=<?= $c['server_id'] ?>" class="btn btn-bedrock">Gestisci Server</a>
                <?php else: ?>
                  <p><em>Server non installato</em></p>
                  <button class="btn btn-primary btn-install" data-container-id="<?= $c['container_id'] ?>">Installa Server Bedrock</button>
                <?php endif; ?>
              </div>
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
  .bg-bedrock { background-color: #003f54; }
  .text-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px black; }
  .btn-bedrock { background-color: #00c8ff; color: black; font-weight: bold; }
</style>

<?php include("includes/footer.php"); ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
