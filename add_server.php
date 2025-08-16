<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require_once 'init.php';
require 'stripe_functions.php';
require 'vendor/autoload.php';

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) die("Accesso negato.");

// Recupero email utente
$stmt = $pdo->prepare("SELECT email, stripe_customer_id FROM users WHERE id=?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("Utente non trovato.");
$email = $user['email'];
$stripeCustomerId = $user['stripe_customer_id'] ?? null;

$error = '';
$checkoutSessionId = '';

/**
 * STEP 1: Se POST senza success -> generazione Stripe Checkout
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['success'])) {

    $planCode = $_POST['plan_code'] ?? 'plan_4gb';
    $serverName = trim($_POST['server_name'] ?? "mcserver");

    // Recupera piano
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) die("Piano non valido.");

    // Controllo subscription già esistente
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id=? AND plan_id=? LIMIT 1");
    $stmt->execute([$userId, $plan['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($subscription) {
        // Subscription esiste → verifica slot disponibile
        $availableSlots = $subscription['total'] - $subscription['used'];
        if ($availableSlots <= 0) {
            die("Hai già utilizzato tutti gli slot disponibili di questo piano.");
        }

        // Possiamo creare server senza pagare
        header("Location: add_server.php?recreate=1&plan_id={$plan['id']}&server_name=" . urlencode($serverName));
        exit;
    }

    // Altrimenti, prima creazione → pagamento Stripe
    \Stripe\Stripe::setApiKey("sk_live_XXXXXXXXXXXXXXXXXXXXXXXX");

    try {
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'customer_email' => $email,
            'line_items' => [[
                'price' => $plan['stripe_price_id'],
                'quantity' => 1
            ]],
            'mode' => 'subscription',
            'success_url' => "https://sians.it/add_server.php?success=1&plan_code=" . urlencode($planCode) . "&server_name=" . urlencode($serverName) . "&session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => "https://sians.it/add_server.php?cancel=1"
        ]);
        header("Location: " . $session->url);
        exit;

    } catch (Exception $e) {
        die("Errore Stripe: " . $e->getMessage());
    }
}

/**
 * STEP 2: Dopo pagamento (success_url) o ricreazione
 */
if ((isset($_GET['success'], $_GET['plan_code'], $_GET['server_name'], $_GET['session_id'])) ||
    isset($_GET['recreate'], $_GET['plan_id'], $_GET['server_name'])) {

    if (isset($_GET['recreate'])) {
        // Ricreazione server
        $planId = $_GET['plan_id'];
        $serverName = trim($_GET['server_name']);

        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) die("Piano non trovato.");

        // Recupero subscription
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id=? AND plan_id=? LIMIT 1");
        $stmt->execute([$userId, $planId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$subscription) die("Subscription non trovata.");
        $subscriptionId = $subscription['id'];

    } else {
        // Prima creazione via Stripe
        $planCode = $_GET['plan_code'];
        $serverName = trim($_GET['server_name']);
        $checkoutSessionId = $_GET['session_id'];

        $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? LIMIT 1");
        $stmt->execute([$planCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) die("Piano non trovato.");

        // Recupero Stripe session e subscription
        \Stripe\Stripe::setApiKey("sk_live_XXXXXXXXXXXXXXXXXXXXXXXX");
        $session = \Stripe\Checkout\Session::retrieve($checkoutSessionId);
        $stripeSubscription = \Stripe\Subscription::retrieve($session->subscription);

        $subscriptionId = $stripeSubscription->id;

        // Inserisci subscription DB
        $stmt = $pdo->prepare("
            INSERT INTO subscriptions
            (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status, total, used, current_period_end, created_at)
            VALUES (?, ?, ?, ?, ?, 1, 0, ?, NOW())
            ON DUPLICATE KEY UPDATE
                stripe_subscription_id=VALUES(stripe_subscription_id),
                status=VALUES(status),
                current_period_end=VALUES(current_period_end)
        ");
        $stmt->execute([
            $userId,
            $plan['id'],
            $stripeCustomerId ?? $stripeSubscription->customer,
            $stripeSubscription->id,
            $stripeSubscription->status,
            date('Y-m-d H:i:s', $stripeSubscription->current_period_end)
        ]);

        // Aggiorna subscription dal DB per uso slot
        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id=? AND plan_id=? LIMIT 1");
        $stmt->execute([$userId, $plan['id']]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // --- Creazione server e container ---
    $ramGb = $plan['ram_gb'];
    $cores = $plan['cpu_cores'];
    $storageGb = $plan['storage_gb'];

    // Risorse disponibili
    $stmt = $pdo->query("SELECT * FROM resources WHERE id=1");
    $resources = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cores > $resources['total_cpu_cores'] - $resources['used_cpu_cores'] ||
        $ramGb*1024 > $resources['total_ram_mb'] ||
        $storageGb > $resources['total_storage_gb'])
        die("Risorse insufficienti per creare il server.");

    $vmid = getNextFreeVmid($pdo);
    $vmIp = getNextFreePrivateIp($pdo);

    // Inserisci server
    $stmt = $pdo->prepare("
        INSERT INTO servers (name, user_id, plan_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress)
        VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, 0)
    ");
    $stmt->execute([$serverName, $userId, $plan['id'], $vmid, $cores, $ramGb*1024]);
    $serverId = $pdo->lastInsertId();

    // Inserisci container
    $stmt = $pdo->prepare("
        INSERT INTO containers (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
        VALUES (?, ?, ?, ?, ?, ?, 'Stopped', ?, ?)
    ");
    $stmt->execute([$vmid, $ramGb*1024, $storageGb, $userId, $serverName, $vmIp, $cores, $serverId]);
    $containerId = $pdo->lastInsertId();

    // Aggiorna server con container_id
    $stmt = $pdo->prepare("UPDATE servers SET container_id=? WHERE id=?");
    $stmt->execute([$containerId, $serverId]);

    // Aggiorna subscription.used
    $stmt = $pdo->prepare("UPDATE subscriptions SET used = used + 1 WHERE id=?");
    $stmt->execute([$subscription['id']]);

    // Aggiorna risorse
    $stmt = $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores + ?, used_ram_mb = used_ram_mb + ?, used_storage_gb = used_storage_gb + ? WHERE id=1");
    $stmt->execute([$cores, $ramGb*1024, $storageGb]);

    // Creazione Proxmox container
    $createResult = createLxcContainer($vmid, $ramGb, $storageGb, $serverName, $vmIp, $cores);
    if (!$createResult['success']) die("Errore creazione container: " . $createResult['message']);

    sleep(4);

    // Setup server remoto
    $sshKey = "/var/www/.ssh/id_proxmox";
    $nodeIp = "192.168.1.251";
    $localScript = '/var/www/setup_server.sh';
    $remoteTemp = "/root/setup_server.sh";  

    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"");
    exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"");
    exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"");

    header("Location: dashboard.php");
    exit;
}
include "includes/header.php";
?>




<div class="container my-5" style="max-width:480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($checkoutSessionId)): ?>
        <!-- Mostra solo pulsante pagamento -->
        <script src="https://js.stripe.com/v3/"></script>
        <button id="checkoutBtn" class="btn btn-success w-100 fw-bold">Paga e Crea Server</button>
        <script>
            var stripe = Stripe('pk_live_51RwTLKGyhVlVUQfHcwneV569jhxFiNNqCWwEup9sg9IzgtkCBpXmBj0F4pSOrgvFi3sgDng1t6oJcmBcqg93I7EM00z2SQl3Gq');
            document.getElementById('checkoutBtn').addEventListener('click', function () {
                stripe.redirectToCheckout({ sessionId: <?php echo json_encode($checkoutSessionId); ?> });
            });
        </script>
    <?php else: ?>
        <!-- Mostra form selezione risorse -->
        <form method="POST" id="createServerForm" class="bg-dark p-4 rounded-4 shadow-lg">
            <?php echo csrf_field(); ?>
            <div class="mb-4">
                <label for="server_name" class="form-label text-black fw-semibold fs-5">Nome Server</label>
                <input type="text" name="server_name" id="server_name"
                    class="form-control form-control-lg bg-secondary text-black border-0 shadow-sm" required
                    placeholder="Es. Il Mio Server" value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
            </div>

            <div class="card border-0 bg-bedrock shadow p-3 rounded-4">
                <div class="card-header bg-primary rounded-3 py-2 text-black text-center fs-5 fw-bold">
                    Configura le Risorse
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label text-black fw-semibold">Seleziona Piano</label>
                        <select name="plan_code" class="form-select form-select-lg">
                            <option value="plan_2gb">2 GB</option>
                            <option value="plan_4gb" selected>4 GB</option>
                            <option value="plan_6gb">6 GB</option>
                            <option value="plan_8gb">8 GB</option>
                            <option value="plan_16gb">16 GB</option>
                            <option value="plan_24gb">24 GB</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-black fw-semibold">
                            CPU Cores: <span id="cpuDisplay" class="fs-5 badge bg-info"></span>
                        </label>
                        <input type="hidden" name="cpu_cores" id="cpu_cores" value="2">

                        <div class="progress rounded-pill" style="height: 12px;">
                            <div id="cpuProgress" class="progress-bar bg-info" role="progressbar"
                                style="width: 0%; transition: width 0.5s;"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-black fw-semibold">
                            RAM Allocata: <span id="ramDisplay" class="fs-5 badge bg-primary"></span>
                        </label>
                    </div>

                    <div class="mb-0 text-black fw-semibold fs-5 text-center">
                        <span id="priceLabel" class="fw-bold text-success">€ 0.00</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success mt-4 w-100 fw-bold shadow-sm">Prosegui al Pagamento</button>
        </form>
    <?php endif; ?>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const plans = [
            { code: 'plan_2gb', ram: 2, cores: 2, price: 5.53 },
            { code: 'plan_4gb', ram: 4, cores: 2, price: 8.50 },
            { code: 'plan_6gb', ram: 6, cores: 3, price: 12.75 },
            { code: 'plan_8gb', ram: 8, cores: 4, price: 18.28 },
            { code: 'plan_16gb', ram: 16, cores: 6, price: 29.75 },
            { code: 'plan_24gb', ram: 24, cores: 8, price: 48.00 }
        ];

        const planSelect = document.querySelector('select[name="plan_code"]');
        const ramDisplay = document.getElementById('ramDisplay');
        const cpuLabel = document.getElementById('cpu_cores'); // hidden
        const cpuDisplay = document.getElementById('cpuDisplay'); // badge
        const cpuProgress = document.getElementById('cpuProgress');
        const priceLabel = document.getElementById('priceLabel');

        function updatePlanDisplay() {
            const planCode = planSelect.value;
            const option = plans.find(p => p.code === planCode);
            if (!option) return;

            // Aggiorna badge RAM
            ramDisplay.textContent = option.ram + ' GB';
            ramDisplay.classList.add('animate__animated', 'animate__fadeIn');
            setTimeout(() => ramDisplay.classList.remove('animate__animated', 'animate__fadeIn'), 500);

            // Aggiorna badge CPU
            cpuDisplay.textContent = option.cores + ' Cores';
            cpuDisplay.classList.add('animate__animated', 'animate__pulse');
            setTimeout(() => cpuDisplay.classList.remove('animate__animated', 'animate__pulse'), 500);

            cpuLabel.value = option.cores;
            cpuProgress.style.width = (option.cores / 8 * 100) + '%';

            // Aggiorna prezzo
            priceLabel.textContent = "€ " + option.price.toFixed(2);
        }

        planSelect.addEventListener('change', updatePlanDisplay);

        updatePlanDisplay(); // inizializza valori
    });
</script>

<!-- Aggiungi Animate.css per le animazioni -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />




<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>