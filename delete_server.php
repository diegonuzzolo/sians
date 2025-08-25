<?php
// FILE: delete_server.php (Compatibile con il nuovo new_server.php)

ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- 0. DIPENDENZE E CONFIGURAZIONE ---
require 'vendor/autoload.php'; // Per la libreria Symfony YAML
require 'auth_check.php';
require_once 'includes/csrf.php';
require 'config/config.php';
require_once 'proxmox_functions.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// --- FUNZIONI HELPER ---

/**
 * Rimuove una regola di ingress dal file config.yml di cloudflared e ricarica il servizio.
 * @param string $serverName Il nome del server (senza dominio) da rimuovere.
 */
function removeTunnelRuleAndReload(string $serverName) {
    $configFile = '/root/.cloudflared/config.yml';
    $hostnameToDelete = $serverName . '.sians.it';

    try {
        $config = Yaml::parseFile($configFile);
        
        if (!isset($config['ingress']) || !is_array($config['ingress'])) {
            return; // Nessuna regola di ingress da rimuovere
        }

        $newIngressRules = [];
        $ruleFound = false;

        // Filtra le regole, escludendo quella da eliminare
        foreach ($config['ingress'] as $rule) {
            if (isset($rule['hostname']) && $rule['hostname'] === $hostnameToDelete) {
                $ruleFound = true;
                continue; // Salta questa regola, non la aggiungere alla nuova lista
            }
            $newIngressRules[] = $rule;
        }

        // Se la regola è stata trovata e rimossa, aggiorna il file e ricarica il servizio
        if ($ruleFound) {
            $config['ingress'] = $newIngressRules;
            $yaml = Yaml::dump($config, 4);
            file_put_contents($configFile, $yaml);
            
            // Ricarica cloudflared per applicare le modifiche
            exec('sudo systemctl reload cloudflared');
        }

    } catch (ParseException $e) {
        throw new Exception("Errore di parsing del file config.yml: " . $e->getMessage());
    } catch (Exception $e) {
        throw new Exception("Errore durante l'aggiornamento della configurazione del tunnel: " . $e->getMessage());
    }
}

/**
 * Cancella i record CNAME e SRV da Cloudflare per un dato server.
 * @param string $serverName Il nome del server (senza dominio).
 */
function deleteCloudflareDnsRecords(string $serverName) {
    // Il tuo codice esistente per deleteCloudflareRecords va qui, è già corretto.
    // Ho solo rinominato la funzione per chiarezza.
    $headers = [
        "Authorization: Bearer " . CLOUDFLARE_API_TOKEN,
        "Content-Type: application/json"
    ];

    $zoneId = CLOUDFLARE_ZONE_ID;
    $hostname = $serverName . '.sians.it';

    $ch = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=" . urlencode($hostname));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) throw new Exception("Errore cURL nella ricerca DNS: " . curl_error($ch));
    $data = json_decode($resp, true);
    curl_close($ch);

    if (!empty($data['result'])) {
        foreach ($data['result'] as $record) {
            $recordId = $record['id'];
            $chDel = curl_init("https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$recordId");
            curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($chDel, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
            $delResp = curl_exec($chDel);
            if (curl_errno($chDel)) throw new Exception("Errore cURL nell'eliminazione DNS: " . curl_error($chDel));
            $delData = json_decode($delResp, true);
            curl_close($chDel);

            if (!($delData['success'] ?? false)) {
                // Ignora l'errore se il record non è stato trovato, potrebbe essere già stato cancellato
            }
        }
    }
}

// --- SCRIPT PRINCIPALE ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Metodo non consentito");
}
csrf_check_or_die();

$idUtente = $_SESSION['user_id'] ?? 0;
$vmid = intval($_POST['vmid'] ?? 0);
if ($vmid <= 0) die("VMID non valido");

try {
    $pdo->beginTransaction();

    // Recupera i dettagli del server dal DB
    $stmt = $pdo->prepare("
        SELECT s.*, c.ip, c.ram_mb, c.storage_gb, c.id as container_id 
        FROM servers s 
        LEFT JOIN containers c ON c.id = s.container_id 
        WHERE s.user_id = ? AND s.proxmox_containerId = ?
    ");
    $stmt->execute([$idUtente, $vmid]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) throw new Exception("Server non trovato o non appartiene all'utente");
    
    $nomeServer = $server['name'];
    $idContainer = $server['container_id'] ?? null;

    // --- 1. Ferma e distruggi il container su Proxmox ---
    callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid/status/stop", 'POST');
    sleep(5); // Attendi un po' per assicurarti che il container sia fermo
    $distruzione = callProxmox("https://192.168.1.251:8006/api2/json/nodes/pve/lxc/$vmid", 'DELETE');
    // Non bloccare l'esecuzione se la distruzione fallisce (potrebbe essere già stato rimosso)

    // --- 2. Rimuove la regola dal tunnel Cloudflared ---
    if (!empty($nomeServer)) {
        removeTunnelRuleAndReload($nomeServer);
    }

    // --- 3. Rimuove i record DNS da Cloudflare ---
    if (!empty($nomeServer)) {
        deleteCloudflareDnsRecords($nomeServer);
    }

    // --- 4. Aggiorna le risorse globali nel DB ---
    $pdo->prepare("UPDATE resources SET used_cpu_cores = used_cpu_cores - ?, used_ram_mb = used_ram_mb - ?, used_storage_gb = used_storage_gb - ? WHERE id = 1")
        ->execute([
            intval($server['cpu_cores']),
            intval($server['ram_mb']),
            intval($server['storage_gb'])
        ]);

    // --- 5. Resetta lo slot del server ---
    if (!empty($server['slot_id'])) {
        $pdo->prepare("UPDATE server_slots SET server_id = NULL, status = 'pending' WHERE id = ?")
            ->execute([$server['slot_id']]);
    }

    // --- 6. Elimina il server e il container dal DB ---
    if ($idContainer) {
        $pdo->prepare("DELETE FROM containers WHERE id = ?")->execute([$idContainer]);
    }
    $pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$server['id']]);

    $pdo->commit();
    
    // Invia una risposta di successo al client (es. per AJAX)
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Server eliminato correttamente']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    // Invia una risposta di errore al client
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>