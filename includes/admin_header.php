<?php
/** Erwartet: string $pageTitle (require_admin() muss bereits gelaufen sein) */

$currentScript = basename((string) $_SERVER['SCRIPT_NAME']);
$navActive = static fn (string $script) => $currentScript === $script ? ' active' : '';
$adminInitial = h(strtoupper(mb_substr(current_admin_username() ?? '?', 0, 1)));
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'Verwaltung') ?> – Mitgliederverwaltung</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= asset_version('style.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark">U19</span>
            <span>AFBÖ<br>Mitgliederverwaltung</span>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="<?= $navActive('index.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Mitglieder
            </a>
            <a href="account.php" class="<?= $navActive('account.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/></svg>
                Konto
            </a>
            <a href="update.php" class="<?= $navActive('update.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>
                Update
            </a>
        </nav>
        <div class="sidebar-footer">
            <span class="avatar-bubble"><?= $adminInitial ?></span>
            <div class="sidebar-footer-info">
                <strong><?= h(current_admin_username() ?? '') ?></strong>
                <a href="logout.php">Abmelden</a>
            </div>
        </div>
    </aside>
    <div class="main">
        <div class="content">
