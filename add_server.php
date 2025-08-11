<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;

$error = null;

// Recupera risorse dal DB
$stmtResources = $pdo->prepare("SELECT total_ram_gb, used_ram_mb FROM resources WHERE id = 1");
$stmtResources->execute();
$res = $stmtResources->fetch(PDO::FETCH_ASSOC);

$totalRamGb = $res ? intval($res['total_ram_gb']) : 64;
$usedRamMb = $res ? intval($res['used_ram_mb']) : 0;
$availableRamGb = max(0, $totalRamGb - ceil($usedRamMb / 1024));

$defaultRam = 4;
if ($defaultRam > $availableRamGb) {
    $defaultRam = max(2, $availableRamGb);
}
$maxRamSelectable = $availableRamGb > 0 ? $availableRamGb : 2;

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

function getPriceLabel(int $ramGb): string {
    if ($ramGb <= 2) {
        return "€ 5 - € 8 (Ideale per test o server molto piccoli)";
    } elseif ($ramGb <= 4) {
        return "€ 8 - € 12 (Un buon punto di partenza per server Bedrock)";
    } elseif ($ramGb <= 6) {
        return "€ 12 - € 18 (Opzione di fascia media per server con più giocatori)";
    } elseif ($ramGb <= 8) {
        return "€ 18 - € 25 (Consigliato per server con 20+ giocatori o mondi grandi)";
    } elseif ($ramGb <= 16) {
        return "€ 30 - € 40 (Per server molto grandi, con molti plugin o community attive)";
    } else {
        return "€ 50 - € 65 (Soluzione \"premium\" per chi non vuole compromessi)";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);

    // Validazioni
    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif ($ramGb < 2) {
        $error = "Inserisci una quantità di RAM valida (minimo 2 GB).";
    } elseif ($ramGb > $maxRamSelectable) {
        $error = "La RAM richiesta supera la RAM disponibile sul server (max $maxRamSelectable GB).";
    }

    if (!$error) {
        $cpuCores = calculateCpuCores($ramGb);

        try {
            $node = 'pve'; // Cambia con il tuo nodo reale Proxmox

            // Ottieni VMID disponibile
            $nextIdResp = proxmoxApiRequest('GET', '/cluster/nextid');
            $vmid = $nextIdResp['data'] ?? null;
            if (!$vmid) throw new Exception("Impossibile ottenere vmid disponibile");

            $swapMb = max(512, min($ramGb * 1024 / 2, 8192)); // metà RAM, min 512MB max 8192MB
            $diskGb = 64;

            // Crea container LXC
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

            if (!$ip) $ip = 'IP non disponibile';

            // Inserisci container nel DB
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ip, hostname, cpu_cores, ram_mb, storage_gb) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vmid, $ip, 'mcserver-' . time(), $cpuCores, $ramGb * 1024, $diskGb]);
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

        <div class="card border-primary shadow-sm mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Configura le Risorse</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="ram_gb">RAM (GB)</label>
                    <input type="number" name="ram_gb" id="ram_gb" class="form-control" min="2" max="<?= $maxRamSelectable ?>" step="1" required
                        value="<?= htmlspecialchars($_POST['ram_gb'] ?? $defaultRam) ?>">
                    <small class="text-muted">RAM massima disponibile: <?= $maxRamSelectable ?> GB</small>
                </div>
                <div class="mb-3">
                    <label for="cpu_cores">CPU Cores: <span id="cpuLabel"><?= htmlspecialchars(calculateCpuCores($_POST['ram_gb'] ?? $defaultRam)) ?></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="<?= htmlspecialchars(calculateCpuCores($_POST['ram_gb'] ?? $defaultRam)) ?>">
                </div>
                <div class="mb-3">
                    <label>Prezzo stimato:</label>
                    <div id="priceLabel" class="fw-bold text-success"><?= htmlspecialchars(getPriceLabel($_POST['ram_gb'] ?? $defaultRam)) ?></div>
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

    function calculateCpuCores(ram) {
        if (ram < 2) return 2;
        if (ram <= 4) return 2;
        if (ram <= 8) return 4;
        if (ram <= 16) return 6;
        return 8;
    }

    function getPriceLabel(ram) {
        if (ram <= 2) return "€ 8 (Ideale per test o server molto piccoli)";
        if (ram <= 4) return "€ 12 (Un buon punto di partenza per server Bedrock)";
        if (ram <= 6) return "€ 18 (Opzione di fascia media per server con più giocatori)";
        if (ram <= 8) return "€ 25 (Consigliato per server con 20+ giocatori o mondi grandi)";
        if (ram <= 16) return "€ 40 (Per server molto grandi, community attive)";
        return "€ 65 (Soluzione \"premium\" per chi non vuole compromessi)";
    }

    function updateFields() {
        const ram = parseInt(ramInput.value) || <?= $defaultRam ?>;
        const maxRam = <?= $maxRamSelectable ?>;
        if (ram > maxRam) {
            ramInput.value = maxRam;
        }
        const cores = calculateCpuCores(ram);
        cpuLabel.textContent = cores;
        cpuInput.value = cores;
        priceLabel.textContent = getPriceLabel(ram);
    }

    ramInput.addEventListener('input', updateFields);

    updateFields(); // inizializza al caricamento
</script>

</body>
</html>
