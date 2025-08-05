fix_missing_mods() {
     local crash_log="$1"
    local mods_folder="mods"
    local mc_version="1.20.1"
    local loader="forge"

    if ! command -v jq &> /dev/null; then
        echo "❌ Richiesto 'jq'. Installa con: sudo apt install jq"
        return 1
    fi

    [ ! -f "$crash_log" ] && echo "❌ File crash log non trovato: $crash_log" && return 1
    mkdir -p "$mods_folder"

    echo "📄 Leggo crash log per estrarre i nomi delle mod mancanti..."

    local missing_mods=$(grep -oP 'Mod \K[^ ]+(?= requires)' "$crash_log" | sort -u)

    if [ -z "$missing_mods" ]; then
        echo "✅ Nessuna mod mancante trovata."
        return 0
    fi

    for mod in $missing_mods; do
        echo -e "\n🔍 Cerco '$mod' su Modrinth..."
        local response=$(curl -sG --data-urlencode "query=$mod" "https://api.modrinth.com/v2/search")
        local project_id=$(echo "$response" | jq -r '.hits[0].project_id')

        if [ -z "$project_id" ] || [ "$project_id" == "null" ]; then
            echo "❌ Mod '$mod' non trovata su Modrinth"
            continue
        fi

        echo "✅ Trovata: project_id = $project_id"

        echo "⬇️  Recupero versione compatibile con $mc_version + $loader..."
        local version=$(curl -s "https://api.modrinth.com/v2/project/$project_id/version" |
            jq -r --arg mc "$mc_version" --arg l "$loader" \
            '[.[] | select(.game_versions[]? == $mc and .loaders[]? == $l)][0]')

        if [ "$version" == "null" ] || [ -z "$version" ]; then
            echo "⚠️  Nessuna versione compatibile trovata per '$mod'"
            continue
        fi

        local file_url=$(echo "$version" | jq -r '.files[0].url')
        local filename=$(basename "$file_url")

        echo "📦 Scarico $filename..."
        curl -s -L -o "$mods_folder/$filename" "$file_url" && echo "✅ Salvata in $mods_folder/"
    done
}
