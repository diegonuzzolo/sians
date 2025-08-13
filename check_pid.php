<?php
header('Content-Type: application/json');
$pid = intval($_GET['pid'] ?? 0);
if (!$pid) {
    echo json_encode(['running' => false]);
    exit;
}

$running = (bool) shell_exec("ps -p $pid | grep $pid");
echo json_encode(['running' => $running]);
