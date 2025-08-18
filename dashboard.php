<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Server attivi
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type, c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Slot server disponibili (non ancora associati a un server)
$stmtSlots = $pdo->prepare("
    SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code
    FROM server_slots ss
    JOIN plans p ON p.stripe_price_id = ss.plan_id
    WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'
");
$stmtSlots->execute([$userId]);
$serverSlots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background-color: #121212; color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.card-server { background: linear-gradient(135deg,#1f1f1f,#2c2c2c); border-radius: 20px; padding: 15px; transition: transform 0.2s, box-shadow 0.3s; position: relative;}
.card-server:hover { transform: translateY(-5px); box-shadow:0 15px 40px rgba(0,200,255,0.4);}
.server-name { color: #00c8ff; font-weight: bold; font-size:1.2rem; margin-bottom:0.3rem;}
.server-ip { color:#a0a0a0; font-size:0.85rem; margin-bottom:0.5rem;}
.status-badge { padding:0.3em 0.7em; border-radius:12px; font-size:0.8rem; font-weight:bold; text-transform:uppercase;}
.status-running { background-color:#28a745; color:#fff;}
.status-stopped { background-color:#dc3545; color:#fff;}
.status-available { background-color:#ffc107; color:#000;}
.btn-server { width:48%; margin-top:5px;}
.server-actions { display:flex; justify-content:space-between; margin-top:8px;}
</style>
<?php include('includes/header.php'); ?>
<div class="container my-5">
<h2 class="mb-4 text-center display-5 fw-bold">🎮 I tuoi server</h2>

<!-- Lista server -->
<div id="server-list" class="row">
<?php foreach ($servers as $server):
    $statusClass = ($server['status']==='running')?'status-running':'status-stopped';
?>
<div class="col-md-4 mb-3" id="server-<?= $server['id'] ?>">
    <div class="card card-server p-3 shadow">
        <div class="server-name"><?= htmlspecialchars($server['name']) ?></div>
        <div class="server-ip">VMID: <?= $server['proxmox_containerId'] ?? 'N/A' ?> | IP: <?= $server['ip'] ?? 'N/A' ?></div>
        <span id="status-<?= $server['id'] ?>" class="status-badge <?= $statusClass ?>"><?= ucfirst($server['status']) ?></span>

        <div class="server-actions">
            <button id="start-<?= $server['id'] ?>" class="btn btn-success btn-server" onclick="startServer(<?= $server['id'] ?>)" <?= $server['status']==='running'?'disabled':'' ?>>Avvia</button>
            <button id="stop-<?= $server['id'] ?>" class="btn btn-danger btn-server" onclick="stopServer(<?= $server['id'] ?>)" <?= $server['status']==='stopped'?'disabled':'' ?>>Ferma</button>
        </div>

        <div class="server-actions mt-2">
            <a href="manage_server.php?id=<?= $server['id'] ?>" class="btn btn-primary btn-server">Gestisci</a>
            <button class="btn btn-outline-danger btn-server" onclick="deleteServer(<?= $server['id'] ?>, '<?= $server['proxmox_containerId'] ?>')">Elimina</button>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>
<hr>
<div class="container my-5">
<h2 class="mb-4 text-center display-5 fw-bold">🎁 Slot server disponibili</h2>
<!-- Lista slot server -->
<div id="slot-list" class="row">
<?php foreach ($serverSlots as $slot): ?>
    <div class="col-md-4 mb-3" id="slot-<?= $slot['id'] ?>">
        <div class="card card-server p-3 shadow">
            <div class="server-name">Slot disponibile</div>
            <div class="server-ip">Piano: <?= htmlspecialchars($slot['name']) ?></div>
            <span class="status-badge status-available">Disponibile</span>
            <div class="server-actions mt-2">
                <a href="new_server.php?plan_code=<?= $slot['plan_code'] ?>&slot_id=<?= $slot['id'] ?>" class="btn btn-primary btn-server w-100">Crea Server</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
</div>


<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';

// --- CONTROLLO SERVER (start/stop) ---
function controlServer(serverId, action) {
    fetch('ajax_server_control.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=' + encodeURIComponent(action) + '&id=' + serverId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.status) {
            const statusEl = document.getElementById('status-' + serverId);
            if (statusEl) {
                statusEl.textContent = (data.status==='running')?'Attivo':'Spento';
                statusEl.className = 'status-badge ' + (data.status==='running'?'status-running':'status-stopped');
            }
            updateButtons(serverId, data.status);
        }
    })
    .catch(err => console.error(err));
}
function startServer(serverId){ controlServer(serverId,'start'); }
function stopServer(serverId){ controlServer(serverId,'stop'); }
function updateButtons(serverId,status){
    const startBtn=document.getElementById('start-'+serverId);
    const stopBtn=document.getElementById('stop-'+serverId);
    if(!startBtn || !stopBtn) return;
    if(status==='running'){ startBtn.disabled=true; stopBtn.disabled=false; }
    else{ startBtn.disabled=false; stopBtn.disabled=true; }
}

// --- ELIMINA SERVER ---
function deleteServer(serverId, vmid) {
    if (!confirm('Sei sicuro di voler eliminare questo server?')) return;
    fetch(`delete_server.php?vmid=${vmid}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('server-' + serverId)?.remove();
            } else {
                alert("Errore eliminazione server: " + data.message);
            }
        })
        .catch(err => console.error(err));
}

function refreshSlots() {
    fetch('ajax_get_slots.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            const container = document.getElementById('slot-list');
            container.innerHTML = ''; // svuota
            data.slots.forEach(slot => {
                container.innerHTML += `
                <div class="col-md-4 mb-3" id="slot-${slot.id}">
                    <div class="card card-server p-3 shadow">
                        <div class="server-name">Slot disponibile</div>
                        <div class="server-ip">Piano: ${slot.name}</div>
                        <span class="status-badge status-available">Disponibile</span>
                        <div class="server-actions mt-2">
                            <a href="new_server.php?plan_code=${slot.plan_code}&slot_id=${slot.id}" class="btn btn-primary btn-server w-100">Crea Server</a>
                        </div>
                    </div>
                </div>`;
            });
        });
}

// Aggiorna ogni 10 secondi
setInterval(refreshSlots, 10000);

</script>

<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
