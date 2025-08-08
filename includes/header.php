<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard Minecraft Bedrock</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet"/>

        <style>
    body {
      background: linear-gradient(135deg, #1e293b, #0f172a);
      color: #f1f5f9;
      min-height: 100vh;
      padding-top: 70px; /* navbar height */
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
  </style>
  </head>
<body>
 <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold text-warning" href="dashboard.php">
        <i class="fa-brands fa-minecraft fa-lg"></i> Minecraft Bedrock Hosting
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
        aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
        <ul class="navbar-nav align-items-center">
          <li class="nav-item">
            <a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-tachometer-alt"></i> Dashboard</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="create_server.php"><i class="fa-solid fa-plus"></i> Crea Server</a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" 
               aria-expanded="false">
              <i class="fa-solid fa-user"></i> <?= htmlspecialchars($username) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
              <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user-gear"></i> Profilo</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a></li>
            </ul>
          </li>
        </ul>
      </div>
    </div>
  </nav>
