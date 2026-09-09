<?php
/** Erwartet: string $pageTitle */
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Admin') ?> – AFBÖ U19</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="topbar">
    <a class="topbar-brand" href="index.php"><span class="brand-badge small">🏈</span> AFBÖ U19 – Admin</a>
    <nav>
        <a href="index.php">Mitglieder</a>
        <a href="account.php">Konto</a>
        <span class="topbar-user">Angemeldet als <?= h(current_admin_username() ?? '') ?></span>
        <a href="logout.php" class="btn btn-small btn-secondary">Abmelden</a>
    </nav>
</div>
<div class="wrap">
