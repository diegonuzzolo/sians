<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Server attivi
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id = ?
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Subscription e slot disponibili
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code, p.ram_gb, p.cpu_cores
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id = ?
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map piano -> subscription info
$subsByPlan = [];
foreach ($subscriptions as $sub) {
    $subsByPlan[$sub['plan_code']] = $sub;
}

// Recupera piani attivi
$stmt = $pdo->query("SELECT * FROM plans WHERE active=1 ORDER BY ram_gb ASC");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcola slot disponibili per piano
$slotsAvailablePerPlan = [];
foreach ($plans as $plan) {
    $sub = $subsByPlan[$plan['code']] ?? null;
    $slotsAvailablePerPlan[$plan['id']] = $sub ? max(0, $sub['total'] - $sub['used']) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body {
        background-color: #121212;
        color: #fff;
    }

    .card-server, .card-plan {
        background: linear-gradient(135deg, #1f1f1f, #2c2c2c);
        border-radius: 20px;
        padding: 25px 20px;
        transition: transform 0.3s, box-shadow 0.3s;
        position: relative;
        overflow: hidden;
        text-align: center;
    }

    .card-server:hover, .card-plan:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(0, 200, 255, 0.4);
    }

    .card-plan::before {
        content: "";
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: rgba(0, 200, 255, 0.1);
        transform: rotate(45deg);
        pointer-events: none;
    }

    .server-name, .card-plan h5 {
        color: #00c8ff;
        font-weight: bold;
        font-size: 1.3rem;
    }

    .server-ip, .card-plan p {
        color: #aaa;
        font-size: 0.9rem;
    }

    .btn-server, .btn-plan {
        width: 48%;
        background: #00c8ff;
        color: #fff;
        font-weight: bold;
        border-radius: 12px;
        padding: 10px 0;
        transition: background 0.3s;
        text-decoration: none;
        display: inline-block;
    }

    .btn-server:hover, .btn-plan:hover {
        background: #00a3d3;
        color: #fff;
    }

    .status-badge {
        padding: 0.4em 0.7em;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: bold;
        text-transform: uppercase;
    }

    .status-running { background-color: #28a745; color: #fff; }
    .status-stopped { background-color: #dc3545; color: #fff; }
</style>
</head>
<body>
<?php include('includes/header.php'); ?>
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
                <div class="d-flex justify-content-between mb-3">
                    <button class="btn btn-server" id="start-<?= $server['id'] ?>" onclick="startServer(<?= $server['id'] ?>)">Avvia</button>
                    <button class="btn btn-server" id="stop-<?= $server['id'] ?>" onclick="stopServer(<?= $server['id'] ?>)">Ferma</button>
                </div>
                <div class="d-flex justify-content-between">
                    <a href="manage_server.php?id=<?= htmlspecialchars($server['id']) ?>" class="btn btn-server w-100 me-1">Gestisci</a>
                    <a href="delete_server.php?vmid=<?= htmlspecialchars($server['proxmox_containerId']) ?>" class="btn btn-server w-100 ms-1" onclick="return confirm('Sei sicuro di voler eliminare questo server?');">Elimina</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="mt-5 mb-4 text-center" style="color:#00c8ff;">📦 I tuoi piani disponibili</h2>
<div class="row g-4">
<?php
foreach ($plans as $plan):
    $slotsAvailable = $slotsAvailablePerPlan[$plan['id']] ?? 0;
    if ($slotsAvailable <= 0) continue;
?>
    <div class="col-md-4">
        <div class="card-plan">
            <h5><?= htmlspecialchars($plan['code']) ?></h5>
            <p><?= $plan['ram_gb'] ?> GB RAM</p>
            <p><?= $plan['cpu_cores'] ?> Cores</p>
            <p><?= $slotsAvailable ?> slot disponibili</p>
            <a href="add_server.php?plan_code=<?= urlencode($plan['code']) ?>&server_name=Il Mio Server&recreate=1"
               class="btn-plan mt-2 w-100"
               data-plan-code="<?= htmlspecialchars($plan['code']) ?>">
               Crea Server
            </a>
        </div>
    </div>
<?php endforeach; ?>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';

function updateButtons(serverId, status) {
    const startBtn = document.getElementById('start-' + serverId);
    const stopBtn = document.getElementById('stop-' + serverId);
    if (!startBtn || !stopBtn) return;
    startBtn.disabled = (status === 'running');
    stopBtn.disabled = (status !== 'running');
}

function controlServer(serverId, action) {
    fetch('ajax_server_control.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=' + encodeURIComponent(action) + '&id=' + serverId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.status) {
            const statusEl = document.getElementById('status-' + serverId);
            if (statusEl) {
                statusEl.textContent = (data.status === 'running') ? 'Attivo' : 'Spento';
                statusEl.className = 'status-badge ' + (data.status === 'running' ? 'status-running' : 'status-stopped');
            }
            updateButtons(serverId, data.status);
        }
        if (data.recalculateSlots) {
            recalcSlots(data.recalculateSlots);
        }
    })
    .catch(err => console.error('Errore comunicazione server:', err));
}

function startServer(serverId) { controlServer(serverId, 'start'); }
function stopServer(serverId) { controlServer(serverId, 'stop'); }

function recalcSlots(slotsData) {
    for (const planCode in slotsData) {
        const btn = document.querySelector(`a[data-plan-code="${planCode}"]`);
        if (!btn) continue;
        const slots = slotsData[planCode];
        btn.textContent = slots > 0 ? 'Ricrea Server' : 'Slot Esauriti';
        if (slots > 0) btn.classList.remove('disabled');
        else btn.classList.add('disabled');
    }
}

// Aggiornamento automatico stato server e slot ogni 10 secondi
setInterval(() => {
    <?php foreach ($servers as $server): ?>
        fetch('check_status.php?id=<?= $server['id'] ?>')
            .then(res => res.json())
            .then(data => {
                const statusEl = document.getElementById('status-<?= $server['id'] ?>');
                if (statusEl) {
                    statusEl.textContent = (data.status === 'running') ? 'Attivo' : 'Spento';
                    statusEl.className = 'status-badge ' + (data.status === 'running' ? 'status-running' : 'status-stopped');
                    updateButtons(<?= $server['id'] ?>, data.status);
                }
            });
    <?php endforeach; ?>

    fetch('ajax_check_slots.php')
        .then(res => res.json())
        .then(data => recalcSlots(data))
        .catch(err => console.error('Errore fetch slots:', err));

}, 10000);
</script>
<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
