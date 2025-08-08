<?php
if (!isset($serverType) || $serverType !== 'modpack') {
    http_response_code(403);
    die("Accesso negato.");
}
