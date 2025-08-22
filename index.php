<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config/config.php';
include 'includes/header.php';
require_once 'includes/force_https.php';

// Variabile usata per storage speed nella descrizione
$vmStorageSpeed = 7500;
?>

<main class="bg-gray-900 text-slate-200 antialiased">
    <div class="relative w-full max-w-7xl mx-auto my-8 rounded-3xl overflow-hidden shadow-2xl shadow-yellow-500/30 animate-fade-in-down">
        
        <picture>
            <source srcset="assets/banner.webp" type="image/webp">
            <source srcset="assets/banner.jpg" type="image/jpeg">
            <img src="assets/banner.jpg" alt="Minecraft Bedrock Hosting" class="w-full h-[55vh] object-cover filter brightness-[.7]">
        </picture>
        
        <div class="absolute inset-0 flex flex-col items-center justify-center text-center px-4">
            <h1 class="text-4xl sm:text-5xl md:text-6xl font-extrabold text-yellow-400 text-shadow-xl animate-fade-in-down-slow">
                Hosting Minecraft Bedrock Edition
            </h1>
            <p class="mt-4 mb-8 text-lg sm:text-xl md:text-2xl font-light text-yellow-200 max-w-2xl text-shadow-md animate-fade-in-down-slower">
                Server ultra-veloci, facili da usare, con performance elevate per un'esperienza di gioco senza interruzioni.
            </p>
            
            <div class="flex flex-col sm:flex-row gap-4">
                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="register.php" class="py-3 px-8 bg-yellow-500 text-gray-900 font-bold rounded-full shadow-lg hover:shadow-yellow-500/50 transition-all duration-300 transform hover:scale-105 animate-fade-in-up">
                        Registrati Ora
                    </a>
                    <a href="login.php" class="py-3 px-8 bg-gray-700 text-slate-200 font-bold rounded-full shadow-lg hover:shadow-gray-600/50 transition-all duration-300 transform hover:scale-105 animate-fade-in-up-slow">
                        Accedi
                    </a>
                <?php else: ?>
                    <a href="dashboard.php" class="py-3 px-8 bg-green-500 text-gray-900 font-bold rounded-full shadow-lg hover:shadow-green-500/50 transition-all duration-300 transform hover:scale-105 animate-fade-in-up">
                        Vai alla Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 text-center">
        <h2 class="text-3xl sm:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500 mb-8 animate-fade-in-up">
            Perché scegliere noi?
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-slate-800 rounded-xl p-8 shadow-xl border border-slate-700 hover:border-yellow-500 transition-all duration-300 transform hover:scale-105 animate-fade-in-up">
                <i class="bi bi-lightning-charge-fill text-4xl text-yellow-400 mb-4"></i>
                <h5 class="text-2xl font-bold mb-2">Velocità</h5>
                <p class="text-slate-400">Server sempre reattivi grazie a container isolati e configurazioni ottimizzate.</p>
            </div>
            <div class="bg-slate-800 rounded-xl p-8 shadow-xl border border-slate-700 hover:border-yellow-500 transition-all duration-300 transform hover:scale-105 animate-fade-in-up-slow">
                <i class="bi bi-gear-fill text-4xl text-blue-400 mb-4"></i>
                <h5 class="text-2xl font-bold mb-2">Controllo Totale</h5>
                <p class="text-slate-400">Gestisci facilmente i tuoi server con una dashboard intuitiva.</p>
            </div>
            <div class="bg-slate-800 rounded-xl p-8 shadow-xl border border-slate-700 hover:border-yellow-500 transition-all duration-300 transform hover:scale-105 animate-fade-in-up-slower">
                <i class="bi bi-check-circle-fill text-4xl text-green-400 mb-4"></i>
                <h5 class="text-2xl font-bold mb-2">Semplicità</h5>
                <p class="text-slate-400">Avvia, crea e personalizza il tuo mondo in pochi clic.</p>
            </div>
        </div>

        <section class="plan-section mt-16 p-8 bg-slate-800 rounded-2xl shadow-2xl shadow-yellow-500/20">
            <h2 class="text-3xl sm:text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500 mb-10">
                Scopri le Nostre Caratteristiche
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-cpu-fill text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">CPU e RAM dedicate</h5>
                    <p class="text-gray-400 text-sm">Processori a 6 GHz e RAM DDR5 dedicati al tuo server.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-hdd-fill text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Storage veloce NVMe</h5>
                    <p class="text-gray-400 text-sm">SSD NVMe con velocità fino a <?= number_format($vmStorageSpeed, 0, ',', '.') ?> MB/s per caricamenti rapidissimi.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-wifi text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Banda 300 Mbps garantita</h5>
                    <p class="text-gray-400 text-sm">Connessione internet ultra veloce e stabile con bassa latenza.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-shield-lock-fill text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Protezione DDoS</h5>
                    <p class="text-gray-400 text-sm">Sicurezza avanzata per proteggere il tuo server da attacchi esterni.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-server text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Backup automatici giornalieri</h5>
                    <p class="text-gray-400 text-sm">Backup completi e ripristino rapido per non perdere mai i tuoi dati.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-headset text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Supporto tecnico 24/7</h5>
                    <p class="text-gray-400 text-sm">Assistenza professionale sempre disponibile via email</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-tools text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Gestione e aggiornamenti</h5>
                    <p class="text-gray-400 text-sm">Manutenzione, aggiornamenti e ottimizzazioni server incluse nel prezzo.</p>
                </div>
                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-layout-text-window-reverse text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Console Web e Dashboard</h5>
                    <p class="text-gray-400 text-sm">Interfaccia alla console del server per controllo completo e gestione intuitiva.</p>
                </div>

                <div class="plan-item bg-gray-700 rounded-xl p-6 shadow-md transition-all duration-300 hover:bg-gray-600">
                    <i class="bi bi-shield-check text-3xl text-yellow-400 mb-2"></i>
                    <h5 class="text-xl font-semibold text-white mb-1">Uptime garantito 99.9%</h5>
                    <p class="text-gray-400 text-sm">Affidabilità elevata con infrastruttura ridondata e monitoraggio costante.</p>
                </div>
            </div>
        </section>
    </div>
</main>

<?php include("includes/footer.php"); ?>
</body>
</html>