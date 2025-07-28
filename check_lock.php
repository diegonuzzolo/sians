<?php
header('Content-Type: application/json');

// Controlla se ci sono file install.lock in /home/diego/*/
$lockFiles = glob('/home/diego/*/install.lock');

echo json_encode([
    'installing' => !empty($lockFiles)
]);
