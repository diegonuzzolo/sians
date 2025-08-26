 <script>
document.addEventListener('DOMContentLoaded', () => {
    console.log("Pagina caricata. Script di gestione unificato avviato.");

    // --- Variabili Globali ---
    const csrfToken = '<?= $csrf_token ?>';
    const serverId = '<?= $serverId ?>';
    const serverIconUrl = '<?= isset($server['proxmox_containerId']) ? "/server_data/{$server['proxmox_containerId']}/server-icon.png?" . time() : '' ?>';

    // --- Elementi del DOM ---
    const msgBox = document.getElementById('notification-message');
    const serverPropertiesForm = document.getElementById('serverPropertiesForm');
    const saveBtn = serverPropertiesForm?.querySelector('button[type="submit"]');
    const iconPreview = document.getElementById('server-icon-preview');
    const uploadIconButton = document.getElementById('upload-icon-btn');
    const consoleOutput = document.getElementById('console-output');
    const consoleInput = document.getElementById('console-input');
    const sendCommandBtn = document.getElementById('send-command-btn');

    // --- Logica SSE (Server-Sent Events) ---
    let logSource = null; // Terrà la nostra connessione di streaming

    // --- Funzioni Helper ---
    function showNotification(success, message) {
        msgBox.textContent = message;
        msgBox.className = `notification ${success ? 'success' : 'error'}`;
        msgBox.classList.remove('hidden');
        msgBox.style.opacity = '1';
        setTimeout(() => {
            msgBox.style.opacity = '0';
            setTimeout(() => msgBox.classList.add('hidden'), 300);
        }, 5000);
    }

    // --- Funzioni Principali ---
    function startLogStream() {
        if (logSource) { // Se la connessione è già attiva, non fare nulla
            return;
        }
        console.log("Avvio dello stream della console...");
        consoleOutput.innerHTML = '<div class="text-gray-400">Connessione al log in corso...</div>';

        // Crea la connessione SSE allo script di streaming
        logSource = new EventSource(`ajax_stream_log.php?id=${serverId}`);

        let isFirstMessage = true;

        // Questo evento viene chiamato per ogni "data:" che lo script PHP invia
        logSource.onmessage = function (event) {
            // Al primo messaggio, pulisce la console dal testo di caricamento
            if (isFirstMessage) {
                consoleOutput.innerHTML = '';
                isFirstMessage = false;
            }
            const data = JSON.parse(event.data);
            if (data.log) {
                const logLine = document.createElement('div');
                logLine.textContent = data.log;
                consoleOutput.appendChild(logLine);
                // Scrolla automaticamente alla fine per mostrare sempre le ultime righe
                consoleOutput.scrollTop = consoleOutput.scrollHeight;
            }
        };

        // Gestisce gli errori di connessione
        logSource.onerror = function () {
            console.error("Errore nello stream della console. Connessione chiusa.");
            const errorLine = document.createElement('div');
            errorLine.className = 'text-red-500 font-semibold';
            errorLine.textContent = 'Connessione al server persa. Cambia tab per riprovare.';
            consoleOutput.appendChild(errorLine);
            stopLogStream(); // Chiude la connessione fallita
        };
    }

    function stopLogStream() {
        if (logSource) {
            logSource.close();
            logSource = null; // Resetta la variabile per permettere una nuova connessione
            console.log("Stream della console chiuso.");
        }
    }

    function sendCommand() {
        const command = consoleInput.value.trim();
        if (!command) return;
        const formData = new FormData();
        formData.append('server_id', serverId);
        formData.append('command', command);
        formData.append('csrf_token', csrfToken);
        fetch('ajax_send_command.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                showNotification(data.success, data.message);
                if (data.success) consoleInput.value = '';
            })
            .catch(() => showNotification(false, "Errore nell'invio del comando"));
    }

    // --- Inizializzazione e Gestione Eventi ---

    // 1. Gestione dei Tab
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.addEventListener('click', () => {
            // Gestione visuale delle tab
            document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
            btn.classList.add('active');
            document.getElementById(`${btn.dataset.tab}-tab`).classList.remove('hidden');

            // Gestione dello stream e del pulsante "Salva"
            if (btn.dataset.tab === 'console') {
                saveBtn?.classList.add('hidden');
                startLogStream(); // Avvia lo stream quando si apre la tab console
            } else {
                saveBtn?.classList.remove('hidden');
                stopLogStream(); // Ferma lo stream quando si lascia la tab console
            }
        });
    });
    
    // Controlla la tab attiva al caricamento iniziale
    const activeTab = document.querySelector('.tab-button.active');
    if (saveBtn && activeTab?.dataset.tab === 'console') {
        saveBtn.classList.add('hidden');
    }

    // 2. Logica Invio Comandi Console
    if (sendCommandBtn && consoleInput) {
        sendCommandBtn.addEventListener('click', sendCommand);
        consoleInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendCommand();
            }
        });
    }

    // 3. Salvataggio Proprietà Server
    if (serverPropertiesForm) {
        serverPropertiesForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            this.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                formData.set(cb.name, cb.checked ? 'true' : 'false');
            });
            fetch('ajax_update_server.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => showNotification(data.success, data.message))
                .catch(() => showNotification(false, "Errore nella richiesta. Riprovare."));
        });
    }

    // 4. Gestione Icona Server
    if (iconPreview && serverIconUrl) {
        fetch(serverIconUrl)
            .then(response => { if (response.ok) iconPreview.src = serverIconUrl; })
            .catch(error => console.error("Errore nel caricamento dell'icona iniziale:", error));
    }
    if (uploadIconButton) {
        uploadIconButton.addEventListener('click', () => {
            const fileInput = document.getElementById('server-icon');
            if (!fileInput.files.length) {
                return alert('Seleziona un file PNG 64x64 prima di caricare.');
            }
            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('server_id', serverId);
            formData.append('server-icon', file);
            formData.append('csrf_token', csrfToken);
            fetch('ajax_upload_icon.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showNotification(data.success, data.message);
                    if (data.success) iconPreview.src = URL.createObjectURL(file);
                })
                .catch(() => showNotification(false, "Errore durante l'upload."));
        });
    }
});
</script>