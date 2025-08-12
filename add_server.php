<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config/config.php';
require_once 'auth_check.php';


$userId = $_SESSION['user_id'] ?? 0;
$defaultRam = 4;
$error = null;

// --- Token anti-doppio submit ---
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$form_token = $_SESSION['form_token'];

// Funzione per ottenere IP libero (la tua originale)
function getNextFreePrivateIp(PDO $pdo): string
{
    $baseIp = '192.168.1.';
    $start = 101;
    $end = 254;

    $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip LIKE '192.168.1.%'");
    $usedIps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $usedLastOctets = [];
    foreach ($usedIps as $ip) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $usedLastOctets[] = (int) $parts[3];
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if (!in_array($i, $usedLastOctets)) {
            return $baseIp . $i;
        }
    }

    return $baseIp . $start;
}

function calculateCpuCores(int $ramGb): int
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

function calculatePrice(int $ramGb): float
{
    $priceTable = [
        2 => 6.5 * 0.85,
        4 => 10 * 0.85,
        6 => 15 * 0.85,
        8 => 21.5 * 0.85,
        16 => 35 * 0.85,
        32 => 57.5 * 0.85,
    ];
    if ($ramGb <= 2)
        return $priceTable[2];
    if ($ramGb <= 4)
        return $priceTable[4];
    if ($ramGb <= 6)
        return $priceTable[6];
    if ($ramGb <= 8)
        return $priceTable[8];
    if ($ramGb <= 16)
        return $priceTable[16];
    return $priceTable[32];
}

function proxmoxLogin()
{
    $url = PROXMOX_API_URL . '/access/ticket';
    $data = [
        'username' => PROXMOX_USERNAME,
        'password' => PROXMOX_PASSWORD,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        error_log("CURL login error: $err");
        throw new Exception('Errore CURL login: ' . $err);
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['data']['ticket']) || !isset($json['data']['CSRFPreventionToken'])) {
        error_log("Proxmox login fallito: $response");
        throw new Exception('Login Proxmox fallito: ' . $response);
    }
    error_log("Proxmox login OK: ticket ricevuto");
    return [
        'ticket' => $json['data']['ticket'],
        'csrf' => $json['data']['CSRFPreventionToken']
    ];
}


