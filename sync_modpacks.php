<?php
require_once 'config/config.php'; // connessione PDO


try {
    // Connessione usando PDO
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASSWORD);

    // Imposta modalità errore a eccezioni
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Opzionale: modalità fetch di default in array associativi
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Debug opzionale
    // echo "Connessione al database avvenuta con successo.\n";
} catch (PDOException $e) {
    // Gestione errore
    die("Errore di connessione al database: " . $e->getMessage());
}



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

    // Normalizza la data
    $modpack['dateModified'] = date('Y-m-d H:i:s', strtotime($modpack['dateModified']));

    // Prepara solo i parametri richiesti nella query (in ordine)
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

    echo "Modpack inserito: {$modpack['name']} ({$modpack['forgeVersion']})\n";
}



// file JSON come quello che hai fornito
$json = file_get_contents(__DIR__ . '/modpack.json');
$data = json_decode($json, true);
syncModpack($data['data']);
