<?php
// server_action.php

session_start();
require 'config/config.php'; // Make sure this path is correct for your setup
require 'includes/auth.php'; // Make sure this path is correct for your setup




try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
   # echo "✅ Connessione DB OK\n";
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage());
}
// --- 1. Authentication and Request Validation ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    error_log("[server_action] Unauthorized access attempt: No user_id in session.");
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['server_id'], $_POST['action'])) {
    http_response_code(400);
    error_log("[server_action] Bad request: Missing server_id or action in POST data.");
    exit('Invalid request');
}

$userId = $_SESSION['user_id'];
$serverId = intval($_POST['server_id']);
$action = $_POST['action']; // Expected: 'start' or 'stop'

// --- 2. Fetch Server and VM Information ---
// Ensure the user owns the server and retrieve its details
try {
    $stmt = $pdo->prepare("
        SELECT vm.ip AS ip_address, s.ssh_user
        FROM servers s
        JOIN minecraft_vms vm ON s.vm_id = vm.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->execute([$serverId, $userId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        http_response_code(404);
        error_log("[server_action] Server not found or not owned by user: user_id={$userId}, server_id={$serverId}");
        exit('Server not found');
    }

    $ip = $server['ip_address'];
    $sshUser = $server['ssh_user'];

} catch (PDOException $e) {
    http_response_code(500);
    error_log("[server_action] Database error during server info retrieval: " . $e->getMessage());
    exit('Internal server error');
}


// --- 3. Define SSH Commands and Validate Action ---
// IMPORTANT: Adjust the path to 'java' if it's not in the default PATH on your VM
// Use a higher timeout for 'start' as Minecraft servers can take a while to boot.
$cmds = [
    'start' => "cd ~/server && screen -dmS mcserver /usr/bin/java -Xmx10G -Xms10G -jar server.jar nogui",
    'stop'  => "screen -S mcserver -X stuff \"stop$(printf '\\r')\""
];

if (!isset($cmds[$action])) {
    http_response_code(400);
    error_log("[server_action] Invalid action specified: {$action}");
    exit('Invalid action');
}

// --- 4. Prepare and Execute SSH Command ---
// Path to your SSH private key on the web server
// Ensure this file has correct permissions (chmod 600) and is readable by your web server user!
$privateKeyPath = '/home/diego/.ssh/id_rsa';

// The timeout should be generous, especially for 'start'
$timeoutSeconds = ($action === 'start') ? 180 : 60; // 3 minutes for start, 1 minute for stop
$wrappedCmd = sprintf("timeout %d bash -c %s", $timeoutSeconds, escapeshellarg($cmds[$action]));

$sshCommand = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no %s@%s "%s" 2>&1', // Redirect stderr to stdout for exec output
    escapeshellarg($privateKeyPath),
    escapeshellarg($sshUser),
    escapeshellarg($ip),
    $wrappedCmd
);

error_log("[server_action] Executing SSH command for user_id={$userId}, server_id={$serverId}, action={$action}: {$sshCommand}");

$output = [];
$exitCode = null;
exec($sshCommand, $output, $exitCode);

error_log("[server_action] SSH Command Exit Code: {$exitCode} for user_id={$userId}, server_id={$serverId}");
error_log("[server_action] SSH Command Output:\n" . implode("\n", $output));

// --- 5. Handle Command Results and Update Server Status ---
if ($exitCode === 0) {
    $newStatus = ($action === 'start') ? 'running' : 'stopped';
    try {
        $update = $pdo->prepare("UPDATE servers SET status = ? WHERE id = ?");
        $res = $update->execute([$newStatus, $serverId]);

        if ($res) {
            error_log("[server_action] Server status updated to '{$newStatus}' for server_id={$serverId}");
            header('Location: dashboard.php?msg=success');
            exit;
        } else {
            error_log("[server_action] Failed to update status for server_id={$serverId} after successful SSH command.");
            header('Location: dashboard.php?msg=db_update_error');
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("[server_action] Database error during status update: " . $e->getMessage());
        header('Location: dashboard.php?msg=db_error');
        exit;
    }
} else {
    // Log specific error codes for better debugging
    $errorMessage = 'ssh_error';
    if ($exitCode === 124) { // 124 is the exit code for `timeout`
        $errorMessage = 'ssh_timeout';
        error_log("[server_action] SSH command timed out (exitCode=124) for user_id={$userId}, server_id={$serverId}. Increase timeout or check VM responsiveness.");
    } else {
        error_log("[server_action] SSH command failed with exitCode={$exitCode} for user_id={$userId}, server_id={$serverId}. Output: " . implode("\n", $output));
    }
    header('Location: dashboard.php?msg=' . $errorMessage);
    exit;
}
?>