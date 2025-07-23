<?php
session_start();
require 'config/config.php';
require 'includes/auth.php';
require 'includes/functions.php'; 
// FUNZIONI UTILI DA INSERIRE O INCLUDERE (esempio minimale)
function startNgrokTcpTunnel($port) {
    // Esempio: esegue ngrok in background e ritorna host e porta pubblica (adattare a tua logica)
    $command = "sudo -u www-data ngrok  tcp $port --log=stdout --log-level=info";
    exec($command . " 2>&1", $output, $return_var);
    // Qui dovresti parsare l'output reale di ngrok per estrarre host e port
    // Per esempio, se ngrok ritorna un URL come 1.tcp.ngrok.io:12345
    // Esempio fittizio:
    return [
        'host' => '1.tcp.ngrok.io',
        'port' => 12345,
    ];
}

function createCloudflareDnsRecord($subdomain, $targetHost, $targetPort) {
    $url = CLOUDFLARE_API_BASE . "/zones/" . CLOUDFLARE_ZONE_ID . "/dns_records";

    $data = [
        "type" => "SRV",
        "name" => $subdomain . '.' . DOMAIN,
        "data" => [
            "service" => "_minecraft",
            "proto" => "_tcp",
            "name" => $subdomain,
            "priority" => 0,
            "weight" => 0,
            "port" => intval($targetPort),
            "target" => $targetHost
        ],
        "ttl" => 120,
        "proxied" => false
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return false;
    }
    $respData = json_decode($response, true);
    return isset($respData['success']) && $respData['success'] === true;
}

// Gestione form
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $subdomain = strtolower(trim($_POST['subdomain'] ?? ''));

    if (!$name || !$subdomain) {
        $error = "Inserisci sia il nome del server che il sottodominio.";
    } elseif (!preg_match('/^[a-z0-9\-]+$/', $subdomain)) {
        $error = "Il sottodominio può contenere solo lettere, numeri e trattini.";
    } else {
        // Controllo sottodominio
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM servers WHERE subdomain = ?");
        $stmt->execute([$subdomain]);
        if ($stmt->fetchColumn() > 0) {
            $error = "Questo sottodominio è già in uso. Scegline un altro.";
        } else {
            // Cerca VM libera
            $stmt = $pdo->query("SELECT * FROM minecraft_vms WHERE assigned_user_id IS NULL LIMIT 1");
            $vm = $stmt->fetch();

            if (!$vm) {
                $error = "Nessun server disponibile al momento. Riprova più tardi.";
            } else {
                // Inserisci server
                $stmt = $pdo->prepare("INSERT INTO servers (user_id, name, subdomain, proxmox_vmid) VALUES (?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $subdomain, $vm['proxmox_vmid']]);
                $server_id = $pdo->lastInsertId();

                // Aggiorna VM assegnata
                $stmt = $pdo->prepare("UPDATE minecraft_vms SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
                $stmt->execute([$_SESSION['user_id'], $server_id, $vm['id']]);

                // Avvia tunnel ngrok
                // Avvia tunnel ngrok e crea record DNS
require_once 'path/to/ngrok_cloudflare_functions.php'; // metti le funzioni startNgrokTcpTunnel() e createOrUpdateCloudflareDnsRecord() in un file a parte

$tunnel = startNgrokTcpTunnel(25565);
if (!$tunnel) {
    $error = "Impossibile avviare il tunnel ngrok.";
} else {
    $dnsSuccess = createOrUpdateCloudflareDnsRecord($subdomain, $tunnel['host']);
    if (!$dnsSuccess) {
        $error = "Errore nella creazione del record DNS su Cloudflare.";
    } else {
        // Aggiorna DB con host e porta
        $stmt = $pdo->prepare("UPDATE servers SET zrok_host = ?, zrok_port = ? WHERE id = ?");
        $stmt->execute([$tunnel['host'], $tunnel['port'], $server_id]);

        $stmt = $pdo->prepare("UPDATE minecraft_vms SET ip_address = ? WHERE id = ?");
        $stmt->execute([$tunnel['host'], $vm['id']]);

        header("Location: dashboard.php");
        exit;
    }
}

            }
        }
    }
}

include 'includes/header.php';
?>

<div class="container mt-5">
    <h2>Aggiungi un nuovo Server</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mt-4" style="max-width: 350px;">
        <div class="mb-3">
            <label for="name" class="form-label">Nome del Server</label>
            <input type="text" name="name" id="name" class="form-control" required style="max-width: 300px;" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>

        <div class="mb-3">
            <label for="subdomain" class="form-label">Hostname (es mc..)</label>
            <div class="input-group" style="max-width: 300px;">
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
