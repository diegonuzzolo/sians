<?php
include(__DIR__ . "/auth_check.php"); // 🔒 Protezione login

// Recupero piani dal DB
$stmt = $pdo->query("SELECT * FROM plans ORDER BY price_per_month DESC");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="it">
<head>
        <link rel="stylesheet" href="assets/css/style.css">

<meta charset="UTF-8">
<title>Crea un nuovo server</title>
<style>
    html {
        height: 100%;
    }
    body {
        min-height: 100%;
        margin: 0;
        display: flex;
        flex-direction: column;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #0d0d0d, #1a1a1a);
        color: #f2f2f2;
    }
    main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .container {
        max-width: 700px;
        width: 100%;
        background: rgba(20, 20, 20, 0.95);
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 0 20px rgba(0,0,0,0.6);
    }
    h2 {
        text-align: center;
        margin-bottom: 25px;
        color: #00ff88;
        font-size: 1.8em;
    }
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: bold;
        font-size: 1.1em;
    }
    select, input {
        width: 100%;
        padding: 12px;
        border-radius: 8px;
        border: none;
        font-size: 1em;
        margin-bottom: 20px;
        background: #222;
        color: #fff;
        outline: none;
        box-shadow: inset 0 0 8px rgba(0, 0, 0, 0.5);
    }
    button {
        width: 100%;
        padding: 14px;
        font-size: 1.2em;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        background: linear-gradient(90deg, #00ff88, #00ccff);
        color: #000;
        font-weight: bold;
        transition: 0.3s ease;
    }
    button:hover {
        background: linear-gradient(90deg, #00ccff, #00ff88);
        transform: scale(1.02);
    }
        footer {
        text-align: center;
        padding: 15px;
        font-size: 0.9em;
        background: rgba(0,0,0,0.8);
        color: #aaa;
        border-top: 1px solid #222;
    }
    footer a {
        color: #00ff88;
        text-decoration: none;
    }
    footer a:hover {
        text-decoration: underline;
    }
</style>
</head>
<body>

<main>
    <div class="container">
        <h2>🚀 Crea un nuovo server</h2>
        <form action="create_server.php" method="POST">
            
            <label for="server_name">Nome del server:</label>
            <input type="text" name="server_name" id="server_name" placeholder="Es: IlMioServerEpico" required>

            <label for="plan">Seleziona Piano:</label>
            <select name="plan_id" id="plan" required>
                <option value="" disabled selected>Seleziona un piano...</option>
                <?php foreach ($plans as $plan): ?>
                    <option value="<?= htmlspecialchars($plan['id']) ?>">
                        <?= htmlspecialchars($plan['name']) ?> — 
                        <?= htmlspecialchars($plan['cpu_cores']) ?> Core / 
                        <?= htmlspecialchars($plan['ram_gb']) ?> GB RAM / 
                        <?= htmlspecialchars($plan['bandwidth_mbps']) ?> Mbps — 
                        €<?= htmlspecialchars($plan['price_per_month']) ?>/mese
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Crea Server</button>
        </form>
    </div>
</main>

<?php include("includes/footer.php"); ?>

</body>
</html>
