<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Connetti al DB per ottenere info utente se necessario
require 'config/config.php';


$stmt = $pdo->prepare("SELECT * FROM servers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$servers = $stmt->fetchAll();
?>

<h3 class="mt-5">I tuoi Server Minecraft</h3>

<?php if (count($servers) === 0): ?>
    <p>Non hai ancora nessun server.</p>
    <a href="add_server.php" class="btn btn-success mb-3">+ Crea Nuovo Server</a>
<?php else: ?>
    
    <table class="table table-bordered">
    <thead>
        <tr>
            <th>Nome</th>
            <th>Stato</th>
            <th>Azioni</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($servers as $server): ?>
            <a href="create_server.php" class="btn btn-success mb-3">Crea Nuovo Server</a>
            <tr>
                <td><?= htmlspecialchars($server['name']) ?></td>
                <td>
                    <input type="hidden" name="action" value="<?= $server['status'] === 'attivo' ? 'stop' : 'start' ?>">
                        <button type="submit" class="btn btn-sm btn-<?= $server['status'] === 'attivo' ? 'danger' : 'primary' ?>">
                            <?= $server['status'] === 'attivo' ? 'Spegni' : 'Accendi' ?>
                        </button>
                </td>
                <td>
    <!-- Form per accendere/spegnere il server -->
    <form method="post" action="server_action.php" class="d-inline">
        <input type="hidden" name="server_id" value="<?= $server['id'] ?>">
        <input type="hidden" name="action" value="<?= $server['status'] === 'online' ? 'stop' : 'start' ?>">
        <button type="submit" class="btn btn-sm btn-<?= $server['status'] === 'online' ? 'danger' : 'primary' ?>">
            <?= $server['status'] === 'online' ? 'Spegni' : 'Accendi' ?>
        </button>
    </form>

    <!-- Form separato per eliminare il server -->
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