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
        </div>
    </main>

    <div id="notification-message" class="notification hidden"></div>

    <script>



        const csrfToken = '<?= $csrf_token ?>';
        const serverId = '<?= $serverId ?>';
        const serverIconUrl = '<?= "/server_data/{$server['proxmox_containerId']}/server-icon.png?" . time() ?>';
        const iconPreview = document.getElementById('server-icon-preview');

        // Carica l'icona del server
        if (iconPreview) {
            fetch(serverIconUrl)
                .then(response => {
                    if (response.ok) {
                        iconPreview.src = serverIconUrl;
                    }
                })
                .catch(error => console.error("Errore nel caricamento dell'icona:", error));
        }

        // Funzione per mostrare le notifiche
        function showNotification(success, message) {
            const msgBox = document.getElementById('notification-message');
            msgBox.textContent = message;
            msgBox.className = `notification ${success ? 'success' : 'error'}`;
            msgBox.classList.remove('hidden');
            msgBox.style.opacity = '1';

            setTimeout(() => {
                msgBox.style.opacity = '0';
                setTimeout(() => msgBox.classList.add('hidden'), 300);
            }, 5000);
        }

        // Gestione cambio tab
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));

                button.classList.add('active');
                document.getElementById(`${button.dataset.tab}-tab`).classList.remove('hidden');
            });
        });

        // Gestione salvataggio proprietà
        document.getElementById('serverPropertiesForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Gestione checkbox non selezionate per inviare 'false'
            this.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                if (!checkbox.checked) {
                    formData.set(checkbox.name, 'false');
                } else {
                    formData.set(checkbox.name, 'true');
                }
            });

            fetch('ajax_update_server.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => showNotification(data.success, data.message))
                .catch(() => showNotification(false, "Errore nella richiesta. Riprovare."));
        });

        // Gestione upload icona
        document.getElementById('upload-icon-btn').addEventListener('click', function () {
            const fileInput = document.getElementById('server-icon');
            if (!fileInput.files.length) {
                alert('Seleziona un file PNG 64x64 prima di caricare.');
                return;
            }

            const file = fileInput.files[0];
            const reader = new FileReader();
            reader.onload = function (event) {
                const img = new Image();
                img.onload = function () {
                    if (img.width !== 64 || img.height !== 64) {
                        alert('L\'immagine deve essere 64x64 pixel.');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('server_id', serverId);
                    formData.append('server-icon', file);
                    formData.append('csrf_token', csrfToken);

                    fetch('ajax_upload_icon.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(r => r.json())
                        .then(data => {
                            showNotification(data.success, data.message);
                            if (data.success) {
                                // Aggiorna l'anteprima dopo l'upload
                                iconPreview.src = URL.createObjectURL(file);
                            }
                        })
                        .catch(() => showNotification(false, "Errore durante l'upload."));
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    </script>
</body>

</html>