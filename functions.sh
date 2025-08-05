fix_missing_mods() {
    local crash_log="$1"
    local mods_folder="$2"
    local mc_version="$3"
    local loader="forge"

    if ! command -v jq &> /dev/null; then
        echo "❌ Richiesto 'jq'. Installa con: sudo apt install jq"
        return 1
    fi

    [ ! -f "$crash_log" ] && echo "❌ File crash log non trovato: $crash_log" && return 1
    mkdir -p "$mods_folder"

    echo "📄 Leggo crash log per estrarre i nomi delle mod mancanti (dipendenze)..."

    # Estrai la mod mancante dopo 'requires' (es. 'silentlib', 'create', ecc.)
    local missing_mods=$(grep -Po 'Mod \S+ requires \K\S+' "$crash_log" | sort -u)

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

        echo "⬇️ Recupero versione compatibile con $mc_version + $loader..."
        local file_url=$(curl -s "https://api.modrinth.com/v2/project/$project_id/version" |
            jq -r --arg mc "$mc_version" --arg l "$loader" '
                .[] | select(.game_versions[]? == $mc and .loaders[]? == $l) | .files[0].url' |
            head -n 1)

        if [ -z "$file_url" ] || [ "$file_url" == "null" ]; then
            echo "⚠️ Nessuna versione compatibile trovata per '$mod'"
            continue
        fi

        local filename=$(basename "$file_url")

        echo "📦 Scarico $filename..."
        if curl -s -L -o "$mods_folder/$filename" "$file_url"; then
            echo "✅ Salvata in $mods_folder/"
        else
            echo "❌ Errore durante il download di $filename"
        fi
    done
}



attempt_fix_missing_mods_loop() {
    local attempt=1
    local max_attempts=10

    while [ $attempt -le $max_attempts ]; do
        echo "🔁 Tentativo #$attempt di avvio server..."

        cd "$SERVER_DIR"
        bash start.sh &
        SERVER_PID=$!
        wait $SERVER_PID

        # Controlla se c'è un crash report
        LAST_CRASH=$(ls -t "$SERVER_DIR/crash-reports/"*.txt 2>/dev/null | head -n 1)

        if [ -z "$LAST_CRASH" ]; then
            echo "✅ Server terminato senza crash. Nessun crash report trovato."
            break
        fi

        echo "❌ Crash rilevato. Analizzo: $LAST_CRASH"
        fix_missing_mods "$LAST_CRASH" "$MODS_DIR" "$GAME_VERSION"
        ((attempt++))
    done

    if [ $attempt -gt $max_attempts ]; then
        echo "❌ Superato il numero massimo di tentativi ($max_attempts). Interrotto."
    else
        echo "✅ Server pronto. Nessuna mod mancante rilevata."
    fi
}
