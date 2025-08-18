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
   <h2 class="text-4xl font-bold text-white text-center mb-12 animate-fade-in-down">🎮 I tuoi server</h2>

<div id="server-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
    <?php if (count($servers) === 0): ?>
        <div class="col-span-full text-center text-gray-500 py-12">
            <p class="text-lg">Nessun server attivo. Hai uno slot disponibile?</p>
            <p class="text-sm mt-2">Crea il tuo primo server per iniziare a giocare!</p>
        </div>
    <?php else: ?>
        <?php foreach ($servers as $server):
            $status = htmlspecialchars($server['status']);
            $statusClass = ($status === 'running') ? 'bg-green-500' : 'bg-red-500';
            $iconColor = ($status === 'running') ? 'text-green-500' : 'text-red-500';
            $iconPath = ($status === 'running') ? 'path/to/running_icon.svg' : 'path/to/stopped_icon.svg'; // Placeholder per icone SVG
        ?>
            <div id="server-<?= $server['id'] ?>" class="bg-gray-800 backdrop-blur-md bg-opacity-40 border border-gray-700 rounded-3xl p-6 shadow-xl transform transition-all duration-300 hover:scale-105 hover:border-blue-500">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="bg-gray-700 rounded-full p-3 <?= $iconColor ?>">
                        <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM13 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2zM13 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-white"><?= htmlspecialchars($server['name']) ?></h3>
                        <p class="text-sm text-gray-400">VMID: <?= $server['proxmox_containerId'] ?? 'N/A' ?> | IP: <?= $server['ip'] ?? 'N/A' ?></p>
                    </div>
                </div>
                
                <div class="flex items-center justify-between mb-4">
                    <span id="status-<?= $server['id'] ?>" class="px-4 py-1 rounded-full text-sm font-bold text-white <?= $statusClass ?> shadow-md">
                        <?= ucfirst($status) ?>
                    </span>
                    <a href="manage_server.php?id=<?= $server['id'] ?>" class="text-blue-400 hover:text-blue-300 text-sm font-medium">
                        Gestisci
                    </a>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <button id="start-<?= $server['id'] ?>" 
                            class="py-2 rounded-xl text-sm font-semibold transition-colors duration-200 
                            <?= ($status === 'running') ? 'bg-gray-600 text-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700 text-white' ?>" 
                            onclick="startServer(<?= $server['id'] ?>)" 
                            <?= ($status === 'running') ? 'disabled' : '' ?>>
                        Avvia
                    </button>
                    <button id="stop-<?= $server['id'] ?>" 
                            class="py-2 rounded-xl text-sm font-semibold transition-colors duration-200 
                            <?= ($status === 'stopped') ? 'bg-gray-600 text-gray-400 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700 text-white' ?>" 
                            onclick="stopServer(<?= $server['id'] ?>)" 
                            <?= ($status === 'stopped') ? 'disabled' : '' ?>>
                        Ferma
                    </button>
                    <button onclick="deleteServer(<?= $server['id'] ?>, '<?= $server['proxmox_containerId'] ?>')"
                            class="col-span-2 py-2 rounded-xl text-sm font-semibold bg-gray-700 hover:bg-red-500 text-white transition-colors duration-300">
                        Elimina
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
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

<div class="container mx-auto px-4 mt-16">
    <h2 class="text-4xl font-bold text-white text-center mb-12 animate-fade-in-down">🚀 I tuoi slot disponibili</h2>

    <?php if (count($serverSlots) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach($serverSlots as $slot): ?>
                <div class="bg-gray-800 backdrop-blur-md bg-opacity-40 border border-gray-700 rounded-3xl p-6 shadow-xl transform transition-all duration-300 hover:scale-105 hover:border-purple-500 relative overflow-hidden">
                    <div class="absolute inset-0 bg-gradient-to-br from-purple-600/20 via-transparent to-blue-600/20 transform -skew-y-6"></div>

                    <div class="relative z-10 flex flex-col h-full">
                        <h3 class="text-2xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500 mb-2">
                            <?= htmlspecialchars($slot['name']) ?>
                        </h3>
                        <p class="text-lg text-gray-400 mb-4">Slot Disponibile</p>

                        <div class="mt-auto pt-4 border-t border-gray-700">
                            <p class="text-4xl font-extrabold my-2 text-white">
                                €<?= htmlspecialchars($slot['price_eur']) ?>
                                <span class="text-base font-medium text-gray-500">/mese</span>
                            </p>
                            
                            <a href="new_server.php?plan_code=<?= $slot['plan_code'] ?>&slot_id=<?= $slot['id'] ?>"
                               class="mt-6 w-full inline-block text-center py-3 px-6 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                                Crea Server
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center text-gray-500 py-24">
            <p class="text-xl">Nessuno slot disponibile 😔</p>
            <p class="mt-4 text-base">Acquista un nuovo piano per iniziare a costruire il tuo server!</p>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.tailwindcss.com"></script>
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
    container.innerHTML = ''; // Svuota il contenitore esistente

    if (slots.length === 0) {
        container.innerHTML = `
            <div class="col-span-full text-center text-gray-500 py-24">
                <p class="text-xl">Nessuno slot disponibile 😔</p>
                <p class="mt-4 text-base">Acquista un nuovo piano per iniziare a costruire il tuo server!</p>
            </div>
        `;
        return;
    }

    const cardsHtml = slots.map(slot => `
        <div class="bg-gray-800 rounded-3xl shadow-2xl p-8 transform transition-all duration-500 hover:scale-105 hover:shadow-cyan-500/50 relative overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-purple-600/20 via-transparent to-blue-600/20 transform -skew-y-6"></div>
            
            <div class="relative z-10 flex flex-col h-full">
                <h3 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500 mb-2">
                    ${slot.name}
                </h3>
                <p class="text-lg text-gray-400 mb-4">Piano Premium</p>

                <div class="mt-auto pt-4 border-t border-gray-700">
                    <p class="text-4xl font-extrabold my-2">
                        €${slot.price_eur}
                        <span class="text-base font-medium text-gray-500">/mese</span>
                    </p>
                    
                    <a href="new_server.php?plan_code=${slot.plan_code}&slot_id=${slot.id}"
                       class="mt-6 w-full inline-block text-center py-3 px-6 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                        Crea Server
                    </a>
                </div>
            </div>
        </div>
    `).join('');

    container.innerHTML = `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">${cardsHtml}</div>`;
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