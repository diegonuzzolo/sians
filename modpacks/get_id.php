<?php
function fetchModrinthForgeModpacks($offset = 0, $limit = 50) {
    $url = "https://api.modrinth.com/v2/search/project";

    $postData = [
        "facets" => [
            ["categories:modpacks"],
            ["loaders:forge"]
        ],
        "index" => "downloads",
        "offset" => $offset,
        "limit" => $limit
    ];

    $payload = json_encode($postData);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode != 200) {
        echo "Errore API Modrinth, HTTP status code: $httpCode\n";
        return null;
    }

    return json_decode($response, true);
}

$offset = 0;
$limit = 50;

$data = fetchModrinthForgeModpacks($offset, $limit);

if (!$data || !isset($data['hits'])) {
    die("Errore nel recupero dati Modrinth\n");
}

foreach ($data['hits'] as $project) {
    $id = $project['id'] ?? 'N/A';
    $slug = $project['slug'] ?? 'N/A';
    $title = $project['title'] ?? $slug;
    echo "ID: $id | Slug: $slug | Title: $title\n";
}
