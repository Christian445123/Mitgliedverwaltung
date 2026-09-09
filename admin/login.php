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
<title>Admin-Login – AFBÖ U19</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="auth-body">
<main class="card auth-card">
    <div class="brand">
        <span class="brand-badge">🏈</span>
        <h1>Admin-Login</h1>
        <p class="muted">AFBÖ U19 Mitgliederverwaltung</p>
    </div>

    <?php if ($justSeeded): ?>
        <div class="alert alert-info">
            <strong>Standard-Zugang wurde angelegt.</strong><br>
            Benutzername: <code><?= h(DEFAULT_ADMIN_USERNAME) ?></code><br>
            Passwort: <code><?= h(DEFAULT_ADMIN_PASSWORD) ?></code><br>
            <span class="hint">Du wirst nach dem Login aufgefordert, das Passwort zu ändern.</span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username">
        </div>
        <div class="field">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-block">Anmelden</button>
    </form>

    <p class="muted center" style="margin-top:1.5rem;"><a href="../index.php">&larr; Zurück zur Mitgliederseite</a></p>
</main>
</body>
</html>
