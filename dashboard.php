<?php
require 'config/config.php';
require 'includes/auth.php';

$userId = $_SESSION['user_id'];

// Recupera i server dell’utente
$stmt = $pdo->prepare("SELECT s.*, v.vm_name FROM servers s LEFT JOIN minecraft_vms v ON s.vm_id = v.id WHERE s.user_id = ?");
$stmt->execute([$userId]);
$servers = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - I tuoi Server Minecraft</title>
    <link rel="stylesheet" href="assets/styles.css"> <!-- personalizza a piacere -->
</head>
<body>
    <h1>Benvenuto nella tua Dashboard</h1>

    <a href="add_server.php">➕ Crea nuovo server</a>

    <?php if (isset($_GET['success'])): ?>
        <p style="color: green;">Server creato con successo! Tunnel e DNS configurati.</p>
    <?php endif; ?>

    <h2>I tuoi server</h2>

    <?php if (empty($servers)): ?>
        <p>Non hai ancora server attivi.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>VM Assegnata</th>
                    <th>Sottodominio</th>
                    <th>Endpoint Zrok</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr>
                        <td><?= htmlspecialchars($server['name']) ?></td>
                        <td><?= htmlspecialchars($server['vm_name'] ?? 'N/A') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($server['subdomain']) ?>.sians.it</strong><br>
                            <small>_minecraft._tcp.<?= htmlspecialchars($server['subdomain']) ?>.sians.it</small>
                        </td>
                        <td>
                            <?php if ($server['zrok_endpoint']): ?>
                                <?= htmlspecialchars($server['zrok_endpoint']) ?>:<?= htmlspecialchars($server['zrok_port']) ?>
                            <?php else: ?>
                                <em>Non configurato</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form action="server_actions.php" method="POST">
                                <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
                                <button name="action" value="start">Start</button>
                                <button name="action" value="stop">Stop</button>
                                <button name="action" value="delete" onclick="return confirm('Sei sicuro di voler eliminare questo server?');">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
