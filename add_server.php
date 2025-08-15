<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';
require_once 'includes/csrf.php';

require_once 'includes/stripe-php/init.php';

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) die("Accesso negato.");
$error = '';
$checkoutSessionId = '';

// Recupero email utente
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$email = $stmt->fetchColumn();
if (!$email) die("Devi avere un'email valida nel profilo.");

// Funzioni lato server per calcolo cores e prezzo
function calculateCores($ramGb) {
    if ($ramGb <= 2) return 2;
    if ($ramGb <= 4) return 2;
    if ($ramGb <= 6) return 3;
    if ($ramGb <= 8) return 4;
    if ($ramGb <= 16) return 6;
    return 8;
}
function calculatePrice($ramGb) {
    if ($ramGb <= 2) return 6.5*0.85;
    if ($ramGb <= 4) return 10*0.85;
    if ($ramGb <= 6) return 15*0.85;
    if ($ramGb <= 8) return 21.5*0.85;
    if ($ramGb <= 16) return 35*0.85;
    return 57.5*0.85;
}

// STEP 1: Generazione Stripe Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['success'])) {
    $ramGb = intval($_POST['ram_gb'] ?? 2);
    $serverName = trim($_POST['server_name'] ?? "mcserver");
    $cores = calculateCores($ramGb);
    $price = calculatePrice($ramGb);
    $priceCents = intval(round($price*100));

    try {
        \Stripe\Stripe::setApiKey(getenv('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'));

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'customer_email' => $email,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => "Server Minecraft $serverName ($ramGb GB / $cores CPU)"],
                    'unit_amount' => $priceCents
                ],
                'quantity' => 1
            ]],
            'mode' => 'payment',
            'success_url' => "https://tuo-sito.it/add_server.php?success=1&server_name=".urlencode($serverName)."&ram_gb=$ramGb",
            'cancel_url' => "https://tuo-sito.it/add_server.php?cancel=1"
        ]);

        $checkoutSessionId = $session->id;

    } catch(Exception $e){
        $error = $e->getMessage();
    }
}

// STEP 2: Dopo pagamento Stripe (success_url) -> creazione reale server
if (isset($_GET['success']) && isset($_GET['server_name']) && isset($_GET['ram_gb'])) {
    $ramGb = intval($_GET['ram_gb']);
    $serverName = trim($_GET['server_name']);
    $cores = calculateCores($ramGb);
    $storageGb = 64;

    try {
        // --- 0. Controllo risorse disponibili ---
        $stmt = $pdo->query("SELECT * FROM resources WHERE id=1");
        $resources = $stmt->fetch(PDO::FETCH_ASSOC);

        $availableCpu = $resources['total_cpu_cores'] - $resources['used_cpu_cores'];
        $availableRam = $resources['total_ram_gb']*1024 - $resources['used_ram_mb'];
        $availableStorage = $resources['total_storage_gb'] - $resources['used_storage_gb'];

        if ($cores > $availableCpu || ($ramGb*1024) > $availableRam || $storageGb > $availableStorage) {
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
        $stmt->execute([$serverName,$userId,$vmid,'stopped','bedrock',$cores,$ramGb*1024,0]);
        $serverId = $pdo->lastInsertId();

        // --- 3. Inserisci container nel DB ---
        $stmt = $pdo->prepare("
            INSERT INTO containers
            (proxmox_containerId, ram_mb, storage_gb, user_id, hostname, ip, status, cores, server_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$vmid,$ramGb*1024,$storageGb,$userId,$serverName,$vmIp,'Spento',$cores,$serverId]);
        $containerId = $pdo->lastInsertId();

        // --- 4. Aggiorna server con container_id ---
        $stmt = $pdo->prepare("UPDATE servers SET container_id=? WHERE id=?");
        $stmt->execute([$containerId,$serverId]);

        // --- 5. Aggiorna risorse ---
        $stmt = $pdo->prepare("
            UPDATE resources 
            SET total_cpu_cores=total_cpu_cores-?, total_ram_gb=total_ram_gb-?, total_storage_gb=total_storage_gb-?,
                used_cpu_cores=used_cpu_cores+?, used_ram_mb=used_ram_mb+?, used_storage_gb=used_storage_gb+?
            WHERE id=1
        ");
        $stmt->execute([$cores,$ramGb,$storageGb,$cores,$ramGb*1024,$storageGb]);

        // --- 6. Crea container Proxmox ---
        $createResult = createLxcContainer($vmid,$ramGb,$storageGb,$serverName,$vmIp,$cores);
        if(!$createResult['success']) throw new Exception("Errore creazione container: ".$createResult['message']);

        sleep(4);

        // --- 7. Setup server ---
        $sshKey="/var/www/.ssh/id_proxmox";
        $nodeIp="192.168.1.251";
        $localScript='/var/www/setup_server.sh';
        $remoteTemp="/root/setup_server.sh";

        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- apt-get update && pct exec $vmid -- apt-get install -y curl jq unzip screen\"", $output, $status);
        if($status!==0) throw new Exception("Errore installazione dipendenze: ".implode("\n",$output));
        exec("scp -i $sshKey -o StrictHostKeyChecking=no $localScript root@$nodeIp:$remoteTemp",$output,$status);
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct push $vmid $remoteTemp /root/setup_server.sh\"",$output,$status);
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no root@$nodeIp \"pct exec $vmid -- chmod +x /root/setup_server.sh && pct exec $vmid -- /root/setup_server.sh $containerId > /dev/null 2>&1 &\"",$output,$status);

        header("Location: dashboard.php");
        exit;

    } catch(Exception $e){
        $error = $e->getMessage();
    }
}

