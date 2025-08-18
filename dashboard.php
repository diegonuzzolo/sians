<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

// --- Server attivi ---
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type,
           c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.user_id=? AND s.status != 'deleted'
");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Slots disponibili (per render iniziale) ---
$slots = [];
if ($userId) {
    $stmt = $pdo->prepare("
        SELECT ss.*, 
               p.code AS plan_code, 
               p.name AS plan_name, 
               p.ram_gb, 
               p.cpu_cores
        FROM server_slots ss
        JOIN plans p ON p.id = ss.plan_id
        WHERE ss.user_id = ?
    ");
    $stmt->execute([$userId]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
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
.btn-server { width:48%; margin-top:5px;}
.server-actions { display:flex; justify-content:space-between; margin-top:8px;}
.card-header-plan { font-weight:bold; text-align:center; background-color:#00c8ff; color:#121212; padding:8px; border-radius:10px; margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;}
.btn-slot { width:100%; }
</style>
<?php include('includes/header.php'); ?>
<div class="container my-5">
    <h2 class="mb-4 text-center display-5 fw-bold">🎮 I tuoi server</h2>

    <!-- Slots disponibili -->
    <h4 class="mb-3">🟦 Slot disponibili</h4>
    <div id="slots-container" class="row mb-4">
        <?php if (!empty($slots)): ?>
            <?php foreach ($slots as $slot): ?>
                <div class="col-md-4 mb-3 card-slot" data-plan-code="<?= htmlspecialchars($slot['plan_code']) ?>">
                    <div class="card shadow-sm text-dark">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($slot['plan_name']) ?></h5>
                            <p>RAM: <?= htmlspecialchars($slot['ram_gb']) ?>GB | CPU: <?= htmlspecialchars($slot['cpu_cores']) ?> core</p>
                            <p>Status: <?= htmlspecialchars($slot['status']) ?></p>
                            <?php if ($slot['server_id']): ?>
                                <span class="badge bg-success">Server collegato: <?= htmlspecialchars($slot['server_id']) ?></span>
                            <?php else: ?>
                                <a href="javascript:void(0);" class="btn btn-success btn-slot" onclick="createServerSlot('<?= $slot['plan_code'] ?>', this)">Crea Server</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Nessuno slot disponibile.</p>
        <?php endif; ?>
    </div>

    <!-- Lista server -->
    <h4 class="mb-3">🟩 Server attivi</h4>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';
const creatingServers = {};

// --- Creazione server ---
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
            } else {
                alert("Errore: " + data.message);
            }
        })
        .catch(err => console.error(err))
        .finally(() => {
            creatingServers[planCode] = false;
            refreshSlots();
        });
}

// --- Aggiunge server alla lista ---
function addServerCard(serverId, serverName, vmid, ip) {
    const serverList = $("#server-list");
    const div = $(`
        <div class="col-md-4 mb-3" id="server-${serverId}">
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
        </div>
    `);
    serverList.prepend(div);
}

// --- Elimina server ---
function deleteServer(serverId, vmid) {
    if (!confirm('Sei sicuro di voler eliminare questo server?')) return;
    fetch(`delete_server.php?vmid=${vmid}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                $(`#server-${serverId}`).remove();
                refreshSlots();
            } else {
                alert("Errore eliminazione server: " + data.message);
            }
        }).catch(err => console.error(err));
}

// --- Start/Stop server ---
function controlServer(serverId, action) {
    $.post('ajax_server_control.php', {action, id: serverId, csrf_token: csrfToken}, function(data) {
        if (data.success && data.status) {
            const statusEl = $(`#status-${serverId}`);
            statusEl.text(data.status==='running'?'Attivo':'Spento')
                    .removeClass('status-running status-stopped')
                    .addClass(data.status==='running'?'status-running':'status-stopped');
            updateButtons(serverId, data.status);
        }
    }, 'json');
}
function startServer(id){ controlServer(id,'start'); }
function stopServer(id){ controlServer(id,'stop'); }
function updateButtons(id,status){
    $(`#start-${id}`).prop('disabled', status==='running');
    $(`#stop-${id}`).prop('disabled', status==='stopped');
}

// --- Refresh slots dinamico ---
function refreshSlots() {
    $.getJSON("ajax_check_slots.php", function(data){
        let container = $("#slots-container");
        container.empty();
        if (!data || Object.keys(data).length === 0) {
            container.append("<p>Nessuno slot disponibile.</p>");
            return;
        }
        Object.keys(data).forEach(planCode => {
            let available = data[planCode];
            let col = $(`<div class="col-md-4 mb-3 card-slot" data-plan-code="${planCode}"></div>`);
            let card = $(`
                <div class="card shadow-sm text-dark">
                    <div class="card-body">
                        <h5 class="card-title">Piano: ${planCode}</h5>
                        <p>Slots disponibili: ${available}</p>
                    </div>
                </div>
            `);
            if (available > 0) {
                let btn = $(`<a href="javascript:void(0);" class="btn btn-success btn-slot">Crea Server</a>`);
                btn.click(() => createServerSlot(planCode, btn[0]));
                card.find('.card-body').append(btn);
            } else {
                card.find('.card-body').append(`<span class="badge bg-danger">Nessuno slot disponibile</span>`);
            }
            col.append(card);
            container.append(col);
        });
    });
}

$(document).ready(function(){ refreshSlots(); });
</script>

<?php include "includes/footer.php"; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
