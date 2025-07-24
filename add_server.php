<?php
require 'config/config.php';
require 'includes/auth.php';
require 'includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['name'] ?? '');
    $subdomain = trim($_POST['subdomain'] ?? '');
    $userId = $_SESSION['user_id'] ?? null;

    if (!$serverName || !$subdomain || !$userId) {
        $error = "Tutti i campi sono obbligatori.";
    } elseif (!preg_match('/^[a-z0-9-]+$/i', $subdomain)) {
        $error = "Hostname non valido. Usa solo lettere, numeri e trattini.";
    } else {
        try {
            // 1. Cerca VM libera
            $stmt = $pdo->prepare("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL AND assigned_server_id IS NULL LIMIT 1");
            $stmt->execute();
            $vm = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vm) {
                $error = "Nessuna VM libera disponibile.";
            } else {
                // 2. Crea il server nel DB
                $stmt = $pdo->prepare("INSERT INTO servers (name, user_id, vm_id, subdomain) VALUES (?, ?, ?, ?)");
                if (!$stmt->execute([$serverName, $userId, $vm['id'], $subdomain])) {
                    $error = "Errore nella creazione del server: " . implode(", ", $stmt->errorInfo());
                } else {
                    $serverId = $pdo->lastInsertId();

                    // 3. Aggiorna VM come assegnata
                    $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                    if (!$stmt->execute([$userId, $serverId, $vm['id']])) {
                        $error = "Errore nell'assegnazione della VM: " . implode(", ", $stmt->errorInfo());
                    } else {
                        // 4. Avvia tunnel ngrok sulla VM
                        $vmIp = $vm['ip'];
                        $sshKey = '/home/diego/.ssh/id_rsa';
                        $sshUser = 'diego';

                        $commandStartNgrok = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'nohup ngrok tcp 25565 > /dev/null 2>&1 &'";

                        exec($commandStartNgrok, $outputStart, $exitStart);
                        if ($exitStart !== 0) {
                            $error = "Errore nell'avviare ngrok sulla VM $vmIp.";
                        } else {
                            sleep(5);
                            $commandGetTunnel = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$vmIp 'curl -s http://127.0.0.1:4040/api/tunnels'";
                            $json = shell_exec($commandGetTunnel);
                            $data = json_decode($json, true);

                            if (!isset($data['tunnels']) || count($data['tunnels']) == 0) {
                                $error = "Nessun tunnel ngrok attivo trovato sulla VM $vmIp.";
                            } else {
                                $tunnelUrl = null;
                                foreach ($data['tunnels'] as $tunnel) {
                                    if ($tunnel['proto'] === 'tcp') {
                                        $tunnelUrl = $tunnel['public_url'];
                                        break;
                                    }
                                }

                                if (!$tunnelUrl) {
                                    $error = "Nessun tunnel TCP trovato.";
                                } else {
                                    $stmt = $pdo->prepare("UPDATE servers SET tunnel_url = ? WHERE id = ?");
                                    if (!$stmt->execute([$tunnelUrl, $serverId])) {
                                        $error = "Errore nell'aggiornamento del tunnel: " . implode(", ", $stmt->errorInfo());
                                    } else {
                                        $success = "Server creato con successo. Tunnel attivo: $tunnelUrl";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Errore database: " . $e->getMessage();
        }
    }
}

include 'includes/header.php';
?>

<div class="container mt-5">
    <h2>Aggiungi un nuovo Server Minecraft</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <a href="dashboard.php" class="btn btn-primary">Torna alla Dashboard</a>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" class="mt-4" style="max-width: 400px;">
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
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
