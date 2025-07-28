<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../config/config.php';

// Percorso file JSON modpacks locale
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

foreach ($modpacksArray as $modpack) {
    // Validazione campi
    $slug = $modpack['slug'] ?? '';
    $name = $modpack['name'] ?? '';
    $minecraftVersion = isset($modpack['minecraftVersion']) && !empty($modpack['minecraftVersion']) 
                        ? $modpack['minecraftVersion'] 
                        : '';

    if (empty($slug) || empty($name)) {
        echo "⚠️  Skipping modpack con dati mancanti (slug o name).\n";
        continue;
    }

    // Verifica se modpack esiste già nel DB tramite slug
    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE slug = ?");
    $stmt->execute([$slug]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Aggiorna record esistente
        $stmt = $pdo->prepare("UPDATE modpacks SET name = ?, minecraftVersion = ?, updated_at = NOW() WHERE slug = ?");
        $stmt->execute([$name, $minecraftVersion, $slug]);
        echo "🔄 Aggiornato modpack: $name ($slug)\n";
    } else {
        // Inserisci nuovo record
        $stmt = $pdo->prepare("INSERT INTO modpacks (slug, name, minecraftVersion, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->execute([$slug, $name, $minecraftVersion]);
        echo "➕ Inserito modpack: $name ($slug)\n";
    }
}

echo "✅ Sincronizzazione completata.\n";
