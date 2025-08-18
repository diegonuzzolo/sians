<?php 
// include __DIR__.'/../includes/force_https.php'; // Includi solo se necessario
include __DIR__.'/../config/config.php';


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT last_password_change FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $lastChange = $stmt->fetchColumn();

    if ($lastChange) {
        $daysSince = (new DateTime())->diff(new DateTime($lastChange))->days;
        if ($daysSince > 90) {
            header("Location: change_password.php?expired=1");
            exit;
        }
    }
}

$username_display = $_SESSION['username'] ?? 'Utente';
$user_logged_in = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sians Hosting</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>

    <style>
        /* Stili di base per il body, coerenti col tema scuro */
        body {
            background-color: #0f172a;
            color: #f1f5f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-top: 5rem; /* Per la navbar fissa */
        }
        
        main {
            flex: 1;
        }
        
        /* Stili per la navbar, usiamo un'ombra più sottile di default */
        .navbar {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }
        
        /* Dropdown con stile scuro */
        .dropdown-menu-dark {
            background-color: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }
        
        /* Animazione per i link hover */
        .nav-link:hover {
            color: #facc15;
            transition: color 0.3s ease;
        }

        /* Stili per il pulsante del toggler */
        .navbar-toggler-icon {
            filter: invert(1);
        }
    </style>
</head>
<body class="bg-slate-900 text-gray-300 min-h-screen flex flex-col">

<nav class="fixed top-0 left-0 right-0 z-50 bg-slate-900 shadow-lg py-4">
    <div class="container mx-auto px-4 flex justify-between items-center">
        <a class="text-white text-xl font-extrabold flex items-center gap-2" href="index.php">
            <i class="fa-brands fa-minecraft text-amber-400 text-2xl"></i> Minecraft Bedrock Hosting
        </a>

        <div class="md:hidden">
            <button id="mobile-menu-button" class="text-white focus:outline-none">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/>
                </svg>
            </button>
        </div>

        <div id="navbar-menu" class="hidden md:flex flex-grow justify-end items-center space-x-6">
            <a href="dashboard.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="add_server.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                <i class="fa-solid fa-plus-circle"></i> Acquista Server
            </a>

            <?php if ($user_logged_in): ?>
                <div class="relative group">
                    <button class="text-gray-300 hover:text-white focus:outline-none flex items-center gap-2">
                        <i class="fa-solid fa-user-circle"></i> <?= htmlspecialchars($username_display) ?>
                        <svg class="h-4 w-4 transform transition-transform duration-200 group-hover:rotate-180" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                    <div class="absolute right-0 mt-2 w-48 bg-slate-800 rounded-lg shadow-lg py-2 opacity-0 group-hover:opacity-100 transform transition-all duration-300 scale-95 group-hover:scale-100 origin-top-right invisible group-hover:visible">
                        <a href="profile.php" class="block px-4 py-2 text-gray-300 hover:bg-slate-700 flex items-center gap-2">
                            <i class="fa-solid fa-user-gear"></i> Profilo
                        </a>
                        <div class="border-t border-gray-700 my-1"></div>
                        <a href="logout.php" class="block px-4 py-2 text-red-400 hover:bg-slate-700 flex items-center gap-2">
                            <i class="fa-solid fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                    <i class="fa-solid fa-right-to-bracket"></i> Accedi
                </a>
                <a href="register.php" class="text-gray-300 hover:text-white flex items-center gap-2 transition duration-300">
                    <i class="fa-solid fa-user-plus"></i> Registrati
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<script>
    const mobileMenuButton = document.getElementById('mobile-menu-button');
    const navbarMenu = document.getElementById('navbar-menu');

    mobileMenuButton.addEventListener('click', () => {
        navbarMenu.classList.toggle('hidden');
    });
</script>