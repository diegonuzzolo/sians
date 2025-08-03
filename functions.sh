
# Funzione per scaricare jar da Maven Central dato gruppo/artifact/version
download_maven_jar() {
    local group=$1      # es: org/scala-lang
    local artifact=$2   # es: scala-library
    local version=$3    # es: 2.11.1
    local target_dir=$4 # es: /path/to/libraries/org/scala-lang/scala-library/2.11.1

    mkdir -p "$target_dir"
    local jar_path="$target_dir/$artifact-$version.jar"

    if [ ! -f "$jar_path" ]; then
        echo "📦 Scarico $artifact-$version.jar..."
        curl -s -L -o "$jar_path" "https://repo1.maven.org/maven2/$group/$artifact/$version/$artifact-$version.jar"
        echo "✅ $artifact-$version.jar scaricato in $target_dir"
    else
        echo "ℹ️ $artifact-$version.jar già presente"
    fi
}

# Funzione principale per gestire moduli mancanti
fix_missing_libraries() {
    local LOG_FILE=$1
    local SERVER_DIR=$2
    local LIBRARIES_DIR="$SERVER_DIR/libraries"

    # Mappa classi a coordinate Maven
    declare -A class_map
    class_map["scala.Product"]="org/scala-lang|scala-library|2.11.1"
    class_map["kotlin.jvm.internal.Intrinsics"]="org/jetbrains/kotlin|kotlin-stdlib|1.3.72"
    # Aggiungi altre classi se vuoi

    # Cerca classi mancanti nel log
    grep -E "ClassNotFoundException:|NoClassDefFoundError:" "$LOG_FILE" | while read -r line; do
        # Estrai nome completo classe mancante
        missing_class=$(echo "$line" | grep -oP '(ClassNotFoundException:|NoClassDefFoundError:) \K[\w\.\$]+')
        if [[ -z "$missing_class" ]]; then
            continue
        fi

        echo "⚠️ Classe mancante trovata: $missing_class"

        # Controlla se la classe è in mappa
        for key in "${!class_map[@]}"; do
            if [[ "$missing_class" == "$key"* ]]; then
                IFS='|' read -r group artifact version <<< "${class_map[$key]}"
                target_dir="$LIBRARIES_DIR/$group/$artifact/$version"
                download_maven_jar "$group" "$artifact" "$version" "$target_dir"
                break
            fi
        done
    done
}

check_and_fix_missing_mods() {
  local SERVER_DIR=$1
  local GAME_VERSION=$2
  local latest_forge_version=$3
  local LOG_FILE="$SERVER_DIR/logs/latest.log"
  local SERVER_JAR="$SERVER_DIR/forge-server.jar"
  local MAX_WAIT=60

  cd "$SERVER_DIR" || return 1

  local attempt=1

  while true; do
    echo ""
    echo "🔁 Tentativo #$attempt di avvio e diagnosi..."

    echo "🟡 Avvio server temporaneo per catturare crash..."
    /usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java -jar "$SERVER_JAR" nogui &> /dev/null &
    local SERVER_PID=$!

    echo "⏳ Attendo che il server crashi (timeout: ${MAX_WAIT}s)..."
    local timer=0
    while kill -0 "$SERVER_PID" 2>/dev/null && [ "$timer" -lt "$MAX_WAIT" ]; do
      sleep 1
      ((timer++))
    done

    if kill -0 "$SERVER_PID" 2>/dev/null; then
      echo "⚠️ Timeout raggiunto, il server non è crashato. Lo termino..."
      kill "$SERVER_PID"
      wait "$SERVER_PID" 2>/dev/null
    else
      echo "💥 Server terminato (crash o chiusura)."
    fi

    if [ ! -f "$LOG_FILE" ]; then
      echo "❌ Log non trovato: $LOG_FILE"
      return 1
    fi

    echo "🔎 Analisi delle mod mancanti nel log..."
    local missing_mods=$(sed -n '/Missing Mods:/,/^$/p' "$LOG_FILE" | tail -n +2 | awk -F ':' '{print $1}' | sed 's/^[ \t]*//;s/[ \t]*$//')

    if [ -z "$missing_mods" ]; then
      echo "✅ Nessuna mod mancante rilevata. Il server dovrebbe essere pronto!"
      break
    fi

    echo "📋 Mods mancanti trovate nel log:"
    echo "$missing_mods"
    echo "-------------------------"

    for mod in $missing_mods; do
      echo "🔍 Cerco mod '$mod' su Modrinth..."

      local slug=$(curl -sG --data-urlencode "query=$mod" "https://api.modrinth.com/v2/search" \
        | jq -r '.hits[0].project_id // empty')

      if [ -z "$slug" ]; then
        echo "❌ Nessun risultato Modrinth per '$mod'"
        continue
      fi

      echo "✅ Trovato slug: $slug"

      # Ottieni l'ultima versione compatibile con Forge
      local version=$(curl -s "https://api.modrinth.com/v2/project/$slug/version" \
        | jq -r --arg game_version "$GAME_VERSION" '.[] | select(.loaders[]? == "forge" and .game_versions[]? == $game_version) | .files[] | select(.url | endswith(".jar")).url' \
        | head -n 1)

      if [ -z "$version" ]; then
        echo "❌ Nessuna versione compatibile trovata per '$mod'"
        continue
      fi

      echo "⬇️ Scarico mod da $version"
      wget -q -O "$SERVER_DIR/mods/$mod.jar" "$version" && echo "✅ Mod '$mod' scaricata"
    done

    ((attempt++))
  done

  echo "🏁 Fine ciclo. Avvio server completo con tutte le mod..."
  bash "$SERVER_DIR/start.sh"
}

