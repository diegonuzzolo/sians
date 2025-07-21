<?php session_start(); ?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Server Minecraft Hosting - Sians</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(to right, #1e3c72, #2a5298);
      color: #fff;
      font-family: 'Segoe UI', sans-serif;
    }
    .hero {
      padding: 80px 0;
      text-align: center;
    }
    .hero h1 {
      font-size: 3.5rem;
      font-weight: bold;
    }
    .hero p {
      font-size: 1.3rem;
    }
    .btn-primary {
      background-color: #28a745;
      border: none;
    }
    .btn-primary:hover {
      background-color: #218838;
    }
    .navbar {
      background-color: rgba(0, 0, 0, 0.7);
    }
    .card {
      background-color: #fff;
      color: #000;
    }
    footer {
      background-color: #111;
      color: #ccc;
      padding: 20px 0;
      text-align: center;
    }
  </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="https://sians.it">Sians Hosting</a>
    <div class="collapse navbar-collapse justify-content-end">
      <ul class="navbar-nav">
        <?php if (isset($_SESSION['user_id'])): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="register.php">Registrati</a></li>
          <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="container">
    <h1>Ospita il tuo server Minecraft in pochi click</h1>
    <p>Prestazioni elevate, gestione facile, 100% gratuito per iniziare.</p>
    <a href="register.php" class="btn btn-primary btn-lg mt-3">Inizia ora</a>
  </div>
</section>

<section class="py-5">
  <div class="container">
    <h2 class="text-center mb-5">Funzionalità principali</h2>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="card p-3">
          <h4>Setup istantaneo</h4>
          <p>Avvia un nuovo server Minecraft con un click dalla tua dashboard personale.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card p-3">
          <h4>Controllo completo</h4>
          <p>Avvia, spegni o elimina il tuo server in qualsiasi momento.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card p-3">
          <h4>100% web</h4>
          <p>Gestisci tutto direttamente dal sito, senza complicazioni.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<footer>
  <div class="container">
    <p>&copy; <?= date('Y') ?> Sians Hosting - Tutti i diritti riservati</p>
  </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
