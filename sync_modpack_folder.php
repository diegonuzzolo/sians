<?php
require_once 'config/config.php'; // connessione PDO
require_once 'sync_functions.php'; // contiene la funzione syncModpack()

$dir = __DIR__ . '/modpacks-json';
$files = glob($dir . '/*.json');

if (!$files) {
    die("❌ Nessun file JSON trovato in $dir\n");
}

foreach ($files as $file) {
    echo "📥 Importando: $file\n";

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!isset($data['data'])) {
        echo "⚠️  JSON malformato: $file — salto\n";
        continue;
    }

    syncModpack($data['data']);
}
