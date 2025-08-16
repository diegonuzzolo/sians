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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .card-server {
            background: linear-gradient(135deg, #1f1f1f, #2c2c2c);
            border-radius: 20px;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.3s;
            position: relative;
        }

        .card-server:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 200, 255, 0.4);
        }

        .server-name {
            color: #00c8ff;
            font-weight: bold;
            font-size: 1.3rem;
            margin-bottom: 0.3rem;
        }

        .server-ip {
            color: #a0a0a0;
            font-size: 0.9rem;
            margin-bottom: 0.7rem;
        }

        .status-badge {
            padding: 0.4em 0.8em;
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

        .btn-server {
            width: 48%;
            margin-top: 5px;
        }

        .server-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }

        .card-header-plan {
            font-weight: bold;
            text-align: center;
            background-color: #00c8ff;
            color: #121212;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .slot-count {
            text-align: center;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .btn-slot {
            width: 100%;
        }
    </style>
    <?php include('includes/header.php'); ?>
    <div class="container my-5">

        <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 I tuoi server</h2>

        <!-- Slots disponibili -->
        <div class="row mb-4">
            <?php
            $stmt = $pdo->prepare("SELECT * FROM plans WHERE active=1 ORDER BY ram_gb ASC");
            $stmt->execute();
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($plans as $plan):
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE user_id=? AND plan_id=?");
                $stmt2->execute([$_SESSION['user_id'], $plan['id']]);
                $totalSlots = (int) $stmt2->fetchColumn();

                $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE user_id=? AND plan_id=?");
                $stmt3->execute([$_SESSION['user_id'], $plan['id']]);
                $usedSlots = (int) $stmt3->fetchColumn();

                $availableSlots = max(0, $totalSlots - $usedSlots);

                // Mostra solo se ci sono slot disponibili
                if ($availableSlots <= 0)
                    continue;
                ?>
                <div class="col-md-4 mb-3">
                    <div class="card shadow p-3 rounded card-server">
                        <div class="card-header-plan"><?= htmlspecialchars($plan['name']) ?></div>
                        <p class="slot-count" data-plan-code="<?= htmlspecialchars($plan['code']) ?>">
                            <?= $availableSlots ?> slot disponibili
                        </p>
                        <a href="javascript:void(0);" onclick="createServerSlot('<?= htmlspecialchars($plan['code']) ?>')"
                            class="btn btn-success btn-slot" data-plan-code="<?= htmlspecialchars($plan['code']) ?>">
                            Crea Server
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Lista server -->
        <div id="server-list" class="row">
            <?php
            $stmt = $pdo->prepare("SELECT * FROM servers WHERE user_id=? ORDER BY created_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($servers as $server):
                $statusClass = ($server['status'] === 'running') ? 'status-running' : 'status-stopped';
                $container = $pdo->prepare("SELECT * FROM containers WHERE server_id=? LIMIT 1");
                $container->execute([$server['id']]);
                $container = $container->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="col-md-4 mb-3">
                    <div class="card card-server p-3 shadow">
                        <div class="server-name"><?= htmlspecialchars($server['name']) ?></div>
                        <div class="server-ip">VMID: <?= $container['proxmox_containerId'] ?? 'N/A' ?> | IP:
                            <?= $container['ip'] ?? 'N/A' ?>
                        </div>
                        <span id="status-<?= $server['id'] ?>" class="status-badge <?= $statusClass ?>">
                            <?= ucfirst($server['status']) ?>
                        </span>

                        <div class="server-actions">
                            <button id="start-<?= $server['id'] ?>" class="btn btn-success btn-server"
                                onclick="startServer(<?= $server['id'] ?>)" <?= $server['status'] === 'running' ? 'disabled' : '' ?>>
                                Avvia
                            </button>
                            <button id="stop-<?= $server['id'] ?>" class="btn btn-danger btn-server"
                                onclick="stopServer(<?= $server['id'] ?>)" <?= $server['status'] === 'stopped' ? 'disabled' : '' ?>>
                                Ferma
                            </button>
                        </div>

                        <div class="server-actions mt-2">
                            <a href="manage_server.php?id=<?= $server['id'] ?>"
                                class="btn btn-primary btn-server">Gestisci</a>
                            <a href="delete_server.php?vmid=<?= $container['proxmox_containerId'] ?>"
                                class="btn btn-outline-danger btn-server"
                                onclick="return confirm('Sei sicuro di voler eliminare questo server?');">
                                Elimina
                            </a>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';

        function createServerSlot(planCode) {
            let serverName = prompt("Inserisci il nome del tuo server:");
            if (!serverName) return;

            fetch(`new_server.php?plan_code=${encodeURIComponent(planCode)}&server_name=${encodeURIComponent(serverName)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert("Server creato con successo! VMID: " + data.vmid + " IP: " + data.ip);

                        // Aggiorna slot
                        const slotSpan = document.querySelector(`.slot-count[data-plan-code="${planCode}"]`);
                        if (slotSpan) {
                            let current = parseInt(slotSpan.textContent) || 0;
                            current = Math.max(0, current - 1);
                            slotSpan.textContent = current + " slot disponibili";
                        }

                        // Aggiungi server dinamicamente alla lista frontend
                        const serverList = document.getElementById('server-list'); // Assicurati di avere un container con questo ID
                        if (serverList) {
                            const div = document.createElement('div');
                            div.className = 'server-item card mb-3 p-3 shadow rounded';
                            div.innerHTML = `
                        <h5>${serverName}</h5>
                        <p>VMID: ${data.vmid} | IP: ${data.ip}</p>
                        <p>Status: <span id="status-${data.server_id}" class="status-badge status-stopped">Spento</span></p>
                        <button id="start-${data.server_id}" class="btn btn-success me-2" onclick="startServer(${data.server_id})">Avvia</button>
                        <button id="stop-${data.server_id}" class="btn btn-danger" onclick="stopServer(${data.server_id})" disabled>Ferma</button>
                    `;
                            serverList.prepend(div);
                        }

                    } else {
                        alert("Errore: " + data.message);
                    }
                })
                .catch(err => console.error('Errore creazione server:', err));
        }

        // Funzioni start/stop server
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
                })
                .catch(err => console.error('Errore comunicazione server:', err));
        }

        function startServer(serverId) { controlServer(serverId, 'start'); }
        function stopServer(serverId) { controlServer(serverId, 'stop'); }

        function updateButtons(serverId, status) {
            const startBtn = document.getElementById('start-' + serverId);
            const stopBtn = document.getElementById('stop-' + serverId);
            if (!startBtn || !stopBtn) return;
            if (status === 'running') {
                startBtn.disabled = true;
                stopBtn.disabled = false;
            } else {
                startBtn.disabled = false;
                stopBtn.disabled = true;
            }
        }

    </script>



    <?php include "includes/footer.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    </body>

</html>