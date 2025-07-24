<?php
require 'config/config.php';
require 'includes/auth.php';
require 'includes/functions.php';

$serverName = $_POST['server_name'] ?? null;
$userId = $_SESSION['user_id'] ?? null; // supponendo utente loggato

if (!$serverName || !$userId) {
    http_response_code(400);
    echo "Parametri mancanti";
    exit;
}

// 1. Cerca VM libera
$stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
$stmt->execute();
$vm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vm) {
    echo "Nessuna VM libera disponibile";
    exit;
}

// 2. Crea il server nel DB
$stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id) VALUES (?, ?, ?)");
$stmt->execute([$serverName, $userId, $vm['id']]);
$serverId = $pdo->lastInsertId();

// 3. Aggiorna VM come assegnata
$stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
$stmt->execute([$userId, $serverId, $vm['id']]);

// 4. Avvia tunnel ngrok sulla VM

$vmIp = $vm['ip']; // assumendo la colonna IP VM
$sshKey = '/home/diego/.ssh/id_rsa'; // percorso chiave SSH privata
$sshUser = 'diego';

// Comando SSH per avviare ngrok in background
$commandStartNgrok = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > /dev/null 2>&1 &'";

exec($commandStartNgrok, $outputStart, $exitStart);
if ($exitStart !== 0) {
    echo "Errore nell'avviare ngrok sulla VM $vmIp";
    exit;
}

// Aspetta 5 secondi che ngrok si avvii
sleep(5);

// Comando per ottenere tunnel JSON da ngrok API sulla VM
$commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";

$json = shell_exec($commandGetTunnel);
$data = json_decode($json, true);

if (!isset($data['tunnels']) || count($data['tunnels']) == 0) {
    echo "Nessun tunnel ngrok attivo trovato sulla VM $vmIp.";
    exit;
}

// Estrai l'URL pubblico tcp
$tunnelUrl = null;
foreach ($data['tunnels'] as $tunnel) {
    if ($tunnel['proto'] === 'tcp') {
        $tunnelUrl = $tunnel['public_url'];
        break;
    }
}

if (!$tunnelUrl) {
    echo "Nessun tunnel TCP trovato.";
    exit;
}

// 5. Aggiorna il server con il tunnelUrl
$stmt = $pdo->prepare("UPDATE servers SET tunnel_url = ? WHERE id = ?");
$stmt->execute([$tunnelUrl, $serverId]);

// 6. Risposta finale
echo "Server creato con successo. Tunnel attivo: $tunnelUrl";


?>

<?php include 'includes/header.php'; ?>
<div class="container mt-5">
    <h2>Aggiungi un nuovo Server</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mt-4" style="max-width: 350px;">
        <div class="mb-3">
            <label for="name" class="form-label">Nome del Server</label>
            <input type="text" name="name" id="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label for="subdomain" class="form-label">Hostname (es. mc123)</label>
            <div class="input-group">
                <input type="text" name="subdomain" id="subdomain" class="form-control" required value="<?= htmlspecialchars($_POST['subdomain'] ?? '') ?>">
                <span class="input-group-text">.<?= DOMAIN ?></span>
            </div>
            <div class="form-text">Questo sarà l'indirizzo che userai per collegarti al server Minecraft.</div>
        </div>

        <button type="submit" class="btn btn-primary">Crea Server</button>
        <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
    </form>
</div>
<?php include 'includes/footer.php'; ?>
