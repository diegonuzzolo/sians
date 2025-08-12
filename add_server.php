<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;

$defaultRam = 4;
$error = null;

function calculateCpuCores(int $ramGb): int {
    if ($ramGb <= 2) return 2;
    elseif ($ramGb <= 4) return 2;
    elseif ($ramGb <= 6) return 3;
    elseif ($ramGb <= 8) return 4;
    elseif ($ramGb <= 16) return 6;
    else return 8;
}

function calculatePrice(int $ramGb): float {
    $priceTable = [
        2 => 6.5 * 0.85,
        4 => 10 * 0.85,
        6 => 15 * 0.85,
        8 => 21.5 * 0.85,
        16 => 35 * 0.85,
        32 => 57.5 * 0.85,
    ];
    if ($ramGb <= 2) return $priceTable[2];
    elseif ($ramGb <= 4) return $priceTable[4];
    elseif ($ramGb <= 6) return $priceTable[6];
    elseif ($ramGb <= 8) return $priceTable[8];
    elseif ($ramGb <= 16) return $priceTable[16];
    else return $priceTable[32];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);

    if ($ramGb > 20) $ramGb = 20;

    $cpuCores = calculateCpuCores($ramGb);
    $price = calculatePrice($ramGb);

    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif ($ramGb < 2) {
        $error = "Inserisci una quantità di RAM valida (minimo 2 GB).";
    }

    if (!$error) {
        try {
            $node = 'pve';
            $nextIdResp = proxmoxApiRequest('GET', '/cluster/nextid');
            $vmid = $nextIdResp['data'] ?? null;
            if (!$vmid) throw new Exception("Impossibile ottenere VMID disponibile");

            $swapMb = max(512, min($ramGb * 1024 / 2, 8192));

            // IP statico (in futuro si può rendere dinamico)
            $ipAddress = "192.168.1.101";
            $gateway = "192.168.1.254";

            // 1) Creazione container
            proxmoxApiRequest('POST', "/nodes/$node/lxc", [
                'vmid'       => $vmid,
                'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
                'hostname'   => 'mcserver-' . time(),
                'memory'     => $ramGb * 1024,
                'swap'       => intval($swapMb),
                'cores'      => $cpuCores,
                'rootfs'     => 'local-lvm:64',
                'net0'       => "name=eth0,bridge=vmbr0,ip=$ipAddress/24,gw=$gateway",
                'password'   => 'Lgu8330Serve6',
                'start'      => 1
            ]);

            // 2) Configurazione netplan dentro il container
            $netplanConfig = <<<EOT
network:
  version: 2
  ethernets:
    eth0:
      addresses: [$ipAddress/24]
      gateway4: $gateway
      nameservers:
        addresses: [8.8.8.8,1.1.1.1]
EOT;

            proxmoxApiRequest('POST', "/nodes/$node/lxc/$vmid/exec", [
                'command' => '/bin/bash',
                'args'    => ['-c', "echo \"$netplanConfig\" > /etc/netplan/50-cloud-init.yaml && netplan apply"]
            ]);

            // 3) Attendi che il container risponda con IP
            $ip = null;
            $maxRetries = 10;
            $retry = 0;
            while ($retry < $maxRetries && !$ip) {
                sleep(3);
                $status = proxmoxApiRequest('GET', "/nodes/$node/lxc/$vmid/status/current");
                $ip = $status['data']['network']['eth0']['ip'] ?? null;
                $retry++;
            }
            if (!$ip) $ip = $ipAddress;

            // 4) Inserimento nel DB
            $hostname = 'mcserver-' . time();
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ip, hostname, cpu_cores, ram_mb) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$vmid, $ip, $hostname, $cpuCores, $ramGb * 1024]);
            $containerId = $pdo->lastInsertId();

            $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at, price) VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, NOW(), NOW(), ?)");
            $stmt2->execute([$serverName, $userId, $containerId, $vmid, $cpuCores, $ramGb * 1024, $price]);
            $serverId = $pdo->lastInsertId();

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
                    <label><span id="priceLabel">€ 0</span></label>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-4 w-100">Crea Server</button>
    </form>
</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php include("includes/footer.php"); ?>

<script>
    const ramInput = document.getElementById('ram_gb');
    const cpuLabel = document.getElementById('cpuLabel');
    const cpuInput = document.getElementById('cpu_cores');
    const priceLabel = document.getElementById('priceLabel');

    function updateCpuAndPrice() {
        // Limite RAM max 20GB
        let ram = parseInt(ramInput.value) || 2;
        if (ram > 20) {
            ram = 20;
            ramInput.value = 20; // forza il limite nel campo input
        }

        let cores = 2;
        let price = 6.5 * 0.85;  // media tra 5 e 8 * 0.85

        if (ram <= 2) {
            cores = 2;
            price = 6.5 * 0.85;
        } else if (ram <= 4) {
            cores = 2;
            price = 10 * 0.85;
        } else if (ram <= 6) {
            cores = 3;
            price = 15 * 0.85;
        } else if (ram <= 8) {
            cores = 4;
            price = 21.5 * 0.85;
        } else if (ram <= 16) {
            cores = 6;
            price = 35 * 0.85;
        } else {
            cores = 8;
            price = 57.5 * 0.85;
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
