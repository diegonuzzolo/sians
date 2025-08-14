<?php
include("auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT s.*, c.ram_mb, c.cores
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id = ? AND s.user_id = ?
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
    <title>Gestione Server <?= htmlspecialchars($server['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tab-content { background-color: #343a40; padding: 20px; border-radius: 8px; }
        .nav-tabs .nav-link { color: #fff; }
        .nav-tabs .nav-link.active { color: #fff; background-color: #495057; }
    </style>
</head>
<body class="bg-dark text-white">
    <?php include "includes/header.php"; ?>

    <div class="container my-5">
        <h2 class="mb-4 text-center">⚙️ Gestione Server: <?= htmlspecialchars($server['name']) ?></h2>

        <ul class="nav nav-tabs mb-3" id="serverTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button">Generale</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="game-tab" data-bs-toggle="tab" data-bs-target="#game" type="button">Gameplay</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="resources-tab" data-bs-toggle="tab" data-bs-target="#resources" type="button">Risorse</button>
            </li>
        </ul>

        <form id="serverPropsForm">
            <input type="hidden" name="server_id" value="<?= $server['id'] ?>">

            <div class="tab-content" id="serverTabContent">
                <!-- Generale -->
                <div class="tab-pane fade show active" id="general">
                    <div class="mb-3">
                        <label class="form-label">Nome Server</label>
                        <input type="text" class="form-control" name="server-name" value="<?= htmlspecialchars($server['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">MOTD</label>
                        <input type="text" class="form-control" name="motd" value="<?= htmlspecialchars($server['motd'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Seconda riga MOTD</label>
                        <input type="text" class="form-control" name="motd2" value="<?= htmlspecialchars($server['motd2'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nome Mondo</label>
                        <input type="text" class="form-control" name="level-name" value="<?= htmlspecialchars($server['level_name'] ?? 'Bedrock level') ?>">
                    </div>
                </div>

                <!-- Gameplay -->
                <div class="tab-pane fade" id="game">
                    <div class="mb-3">
                        <label class="form-label">Modalità Gioco</label>
                        <select class="form-select" name="gamemode">
                            <option value="survival" <?= ($server['gamemode']=='survival')?'selected':'' ?>>Survival</option>
                            <option value="creative" <?= ($server['gamemode']=='creative')?'selected':'' ?>>Creative</option>
                            <option value="adventure" <?= ($server['gamemode']=='adventure')?'selected':'' ?>>Adventure</option>
                            <option value="spectator" <?= ($server['gamemode']=='spectator')?'selected':'' ?>>Spectator</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Difficoltà</label>
                        <select class="form-select" name="difficulty">
                            <option value="peaceful" <?= ($server['difficulty']=='peaceful')?'selected':'' ?>>Peaceful</option>
                            <option value="easy" <?= ($server['difficulty']=='easy')?'selected':'' ?>>Easy</option>
                            <option value="normal" <?= ($server['difficulty']=='normal')?'selected':'' ?>>Normal</option>
                            <option value="hard" <?= ($server['difficulty']=='hard')?'selected':'' ?>>Hard</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max Players</label>
                        <input type="number" class="form-control" name="max-players" value="<?= htmlspecialchars($server['max_players'] ?? 20) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">View Distance</label>
                        <input type="number" class="form-control" name="view-distance" value="<?= htmlspecialchars($server['view_distance'] ?? 6) ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow-cheats" <?= ($server['allow_cheats']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Permetti Cheat</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="white-list" <?= ($server['white_list']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Abilita Whitelist</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow-flight" <?= ($server['allow_flight']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Permetti Volo</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="spawn-animals" <?= ($server['spawn_animals']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Genera Animali</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="spawn-monsters" <?= ($server['spawn_monsters']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Genera Mostri</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="spawn-npcs" <?= ($server['spawn_npcs']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Genera NPC</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tick Distance</label>
                        <input type="number" class="form-control" name="tick-distance" value="<?= htmlspecialchars($server['tick_distance'] ?? 4) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">RCON Password</label>
                        <input type="text" class="form-control" name="rcon-password" value="<?= htmlspecialchars($server['rcon_password'] ?? '') ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="enable-query" <?= ($server['enable_query']=='true')?'checked':'' ?>>
                        <label class="form-check-label">Abilita Query RCON</label>
                    </div>
                </div>

                <!-- Risorse -->
                <div class="tab-pane fade" id="resources">
                    <div class="mb-3">
                        <label class="form-label">RAM (MB)</label>
                        <input type="number" class="form-control" name="ram_mb" value="<?= htmlspecialchars($server['ram_mb'] ?? 2048) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CPU Cores</label>
                        <input type="number" class="form-control" name="cores" value="<?= htmlspecialchars($server['cores'] ?? 2) ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-3">Salva Modifiche</button>
        </form>
    </div>

    <div class="mt-3">
        <h5>Stato Server: <span id="server-status"><?= htmlspecialchars($server['status']) ?></span></h5>
        <h6>Giocatori connessi: <span id="player-count">0</span></h6>
        <div class="progress mb-2" style="height: 20px;">
            <div id="progress-bar" class="progress-bar bg-info" role="progressbar" style="width: <?= $server['progress'] ?>%"><?= $server['progress'] ?>%</div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateServerStatus() {
    fetch('check_status.php?id=<?= $server['id'] ?>')
        .then(res => res.json())
        .then(data => {
            document.getElementById('server-status').textContent = data.status || 'sconosciuto';
            document.getElementById('player-count').textContent = data.players || 0;

            const progressEl = document.getElementById('progress-bar');
            if(progressEl && data.progress !== undefined){
                progressEl.style.width = data.progress + "%";
                progressEl.textContent = data.progress + "%";
            }
        })
        .catch(err => console.error("Errore aggiornamento stato:", err));
}

// Aggiorna ogni 5 secondi
setInterval(updateServerStatus, 5000);
updateServerStatus(); // esegue subito al caricamento
</script>


    <?php include "includes/footer.php"; ?>
</body>
</html>
