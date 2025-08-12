<?php
include(__DIR__ . "/auth_check.php");
require 'config/config.php';
include("includes/proxmox_api.php");

$userId = $_SESSION['user_id'] ?? 0;

$defaultRam = 4;
$error = null;

function calculateCpuCores(int $ramGb): int {
    if ($ramGb <= 2) {
        return 2;
    } elseif ($ramGb <= 4) {
        return 2;
    } elseif ($ramGb <= 6) {
        return 3;
    } elseif ($ramGb <= 8) {
        return 4;
    } elseif ($ramGb <= 16) {
        return 6;
    } else {
        return 8;
    }
}

function calculatePrice(int $ramGb): float {
    $priceTable = [
        2 => 6.5 * 0.85,
        4 => 10 * 0.85,
        6 => 15 * 0.85,
        8 => 21.5 * 0.85,
        16 => 35 * 0.85,
        32 => 57.5 * 0.85,
    ];

    if ($ramGb <= 2) {
        return $priceTable[2];
    } elseif ($ramGb <= 4) {
        return $priceTable[4];
    } elseif ($ramGb <= 6) {
        return $priceTable[6];
    } elseif ($ramGb <= 8) {
        return $priceTable[8];
    } elseif ($ramGb <= 16) {
        return $priceTable[16];
    } else {
        return $priceTable[32];
    }
}

