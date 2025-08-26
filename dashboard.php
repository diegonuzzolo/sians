<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'includes/csrf.php';

// Definiamo l'IP del VPS per costruire l'indirizzo pubblico
define('VPS_IP', '195.231.66.137');

$userId = $_SESSION['user_id'] ?? 0;

// Server attivi - AGGIUNTO s.public_port alla query
$stmt = $pdo->prepare("
    SELECT s.id, s.name, s.status, s.type, c.ip AS ip, c.ram_mb, c.proxmox_containerId, s.plan_id, s.public_port
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
<?php
// Messaggi da mostrare all'utente
$errorMessage = $_GET['error'] ?? '';
$successMessage = $_GET['success'] ?? '';
$warningMessage = $_GET['warning'] ?? '';

// Personalizzazione dei messaggi di errore SQL
if (strpos($errorMessage, '1062 Duplicate entry') !== false) {
    $errorMessage = "Nome server già esistente. Scegli un nome diverso.";
}
?>

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
                    $statusClass = ($status === 'running') ? 'bg-green-500' : ($status === 'creating' ? 'bg-yellow-500' : 'bg-red-500');
                    $iconColor = ($status === 'running') ? 'text-green-400' : ($status === 'creating' ? 'text-yellow-400' : 'text-red-400');

                    // MODIFICATO: Costruiamo l'indirizzo pubblico con IP e PORTA
                    $publicPort = htmlspecialchars($server['public_port']);
                    $domainAddress = htmlspecialchars($server['name']) . '.sians.it'; // Manteniamo il dominio per riferimento
                ?>
                    <div id="server-<?= $server['id'] ?>" class="bg-gray-800 backdrop-blur-md bg-opacity-40 border border-gray-700 rounded-3xl p-6 shadow-xl flex flex-col h-full transition-all duration-300 hover:scale-105 hover:border-blue-500">

                        <div class="flex items-center space-x-4 mb-4">
                            <div id="icon-container-<?= $server['id'] ?>" class="bg-gray-700 rounded-full p-3 <?= $iconColor ?>">
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM13 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2zM13 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2h-2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold text-white"><?= htmlspecialchars($server['name']) ?></h3>
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

                        <div class="mt-auto">
                            <!-- AGGIUNTO: Mostra anche l'indirizzo con dominio -->
                           <label class="text-xs font-semibold text-gray-400 mt-2">Indirizzo Dominio</label>
                           <div class="flex items-center gap-2 mt-1 bg-gray-900 rounded-lg p-2">
                               <input type="text" readonly value="<?= $domainAddress ?>" class="flex-grow bg-transparent text-white text-sm border-none p-1 focus:ring-0">
                               <button onclick="copyToClipboard('<?= $domainAddress ?>', this)"
                                   class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 rounded-md text-xs transition-colors duration-200">
                                   Copia
                               </button>
                           </div>
                            <!-- Mostra la PORTA -->
                            <label class="text-xs font-semibold text-gray-400">PORTA</label>
                            <div class="flex items-center gap-2 mt-1 bg-gray-900 rounded-lg p-2">
                                <input type="text" readonly value="<?= $publicPort ?>" class="flex-grow bg-transparent text-white text-sm border-none p-1 focus:ring-0">
                                <button onclick="copyToClipboard('<?= $publicPort ?>', this)"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 rounded-md text-xs transition-colors duration-200">
                                    Copia
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <button id="start-<?= $server['id'] ?>"
                                class="py-2 rounded-xl text-sm font-semibold transition-colors duration-200 
                                <?= ($status === 'running' || $status === 'creating') ? 'bg-gray-600 text-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700 text-white' ?>"
                                onclick="startServer(<?= $server['id'] ?>)"
                                <?= ($status === 'running' || $status === 'creating') ? 'disabled' : '' ?>>
                                Avvia
                            </button>
                            <button id="stop-<?= $server['id'] ?>"
                                class="py-2 rounded-xl text-sm font-semibold transition-colors duration-200 
                                <?= ($status === 'stopped' || $status === 'creating') ? 'bg-gray-600 text-gray-400 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700 text-white' ?>"
                                onclick="stopServer(<?= $server['id'] ?>)"
                                <?= ($status === 'stopped' || $status === 'creating') ? 'disabled' : '' ?>>
                                Ferma
                            </button>
                            <button onclick="deleteServer('<?= $server['proxmox_containerId'] ?>')"
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
                <?php foreach ($serverSlots as $slot): ?>
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

                                <a href="#" onclick="promptAndCreateServer('<?= $slot['plan_code'] ?>', '<?= $slot['id'] ?>', this)"
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
<?php
$flashMessage = $_SESSION['flash_message'] ?? null;
unset($_SESSION['flash_message']); // Lo rimuoviamo subito dopo averlo letto
?>
<?php if ($flashMessage): ?>
    <div id="flash-message"
         class="mb-6 p-4 rounded-lg text-white text-center font-semibold
                <?= $flashMessage['type'] === 'error' ? 'bg-red-600' : ($flashMessage['type'] === 'success' ? 'bg-green-600' : 'bg-yellow-500') ?>">
        <?= htmlspecialchars($flashMessage['text']) ?>
    </div>
<?php endif; ?>




<?php include "includes/footer.php"; ?>

<script>
    const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';
 
    
    document.addEventListener('DOMContentLoaded', function() {
    const flash = document.getElementById('flash-message');
    if (flash) {
        setTimeout(() => {
            flash.style.transition = "opacity 0.5s ease";
            flash.style.opacity = 0;
            setTimeout(() => flash.remove(), 500); // Rimuove il div completamente
        }, 5000); // 5 secondi
    }
});


    // --- CONTROLLO SERVER (start/stop) ---
    // --- CONTROLLO SERVER (start/stop) ---
    function controlServer(serverId, action) {
        fetch('ajax_server_control.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=${encodeURIComponent(action)}&id=${serverId}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.status) {
                    // Se la chiamata ha successo, aggiorniamo dinamicamente l'interfaccia
                    const statusEl = document.getElementById('status-' + serverId);
                    if (statusEl) {
                        statusEl.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);

                        // Rimuovi le vecchie classi di colore e aggiungi quella nuova
                        statusEl.classList.remove('bg-green-500', 'bg-red-500', 'bg-yellow-500');
                        if (data.status === 'running') {
                            statusEl.classList.add('bg-green-500');
                        } else if (data.status === 'creating') {
                            statusEl.classList.add('bg-yellow-500');
                        } else {
                            statusEl.classList.add('bg-red-500');
                        }
                    }

                    // Aggiorna lo stato dei bottoni
                    updateButtons(serverId, data.status);

                    // **NUOVA PARTE: Aggiorna il colore dell'icona**
                    updateIconColor(serverId, data.status);
                } else {
                    // Se c'è un errore, mostralo
                    alert('Errore: ' + (data.message || 'Si è verificato un problema.'));
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

    // **NUOVA FUNZIONE PER AGGIORNARE L'ICONA**
    function updateIconColor(serverId, status) {
        const iconContainer = document.getElementById('icon-container-' + serverId);
        if (!iconContainer) return;

        // Rimuovi tutte le possibili classi di colore precedenti
        iconContainer.classList.remove('text-green-400', 'text-red-400', 'text-yellow-400');

        // Aggiungi la classe di colore corretta in base al nuovo stato
        if (status === 'running') {
            iconContainer.classList.add('text-green-400');
        } else if (status === 'creating') {
            iconContainer.classList.add('text-yellow-400');
        } else { // per 'stopped', 'error', ecc.
            iconContainer.classList.add('text-red-400');
        }
    }


    // --- AGGIORNA BOTTONI ---
    function updateButtons(serverId, status) {
        const startBtn = document.getElementById('start-' + serverId);
        const stopBtn = document.getElementById('stop-' + serverId);

        if (!startBtn || !stopBtn) return;

        const isRunning = status === 'running';
        const isStopped = status === 'stopped';
        const isBusy = !isRunning && !isStopped; // Es. 'creating', 'deleting'

        // Abilita/disabilita il bottone Avvia
        startBtn.disabled = isRunning || isBusy;
        startBtn.classList.toggle('bg-gray-600', isRunning || isBusy);
        startBtn.classList.toggle('text-gray-400', isRunning || isBusy);
        startBtn.classList.toggle('cursor-not-allowed', isRunning || isBusy);
        startBtn.classList.toggle('bg-green-600', isStopped);
        startBtn.classList.toggle('hover:bg-green-700', isStopped);
        startBtn.classList.toggle('text-white', isStopped);

        // Abilita/disabilita il bottone Ferma
        stopBtn.disabled = isStopped || isBusy;
        stopBtn.classList.toggle('bg-gray-600', isStopped || isBusy);
        stopBtn.classList.toggle('text-gray-400', isStopped || isBusy);
        stopBtn.classList.toggle('cursor-not-allowed', isStopped || isBusy);
        stopBtn.classList.toggle('bg-red-600', isRunning);
        stopBtn.classList.toggle('hover:bg-red-700', isRunning);
        stopBtn.classList.toggle('text-white', isRunning);
    }

    // --- ELIMINA SERVER ---
    function deleteServer(vmid) {
        if (!confirm('Sei sicuro di voler eliminare questo server? L\'azione è irreversibile.')) {
            return;
        }

        // Crea un oggetto FormData per inviare i dati come POST
        const formData = new FormData();
        formData.append('vmid', vmid);
        formData.append('csrf_token', csrfToken); // Invia il token CSRF per sicurezza

        fetch('delete_server.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    // Se la risposta non è "OK" (es. errore 500), leggi l'errore
                    return response.text().then(text => {
                        throw new Error(text)
                    });
                }
                return response.text();
            })
            .then(data => {
                alert(data); // Mostra il messaggio di successo
                window.location.reload(); // Ricarica la pagina per aggiornare la lista
            })
            .catch(err => {
                // Mostra il messaggio di errore restituito dallo script PHP
                alert("Errore durante l'eliminazione: " + err.message);
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

    function promptAndCreateServer(planCode, slotId, buttonElement) {
        let serverName = prompt("Inserisci un nome per il tuo server (es. 'MioMondo'):");

        if (serverName === null) return; // L'utente ha cliccato "Annulla"
        serverName = serverName.trim();

        if (serverName === "") {
            alert("Il nome del server non può essere vuoto.");
            return;
        }
        if (!/^[a-zA-Z0-9-]{3,20}$/.test(serverName)) {
            alert("Nome non valido. Usa solo lettere, numeri e trattini, da 3 a 20 caratteri.");
            return;
        }

        // --- NUOVA PARTE: DISABILITA IL PULSANTE ---
        // Rimuovi le classi di colore e hover esistenti
        buttonElement.classList.remove('bg-gradient-to-r', 'from-blue-600', 'to-purple-600', 'hover:shadow-xl', 'hover:scale-105');
        // Aggiungi le classi per lo stato "disabilitato" e mostra un feedback
        buttonElement.classList.add('bg-gray-500', 'cursor-not-allowed', 'opacity-75');
        buttonElement.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-2"></i>Creazione in corso...`;

        // Blocca ulteriori click
        buttonElement.style.pointerEvents = 'none';
        // --- FINE NUOVA PARTE ---

        // Crea un form invisibile per inviare i dati con il metodo POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'new_server.php';

        const data = {
            plan_code: planCode,
            slot_id: slotId,
            server_name: serverName,
            csrf_token: csrfToken
        };

        for (const key in data) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = data[key];
            form.appendChild(input);
        }

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function copyToClipboard(text, buttonElement) {
        // Controlla se il browser supporta l'API per gli appunti (per sicurezza)
        if (!navigator.clipboard) {
            alert("La funzione di copia non è supportata da questo browser.");
            return;
        }

        // Prova a scrivere il testo negli appunti
        navigator.clipboard.writeText(text).then(function() {
            // Successo: Fornisci un feedback visivo all'utente
            const originalText = buttonElement.textContent;
            buttonElement.disabled = true;
            buttonElement.textContent = 'Copiato!';
            buttonElement.classList.add('bg-green-500', 'hover:bg-green-500'); // Aggiunge uno stile per indicare il successo

            // Dopo 2 secondi, ripristina il pulsante al suo stato originale
            setTimeout(function() {
                buttonElement.textContent = originalText;
                buttonElement.disabled = false;
                buttonElement.classList.remove('bg-green-500', 'hover:bg-green-500');
            }, 2000);

        }, function(err) {
            // Errore: Avvisa l'utente
            alert('Impossibile copiare l\'indirizzo.');
            console.error('Errore durante la copia:', err);
        });
    }
</script>
</body>

</html>
