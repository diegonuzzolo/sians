<?php
// sync_functions.php

function syncModpack($modpack) {
    // Database connection (assuming $pdo is available or established here)
    // You should ideally establish this connection once, e.g., in config.php
    require_once 'config/config.php'; // Assuming your DB connection is here

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM modpacks WHERE downloadUrl = :downloadUrl");
        $stmt->bindParam(':downloadUrl', $modpack['downloadUrl']);
        $stmt->execute();
        $exists = $stmt->fetchColumn();

        if ($exists) {
            // Update existing record
            $sql = "UPDATE modpacks SET
                        gameVersionId = :gameVersionId,
                        minecraftGameVersionId = :minecraftGameVersionId,
                        forgeVersion = :forgeVersion,
                        name = :name,
                        type = :type,
                        filename = :filename,
                        installMethod = :installMethod,
                        latest = :latest,
                        recommended = :recommended,
                        approved = :approved,
                        dateModified = :dateModified,
                        minecraftVersion = :minecraftVersion
                    WHERE downloadUrl = :downloadUrl";

            $stmt = $pdo->prepare($sql);
            // Bind all parameters
            $stmt->bindParam(':gameVersionId', $modpack['gameVersionId']);
            $stmt->bindParam(':minecraftGameVersionId', $modpack['minecraftGameVersionId']);
            $stmt->bindParam(':forgeVersion', $modpack['forgeVersion']);
            $stmt->bindParam(':name', $modpack['name']);
            $stmt->bindParam(':type', $modpack['type']);
            $stmt->bindParam(':filename', $modpack['filename']);
            $stmt->bindParam(':installMethod', $modpack['installMethod']);
            $stmt->bindParam(':latest', $modpack['latest'], PDO::PARAM_BOOL);
            $stmt->bindParam(':recommended', $modpack['recommended'], PDO::PARAM_BOOL);
            $stmt->bindParam(':approved', $modpack['approved'], PDO::PARAM_BOOL);
            $stmt->bindParam(':dateModified', $modpack['dateModified']);
            $stmt->bindParam(':minecraftVersion', $modpack['minecraftVersion']);
            $stmt->bindParam(':downloadUrl', $modpack['downloadUrl']); // For WHERE clause

            $stmt->execute();
            echo "✅ Modpack '{$modpack['name']}' updated successfully.\n";
        } else {
            // Insert new record
            $sql = "INSERT INTO modpacks (
                        gameVersionId, minecraftGameVersionId, forgeVersion, name, type,
                        downloadUrl, filename, installMethod, latest, recommended,
                        approved, dateModified, mavenVersionString, versionJson,
                        librariesInstallLocation, minecraftVersion, additionalFilesJson,
                        modLoaderGameVersionId, modLoaderGameVersionTypeId, modLoaderGameVersionStatus,
                        modLoaderGameVersionTypeStatus, mcGameVersionId, mcGameVersionTypeId,
                        mcGameVersionStatus, mcGameVersionTypeStatus, installProfileJson
                    ) VALUES (
                        :gameVersionId, :minecraftGameVersionId, :forgeVersion, :name, :type,
                        :downloadUrl, :filename, :installMethod, :latest, :recommended,
                        :approved, :dateModified, :mavenVersionString, :versionJson,
                        :librariesInstallLocation, :minecraftVersion, :additionalFilesJson,
                        :modLoaderGameVersionId, :modLoaderGameVersionTypeId, :modLoaderGameVersionStatus,
                        :modLoaderGameVersionTypeStatus, :mcGameVersionId, :mcGameVersionTypeId,
                        :mcGameVersionStatus, :mcGameVersionTypeStatus, :installProfileJson
                    )";

            $stmt = $pdo->prepare($sql);
            // Bind all parameters
            foreach ($modpack as $key => &$value) {
                // Determine the correct PDO type
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
        }
    } catch (PDOException $e) {
        die("❌ Database error: " . $e->getMessage() . "\n");
    }
}
?>