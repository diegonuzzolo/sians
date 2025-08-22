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
cd "$(dirname "$0")"

# Nome univoco per la sessione screen, es. "mc-123" dove 123 è l'ID del container
SESSION_NAME="mc-$(hostname)"
LOG_FILE="server.log"

# Se una sessione con lo stesso nome è già in esecuzione, la terminiamo
if screen -list | grep -q "$SESSION_NAME"; then
    screen -S "$SESSION_NAME" -X quit
fi

# Avvia il server dentro una sessione screen staccata (-d -m)
# L'output viene loggato su screen e anche nel file LOG_FILE
screen -dmS "$SESSION_NAME" -L -Logfile "$LOG_FILE" ./bedrock_server

echo "Server avviato nella sessione screen '$SESSION_NAME'."
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