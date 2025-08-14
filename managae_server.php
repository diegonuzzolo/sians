<?php
include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

if ($serverId <= 0) {
    die("Server non valido.");
}

// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.*, c.ip AS private_ip, c.proxmox_containerId, c.ram_mb AS container_ram, c.cores AS container_cores
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) {
    die("Server non trovato o non autorizzato.");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update') {
            // Aggiorna nome, ram, cpu, versione nel DB
            $stmt = $pdo->prepare("UPDATE servers s
                                   JOIN containers c ON c.id = s.container_id
                                   SET s.name = ?, s.version = ?, c.ram_mb = ?, c.cores = ?
                                   WHERE s.id = ? AND s.user_id = ?");
            $stmt->execute([
                $_POST['name'],
                $_POST['version'],
                intval($_POST['ram_mb']),
                intval($_POST['cores']),
                $serverId,
                $userId
            ]);
        }

        elseif ($action === 'start') {
            $url = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/{$server['proxmox_containerId']}/status/start";
            $result = callProxmox($url, 'POST');
            if (!$result['success']) throw new Exception($result['message']);
        }

        elseif ($action === 'stop') {
            $url = "https://192.168.1.251:8006/api2/json/nodes/pve/lxc/{$server['proxmox_containerId']}/status/stop";
            $result = callProxmox($url, 'POST');
            if (!$result['success']) throw new Exception($result['message']);
        }

        header("Location: manage_server.php?id=$serverId&msg=OK");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Gestisci Server</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">

<div class="container my-5">
    <h2 class="mb-4">Gestisci Server: <?= htmlspecialchars($server['name']) ?></h2>

    <form action="manage_server.php?id=<?= $server['id'] ?>" method="POST">
        <div class="mb-3">
            <label class="form-label">Nome Server</label>
            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($server['name']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">RAM (MB)</label>
            <input type="number" class="form-control" name="ram_mb" value="<?= htmlspecialchars($server['container_ram']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">CPU Cores</label>
            <input type="number" class="form-control" name="cores" value="<?= htmlspecialchars($server['container_cores']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Versione Minecraft</label>
            <input type="text" class="form-control" name="version" value="<?= htmlspecialchars($server['version']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Stato</label>
            <p><?= htmlspecialchars($server['status']) ?></p>
            <?php if ($server['status'] === 'running'): ?>
                <button type="submit" name="action" value="stop" class="btn btn-warning">Ferma</button>
            <?php else: ?>
                <button type="submit" name="action" value="start" class="btn btn-success">Avvia</button>
            <?php endif; ?>
        </div>

        <button type="submit" name="action" value="update" class="btn btn-primary">Salva modifiche</button>
    </form>

</div>
</body>
</html>
