<?php
$host = 'localhost';
$db   = 'minecraft_platform';
$user = 'diego';    // o un utente mysql dedicato
$pass = 'Lgu8330Serve6';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Errore connessione DB: " . $e->getMessage());
}

// config.php
define('PROXMOX_HOST', 'https://192.168.1.251:8006');
define('PROXMOX_NODE', 'pve');
define('PROXMOX_API_TOKEN_ID', 'root@pam!sians-token');
define('PROXMOX_API_TOKEN_SECRET', '8063fe38-5209-477a-8d77-6024ad52966a');
