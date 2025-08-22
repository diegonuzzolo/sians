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
SESSION_NAME="mcserver"

cd "$SERVER_DIR" || exit 1

# Se non esiste, crea il log file
touch "$LOG_FILE"
chmod 664 "$LOG_FILE"
rm -f "$PID_FILE"

# Se esiste già una sessione screen con lo stesso nome, la killo
if screen -list | grep -q "$SESSION_NAME"; then
    echo "⚠️  Sessione $SESSION_NAME già in esecuzione. La termino..."
    screen -S "$SESSION_NAME" -X quit
fi

# Avvio del server in una sessione screen con log persistente
echo "✅ Avvio del server Bedrock..."
screen -dmS "$SESSION_NAME" bash -c "LD_LIBRARY_PATH=. ./bedrock_server | tee -a '$LOG_FILE'"

# Salva il PID del processo bedrock_server
sleep 2
BEDROCK_PID=$(pgrep -f bedrock_server | head -n 1)
if [ -n "$BEDROCK_PID" ]; then
    echo "$BEDROCK_PID" > "$PID_FILE"
    echo "✅ Server avviato con PID $BEDROCK_PID"
else
    echo "❌ Errore: impossibile trovare il PID del server!"
fi


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