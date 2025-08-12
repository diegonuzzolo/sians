<?php
// Config database
define('DB_HOST', 'localhost');
define('DB_NAME', 'minecraft_platform');
define('DB_USER', 'diego');    
define('DB_PASSWORD', 'Lgu8330Serve6');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage());
}

// Config Proxmox API
define('PROXMOX_API_URL', 'https://192.168.1.251:8006/api2/json');
define('PROXMOX_NODE', 'pve');
define('PROXMOX_API_TOKEN_NAME', 'root@pam!sians');  // esempio: user@realm!tokenname
define('PROXMOX_API_TOKEN_VALUE', '0b4b070d-90bc-40a5-b0d1-9db4df95aa79');

// Storage e template LXC
define('PROXMOX_TEMPLATE_STORAGE', 'local');
define('PROXMOX_TEMPLATE_NAME', 'ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst');
