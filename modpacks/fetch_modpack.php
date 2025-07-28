<?php
require '../config/config.php';  // connessione DB



function fetchModpacksFromModrinth() {
    $url = "https://api.modrinth.com/v2/projects?categories=modpacks&facets=[\"categories:fabric\"]&limit=50";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if(curl_errno($ch)) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['hits'])) {
        throw new Exception("Invalid API response");
    }
    return $data['hits']; // array di modpack
}

function saveOrUpdateModpack($pdo, $modpack) {
    // Campi da salvare nella tabella modpacks
    $slug = $modpack['slug'] ?? '';
    $title = $modpack['title'] ?? '';
    $description = $modpack['description'] ?? '';
    $categories = json_encode($modpack['categories'] ?? []);
    $versions_json = json_encode($modpack['versions'] ?? []);
    
    // Controlla se esiste già
    $stmt = $pdo->prepare("SELECT id FROM modpacks WHERE slug = ?");
    $stmt->execute([$slug]);
    $exists = $stmt->fetchColumn();
    
    if ($exists) {
        // Update
        $stmt = $pdo->prepare("UPDATE modpacks SET title = ?, description = ?, categories = ?, versions_json = ?, last_updated = NOW() WHERE slug = ?");
        $stmt->execute([$title, $description, $categories, $versions_json, $slug]);
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO modpacks (slug, title, description, categories, versions_json) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$slug, $title, $description, $categories, $versions_json]);
    }
}

try {
    $modpacks = fetchModpacksFromModrinth();
    foreach ($modpacks as $modpack) {
        saveOrUpdateModpack($pdo, $modpack);
    }
    echo "Sincronizzazione modpack completata. Totale modpack salvati/aggiornati: " . count($modpacks) . "\n";
} catch (Exception $e) {
    echo "Errore: " . $e->getMessage() . "\n";
}
