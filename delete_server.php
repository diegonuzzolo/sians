<?php

include 'server_functions.php';

if (isset($_POST['server_id'])) {
    $serverId = intval($_POST['server_id']);
    $result = deleteServer($pdo, $serverId);

    if ($result === true) {
        echo "Server eliminato con successo!";
    } else {
        echo $result; // Mostra l'errore
    }
}
