<div class="container main-container">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6">
        <div class="card shadow p-4">
            <h2 class="text-center mb-4">Login</h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="username" class="form-label">Username o Email</label>
                    <input name="username" id="username" class="form-control" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Accedi</button>
                </div>

                <div class="mt-3 text-center">
                    <a href="register.php">Non hai un account? Registrati</a>
                </div>
            </form>
        </div>
    </div>
</div>
