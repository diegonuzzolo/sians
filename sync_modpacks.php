<?php
require_once 'db.php'; // connessione PDO

function syncModpack(array $modpack) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE name = :name AND forgeVersion = :forgeVersion");
    $stmt->execute([
        ':name' => $modpack['name'],
        ':forgeVersion' => $modpack['forgeVersion']
    ]);

    if ($stmt->rowCount() > 0) {
        echo "Modpack già esistente: {$modpack['name']} ({$modpack['forgeVersion']})\n";
        return;
    }

    $insert = $pdo->prepare("INSERT INTO modpacks (
        gameVersionId, minecraftGameVersionId, forgeVersion, name, type, downloadUrl, filename,
        installMethod, latest, recommended, approved, dateModified, mavenVersionString, versionJson,
        librariesInstallLocation, minecraftVersion, additionalFilesJson, modLoaderGameVersionId,
        modLoaderGameVersionTypeId, modLoaderGameVersionStatus, modLoaderGameVersionTypeStatus,
        mcGameVersionId, mcGameVersionTypeId, mcGameVersionStatus, mcGameVersionTypeStatus,
        installProfileJson
    ) VALUES (
        :gameVersionId, :minecraftGameVersionId, :forgeVersion, :name, :type, :downloadUrl, :filename,
        :installMethod, :latest, :recommended, :approved, :dateModified, :mavenVersionString, :versionJson,
        :librariesInstallLocation, :minecraftVersion, :additionalFilesJson, :modLoaderGameVersionId,
        :modLoaderGameVersionTypeId, :modLoaderGameVersionStatus, :modLoaderGameVersionTypeStatus,
        :mcGameVersionId, :mcGameVersionTypeId, :mcGameVersionStatus, :mcGameVersionTypeStatus,
        :installProfileJson
    )");

    $modpack['dateModified'] = date('Y-m-d H:i:s', strtotime($modpack['dateModified']));

    $insert->execute($modpack);
    echo "Modpack inserito: {$modpack['name']} ({$modpack['forgeVersion']})\n";
}

// esempio:
$json = file_get_contents('modpack.json'); // file JSON come quello che hai fornito
$data = json_decode($json, true);
syncModpack($data['data']);
