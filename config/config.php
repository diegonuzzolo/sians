<?php
// ==========================
// 📦 CONFIGURAZIONE DATABASE
// ==========================
define('DB_HOST', 'localhost');
define('DB_NAME', 'minecraft_platform');
define('DB_USER', 'diego');
define('DB_PASSWORD', 'Lgu8330Serve6');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASSWORD
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ Errore connessione DB: " . $e->getMessage());
}

// ==========================
// ⚙️ CONFIGURAZIONE PROXMOX
// ==========================

// URL base API (https, ip interno, porta e path API JSON)
define('PROXMOX_API_URL', 'https://192.168.1.251:8006/api2/json');

// Nome del nodo Proxmox (come appare nella GUI)
define('PROXMOX_NODE', 'pve');

// Credenziali (username con realm @pam e password root)
define('PROXMOX_USERNAME', 'root@pam');
define('PROXMOX_PASSWORD', 'Lgu8330Serve6');

// Nome dello storage LVM dove salvare il rootfs
define('PROXMOX_STORAGE', 'local-lvm');

// Percorso del template LXC già presente in Proxmox
define('PROXMOX_TEMPLATE', 'local:vztmpl/ubuntu-25.04-standard_25.04-1.1_amd64.tar.zst');

// ==========================
// 🌐 RETE CONTAINER
// ==========================

// Gateway per i container LXC
define('CONTAINER_GATEWAY', '192.168.1.254');

// Subnet CIDR (di solito /24 in reti domestiche)
define('CONTAINER_SUBNET', '/24');