// Funzione per trovare primo IP libero in range
function findFirstFreeIp(array $usedIps, string $subnetBase, int $startIp, int $endIp): ?string {
    for ($i = $startIp; $i <= $endIp; $i++) {
        $candidate = $subnetBase . $i;
        if (!in_array($candidate, $usedIps)) {
            return $candidate;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serverName = trim($_POST['server_name'] ?? '');
    $ramGb = intval($_POST['ram_gb'] ?? $defaultRam);

    // Limite massimo RAM a 20 GB
    if ($ramGb > 20) {
        $ramGb = 20;
    }

    $cpuCores = calculateCpuCores($ramGb);
    $price = calculatePrice($ramGb);

    // Validazioni base
    if (empty($serverName)) {
        $error = "Inserisci un nome valido per il server.";
    } elseif ($ramGb < 2) {
        $error = "Inserisci una quantità di RAM valida (minimo 2 GB).";
    }

    if (!$error) {
        try {
            // Recupera IP privati già assegnati
            $usedPrivateIps = $pdo->query("SELECT private_ip FROM containers WHERE private_ip IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $subnetBase = '192.168.1.';
            $startIp = 101;
            $endIp = 150;

            $privateIp = findFirstFreeIp($usedPrivateIps, $subnetBase, $startIp, $endIp);
            if (!$privateIp) {
                throw new Exception("Nessun IP privato libero disponibile.");
            }

            $node = 'pve'; // Cambia con il tuo nodo reale Proxmox

            // Ottieni VMID disponibile
            $nextIdResp = proxmoxApiRequest('GET', '/cluster/nextid');
            $vmid = $nextIdResp['data'] ?? null;
            if (!$vmid) throw new Exception("Impossibile ottenere vmid disponibile");

            $swapMb = max(512, min($ramGb * 1024 / 2, 8192)); // metà della RAM, minimo 512 MB, max 8192 MB

            $hostname = 'mcserver-' . time();

            // Crea container con IP privato assegnato
            $containerData = proxmoxApiRequest('POST', "/nodes/$node/lxc", [
                'vmid' => $vmid,
                'ostemplate' => 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst',
                'hostname' => $hostname,
                'memory' => $ramGb * 1024,
                'swap' => intval($swapMb),
                'cores' => $cpuCores,
                'rootfs' => 'local-lvm:64',
                'net0' => "name=eth0,bridge=vmbr0,ip={$privateIp}/24,gw=192.168.1.254",
                'password' => 'Lgu8330Serve6' // valuta di usare gestione password sicura
            ]);

            // Recupera IP container (retry se serve)
            $ip = null;
            $maxRetries = 10;
            $retry = 0;
            while ($retry < $maxRetries && !$ip) {
                sleep(3);
                $status = proxmoxApiRequest('GET', "/nodes/$node/lxc/$vmid/status/current");
                $ip = $status['network']['eth0']['ip'] ?? null;
                $retry++;
            }

            if (!$ip) $ip = 'IP non disponibile';

            // Inserisci container nel DB con private_ip
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, private_ip, hostname, cpu_cores, ram_mb) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$vmid, $privateIp, $hostname, $cpuCores, $ramGb * 1024]);
            $containerId = $pdo->lastInsertId();

            // Inserisci server nel DB, incluso il prezzo
            $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at, price) VALUES (?, ?, ?, ?, 'stopped', 'bedrock', ?, ?, NOW(), NOW(), ?)");
            $stmt2->execute([$serverName, $userId, $containerId, $vmid, $cpuCores, $ramGb * 1024, $price]);
            $serverId = $pdo->lastInsertId();

            // Aggiorna container assegnato
            $stmt3 = $pdo->prepare("UPDATE containers SET assigned_user_id = ?, assigned_server_id = ? WHERE id = ?");
            $stmt3->execute([$userId, $serverId, $containerId]);

            header("Location: dashboard.php?created=1");
            exit;

        } catch (Exception $e) {
            $error = "Errore nella creazione del server: " . $e->getMessage();
        }
    }
}
?>
<?php include("includes/header.php"); ?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-5" style="max-width: 480px;">
    <h2 class="mb-4 text-center text-bedrock display-5 fw-bold">🎮 Crea un nuovo server</h2>

    <!-- Qui va un eventuale alert errore -->

    <form method="POST" action="" class="bg-dark p-4 rounded-4 shadow-lg">
        <div class="mb-4">
            <label for="server_name" class="form-label text-white fw-semibold fs-5">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control form-control-lg bg-secondary text-white border-0 shadow-sm" required
                placeholder="Es. Il Mio Server" value="">
        </div>

        <div class="card border-0 bg-bedrock shadow p-3 rounded-4">
            <div class="card-header bg-primary rounded-3 py-2 text-white text-center fs-5 fw-bold">
                Configura le Risorse
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label for="ram_gb" class="form-label text-white fw-semibold">RAM (GB)</label>
                    <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1" value="4">
                    <div class="d-flex justify-content-between text-white small mt-1">
                        <span>2GB</span>
                        <span>20GB</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-white fw-semibold">CPU Cores: <span id="cpuLabel" class="fs-5"></span></label>
                    <input type="hidden" name="cpu_cores" id="cpu_cores" value="2">
                    <div class="progress" style="height: 8px;">
                        <div id="cpuProgress" class="progress-bar bg-info" role="progressbar" style="width: 0%; transition: width 0.4s;"></div>
                    </div>
                </div>

                <div class="mb-0 text-white fw-semibold fs-5 text-center">
                     <span id="priceLabel">€ 0.00</span>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success mt-4 w-100 fw-bold shadow-sm" style="font-size: 1.2rem;">Crea Server</button>
    </form>
</div>

<style>
  .bg-bedrock {
    background-color: #003f54 !important;
  }
  .text-bedrock {
    color: #00c8ff;
    text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
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
  .btn-success:hover, .btn-success:focus {
    background-color: #0099cc;
    box-shadow: 0 0 12px #00c8ffaa;
  }
  label {
    user-select: none;
  }
</style>

<script>
  const ramInput = document.getElementById('ram_gb');
  const cpuLabel = document.getElementById('cpuLabel');
  const cpuInput = document.getElementById('cpu_cores');
  const cpuProgress = document.getElementById('cpuProgress');
  const priceLabel = document.getElementById('priceLabel');

  function updateCpuAndPrice() {
    let ram = parseInt(ramInput.value) || 2;
    if (ram > 20) ram = 20;

    let cores = 2;
    let price = 6.5 * 0.85; // default for 2GB

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

    // barra progress CPU (max 8 core)
    let percent = (cores / 8) * 100;
    cpuProgress.style.width = percent + '%';

    priceLabel.textContent = "€ " + price.toFixed(2);
  }

  ramInput.addEventListener('input', updateCpuAndPrice);

  // inizializza al caricamento pagina
  updateCpuAndPrice();
</script>


<?php include("includes/footer.php"); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