include "includes/header.php";
?>

<div class="container my-5" style="max-width:480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>

    <?php if($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if($checkoutSessionId): ?>
        <script src="https://js.stripe.com/v3/"></script>
        <button id="checkoutBtn" class="btn btn-success w-100 fw-bold">Paga e Crea Server</button>
        <script>
            var stripe = Stripe('<?= getenv('STRIPE_PUBLISHABLE_KEY') ?>');
            document.getElementById('checkoutBtn').addEventListener('click', function(){
                stripe.redirectToCheckout({ sessionId: '<?= $checkoutSessionId ?>' });
            });
        </script>
    <?php else: ?>
        <!-- Form selezione risorse -->
        <form method="POST" id="createServerForm" class="bg-dark p-4 rounded-4 shadow-lg">
            <?= csrf_field() ?>
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
                    <div class="mb-4">
                        <label for="ram_gb" class="form-label text-black fw-semibold">RAM: <span id="ramDisplay" class="fw-bold"></span></label>
                        <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1"
                               value="<?= htmlspecialchars($_POST['ram_gb'] ?? 4) ?>">
                        <div class="d-flex justify-content-between text-black small mt-1">
                            <span>2GB</span>
                            <span>20GB</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-black fw-semibold">CPU Cores: <span id="cpuLabel" class="fs-5"></span></label>
                        <input type="hidden" name="cpu_cores" id="cpu_cores" value="2">
                        <div class="progress" style="height: 8px;">
                            <div id="cpuProgress" class="progress-bar bg-info" role="progressbar"
                                 style="width: 0%; transition: width 0.4s;"></div>
                        </div>
                    </div>
                    <div class="mb-0 text-black fw-semibold fs-5 text-center">
                        <span id="priceLabel">€ 0.00</span>

                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-success mt-4 w-100 fw-bold shadow-sm">Prosegui al Pagamento</button>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ramInput = document.getElementById('ram_gb');
    const ramDisplay = document.getElementById('ramDisplay');
    const cpuLabel = document.getElementById('cpuLabel');
    const cpuInput = document.getElementById('cpu_cores');
    const cpuProgress = document.getElementById('cpuProgress');
    const priceLabel = document.getElementById('priceLabel');

    function updateCpuAndPrice(){
        let ram = parseInt(ramInput.value) || 4; // valore di default se vuoto
        ramDisplay.textContent = ram + ' GB';

        let cores = 2, price = 6.5*0.85;
        if(ram<=2){cores=2;price=6.5*0.85;}
        else if(ram<=4){cores=2;price=10*0.85;}
        else if(ram<=6){cores=3;price=15*0.85;}
        else if(ram<=8){cores=4;price=21.5*0.85;}
        else if(ram<=16){cores=6;price=35*0.85;}
        else{cores=8;price=57.5*0.85;}

        cpuLabel.textContent = cores;
        cpuInput.value = cores;
        cpuProgress.style.width = (cores/8*100)+'%';
        priceLabel.textContent = "€ "+price.toFixed(2);
    }

    ramInput.addEventListener('input', updateCpuAndPrice);
    updateCpuAndPrice(); // aggiorna valori iniziali
});
</script>


<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
