<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

// Funzione per aggiornare server.properties direttamente
function updateServerPropertiesDirect(PDO $pdo, int $serverId, int $userId): array {
    try {
        $stmt = $pdo->prepare("SELECT proxmox_containerId FROM servers WHERE id=? AND user_id=?");
        $stmt->execute([$serverId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Server non trovato');
        $vmid = intval($row['proxmox_containerId'] ?? 0);

        // Dati default / post
        $motd = trim($_POST['motd'] ?? 'Benvenuto sul server');
        $gamemode = $_POST['gamemode'] ?? 'survival';
        $difficulty = $_POST['difficulty'] ?? 'easy';
        $maxPlayers = max(1, min(100, intval($_POST['max_players'] ?? 10)));
        $viewDistance = max(2, min(32, intval($_POST['view_distance'] ?? 6)));
        $enableCommandBlocks = (($_POST['enable_command_blocks'] ?? 'false') === 'true') ? 'true' : 'false';
        $levelName = trim($_POST['level_name'] ?? 'world');
        $seed = trim($_POST['seed'] ?? '');
        $spawnProtection = max(0, intval($_POST['spawn_protection'] ?? 16));
        $whiteListEnabled = (($_POST['white_list_enabled'] ?? 'false') === 'true') ? 'true' : 'false';
        $whitelistText = trim($_POST['whitelist'] ?? '');
        $blacklistText = trim($_POST['blacklist'] ?? '');
        $tickDistance = max(4, min(12, intval($_POST['tick_distance'] ?? 4)));
        $playerIdleTimeout = max(0, intval($_POST['player_idle_timeout'] ?? 30));
        $maxThreads = max(1, intval($_POST['max_threads'] ?? 8));
        $enableLanVisibility = 'true'; // statico
        $serverDir = "/root/minecraft_bedrock";

        $propertiesLines = [
            "server-name={$motd}",
            "motd={$motd}",
            "gamemode={$gamemode}",
            "force-gamemode=false",
            "difficulty={$difficulty}",
            "allow-cheats=false",
            "max-players={$maxPlayers}",
            "online-mode=true",
            "allow-list={$whiteListEnabled}",
            "server-port=19132",
            "server-portv6=19133",
            "enable-lan-visibility={$enableLanVisibility}",
            "view-distance={$viewDistance}",
            "tick-distance={$tickDistance}",
            "player-idle-timeout={$playerIdleTimeout}",
            "max-threads={$maxThreads}",
            "level-name={$levelName}",
            "level-seed={$seed}"
        ];
        $serverProperties = implode("\n", $propertiesLines)."\n";

        $sshKey = "/var/www/.ssh/id_proxmox";
        $nodeIp = "192.168.1.251";
        $tmpFile = "/root/server.properties";

        file_put_contents("/tmp/server.properties", $serverProperties);

        exec("sudo scp -i $sshKey -o StrictHostKeyChecking=no /tmp/server.properties root@$nodeIp:$tmpFile", $output, $status);
        if ($status !== 0) throw new Exception("Errore copia server.properties sul nodo: ".implode("\n",$output));

        exec("sudo ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $tmpFile $serverDir/server.properties\"", $output, $status);
        if ($status !== 0) throw new Exception("Errore push server.properties nel container: ".implode("\n",$output));

        $stmt = $pdo->prepare("
            UPDATE servers SET
                motd=?, gamemode=?, difficulty=?,
                max_players=?, view_distance=?,
                enable_command_blocks=?, level_name=?,
                seed=?, spawn_protection=?, white_list=?,
                whitelist=?, blacklist=?, tick_distance=?,
                player_idle_timeout=?, max_threads=?, updated_at=NOW()
            WHERE id=? AND user_id=?
        ");
        $stmt->execute([
            $motd,$gamemode,$difficulty,
            $maxPlayers,$viewDistance,
            $enableCommandBlocks,$levelName,
            $seed,$spawnProtection,$whiteListEnabled,
            $whitelistText,$blacklistText,$tickDistance,
            $playerIdleTimeout,$maxThreads,$serverId,$userId
        ]);

        return ['success'=>true, 'message'=>'Server.properties aggiornato', 'vmid'=>$vmid];

    } catch (Throwable $e) {
        return ['success'=>false, 'message'=>$e->getMessage()];
    }
}

// ---- Creazione server ----
$userId = $_SESSION['user_id'] ?? 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ramGb = intval($_POST['ram_gb'] ?? 2);
        $storageGb = intval($_POST['storage_gb'] ?? 20);
        $cores = intval($_POST['cpu_cores'] ?? 2);
        $serverName = trim($_POST['server_name'] ?? "mcserver");

        $vmid = getNextFreeVmid($pdo);
        $vmIp = getNextFreePrivateIp($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO servers
            (name, user_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$serverName, $userId, $vmid, 'installing', 'bedrock', $cores, $ramGb*1024, 0]);
        $serverId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO containers
            (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$vmid, $ramGb*1024, $storageGb, $userId, $serverName, $vmIp, 'installing', $cores, $serverId]);
        $containerId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("UPDATE servers SET container_id = ? WHERE id = ?");
        $stmt->execute([$containerId, $serverId]);

        $createResult = createLxcContainer($vmid, $ramGb, $storageGb, $serverName, $vmIp, $cores);
        if (!$createResult['success']) throw new Exception("Errore creazione container: ".$createResult['message']);

        sleep(4); // attesa container

        $result = updateServerPropertiesDirect($pdo, $serverId, $userId);
        if (!$result['success']) throw new Exception("Errore setup server.properties: ".$result['message']);

        header("Location: dashboard.php");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<?php include 'includes/header.php'; ?>

<div class="container my-5" style="max-width:480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>
    <?php if($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" id="createServerForm" class="bg-dark p-4 rounded-4 shadow-lg">
        <div class="mb-4">
            <label for="server_name" class="form-label text-white fw-semibold fs-5">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control form-control-lg bg-secondary text-white border-0 shadow-sm" required placeholder="Es. Il Mio Server" value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-0 bg-bedrock shadow p-3 rounded-4">
            <div class="card-header bg-primary rounded-3 py-2 text-white text-center fs-5 fw-bold">Configura le Risorse</div>
            <div class="card-body">
                <div class="mb-4">
                    <label for="ram_gb" class="form-label text-white fw-semibold">RAM (GB) <span id="ramDisplay" class="fw-bold"></span></label>
                    <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1" value="<?= htmlspecialchars($_POST['ram_gb'] ?? 4) ?>">
                    <div class="d-flex justify-content-between text-white small mt-1">
                        <span>2GB</span>
                        <span>20GB</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-white fw-semibold">CPU Cores: <span id="cpuLabel" class="fs-5"></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="2">
                    <div class="progress" style="height:8px;">
                        <div id="cpuProgress" class="progress-bar bg-info" role="progressbar" style="width:0%; transition:width 0.4s;"></div>
                    </div>
                </div>
                <div class="mb-0 text-white fw-semibold fs-5 text-center">
                    <span id="priceLabel">€0.00</span>
                </div>
            </div>
        </div>

        <button id="createServerBtn" type="submit" class="btn btn-success mt-4 w-100 fw-bold shadow-sm" style="font-size:1.2rem;">Crea Server</button>
    </form>
</div>

<style>
.bg-bedrock { background-color: #003f54 !important; }
.text-bedrock { color:#00c8ff; text-shadow:1px 1px 3px rgba(0,0,0,0.8);}
.form-control-lg{border-radius:12px;}
.form-range{cursor:pointer;}
.btn-success{background-color:#00c8ff;border:none;transition:background-color 0.3s ease;}
.btn-success:hover,.btn-success:focus{background-color:#0099cc;box-shadow:0 0 12px #00c8ffaa;}
label{user-select:none;}
</style>

<script>
const ramInput = document.getElementById('ram_gb');
const ramDisplay = document.getElementById('ramDisplay');
const cpuLabel = document.getElementById('cpuLabel');
const cpuInput = document.getElementById('cpu_cores');
const cpuProgress = document.getElementById('cpuProgress');
const priceLabel = document.getElementById('priceLabel');

function updateCpuAndPrice() {
    let ram = parseInt(ramInput.value)||2;
    ramDisplay.textContent = ram+' GB';
    let cores=2, price=6.5*0.85;
    if(ram<=2){cores=2; price=6.5*0.85;}
    else if(ram<=4){cores=2; price=10*0.85;}
    else if(ram<=6){cores=3; price=15*0.85;}
    else if(ram<=8){cores=4; price=21.5*0.85;}
    else if(ram<=16){cores=6; price=35*0.85;}
    else{cores=8; price=57.5*0.85;}
    cpuLabel.textContent = cores;
    cpuInput.value = cores;
    cpuProgress.style.width = (cores/8*100)+'%';
    priceLabel.textContent = "€ "+price.toFixed(2);
}
ramInput.addEventListener('input',updateCpuAndPrice);
document.getElementById('createServerForm').addEventListener('submit', function(){
    document.getElementById('createServerBtn').disabled=true;
    document.getElementById('createServerBtn').innerText='Creazione in corso...';
});
updateCpuAndPrice();
</script>

<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
