<?php 
require_once __DIR__ . '/../includes/force_https.php';

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
$user_logged_in   = isset($_SESSION['user_id']);
?>

<!-- CDN Bootstrap + Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

<style>
  body {
    background: linear-gradient(135deg, #1e293b, #0f172a);
    color: #f1f5f9;
    min-height: 100vh;
    padding-top: 70px;
    margin: 0;
    display: flex;
    flex-direction: column;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  main { flex: 1; }

  /* Navbar */
  .navbar {
    background: linear-gradient(90deg, #1f2937, #111827) !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.6);
  }
  .navbar-brand {
    font-weight: 900;
    letter-spacing: 0.5px;
  }
  .navbar-brand i {
    color: #facc15;
  }
  .navbar-nav .nav-link {
    color: #f1f5f9 !important;
    transition: color 0.3s ease;
  }
  .navbar-nav .nav-link:hover {
    color: #facc15 !important;
  }
  .dropdown-menu {
    background: #1e293b;
    border: none;
    box-shadow: 0 6px 20px rgba(0,0,0,0.4);
  }
  .dropdown-item {
    color: #f1f5f9;
  }
  .dropdown-item:hover {
    background: #334155;
    color: #facc15;
  }
</style>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
  <div class="container">
    <a class="navbar-brand text-warning d-flex align-items-center gap-2" href="index.php">
      <i class="fa-brands fa-minecraft fa-lg"></i> Minecraft Bedrock Hosting
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
      <ul class="navbar-nav align-items-center gap-3">
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center gap-2" href="dashboard.php">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center gap-2" href="add_server.php">
            <i class="fa-solid fa-plus"></i> Acquista Server
          </a>
        </li>

        <?php if ($user_logged_in): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fa-solid fa-user-circle"></i> <?= htmlspecialchars($username_display) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2" href="profile.php">
                  <i class="fa-solid fa-user-gear"></i> Profilo
                </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <a class="dropdown-item text-danger d-flex align-items-center gap-2" href="logout.php">
                  <i class="fa-solid fa-sign-out-alt"></i> Logout
                </a>
              </li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="nav-link d-flex align-items-center gap-2" href="login.php">
              <i class="fa-solid fa-right-to-bracket"></i> Accedi
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link d-flex align-items-center gap-2" href="register.php">
              <i class="fa-solid fa-user-plus"></i> Registrati
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
