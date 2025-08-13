<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) die("Server ID non valido.");

$stmt = $pdo->prepare("SELECT s.id AS server_id, s.name AS server_name, s.status, c.proxmox_containerId, c.private_ip
                       FROM servers s
                       JOIN containers c ON c.id = s.container_id
                       WHERE s.id = ?");
$stmt->execute([$serverId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$server) die("Server non trovato.");

$vmid = $server['proxmox_containerId'];
$statusMessage = "Preparazione del container...";

if ($server['status'] === 'new') {
    $stmt = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
    $stmt->execute(['ready_to_install', $serverId]);
}

$installScriptPath = "/root/install_bedrock.sh";
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Installazione server</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
<div class="text-center">
    <h2>Server <?= htmlspecialchars($server['server_name']) ?></h2>
    <p>Container ID: <?= $vmid ?></p>

    <div id="statusMessage" class="mt-3"><?= $statusMessage ?></div>

    <div class="spinner-border text-info mt-3" role="status">
        <span class="visually-hidden">Loading...</span>
    </div>

    <div class="mt-3" id="installButtonContainer" style="display:none;">
        <p>Clicca il pulsante per installare Bedrock direttamente nel container:</p>
        <button id="installBtn" class="btn btn-success">Installa Bedrock</button>
    </div>
</div>

<div class="mt-3" id="installLogContainer" style="display:none;">
    <h5>Log installazione:</h5>
    <pre id="installLog" style="background:#111; color:#0f0; padding:10px; height:300px; overflow:auto;"></pre>
</div>
<div class="mt-3" id="installControlContainer" style="display:none;">
    <button id="stopInstallBtn" class="btn btn-danger">Interrompi / Reset installazione</button>
</div>

<script>

    // Mostra il pulsante di controllo quando l'installazione è in corso
function showInstallControls() {
    document.getElementById('installControlContainer').style.display = "block";
}

document.getElementById('stopInstallBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.textContent = "Interrompo...";

    fetch('stop_install_bedrock.php?server_id=<?= $serverId ?>')
        .then(res => res.json())
        .then(data => {
            const statusEl = document.getElementById('statusMessage');
            if (data.success) {
                statusEl.textContent = "Installazione interrotta. Stato resettato.";
                document.getElementById('installBtn').disabled = false;
                document.getElementById('installBtn').textContent = "Installa Bedrock";
                logEl.textContent = "";
            } else {
                statusEl.textContent = "Errore: " + data.message;
            }
            btn.disabled = false;
            btn.textContent = "Interrompi / Reset installazione";
        })
        .catch(() => {
            document.getElementById('statusMessage').textContent = "Errore di comunicazione.";
            btn.disabled = false;
            btn.textContent = "Interrompi / Reset installazione";
        });
});

document.getElementById('installBtn').addEventListener('click', function() {
    const btn = this;
    btn.disabled = true;
    btn.textContent = "Installazione in corso...";
    const logContainer = document.getElementById('installLogContainer');
    const logEl = document.getElementById('installLog');
    logContainer.style.display = "block";

    fetch('exec_install_bedrock.php?server_id=<?= $serverId ?>')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('statusMessage').textContent = "Errore: " + data.message;
                btn.disabled = false;
                btn.textContent = "Installa Bedrock";
                return;
            }

            const logFile = data.log_file;
            const pid = data.pid;

            function updateLog() {
                fetch('read_install_log.php?file=' + encodeURIComponent(logFile))
                    .then(r => r.text())
                    .then(txt => {
                        logEl.textContent = txt;
                        logEl.scrollTop = logEl.scrollHeight;

                        // Continua finché il PID esiste
                        fetch('check_pid.php?pid=' + pid)
                            .then(r => r.json())
                            .then(info => {
                                if (info.running) {
                                    setTimeout(updateLog, 1000);
                                } else {
                                    // Installazione terminata
                                    document.getElementById('statusMessage').textContent = "Installazione completata! Reindirizzamento...";
                                    setTimeout(() => window.location.href = "dashboard.php", 1500);
                                }
                            });
                    });
            }
            updateLog();
        });
});
</script>

</body>
</html>
