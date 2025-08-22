#!/bin/bash

# --- Parametri ---
SERVER_ID=$1

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}



# --- Percorso server ---
MC_DIR="/root/minecraft_bedrock"
mkdir -p "$MC_DIR"
cd "$MC_DIR" || exit 1

# --- Inizio installazione ---
log "Installazione server Bedrock iniziata"

# --- Ottieni URL di download ---
log "Ottenimento URL di download..."
DOWNLOAD_URL=$(curl -s https://net-secondary.web.minecraft-services.net/api/v1.0/download/links | jq -r '.result.links[] | select(.downloadType=="serverBedrockLinux") | .downloadUrl')
if [ -z "$DOWNLOAD_URL" ]; then
    log "Errore: impossibile ottenere l'URL di download"
    exit 1
fi

# --- Scarica server Bedrock ---
log "Download server Bedrock..."
wget -q --show-progress "$DOWNLOAD_URL" -O bedrock-server.zip
if [ $? -ne 0 ]; then
    log "Errore download server Bedrock"
    exit 1
fi

# --- Estrai server ---
unzip -o bedrock-server.zip
rm bedrock-server.zip


# --- Accetta EULA ---
echo "eula=true" > eula.txt

# --- Crea start.sh e stop.sh ---
cat > start.sh << 'EOF'
#!/bin/bash
SERVER_DIR="/root/minecraft_bedrock"
LOG_FILE="$SERVER_DIR/server.log"
PID_FILE="$SERVER_DIR/server.pid"

cd "$SERVER_DIR" || exit 1

# Crea log se non esiste
touch "$LOG_FILE"
chmod 664 "$LOG_FILE"
rm -f "$PID_FILE"

# Avvia server in background
nohup ./bedrock_server > "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

EOF
# --- Disabilita telemetria ---
chmod +x start.sh

cat > stop.sh << 'EOF'
#!/bin/bash
pkill -f bedrock_server
EOF

chmod +x stop.sh

# --- Aggiornamento finale ---
log "Server Bedrock pronto e avviato"