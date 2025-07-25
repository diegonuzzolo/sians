<?php
function syncModpack(array $modpack) {
    global $pdo;
    echo "➡️ Sync modpack: {$modpack['name']} ({$modpack['forgeVersion']})\n";

    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE name = :name AND forgeVersion = :forgeVersion");
    $stmt->execute([
        ':name' => $modpack['name'],
        ':forgeVersion' => $modpack['forgeVersion']
    ]);

    if ($stmt->rowCount() > 0) {
        echo "⚠️ Già esistente: {$modpack['name']} ({$modpack['forgeVersion']})\n";
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
        ':gameVersionId' => $modpack['gameVersionId'] ?? 0,
        ':minecraftGameVersionId' => $modpack['minecraftGameVersionId'] ?? 0,
        ':forgeVersion' => $modpack['forgeVersion'] ?? 'unknown',
        ':name' => $modpack['name'] ?? 'unknown',
        ':type' => $modpack['type'] ?? 0,
        ':downloadUrl' => $modpack['downloadUrl'] ?? '',
        ':filename' => $modpack['filename'] ?? '',
        ':installMethod' => $modpack['installMethod'] ?? 1,
        ':latest' => $modpack['latest'] ?? false,
        ':recommended' => $modpack['recommended'] ?? false,
        ':approved' => $modpack['approved'] ?? true,
        ':dateModified' => $modpack['dateModified'],
        ':mavenVersionString' => $modpack['mavenVersionString'] ?? '',
        ':versionJson' => $modpack['versionJson'] ?? '{}',
        ':librariesInstallLocation' => $modpack['librariesInstallLocation'] ?? '',
        ':minecraftVersion' => $modpack['minecraftVersion'] ?? '',
        ':additionalFilesJson' => $modpack['additionalFilesJson'] ?? '[]',
        ':modLoaderGameVersionId' => $modpack['modLoaderGameVersionId'] ?? 0,
        ':modLoaderGameVersionTypeId' => $modpack['modLoaderGameVersionTypeId'] ?? 0,
        ':modLoaderGameVersionStatus' => $modpack['modLoaderGameVersionStatus'] ?? 1,
        ':modLoaderGameVersionTypeStatus' => $modpack['modLoaderGameVersionTypeStatus'] ?? 1,
        ':mcGameVersionId' => $modpack['mcGameVersionId'] ?? 0,
        ':mcGameVersionTypeId' => $modpack['mcGameVersionTypeId'] ?? 0,
        ':mcGameVersionStatus' => $modpack['mcGameVersionStatus'] ?? 1,
        ':mcGameVersionTypeStatus' => $modpack['mcGameVersionTypeStatus'] ?? 1,
        ':installProfileJson' => $modpack['installProfileJson'] ?? '{}',
    ];

    try {
        $insert->execute($params);
        echo "✅ Inserito modpack: {$modpack['name']} ({$modpack['forgeVersion']})\n";
    } catch (PDOException $e) {
        echo "❌ Errore inserimento: " . $e->getMessage() . "\n";
    }
}
