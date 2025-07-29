<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../config/config.php';

$jsonFile = __DIR__ . '/modpacks.json';

if (!file_exists($jsonFile)) {
    die("❌ File modpacks.json non trovato in $jsonFile\n");
}

$jsonContent = file_get_contents($jsonFile);
$modpacksArray = json_decode($jsonContent, true);

if (!is_array($modpacksArray)) {
    die("❌ Errore: modpacks.json non contiene un array valido\n");
}

echo "📦 Sincronizzazione modpacks da modpacks.json...\n";

function getLatestGameVersionFromModrinth($slug) {
    $url = "https://api.modrinth.com/v2/project/$slug/version";
    $json = @file_get_contents($url);
    if (!$json) return null;

    $versions = json_decode($json, true);
    if (!is_array($versions) || count($versions) === 0) return null;

    // Cerca la prima versione stabile con almeno una versione di Minecraft
    foreach ($versions as $v) {
        if (!empty($v['game_versions']) && !$v['version_type'] || $v['version_type'] === 'release') {
            return $v['game_versions'][0]; // Prima versione Minecraft disponibile
        }
    }

    return null;
}

foreach ($modpacksArray as $modpack) {
    $slug = $modpack['slug'] ?? '';
    $name = $modpack['name'] ?? '';

    if (empty($slug) || empty($name)) {
        echo "⚠️  Skipping modpack con dati mancanti (slug o name).\n";
        continue;
    }

    echo "🔍 Recupero versione Minecraft per $slug...\n";
    $gameVersion = getLatestGameVersionFromModrinth($slug) ?? '';

    if (empty($gameVersion)) {
        echo "⚠️  Nessuna versione trovata per $slug, salto.\n";
        continue;
    }

    // Verifica se esiste già
    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE slug = ?");
    $stmt->execute([$slug]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE modpacks SET name = ?, game_version = ?, updated_at = NOW() WHERE slug = ?");
        $stmt->execute([$name, $gameVersion, $slug]);
        echo "🔄 Aggiornato: $name ($slug) - Minecraft $gameVersion\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO modpacks (slug, name, game_version, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->execute([$slug, $name, $gameVersion]);
        echo "➕ Inserito: $name ($slug) - Minecraft $gameVersion\n";
    }
}

echo "✅ Sincronizzazione completata.\n";
