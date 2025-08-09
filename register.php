<div class="container main-container">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6">
        <h2 class="mb-4 text-center">Registrati</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>

        <form method="post" class="bg-light p-4 rounded shadow-sm">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input name="username" id="username" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">Registrati</button>

            <div class="text-center mt-3">
                <a href="login.php">Hai già un account? Accedi</a>
            </div>
        </form>
    </div>
</div>
