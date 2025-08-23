<?php
// Avvia la sessione e include i file di configurazione
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';
require 'includes/stripe_manager.php'; // Nuovo file per la gestione di Stripe

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header('Location: login.php');
    exit;
}

$msg = '';

// --- Recupero dati utente e billing ---
$stmt = $pdo->prepare("SELECT username, email, stripe_customer_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("SELECT * FROM billing_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$billing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Ricarico i piani attivi
$stmt = $pdo->prepare("
    SELECT
        p.name AS plan_name,
        p.cpu_cores,
        p.ram_gb,
        s.status,
        s.stripe_subscription_id,
        s.created_at,
        s.cancel_at_period_end
    FROM subscriptions s
    JOIN plans p ON p.stripe_price_id = s.plan_id
    WHERE s.user_id = ? AND s.status IN ('active', 'trialing')
");
$stmt->execute([$userId]);
$activeSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Gestione POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die(); // <-- controlla CSRF solo su POST

    if (isset($_POST['change_password'])) {
        // ... (Logica per il cambio password, invariata) ...
        $curr = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password_confirm'] ?? '';

        if ($new !== $new2) {
            $msg = "Le password non coincidono";
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{10,}$/', $new)) {
            $msg = "Password troppo debole";
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($curr, $hash)) {
                $msg = "Password attuale errata";
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?, last_password_change = NOW(), force_password_reset = 0
                    WHERE id = ?
                ");
                $upd->execute([$newHash, $userId]);
                $msg = "Password aggiornata";
            }
        }
    } elseif (isset($_POST['cancel_renewal'])) {
        // --- Nuova logica per cancellare il rinnovo ---
        $subscriptionId = $_POST['stripe_subscription_id'] ?? '';
        if (StripeManager::cancelSubscriptionAtPeriodEnd($subscriptionId)) {
            $stmt = $pdo->prepare("UPDATE subscriptions SET cancel_at_period_end = 1 WHERE stripe_subscription_id = ? AND user_id = ?");
            $stmt->execute([$subscriptionId, $userId]);
            $msg = "Il rinnovo dell'abbonamento è stato annullato. Potrai continuare a usare il piano fino alla scadenza.";
        } else {
            $msg = "Errore durante l'annullamento del rinnovo. Riprova più tardi.";
        }
    } elseif (isset($_POST['request_refund'])) {
        // --- Nuova logica per la richiesta di rimborso ---
        $subscriptionId = $_POST['stripe_subscription_id'] ?? '';
        $subscriptionCreatedAt = $_POST['subscription_created_at'] ?? '';
        $today = new DateTime();
        $subscriptionDate = new DateTime($subscriptionCreatedAt);
        $diff = $today->diff($subscriptionDate);

        // Controllo se sono passati meno di 14 giorni (diritto di recesso UE)
        if ($diff->days <= 14 && StripeManager::refundLatestPayment($subscriptionId)) {
            $stmt = $pdo->prepare("
                INSERT INTO refund_requests (user_id, stripe_subscription_id, status)
                VALUES (?, ?, 'pending')
            ");
            $stmt->execute([$userId, $subscriptionId]);
            $msg = "Richiesta di rimborso inviata con successo. La processeremo entro pochi giorni lavorativi.";
        } else {
            $msg = "Non è più possibile richiedere il rimborso. Sono trascorsi più di 14 giorni dalla data di acquisto o si è verificato un errore.";
        }
    } else {
        // --- Logica per l'aggiornamento del profilo, invariata ---
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $username = trim($_POST['username'] ?? '');

        if (!$email || $username === '') {
            $msg = "Dati profilo non validi";
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                $stmt->execute([$username, $email, $userId]);

                $stmt = $pdo->prepare("SELECT id FROM billing_profiles WHERE user_id = ?");
                $stmt->execute([$userId]);
                $bp = $stmt->fetchColumn();

                $bpData = [
                    $_POST['first_name'] ?? '',
                    $_POST['last_name'] ?? '',
                    $_POST['company'] ?? null,
                    $_POST['vat_number'] ?? null,
                    $_POST['address_line1'] ?? '',
                    $_POST['address_line2'] ?? null,
                    $_POST['city'] ?? '',
                    $_POST['postal_code'] ?? '',
                    strtoupper($_POST['country_code'] ?? 'IT'),
                    $userId
                ];

                if ($bp) {
                    $upd = $pdo->prepare("
                        UPDATE billing_profiles
                        SET first_name = ?, last_name = ?, company = ?, vat_number = ?,
                            address_line1 = ?, address_line2 = ?, city = ?, postal_code = ?,
                            country_code = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $upd->execute($bpData);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO billing_profiles
                        (first_name, last_name, company, vat_number, address_line1, address_line2, city, postal_code, country_code, user_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ");
                    $ins->execute($bpData);
                }

                $pdo->commit();
                $msg = "Profilo aggiornato";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "Errore: " . $e->getMessage();
            }
        }
    }
    // Ricarico i dati dopo POST
    $stmt = $pdo->prepare("SELECT username, email, stripe_customer_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT * FROM billing_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Ricarico i piani attivi
    $stmt = $pdo->prepare("
        SELECT
            p.name AS plan_name,
            p.cpu_cores,
            p.ram_gb,
            s.status,
            s.stripe_subscription_id,
            s.created_at,
            s.cancel_at_period_end
        FROM subscriptions s
        JOIN plans p ON p.stripe_price_id = s.plan_id
        WHERE s.user_id = ? AND s.status IN ('active', 'trialing')
    ");
    $stmt->execute([$userId]);
    $activeSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php include 'includes/header.php'; ?>

<div class="bg-gray-900 text-white min-h-screen py-12">
    <div class="container mx-auto px-4 max-w-5xl">
        <h2
            class="text-3xl sm:text-4xl font-extrabold text-center mb-10 text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500">
            Il Mio Profilo
        </h2>

        <?php if ($msg): ?>
            <div class="bg-blue-600 text-white p-4 rounded-lg mb-6 shadow-md text-center animate-fade-in-down">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <div class="bg-gray-800 rounded-xl shadow-lg mb-8 p-6">
            <div class="bg-blue-600 text-white text-lg font-semibold rounded-t-xl px-6 py-4 -mx-6 -mt-6 mb-6">
                Piani Attivi
            </div>
            <div class="overflow-x-auto">
                <?php if (!empty($activeSubscriptions)): ?>
                    <table class="w-full text-left table-auto">
                        <thead>
                            <tr class="text-gray-400 uppercase text-sm leading-normal">
                                <th class="py-3 px-6">Piano</th>
                                <th class="py-3 px-6">RAM</th>
                                <th class="py-3 px-6">CPU</th>
                                <th class="py-3 px-6">Stato</th>
                                <th class="py-3 px-6">Gestione</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-200 text-sm font-light">
                            <?php foreach ($activeSubscriptions as $plan): ?>
                                <tr class="border-b border-gray-700 hover:bg-gray-700 transition-colors duration-200">
                                    <td class="py-3 px-6 whitespace-nowrap"><?= htmlspecialchars($plan['plan_name']) ?></td>
                                    <td class="py-3 px-6"><?= htmlspecialchars(number_format((float) $plan['ram_gb'], 0)) ?> GB
                                    </td>
                                    <td class="py-3 px-6"><?= htmlspecialchars($plan['cpu_cores']) ?> Core</td>
                                    <td class="py-3 px-6">
                                        <span
                                            class="py-1 px-3 rounded-full text-xs font-semibold
                                            <?= $plan['status'] === 'active' || $plan['status'] === 'trialing' ? 'bg-green-500 text-green-900' : 'bg-yellow-500 text-yellow-900' ?>">
                                            <?= htmlspecialchars(ucfirst($plan['status'])) ?>
                                        </span>
                                        <?php if ($plan['cancel_at_period_end']): ?>
                                            <span class="ml-2 text-xs font-bold text-red-400"> (Rinnovo annullato)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-6">
                                        <?php if ($plan['cancel_at_period_end'] != 1): ?>
                                            <form method="POST" class="inline-block" onsubmit="return confirm('Sei sicuro di voler annullare il rinnovo? Il servizio rimarrà attivo fino alla prossima scadenza.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="cancel_renewal" value="1">
                                                <input type="hidden" name="stripe_subscription_id" value="<?= htmlspecialchars($plan['stripe_subscription_id']) ?>">
                                                <button type="submit"
                                                    class="py-2 px-4 text-white font-bold rounded-lg shadow-lg bg-red-500 hover:bg-red-600 transition-all duration-300 transform hover:scale-105">
                                                    Annulla Rinnovo
                                                </button>
                                            </form>
                                        <?php else: ?>
                                             <button disabled class="py-2 px-4 text-gray-500 bg-gray-700 rounded-lg">Annulla Rinnovo</button>
                                        <?php endif; ?>
                                        
                                        <?php 
                                            $created_date = new DateTime($plan['created_at']);
                                            $now = new DateTime();
                                            $diff = $now->diff($created_date);
                                            // Il pulsante di rimborso è visibile solo se sono trascorsi meno di 14 giorni
                                            if ($diff->days <= 14):
                                        ?>
                                            <form method="POST" class="inline-block ml-2" onsubmit="return confirm('Sei sicuro di voler richiedere un rimborso? Questo annullerà immediatamente il tuo abbonamento.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="request_refund" value="1">
                                                <input type="hidden" name="stripe_subscription_id" value="<?= htmlspecialchars($plan['stripe_subscription_id']) ?>">
                                                <input type="hidden" name="subscription_created_at" value="<?= htmlspecialchars($plan['created_at']) ?>">
                                                <button type="submit"
                                                    class="py-2 px-4 text-white font-bold rounded-lg shadow-lg bg-orange-500 hover:bg-orange-600 transition-all duration-300 transform hover:scale-105">
                                                    Richiedi Rimborso
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-gray-400 text-center py-4">Nessun piano attivo al momento.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-gray-800 rounded-xl shadow-lg mb-8 p-6">
            <div class="bg-purple-600 text-white text-lg font-semibold rounded-t-xl px-6 py-4 -mx-6 -mt-6 mb-6">
                Profilo & Fatturazione
            </div>
            <form method="POST">
                <?= csrf_field() ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Username</label>
                        <input type="text" name="username"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            required value="<?= htmlspecialchars($_POST['username'] ?? $user['username'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Email</label>
                        <input type="email" name="email"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            required value="<?= htmlspecialchars($_POST['email'] ?? $user['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Nome</label>
                        <input type="text" name="first_name"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            value="<?= htmlspecialchars($_POST['first_name'] ?? $billing['first_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Cognome</label>
                        <input type="text" name="last_name"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            value="<?= htmlspecialchars($_POST['last_name'] ?? $billing['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Azienda</label>
                    <input type="text" name="company"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        value="<?= htmlspecialchars($_POST['company'] ?? $billing['company'] ?? '') ?>">
                </div>

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Partita IVA / Cod. Fiscale</label>
                    <input type="text" name="vat_number"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        value="<?= htmlspecialchars($_POST['vat_number'] ?? $billing['vat_number'] ?? '') ?>">
                </div>

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Indirizzo</label>
                    <input type="text" name="address_line1"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500 mb-2"
                        placeholder="Via / Piazza"
                        value="<?= htmlspecialchars($_POST['address_line1'] ?? $billing['address_line1'] ?? '') ?>">
                    <input type="text" name="address_line2"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        placeholder="App./Piano / Altro"
                        value="<?= htmlspecialchars($_POST['address_line2'] ?? $billing['address_line2'] ?? '') ?>">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Città</label>
                        <input type="text" name="city"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            value="<?= htmlspecialchars($_POST['city'] ?? $billing['city'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">CAP</label>
                        <input type="text" name="postal_code"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            value="<?= htmlspecialchars($_POST['postal_code'] ?? $billing['postal_code'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-gray-400 text-sm font-medium mb-1">Nazione</label>
                        <input type="text" name="country_code"
                            class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                            value="<?= htmlspecialchars($_POST['country_code'] ?? $billing['country_code'] ?? 'IT') ?>">
                    </div>
                </div>

                <button type="submit"
                    class="w-full py-3 px-8 bg-gradient-to-r from-green-500 to-teal-500 text-white font-bold rounded-lg shadow-lg hover:from-green-600 hover:to-teal-600 transition-all duration-300 transform hover:scale-105">
                    Aggiorna Profilo
                </button>
            </form>
        </div>

        <div class="bg-gray-800 rounded-xl shadow-lg p-6">
            <div class="bg-red-600 text-white text-lg font-semibold rounded-t-xl px-6 py-4 -mx-6 -mt-6 mb-6">
                Cambio Password
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Password Attuale</label>
                    <input type="password" name="current_password"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        required>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Nuova Password</label>
                    <input type="password" name="new_password"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        required>
                    <small class="text-gray-500 mt-1 block">Minimo 10 caratteri, almeno una maiuscola, una minuscola e
                        un numero</small>
                </div>

                <div class="mb-6">
                    <label class="block text-gray-400 text-sm font-medium mb-1">Conferma Nuova Password</label>
                    <input type="password" name="new_password_confirm"
                        class="w-full bg-gray-700 text-white rounded-lg px-4 py-2 border border-gray-600 focus:outline-none focus:border-blue-500"
                        required>
                </div>

                <button type="submit"
                    class="w-full py-3 px-8 bg-gradient-to-r from-red-500 to-rose-500 text-white font-bold rounded-lg shadow-lg hover:from-red-600 hover:to-rose-600 transition-all duration-300 transform hover:scale-105">
                    Aggiorna Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>