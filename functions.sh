fix_missing_mods() {
    LAST_CRASH=$1
    MODS_DIR=$2
    GAME_VERSION=$3
    local max_attempts=10   # Evita loop infiniti
    local attempt=1

    while [ $attempt -le $max_attempts ]; do
        echo "⚙️  Tentativo #$attempt di avvio del server..."

        # Avvia il server in background
        cd "$SERVER_DIR"
        ./start.sh &> /dev/null &
        SERVER_PID=$!
        sleep 10  # Attendi che crashi o si stabilizzi

        if ps -p $SERVER_PID > /dev/null; then
            echo "✅ Server avviato correttamente al tentativo #$attempt."
            kill $SERVER_PID
            wait $SERVER_PID 2>/dev/null
            return 0  # Fine, server stabile
        else
            echo "❌ Server crashato. Controllo crash log..."

            LAST_CRASH=$(ls -t "$SERVER_DIR/crash-reports/"*.txt 2>/dev/null | head -n 1)

            if [ -z "$LAST_CRASH" ]; then
                echo "⚠️  Nessun crash log trovato. Esco."
                return 1
            fi

            fix_missing_mods "$LAST_CRASH" "$MODS_DIR" "$GAME_VERSION"

            ((attempt++))
        fi
    done

    echo "🚫 Raggiunto numero massimo di tentativi ($max_attempts). Server ancora instabile."
    return 1
}
