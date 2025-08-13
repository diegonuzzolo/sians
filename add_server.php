<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

$userId = $_SESSION['user_id'] ?? 0;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Dati dal form ---
    $ramGb = intval($_POST['ram_gb'] ?? 2);
    $storageGb = intval($_POST['storage_gb'] ?? 20);
    $server_id = 123; // id del server nel DB

    // --- Trova un VMID libero ---
    $nextVmid = 100;
    do {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM containers WHERE proxmox_vmid = ?");
        $stmt->execute([$nextVmid]);
        if ($stmt->fetchColumn() == 0) break;
        $nextVmid++;
    } while(true);
    $vmid = $nextVmid;

    // --- Inserisci nel DB ---
    $stmt = $pdo->prepare("INSERT INTO containers (proxmox_vmid, ram_gb, storage_gb) VALUES (?, ?, ?)");
    $stmt->execute([$vmid, $ramGb, $storageGb]);

    // --- Crea container su Proxmox ---
    createLxcContainer($vmid, $ramGb, $storageGb);

    // --- Lancia setup_server.sh in background con sshpass ---
    $vmIp = 'IP_DEL_CONTAINER'; // sostituisci con l'IP del container appena creato
    $setupCommand = '/root/setup_server.sh'; // percorso dello script sul container
    shell_exec("sshpass -f /var/www/.sshpass_pw ssh -o StrictHostKeyChecking=no root@$vmIp '$setupCommand' > /dev/null 2>&1 &");

    // --- Aggiorna subito lo stato a 'installing' ---
    $stmt = $pdo->prepare("UPDATE servers SET status = 'installing' WHERE id = ?");
    $stmt->execute([$server_id]);

    // --- Redirect alla dashboard ---
    header("Location: dashboard.php");
    exit;
}

    ?>


<?php include "includes/header.php"; ?>
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
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($_POST['form_token'] ?? '') ?>">

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
        const ramInput = document.getElementById('ram_gb');
        const ramDisplay = document.getElementById('ramDisplay');
        const cpuLabel = document.getElementById('cpuLabel');
        const cpuInput = document.getElementById('cpu_cores');
        const cpuProgress = document.getElementById('cpuProgress');
        const priceLabel = document.getElementById('priceLabel');

        function updateCpuAndPrice() {
            let ram = parseInt(ramInput.value) || 2;
            ramDisplay.textContent = ram + ' GB';

            let cores = 2, price = 6.5 * 0.85;
            if (ram <= 2) { cores = 2; price = 6.5*0.85; }
            else if (ram <= 4) { cores = 2; price = 10*0.85; }
            else if (ram <= 6) { cores = 3; price = 15*0.85; }
            else if (ram <= 8) { cores = 4; price = 21.5*0.85; }
            else if (ram <= 16) { cores = 6; price = 35*0.85; }
            else { cores = 8; price = 57.5*0.85; }

            cpuLabel.textContent = cores;
            cpuInput.value = cores;
            cpuProgress.style.width = (cores/8*100)+'%';
            priceLabel.textContent = "€ "+price.toFixed(2);
        }

        ramInput.addEventListener('input', updateCpuAndPrice);
        document.getElementById('createServerForm').addEventListener('submit', function() {
            document.getElementById('createServerBtn').disabled = true;
            document.getElementById('createServerBtn').innerText = 'Creazione in corso...';
        });

        updateCpuAndPrice();
    </script>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    