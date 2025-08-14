<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include("auth_check.php");
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$serverId = intval($_GET['id'] ?? 0);

if ($serverId <= 0) die("Server non valido.");

// Recupera server e container
$stmt = $pdo->prepare("
    SELECT s.*, c.ip AS private_ip, c.proxmox_containerId, c.ram_mb AS container_ram, c.cores AS container_cores
    FROM servers s
    LEFT JOIN containers c ON c.id = s.container_id
    WHERE s.id = ? AND s.user_id = ?
");
$stmt->execute([$serverId, $userId]);
$server = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$server) die("Server non trovato o non autorizzato.");

$nodeIp = "192.168.1.251";
$sshKey = "/var/www/.ssh/id_proxmox";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {

            // Aggiorna database
            $stmt = $pdo->prepare("
                UPDATE servers s
                JOIN containers c ON c.id = s.container_id
                SET s.name = ?, 
                    s.motd = ?, 
                    s.max_players = ?, 
                    s.view_distance = ?, 
                    s.server_port = ?,
                    c.ram_mb = ?, 
                    c.cores = ?
                WHERE s.id = ? AND s.user_id = ?
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['motd'],
                intval($_POST['max_players']),
                intval($_POST['view_distance']),
                intval($_POST['server_port']),
                intval($_POST['ram_mb']),
                intval($_POST['cores']),
                $serverId,
                $userId
            ]);

            $vmid = $server['proxmox_containerId'];

            // Gestione icona
            if (!empty($_FILES['icon']['tmp_name'])) {
                $tmpFile = $_FILES['icon']['tmp_name'];
                $destFile = "/tmp/server-icon.png";
                $copyCmd = "scp -i $sshKey -o StrictHostKeyChecking=no $tmpFile root@$nodeIp:$destFile";
                exec($copyCmd, $output, $status);
                if ($status !== 0) throw new Exception("Errore upload icona: ".implode("\n",$output));

                $pushCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
                         . "\"pct push $vmid $destFile /root/server-icon.png\"";
                exec($pushCmd, $output, $status);
                if ($status !== 0) throw new Exception("Errore push icona nel container: ".implode("\n",$output));
            }

            // Aggiorna server.properties in memoria e invia direttamente al container
            $props = [
                'server-name' => $_POST['name'],
                'max-players' => $_POST['max_players'],
                'view-distance' => $_POST['view_distance'],
                'motd' => $_POST['motd'],
                'server-port' => $_POST['server_port']
            ];

            $propsString = '';
            foreach ($props as $key => $value) {
                $propsString .= "$key=$value\n";
            }

            // Escapa la stringa per shell
            $escapedProps = escapeshellarg($propsString);
            $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp "
                 . "\"echo $escapedProps | pct exec $vmid -- tee /root/server.properties > /dev/null\"";
            exec($cmd, $output, $status);
            if ($status !== 0) {
                throw new Exception("Errore aggiornamento server.properties: " . implode("\n", $output));
            }

            header("Location: manage_server.php?id=$serverId&msg=OK");
            exit;
        }

        elseif ($action === 'start') {
            $url = "https://$nodeIp:8006/api2/json/nodes/pve/lxc/$vmid/status/start";
            $result = callProxmox($url, 'POST');
            if (!$result['success']) throw new Exception($result['message']);
        }

        elseif ($action === 'stop') {
            $url = "https://$nodeIp:8006/api2/json/nodes/pve/lxc/$vmid/status/stop";
            $result = callProxmox($url, 'POST');
            if (!$result['success']) throw new Exception($result['message']);
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Gestisci Server Bedrock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white">
<?php include "includes/header.php"; ?>

<div class="container my-5">
    <h2 class="mb-4">Gestisci Server: <?= htmlspecialchars($server['name']) ?></h2>

    <?php if(!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif(!empty($_GET['msg'])): ?>
        <div class="alert alert-success">Modifiche salvate correttamente!</div>
    <?php endif; ?>

    <form action="manage_server.php?id=<?= $server['id'] ?>" method="POST" enctype="multipart/form-data">
        <ul class="nav nav-tabs mb-3" id="serverTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#general" type="button">Generale</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#game" type="button">Gioco</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#branding" type="button">Branding</button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Generale -->
            <div class="tab-pane fade show active" id="general">
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
            </div>

            <!-- Gioco -->
            <div class="tab-pane fade" id="game">
                <div class="mb-3">
                    <label class="form-label">Max Players</label>
                    <input type="number" class="form-control" name="max_players" value="<?= htmlspecialchars($server['max_players'] ?? 20) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">View Distance</label>
                    <input type="number" class="form-control" name="view_distance" value="<?= htmlspecialchars($server['view_distance'] ?? 10) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Porta Server</label>
                    <input type="number" class="form-control" name="server_port" value="<?= htmlspecialchars($server['server_port'] ?? 19132) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">MOTD</label>
                    <input type="text" class="form-control" name="motd" value="<?= htmlspecialchars($server['motd'] ?? '') ?>">
                </div>
            </div>

            <!-- Branding -->
            <div class="tab-pane fade" id="branding">
                <div class="mb-3">
                    <label class="form-label">Icona Server (PNG)</label>
                    <input type="file" class="form-control" name="icon">
                </div>
            </div>
        </div>

        <button type="submit" name="action" value="update" class="btn btn-primary mt-3">Salva modifiche</button>
        <button type="submit" name="action" value="start" class="btn btn-success mt-3">Avvia</button>
        <button type="submit" name="action" value="stop" class="btn btn-warning mt-3">Ferma</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include "includes/footer.php"; ?>
</body>
</html>
