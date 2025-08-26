<?php
include("auth_check.php");
require 'config/config.php';

// Genera CSRF token se non presente
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.*, c.proxmox_containerId
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id=? AND s.user_id=?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    // Reindirizza l'utente o mostra un errore
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <title>Gestione Server: <?= htmlspecialchars($server['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #fff;
        }

        /* Aggiungi questo nuovo stile */
        #console-output {
            white-space: pre-wrap;
            /* Preserva gli spazi e va a capo automaticamente */
            word-break: break-all;
            /* Forza le parole lunghe (es. URL) ad andare a capo */
            font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
            /* Usa un font monospaziato */
            font-size: 0.8rem;
            /* Rende il testo più piccolo (puoi usare 12px, 0.75rem, etc.) */
            line-height: 1.5;
            /* Aumenta lo spazio tra le righe per una migliore leggibilità */
        }

        .tab-button.active {
            background-color: #3b82f6;
            color: #fff;
        }

        /* Personalizzazione barra di scorrimento */
        .scrollbar-hidden::-webkit-scrollbar {
            width: 0.5rem;
            height: 0.5rem;
        }

        .scrollbar-hidden::-webkit-scrollbar-thumb {
            background-color: transparent;
        }

        .scrollbar-hidden {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Notifica */
        .notification {
            position: fixed;
            top: 1.25rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1050;
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
            text-align: center;
            box-shadow: 0px 4px 6px -1px rgba(0, 0, 0, 0.1), 0px 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: opacity 0.3s ease-in-out;
        }

        .notification.success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .notification.error {
            background-color: #fecaca;
            color: #991b1b;
        }
    </style>
</head>

<body class="bg-gray-900 text-white font-sans antialiased">
    <?php include "includes/header.php"; ?>
<div id="google_translate_element"></div>
    <main class="container mx-auto px-4 py-12">
        <div
            class="max-w-4xl mx-auto bg-gray-800 rounded-3xl p-8 shadow-2xl backdrop-blur-md bg-opacity-70 border border-gray-700">
            <h1 class="text-4xl font-bold text-center mb-6 text-blue-400">
                <i class="bi bi-server mr-3"></i> Gestione Server: <span
                    class="text-white"><?= htmlspecialchars($server['name']) ?></span>
            </h1>
            <p class="text-center text-gray-400 mb-8">
                Configura e gestisci le impostazioni del tuo server Bedrock.
            </p>

            <div
                class="flex justify-center border-b border-gray-700 mb-6 scrollbar-hidden overflow-x-auto whitespace-nowrap">
                <button type="button"
                    class="tab-button active text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="general">Generale</button>

                <button type="button"
                    class="tab-button text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="console">Console</button>

                <button type="button"
                    class="tab-button text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="players">Giocatori</button>
                <button type="button"
                    class="tab-button text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="world">Mondo</button>
                <button type="button"
                    class="tab-button text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="advanced">Avanzate</button>
                <button type="button"
                    class="tab-button text-lg py-3 px-6 rounded-t-xl transition-colors duration-200 hover:bg-gray-700 focus:outline-none"
                    data-tab="icon">Icona</button>
            </div>

            <form id="serverPropertiesForm" class="space-y-6">
                <input type="hidden" name="server_id" value="<?= $serverId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                <div id="general-tab" class="tab-content">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">MOTD</label>
                            <input type="text"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="motd" value="<?= htmlspecialchars($server['motd'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Gamemode</label>
                            <select
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="gamemode">
                                <option value="survival" <?= ($server['gamemode'] ?? '') === 'survival' ? 'selected' : '' ?>>Survival</option>
                                <option value="creative" <?= ($server['gamemode'] ?? '') === 'creative' ? 'selected' : '' ?>>Creative</option>
                                <option value="adventure" <?= ($server['gamemode'] ?? '') === 'adventure' ? 'selected' : '' ?>>Adventure</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Difficulty</label>
                            <select
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="difficulty">
                                <option value="peaceful" <?= ($server['difficulty'] ?? '') === 'peaceful' ? 'selected' : '' ?>>Peaceful</option>
                                <option value="easy" <?= ($server['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy
                                </option>
                                <option value="normal" <?= ($server['difficulty'] ?? '') === 'normal' ? 'selected' : '' ?>>
                                    Normal</option>
                                <option value="hard" <?= ($server['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Hard
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="players-tab" class="tab-content hidden">
                    <div class="space-y-6">
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Max Players (1-100)</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="max_players" min="1" max="100"
                                value="<?= intval($server['max_players'] ?? 10) ?>">
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="checkbox"
                                class="h-5 w-5 rounded form-checkbox text-blue-600 bg-gray-700 border-gray-600"
                                name="white_list_enabled" <?= ($server['white_list'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                            <label class="text-gray-300">Whitelist Abilitata</label>
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Whitelist (nomi separati da
                                invio)</label>
                            <textarea
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="whitelist" rows="3"><?= htmlspecialchars($server['whitelist'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Blacklist (nomi separati da
                                invio)</label>
                            <textarea
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="blacklist" rows="3"><?= htmlspecialchars($server['blacklist'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div id="world-tab" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Level Name</label>
                            <input type="text"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="level_name" value="<?= htmlspecialchars($server['level_name'] ?? 'world') ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Seed</label>
                            <input type="text"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="seed" value="<?= htmlspecialchars($server['seed'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">View Distance (2-32)</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="view_distance" min="2" max="32"
                                value="<?= intval($server['view_distance'] ?? 6) ?>">
                        </div>
                    </div>
                </div>

                <div id="advanced-tab" class="tab-content hidden">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox"
                                class="h-5 w-5 rounded form-checkbox text-blue-600 bg-gray-700 border-gray-600"
                                name="enable_command_blocks" <?= ($server['enable_command_blocks'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                            <label class="text-gray-300">Command Blocks Abilitati</label>
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Spawn Protection</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="spawn_protection" min="0"
                                value="<?= intval($server['spawn_protection'] ?? 16) ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Tick Distance (4-12)</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="tick_distance" min="4" max="12"
                                value="<?= intval($server['tick_distance'] ?? 4) ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Player Idle Timeout (minuti)</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="player_idle_timeout" min="0"
                                value="<?= intval($server['player_idle_timeout'] ?? 30) ?>">
                        </div>
                        <div>
                            <label class="block text-gray-300 font-semibold mb-2">Max Threads</label>
                            <input type="number"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                name="max_threads" min="1" value="<?= intval($server['max_threads'] ?? 8) ?>">
                        </div>
                    </div>
                </div>

                <div id="icon-tab" class="tab-content hidden">
                    <div class="flex flex-col items-center space-y-4">
                        <img id="server-icon-preview" src="/path/to/default/icon.png" alt="Server Icon"
                            class="w-16 h-16 rounded-lg border border-gray-600">
                        <div class="w-full max-w-sm">
                            <label class="block text-gray-300 font-semibold mb-2">Carica nuova icona (PNG 64x64)</label>
                            <input type="file"
                                class="w-full p-3 rounded-lg bg-gray-700 border border-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-500 file:text-white hover:file:bg-blue-600"
                                name="server-icon" id="server-icon" accept="image/png">
                        </div>
                        <button type="button"
                            class="mt-4 px-6 py-3 bg-blue-600 text-white rounded-full font-bold shadow-lg hover:bg-blue-700 transition-colors duration-200"
                            id="upload-icon-btn">
                            <i class="bi bi-upload mr-2"></i> Carica Icona
                        </button>
                    </div>
                </div>

                <div class="text-center mt-8">
                    <button type="submit"
                        class="w-full md:w-auto px-10 py-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:from-blue-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105">
                        <i class="bi bi-save mr-2"></i> Salva Modifiche
                    </button>
                </div>
            </form>

            <div id="console-tab" class="tab-content hidden mt-6">
                <div id="console-output" class="h-96 bg-black p-4 rounded-lg overflow-y-auto border border-gray-700">
                    Caricamento console...
                </div>
                <div class="mt-4 flex">
                    <input type="text" id="console-input"
                        class="flex-grow p-3 rounded-l-lg bg-gray-700 border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                        placeholder="Scrivi un comando e premi Invio...">
                    <button id="send-command-btn"
                        class="px-6 py-3 bg-blue-600 text-white rounded-r-lg font-bold hover:bg-blue-700 transition-colors duration-200">
                        Invia
                    </button>
                </div>
            </div>
        </div>
    </main>

    <div id="notification-message" class="notification hidden"></div>

 <script>
document.addEventListener('DOMContentLoaded', () => {
    console.log("Pagina caricata. Script di gestione unificato avviato.");

    // --- Variabili Globali ---
    const csrfToken = '<?= $csrf_token ?>';
    const serverId = '<?= $serverId ?>';
    const serverIconUrl = '<?= isset($server['proxmox_containerId']) ? "/server_data/{$server['proxmox_containerId']}/server-icon.png?" . time() : '' ?>';

    // --- Elementi del DOM ---
    const msgBox = document.getElementById('notification-message');
    const serverPropertiesForm = document.getElementById('serverPropertiesForm');
    const saveBtn = serverPropertiesForm?.querySelector('button[type="submit"]');
    const iconPreview = document.getElementById('server-icon-preview');
    const uploadIconButton = document.getElementById('upload-icon-btn');
    const consoleOutput = document.getElementById('console-output');
    const consoleInput = document.getElementById('console-input');
    const sendCommandBtn = document.getElementById('send-command-btn');

    // --- Logica SSE (Server-Sent Events) ---
    let logSource = null; // Terrà la nostra connessione di streaming

    // --- Funzioni Helper ---
    function showNotification(success, message) {
        msgBox.textContent = message;
        msgBox.className = `notification ${success ? 'success' : 'error'}`;
        msgBox.classList.remove('hidden');
        msgBox.style.opacity = '1';
        setTimeout(() => {
            msgBox.style.opacity = '0';
            setTimeout(() => msgBox.classList.add('hidden'), 300);
        }, 5000);
    }

    // --- Funzioni Principali ---
    function startLogStream() {
        if (logSource) { // Se la connessione è già attiva, non fare nulla
            return;
        }
        console.log("Avvio dello stream della console...");
        consoleOutput.innerHTML = '<div class="text-gray-400">Connessione al log in corso...</div>';

        // Crea la connessione SSE allo script di streaming
        logSource = new EventSource(`ajax_stream_log.php?id=${serverId}`);

        let isFirstMessage = true;

        // Questo evento viene chiamato per ogni "data:" che lo script PHP invia
        logSource.onmessage = function (event) {
            // Al primo messaggio, pulisce la console dal testo di caricamento
            if (isFirstMessage) {
                consoleOutput.innerHTML = '';
                isFirstMessage = false;
            }
            const data = JSON.parse(event.data);
            if (data.log) {
                const logLine = document.createElement('div');
                logLine.textContent = data.log;
                consoleOutput.appendChild(logLine);
                // Scrolla automaticamente alla fine per mostrare sempre le ultime righe
                consoleOutput.scrollTop = consoleOutput.scrollHeight;
            }
        };

        // Gestisce gli errori di connessione
        logSource.onerror = function () {
            console.error("Errore nello stream della console. Connessione chiusa.");
            const errorLine = document.createElement('div');
            errorLine.className = 'text-red-500 font-semibold';
            errorLine.textContent = 'Connessione al server persa. Cambia tab per riprovare.';
            consoleOutput.appendChild(errorLine);
            stopLogStream(); // Chiude la connessione fallita
        };
    }

    function stopLogStream() {
        if (logSource) {
            logSource.close();
            logSource = null; // Resetta la variabile per permettere una nuova connessione
            console.log("Stream della console chiuso.");
        }
    }

    function sendCommand() {
        const command = consoleInput.value.trim();
        if (!command) return;
        const formData = new FormData();
        formData.append('server_id', serverId);
        formData.append('command', command);
        formData.append('csrf_token', csrfToken);
        fetch('ajax_send_command.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                showNotification(data.success, data.message);
                if (data.success) consoleInput.value = '';
            })
            .catch(() => showNotification(false, "Errore nell'invio del comando"));
    }

    // --- Inizializzazione e Gestione Eventi ---

    // 1. Gestione dei Tab
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.addEventListener('click', () => {
            // Gestione visuale delle tab
            document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
            btn.classList.add('active');
            document.getElementById(`${btn.dataset.tab}-tab`).classList.remove('hidden');

            // Gestione dello stream e del pulsante "Salva"
            if (btn.dataset.tab === 'console') {
                saveBtn?.classList.add('hidden');
                startLogStream(); // Avvia lo stream quando si apre la tab console
            } else {
                saveBtn?.classList.remove('hidden');
                stopLogStream(); // Ferma lo stream quando si lascia la tab console
            }
        });
    });
    
    // Controlla la tab attiva al caricamento iniziale
    const activeTab = document.querySelector('.tab-button.active');
    if (saveBtn && activeTab?.dataset.tab === 'console') {
        saveBtn.classList.add('hidden');
    }

    // 2. Logica Invio Comandi Console
    if (sendCommandBtn && consoleInput) {
        sendCommandBtn.addEventListener('click', sendCommand);
        consoleInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendCommand();
            }
        });
    }

    // 3. Salvataggio Proprietà Server
    if (serverPropertiesForm) {
        serverPropertiesForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            this.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                formData.set(cb.name, cb.checked ? 'true' : 'false');
            });
            fetch('ajax_update_server.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => showNotification(data.success, data.message))
                .catch(() => showNotification(false, "Errore nella richiesta. Riprovare."));
        });
    }

    // 4. Gestione Icona Server
    if (iconPreview && serverIconUrl) {
        fetch(serverIconUrl)
            .then(response => { if (response.ok) iconPreview.src = serverIconUrl; })
            .catch(error => console.error("Errore nel caricamento dell'icona iniziale:", error));
    }
    if (uploadIconButton) {
        uploadIconButton.addEventListener('click', () => {
            const fileInput = document.getElementById('server-icon');
            if (!fileInput.files.length) {
                return alert('Seleziona un file PNG 64x64 prima di caricare.');
            }
            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('server_id', serverId);
            formData.append('server-icon', file);
            formData.append('csrf_token', csrfToken);
            fetch('ajax_upload_icon.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showNotification(data.success, data.message);
                    if (data.success) iconPreview.src = URL.createObjectURL(file);
                })
                .catch(() => showNotification(false, "Errore durante l'upload."));
        });
    }
});
</script>
</body>

</html>