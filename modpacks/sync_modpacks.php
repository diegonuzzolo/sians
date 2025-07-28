<?php
require __DIR__.'/../config/config.php';

// Slug dei modpack che vuoi importare
$modpacksToSync = [
    'better-mc-fabric',      // esempio: Better MC [FABRIC]
    'another-fabric-pack'    // aggiungi gli slug che ti interessano
];

foreach ($modpacksToSync as $slug) {
    echo "📦 Recupero modpack: $slug\n";

    // Recupera info progetto
    $projectJson = file_get_contents("https://api.modrinth.com/v2/project/$slug");
    if (!$projectJson) {
        echo "❌ Errore nel recupero del progetto $slug\n";
        continue;
    }

    $project = json_decode($projectJson, true);
    $projectId = $project['id'];
    $name = $project['title'];
    $description = $project['description'] ?? '';
    $iconUrl = $project['icon_url'] ?? '';
    $slug = $project['slug'];

    // Recupera l'ultima versione compatibile con Fabric
    $versionJson = file_get_contents("https://api.modrinth.com/v2/project/$slug/version");
    $versions = json_decode($versionJson, true);

    $selectedVersion = null;
    foreach ($versions as $v) {
        if (in_array("fabric", $v['loaders'])) {
            $selectedVersion = $v;
            break;
        }
    }

    if (!$selectedVersion) {
        echo "❌ Nessuna versione Fabric trovata per $slug\n";
        continue;
    }

    $versionId = $selectedVersion['id'];
    $versionName = $selectedVersion['name'];
    $files = $selectedVersion['files'];
    $downloadUrl = $files[0]['url'] ?? null;

    if (!$downloadUrl) {
        echo "❌ Nessun file da scaricare per $slug\n";
        continue;
    }

    // Inserisci nel DB
    $stmt = $pdo->prepare("INSERT INTO modpacks (modrinth_id, slug, name, version_id, version_name, download_url, description, icon_url)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                             name = VALUES(name),
                             version_id = VALUES(version_id),
                             version_name = VALUES(version_name),
                             download_url = VALUES(download_url),
                             description = VALUES(description),
                             icon_url = VALUES(icon_url)");
                             
    $stmt->execute([
        $projectId,
        $slug,
        $name,
        $versionId,
        $versionName,
        $downloadUrl,
        $description,
        $iconUrl
    ]);

    echo "✅ Modpack $name ($versionName) sincronizzato\n";
}