function createLxcContainer($vmid, $cpu, $ramMb, $storageGb, $privateIp, $auth)
{
    $swapMb = intval($ramMb * 1.5);

    $postData = [
        'vmid' => $vmid,
        'hostname' => "mcserver-$vmid",
        'storage' => PROXMOX_STORAGE,
        'ostemplate' => 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst',
        'memory' => $ramMb,
        'swap' => $swapMb,
        'cores' => $cpu,
        'rootfs' => PROXMOX_STORAGE . ':' . $storageGb,
        'net0' => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=192.168.1.254",
        'password' => 'MinecraftStrongPass123!',
    ];

    error_log("Creazione container Proxmox - dati POST: " . print_r($postData, true));

    $url = PROXMOX_API_URL . "/nodes/" . PROXMOX_NODE . "/lxc";
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "CSRFPreventionToken: {$auth['csrf']}",
        "Cookie: PVEAuthCookie={$auth['ticket']}",
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        error_log("CURL createLxc error: $err");
        throw new Exception('Errore CURL createLxc: ' . $err);
    }
    curl_close($ch);

    error_log("Risposta createLxcContainer: $response");

    $json = json_decode($response, true);
    if (isset($json['errors']) || !isset($json['data'])) {
        error_log("Errore creazione container Proxmox: " . json_encode($json));
        throw new Exception('Errore creazione container: ' . json_encode($json));
    }

    return $json['data'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('POST ricevuto: ' . print_r($_POST, true));
    error_log('Token sessione: ' . ($_SESSION['form_token'] ?? 'manca'));
    error_log('Token POST: ' . ($_POST['form_token'] ?? 'manca'));

    if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
        $error = "Richiesta duplicata o token non valido.";
    } else {
        // Invalida il token subito per prevenire doppio submit
        unset($_SESSION['form_token']);

        $serverName = trim($_POST['server_name'] ?? '');
        $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);
        if ($ramGb > 20) $ramGb = 20;

        $cpuCores = calculateCpuCores($ramGb);
        $price = calculatePrice($ramGb);

        if (empty($serverName)) {
            $error = "Inserisci un nome valido per il server.";
        } elseif ($ramGb < 2) {
            $error = "Inserisci una quantità di RAM valida (minimo 2 GB).";
        }

        if (!$error) {
            try {
                $stmt = $pdo->query("SELECT MAX(proxmox_containerId) AS max_vmid FROM containers");
                $maxVmid = (int) $stmt->fetchColumn();
                $vmid = $maxVmid > 100 ? $maxVmid + 1 : 101;

                $nextIp = getNextFreePrivateIp($pdo);
                $storageGb = 64;

                $auth = proxmoxLogin();

                $result = createLxcContainer($vmid, $cpuCores, $ramGb * 1024, $storageGb, $nextIp, $auth);

                if (!$result) {
                    throw new Exception('Creazione container fallita senza errore specifico.');
                }

                $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, cpu_cores, ram_mb, assigned_user_id, storage_gb, private_ip) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vmid, $cpuCores, $ramGb * 1024, $userId, $storageGb, $nextIp]);
                $containerId = $pdo->lastInsertId();

                $status = 'not_installed';

                $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
                $stmt2->execute([$serverName, $userId, $containerId, $vmid, $status, 'bedrock', $cpuCores, $ramGb * 1024, $price]);
                $serverId = $pdo->lastInsertId();

                $stmt3 = $pdo->prepare("UPDATE containers SET assigned_server_id = ? WHERE id = ?");
                $stmt3->execute([$serverId, $containerId]);

                // Rigenera token per la prossima volta
                $_SESSION['form_token'] = bin2hex(random_bytes(32));

                header("Location: dashboard.php?created=1");
                exit;

            } catch (Exception $e) {
                $error = "Errore nella creazione del server: " . $e->getMessage();
            }
        }
    }
}

?>


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
                    <label for="ram_gb" class="form-label text-white fw-semibold">RAM (GB) <span id="ramDisplay" class="fw-bold"></span></label>
                    <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1"
                        value="<?= htmlspecialchars($_POST['ram_gb'] ?? 4) ?>">
                    <div class="d-flex justify-content-between text-white small mt-1">
                        <span>2GB</span>
                        <span>20GB</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-white fw-semibold">CPU Cores: <span id="cpuLabel" class="fs-5"></span></label>
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
    .bg-bedrock {
        background-color: #003f54 !important;
    }

    .text-bedrock {
        color: #00c8ff;
        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.8);
    }

    .form-control-lg {
        border-radius: 12px;
    }

    .form-range {
        cursor: pointer;
    }

    .btn-success {
        background-color: #00c8ff;
        border: none;
        transition: background-color 0.3s ease;
    }

    .btn-success:hover,
    .btn-success:focus {
        background-color: #0099cc;
        box-shadow: 0 0 12px #00c8ffaa;
    }

    label {
        user-select: none;
    }
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

        let cores = 2;
        let price = 6.5 * 0.85;

        if (ram <= 2) {
            cores = 2;
            price = 6.5 * 0.85;
        } else if (ram <= 4) {
            cores = 2;
            price = 10 * 0.85;
        } else if (ram <= 6) {
            cores = 3;
            price = 15 * 0.85;
        } else if (ram <= 8) {
            cores = 4;
            price = 21.5 * 0.85;
        } else if (ram <= 16) {
            cores = 6;
            price = 35 * 0.85;
        } else {
            cores = 8;
            price = 57.5 * 0.85;
        }

        cpuLabel.textContent = cores;
        cpuInput.value = cores;

        let percent = (cores / 8) * 100;
        cpuProgress.style.width = percent + '%';

        priceLabel.textContent = "€ " + price.toFixed(2);
    }

    ramInput.addEventListener('input', updateCpuAndPrice);

    form.addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Creazione in corso...';
    });

    updateCpuAndPrice();
</script>

<?php include("includes/footer.php"); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
    </html>