<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';
require_once 'init.php';

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId)
    die("Accesso negato.");
$error = '';
$checkoutSessionId = '';

// Recupero email utente
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$email = $stmt->fetchColumn();
if (!$email)
    die("Devi avere un'email valida nel profilo.");

// Funzioni lato server per calcolo cores e prezzo
function calculateCores($ramGb)
{
    if ($ramGb <= 2)
        return 2;
    if ($ramGb <= 4)
        return 2;
    if ($ramGb <= 6)
        return 3;
    if ($ramGb <= 8)
        return 4;
    if ($ramGb <= 16)
        return 6;
    return 8;
}
function calculatePrice($ramGb)
{
    if ($ramGb <= 2)
        return 6.5 * 0.85;
    if ($ramGb <= 4)
        return 10 * 0.85;
    if ($ramGb <= 6)
        return 15 * 0.85;
    if ($ramGb <= 8)
        return 21.5 * 0.85;
    if ($ramGb <= 16)
        return 35 * 0.85;
    return 57.5 * 0.85;



}

// STEP 1: Generazione Stripe Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['success'])) {
    $planCode = $_POST['plan_code'] ?? 'plan_4gb'; // default piano 4GB
    $serverName = trim($_POST['server_name'] ?? "mcserver");

    // Recupero dati piano dalla tabella plans
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code = ? AND active=1 LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        die("Piano selezionato non valido.");

    $ramGb = $plan['ram_gb'];
    $cores = $plan['cpu_cores'];
    $stripePriceId = $plan['stripe_price_id'];

    try {
        \Stripe\Stripe::setApiKey("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");

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'customer_email' => $email,
            'line_items' => [
                [
                    'price' => $stripePriceId,  // Price ID ricorrente Stripe
                    'quantity' => 1
                ]
            ],
            'mode' => 'subscription',
            'success_url' => "https://sians.it/add_server.php?success=1&plan_code=" . urlencode($planCode) . "&server_name=" . urlencode($serverName),
            'cancel_url' => "https://sians.it/add_server.php?cancel=1"
        ]);


        $checkoutSessionId = $session->id;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// STEP 2: Dopo pagamento (success_url) -> creazione server
if (isset($_GET['success']) && isset($_GET['plan_code']) && isset($_GET['server_name'])) {
    $planCode = $_GET['plan_code'];
    $serverName = trim($_GET['server_name']);

    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        die("Piano non trovato.");

    $ramGb = $plan['ram_gb'];
    $cores = $plan['cpu_cores'];
    $storageGb = $plan['storage_gb'];

    try {
        // --- 0. Controllo risorse disponibili ---
        $stmt = $pdo->query("SELECT * FROM resources WHERE id=1");
        $resources = $stmt->fetch(PDO::FETCH_ASSOC);

        $availableCpu = $resources['total_cpu_cores'] - $resources['used_cpu_cores'];
        $availableRam = $resources['total_ram_gb'] * 1024 - $resources['used_ram_mb'];
        $availableStorage = $resources['total_storage_gb'] - $resources['used_storage_gb'];

        if ($cores > $availableCpu || ($ramGb * 1024) > $availableRam || $storageGb > $availableStorage) {
            throw new Exception("Risorse insufficienti per creare il server.");
        }

        // --- 1. Trova VMID e IP liberi ---
        $vmid = getNextFreeVmid($pdo);
        $vmIp = getNextFreePrivateIp($pdo);

        // --- 2. Inserisci server nel DB (status = stopped) ---
        $stmt = $pdo->prepare("
            INSERT INTO servers
            (name, user_id, proxmox_containerId, status, type, cpu_cores, ram_mb, progress)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$serverName, $userId, $vmid, 'stopped', 'bedrock', $cores, $ramGb * 1024, 0]);
        $serverId = $pdo->lastInsertId();

        // --- 3. Inserisci container nel DB ---
        $stmt = $pdo->prepare("
            INSERT INTO containers
            (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$vmid, $ramGb * 1024, $storageGb, $userId, $serverName, $vmIp, 'Spento', $cores, $serverId]);
        $containerId = $pdo->lastInsertId();

        // --- 4. Aggiorna server con container_id ---
        $stmt = $pdo->prepare("UPDATE servers SET container_id=? WHERE id=?");
        $stmt->execute([$containerId, $serverId]);

        // --- 5. Aggiorna risorse ---
        $stmt = $pdo->prepare("
            UPDATE resources 
            SET total_cpu_cores=total_cpu_cores-?, total_ram_gb=total_ram_gb-?, total_storage_gb=total_storage_gb-?,
                used_cpu_cores=used_cpu_cores+?, used_ram_mb=used_ram_mb+?, used_storage_gb=used_storage_gb+?
            WHERE id=1
        ");
        $stmt->execute([$cores, $ramGb, $storageGb, $cores, $ramGb * 1024, $storageGb]);

        // --- 6. Crea container Proxmox ---
        $createResult = createLxcContainer($vmid, $ramGb, $storageGb, $serverName, $vmIp, $cores);
        if (!$createResult['success'])
            throw new Exception("Errore creazione container: " . $createResult['message']);

        sleep(4);

        // --- 7. Setup server ---
        $sshKey = "/var/www/.ssh/id_proxmox";
        $nodeIp = "192.168.1.251";
        $localScript = '/var/www/setup_server.sh';
        $remoteTemp = "/root/setup_server.sh";

        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"", $output, $status);
        if ($status !== 0)
            throw new Exception("Errore installazione dipendenze: " . implode("\n", $output));
        exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp", $output, $status);
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"", $output, $status);
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"", $output, $status);

        header("Location: dashboard.php");
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

include "includes/header.php";
?>

<div class="container my-5" style="max-width:480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($checkoutSessionId): ?>
        <script src="https://js.stripe.com/v3/"></script>
        <button id="checkoutBtn" class="btn btn-success w-100 fw-bold">Paga e Crea Server</button>
        <script>
            var stripe = Stripe('pk_live_51RwTLKGyhVlVUQfHcwneV569jhxFiNNqCWwEup9sg9IzgtkCBpXmBj0F4pSOrgvFi3sgDng1t6oJcmBcqg93I7EM00z2SQl3Gq');
            document.getElementById('checkoutBtn').addEventListener('click', function () {
                stripe.redirectToCheckout({ sessionId: '<?= $checkoutSessionId ?>' });
            });
        </script>
    <?php else: ?>
        <!-- Form selezione risorse -->
        <form method="POST" id="createServerForm" class="bg-dark p-4 rounded-4 shadow-lg">

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
                <label class="form-label fw-semibold">Seleziona Piano</label>
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

<script>
document.addEventListener("DOMContentLoaded", function() {
    const plans = [
        {code:'plan_2gb', ram:2, cores:2, price:5.53},
        {code:'plan_4gb', ram:4, cores:2, price:8.50},
        {code:'plan_6gb', ram:6, cores:3, price:12.75},
        {code:'plan_8gb', ram:8, cores:4, price:18.28},
        {code:'plan_16gb', ram:16, cores:6, price:29.75},
        {code:'plan_24gb', ram:24, cores:8, price:48.00}
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>




<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>