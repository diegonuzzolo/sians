<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';

$userId = $_SESSION['user_id'] ?? 0;

function findAvailableContainer(PDO $pdo) {
    $sql = "SELECT * FROM containers WHERE assigned_user_id IS NULL LIMIT 1";
    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function checkResourcesAvailable(PDO $pdo, $cpu, $ram_gb) {
    $stmt = $pdo->query("SELECT * FROM resources LIMIT 1");
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$res) {
        return false; 
    }
    $availableCpu = $res['total_cpu_cores'] - $res['used_cpu_cores'];
    $availableRamMb = ($res['total_ram_gb'] * 1024) - $res['used_ram_mb'];

    return ($cpu <= $availableCpu) && (($ram_gb*1024) <= $availableRamMb);
}

$defaultCpu = 4;
$defaultRam = 8;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $cpuCores = max(1, intval($_POST['cpu_cores'] ?? $defaultCpu));
    $ramGb = max(2, intval($_POST['ram_gb'] ?? $defaultRam));

    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif (!checkResourcesAvailable($pdo, $cpuCores, $ramGb)) {
        $error = "Risorse insufficienti disponibili per la configurazione richiesta. Riprova con valori inferiori.";
    } 

    if (!$error) {
        $container = findAvailableContainer($pdo);
        if (!$container) {
            $error = "Nessun container disponibile. Contatta l'amministratore.";
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO servers 
                (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, NOW(), NOW())");
            $stmt->execute([
                $serverName,
                $userId,
                $container['id'],
                $container['proxmox_containerId'],
                $cpuCores,
                $ramGb * 1024
            ]);
            $serverId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("UPDATE containers SET assigned_user_id = ?, assigned_server_id = ?, cpu_cores = ?, ram_mb = ?, storage_gb = ? WHERE id = ?");
            $stmt->execute([
                $userId,
                $serverId,
                $cpuCores,
                $ramGb * 1024,
                null,
                $container['id']
            ]);

            $stmt = $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?");
            $stmt->execute([
                $cpuCores,
                $ramGb * 1024
            ]);

            $pdo->commit();

            header("Location: dashboard.php?created=1");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Errore durante la creazione del server: " . $e->getMessage();
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
            <input type="text" name="server_name" id="server_name" class="form-control" required value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-primary shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Configura le Risorse</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="cpu_cores" class="form-label">CPU Cores</label>
                    <input type="number" name="cpu_cores" id="cpu_cores" class="form-control" min="1" max="64" step="1" required value="<?= htmlspecialchars($_POST['cpu_cores'] ?? $defaultCpu) ?>">
                </div>
                <div class="mb-3">
                    <label for="ram_gb" class="form-label">RAM (GB)</label>
                    <input type="number" name="ram_gb" id="ram_gb" class="form-control" min="2" max="256" step="1" required value="<?= htmlspecialchars($_POST['ram_gb'] ?? $defaultRam) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-4 w-100">Crea Server</button>
    </form>
</div>

<?php include("includes/footer.php"); ?>
