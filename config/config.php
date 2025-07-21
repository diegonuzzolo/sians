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
define('PROXMOX_HOST', 'https://proxmox.sians.it');
define('PROXMOX_NODE', 'pve');
define('PROXMOX_API_TOKEN_ID', 'diego@pve!sians');
define('PROXMOX_API_TOKEN_SECRET', '04630a7b-98e3-4090-9226-b9b7dfa025b9');
