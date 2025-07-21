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
