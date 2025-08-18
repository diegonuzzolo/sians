<?php
// Utilizza questo file come un "include" all'inizio di ogni pagina
// Esempio: `<?php require_once 'includes/header.php'; 

require_once __DIR__ . '/force_https.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Controllo scadenza password
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

// Prepara il nome utente per la visualizzazione
$username_display = $_SESSION['username'] ?? 'Utente';
$user_logged_in = isset($_SESSION['user_id']);
?>`

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sians - Minecraft Bedrock</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
    
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* Stili Base per il Tema Scuro */
        body {
            background-color: #121212;
            color: #f1f5f9;
            min-height: 100vh;
            padding-top: 5rem; /* Altezza navbar fissa */
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* Navbar */
        .navbar-custom {
            background: rgba(18, 18, 18, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .navbar-brand-custom {
            color: #facc15 !important;
            font-weight: 700;
        }
        .nav-link-custom {
            color: #e2e8f0 !important;
            transition: color 0.3s ease;
        }
        .nav-link-custom:hover {
            color: #facc15 !important;
        }
        .dropdown-menu-custom {
            background-color: #1a1a1a;
            border: 1px solid #333;
        }
        .dropdown-item-custom {
            color: #e2e8f0;
            transition: background-color 0.2s ease;
        }
        .dropdown-item-custom:hover {
            background-color: #333;
            color: #facc15;
        }
        
        /* Utility */
        .text-neon {
            color: #00c8ff;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-md navbar-custom">
    <div class="container-fluid mx-auto px-4">
        <a class="navbar-brand navbar-brand-custom d-flex items-center gap-2" href="index.php">
            <i class="fa-brands fa-minecraft fa-lg"></i> Minecraft Bedrock Hosting
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto gap-2 items-center">
                <li class="nav-item">
                    <a class="nav-link nav-link-custom d-flex items-center gap-2" href="dashboard.php">
                        <i class="fa-solid fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-custom d-flex items-center gap-2" href="add_server.php">
                        <i class="fa-solid fa-plus-circle"></i> Acquista Server
                    </a>
                </li>
                
                <?php if ($user_logged_in): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link nav-link-custom dropdown-toggle d-flex items-center gap-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fa-solid fa-user"></i> <?= htmlspecialchars($username_display) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-custom" aria-labelledby="userDropdown">
                            <li>
                                <a class="dropdown-item dropdown-item-custom d-flex items-center gap-2" href="profile.php">
                                    <i class="fa-solid fa-user-gear"></i> Profilo
                                </a>
                            </li>
                            <li><hr class="dropdown-divider bg-gray-600"></li>
                            <li>
                                <a class="dropdown-item dropdown-item-custom text-danger d-flex items-center gap-2" href="logout.php">
                                    <i class="fa-solid fa-sign-out-alt"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom d-flex items-center gap-2" href="login.php">
                            <i class="fa-solid fa-right-to-bracket"></i> Accedi
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-link-custom d-flex items-center gap-2" href="register.php">
                            <i class="fa-solid fa-user-plus"></i> Registrati
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="flex-grow-1">