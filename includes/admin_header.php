<?php
/** Erwartet: string $pageTitle (require_admin() muss bereits gelaufen sein) */
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Verwaltung') ?> – Mitgliederverwaltung</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-left">
            <span class="topbar-title">AFBÖ U19 – Mitgliederverwaltung</span>
            <nav class="topbar-nav">
                <a href="index.php">Mitglieder</a>
                <a href="account.php">Konto</a>
                <a href="update.php">Update</a>
            </nav>
        </div>
        <div class="topbar-user">
            <span class="avatar-bubble"><?= h(strtoupper(mb_substr(current_admin_username() ?? '?', 0, 1))) ?></span>
            <span><?= h(current_admin_username() ?? '') ?></span>
            &middot; <a href="logout.php">Abmelden</a>
        </div>
    </header>
    <main class="content">
