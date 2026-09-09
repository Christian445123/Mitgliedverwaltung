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
<body class="auth-page">
    <div class="auth-box">
        <h1>AFBÖ U19</h1>
        <h2>Mitgliederdaten verwalten</h2>

        <p>Gib deine E-Mail-Adresse ein. Du erhältst einen Link, mit dem du deine Daten neu anlegen oder bearbeiten kannst.</p>

        <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>
        <?php if ($info): ?><p class="alert alert-info"><?= h($info) ?></p><?php endif; ?>

        <form method="post" action="request-link.php" novalidate>
            <?= csrf_field() ?>
            <label for="email">E-Mail-Adresse</label>
            <input type="email" id="email" name="email" required autocomplete="email" placeholder="name@beispiel.at">

            <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">Link anfordern</button>
        </form>

        <p class="privacy-note" style="text-align:center;"><a href="admin/login.php">Admin-Login</a></p>
    </div>
</body>
</html>
