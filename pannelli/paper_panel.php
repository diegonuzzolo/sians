<?php
if (!isset($serverType) || $serverType !== 'paper') {
    http_response_code(403);
    die("Accesso negato.");
}
