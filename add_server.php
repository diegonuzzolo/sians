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

// NUOVO BLOCCO: Recupera i piani già posseduti dall'utente
$stmtOwned = $pdo->prepare("
    SELECT p.code
    FROM server_slots ss
    JOIN plans p ON ss.plan_id = p.stripe_price_id
    WHERE ss.user_id = ?
");
$stmtOwned->execute([$userId]);
$ownedPlanCodes = $stmtOwned->fetchAll(PDO::FETCH_COLUMN);

// NUOVO BLOCCO: Controlla le risorse totali disponibili sul server
$stmtRes = $pdo->query("SELECT * FROM resources WHERE id = 1");
$resources = $stmtRes->fetch(PDO::FETCH_ASSOC);

$availableRamGb = ($resources['total_ram_gb'] * 1024 - $resources['used_ram_mb']) / 1024;
$availableCores = $resources['total_cpu_cores'] - $resources['used_cpu_cores'];
$availableStorageGb = $resources['total_storage_gb'] - $resources['used_storage_gb'];

// Recupera tutti i piani per confrontarli con le risorse disponibili
$stmtPlans = $pdo->query("SELECT code, ram_gb, cpu_cores, storage_gb FROM plans WHERE active = 1");
$allPlans = $stmtPlans->fetchAll(PDO::FETCH_ASSOC);

$unavailablePlanCodes = [];
foreach ($allPlans as $plan) {
    if (
        $plan['ram_gb'] > $availableRamGb ||
        $plan['cpu_cores'] > $availableCores ||
        $plan['storage_gb'] > $availableStorageGb
    ) {
        $unavailablePlanCodes[] = $plan['code'];
    }
}

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

     if (in_array($planCode, $ownedPlanCodes)) {
        $error = "Hai già un abbonamento attivo per questo piano. Non puoi acquistarlo di nuovo.";
    } else {
        // Se il piano non è posseduto, procedi con la logica di Stripe
        
        // Recupera piano
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
        // ... (il resto della tua logica Stripe rimane qui dentro)
        // ...
    }

    // Recupera piano
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
    $stmt->execute([$planCode]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        die("Piano non valido.");

    // Verifica se l'utente ha già subscription
    // La logica di verifica è ora gestita tramite Stripe nel webhook
    // e la creazione dello slot avviene in sync_server_slots.php
    
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

<div class="container mx-auto px-4 py-12">
    <h2 class="text-4xl font-bold text-white text-center mb-12 animate-fade-in-down">🚀 Acquista un nuovo server</h2>

    <?php if ($error): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6 text-center shadow-md">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col md:flex-row justify-center items-center md:items-start gap-8">
        
        <div class="w-full max-w-lg lg:max-w-xl">
            <form method="POST" id="createServerForm">
                <?= csrf_field(); ?>
                
                <h3 class="text-2xl font-semibold mb-4 text-center text-white">Seleziona il tuo piano</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <label for="plan_2gb" class="bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300">
                        <input type="radio" name="plan_code" id="plan_2gb" value="plan_2gb" class="hidden peer">
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-blue-400">2 GB RAM</span>
                            <span class="text-gray-400 text-sm">2 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€5.53<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>

                    <label for="plan_4gb" class="relative bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300 transform hover:scale-105">
                         <div class="absolute top-0 right-0 -mt-2 -mr-2 bg-yellow-500 text-gray-900 text-xs font-bold px-3 py-1 rounded-full rotate-12 transform">PIÙ POPOLARE</div>
                        <input type="radio" name="plan_code" id="plan_4gb" value="plan_4gb" class="hidden peer" checked>
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-yellow-400">4 GB RAM</span>
                            <span class="text-gray-400 text-sm">2 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€8.50<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>

                    <label for="plan_6gb" class="bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300">
                        <input type="radio" name="plan_code" id="plan_6gb" value="plan_6gb" class="hidden peer">
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-blue-400">6 GB RAM</span>
                            <span class="text-gray-400 text-sm">3 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€12.75<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>
                    <label for="plan_8gb" class="bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300">
                        <input type="radio" name="plan_code" id="plan_8gb" value="plan_8gb" class="hidden peer">
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-blue-400">8 GB RAM</span>
                            <span class="text-gray-400 text-sm">4 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€18.28<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>
                    <label for="plan_16gb" class="bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300">
                        <input type="radio" name="plan_code" id="plan_16gb" value="plan_16gb" class="hidden peer">
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-blue-400">16 GB RAM</span>
                            <span class="text-gray-400 text-sm">6 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€29.75<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>
                    <label for="plan_24gb" class="bg-gray-800 rounded-xl p-6 shadow-xl border border-transparent hover:border-blue-500 cursor-pointer transition-all duration-300">
                        <input type="radio" name="plan_code" id="plan_24gb" value="plan_24gb" class="hidden peer">
                        <div class="flex flex-col items-center">
                            <span class="text-3xl font-bold text-blue-400">24 GB RAM</span>
                            <span class="text-gray-400 text-sm">8 CPU Cores</span>
                            <span class="mt-4 text-4xl font-extrabold text-white">€48.00<span class="text-base font-medium text-gray-500">/mese</span></span>
                        </div>
                    </label>
                </div>
                
                <div class="mt-8 text-center">
                    <button type="submit" class="w-full md:w-auto py-3 px-8 bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold rounded-full shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                        Prosegui al Pagamento
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php include 'includes/footer.php'; ?>
<script>
    // Passa entrambi gli array da PHP a JavaScript
    const ownedPlanCodes = <?= json_encode($ownedPlanCodes); ?>;
    const unavailablePlanCodes = <?= json_encode($unavailablePlanCodes); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const planRadios = document.querySelectorAll('input[name="plan_code"]');
        
        // Disabilita i piani GIÀ ACQUISTATI
        ownedPlanCodes.forEach(code => {
            const radio = document.getElementById(code);
            if (radio) {
                const label = radio.parentElement;
                radio.disabled = true;
                label.classList.add('opacity-50', 'cursor-not-allowed', 'relative');
                label.classList.remove('hover:border-blue-500');

                const ownedBadge = document.createElement('div');
                ownedBadge.className = 'absolute top-0 left-0 -mt-2 -ml-2 bg-green-600 text-white text-xs font-bold px-3 py-1 rounded-full -rotate-12 transform z-10';
                ownedBadge.textContent = 'GIÀ ATTIVO';
                label.appendChild(ownedBadge);
            }
        });

        // NUOVO BLOCCO: Disabilita i piani per MANCANZA DI RISORSE
        unavailablePlanCodes.forEach(code => {
            const radio = document.getElementById(code);
            // Controlla che non sia già stato disabilitato (perché già posseduto)
            if (radio && !radio.disabled) {
                const label = radio.parentElement;
                radio.disabled = true;
                label.classList.add('opacity-50', 'cursor-not-allowed', 'relative');
                label.classList.remove('hover:border-blue-500');

                const unavailableBadge = document.createElement('div');
                unavailableBadge.className = 'absolute top-0 left-0 -mt-2 -ml-2 bg-red-600 text-white text-xs font-bold px-3 py-1 rounded-full -rotate-12 transform z-10';
                unavailableBadge.textContent = 'RISORSE ESAURITE';
                label.appendChild(unavailableBadge);
            }
        });

        // Controlla se il piano selezionato di default è disabilitato
        const checkedRadio = document.querySelector('input[name="plan_code"]:checked');
        if (checkedRadio && checkedRadio.disabled) {
            const firstAvailableRadio = document.querySelector('input[name="plan_code"]:not(:disabled)');
            if (firstAvailableRadio) {
                firstAvailableRadio.checked = true;
            } else {
                const submitButton = document.querySelector('button[type="submit"]');
                if(submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = "Nessun piano disponibile";
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
            }
        }

        // Funzione per aggiornare lo stile del piano selezionato (invariata)
        function updateSelection() {
            planRadios.forEach(radio => {
                const label = radio.parentElement;
                if (radio.checked) {
                    label.classList.add('scale-105', 'border-blue-500');
                    label.classList.remove('border-transparent');
                } else {
                    label.classList.remove('scale-105', 'border-blue-500');
                    label.classList.add('border-transparent');
                }
            });
        }

        planRadios.forEach(radio => {
            radio.addEventListener('change', updateSelection);
        });

        updateSelection();
    });
</script>
</body>
</html>