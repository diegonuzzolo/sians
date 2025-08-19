<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

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

// Slot server disponibili
$stmtSlots = $pdo->prepare("
    SELECT ss.id, ss.status, p.name, p.price_eur, p.code as plan_code
    FROM server_slots ss
    JOIN plans p ON p.stripe_price_id = ss.plan_id
    WHERE ss.user_id = ? AND ss.server_id IS NULL AND ss.status = 'pending'
");
$stmtSlots->execute([$userId]);
$serverSlots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

// Includi l'header
include 'includes/header.php';
?>

<main class="flex-grow-1 container mx-auto px-4 py-12">

    <section class="mb-16">
        <h2 class="text-4xl font-bold text-white text-center mb-12 animate-fade-in-down">🎮 I tuoi server</h2>
        <div id="server-list" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php if (empty($servers)): ?>
                <div class="col-span-full text-center text-gray-500 py-12">
                    <p class="text-lg">Nessun server attivo. Hai uno slot disponibile?</p>
                    <p class="text-sm mt-2">Crea il tuo primo server per iniziare a giocare!</p>
                </div>
            <?php else: ?>
                <?php foreach ($servers as $server):
                    $status = htmlspecialchars($server['status']);
                    $statusClass = ($status === 'running') ? 'bg-green-500' : 'bg-red-500';
                    $iconColor = ($status === 'running') ? 'text-green-500' : 'text-red-500';
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
    </section>

    <hr class="my-16 border-gray-700">

    <section>
        <h2 class="text-4xl font-extrabold text-center mb-12 animate-fade-in-down">🚀 I tuoi slot disponibili</h2>
        <div id="slot-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php if (empty($serverSlots)): ?>
                <div class="col-span-full text-center text-gray-500 py-24">
                    <p class="mt-4 text-base">Acquista un nuovo piano per iniziare a costruire il tuo server!</p>
                </div>
            <?php else: ?>
                <?php foreach($serverSlots as $slot): ?>
                    <div class="bg-gray-800 rounded-3xl shadow-2xl p-8 transform transition-all duration-500 hover:scale-105 hover:shadow-cyan-500/50 relative overflow-hidden">
                        
                        <div class="absolute inset-0 bg-gradient-to-br from-purple-600/20 via-transparent to-blue-600/20 transform -skew-y-6"></div>

                        <div class="relative z-10 flex flex-col h-full">
                            <h3 class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500 mb-2">
                                <?= htmlspecialchars($slot['name']) ?>
                            </h3>
                            <p class="text-lg text-gray-400 mb-4">Piano Premium</p>

                            <div class="mt-auto pt-4 border-t border-gray-700">
                                <p class="text-4xl font-extrabold my-2">
                                    €<?= htmlspecialchars($slot['price_eur']) ?>
                                    <span class="text-base font-medium text-gray-500">/mese</span>
                                </p>
                                
                                <a href="#" onclick="promptAndCreateServer('<?= $slot['plan_code'] ?>', '<?= $slot['id'] ?>')"
                                   class="mt-6 w-full inline-block text-center py-3 px-6 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                                    Crea Server
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include "includes/footer.php"; ?>

<script>
    const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';


    // --- CONTROLLO SERVER (start/stop) ---
    function controlServer(serverId, action) {
        fetch('ajax_server_control.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${encodeURIComponent(action)}&id=${serverId}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.status) {
                    const statusEl = document.getElementById('status-' + serverId);
                    if (statusEl) {
                        statusEl.textContent = (data.status === 'running') ? 'Attivo' : 'Spento';
                        statusEl.className = `px-4 py-1 rounded-full text-sm font-bold text-white shadow-md ${data.status === 'running' ? 'bg-green-500' : 'bg-red-500'}`;
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

    // --- AGGIORNA BOTTONI ---
    function updateButtons(serverId, status) {
        const startBtn = document.getElementById('start-' + serverId);
        const stopBtn = document.getElementById('stop-' + serverId);
        
        if (!startBtn || !stopBtn) return;
        
        const isRunning = status === 'running';

        startBtn.disabled = isRunning;
        startBtn.classList.toggle('bg-gray-600', isRunning);
        startBtn.classList.toggle('text-gray-400', isRunning);
        startBtn.classList.toggle('cursor-not-allowed', isRunning);
        startBtn.classList.toggle('bg-green-600', !isRunning);
        startBtn.classList.toggle('hover:bg-green-700', !isRunning);
        startBtn.classList.toggle('text-white', !isRunning);

        stopBtn.disabled = !isRunning;
        stopBtn.classList.toggle('bg-gray-600', !isRunning);
        stopBtn.classList.toggle('text-gray-400', !isRunning);
        stopBtn.classList.toggle('cursor-not-allowed', !isRunning);
        stopBtn.classList.toggle('bg-red-600', isRunning);
        stopBtn.classList.toggle('hover:bg-red-700', isRunning);
        stopBtn.classList.toggle('text-white', isRunning);
    }

    // --- ELIMINA SERVER ---
    function deleteServer(serverId, vmid) {
        if (!confirm('Sei sicuro di voler eliminare questo server? Questa azione è irreversibile.')) return;
        fetch(`delete_server.php?vmid=${vmid}`)
            .then(res => res.text())
            .then(data => {
                if (data.includes("Server eliminato correttamente")) {
                    // Ricarica la pagina per aggiornare lo stato
                    window.location.reload();
                } else {
                    alert("Errore nell'eliminazione del server: " + data);
                }
            })
            .catch(err => {
                alert("Errore di rete durante l'eliminazione.");
                console.error(err);
            });
    }
       document.addEventListener('DOMContentLoaded', function() {
        const slotsContainer = document.querySelector('#slots-container');



        // Funzione per gestire la creazione del server
        // Questa parte gestisce il click e aggiorna l'UI immediatamente
        document.querySelectorAll('.create-server-btn').forEach(button => {
            button.addEventListener('click', function(event) {
                const slotId = this.dataset.slotId;
                const slotCard = document.querySelector(`.slot-card[data-slot-id="${slotId}"]`);
                
                // Aggiorna l'UI per mostrare lo stato di attesa
                if (slotCard) {
                    slotCard.classList.add('pending');
                    slotCard.innerHTML = `
                        <div class="text-center p-4">
                            <i class="bi bi-gear-wide-connected text-6xl animate-spin text-blue-500"></i>
                            <p class="mt-4 text-white font-semibold">Creazione server in corso...</p>
                        </div>
                    `;
                }

                // Qui dovresti avviare la chiamata AJAX per la creazione del server (new_server.php)
                // e gestire la risposta
            });
        });
    });
// Funzione per chiedere il nome del server e reindirizzare
    function promptAndCreateServer(planCode, slotId) {
        let serverName = prompt("Inserisci un nome per il tuo server:");
        
        if (serverName === null) return;
        serverName = serverName.trim();
        if (serverName === "") {
            alert("Il nome del server non può essere vuoto.");
            return;
        }

        const btn = document.querySelector(`[data-slot-id="${slotId}"]`);
        if (btn) {
            // Blocca il pulsante e cambia il testo
            btn.classList.add('pointer-events-none', 'bg-gray-600', 'text-gray-400');
            btn.textContent = "Creazione in corso…";
        }

        // Reindirizza alla pagina di creazione con il nome
        window.location.href = `new_server.php?plan_code=${encodeURIComponent(planCode)}&slot_id=${encodeURIComponent(slotId)}&server_name=${encodeURIComponent(serverName)}`;
    }

</script>
</body>
</html>