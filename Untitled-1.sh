#!/bin/bash
source "/home/diego/functions.sh"

set -x

TYPE=$1
VERSION_OR_SLUG=$2
URL=$3
METHOD=$4
SERVER_ID=$5
GAME_VERSION=$6
MODPACK_ID=$7
MODPACK_SLUG=$8

BASE_DIR="/home/diego/minecraft_servers"
SERVER_DIR="$BASE_DIR/$SERVER_ID"
LOG_FILE="$SERVER_DIR/setup.log"
MODS_DIR="$SERVER_DIR/mods"
UPDATE_URL="https://sians.it/update_status.php"
UPDATE_TOKEN="la_luna_il_mio_cane_numero_uno"
JAVA_BIN=""

CRASH_LOG="$SERVER_DIR/test.log"


mkdir -p "$SERVER_DIR" "$MODS_DIR" "$SERVER_DIR/logs" "$SERVER_DIR/debug"



select_java() {
    echo "🎮 Selezione Java per GAME_VERSION: '$GAME_VERSION'"

    case "$GAME_VERSION" in
        1.7*|1.8*|1.9*|1.10*|1.11*|1.12*)
            # Minecraft 1.12 e precedenti: Java 8
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.13*|1.14*|1.15*)
            # Da Minecraft 1.13 a 1.15: Java 8 consigliato, Java 11 supportato
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.16*)
            # Minecraft 1.16: Java 8 consigliato, Java 11 supportato
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.17*)
            # Minecraft 1.17 richiede Java 16 (ma Java 17 funziona)
            JAVA_BIN="/usr/lib/jvm/java-17-openjdk-amd64/bin/java"
            ;;
        1.18*|1.19*|1.20*|1.21*)
            # Minecraft 1.18+ richiede Java 17
            JAVA_BIN="/usr/lib/jvm/java-17-openjdk-amd64/bin/java"
            ;;
        *)
            echo "❌ Versione Minecraft non riconosciuta o troppo nuova: '$GAME_VERSION'"
            exit 1
            ;;
    esac

    echo "✅ Java selezionato: \$JAVA_BIN"
}


log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

update_status() {
    local status="$1"
    local progress="${2:-null}"
    curl -s -X POST -H "Content-Type: application/json" \
         -H "Authorization: Bearer $UPDATE_TOKEN" \
         -d "{\"server_id\": \"$SERVER_ID\", \"status\": \"$status\", \"progress\": $progress}" \
         "$UPDATE_URL" > /dev/null
}

log "🚀 Avvio installazione server $SERVER_ID (tipo: $TYPE, versione: $VERSION_OR_SLUG)"
update_status "installing" 1

cd "$SERVER_DIR"

# === VANILLA INSTALLATION ===
if [ "$TYPE" == "vanilla" ]; then
    update_status "installing" 10
    log "⬇️ Scarico server Vanilla $VERSION_OR_SLUG"
    MANIFEST_URL="https://launchermeta.mojang.com/mc/game/version_manifest.json"
    VANILLA_URL=$(curl -s "$MANIFEST_URL" | jq -r --arg v "$VERSION_OR_SLUG" '.versions[] | select(.id == $v) | .url' | xargs curl -s | jq -r '.downloads.server.url')
    curl -L -o server.jar "$VANILLA_URL"
    update_status "installing" 40
fi

# === PAPER INSTALLATION ===
if [ "$TYPE" == "paper" ]; then
    update_status "installing" 10
    log "⬇️ Scarico server Paper $VERSION_OR_SLUG"
    BUILD=$(curl -s "https://api.papermc.io/v2/projects/paper/versions/$VERSION_OR_SLUG" | jq -r '.builds[-1]')
    PAPER_URL="https://api.papermc.io/v2/projects/paper/versions/$VERSION_OR_SLUG/builds/$BUILD/downloads/paper-$VERSION_OR_SLUG-$BUILD.jar"
    curl -L -o server.jar "$PAPER_URL"
    update_status "installing" 40
fi

mkdir -p "$MODS_DIR"

