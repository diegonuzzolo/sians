<?php
require 'auth_check.php';
require 'config/config.php';
require 'includes/csrf.php';
csrf_check_or_die();

$userId = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD']==='POST') {
    // aggiornamento email/username + dati fatturazione
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $username = trim($_POST['username'] ?? '');
    if (!$email || $username === '') { die('Dati non validi'); }

    $pdo->beginTransaction();
    try {
        // aggiorna utente
        $stmt = $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?");
        $stmt->execute([$username, $email, $userId]);

        // upsert billing
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

// cambio password
if (isset($_POST['change_password'])) {
    csrf_check_or_die();
    $curr = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password'] ?? '';
    $new2 = $_POST['new_password_confirm'] ?? '';
    if ($new !== $new2) { die('Le password non coincidono'); }
    // policy minima
    if (!preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d).{10,}$/', $new)) { die('Password troppo debole'); }

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($curr, $hash)) { die('Password attuale errata'); }

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE users SET password_hash=?, password_changed_at=NOW(), force_password_reset=0 WHERE id=?");
    $upd->execute([$newHash, $userId]);
    $msg = "Password aggiornata";
}
?>
<!-- QUI il tuo HTML con due form: (1) profilo/billing e (2) cambio password) -->
