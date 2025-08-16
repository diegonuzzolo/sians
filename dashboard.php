<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id = ?
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #fff;
        }

        .card-server {
            background: linear-gradient(135deg, #1f1f1f, #2c2c2c);
            border-radius: 20px;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card-server:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 200, 255, 0.3);
        }

        .server-name {
            color: #00c8ff;
            font-weight: bold;
            font-size: 1.3rem;
        }

        .server-ip {
            color: #a0a0a0;
            font-size: 0.9rem;
        }

        .btn-server {
            width: 48%;
        }

        .status-badge {
            padding: 0.4em 0.7em;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-running {
            background-color: #28a745;
            color: #fff;
        }

        .status-stopped {
            background-color: #dc3545;
            color: #fff;
        }
    </style>
</head>

<body>
    <?php include "includes/header.php"; ?>

    <div class="container my-5">
        <h2 class="mb-4 text-center" style="color:#00c8ff;">🖥️ I tuoi server</h2>

        <?php if (empty($servers)): ?>
            <div class="alert alert-info">Non hai ancora server attivi.</div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($servers as $server):
                    $statusClass = ($server['status'] === 'running') ? 'status-running' : 'status-stopped';
                    $statusText = ($server['status'] === 'running') ? 'Attivo' : 'Spento';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card-server shadow">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="server-name"><?= htmlspecialchars($server['name']) ?></div>
                                <span class="status-badge <?= $statusClass ?>" id="status-<?= $server['id'] ?>">
                                    <?= $statusText ?>
                                </span>
                            </div>
                            <p class="server-ip mb-2">IP: <?= htmlspecialchars($server['ip']) ?></p>
                            <p class="server-ip mb-3">RAM: <?= htmlspecialchars($server['ram_mb']) ?> MB</p>

                            <div class="d-flex justify-content-between">
                                <button class="btn btn-success btn-server" id="start-<?= $server['id'] ?>"
                                    onclick="startServer(<?= $server['id'] ?>)">Avvia</button>
                                <button class="btn btn-danger btn-server" id="stop-<?= $server['id'] ?>"
                                    onclick="stopServer(<?= $server['id'] ?>)">Ferma</button>
                            </div>



                            <div class="mt-3 d-flex justify-content-between">
                                <a href="manage_server.php?id=<?= htmlspecialchars($server['id']) ?>"
                                    class="btn btn-primary btn-sm w-100 me-1">Gestisci</a>
                                <a href="delete_server.php?vmid=<?= htmlspecialchars($server['proxmox_containerId']) ?>"
                                    class="btn btn-outline-danger btn-sm w-100 ms-1"
                                    onclick="return confirm('Sei sicuro di voler eliminare questo server?');">
                                    Elimina
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>'; // Prendi il token CSRF dalla sessione

function updateButtons(serverId, status) {
    const startBtn = document.getElementById('start-' + serverId);
    const stopBtn = document.getElementById('stop-' + serverId);

    if (status === 'running') {
        startBtn.disabled = true;
        stopBtn.disabled = false;
    } else {
        startBtn.disabled = false;
        stopBtn.disabled = true;
    }
}

function controlServer(serverId, action) {
    fetch('ajax_server_control.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=' + action + '&id=' + serverId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if (data.success && data.status) {
            const statusEl = document.getElementById('status-' + serverId);
            statusEl.textContent = (data.status === 'running') ? 'Attivo' : 'Spento';
            statusEl.className = 'status-badge ' + (data.status === 'running' ? 'status-running' : 'status-stopped');
            updateButtons(serverId, data.status);
        }
    })
    .catch(err => {
        alert('Errore comunicazione con il server: ' + err);
    });
}

function startServer(serverId) { controlServer(serverId, 'start'); }
function stopServer(serverId) { controlServer(serverId, 'stop'); }

// Aggiornamento automatico stato ogni 10 secondi
setInterval(() => {
    <?php foreach ($servers as $server): ?>
        updateButtons(<?= $server['id'] ?>, '<?= $server['status'] ?>');
    <?php endforeach; ?>
}, 10000);
</script>

<?php include "includes/footer.php"; ?>
</body>

</html>