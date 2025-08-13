<?php
$ip = $_GET['ip'] ?? '';
header('Content-Type: application/json');

if (!$ip) {
    echo json_encode(['sshReady' => false]);
    exit;
}

$timeout = 2; // secondi
$fp = @fsockopen($ip, 22, $errno, $errstr, $timeout);
if ($fp) {
    fclose($fp);
    echo json_encode(['sshReady' => true]);
} else {
    echo json_encode(['sshReady' => false]);
}
