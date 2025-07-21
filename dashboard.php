<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'config/config.php';

$stmt = $pdo->prepare("SELECT * FROM servers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$servers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Minecraft Hosting</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="https://sians.it">Minecraft Hosting</a>
        <div class="collapse navbar-collapse justify-content-end">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Esci</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>I tuoi Server Minecraft</h3>
        <a href="add_server.php" class="btn btn-success">+ Crea Nuovo Server</a>
    </div>

    <?php if (count($servers) === 0): ?>
        <div class="alert alert-warning">Non hai ancora nessun server.</div>
    <?php else: ?>
        <table class="table table-striped table-hover shadow-sm bg-white">
            <thead class="table-dark">
                <tr>
                    <th>Nome</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr>
                        <td><?= htmlspecialchars($server['name']) ?></td>
                        <td>
                            <?= $server['status'] === 'online' ? '<span class="badge bg-success">Online</span>' : '<span class="badge bg-secondary">Offline</span>' ?>
                        </td>
                        <td>
                            <!-- Azione Avvio/Spegnimento -->
                            <form method="post" action="server_action.php" class="d-inline">
                                <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
                                <input type="hidden" name="action" value="<?= $server['status'] === 'online' ? 'stop' : 'start' ?>">
                                <button type="submit" class="btn btn-sm btn-<?= $server['status'] === 'online' ? 'danger' : 'primary' ?>">
                                    <?= $server['status'] === 'online' ? 'Spegni' : 'Accendi' ?>
                                </button>
                            </form>

                            <!-- Elimina -->
                            <form method="post" action="delete_server.php" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo server?');">
                                <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Elimina</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
