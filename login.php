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
<div class="main-container">
  <div class="card-custom">
    <h2>Accedi</h2>

    <?php if (!empty($error)): ?>
      <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <label for="username" class="form-label">Username o Email</label>
      <input name="username" id="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

      <label for="password" class="form-label" style="margin-top:1rem;">Password</label>
      <input type="password" name="password" id="password" class="form-control" required>

      <button type="submit" class="btn-custom" style="margin-top:1.5rem;">Accedi</button>
    </form>

    <div class="form-link">
      <p>Non hai un account? <a href="register.php">Registrati</a></p>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>