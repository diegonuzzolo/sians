<?php

$_GET['server_id'] = $_GET['id'] ?? null; // Se usi un parametro diverso da server_id
include("auth_check.php");
// se arrivi qui, l'utente è loggato e il server è suo






?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Dashboard Minecraft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body {
      background: linear-gradient(to right, #0f172a, #1e293b);
      color: #f1f5f9;
      font-family: 'Segoe UI', sans-serif;
    }
    h2 {
      color: #facc15;
      font-weight: 600;
    }
    .server-card {
      background: #0f172a;
      border: 1px solid #334155;
      border-radius: 16px;
      padding: 25px 20px;
      margin-bottom: 25px;
      box-shadow: inset 0 0 10px rgba(51, 65, 85, 0.2), 0 4px 12px rgba(0, 0, 0, 0.3);
      transition: all 0.3s ease-in-out;
      color: #e2e8f0;
      font-size: 1.05rem;
    }

    .server-card:hover {
      transform: scale(1.02);
      box-shadow: 0 0 25px rgba(250, 204, 21, 0.2);
    }

    .server-card h5 {
      font-size: 1.3rem;
      font-weight: bold;
      color: #facc15;
    }

    .server-status {
      font-weight: bold;
      font-size: 1rem;
    }

    .badge-running {
      background-color: #16a34a;
      color: #fff;
      padding: 6px 12px;
      border-radius: 8px;
    }

    .badge-stopped {
      background-color: #475569;
      color: #fff;
      padding: 6px 12px;
      border-radius: 8px;
    }

    .ip-box {
      background-color: #1e293b;
      color: #93c5fd;
      padding: 6px 12px;
      border-radius: 8px;
      display: inline-block;
      font-size: 0.95rem;
      font-family: monospace;
    }

    .action-btn {
      padding: 10px 16px;
      font-size: 1rem;
      font-weight: 500;
      border-radius: 10px;
      transition: transform 0.2s ease-in-out;
    }

    .action-btn:hover {
      transform: scale(1.05);
    }

    .card-create {
      background: linear-gradient(to right, #0ea5e9, #22d3ee);
      color: #0f172a;
      font-weight: bold;
      transition: 0.3s;
      border-radius: 12px;
      font-size: 1.2rem;
      padding: 14px 24px;
    }

    .card-create:hover {
      background: linear-gradient(to right, #06b6d4, #38bdf8);
      transform: scale(1.04);
    }
  </style>
</head>
<body>




</body>
</html>
