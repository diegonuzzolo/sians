<?php
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

if(!$server){
    die("Server non trovato o non autorizzato.");
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestione Server Bedrock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
<?php include "includes/header.php"; ?>

<div class="container my-5">
    <h2 class="mb-4 text-center">Gestione Server: <?= htmlspecialchars($server['name']) ?></h2>

    <form id="serverPropertiesForm">
        <input type="hidden" name="server_id" value="<?= $serverId ?>">

        <div class="mb-3">
            <label class="form-label">MOTD</label>
            <input type="text" class="form-control" name="motd" value="<?= htmlspecialchars($server['motd'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Gamemode</label>
            <select class="form-select" name="gamemode">
                <option value="survival" <?= ($server['gamemode'] ?? '')==='survival'?'selected':'' ?>>Survival</option>
                <option value="creative" <?= ($server['gamemode'] ?? '')==='creative'?'selected':'' ?>>Creative</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Difficulty</label>
            <select class="form-select" name="difficulty">
                <option value="easy" <?= ($server['difficulty'] ?? '')==='easy'?'selected':'' ?>>Easy</option>
                <option value="normal" <?= ($server['difficulty'] ?? '')==='normal'?'selected':'' ?>>Normal</option>
                <option value="hard" <?= ($server['difficulty'] ?? '')==='hard'?'selected':'' ?>>Hard</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Max Players (1-100)</label>
            <input type="number" class="form-control" name="max_players" min="1" max="100" value="<?= intval($server['max_players'] ?? 10) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">View Distance (2-32)</label>
            <input type="number" class="form-control" name="view_distance" min="2" max="32" value="<?= intval($server['view_distance'] ?? 6) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Enable Command Blocks</label>
            <select class="form-select" name="enable_command_blocks">
                <option value="true" <?= ($server['enable_command_blocks'] ?? '')==='true'?'selected':'' ?>>True</option>
                <option value="false" <?= ($server['enable_command_blocks'] ?? '')==='false'?'selected':'' ?>>False</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Level Name</label>
            <input type="text" class="form-control" name="level_name" value="<?= htmlspecialchars($server['level_name'] ?? 'world') ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Seed</label>
            <input type="text" class="form-control" name="seed" value="<?= htmlspecialchars($server['seed'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Spawn Protection</label>
            <input type="number" class="form-control" name="spawn_protection" min="0" value="<?= intval($server['spawn_protection'] ?? 16) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Whitelist Enabled</label>
            <select class="form-select" name="white_list">
                <option value="true" <?= ($server['white_list'] ?? '')==='true'?'selected':'' ?>>True</option>
                <option value="false" <?= ($server['white_list'] ?? '')==='false'?'selected':'' ?>>False</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Whitelist (nomi separati da invio)</label>
            <textarea class="form-control" name="whitelist" rows="3"><?= htmlspecialchars($server['whitelist'] ?? '') ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Blacklist (nomi separati da invio)</label>
            <textarea class="form-control" name="blacklist" rows="3"><?= htmlspecialchars($server['blacklist'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-success">Aggiorna Server</button>
    </form>

    <div id="updateMessage" class="mt-3"></div>
</div>

<script>
document.getElementById('serverPropertiesForm').addEventListener('submit', function(e){
    e.preventDefault();

    let formData = new FormData(this);

    fetch('ajax_update_server.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        let msgEl = document.getElementById('updateMessage');
        if(data.success){
            msgEl.textContent = "Server aggiornato correttamente!";
            msgEl.className = "alert alert-success mt-2";
        } else {
            msgEl.textContent = "Errore aggiornamento: " + data.message;
            msgEl.className = "alert alert-danger mt-2";
        }
    })
    .catch(err => {
        let msgEl = document.getElementById('updateMessage');
        msgEl.textContent = "Errore AJAX: " + err;
        msgEl.className = "alert alert-danger mt-2";
    });
});
</script>

<?php include "includes/footer.php"; ?>
</body>
</html>
