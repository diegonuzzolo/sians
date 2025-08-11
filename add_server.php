<?php
include __DIR__ . "/auth_check.php";
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;

$defaultCpu = 2;
$defaultRam = 4;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $ramGb = intval($_POST['ram_gb'] ?? 0);

    // Calcolo core:
    // 2 core base per 4 GB
    // ogni 4 GB RAM in più aggiunge 1 core
    // max 8 core
    // se RAM > 32 GB, core max bloccati a 8
    if ($ramGb <= 4) {
        $cpuCores = 2;
    } else {
        $extraCores = intval(floor(($ramGb - 4) / 4));
        $cpuCores = 2 + $extraCores;
        if ($ramGb > 32) {
            $cpuCores = min(8, $cpuCores);
        } else {
            $cpuCores = min(8, $cpuCores);
        }
    }

    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif ($ramGb < 2) {
        $error = "Inserisci almeno 2 GB di RAM.";
    }

    if (!$error) {
        try {
            $node = 'pve'; // Nodo Proxmox reale

            // 1) Ottieni VMID disponibile
            $nextIdResp = proxmoxApiRequest('GET', '/cluster/nextid');
            $vmid = $nextIdResp['data'] ?? null;
            if (!$vmid)
                throw new Exception("Impossibile ottenere vmid disponibile");

            $swapMb = max(512, min($ramGb * 1024 / 2, 8192));
            $diskGb = 64;

            $containerData = proxmoxApiRequest('POST', "/nodes/$node/lxc", [
                'vmid' => $vmid,
                'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
                'hostname' => 'mcserver-' . time(),
                'memory' => $ramGb * 1024,
                'swap' => intval($swapMb),
                'cores' => $cpuCores,
                'storage' => 'local-lvm',
                'rootfs' => "local-lvm:${diskGb}G",
                'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
                'password' => 'Lgu8330Serve6'
            ]);

            // Recupera IP container (retry)
            $ip = null;
            $maxRetries = 10;
            $retry = 0;
            while ($retry < $maxRetries && !$ip) {
                sleep(3);
                $status = proxmoxApiRequest('GET', "/nodes/$node/lxc/$vmid/status/current");
                $ip = $status['network']['eth0']['ip'] ?? null;
                $retry++;
            }
            if (!$ip)
                $ip = 'IP non disponibile';

            // Inserisci container nel DB
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ip, hostname) VALUES (?, ?, ?)");
            $stmt->execute([$vmid, $ip, 'mcserver-' . time()]);
            $containerId = $pdo->lastInsertId();

            // Inserisci server nel DB
            $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at) VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, NOW(), NOW())");
            $stmt2->execute([$serverName, $userId, $containerId, $vmid, $cpuCores, $ramGb * 1024]);
            $serverId = $pdo->lastInsertId();

            // Aggiorna container assegnato
            $stmt3 = $pdo->prepare("UPDATE containers SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
            $stmt3->execute([$userId, $serverId, $containerId]);

            header("Location: dashboard.php?created=1");
            exit;

        } catch (Exception $e) {
            $error = "Errore nella creazione del server: " . $e->getMessage();
        }
    }
}

?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<?php include("includes/header.php"); ?>

<div class="container my-5" style="max-width:600px;">
    <h2 class="mb-4 text-center">Crea un nuovo server Minecraft Bedrock</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label for="server_name" class="form-label">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control" required
                value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Configura le Risorse</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="ram_gb">RAM (GB)</label>
                    <input type="number" name="ram_gb" id="ram_gb" class="form-control" min="2" max="256" step="1" required
                        value="<?= htmlspecialchars($_POST['ram_gb'] ?? $defaultRam) ?>">
                </div>
                <div class="mb-3">
                    <label for="cpu_cores">CPU Cores: <span id="cpuLabel"><?= htmlspecialchars($_POST['cpu_cores'] ?? $defaultCpu) ?></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="<?= htmlspecialchars($_POST['cpu_cores'] ?? $defaultCpu) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-4 w-100">Crea Server</button>
    </form>
</div>

<?php include("includes/footer.php"); ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const ramInput = document.getElementById('ram_gb');
    const cpuLabel = document.getElementById('cpuLabel');
    const cpuInput = document.getElementById('cpu_cores');

    function updateCpuCores() {
        const ram = parseInt(ramInput.value) || <?= $defaultRam ?>;
        let cores = 2;
        if (ram > 4) {
            cores += Math.floor((ram - 4) / 4);
        }
        if (ram > 32) {
            cores = Math.min(cores, 8);
        } else {
            cores = Math.min(cores, 8);
        }
        cpuLabel.textContent = cores;
        cpuInput.value = cores;
    }

    ramInput.addEventListener('input', updateCpuCores);
    updateCpuCores();
</script>

</body>
</html>