# === FORGE MODPACK INSTALLATION ===
if [ "$METHOD" == "modrinth" ]; then
    log "🎯 Installazione Modrinth per server Forge ($GAME_VERSION)"
    update_status "downloading_mods" 2

    # Scarica Forge compatibile con GAME_VERSION
    MAVEN_URL="https://files.minecraftforge.net/maven/net/minecraftforge/forge/maven-metadata.xml"
    versions=$(curl -sL "$MAVEN_URL")
    latest_forge_version=$(echo "$versions" | grep -oP "(?<=<version>)${GAME_VERSION}-.*?(?=</version>)" | sort -V | tail -n1)
    [ -z "$latest_forge_version" ] && log "❌ Nessuna versione Forge trovata" && exit 1

    FORGE_JAR_URL="https://maven.minecraftforge.net/net/minecraftforge/forge/$latest_forge_version/forge-$latest_forge_version-installer.jar"
    log "⬇️ Scarico Forge $latest_forge_version"
    curl -fsSL "$FORGE_JAR_URL" -o "$SERVER_DIR/forge-installer.jar" || { log "❌ Download Forge fallito"; exit 1; }

    update_status "downloading_mods" 3

    # Scarica direttamente l'URL del Modpack (.mrpack)
    MODRINTH_PACK="$SERVER_DIR/modpack_modrinth"
    mkdir -p "$MODRINTH_PACK"
    log "⬇️ Scarico pacchetto Modrinth da: $URL"
    curl -fsSL "$URL" -o "$MODRINTH_PACK/pack.mrpack" || { log "❌ Download Modpack fallito"; exit 1; }

    # Estrai .mrpack
    log "📦 Estrazione .mrpack"
    unzip -oq "$MODRINTH_PACK/pack.mrpack" -d "$MODRINTH_PACK" && rm "$MODRINTH_PACK/pack.mrpack"
    update_status "extracting_mods" 4
    chown -R diego:diego modpack_modrinth

    # Sposta mod da /mods o /overrides/mods
    if [ -d "$MODRINTH_PACK/mods" ]; then
        log "📁 Sposto mod da mods/"
        mv "$MODRINTH_PACK/mods/"* "$MODS_DIR"/
    elif [ -d "$MODRINTH_PACK/overrides/mods" ]; then
        log "📁 Sposto mod da overrides/mods/"
        mv "$MODRINTH_PACK/overrides/mods/"* "$MODS_DIR"/
    fi

    # Fallback: scarica da modrinth.index.json se esiste
    JSON_FILE="$MODRINTH_PACK/modrinth.index.json"
    if [ -f "$JSON_FILE" ]; then
        log "🔍 Scarico mod dichiarate in modrinth.index.json"
        jq -c '.files[] | select(.env.server == "required" or .env.server == "optional")' "$JSON_FILE" | while read -r mod; do
            url=$(echo "$mod" | jq -r '.downloads[0]')
            path=$(echo "$mod" | jq -r '.path')
            filename=$(basename "$path")
            log "⬇️  Scarico mod $filename"
            curl -fsSL "$url" -o "$MODS_DIR/$filename"
        done
    fi

    log "✅ Modpack installato in $MODS_DIR"

    cd "$SERVER_DIR"
    log "⚙️  Installo Forge"
    select_java
    "$JAVA_BIN" -jar forge-installer.jar --installServer
    rm forge-installer.jar

    FORGE_JAR=$(find . -maxdepth 1 -type f -name 'forge-*.jar' | head -n1)
    [ -n "$FORGE_JAR" ] && mv "$FORGE_JAR" forge-server.jar
fi



# === FILE DI CONFIGURAZIONE ===
echo "eula=true" > "$SERVER_DIR/eula.txt"
update_status "setting_up" 90

cat > "$SERVER_DIR/server.properties" <<EOF
enable-jmx-monitoring=false
rcon.port=25575
level-seed=
gamemode=survival
enable-command-block=true
enable-query=false
generator-settings=
enforce-secure-profile=true
level-name=world
motd=Server Forge $VERSION_OR_SLUG
query.port=25565
pvp=true
difficulty=easy
network-compression-threshold=256
max-tick-time=60000
use-native-transport=true
max-players=50
online-mode=true
enable-status=true
allow-flight=true
broadcast-rcon-to-ops=true
view-distance=32
server-ip=0.0.0.0
resource-pack-prompt=
allow-nether=true
server-port=25565
enable-rcon=false
sync-chunk-writes=true
op-permission-level=4
prevent-proxy-connections=false
hide-online-players=false
resource-pack=
entity-broadcast-range-percentage=100
simulation-distance=10
rcon.password=
player-idle-timeout=0
force-gamemode=false
rate-limit=0
hardcore=false
white-list=false
broadcast-console-to-ops=true
spawn-npcs=true
spawn-animals=true
function-permission-level=2
text-filtering-config=
spawn-monsters=true
enforce-whitelist=false
resource-pack-sha1=
spawn-protection=16
max-world-size=29999984
EOF








