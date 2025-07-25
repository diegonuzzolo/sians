<?php
function syncModpack(array $modpack) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE name = :name AND forgeVersion = :forgeVersion");
    $stmt->execute([
        ':name' => $modpack['name'],
        ':forgeVersion' => $modpack['forgeVersion']
    ]);

    if ($stmt->rowCount() > 0) {
        echo "✅ Già esistente: {$modpack['name']} ({$modpack['forgeVersion']})\n";
        return;
    }

    $modpack['dateModified'] = date('Y-m-d H:i:s', strtotime($modpack['dateModified']));

    $insert = $pdo->prepare("
        INSERT INTO modpacks (
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
        )
    ");

    $params = [
        ':gameVersionId' => $modpack['gameVersionId'],
        ':minecraftGameVersionId' => $modpack['minecraftGameVersionId'],
        ':forgeVersion' => $modpack['forgeVersion'],
        ':name' => $modpack['name'],
        ':type' => $modpack['type'],
        ':downloadUrl' => $modpack['downloadUrl'],
        ':filename' => $modpack['filename'],
        ':installMethod' => $modpack['installMethod'],
        ':latest' => $modpack['latest'],
        ':recommended' => $modpack['recommended'],
        ':approved' => $modpack['approved'],
        ':dateModified' => $modpack['dateModified'],
        ':mavenVersionString' => $modpack['mavenVersionString'],
        ':versionJson' => $modpack['versionJson'],
        ':librariesInstallLocation' => $modpack['librariesInstallLocation'],
        ':minecraftVersion' => $modpack['minecraftVersion'],
        ':additionalFilesJson' => $modpack['additionalFilesJson'],
        ':modLoaderGameVersionId' => $modpack['modLoaderGameVersionId'],
        ':modLoaderGameVersionTypeId' => $modpack['modLoaderGameVersionTypeId'],
        ':modLoaderGameVersionStatus' => $modpack['modLoaderGameVersionStatus'],
        ':modLoaderGameVersionTypeStatus' => $modpack['modLoaderGameVersionTypeStatus'],
        ':mcGameVersionId' => $modpack['mcGameVersionId'],
        ':mcGameVersionTypeId' => $modpack['mcGameVersionTypeId'],
        ':mcGameVersionStatus' => $modpack['mcGameVersionStatus'],
        ':mcGameVersionTypeStatus' => $modpack['mcGameVersionTypeStatus'],
        ':installProfileJson' => $modpack['installProfileJson'],
    ];

    $insert->execute($params);

    echo "✅ Modpack inserito: {$modpack['name']} ({$modpack['forgeVersion']})\n";
}