monitor_and_fix_server() {
  local SERVER_DIR=$1
  local MODS_DIR="$SERVER_DIR/mods"
  local DISABLED_MODS_DIR="$SERVER_DIR/disabled_mods"
  local LOG_FILE="$SERVER_DIR/logs/latest.log"
  local SERVER_JAR="$SERVER_DIR/forge-server.jar"
  local JAVA="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
  local MAX_WAIT=60
  local MAX_ATTEMPTS=10

  mkdir -p "$DISABLED_MODS_DIR"
  cd "$SERVER_DIR" || { echo "❌ Errore: impossibile entrare in $SERVER_DIR"; return 1; }

  local attempt=1

  while (( attempt <= MAX_ATTEMPTS )); do
    echo "🟡 Tentativo $attempt: avvio server (max $MAX_WAIT s)..."
    > "$LOG_FILE"

    $JAVA -Xmx3G -Xms3G -jar "$SERVER_JAR" nogui &>/dev/null &
    local SERVER_PID=$!

    local timer=0
    while kill -0 "$SERVER_PID" 2>/dev/null && [ "$timer" -lt "$MAX_WAIT" ]; do
      sleep 1
      ((timer++))
    done

    if kill -0 "$SERVER_PID" 2>/dev/null; then
      echo "⏱ Timeout raggiunto, server stabile"
      kill "$SERVER_PID" 2>/dev/null || true
      wait "$SERVER_PID" 2>/dev/null || true
      echo "✅ Server stabile al tentativo $attempt, esco."
      return 0
    fi

    wait "$SERVER_PID"

    echo "💥 Server crashato, analizzo log..."
    sleep 2

    if [ ! -f "$LOG_FILE" ]; then
      echo "❌ Log $LOG_FILE non trovato!"
      return 1
    fi

    # Trova mod crashanti con vari metodi e concatena risultati in una lista unica
    mods_to_disable=""

    # 1) Caught exception from <ModName> (<modid>)
    caught_modid=$(grep -oP 'Caught exception from .+ \(\K[^)]+' "$LOG_FILE" | sort -u)
    mods_to_disable+="$caught_modid"$'\n'

    # 2) NoClassDefFoundError o ClassNotFoundException per mod: estrai modid da percorso classi
    class_errors=$(grep -E 'NoClassDefFoundError|ClassNotFoundException' "$LOG_FILE" -A5 | \
      grep -oP '(?<=at )[a-zA-Z0-9_./]+' | grep -Eo '[a-z0-9_\-]+' | sort -u)

    mods_to_disable+="$class_errors"$'\n'

    # 3) RuntimeException o altri errori con pattern "invalid side <SIDE>" (estrai modid vicino)
    invalid_side_mods=$(grep -i 'invalid side' "$LOG_FILE" | grep -oP '[a-z0-9_\-]+' | sort -u)
    mods_to_disable+="$invalid_side_mods"$'\n'

    # 4) Cerca in crash report il nome mod (es. linee con "modid" o "mod name")
    modid_lines=$(grep -oP '(?<=modid=)[a-z0-9_\-]+' "$LOG_FILE" | sort -u)
    mods_to_disable+="$modid_lines"$'\n'

    # Pulisci la lista mod (unica, non vuota, case insensitive)
    mods_to_disable=$(echo "$mods_to_disable" | tr '[:upper:]' '[:lower:]' | sort -u | grep -v '^$')

    if [[ -z "$mods_to_disable" ]]; then
      echo "⚠️ Nessuna mod identificata per la disabilitazione nel log."
      return 1
    fi

    echo "🔻 Mod da disabilitare trovate:"
    echo "$mods_to_disable"

    local disabled_any=0

    for modid in $mods_to_disable; do
      # Cerca il file jar in mods che contiene il modid
      modfile=$(find "$MODS_DIR" -type f -iname "*$modid*.jar" | head -n1)
      if [[ -n "$modfile" ]]; then
        echo "🗑 Sposto mod '$modfile' in disabled_mods/"
        mv "$modfile" "$DISABLED_MODS_DIR/"
        disabled_any=1
      else
        echo "⚠️ Non trovato file jar per mod '$modid'"
      fi
    done

    if [[ $disabled_any -eq 0 ]]; then
      echo "⚠️ Nessuna mod spostata, esco per evitare loop infinito."
      return 1
    fi

    ((attempt++))
    echo "🔁 Riprovo il server dopo rimozione mod..."
    sleep 3
  done

  echo "❌ Raggiunto numero massimo tentativi ($MAX_ATTEMPTS), server non stabile."
  return 1
}
