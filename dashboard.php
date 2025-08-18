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
        body {
            background-color: #121212;
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .card-server {
            background: linear-gradient(135deg, #1f1f1f, #2c2c2c);
            border-radius: 20px;
            padding: 15px;
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
            font-size: 1.2rem;
            margin-bottom: 0.3rem;
        }

        .server-ip {
            color: #a0a0a0;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .status-badge {
            padding: 0.3em 0.7em;
            border-radius: 12px;
            font-size: 0.8rem;
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

        .status-available {
            background-color: #ffc107;
            color: #000;
        }

        .btn-server {
            width: 48%;
            margin-top: 5px;
        }

        .server-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
        }


    </style>
    <?php include('includes/header.php'); ?>
    <div class="container my-5">
        <h2 class="mb-4 text-center display-5 fw-bold">🎮 I tuoi server</h2>

        <!-- Lista server -->
        <div id="server-list" class="row">
            <?php foreach ($servers as $server):
                $statusClass = ($server['status'] === 'running') ? 'status-running' : 'status-stopped';
            ?>
                <div class="col-md-4 mb-3" id="server-<?= $server['id'] ?>">
                    <div class="card card-server p-3 shadow">
                        <div class="server-name"><?= htmlspecialchars($server['name']) ?></div>
                        <div class="server-ip">VMID: <?= $server['proxmox_containerId'] ?? 'N/A' ?> | IP: <?= $server['ip'] ?? 'N/A' ?></div>
                        <span id="status-<?= $server['id'] ?>" class="status-badge <?= $statusClass ?>"><?= ucfirst($server['status']) ?></span>

                        <div class="server-actions">
                            <button id="start-<?= $server['id'] ?>" class="btn btn-success btn-server" onclick="startServer(<?= $server['id'] ?>)" <?= $server['status'] === 'running' ? 'disabled' : '' ?>>Avvia</button>
                            <button id="stop-<?= $server['id'] ?>" class="btn btn-danger btn-server" onclick="stopServer(<?= $server['id'] ?>)" <?= $server['status'] === 'stopped' ? 'disabled' : '' ?>>Ferma</button>
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
<?php
// Prelevo gli slot disponibili dal DB
$stmt = $pdo->prepare("SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code 
                       FROM server_slots ss 
                       JOIN plans p ON p.stripe_price_id = ss.plan_id 
                       WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'");
$stmt->execute([$userId]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-4">
    <?php if (count($slots) === 0): ?>
        <div class="col-span-full text-center text-gray-500 py-12">
            <p class="text-lg">Nessuno slot disponibile 😔</p>
            <p class="text-sm mt-2">Acquista un nuovo piano per creare un server!</p>
        </div>
    <?php else: ?>
        <?php foreach($slots as $slot): ?>
            <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
                <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-t-xl h-24 flex items-center justify-center p-4">
                    <h2 class="text-2xl font-bold text-white tracking-wide"><?= htmlspecialchars($slot['name']) ?></h2>
                </div>

                <div class="p-6">
                    <div class="text-center">
                        <p class="text-gray-600 text-sm">Piano di Abbonamento</p>
                        <p class="mt-1 text-4xl font-extrabold text-gray-900">
                            €<?= htmlspecialchars($slot['price_eur']) ?>
                            <span class="text-base font-normal text-gray-500">/mese</span>
                        </p>
                    </div>
                    
                    <div class="mt-6 border-t pt-6">
                        <p class="text-center text-gray-700">Il tuo slot server è pronto per la configurazione.</p>
                        <button 
                            class="mt-4 w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-full transition-colors duration-300 ease-in-out transform hover:scale-105"
                            data-slot-id="<?= $slot['id'] ?>"
                            data-plan-code="<?= $slot['plan_code'] ?>"
                            onclick="window.location.href='new_server.php?plan_code=<?= $slot['plan_code'] ?>&slot_id=<?= $slot['id'] ?>'">
                            🚀 Crea Server Ora
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>





    <script>
        const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';

        // --- CONTROLLO SERVER (start/stop) ---
        function controlServer(serverId, action) {
            fetch('ajax_server_control.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
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
                .catch(err => console.error(err));
        }

        function startServer(serverId) {
            controlServer(serverId, 'start');
        }

        function stopServer(serverId) {
            controlServer(serverId, 'stop');
        }

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

        document.querySelectorAll('.create-server-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const slotId = btn.getAttribute('data-slot-id');
        const planCode = btn.getAttribute('data-plan-code');
        btn.disabled = true;
        btn.textContent = 'Creazione in corso...';

        fetch(`new_server.php?slot_id=${slotId}&plan_code=${planCode}`)
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    btn.textContent = 'Server Creato!';
                    btn.classList.remove('bg-white', 'text-indigo-600');
                    btn.classList.add('bg-green-500', 'text-white');
                } else {
                    btn.textContent = 'Errore, riprova';
                    btn.disabled = false;
                    alert(data.message);
                }
            })
            .catch(err => {
                btn.textContent = 'Errore, riprova';
                btn.disabled = false;
                console.error(err);
            });
    });
});

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

        function renderSlots(slots) {
    const container = document.getElementById('slot-list');
    container.innerHTML = ''; // Clear container

    if (slots.length === 0) {
        container.innerHTML = `
            <div class="text-center text-gray-500 py-12">
                <p class="text-lg">Nessuno slot disponibile 😔</p>
                <p class="text-sm mt-2">Acquista un nuovo piano per creare un server!</p>
            </div>
        `;
        return;
    }

    const cardsHtml = slots.map(slot => `
        <div class="bg-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
            <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-t-xl h-24 flex items-center justify-center p-4">
                <h2 class="text-2xl font-bold text-white tracking-wide">${slot.name}</h2>
            </div>
            <div class="p-6">
                <div class="text-center">
                    <p class="text-gray-600 text-sm">Piano di Abbonamento</p>
                    <p class="mt-1 text-4xl font-extrabold text-gray-900">
                        €${slot.price_eur}
                        <span class="text-base font-normal text-gray-500">/mese</span>
                    </p>
                </div>
                
                <div class="mt-6 border-t pt-6">
                    <p class="text-center text-gray-700">Il tuo slot server è pronto per la configurazione.</p>
                    <a href="new_server.php?plan_code=${slot.plan_code}&slot_id=${slot.id}" 
                        class="mt-4 w-full block text-center bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-full transition-colors duration-300 ease-in-out transform hover:scale-105">
                        🚀 Crea Server Ora
                    </a>
                </div>
            </div>
        </div>
    `).join('');

    container.innerHTML = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-4">${cardsHtml}</div>`;
}

        function refreshSlots() {
            fetch('ajax_get_slots.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        renderSlots(data.slots);
                    }
                })
                .catch(err => console.error("Errore caricamento slots:", err));
        }

        // Prima chiamata subito al load
        refreshSlots();
        // Poi aggiorna ogni 10 secondi
        setInterval(refreshSlots, 10000);
    </script>

    <?php include "includes/footer.php"; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>

</html>