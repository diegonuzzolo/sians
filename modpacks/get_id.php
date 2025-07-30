<?php
// CONFIG
$apiUrl = "https://api.modrinth.com/v2/search";
$token = "mrp_RvSag6ASSA006S77GkMC97sqT0jpSqVPrTn6kSCnMtAMm6ydwxW5g6rAqLt2";
$limit = 100; // massimo per chiamata
$offset = 0;
$allProjects = [];

do {
    $queryData = [
        "query" => "",
        "facets" => json_encode([
            ["project_type:modpack"]
        ]),
        "index" => "downloads", // oppure "relevance"
        "limit" => $limit,
        "offset" => $offset
    ];

    $queryString = http_build_query($queryData);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$apiUrl?$queryString");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: Modrinth Sync Script",
        "Authorization: Bearer $token"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Errore nella chiamata Modrinth: codice HTTP $httpCode\n";
        exit;
    }

    $data = json_decode($response, true);
    if (!isset($data["hits"])) {
        echo "Risposta inattesa: " . $response . "\n";
        exit;
    }

    foreach ($data["hits"] as $project) {
        $allProjects[] = [
            "id" => $project["project_id"],
            "slug" => $project["slug"],
            "title" => $project["title"],
            "downloads" => $project["downloads"]
        ];
    }

    $offset += $limit;
} while (count($data["hits"]) === $limit);

// STAMPA O SALVA
foreach ($allProjects as $project) {
    echo "ID: {$project['id']} | Slug: {$project['slug']} | Title: {$project['title']} | Downloads: {$project['downloads']}\n";
}

// FACOLTATIVO: salva in JSON
// file_put_contents('modpacks_list.json', json_encode($allProjects, JSON_PRETTY_PRINT));
