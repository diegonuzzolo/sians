<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'includes/csrf.php';
require 'stripe-php/init.php';

csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId)
    die("Accesso negato.");

$stmt = $pdo->prepare("SELECT email, stripe_customer_id FROM users WHERE id=?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user)
    die("Utente non trovato.");
$email = $user['email'];
$stripeCustomerId = $user['stripe_customer_id'] ?? null;

$error = '';
$checkoutSessionId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planCode = $_POST['plan_code'] ?? 'plan_4gb';

    // Recupera piano
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        die("Piano non valido.");

    // Verifica se l'utente ha già subscription
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE stripe_customer_id=? AND stripe_subscription_id=? LIMIT 1");
    $stmt->execute([$userId, $plan['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($subscription) {
        $error = "Hai già slot disponibili per questo piano.";
    } else {
        // Stripe checkout
        \Stripe\Stripe::setApiKey("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");

        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'customer_email' => $email,
                'line_items' => [
                    [
                        'price' => $plan['stripe_price_id'],
                        'quantity' => 1
                    ]
                ],
                'mode' => 'subscription',
                'success_url' => "https://sians.it/add_server.php?success=1&plan_code=" . urlencode($planCode) . "&session_id={CHECKOUT_SESSION_ID}",
                'cancel_url' => "https://sians.it/add_server.php?cancel=1"
            ]);
            header("Location: " . $session->url);
            exit;
        } catch (Exception $e) {
            $error = "Errore Stripe: " . $e->getMessage();
        }
    }
}

// Dopo pagamento Stripe → registra subscription
if (isset($_GET['success'], $_GET['plan_code'], $_GET['session_id'])) {
    $planCode = $_GET['plan_code'];
    $checkoutSessionId = $_GET['session_id'];

    // Recupera il piano
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        die("Piano non trovato.");

    // Recupera dati da Stripe
    \Stripe\Stripe::setApiKey("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");
    $session = \Stripe\Checkout\Session::retrieve($checkoutSessionId);
    $stripeSubscription = \Stripe\Subscription::retrieve($session->subscription);

    include "sync_server_slots.php";

    // Redireziona alla dashboard
    header("Location: dashboard.php?subscription=success");
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
const csrfToken = '<?= $_SESSION["csrf_token"] ?? "" ?>';
const creatingServers = {}; // Stato creazione per piano

// --- FUNZIONE CREAZIONE SERVER ---
function createServerSlot(planCode, btnElement) {
    if (creatingServers[planCode]) return;
    creatingServers[planCode] = true;

    btnElement.disabled = true;
    const originalText = btnElement.textContent;
    btnElement.textContent = 'Creazione in corso...';

    let serverName = prompt("Inserisci il nome del tuo server:");
    if (!serverName) {
        btnElement.disabled = false;
        btnElement.textContent = originalText;
        creatingServers[planCode] = false;
        return;
    }

    fetch(`new_server.php?plan_code=${encodeURIComponent(planCode)}&server_name=${encodeURIComponent(serverName)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert("Server creato con successo! VMID: " + data.vmid + " IP: " + data.ip);
                addServerCard(data.server_id, serverName, data.vmid, data.ip);
            } else {
                alert("Errore: " + data.message);
            }
        })
        .catch(err => console.error(err))
        .finally(() => {
            creatingServers[planCode] = false;
            refreshSlots();
        });
}

// --- AGGIUNGE IL SERVER ALLA LISTA ---
function addServerCard(serverId, serverName, vmid, ip) {
    const serverList = document.getElementById('server-list');
    if (!serverList) return;

    const div = document.createElement('div');
    div.className = 'col-md-4 mb-3';
    div.id = 'server-' + serverId;
    div.innerHTML = `
        <div class="card card-server p-3 shadow">
            <div class="server-name">${serverName}</div>
            <div class="server-ip">VMID: ${vmid} | IP: ${ip}</div>
            <span id="status-${serverId}" class="status-badge status-stopped">Spento</span>
            <div class="server-actions mt-2">
                <button id="start-${serverId}" class="btn btn-success btn-server" onclick="startServer(${serverId})">Avvia</button>
                <button id="stop-${serverId}" class="btn btn-danger btn-server" onclick="stopServer(${serverId})" disabled>Ferma</button>
            </div>
            <div class="server-actions mt-2">
                <a href="manage_server.php?id=${serverId}" class="btn btn-primary btn-server">Gestisci</a>
                <button class="btn btn-outline-danger btn-server" onclick="deleteServer(${serverId}, '${vmid}')">Elimina</button>
            </div>
        </div>
    `;
    serverList.prepend(div);
}

// --- ELIMINA SERVER ---
function deleteServer(serverId, vmid) {
    if (!confirm('Sei sicuro di voler eliminare questo server?')) return;

    fetch(`delete_server.php?vmid=${vmid}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('server-' + serverId)?.remove();
                refreshSlots();
            } else {
                alert("Errore eliminazione server: " + data.message);
            }
        })
        .catch(err => console.error(err));
}

// --- CONTROLLO SERVER (start/stop) ---
function controlServer(serverId, action) {
    fetch('ajax_server_control.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=' + encodeURIComponent(action) + '&id=' + serverId + '&csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.status) {
            const statusEl = document.getElementById('status-' + serverId);
            if (statusEl) {
                statusEl.textContent = (data.status==='running')?'Attivo':'Spento';
                statusEl.className = 'status-badge ' + (data.status==='running'?'status-running':'status-stopped');
            }
            updateButtons(serverId, data.status);
        }
    })
    .catch(err => console.error(err));
}

function startServer(serverId){ controlServer(serverId,'start'); }
function stopServer(serverId){ controlServer(serverId,'stop'); }

function updateButtons(serverId,status){
    const startBtn=document.getElementById('start-'+serverId);
    const stopBtn=document.getElementById('stop-'+serverId);
    if(!startBtn || !stopBtn) return;
    if(status==='running'){ startBtn.disabled=true; stopBtn.disabled=false; }
    else{ startBtn.disabled=false; stopBtn.disabled=true; }
}

// --- AGGIORNA GLI SLOT DISPONIBILI ---
function refreshSlots() {
    fetch('ajax_check_slots.php')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('slots-container');
            container.innerHTML = ''; // Pulisce tutte le card
            for (const planCode in data) {
                const slots = data[planCode];
                if (slots > 0) {
                    const div = document.createElement('div');
                    div.className = 'col-md-4 mb-3 card-slot';
                    div.dataset.planCode = planCode;
                    div.innerHTML = `
                        <div class="card shadow p-3 rounded card-server">
                            <div class="card-header-plan">${planCode}</div>
                            <p class="slot-count">${slots} slot disponibili</p>
                            <a href="javascript:void(0);" onclick="createServerSlot('${planCode}', this)" class="btn btn-success btn-slot">Crea Server</a>
                        </div>
                    `;
                    container.appendChild(div);
                }
            }
        })
        .catch(err => console.error(err));
}

// --- CHIAMATA INIZIALE AL CARICAMENTO PAGINA ---
document.addEventListener('DOMContentLoaded', () => {
    refreshSlots();
});
</script>

<!-- Aggiungi Animate.css per le animazioni -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />




<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>