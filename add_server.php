<?php
include("auth_check.php");
require 'config/config.php';

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

function ipIncrement($ip) {
    $parts = explode('.', $ip);
    for ($i = 3; $i >= 0; $i--) {
        if (++$parts[$i] <= 255) {
            break;
        }
        $parts[$i] = 0;
    }
    return implode('.', $parts);
}

function proxmoxCurlRequest($method, $endpoint, $data = null) {
    $url = PROXMOX_API_URL . $endpoint;

    $headers = [
        "Authorization: PVEAPIToken=" . PROXMOX_API_TOKEN_NAME . "=" . PROXMOX_API_TOKEN_VALUE,
    ];

    $ch = curl_init($url);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // attenzione in produzione

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Errore CURL: ' . curl_error($ch));
    }
    curl_close($ch);

    return json_decode($response, true);
}


function createLxcContainer($vmid, $cpu, $ramMb, $storageGb, $privateIp) {
    $postData = [
    'vmid' => $vmid,
    'hostname' => "mcserver-$vmid",
    'storage' => PROXMOX_TEMPLATE_STORAGE,
    'ostemplate' => PROXMOX_TEMPLATE_STORAGE . ':vztmpl/' . PROXMOX_TEMPLATE_NAME,
    'memory' => $ramMb,
    'cores' => $cpu,
    'rootfs' => $storageGb . "G",
    'net0' => "name=eth0,bridge=vmbr0,ip=$privateIp/24,gw=192.168.1.254",
    'password' => 'MinecraftStrongPass123!',
];


    $result = proxmoxCurlRequest('POST', "/nodes/" . PROXMOX_NODE . "/lxc", $postData);

    if (isset($result['errors']) || !isset($result['data'])) {
        throw new Exception('Errore creazione container: ' . json_encode($result));
    }

    return $result['data'];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Ottieni vmid disponibile da DB o Proxmox API
            $stmt = $pdo->query("SELECT MAX(proxmox_containerId) AS max_vmid FROM containers");
            $maxVmid = (int) $stmt->fetchColumn();
            $vmid = $maxVmid > 100 ? $maxVmid + 1 : 101; // parte da 101

            // Calcola prossimo IP privato
            $stmt = $pdo->query("SELECT private_ip FROM containers WHERE private_ip IS NOT NULL ORDER BY id DESC LIMIT 1");
            $lastIp = $stmt->fetchColumn();

            if ($lastIp) {
                $nextIp = ipIncrement($lastIp);
                $parts = explode('.', $nextIp);
                if ($parts[0] != 192 || $parts[1] != 168 || $parts[2] != 1 || $parts[3] > 254) {
                    $nextIp = '192.168.1.101';
                }
            } else {
                $nextIp = '192.168.1.101';
            }


            // Storage fisso 64 GB
            $storageGb = 64;

            createLxcContainer($vmid, $cpuCores, $ramGb * 1024, $storageGb, $nextIp);


            // Salva container nel DB con IP
            $stmt = $pdo->prepare("INSERT INTO containers (proxmox_containerId, cpu_cores, ram_mb, assigned_user_id, storage_gb, private_ip) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vmid, $cpuCores, $ramGb * 1024, $userId, $storageGb, $nextIp]);
            $containerId = $pdo->lastInsertId();

            // Stato iniziale server non installato
            $status = 'not_installed';

            $stmt2 = $pdo->prepare("INSERT INTO servers (name, user_id, container_id, proxmox_containerId, status, type, cpu_cores, ram_mb, created_at, updated_at, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)");
            $stmt2->execute([$serverName, $userId, $containerId, $vmid, $status, 'bedrock', $cpuCores, $ramGb * 1024, $price]);
            $serverId = $pdo->lastInsertId();

            // Aggiorna container con server_id
            $stmt3 = $pdo->prepare("UPDATE containers SET assigned_server_id = ? WHERE id = ?");
            $stmt3->execute([$serverId, $containerId]);

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

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="bg-dark p-4 rounded-4 shadow-lg">
        <div class="mb-4">
            <label for="server_name" class="form-label text-white fw-semibold fs-5">Nome Server</label>
            <input type="text" name="server_name" id="server_name" class="form-control form-control-lg bg-secondary text-white border-0 shadow-sm" required
                placeholder="Es. Il Mio Server" value="<?= htmlspecialchars($_POST['server_name'] ?? '') ?>">
        </div>

        <div class="card border-0 bg-bedrock shadow p-3 rounded-4">
            <div class="card-header bg-primary rounded-3 py-2 text-white text-center fs-5 fw-bold">
                Configura le Risorse
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label for="ram_gb" class="form-label text-white fw-semibold">RAM (GB)</label>
                    <input type="range" name="ram_gb" id="ram_gb" class="form-range" min="2" max="20" step="1" value="<?= htmlspecialchars($_POST['ram_gb'] ?? 4) ?>">
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

    let percent = (cores / 8) * 100;
    cpuProgress.style.width = percent + '%';

    priceLabel.textContent = "€ " + price.toFixed(2);
  }

  ramInput.addEventListener('input', updateCpuAndPrice);
  updateCpuAndPrice();
</script>

<?php include("includes/footer.php"); ?>
