<?php
if (!isset($serverType) || $serverType !== 'vanilla') {
    http_response_code(403);
    die("Accesso negato.");
}
