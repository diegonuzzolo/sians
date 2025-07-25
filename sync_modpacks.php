<?php
// Configurazione
$apiKey = '$2a$10$yykz2aOhcuZ8rQNQTvOCGO0/sgIdJ7sKUjRqOv0LmllIPEimHh9XC';
$db = new PDO('mysql:host=localhost;dbname=minecraft_platform', 'diego', 'Lgu8330Serve6');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Funzione per chiamare CurseForge API
function curseApi($endpoint, $params = []) {
    global $apiKey;
    $url = 'https://api.curseforge.com' . $endpoint . '?' . http_build_query($params);
    $opts = [
        "http" => [
            "header" => "x-api-key: $apiKey"
        ]
    ];
    $context = stream_context_create($opts);
    $response = file_get_contents($url, false, $context);
    return json_decode($response, true);
}

// Lista di modpack ID da file (es. modpack_ids.json)
$modpackIds = json_decode(file_get_contents('/var/www/html/ids.json'), true);

foreach ($modpackIds as $modpackId) {
    echo "\n⏳ Elaborazione modpack ID $modpackId...\n";

    // Controlla se già presente
    $stmt = $db->prepare("SELECT id FROM modpacks WHERE curseforge_id = ?");
    $stmt->execute([$modpackId]);

    if ($stmt->fetch()) {
        echo "✅ Modpack già presente, salto.\n";
        continue;
    }

    // Ottieni i dettagli del modpack
    $data = curseApi("/v1/mods/$modpackId");
    $mod = $data['data'] ?? null;
    if (!$mod) {
        echo "❌ Errore nel recupero dati.\n";
        continue;
    }

    $projectId = $mod['id']; // 👈 Project ID dal JSON

    // Ottieni ultima file release
    $files = curseApi("/v1/mods/$modpackId/files");
    $file = $files['data'][0] ?? null;
    if (!$file) {
        echo "❌ Nessun file disponibile.\n";
        continue;
    }

    // Prepara i campi
    $forgeVersion = 'unknown';
    $mcVersion = 'unknown';
    $modLoaderGameVersionId = 0;
    $modLoaderGameVersionTypeId = 0;
    $mcGameVersionId = 0;
    $mcGameVersionTypeId = 0;
    $versionJson = '{}';
    $installProfileJson = '{}';
    $additionalFilesJson = '[]';

    foreach ($file['gameVersions'] as $v) {
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $v)) $mcVersion = $v;
        if (stripos($v, 'forge') !== false) $forgeVersion = $v;
    }

    // Ottieni file JSON se presenti
    foreach ($file['additionalFiles'] ?? [] as $additionalFile) {
        if (str_ends_with($additionalFile['fileName'], 'version.json')) {
            $versionJson = file_get_contents($additionalFile['downloadUrl']);
        }
        if (str_ends_with($additionalFile['fileName'], 'install_profile.json')) {
            $installProfileJson = file_get_contents($additionalFile['downloadUrl']);
        }
    }

    $downloadUrl = $file['downloadUrl'] ?? null;
    if (!$downloadUrl) {
        echo "❌ Nessun downloadUrl disponibile per il file {$file['id']}.\n";
        continue;
    }

    $filename = $file['fileName'];
    $dateModified = $file['fileDate'];

    // Inserisci nel DB
    $stmt = $db->prepare("
        INSERT INTO modpacks (
            projectId,
            gameVersionId, minecraftGameVersionId, forgeVersion, name, type,
            downloadUrl, filename, installMethod, latest, recommended, approved,
            dateModified, mavenVersionString, versionJson, librariesInstallLocation,
            minecraftVersion, additionalFilesJson,
            modLoaderGameVersionId, modLoaderGameVersionTypeId,
            modLoaderGameVersionStatus, modLoaderGameVersionTypeStatus,
            mcGameVersionId, mcGameVersionTypeId,
            mcGameVersionStatus, mcGameVersionTypeStatus,
            installProfileJson
        ) VALUES (
            :projectId,
            0, 0, :forgeVersion, :name, 1,
            :downloadUrl, :filename, 1, 0, 0, 1,
            :dateModified, '', :versionJson, '', :minecraftVersion, :additionalFilesJson,
            0, 0, 1, 1, 0, 0, 1, 1,
            :installProfileJson
        )
    ");

    $stmt->execute([
        ':projectId' => $projectId,
        ':forgeVersion' => $forgeVersion,
        ':name' => $mod['name'],
        ':downloadUrl' => $downloadUrl,
        ':filename' => $filename,
        ':dateModified' => date('Y-m-d H:i:s', strtotime($dateModified)),
        ':versionJson' => $versionJson,
        ':minecraftVersion' => $mcVersion,
        ':additionalFilesJson' => json_encode($file['additionalFiles'] ?? []),
        ':installProfileJson' => $installProfileJson,
    ]);

    echo "✅ Inserito: {$mod['name']} [$mcVersion]\n";
}

echo "\n🎉 Sincronizzazione completata.\n";
