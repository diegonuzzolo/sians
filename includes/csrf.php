<?php
/**
 * Avvia la sessione se non è già attiva.
 * È fondamentale che sia chiamata prima di qualsiasi output.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Genera e restituisce un token CSRF, salvandolo nella sessione.
 *
 * @return string Il token CSRF.
 */
function csrf_token(): string {
    // Se non esiste un token, ne crea uno nuovo.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Genera l'input HTML nascosto con il token CSRF.
 * Da inserire all'interno di ogni form.
 *
 * @return string La stringa HTML dell'input.
 */
function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Controlla il token CSRF inviato tramite POST.
 * Se il token non è valido, interrompe l'esecuzione con un errore 403.
 */
function csrf_check_or_die() {
    // Controlla solo per le richieste POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted_token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';

        // Usa hash_equals per una comparazione sicura (previene timing attacks)
        if (empty($posted_token) || empty($session_token) || !hash_equals($session_token, $posted_token)) {
            http_response_code(403);
            die('Errore di sicurezza: Token CSRF non valido o mancante.');
        }
    }
}

/**
 * Controlla il token CSRF e in caso di fallimento restituisce un errore JSON.
 * Ideale per le chiamate AJAX.
 */
function csrf_check_or_die_json() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted_token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';

        if (empty($posted_token) || empty($session_token) || !hash_equals($session_token, $posted_token)) {
            http_response_code(403);
            // Imposta l'header corretto per la risposta JSON
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Errore di sicurezza: Token CSRF non valido.']);
            exit;
        }
    }
}