<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;





$defaultCpu = 4;
$defaultRam = 8;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $cpuCores = intval($_POST['cpu_cores'] ?? 0);
    $ramGb = intval($_POST['ram_gb'] ?? 0);

    // ...validazioni...

    try {
        // 1) Crea container su Proxmox
        $node = 'pve'; // Modifica con il nodo Proxmox reale
        $containerData = proxmoxApiRequest('POST', "/nodes/$node/lxc", [
            'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
            'hostname' => 'mcserver-' . time(),
            'memory' => $ramGb * 1024,
            'cores' => $cpuCores,
            'storage' => 'local-lvm',
            'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
            'password' => 'lgu8330'
        ]);
        $vmid = $containerData['vmid'] ?? null;
        if (!$vmid) throw new Exception("Impossibile ottenere VMID dopo creazione container");

        // 2) Recupera IP container (potresti dover aspettare un po' o fare retry)
        $status = proxmoxApiRequest('GET', "/nodes/$node/lxc/$vmid/status/current");
        $ip = $status['network']['eth0']['ip'] ?? null;

        // 3) Inserisci container nel DB
        $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, ip, hostname) VALUES (?, ?, ?)");
        $stmt->execute([$vmid, $ip, 'mcserver-' . time()]);
        $containerId = $pdo->lastInsertId();

        // 4) Inserisci server nel DB con container assegnato
        $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at) VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, NOW(), NOW())");
        $stmt2->execute([$serverName, $userId, $containerId, $vmid, $cpuCores, $ramGb * 1024]);
        $serverId = $pdo->lastInsertId();

        // 5) Aggiorna container con utente e server assegnati
        $stmt3 = $pdo->prepare("UPDATE containers SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
        $stmt3->execute([$userId, $serverId, $containerId]);

        header("Location: dashboard.php?created=1");
        exit;

    } catch (Exception $e) {
        $error = "Errore nella creazione del server: " . $e->getMessage();
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
            <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Configura le Risorse</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="cpu_cores" >CPU Cores</label>
                    <input type="number" name="cpu_cores" id="cpu_cores" class="form-control" min="1" max="64" step="1" required value="<?= htmlspecialchars($_POST['cpu_cores'] ?? $defaultCpu) ?>">
                </div>
                <div class="mb-3">
                    <label for="ram_gb" >RAM (GB)</label>
                    <input type="number" name="ram_gb" id="ram_gb" class="form-control" min="2" max="256" step="1" required value="<?= htmlspecialchars($_POST['ram_gb'] ?? $defaultRam) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-4 w-100">Crea Server</button>
    </form>
</div>

<?php include("includes/footer.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
