<?php
// ==========================
// 📦 CONFIGURAZIONE DATABASE

// config.php
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'minecraft_platform');
    define('DB_USER', 'diego');
    define('DB_PASSWORD', 'Lgu8330Serve6');
    define('DB_CHARSET', 'utf8mb4');
    
    define('PROXMOX_API_URL', 'https://192.168.1.251:8006/api2/json');
    define('PROXMOX_NODE', 'pve');
    define('PROXMOX_USERNAME', 'root@pam');
    define('PROXMOX_PASSWORD', 'Lgu8330Serve6');
    define('PROXMOX_STORAGE', 'local-lvm');
    define('PROXMOX_TEMPLATE', 'local:vztmpl/ubuntu-22.04-standard_22.04-1_amd64.tar.zst');
    define('CONTAINER_GATEWAY', '192.168.1.254');
    define('CONTAINER_SUBNET', '192.168.1.0/24');

        define('STRIPE_SECRET_KEY', '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');

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
