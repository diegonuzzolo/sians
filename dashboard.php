<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Recupera i server attivi dell'utente
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera gli slot disponibili dell'utente
$stmt = $pdo->prepare("
    SELECT ss.*, p.code AS plan_code, p.name AS plan_name
    FROM server_slots ss
    JOIN plans p ON p.id = ss.plan_id
    WHERE ss.user_id = ? AND ss.status = 'pending'
");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
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
.btn-server { width:48%; margin-top:5px;}
.server-actions { display:flex; justify-content:space-between; margin-top:8px;}
.card-header-plan { font-weight:bold; text-align:center; background-color:#00c8ff; color:#121212; padding:8px; border-radius:10px; margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;}
.slot-count { text-align:center; font-weight:bold; margin-bottom:8px;}
.btn-slot { width:100%; }
</style>
<?php include('includes/header.php'); ?>
<div class="container my-5">
<h2 class="mb-4 text-center display-5 fw-bold">🎮 I tuoi server</h2>

<!-- Slots disponibili -->
<div id="slots-container" class="row mb-4">
<?php foreach ($slots as $slot): ?>
<div class="col-md-4 mb-3 card-slot" data-plan-code="<?= htmlspecialchars($slot['plan_code']) ?>">
    <div class="card shadow p-3 rounded card-server">
        <div class="card-header-plan"><?= htmlspecialchars($slot['plan_code']) ?></div>
        <p class="slot-count">1 slot disponibile</p>
        <a href="javascript:void(0);" onclick="createServerSlot('<?= htmlspecialchars($slot['plan_code']) ?>', this)" class="btn btn-success btn-slot">Crea Server</a>
    </div>
</div>
<?php endforeach; ?>
</div>

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

<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';
const creatingServers = {}; 

function createServerSlot(planCode, btnElement) {
    if (creatingServers[planCode]) return;
    creatingServers[planCode] = true;
    btnElement.disabled = true;
    const originalText = btnElement.textContent;
    btnElement.textContent = 'Creazione in corso...';

    let serverName = prompt("Inserisci il nome del tuo server:");
    if (!serverName) {
        btnElement.disabled = false;
        btnElement.textContent = originalText;
        creatingServers[planCode] = false;
        return;
    }

    fetch(`new_server.php?plan_code=${encodeURIComponent(planCode)}&server_name=${encodeURIComponent(serverName)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("Server creato con successo! VMID: " + data.vmid + " IP: " + data.ip);
                addServerCard(data.server_id, serverName, data.vmid, data.ip);
                btnElement.closest('.card-slot').remove(); // rimuove lo slot usato
            } else {
                alert("Errore: " + data.message);
            }
        })
        .catch(err => console.error(err))
        .finally(() => {
            creatingServers[planCode] = false;
        });
}

function addServerCard(serverId, serverName, vmid, ip) {
    const serverList = document.getElementById('server-list');
    if (!serverList) return;

    const div = document.createElement('div');
    div.className = 'col-md-4 mb-3';
    div.id = 'server-' + serverId;
    div.innerHTML = `
        <div class="card card-server p-3 shadow">
            <div class="server-name">${serverName}</div>
            <div class="server-ip">VMID: ${vmid} | IP: ${ip}</div>
            <span id="status-${serverId}" class="status-badge status-stopped">Spento</span>
            <div class="server-actions mt-2">
                <button id="start-${serverId}" class="btn btn-success btn-server" onclick="startServer(${serverId})">Avvia</button>
                <button id="stop-${serverId}" class="btn btn-danger btn-server" onclick="stopServer(${serverId})" disabled>Ferma</button>
            </div>
            <div class="server-actions mt-2">
                <a href="manage_server.php?id=${serverId}" class="btn btn-primary btn-server">Gestisci</a>
                <button class="btn btn-outline-danger btn-server" onclick="deleteServer(${serverId}, '${vmid}')">Elimina</button>
            </div>
        </div>
    `;
    serverList.prepend(div);
}

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
</script>

<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
