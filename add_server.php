<?php
require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php'; // proxmoxLogin, createLxcContainer, startContainer, waitForContainer, installBedrockServer

$userId = $_SESSION['user_id'] ?? 0;
$defaultRam = 4;
$error = null;

// Token anti-doppio submit
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['form_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        $error = "Richiesta duplicata o token non valido.";
    } else {
        unset($_SESSION['form_token']); // Invalida token subito

        $serverName = trim($_POST['server_name'] ?? '');
        $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);
        $ramGb = min($ramGb, 20);

        if (!$serverName) {
            $error = "Inserisci un nome valido per il server.";
        } elseif ($ramGb < 2) {
            $error = "Inserisci almeno 2 GB di RAM.";
        }

        if (!$error) {
            try {
                // Calcolo CPU e prezzo
                $cpuCores = calculateCpuCores($ramGb);
                $price = calculatePrice($ramGb);

                // VMID e IP
                $stmt = $pdo->query("SELECT MAX(proxmox_containerId) AS max_vmid FROM containers");
                $vmid = max(101, (int)$stmt->fetchColumn() + 1);
                $privateIp = getNextFreePrivateIp($pdo);
                $storageGb = 64;

                // Login Proxmox
                $auth = proxmoxLogin();

                // 1️⃣ Crea container
                $result = createLxcContainer($vmid, $cpuCores, $ramGb*1024, $storageGb, $privateIp, $auth);

                // 2️⃣ Attendi che il container sia pronto
                waitForContainer($vmid, $auth, 120, 2);

                // 3️⃣ Avvia container
                startContainer($vmid, $auth);

                // 4️⃣ Salva container nel DB
                $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, cpu_cores, ram_mb, assigned_user_id, storage_gb, private_ip) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vmid, $cpuCores, $ramGb*1024, $userId, $storageGb, $privateIp]);
                $containerId = $pdo->lastInsertId();

                // 5️⃣ Crea entry server
                $status = 'installing';
                $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
                $stmt2->execute([$serverName, $userId, $containerId, $vmid, $status, 'bedrock', $cpuCores, $ramGb*1024, $price]);
                $serverId = $pdo->lastInsertId();

                // 6️⃣ Aggiorna container con server ID
                $stmt3 = $pdo->prepare("UPDATE containers SET assigned_server_id = ? WHERE id = ?");
                $stmt3->execute([$serverId, $containerId]);

                // 7️⃣ Installa Bedrock server
                installBedrockServer($privateIp, $ramGb*1024, $pdo, $serverId);

                // Rigenera token per la prossima volta
                $_SESSION['form_token'] = bin2hex(random_bytes(32));

                // 8️⃣ Reindirizza a install_wait
                header("Location: install_wait.php?server_id=".$serverId);
                exit;

            } catch(Exception $e) {
                $error = "Errore creazione server: ".$e->getMessage();
            }
        }
    }
}
?>

<!-- HTML e Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include 'includes/header.php'; ?>
<div class="container my-5" style="max-width: 480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="add_server.php" id="createServerForm" class="bg-dark p-4 rounded-4 shadow-lg">
        <div class="mb-4">
            <label for="server_name" class="form-label text-white fw-semibold fs-5">Nome Server</label>
            <input type="text" name="server_name" id="server_name"
                class="form-control form-control-lg bg-secondary text-white border-0 shadow-sm" required
                placeholder="Es. Il Mio Server" value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-0 bg-bedrock shadow p-3 rounded-4">
            <div class="card-header bg-primary rounded-3 py-2 text-white text-center fs-5 fw-bold">
                Configura le Risorse
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label for="ram_gb" class="form-label text-white fw-semibold">RAM (GB) <span id="ramDisplay"
                            class="fw-bold"></span></label>
                    <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1"
                        value="<?= htmlspecialchars($_POST['ram_gb'] ?? 4) ?>">
                    <div class="d-flex justify-content-between text-white small mt-1">
                        <span>2GB</span>
                        <span>20GB</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-white fw-semibold">CPU Cores: <span id="cpuLabel"
                            class="fs-5"></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="2">
                    <div class="progress" style="height: 8px;">
                        <div id="cpuProgress" class="progress-bar bg-info" role="progressbar"
                            style="width: 0%; transition: width 0.4s;"></div>
                    </div>
                </div>

                <div class="mb-0 text-white fw-semibold fs-5 text-center">
                    <span id="priceLabel">€ 0.00</span>
                </div>
            </div>
        </div>

        <!-- Token anti-duplicazione -->
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($form_token) ?>">

        <button id="createServerBtn" type="submit" class="btn btn-success mt-4 w-100 fw-bold shadow-sm"
            style="font-size: 1.2rem;">
            Crea Server
        </button>
    </form>
</div>

<style>
.bg-bedrock { background-color: #003f54 !important; }
.text-bedrock { color: #00c8ff; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); }
.form-control-lg { border-radius: 12px; }
.form-range { cursor: pointer; }
.btn-success { background-color: #00c8ff; border: none; transition: background-color 0.3s ease; }
.btn-success:hover, .btn-success:focus { background-color: #0099cc; box-shadow: 0 0 12px #00c8ffaa; }
label { user-select: none; }
</style>

<script>
const form = document.getElementById('createServerForm');
const submitBtn = document.getElementById('createServerBtn');
const ramInput = document.getElementById('ram_gb');
const ramDisplay = document.getElementById('ramDisplay');
const cpuLabel = document.getElementById('cpuLabel');
const cpuInput = document.getElementById('cpu_cores');
const cpuProgress = document.getElementById('cpuProgress');
const priceLabel = document.getElementById('priceLabel');

function updateCpuAndPrice() {
    let ram = parseInt(ramInput.value) || 2;
    if (ram > 20) ram = 20;
    ramDisplay.textContent = ram + ' GB';

    let cores = 2, price = 6.5*0.85;
    if (ram <= 2) { cores=2; price=6.5*0.85; }
    else if (ram <= 4) { cores=2; price=10*0.85; }
    else if (ram <= 6) { cores=3; price=15*0.85; }
    else if (ram <= 8) { cores=4; price=21.5*0.85; }
    else if (ram <= 16) { cores=6; price=35*0.85; }
    else { cores=8; price=57.5*0.85; }

    cpuLabel.textContent = cores;
    cpuInput.value = cores;
    cpuProgress.style.width = (cores/8*100)+'%';
    priceLabel.textContent = "€ "+price.toFixed(2);
}

ramInput.addEventListener('input', updateCpuAndPrice);
form.addEventListener('submit', ()=>{ submitBtn.disabled=true; submitBtn.innerText='Creazione in corso...'; });
updateCpuAndPrice();
</script>

<?php include("includes/footer.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
