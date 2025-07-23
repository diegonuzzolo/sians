<?php
session_start();
require 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Slot disponibili
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Server utente
$stmt = $pdo->prepare("SELECT s.id, s.name, s.status, s.subdomain, vm.proxmox_vmid, vm.ip_address, vm.hostname, s.ngrok_tcp_host, s.ngrok_tcp_port
                       FROM servers s
                       JOIN minecraft_vms vm ON s.proxmox_vmid = vm.proxmox_vmid
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
    body { background-color: #f7f9fc; }
    .server-box {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container my-5">
    <h3>I tuoi server Minecraft</h3>

    <?php if (empty($servers)): ?>
        <p class="text-muted">Non hai ancora server attivi.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped mt-3">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>ID Proxmox</th>
                        <th>IP / Hostname / Ngrok</th>
                        <th>Dominio</th>
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
                            <?php if (!empty($server['ip_address'])): ?>
                                <?= htmlspecialchars($server['ip_address']) ?><br>
                            <?php endif; ?>

                            <?php if (!empty($server['hostname'])): ?>
                                <small><?= htmlspecialchars($server['hostname']) ?></small><br>
                            <?php endif; ?>

                            <?php if (!empty($server['ngrok_tcp_host']) && !empty($server['ngrok_tcp_port'])): ?>
                                <div>
                                    <strong>Ngrok:</strong>
                                    <code><?= htmlspecialchars($server['ngrok_tcp_host']) ?>:<?= htmlspecialchars($server['ngrok_tcp_port']) ?></code>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">Ngrok non disponibile</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($server['subdomain'])): ?>
                                <a href="http://<?= htmlspecialchars($server['subdomain']) ?>.sians.it" target="_blank">
                                    <?= htmlspecialchars($server['subdomain']) ?>.sians.it
                                </a>
                            <?php else: ?>
                                <span class="text-muted">In attesa...</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $server['status'] === 'running' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $server['status'] === 'running' ? 'Attivo' : 'Spento' ?>
                            </span>
                        </td>
                        <td>
                            <!-- Azioni -->
                            <form action="server_action.php" method="post" class="d-inline action-form">
                                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                                <button name="action" value="<?= $server['status'] === 'running' ? 'stop' : 'start' ?>"
                                        class="btn btn-sm <?= $server['status'] === 'running' ? 'btn-warning' : 'btn-success' ?>"
                                        title="<?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?> Server">
                                    <?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?>
                                </button>
                            </form>
                            <form action="delete_server.php" method="post" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo server?');">
                                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                                <button class="btn btn-danger btn-sm" title="Elimina Server">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Nuovo Server -->
<div class="d-flex justify-content-center align-items-center">
    <div class="card bg-light mb-3" style="width: 300px;">
        <div class="card-body text-center">
            <h5 class="card-title">Nuovo Server</h5>
            <?php if ($slotDisponibili > 0): ?>
                <a href="add_server.php" class="btn btn-success">Crea Nuovo Server</a>
            <?php else: ?>
                <p class="text-danger mt-2">Nessuno slot disponibile</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('tbody tr');

    rows.forEach(row => {
        const vmid = row.dataset.vmid;
        const serverId = row.dataset.serverId;
        const statusBadge = row.querySelector('td:nth-child(5) span'); // Stato è colonna 5
        const form = row.querySelector('.action-form');
        const button = form.querySelector('button');

        let previousStatus = null;

        async function updateStatus() {
            try {
                const res = await fetch(`get_vm_status.php?vmid=${vmid}`);
                if (!res.ok) throw new Error('Errore rete');
                const data = await res.json();

                if (data.status) {
                    if (previousStatus && previousStatus !== data.status) {
                        row.style.transition = 'background-color 0.5s';
                        row.style.backgroundColor = '#fff3cd';
                        setTimeout(() => row.style.backgroundColor = '', 2000);
                    }

                    previousStatus = data.status;

                    // Badge
                    if (data.status === 'running') {
                        statusBadge.textContent = 'Attivo';
                        statusBadge.className = 'badge bg-success';
                        // Bottone: FERMA
                        button.textContent = 'Ferma';
                        button.className = 'btn btn-warning btn-sm';
                        button.value = 'stop';
                        button.title = 'Ferma Server';
                    } else {
                        statusBadge.textContent = 'Spento';
                        statusBadge.className = 'badge bg-secondary';
                        // Bottone: AVVIA
                        button.textContent = 'Avvia';
                        button.className = 'btn btn-success btn-sm';
                        button.value = 'start';
                        button.title = 'Avvia Server';
                    }
                }
            } catch (err) {
                console.error('Errore nel polling stato VM:', err);
            }
        }

        updateStatus();
        setInterval(updateStatus, 5000); // ogni 5 secondi
    });
});
</script>

</body>
</html>
