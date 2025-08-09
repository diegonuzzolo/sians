<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="assets/css/style.css">
    <meta charset="UTF-8">
    <title>Registrazione - Sians</title>
    <link
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
  rel="stylesheet"
/>

</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="main-container">
  <div class="card-custom">
    <h2>Registrati</h2>

    <?php if ($error): ?>
      <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert-success"><?= $success ?></div>
    <?php endif; ?>

    <form method="post">
      <label for="username" class="form-label">Username</label>
      <input name="username" id="username" class="form-control" required>

      <label for="email" class="form-label" style="margin-top:1rem;">Email</label>
      <input type="email" name="email" id="email" class="form-control" required>

      <label for="password" class="form-label" style="margin-top:1rem;">Password</label>
      <input type="password" name="password" id="password" class="form-control" required>

      <button type="submit" class="btn-custom" style="margin-top:1.5rem;">Registrati</button>
    </form>

    <div class="form-link">
      <p>Hai già un account? <a href="login.php">Accedi</a></p>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>