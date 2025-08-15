<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
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
    die("Server non trovato o non autorizzato.");
}
?>

<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <title>Gestione Server Bedrock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #fff;
        }

        .nav-tabs .nav-link.active {
            background-color: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }

        .card {
            background-color: #1e1e1e;
            border: none;
        }

        .form-control,
        .form-select,
        .form-check-input {
            background-color: #2a2a2a;
            color: #fff;
            border-color: #444;
        }

        .form-control:focus,
        .form-select:focus {
            background-color: #2a2a2a;
            color: #fff;
            border-color: #0d6efd;
            box-shadow: none;
        }

        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0b5ed7;
        }

        textarea.form-control {
            resize: vertical;
        }

        #server-message {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1050;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            text-align: center;
            box-shadow: 0px 3px 6px rgba(0, 0, 0, 0.15);
            transition: opacity 0.3s ease;
        }

        #server-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        #server-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <?php include "includes/header.php"; ?>

    <div class="container my-5">
        <h2 class="text-center mb-4"><i class="bi bi-server"></i> Gestione Server:
            <?= htmlspecialchars($server['name']) ?></h2>

        <ul class="nav nav-tabs mb-4" id="serverTab" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab"
                    data-bs-target="#general">Generale</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                    data-bs-target="#players">Giocatori</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#world">Mondo</button>
            </li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                    data-bs-target="#advanced">Avanzate</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#icon">Icona</button>
            </li>
        </ul>

        <form id="serverPropertiesForm">
            <input type="hidden" name="server_id" value="<?= $serverId ?>">

            <div class="tab-content">

                <!-- Generale -->
                <div class="tab-pane fade show active" id="general">
                    <div class="mb-3">
                        <label class="form-label">MOTD</label>
                        <input type="text" class="form-control" name="motd"
                            value="<?= htmlspecialchars($server['motd'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gamemode</label>
                        <select class="form-select" name="gamemode">
                            <option value="survival" <?= ($server['gamemode'] ?? '') === 'survival' ? 'selected' : '' ?>>
                                Survival</option>
                            <option value="creative" <?= ($server['gamemode'] ?? '') === 'creative' ? 'selected' : '' ?>>
                                Creative</option>
                            <option value="adventure" <?= ($server['gamemode'] ?? '') === 'adventure' ? 'selected' : '' ?>>
                                Adventure</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Difficulty</label>
                        <select class="form-select" name="difficulty">
                            <option value="peaceful" <?= ($server['difficulty'] ?? '') === 'peaceful' ? 'selected' : '' ?>>
                                Peaceful</option>
                            <option value="easy" <?= ($server['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy
                            </option>
                            <option value="normal" <?= ($server['difficulty'] ?? '') === 'normal' ? 'selected' : '' ?>>
                                Normal</option>
                            <option value="hard" <?= ($server['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Hard
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Giocatori -->
                <div class="tab-pane fade" id="players">
                    <div class="mb-3">
                        <label class="form-label">Max Players (1-100)</label>
                        <input type="number" class="form-control" name="max_players" min="1" max="100"
                            value="<?= intval($server['max_players'] ?? 10) ?>">
                    </div>
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="white_list_enabled"
                            <?= ($server['white_list'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                        <label class="form-check-label">Whitelist Abilitata</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Whitelist (nomi separati da invio)</label>
                        <textarea class="form-control" name="whitelist"
                            rows="3"><?= htmlspecialchars($server['whitelist'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Blacklist (nomi separati da invio)</label>
                        <textarea class="form-control" name="blacklist"
                            rows="3"><?= htmlspecialchars($server['blacklist'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Mondo -->
                <div class="tab-pane fade" id="world">
                    <div class="mb-3">
                        <label class="form-label">Level Name</label>
                        <input type="text" class="form-control" name="level_name"
                            value="<?= htmlspecialchars($server['level_name'] ?? 'world') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Seed</label>
                        <input type="text" class="form-control" name="seed"
                            value="<?= htmlspecialchars($server['seed'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">View Distance (2-32)</label>
                        <input type="number" class="form-control" name="view_distance" min="2" max="32"
                            value="<?= intval($server['view_distance'] ?? 6) ?>">
                    </div>
                </div>

                <!-- Avanzate -->
                <div class="tab-pane fade" id="advanced">
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="enable_command_blocks"
                            <?= ($server['enable_command_blocks'] ?? 'false') === 'true' ? 'checked' : '' ?>>
                        <label class="form-check-label">Command Blocks Abilitati</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Spawn Protection</label>
                        <input type="number" class="form-control" name="spawn_protection" min="0"
                            value="<?= intval($server['spawn_protection'] ?? 16) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tick Distance (4-12)</label>
                        <input type="number" class="form-control" name="tick_distance" min="4" max="12"
                            value="<?= intval($server['tick_distance'] ?? 4) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Player Idle Timeout (minuti)</label>
                        <input type="number" class="form-control" name="player_idle_timeout" min="0"
                            value="<?= intval($server['player_idle_timeout'] ?? 30) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max Threads</label>
                        <input type="number" class="form-control" name="max_threads" min="1"
                            value="<?= intval($server['max_threads'] ?? 8) ?>">
                    </div>
                </div>

                <!-- Icona -->
                <div class="tab-pane fade" id="icon">
                    <div class="mb-3 text-center">
                        <img id="server-icon-preview" src="/path/to/default/icon.png" alt="Server Icon"
                            style="width:64px;height:64px;border-radius:8px;border:1px solid #444;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Carica nuova icona (PNG 64x64)</label>
                        <input type="file" class="form-control" name="server-icon" id="server-icon" accept="image/png">
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-primary" id="upload-icon-btn">
                            <i class="bi bi-upload"></i> Carica Icona
                        </button>
                    </div>
                </div>

            </div>

            <div class="mt-4 text-center">
                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Salva Modifiche</button>
            </div>
        </form>

        <div id="server-message" style="display:none;"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('serverPropertiesForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('ajax_update_server.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => showMessage(data.success, data.message))
                .catch(() => showMessage(false, "Errore nella richiesta. Riprovare."));
        });

        document.getElementById('upload-icon-btn').addEventListener('click', function () {
            const fileInput = document.getElementById('server-icon');
            if (!fileInput.files.length) {
                alert('Seleziona un file PNG 64x64 prima di caricare.');
                return;
            }
            const formData = new FormData();
            formData.append('server_id', '<?= $serverId ?>');
            formData.append('server-icon', fileInput.files[0]);
            fetch('ajax_upload_icon.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showMessage(data.success, data.message);
                    if (data.success) {
                        document.getElementById('server-icon-preview').src = URL.createObjectURL(fileInput.files[0]);
                    }
                })
                .catch(() => showMessage(false, "Errore durante l'upload."));
        });

        function showMessage(success, message) {
            const msgBox = document.getElementById('server-message');
            msgBox.textContent = message;
            msgBox.className = success ? 'success' : 'error';
            msgBox.style.display = 'block';
            msgBox.style.opacity = '1';
            setTimeout(() => {
                msgBox.style.opacity = '0';
                setTimeout(() => msgBox.style.display = 'none', 300);
            }, 5000);
        }
    </script>

</body>

</html>