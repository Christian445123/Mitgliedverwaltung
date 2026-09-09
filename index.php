<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$error = flash_get('error');
$info = flash_get('info');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AFBÖ U19 – Mitgliederdaten verwalten</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
<main class="card auth-card">
    <div class="brand">
        <span class="brand-badge">🏈</span>
        <h1>Mitgliederdaten verwalten</h1>
        <p class="muted">AFBÖ U19</p>
    </div>

    <p class="muted center" style="margin-bottom:1.5rem;">Gib deine E-Mail-Adresse ein. Du erhältst einen Link, mit dem du deine Daten neu anlegen oder bearbeiten kannst.</p>

    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
    <?php if ($info): ?><p class="alert alert-info"><?= h($info) ?></p><?php endif; ?>

    <form method="post" action="request-link.php">
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email" required autocomplete="email" placeholder="name@beispiel.at">
        </div>
        <button type="submit" class="btn-block">Link anfordern</button>
    </form>

    <p class="muted center" style="margin-top:1.5rem;"><a href="admin/login.php">Admin-Login</a></p>
</main>
</body>
</html>
