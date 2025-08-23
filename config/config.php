<?php
// ==========================
// 📦 CONFIGURAZIONE DATABASE
date_default_timezone_set('UTC');

// config.php
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'minecraft_platform');
    define('DB_USER', 'diego');
    define('DB_PASSWORD', 'Lgu8330Serve6');
    define('DB_CHARSET', 'utf8mb4');
    
// ... altre configurazioni già presenti ...

// --- Configurazione Proxmox ---
$proxmox_host = '192.168.1.251';      // IP o hostname di Proxmox
$proxmox_port = 8006;                 // Porta API
$proxmox_user = 'root@pam';           // Utente Proxmox
$proxmox_token_id = 'sians';          // ID token
$proxmox_token_secret = '0b4b070d-90bc-40a5-b0d1-9db4df95aa79'; // Secret token

    define('PROXMOX_API_URL', 'https://192.168.1.251:8006/api2/json');
    define('PROXMOX_NODE', 'pve');
    define('PROXMOX_USERNAME', 'root@pam');
    define('PROXMOX_PASSWORD', 'Lgu8330Serve6');
    define('PROXMOX_STORAGE', 'local-lvm');
    define('PROXMOX_TEMPLATE', 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst');
    define('CONTAINER_GATEWAY', '192.168.1.254');
    define('CONTAINER_SUBNET', '192.168.1.0/24');

        define('STRIPE_SECRET_KEY', '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');
define('CLOUDFLARE_API_TOKEN', 'UI5ZCFz62itti1220yKgJV9y5SsZtFJ4CoFvU5yo');
define('CLOUDFLARE_ZONE_ID', 'ad73843747d02aa059e3a650182af704');
// In config/config.php



// Credenziali per l'invio di email tramite SMTP di Brevo
define('SMTP_HOST', 'smtp-relay.brevo.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '955021001@smtp-brevo.com'); // La tua email di login Brevo
define('SMTP_PASSWORD', 'T8NO913M6AjXxY7p');      // La tua chiave API v3 (o password SMTP)
define('SMTP_FROM_ADDRESS', 'no-reply@sians.it');    // L'indirizzo che i tuoi utenti vedranno
define('SMTP_FROM_NAME', 'Sians Hosting');
    }

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage());

}
