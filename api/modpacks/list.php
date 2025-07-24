<?php
header('Content-Type: application/json');

$dir = __DIR__ . '/../../data/modpacks';
$files = scandir($dir);

$modpacks = [];

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
        $modpacks[] = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'filename' => $file,
            'url' => 'https://sians.it/data/modpacks/' . $file
        ];
    }
}

echo json_encode($modpacks);