cat > "$SERVER_DIR/start.sh" <<EOF
#!/bin/bash
cd "\$(dirname "\$0")"
SERVER_TYPE=$TYPE
GAME_VERSION=$GAME_VERSION
SERVER_ID=$SERVER_ID
FORGE_VERSION=$latest_forge_version


select_java() {
    echo "🎮 Selezione Java per GAME_VERSION: '$GAME_VERSION'"

    case "$GAME_VERSION" in
        1.7*|1.8*|1.9*|1.10*|1.11*|1.12*)
            # Minecraft 1.12 e precedenti: Java 8
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.13*|1.14*|1.15*)
            # Da Minecraft 1.13 a 1.15: Java 8 consigliato, Java 11 supportato
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.16*)
            # Minecraft 1.16: Java 8 consigliato, Java 11 supportato
            JAVA_BIN="/usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java"
            ;;
        1.17*)
            # Minecraft 1.17 richiede Java 16 (ma Java 17 funziona)
            JAVA_BIN="/usr/lib/jvm/java-17-openjdk-amd64/bin/java"
            ;;
        1.18*|1.19*|1.20*|1.21*)
            # Minecraft 1.18+ richiede Java 17
            JAVA_BIN="/usr/lib/jvm/java-17-openjdk-amd64/bin/java"
            ;;
        *)
            echo "❌ Versione Minecraft non riconosciuta o troppo nuova: '$GAME_VERSION'"
            exit 1
            ;;
    esac

    echo "✅ Java selezionato: \$JAVA_BIN"
}


select_java


version_lt() {
  [ "$(printf '%s\n' "$GAME_VERSION" "1.17" | sort -V | head -n1)" != "1.17" ]
}



case \$SERVER_TYPE in
  vanilla)
    CMD="\$JAVA_BIN -Xmx10G -Xms10G -jar server.jar nogui"
    ;;
  modpack)
    if version_lt; then
      CMD="\$JAVA_BIN -Xmx10G -Xms10G -jar forge-server.jar nogui"
    else
      ARG_TXT="/home/diego/minecraft_servers/\$SERVER_ID/libraries/net/minecraftforge/forge/\$FORGE_VERSION/unix_args.txt"
      if [ ! -f "\$ARG_TXT" ]; then
        echo "❌ File unix_args.txt non trovato: \$ARG_TXT"
        exit 1
      fi
      CMD="\$JAVA_BIN -Xmx10G -Xms10G @\$ARG_TXT"
    fi
    ;;
  paper)
    CMD="\$JAVA_BIN -Xmx10G -Xms10G -jar paper.jar nogui"
    ;;
  *)
    echo "❌ Tipo server sconosciuto: \$SERVER_TYPE"
    exit 1
    ;;
esac

# Avvia il server in uno screen con comando corretto
screen -dmS mc_\$SERVER_ID bash -c "\$CMD"
EOF

chmod +x "$SERVER_DIR/start.sh"

cat > "$SERVER_DIR/stop.sh" <<EOF
#!/bin/bash
screen -S mc_$SERVER_ID -X quit
if [ \$? -eq 0 ]; then
    echo "Server $SERVER_ID arrestato con successo."
else
    killall java
fi
killall screen
killall java
EOF

chmod +x "$SERVER_DIR/start.sh" "$SERVER_DIR/stop.sh"

version_in_range() {
  local game_version=$1
  local version_min=$2
  local version_max=$3

  # Controlla se game_version >= version_min
  local ge_min=false
  if [ "$(printf '%s\n' "$game_version" "$version_min" | sort -V | head -n1)" = "$version_min" ]; then
    ge_min=true
  fi

  # Controlla se game_version <= version_max
  local le_max=false
  if [ "$(printf '%s\n' "$game_version" "$version_max" | sort -V | tail -n1)" = "$version_max" ]; then
    le_max=true
  fi

  if [ "$ge_min" = true ] && [ "$le_max" = true ]; then
    return 0  # true: game_version in [version_min, version_max]
  else
    return 1  # false
  fi
}

