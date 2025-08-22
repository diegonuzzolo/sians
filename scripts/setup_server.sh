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

# Il nome della sessione DEVE essere identico a quello usato in start.sh
SESSION_NAME="mc-$(hostname)"
LOG_FILE="server.log"
TIMEOUT=30 # Secondi da attendere prima di forzare la chiusura

echo "Tentativo di spegnimento del server nella sessione '$SESSION_NAME'..."

# 1. Controlla se la sessione screen esiste
if ! screen -list | grep -q "$SESSION_NAME"; then
    echo "Il server non sembra essere in esecuzione. Nessuna sessione screen trovata."
    # Potrebbe essere un processo orfano, proviamo a cercarlo comunque
    if pgrep -f "bedrock_server" > /dev/null; then
        echo "Trovato un processo bedrock_server orfano. Si procede con la terminazione forzata."
        pkill -f bedrock_server
    fi
    exit 0
fi

# 2. Invia il comando di stop "grazioso" alla console del server
screen -S "$SESSION_NAME" -p 0 -X stuff "stop\n"
echo "Comando di spegnimento inviato. Attesa di ${TIMEOUT} secondi per la terminazione..."

# 3. Attendi e verifica che il processo si sia chiuso
end_time=$((SECONDS + TIMEOUT))
while [ $SECONDS -lt $end_time ]; do
    # Se la sessione screen non esiste più, il server si è chiuso correttamente
    if ! screen -list | grep -q "$SESSION_NAME"; then
        echo "Server fermato con successo."
        exit 0
    fi
    sleep 1
done

# 4. Se il server è ancora attivo dopo il timeout, usa la forza bruta
echo "Il server non si è chiuso entro il tempo limite. Terminazione forzata in corso (pkill)..."
pkill -f bedrock_server

# Diamo un ultimo secondo per assicurarsi che sia morto
sleep 1
if ! pgrep -f "bedrock_server" > /dev/null; then
    echo "Processo terminato forzatamente con successo."
else
    echo "ERRORE: Impossibile terminare il processo del server."
    exit 1
fi

exit 0
EOF

chmod +x stop.sh

# --- Aggiornamento finale ---
log "Server Bedrock pronto e avviato"