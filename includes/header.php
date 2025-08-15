<?php 
include __DIR__ . '/../includes/force_https.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
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


// Usa username dalla variabile $username se esiste, altrimenti dalla sessione, altrimenti fallback "Utente"
$username_display = $username ?? ($_SESSION['username'] ?? 'Utente');
?>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Sians - Minecraft Bedrock</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>

<style>
  /* Corpo e layout */
  body {
    background: linear-gradient(135deg, #1e293b, #0f172a);
    color: #f1f5f9;
    min-height: 100vh;
    padding-top: 70px; /* altezza navbar fissa */
    margin: 0;
    display: flex;
    flex-direction: column;
  }

  main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
  }

  .container {
    max-width: 1100px;
  }

  h1 {
    color: #facc15;
    font-weight: 900;
    text-shadow: 2px 2px 8px rgba(250, 204, 21, 0.7);
  }

  .table thead {
    background: #334155;
  }
  .table thead th {
    color: #facc15;
    border: none;
  }
  .table tbody tr {
    cursor: pointer;
    transition: background-color 0.25s ease;
  }
  .table tbody tr:hover {
    background-color: #475569;
  }

  .status-badge {
    font-weight: 600;
    padding: 0.25em 0.75em;
    border-radius: 15px;
    text-transform: capitalize;
    font-size: 0.9rem;
    user-select: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .status-running {
    background-color: #22c55e;
    color: #064e3b;
  }
  .status-stopped {
    background-color: #ef4444;
    color: #7f1d1d;
  }
  .status-installing {
    background-color: #facc15;
    color: #92400e;
  }

  .btn-action {
    min-width: 90px;
  }
  .progress {
    height: 20px;
    border-radius: 12px;
    overflow: hidden;
  }
  .progress-bar {
    background: linear-gradient(90deg, #fbbf24, #f59e0b);
  }

  /* Navbar */
  .navbar-nav .nav-link:hover {
    color: #facc15 !important;
  }
  .dropdown-menu .dropdown-item {
    padding: 10px 1.5rem;
  }

  /* .main-container serve solo se in pagina usi questo wrapper per centratura verticale e orizzontale */
  .main-container {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    padding: 0 1rem;
  }

  /* Card custom usata per login o altri contenitori centrati */
  .card-custom {
    background: linear-gradient(135deg, #1e1e1e, #2a2a2a);
    border-radius: 1rem;
    padding: 2.5rem 3rem;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 8px 24px rgba(255, 204, 0, 0.2);
  }
  .card-custom:hover {
    box-shadow: 0 12px 40px rgba(255, 204, 0, 0.5);
  }
  .card-custom h2 {
    color: #ffcc00;
    margin-bottom: 1.5rem;
    font-weight: 700;
    text-align: center;
    letter-spacing: 1.5px;
  }

  /* Form styles */
  .form-label {
    font-weight: 600;
    margin-bottom: 0.4rem;
    color: #ffcc00;
    letter-spacing: 0.05em;
  }
  .form-control {
    background: #2a2a2a;
    border: none;
    border-radius: 0.5rem;
    color: #fff;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    box-shadow: inset 0 0 6px #000000a0;
    transition: background 0.3s ease, box-shadow 0.3s ease;
  }
  .form-control:focus {
    outline: none;
    background: #3c3c3c;
    box-shadow: 0 0 8px #ffcc00;
    color: #ffcc00;
  }
  .btn-custom {
    background: #ffcc00;
    border: none;
    border-radius: 0.75rem;
    color: #222;
    font-weight: 700;
    font-size: 1.1rem;
    padding: 0.85rem;
    width: 100%;
    box-shadow: 0 4px 15px rgba(255, 204, 0, 0.4);
    transition: background 0.3s ease, box-shadow 0.3s ease;
    cursor: pointer;
  }
  .btn-custom:hover,
  .btn-custom:focus {
    background: #ffd633;
    box-shadow: 0 6px 20px rgba(255, 204, 0, 0.8);
    color: #111;
  }

  .form-link {
    text-align: center;
    margin-top: 1.2rem;
    font-size: 0.9rem;
  }
  .form-link a {
    color: #ffcc00;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
  }
  .form-link a:hover {
    color: #ffd633;
    text-decoration: underline;
  }

  .alert-danger {
    background: #7a1e1e;
    border-radius: 0.5rem;
    padding: 0.8rem 1rem;
    color: #ffb3b3;
    margin-bottom: 1rem;
    text-align: center;
  }
  .alert-success {
    background: #3b5d1f;
    border-radius: 0.5rem;
    padding: 0.8rem 1rem;
    color: #c9ffb3;
    margin-bottom: 1rem;
    text-align: center;
  }

  /* Footer */
  footer {
    background: #1b1b1b;
    color: #777;
    padding: 15px 0;
    text-align: center;
    font-size: 0.9rem;
    border-top: 1px solid #333;
    margin-top: auto;
  }
  footer a {
    color: #ffcc00;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
  }
  footer a:hover {
    color: #ffd633;
    text-decoration: underline;
  }
</style>
</head>
<body>

<?php
// Usa questa variabile per mostrare username nella navbar
$username_display = $_SESSION['username'] ?? null;
$user_logged_in = isset($_SESSION['user_id']);
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-gradient shadow-sm fixed-top" style="background: linear-gradient(90deg, #1f2937, #111827);">
  <div class="container">
    <a class="navbar-brand fw-bold text-warning d-flex align-items-center gap-2" href="index.php">
      <i class="fa-brands fa-minecraft fa-lg"></i> Minecraft Bedrock Hosting
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
      aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
      <ul class="navbar-nav align-items-center gap-3">
        <li class="nav-item">
          <a class="nav-link active d-flex align-items-center gap-2" href="dashboard.php">
            <i class="fa-solid fa-tachometer-alt"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center gap-2" href="add_server.php">
            <i class="fa-solid fa-plus"></i> Crea Server
          </a>
        </li>
        
        <?php if ($user_logged_in): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fa-solid fa-user"></i> <?= htmlspecialchars($username_display) ?>
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