update_status "diagnosing" 99

# if version_in_range "$GAME_VERSION" "1.7.10" "1.8.9"; then
#   # Codice per versioni precedenti a 1.17
#   MAX_ATTEMPTS=10
#   DISABLED_MODS_DIR="$SERVER_DIR/disabled_mods"
#   mkdir -p "$DISABLED_MODS_DIR"

#   for i in $(seq 1 $MAX_ATTEMPTS); do
#     log "🌀 Tentativo $i di avvio del server..."

#     # Avvia il server in background
#     /usr/lib/jvm/java-8-openjdk-amd64/jre/bin/java -Xmx2G -Xms2G -jar "$SERVER_DIR/forge-server.jar" nogui > "$SERVER_DIR/test.log" 2>&1 &
#     PID=$!
#     sleep 20

#     # Controlla se il server è ancora vivo
#     if ps -p $PID > /dev/null; then
#       kill $PID
#       log "✅ Server avviato correttamente al tentativo $i"
#       break
#     else
#       log "💥 Server crashato al tentativo $i"

#       # Analizza il log
#       CRASH_LOG="$SERVER_DIR/test.log"
#       suspect_mods=()

#       # Cerca riferimenti a file .jar
#       jar_mods=$(grep -Eo '[a-zA-Z0-9_.-]+\.jar' "$CRASH_LOG" | sort -u)
#       for mod in $jar_mods; do
#         mod_path=$(find "$MODS_DIR" -maxdepth 1 -iname "$mod" | head -n1)
#         if [ -n "$mod_path" ]; then
#           suspect_mods+=("$mod_path")
#         fi
#       done

#       # Cerca riferimenti a package/classi
#       class_mods=$(grep -Eo 'Caused by:.*|at .*' "$CRASH_LOG" | grep -Eo '[a-zA-Z0-9_]+\.[a-zA-Z0-9_.]+' | cut -d. -f1 | sort -u)
#       for mod_prefix in $class_mods; do
#         mod_path=$(find "$MODS_DIR" -maxdepth 1 -iname "*$mod_prefix*.jar" | head -n1)
#         if [ -n "$mod_path" ]; then
#           suspect_mods+=("$mod_path")
#         fi
#       done

#       # Rimuove duplicati
#       suspect_mods=($(printf "%s\n" "${suspect_mods[@]}" | sort -u))

#       # Se ha trovato qualcosa, disabilita la prima mod sospetta
#       if [ "${#suspect_mods[@]}" -gt 0 ]; then
#         mv "${suspect_mods[0]}" "$DISABLED_MODS_DIR/"
#         log "🚫 Mod disabilitata: $(basename "${suspect_mods[0]}")"
#       else
#         # Fallback: disabilita una mod a caso
#         any_mod=$(find "$MODS_DIR" -maxdepth 1 -iname "*.jar" | head -n1)
#         if [ -n "$any_mod" ]; then
#           mv "$any_mod" "$DISABLED_MODS_DIR/"
#           log "⚠️ Nessuna mod identificata. Rimozione casuale: $(basename "$any_mod")"
#         else
#           log "❌ Nessuna mod rimasta da disabilitare. Interrompo il ciclo."
#           break
#         fi
#       fi
#     fi
#   done

# fi


# if version_in_range "$GAME_VERSION" "1.10.2" "1.16.5"; then
# check_and_fix_missing_mods "$SERVER_DIR" "$GAME_VERSION" "$latest_forge_version" 
# fix_missing_libraries "$LATEST_LOG_FILE" "$SERVER_DIR"
# monitor_and_fix_server "$SERVER_DIR"
# fi
chmod 644 /home/diego/minecraft_servers/$SERVER_ID/mods/*
chown diego:diego /home/diego/minecraft_servers/$SERVER_ID/mods/*
LATEST_LOG_FILE=$(find "$SERVER_DIR/logs" -type f -name "latest.log" | head -n 1)
# Avvio temporaneo del server
cd "$SERVER_DIR"
./start.sh &                   # Avvia in background
SERVER_PID=$!                 # Salva il PID del processo
sleep 10                      # Aspetta 10 secondi (o di più se vuoi)

# Controllo se il server è ancora in esecuzione
attempt_fix_missing_mods_loop

update_status "created" 100

log "🏁 Installazione completata per server $SERVER_ID"

exit 0