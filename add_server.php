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
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id=? AND plan_id=? LIMIT 1");
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
    \Stripe\Stripe::setApiKey("sk_live_...");
    $session = \Stripe\Checkout\Session::retrieve($checkoutSessionId);
    $stripeSubscription = \Stripe\Subscription::retrieve($session->subscription);

    // Salva/aggiorna la subscription nel DB
    $stmt = $pdo->prepare("INSERT INTO subscriptions 
        (user_id, plan_id, stripe_customer_id, stripe_subscription_id, status, current_period_end, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status), 
            current_period_end = VALUES(current_period_end)");

    $stmt->execute([
        $userId,
        $plan['id'],
        $stripeSubscription->customer,
        $stripeSubscription->id,
        $stripeSubscription->status,
        date('Y-m-d H:i:s', $stripeSubscription->current_period_end)
    ]);

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