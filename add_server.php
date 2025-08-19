<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'auth_check.php';
require 'config/config.php';
require_once 'includes/csrf.php';
// Usa l'autoload di Composer, che è lo standard moderno per le librerie PHP
require 'vendor/autoload.php'; 

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header('Location: login.php');
    exit;
}

// --- LOGICA DI CONTROLLO (eseguita prima di mostrare la pagina) ---

// 1. Recupera i piani già posseduti dall'utente
$stmtOwned = $pdo->prepare("
    SELECT p.code
    FROM server_slots ss
    JOIN plans p ON ss.plan_id = p.stripe_price_id
    WHERE ss.user_id = ?
");
$stmtOwned->execute([$userId]);
$ownedPlanCodes = $stmtOwned->fetchAll(PDO::FETCH_COLUMN);

// 2. Controlla le risorse totali disponibili sul server
$stmtRes = $pdo->query("SELECT * FROM resources WHERE id = 1");
$resources = $stmtRes->fetch(PDO::FETCH_ASSOC);
$availableRamGb = ($resources['total_ram_gb'] * 1024 - $resources['used_ram_mb']) / 1024;
$availableCores = $resources['total_cpu_cores'] - $resources['used_cpu_cores'];
$availableStorageGb = $resources['total_storage_gb'] - $resources['used_storage_gb'];

// 3. Determina quali piani non sono disponibili per mancanza di risorse
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

// --- GESTIONE DELLA RICHIESTA ---

$error = '';
$successMessage = '';

// Gestione del reindirizzamento da Stripe (solo per mostrare un messaggio all'utente)
if (isset($_GET['success'])) {
    $successMessage = "Pagamento completato! Il tuo nuovo server sarà disponibile nella dashboard a breve, non appena riceveremo la conferma.";
}
if (isset($_GET['cancel'])) {
    $error = "Pagamento annullato. Puoi riprovare in qualsiasi momento.";
}

// Gestione dell'invio del form per l'acquisto (quando l'utente clicca "Prosegui")
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    
    $planCode = $_POST['plan_code'] ?? null;

    // Esegui tutti i controlli di validazione lato server
    if (!$planCode) {
        $error = "Devi selezionare un piano.";
    } elseif (in_array($planCode, $ownedPlanCodes)) {
        $error = "Hai già un abbonamento attivo per questo piano.";
    } elseif (in_array($planCode, $unavailablePlanCodes)) {
        $error = "Le risorse del server non sono sufficienti per questo piano. Riprova più tardi.";
    } else {
        // Se tutti i controlli passano, procedi con la creazione della sessione Stripe
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE code=? AND active=1 LIMIT 1");
        $stmt->execute([$planCode]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            $error = "Piano selezionato non valido.";
        } else {
            // Recupera i dati utente necessari per Stripe
            $stmtUser = $pdo->prepare("SELECT email, stripe_customer_id FROM users WHERE id=?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY); // Usa la costante dal tuo config.php

            try {
                $checkout_params = [
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price' => $plan['stripe_price_id'],
                        'quantity' => 1,
                    ]],
                    'mode' => 'subscription',
                    // **MODIFICA CRUCIALE: Passa l'ID utente a Stripe per identificarlo nel webhook**
                    'client_reference_id' => $userId, 
                    'success_url' => "https://sians.it/add_server.php?success=1",
                    'cancel_url' => "https://sians.it/add_server.php?cancel=1",
                ];

                // Se l'utente ha già un customer ID su Stripe, usalo per non creare duplicati
                if (!empty($user['stripe_customer_id'])) {
                    $checkout_params['customer'] = $user['stripe_customer_id'];
                } else {
                    $checkout_params['customer_email'] = $user['email'];
                }

                $session = \Stripe\Checkout\Session::create($checkout_params);
                
                // Reindirizza l'utente alla pagina di pagamento di Stripe
                header("Location: " . $session->url);
                exit;

            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Gestione specifica per l'errore "No such customer"
                if (strpos($e->getMessage(), 'No such customer') !== false) {
                    
                    // L'ID cliente nel DB è invalido. Rimuovilo.
                    $stmtClear = $pdo->prepare("UPDATE users SET stripe_customer_id = NULL WHERE id = ?");
                    $stmtClear->execute([$userId]);

                    // Rimuovi il parametro 'customer' e riprova usando l'email
                    unset($checkout_params['customer']);
                    $checkout_params['customer_email'] = $user['email'];

                    try {
                        // Secondo tentativo
                        $session = \Stripe\Checkout\Session::create($checkout_params);
                        header("Location: " . $session->url);
                        exit;
                    } catch (Exception $sub_e) {
                        $error = "Errore durante la creazione della sessione di pagamento: " . $sub_e->getMessage();
                    }

                } else {
                    // Era un altro tipo di errore di Stripe
                    $error = "Errore Stripe: " . $e->getMessage();
                }
            } catch (Exception $e) {
                // Cattura qualsiasi altra eccezione generica
                $error = "Errore generico: " . $e->getMessage();
            }
        }
    }
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
    
    <?php if ($successMessage): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6 text-center shadow-md">
            <?= htmlspecialchars($successMessage) ?>
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
    // Passa gli array da PHP a JavaScript per gestire dinamicamente l'interfaccia
    const ownedPlanCodes = <?= json_encode($ownedPlanCodes); ?>;
    const unavailablePlanCodes = <?= json_encode($unavailablePlanCodes); ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const planRadios = document.querySelectorAll('input[name="plan_code"]');
        
        // Disabilita i piani GIÀ ACQUISTATI dall'utente
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

        // Disabilita i piani per MANCANZA DI RISORSE sul server
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

        // Se il piano selezionato di default è disabilitato, seleziona il primo disponibile
        const checkedRadio = document.querySelector('input[name="plan_code"]:checked');
        if (checkedRadio && checkedRadio.disabled) {
            const firstAvailableRadio = document.querySelector('input[name="plan_code"]:not(:disabled)');
            if (firstAvailableRadio) {
                firstAvailableRadio.checked = true;
            } else {
                // Se nessun piano è disponibile, disabilita il pulsante di acquisto
                const submitButton = document.querySelector('button[type="submit"]');
                if(submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = "Nessun piano disponibile";
                    submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                }
            }
        }

        // Funzione per aggiornare lo stile del piano selezionato
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

        // Applica lo stile iniziale al caricamento della pagina
        updateSelection();
    });
</script>
</body>
</html>
