<?php

$limit = 100;
$offset = 0;
$allProjects = [];

do {
    $query = [
        'query' => '',
        'facets' => json_encode([
            ['project_type:modpack']
        ]),
        'limit' => $limit,
        'offset' => $offset,
        'index' => 'downloads' // o relevance, newest, etc.
    ];

    $url = "https://api.modrinth.com/v2/search?" . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo "Errore API Modrinth, codice: $http_code\n";
        exit;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['hits'])) {
        echo "Risposta non valida o senza risultati.\n";
        exit;
    }

    foreach ($data['hits'] as $project) {
        $allProjects[] = [
            'id' => $project['project_id'],
            'slug' => $project['slug'],
            'title' => $project['title']
        ];
    }

    $count = count($data['hits']);
    $offset += $count;
} while ($count === $limit);

foreach ($allProjects as $project) {
    echo "ID: {$project['id']} | Slug: {$project['slug']} | Titolo: {$project['title']}\n";
}

echo "Totale modpack trovati: " . count($allProjects) . "\n";
