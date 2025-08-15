<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;
$msg = '';

// --- Recupero dati utente e billing dal DB ---
$stmt = $pdo->prepare("SELECT username,email FROM users WHERE id=?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM billing_profiles WHERE user_id=?");
$stmt->execute([$userId]);
$billing = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Gestione POST ---
if ($_SERVER['REQUEST_METHOD']==='POST') {
    // cambio password
    if (isset($_POST['change_password'])) {
        $curr = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password_confirm'] ?? '';

        if ($new !== $new2) {
            $msg = "Le password non coincidono";
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{10,}$/', $new)) {
            $msg = "Password troppo debole";
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($curr, $hash)) {
                $msg = "Password attuale errata";
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password_hash=?, password_changed_at=NOW(), force_password_reset=0 WHERE id=?");
                $upd->execute([$newHash, $userId]);
                $msg = "Password aggiornata";
            }
        }
    } 
    // aggiornamento profilo
    else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $username = trim($_POST['username'] ?? '');
        if (!$email || $username === '') { 
            $msg = "Dati profilo non validi"; 
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?");
                $stmt->execute([$username, $email, $userId]);

                $stmt = $pdo->prepare("SELECT id FROM billing_profiles WHERE user_id=?");
                $stmt->execute([$userId]);
                $bp = $stmt->fetchColumn();

                $bpData = [
                    $_POST['first_name'] ?? '', $_POST['last_name'] ?? '', $_POST['company'] ?? null,
                    $_POST['vat_number'] ?? null, $_POST['address_line1'] ?? '', $_POST['address_line2'] ?? null,
                    $_POST['city'] ?? '', $_POST['postal_code'] ?? '', strtoupper($_POST['country_code'] ?? 'IT'), $userId
                ];

                if ($bp) {
                    $upd = $pdo->prepare("UPDATE billing_profiles SET first_name=?, last_name=?, company=?, vat_number=?, address_line1=?, address_line2=?, city=?, postal_code=?, country_code=?, updated_at=NOW() WHERE user_id=?");
                    $upd->execute($bpData);
                } else {
                    $ins = $pdo->prepare("INSERT INTO billing_profiles (first_name,last_name,company,vat_number,address_line1,address_line2,city,postal_code,country_code,user_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $ins->execute($bpData);
                }
                $pdo->commit();
                $msg = "Profilo aggiornato";
            } catch(Exception $e) {
                $pdo->rollBack();
                $msg = "Errore: ".$e->getMessage();
            }
        }
    }

    // ricarico i dati per mantenere valori
    $stmt = $pdo->prepare("SELECT username,email FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM billing_profiles WHERE user_id=?");
    $stmt->execute([$userId]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!-- QUI il tuo HTML con due form: (1) profilo/billing e (2) cambio password) -->
<?php include 'includes/header.php'; ?>

<div class="container my-5" style="max-width: 720px;">
    <h2 class="text-center text-primary mb-4">Il Mio Profilo</h2>

    <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Profilo & Fatturazione -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-bold">Profilo & Fatturazione</div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>

                <div class="row mb-3">
                    <div class="col">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required
                               value="<?= htmlspecialchars($_POST['username'] ?? $user['username'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? $user['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col">
                        <label class="form-label">Nome</label>
                        <input type="text" name="first_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['first_name'] ?? $billing['first_name'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label class="form-label">Cognome</label>
                        <input type="text" name="last_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['last_name'] ?? $billing['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Azienda</label>
                    <input type="text" name="company" class="form-control"
                           value="<?= htmlspecialchars($_POST['company'] ?? $billing['company'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Partita IVA / Cod. Fiscale</label>
                    <input type="text" name="vat_number" class="form-control"
                           value="<?= htmlspecialchars($_POST['vat_number'] ?? $billing['vat_number'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Indirizzo</label>
                    <input type="text" name="address_line1" class="form-control mb-2"
                           placeholder="Via / Piazza"
                           value="<?= htmlspecialchars($_POST['address_line1'] ?? $billing['address_line1'] ?? '') ?>">
                    <input type="text" name="address_line2" class="form-control"
                           placeholder="App./Piano / Altro"
                           value="<?= htmlspecialchars($_POST['address_line2'] ?? $billing['address_line2'] ?? '') ?>">
                </div>

                <div class="row mb-3">
                    <div class="col">
                        <label class="form-label">Città</label>
                        <input type="text" name="city" class="form-control"
                               value="<?= htmlspecialchars($_POST['city'] ?? $billing['city'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label class="form-label">CAP</label>
                        <input type="text" name="postal_code" class="form-control"
                               value="<?= htmlspecialchars($_POST['postal_code'] ?? $billing['postal_code'] ?? '') ?>">
                    </div>
                    <div class="col">
                        <label class="form-label">Nazione</label>
                        <input type="text" name="country_code" class="form-control"
                               value="<?= htmlspecialchars($_POST['country_code'] ?? $billing['country_code'] ?? 'IT') ?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-success w-100 fw-bold">Aggiorna Profilo</button>
            </form>
        </div>
    </div>

    <!-- Cambio Password -->
    <div class="card shadow-sm">
        <div class="card-header bg-warning text-dark fw-bold">Cambio Password</div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="change_password" value="1">

                <div class="mb-3">
                    <label class="form-label">Password Attuale</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Nuova Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                    <small class="text-muted">Minimo 10 caratteri, almeno una maiuscola, una minuscola e un numero</small>
                </div>

                <div class="mb-3">
                    <label class="form-label">Conferma Nuova Password</label>
                    <input type="password" name="new_password_confirm" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-warning w-100 fw-bold">Aggiorna Password</button>
            </form>
        </div>
    </div>
</div>

<style>
   <style>
    body { background-color: #f4f6f9; }
    .card { border-radius: 12px; }
    .card-header { font-size: 1.1rem; }
    /* Etichette leggibili su sfondo chiaro */
    .form-label, .text-muted {
        color: #333 !important;
    }
    /* Input chiari con bordo discreto */
    .form-control {
        background-color: #fff;
        color: #000;
        border: 1px solid #ccc;
    }
</style>

</style>

<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>