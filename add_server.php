<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;

$defaultRam = 4;
$error = null;

function calculateCpuCores(int $ramGb): int {
    if ($ramGb < 2) {
        return 2;
    } elseif ($ramGb <= 4) {
        return 2;
    } elseif ($ramGb <= 8) {
        return 4;
    } elseif ($ramGb <= 16) {
        return 6;
    } else {
        return 8;
    }
}

function calculatePriceByRam(int $ramGb): float {
    if ($ramGb <= 2) return (5 + 8) / 2;      // 6.5
    if ($ramGb <= 4) return (8 + 12) / 2;     // 10
    if ($ramGb <= 6) return (12 + 18) / 2;    // 15
    if ($ramGb <= 8) return (18 + 25) / 2;    // 21.5
    if ($ramGb <= 16) return (30 + 40) / 2;   // 35
    return (50 + 65) / 2;                      // 57.5
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);

    // Calcolo cores e prezzo da ram
    $cpuCores = calculateCpuCores($ramGb);
    $priceEur = calculatePriceByRam($ramGb);

    // Validazioni base
    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif ($ramGb < 2) {
        $error = "Inserisci una quantità di RAM valida (minimo 2 GB).";
    }

    if (!$error) {
        try {
            $node = 'pve'; // Cambia con il tuo nodo reale Proxmox

            // Ottieni VMID disponibile
            $nextIdResp = proxmoxApiRequest('GET', '/cluster/nextid');
            $vmid = $nextIdResp['data'] ?? null;
            if (!$vmid) throw new Exception("Impossibile ottenere vmid disponibile");

            $swapMb = max(512, min($ramGb * 1024 / 2, 8192)); // metà della RAM, minimo 512 MB, max 8192 MB
            $diskGb = 64;

            $containerData = proxmoxApiRequest('POST', "/nodes/$node/lxc", [
                'vmid' => $vmid,
                'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
                'hostname' => 'mcserver-' . time(),
                'memory' => $ramGb * 1024,
                'swap' => intval($swapMb),
                'cores' => $cpuCores,
                'rootfs' => 'local-lvm:' . $diskGb . 'G',
                'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
                'password' => 'Lgu8330Serve6'
            ]);

            // Recupera IP container (retry se serve)
            $ip = null;
            $maxRetries = 10;
            $retry = 0;
            while ($retry < $maxRetries && !$ip) {
                sleep(3);
                $status = proxmoxApiRequest('GET', "/nodes/$node/lxc/$vmid/status/current");
                $ip = $status['network']['eth0']['ip'] ?? null;
                $retry++;
            }

            if (!$ip) $ip = 'IP non disponibile';

            // Inserisci container nel DB
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ip, hostname, cpu_cores, ram_mb, storage_gb) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vmid, $ip, 'mcserver-' . time(), $cpuCores, $ramGb * 1024, $diskGb]);
            $containerId = $pdo->lastInsertId();

            // Inserisci server nel DB con prezzo
            $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, price_eur, created_at, updated_at) VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, ?, NOW(), NOW())");
            $stmt2->execute([$serverName, $userId, $containerId, $vmid, $cpuCores, $ramGb * 1024, $priceEur]);
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
                    <label for="cpu_cores">CPU Cores: <span id="cpuLabel"><?= htmlspecialchars($_POST['cpu_cores'] ?? 2) ?></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="<?= htmlspecialchars($_POST['cpu_cores'] ?? 2) ?>">
                </div>
                <div class="mb-3">
                    <label>Prezzo indicativo: <span id="priceLabel">€ 0</span></label>
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
    const priceLabel = document.getElementById('priceLabel');

    function updateCpuAndPrice() {
        const ram = parseInt(ramInput.value) || 2;
        let cores = 2;
        let price = 6.5;  // media tra 5 e 8

        if (ram <= 2) {
            cores = 2;
            price = 6.5;    // (5+8)/2
        } else if (ram <= 4) {
            cores = 2;
            price = 10;     // (8+12)/2
        } else if (ram <= 6) {
            cores = 3;      // tra 2-4 suggerito: prendo 3 come media
            price = 15;     // (12+18)/2
        } else if (ram <= 8) {
            cores = 4;
            price = 21.5;   // (18+25)/2
        } else if (ram <= 16) {
            cores = 6;
            price = 35;     // (30+40)/2
        } else {
            cores = 8;
            price = 57.5;   // (50+65)/2
        }

        cpuLabel.textContent = cores;
        cpuInput.value = cores;
        priceLabel.textContent = "€ " + price.toFixed(2);
    }

    ramInput.addEventListener('input', updateCpuAndPrice);
    updateCpuAndPrice();
</script>


</body>
</html>
