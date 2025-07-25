<?php
// sync_functions.php

function syncModpack($modpack) {
    require_once 'config/config.php'; // Assuming your DB connection is here

    try {
        $sql = "INSERT INTO modpacks (...) VALUES (...)"; // Same as insert part in Option 1
        $stmt = $pdo->prepare($sql);
        foreach ($modpack as $key => &$value) {
            $paramType = PDO::PARAM_STR;
            if (is_bool($value)) {
                $paramType = PDO::PARAM_BOOL;
            } elseif (is_int($value)) {
                $paramType = PDO::PARAM_INT;
            }
            $stmt->bindParam(":$key", $value, $paramType);
        }
        $stmt->execute();
        echo "✅ Modpack '{$modpack['name']}' inserted successfully.\n";
    } catch (PDOException $e) {
        // You might want to check for duplicate entry errors specifically
        if ($e->getCode() == 23000) { // Example SQLSTATE for duplicate entry
            echo "⚠️ Modpack '{$modpack['name']}' already exists, skipping insertion.\n";
        } else {
            die("❌ Database error: " . $e->getMessage() . "\n");
        }
    }
}
?>