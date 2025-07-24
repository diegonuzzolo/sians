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
    body { background-color: #f7f9fc; }
    .server-box {
      background: white;
      border-radius: 12px;
      padding: 25px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    code {
      background: #f1f1f1;
      padding: 2px 6px;
      border-radius: 4px;
    }
  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container my-5">
    <h3 class="mb-4">I tuoi server Minecraft</h3>

    <?php if (empty($servers)): ?>
        <p class="text-muted">Non hai ancora server attivi.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>ID Proxmox</th>
                        <th>IP / Hostname</th>
                        <th>Tunnel ngrok TCP</th>
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
                            <?= !empty($server['ip_address']) ? htmlspecialchars($server['ip_address']) . '<br>' : '' ?>
                            <?= !empty($server['hostname']) ? '<small>' . htmlspecialchars($server['hostname']) . '</small><br>' : '' ?>
                        </td>
                        <td>
                            <?php if (!empty($server['tunnel_url'])): 
                                // Estraggo host e port da tunnel_url (es. tcp://0.tcp.ngrok.io:12345)
                                $url = $server['tunnel_url'];
                                $urlParts = parse_url(str_replace('tcp://', 'tcp://', $url));
                                $host = $urlParts['host'] ?? '';
                                $port = $urlParts['port'] ?? '';
                            ?>
                                <code><?= htmlspecialchars($host . ':' . $port) ?></code>
                            <?php else: ?>
                                <span class="text-muted small">Non disponibile</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($server['subdomain'])): ?>
                                <a href="http://<?= htmlspecialchars($server['subdomain']) ?>.sians.it" target="_blank" rel="noopener noreferrer">
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
                            <!-- Form Start/Stop -->
                            <form action="server_action.php" method="post" class="d-inline">
                                <input type="hidden" name="server_id" value="<?= htmlspecialchars($server['id']) ?>">
                                <button name="action" value="<?= $server['status'] === 'running' ? 'stop' : 'start' ?>"
                                        class="btn btn-sm <?= $server['status'] === 'running' ? 'btn-warning' : 'btn-success' ?>"
                                        title="<?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?> Server">
                                    <?= $server['status'] === 'running' ? 'Ferma' : 'Avvia' ?>
                                </button>
                            </form>

                            <!-- Form Delete -->
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

<!-- Nuovo Server -->
<div class="d-flex justify-content-center align-items-center my-4">
    <div class="card bg-light" style="width: 320px;">
        <div class="card-body text-center">
            <h5 class="card-title">Nuovo Server</h5>
            <?php if ($slotDisponibili > 0): ?>
               <form method="POST" action="add_server.php">
    <input type="text" name="server_name" placeholder="Nome server" required>
    <button type="submit">Crea server</button>
</form>

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
        const statusBadge = row.querySelector('td:nth-child(6) span'); // Stato è colonna 6
        const form = row.querySelector('form[action="server_action.php"]');
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
