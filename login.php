<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Accedi a Sians</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
  rel="stylesheet"
/>


</head>
<body>

<div class="container main-container">
    <div class="col-12 col-sm-10 col-md-8 col-lg-5">
        <div class="card-custom">
            <h2 class="text-center mb-4">Benvenuto 👋</h2>
            <p class="text-center mb-4">Accedi per gestire i tuoi server Minecraft</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="username" class="form-label">Username o Email</label>
                    <input name="username" id="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-custom btn-lg">Accedi</button>
                </div>

                <div class="mt-3 text-center">
                    <small>Non hai un account? <a href="register.php" class="text-warning">Registrati</a></small>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>

</body>
</html>