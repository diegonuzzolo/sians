<?php
session_start();
require 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Cerca il server dell'utente attuale (puoi rimuoverlo se non serve)
$stmt = $pdo->prepare("SELECT * FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

// Quanti slot ancora disponibili in totale
$stmt = $pdo->query("SELECT COUNT(*) FROM minecraft_vms WHERE assigned_user_id IS NULL");
$slotDisponibili = $stmt->fetchColumn();

// Quanti server ha l'utente
$stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id = ?");
$stmt->execute([$userId]);
$mieiServer = $stmt->fetchColumn();

// Prendi tutti i server dell'utente con dati VM (ip e hostname)
$stmt = $pdo->prepare("SELECT s.id, s.name, s.status, vm.proxmox_vmid, vm.ip_address, vm.hostname 
                       FROM servers s
                       JOIN minecraft_vms vm ON s.proxmox_vmid = vm.proxmox_vmid
                       WHERE s.user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard - Server Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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

    <?php if (count($servers) === 0): ?>
        <p class="text-muted">Non hai ancora server attivi.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped mt-3">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>ID Proxmox</th>
                        <th>IP / Hostname</th>
                        <th>Stato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><?= htmlspecialchars($server['name']) ?></td>
                            <td><?= $server['proxmox_vmid'] ?></td>
                            <td>
                                <?= htmlspecialchars($server['ip_address'] ?? '') ?><br>
                                <small><?= htmlspecialchars($server['hostname'] ?? '') ?></small>
                            </td>
                            <td>
                                <?php if ($server['status'] == 'running'): ?>
                                    <span class="badge bg-success">Attivo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Spento</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form action="server_action.php" method="post" class="d-inline">
                                    <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
                                    <?php if ($server['status'] === 'running'): ?>
                                        <button name="action" value="stop" class="btn btn-warning btn-sm">Ferma</button>
                                    <?php else: ?>
                                        <button name="action" value="start" class="btn btn-success btn-sm">Avvia</button>
                                    <?php endif; ?>
                                </form>

                                <form action="delete_server.php" method="post" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo server?');">
                                    <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
                                    <button class="btn btn-danger btn-sm">Elimina</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Azione Nuovo Server -->
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
    const rows = document.querySelectorAll('table tbody tr');

    rows.forEach(row => {
        const vmid = row.querySelector('td:nth-child(2)').textContent.trim();
        const statusBadge = row.querySelector('td:nth-child(4) span');

        if (!vmid || !statusBadge) return;

        let previousStatus = null;

        async function updateStatus() {
            try {
                const res = await fetch(`get_vm_status.php?vmid=${vmid}`);
                if (!res.ok) throw new Error('Errore rete');
                const data = await res.json();
                if (data.status) {
                    // Se cambia lo stato, evidenzia la riga (alert visivo)
                    if (previousStatus && previousStatus !== data.status) {
                        row.style.transition = 'background-color 0.5s ease';
                        row.style.backgroundColor = '#fff3cd'; // giallo chiaro

                        setTimeout(() => {
                            row.style.backgroundColor = '';
                        }, 2000);
                    }

                    previousStatus = data.status;

                    // Aggiorna badge UI in base allo stato reale
                    if (data.status === 'running') {
                        statusBadge.textContent = 'Attivo';
                        statusBadge.className = 'badge bg-success';
                    } else if (data.status === 'stopped' || data.status === 'halted') {
                        statusBadge.textContent = 'Spento';
                        statusBadge.className = 'badge bg-secondary';
                    } else {
                        statusBadge.textContent = data.status;
                        statusBadge.className = 'badge bg-warning';
                    }
                }
            } catch (e) {
                console.error('Errore aggiornamento stato VM', e);
            }
        }

        // Primo aggiornamento e poi ogni 10 secondi
        updateStatus();
        setInterval(updateStatus, 1000);
    });
});
</script>

</body>
</html>
