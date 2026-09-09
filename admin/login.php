<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('index.php');
}

$justSeeded = ensure_default_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = post_str('username') ?? '';
    $password = (string) ($_POST['password'] ?? '');

    if (admin_login($username, $password)) {
        redirect('index.php');
    }

    $error = 'Benutzername oder Passwort ist falsch.';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login – Mitgliederverwaltung</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="auth-page">
    <div class="auth-box">
        <h1>AFBÖ U19</h1>
        <h2>Admin-Login</h2>

        <?php if ($justSeeded): ?>
            <div class="alert alert-info">
                <strong>Standard-Zugang wurde angelegt.</strong><br>
                Benutzername: <code><?= h(DEFAULT_ADMIN_USERNAME) ?></code><br>
                Passwort: <code><?= h(DEFAULT_ADMIN_PASSWORD) ?></code><br>
                Du wirst nach dem Login aufgefordert, das Passwort zu ändern.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <p class="alert alert-error"><?= h($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <?= csrf_field() ?>
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">Anmelden</button>
        </form>
    </div>
</body>
</html>
