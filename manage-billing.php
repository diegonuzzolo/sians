<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'stripe-php/init.php'; // Carica la libreria di Stripe
require 'config/config.php';   // Il tuo file di configurazione con le chiavi Stripe e la connessione PDO
require 'auth_check.php';      // Verifica che l'utente sia loggato

// 1. Recupera l'ID utente dalla sessione
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header('Location: login.php');
    exit;
}

// 2. Recupera lo Stripe Customer ID dal tuo database
$stmt = $pdo->prepare("SELECT stripe_customer_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$stripeCustomerId = $stmt->fetchColumn();

if (!$stripeCustomerId) {
    // Se l'utente non ha un ID cliente, non può gestire la fatturazione.
    // Riportalo al profilo con un messaggio di errore.
    $_SESSION['profile_message'] = "Nessun abbonamento da gestire trovato.";
    header('Location: profile.php');
    exit;
}

// 3. Imposta la chiave segreta di Stripe
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY); // Assicurati che $stripeSecretKey sia nel tuo config.php

try {
    // 4. Crea la sessione del Portale Clienti
    // L'URL di ritorno è la pagina dove l'utente tornerà dopo aver finito
    $returnUrl = 'https://sians.it/profile.php'; // <-- CAMBIA QUESTO!

    $portalSession = \Stripe\BillingPortal\Session::create([
        'customer' => $stripeCustomerId,
        'return_url' => $returnUrl,
    ]);

    // 5. Reindirizza l'utente all'URL del portale generato
    header('Location: ' . $portalSession->url);
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    // Gestisci eventuali errori API di Stripe
    $_SESSION['profile_message'] = "Errore durante la connessione al portale di fatturazione: " . $e->getMessage();
    header('Location: profile.php');
    exit;
}
?>