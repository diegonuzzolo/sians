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
if (!defined('PROXMOX_API_URL')) define('PROXMOX_HOST', 'https://proxmox.sians.it');
if (!defined('PROXMOX_NODE')) define('PROXMOX_NODE', 'pve') ;
if (!defined('PROXMOX_API_TOKEN_NAME')) define('PROXMOX_API_TOKEN_ID', 'diego@pve!sians');
if (!defined('PROXMOX_API_TOKEN_VALUE')) define('PROXMOX_API_TOKEN_SECRET', 'bff3d5bd-b6f6-47f2-a75b-c763feb51c3e');
if (!defined('CLOUDFLARE_API_TOKEN')) define ('CLOUDFLARE_API_TOKEN', '5qZFVHEm6DDAx10qyEQEjX0VB2vgljgT9sT70FKJ');
if (!defined('CLOUDFLARE_ZONE_ID')) define('CLOUDFLARE_ZONE_ID', 'ad73843747d02aa059e3a650182af704');
if (!defined('CLOUDFLARE_API_BASE')) define('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4');
if (!defined('DOMAIN')) define ('DOMAIN', 'sians.it');
define ('DB_HOST', 'localhost');
define ('DB_NAME', 'minecraft_platform');
define ('DB_USER', 'diego');
define ('DB_PASSWORD', 'Lgu8330Serve6');
define ('DB_CHARSET', 'utf8mb4');



try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
   # echo "✅ Connessione DB OK\n";
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage());
}

