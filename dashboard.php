<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// Server attivi
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Subscription e slot disponibili
$stmt = $pdo->prepare("
    SELECT sub.*, p.code AS plan_code, p.ram_gb, p.cpu_cores
    FROM subscriptions sub
    JOIN plans p ON p.id = sub.plan_id
    WHERE sub.user_id=?
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mappa piano -> subscription
$subsByPlan = [];
foreach ($subscriptions as $sub) {
    $subsByPlan[$sub['plan_code']] = $sub;
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
<div class="row mb-4">
<?php
$stmt = $pdo->prepare("SELECT * FROM plans WHERE active=1 ORDER BY ram_gb ASC");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plans as $plan):
    // Controlla quanti server attivi ha l'utente per questo piano
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id=? AND plan_id=? AND status!='deleted'");
    $stmt2->execute([$userId, $plan['id']]);
    $usedSlots = (int)$stmt2->fetchColumn();

    // Controlla quante subscription ha l'utente per questo piano
    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id=? AND plan_id=?");
    $stmt3->execute([$userId, $plan['id']]);
    $totalSlots = (int)$stmt3->fetchColumn();

    $availableSlots = max(0, $totalSlots - $usedSlots);
    if ($availableSlots <= 0) continue; // mostra solo slot disponibili
?>
<div class="col-md-4 mb-3">
    <div class="card shadow p-3 rounded card-server">
        <div class="card-header-plan"><?= htmlspecialchars($plan['name']) ?></div>
        <p class="slot-count" data-plan-code="<?= htmlspecialchars($plan['code']) ?>"><?= $availableSlots ?> slot disponibili</p>
        <a href="javascript:void(0);" onclick="createServerSlot('<?= htmlspecialchars($plan['code']) ?>', this)" class="btn btn-success btn-slot" data-plan-code="<?= htmlspecialchars($plan['code']) ?>">Crea Server</a>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Lista server -->
<div id="server-list" class="row">
<?php foreach ($servers as $server):
    $statusClass = ($server['status']==='running')?'status-running':'status-stopped';
?>
<div class="col-md-4 mb-3">
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
            <a href="delete_server.php?vmid=<?= $server['proxmox_containerId'] ?>" class="btn btn-outline-danger btn-server" onclick="return confirm('Sei sicuro di voler eliminare questo server?');">Elimina</a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';

function createServerSlot(planCode, btnElement) {
    if (!btnElement) return;
    btnElement.disabled = true;
    const originalText = btnElement.textContent;
    btnElement.textContent = 'Creazione in corso...';

    let serverName = prompt("Inserisci il nome del tuo server:");
    if (!serverName) {
        btnElement.disabled = false;
        btnElement.textContent = originalText;
        return;
    }

    fetch(`new_server.php?plan_code=${encodeURIComponent(planCode)}&server_name=${encodeURIComponent(serverName)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert("Server creato con successo! VMID: " + data.vmid + " IP: " + data.ip);
            const slotSpan = document.querySelector(`.slot-count[data-plan-code="${planCode}"]`);
            if (slotSpan) slotSpan.textContent = "0 slot disponibili";

            const serverList = document.getElementById('server-list');
            if (serverList) {
                const div = document.createElement('div');
                div.className = 'col-md-4 mb-3';
                div.innerHTML = `
                    <div class="card card-server p-3 shadow">
                        <div class="server-name">${serverName}</div>
                        <div class="server-ip">VMID: ${data.vmid} | IP: ${data.ip}</div>
                        <span id="status-${data.server_id}" class="status-badge status-stopped">Spento</span>
                        <div class="server-actions mt-2">
                            <button id="start-${data.server_id}" class="btn btn-success btn-server" onclick="startServer(${data.server_id})">Avvia</button>
                            <button id="stop-${data.server_id}" class="btn btn-danger btn-server" onclick="stopServer(${data.server_id})" disabled>Ferma</button>
                        </div>
                        <div class="server-actions mt-2">
                            <a href="manage_server.php?id=${data.server_id}" class="btn btn-primary btn-server">Gestisci</a>
                            <a href="delete_server.php?vmid=${data.vmid}" class="btn btn-outline-danger btn-server" onclick="return confirm('Sei sicuro di voler eliminare questo server?');">Elimina</a>
                        </div>
                    </div>
                `;
                serverList.prepend(div);
            }
        } else {
            alert("Errore: " + data.message);
        }
    })
    .catch(err => { console.error(err); alert("Errore durante la creazione del server."); })
    .finally(() => { btnElement.disabled = false; btnElement.textContent = originalText; });
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
