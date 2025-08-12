<?php
require 'auth_check.php';
require 'config/config.php';

$serverId = intval($_GET['server_id'] ?? 0);
if ($serverId <= 0) {
    die("Server ID non valido");
}

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <title>Installazione in corso...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body class="bg-dark text-white d-flex flex-column justify-content-center align-items-center vh-100">
    <div class="text-center">
        <h2>Creazione e installazione del server in corso...</h2>
        <p>Attendi qualche istante, ti reindirizzeremo automaticamente appena tutto è pronto.</p>
        <div class="spinner-border text-info" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div id="statusMessage" class="mt-3"></div>
    </div>

<script>
    const serverId = <?= json_encode($serverId) ?>;
    const statusMessage = document.getElementById('statusMessage');

    async function checkStatus() {
        try {
            const response = await fetch(`check_install_status.php?server_id=${serverId}`);
            if (!response.ok) throw new Error("Errore rete");
            const data = await response.json();

            if (data.status === 'ready') {
                statusMessage.textContent = "Server pronto! Reindirizzamento...";
                setTimeout(() => {
                    window.location.href = "dashboard.php";
                }, 1500);
            } else {
                statusMessage.textContent = "Stato attuale: " + data.status;
                setTimeout(checkStatus, 3000);
            }
        } catch (e) {
            statusMessage.textContent = "Errore durante il controllo dello stato, riprovo...";
            setTimeout(checkStatus, 5000);
        }
    }

    checkStatus();
</script>
</body>
</html>
