<?php
require_once 'config/config.php';
require_once 'sync_functions.php';

$dir = __DIR__ . '/modpacks-json';
$files = glob($dir . '/*.json');

if (!$files) {
    die("❌ Nessun file JSON trovato in $dir\n");
}

foreach ($files as $file) {
    echo "📂 Importando: $file\n";

    $json = file_get_contents($file);
    if ($json === false) {
        echo "❌ Impossibile leggere file $file\n";
        continue;
    }

    $data = json_decode($json, true);
    if (!$data) {
        echo "❌ JSON malformato in $file\n";
        continue;
    }

    if (!isset($data['data'])) {
        echo "❌ Campo 'data' mancante in $file\n";
        continue;
    }

    syncModpack($data['data']);
}
