<?php
$logFile = $_GET['file'] ?? '';
if (!$logFile || !file_exists($logFile)) {
    echo "";
    exit;
}
echo file_get_contents($logFile);
