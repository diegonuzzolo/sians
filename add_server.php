
<?php include 'includes/header.php'; ?>

<div class="container mt-4">
    <h2>Crea un nuovo server Minecraft</h2>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

<form action="server_action.php" method="POST">
  <input type="text" name="server_name" placeholder="Nome Server" required>
  <input type="text" name="subdomain" placeholder="Sottodominio" required>
  <button type="submit">Crea Server</button>
</form>

</div>

<?php include 'includes/footer.php'; ?>

