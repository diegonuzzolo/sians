#!/bin/bash
set -e

TYPE=$1
VERSION_OR_SLUG=$2
URL=$3
METHOD=$4
SERVER_ID=$5
GAME_VERSION=$6

BASE_DIR="/home/diego/minecraft_servers"
SERVER_DIR="$BASE_DIR/$SERVER_ID"
LOG_FILE="/home/diego/setup_log_${SERVER_ID}.log"
STATUS_API_URL="https://sians.it/update_status.php"
BEARER_TOKEN="la_luna_il_mio_cane_numero_uno"

echo "[$(date)] START - TYPE=$TYPE | VERSION_OR_SLUG=$VERSION_OR_SLUG | URL=$URL | METHOD=$METHOD | SERVER_ID=$SERVER_ID | GAME_VERSION=$GAME_VERSION" >> "$LOG_FILE"

update_status() {
    local status="$1"
    local progress="$2"
    echo "[$(date)] Chiamata update_status con status=$status, progress=$progress" >> "$LOG_FILE"
    curl -s -X POST -H "Content-Type: application/json" \
         -H "Authorization: Bearer $UPDATE_TOKEN" \
         -d "{\"server_id\": \"$SERVER_ID\", \"status\": \"$status\", \"progress\": $progress}" \
         "$UPDATE_URL" > /dev/null
}

mkdir -p "$SERVER_DIR"
cd "$SERVER_DIR"

update_status "downloading_mods" 30

if [[ "$TYPE" == "modpack" && "$METHOD" == "modrinth" ]]; then
  echo "[$(date)] Download modpack ZIP da Modrinth: $URL" >> "$LOG_FILE"
  curl -L -o "modpack.zip" "$URL"

  echo "[$(date)] Estrazione modpack..." >> "$LOG_FILE"
  unzip -o "modpack.zip" -d "$SERVER_DIR"

  # Cerca file `modrinth.index.json`
  INDEX_FILE=$(find "$SERVER_DIR" -type f -name "modrinth.index.json" | head -n 1)
  if [[ ! -f "$INDEX_FILE" ]]; then
    echo "[$(date)] ERRORE: modrinth.index.json non trovato." >> "$LOG_FILE"
    update_status "failed" 0
    exit 1
  fi

  MODS_DIR="$SERVER_DIR/mods"
  mkdir -p "$MODS_DIR"

  echo "[$(date)] Download mod..." >> "$LOG_FILE"
  jq -r '.files[] | select(.env.server == "required" or .env.server == "optional") | .downloads[0]' "$INDEX_FILE" | while read -r url; do
    echo " - Downloading $url" >> "$LOG_FILE"
    wget -q -P "$MODS_DIR" "$url"
  done

  update_status "installing" 60

  echo "[$(date)] Installazione Forge per Minecraft $GAME_VERSION..." >> "$LOG_FILE"

  # Ottieni ultima versione Forge per la versione indicata
  FORGE_JSON=$(curl -s "https://bmclapi2.bangbang93.com/forge/minecraft/$GAME_VERSION")
  FORGE_VERSION=$(echo "$FORGE_JSON" | jq -r '.[0]')
  INSTALLER_URL="https://maven.minecraftforge.net/net/minecraftforge/forge/${GAME_VERSION}-${FORGE_VERSION}/forge-${GAME_VERSION}-${FORGE_VERSION}-installer.jar"

  echo "[$(date)] Scarico Forge installer da $INSTALLER_URL" >> "$LOG_FILE"
  wget -O forge-installer.jar "$INSTALLER_URL"

  echo "[$(date)] Avvio installer Forge..." >> "$LOG_FILE"
  java -jar forge-installer.jar --installServer >> "$LOG_FILE" 2>&1

  echo "[$(date)] Installazione completata" >> "$LOG_FILE"
fi

echo "eula=true" > "$SERVER_DIR/eula.txt"

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
motd=Server Forge Modrinth $VERSION_OR_SLUG
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
cd "$(dirname "\$0")"
screen -dmS mc_server_$SERVER_ID java -Xmx4G -Xms2G -jar forge-*.jar nogui
EOF

cat > "$SERVER_DIR/stop.sh" <<EOF
#!/bin/bash
screen -S mc_server_$SERVER_ID -X quit
EOF

chmod +x "$SERVER_DIR/start.sh" "$SERVER_DIR/stop.sh"

update_status "created" 100
echo "[$(date)] SETUP COMPLETATO" >> "$LOG_FILE"
