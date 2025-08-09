<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/style.css">
    <meta charset="UTF-8">
    <title>Registrazione - Sians</title>
</head>
<body>
<div class="container main-container">
    <div class="col-12 col-sm-10 col-md-8 col-lg-5">
        <div class="card-custom">
            <h2 class="text-center mb-4">Crea il tuo account ✨</h2>
            <p class="text-center mb-4">Registrati per iniziare a creare il tuo server</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input name="username" id="username" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control" required>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-custom btn-lg">Registrati</button>
                </div>

                <div class="mt-3 text-center">
                    <small>Hai già un account? <a href="login.php" class="text-warning">Accedi</a></small>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
