<?php
/** Erwartet: string $pageTitle (require_admin() muss bereits gelaufen sein) */

$currentScript = basename((string) $_SERVER['SCRIPT_NAME']);
$navActive = static fn (string $script) => $currentScript === $script ? ' active' : '';
$adminInitial = h(strtoupper(mb_substr(current_admin_username() ?? '?', 0, 1)));
require_once __DIR__ . '/expiry.php';
$expiryTotal = (int) (expiry_report()['counts']['total'] ?? 0); // abgelaufene bzw. bald ablaufende NADA-Zertifikate/Pässe
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#11152a">
<title><?= h($pageTitle ?? 'Verwaltung') ?> – Mitgliederverwaltung</title>
<link rel="icon" href="../assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/favicon-32.png">
<link rel="apple-touch-icon" href="../assets/app-icon.png">
<link rel="stylesheet" href="../assets/style.css?v=<?= asset_version('style.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark"><img src="../assets/logo.png" alt="AFBÖ"></span>
            <span>AFBÖ<br>Mitgliederverwaltung</span>
            <button type="button" class="nav-toggle" data-nav-toggle aria-label="Menü öffnen" aria-expanded="false">&#9776;</button>
        </div>
        <nav class="sidebar-nav">
            <?php if (user_can('members.view')): ?>
            <a href="index.php" class="<?= $navActive('index.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Mitglieder
                <?php if ($expiryTotal > 0): ?><span class="nav-badge" title="Abgelaufene bzw. bald ablaufende Dokumente"><?= $expiryTotal ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (user_can('staff.view')): ?>
            <a href="staff.php" class="<?= $navActive('staff.php') . $navActive('staff-form.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Staff
            </a>
            <?php endif; ?>
            <?php if (user_can('members.import')): ?>
            <a href="import.php" class="<?= $navActive('import.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Import / Export
            </a>
            <?php endif; ?>
            <?php if (user_can('camps.manage')): ?>
            <a href="camps.php" class="<?= $navActive('camps.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20 12 4l9 16z"/><path d="M12 12v8"/></svg>
                Camps
            </a>
            <?php endif; ?>
            <a href="download.php" class="<?= $navActive('download.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                Desktop-App
            </a>
            <a href="account.php" class="<?= $navActive('account.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/></svg>
                Konto
            </a>
            <?php if (user_can('users.manage')): ?>
            <a href="users.php" class="<?= $navActive('users.php') . $navActive('user-form.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Benutzer
            </a>
            <?php endif; ?>
            <?php if (user_can('fields.manage')): ?>
            <a href="permissions.php" class="<?= $navActive('permissions.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Feld-Rechte
            </a>
            <?php endif; ?>
            <?php if (user_can('license.manage')): ?>
            <a href="licenses.php" class="<?= $navActive('licenses.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="m10.85 12.15 8.4-8.4"/><path d="m18 5 3 3"/><path d="m15 8 2 2"/></svg>
                Lizenzen
            </a>
            <?php endif; ?>
            <?php if (user_can('api.manage')): ?>
            <a href="api.php" class="<?= $navActive('api.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                API-Zugang
            </a>
            <?php endif; ?>
            <?php if (user_can('logs.view')): ?>
            <a href="logs.php" class="<?= $navActive('logs.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                Protokoll
            </a>
            <?php endif; ?>
            <?php if (user_can('dsgvo.manage')): ?>
            <a href="dsgvo.php" class="<?= $navActive('dsgvo.php') . $navActive('dsgvo-export.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Datenschutz
            </a>
            <?php endif; ?>
            <?php if (user_can('system.update')): ?>
            <a href="update.php" class="<?= $navActive('update.php') ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>
                Update
            </a>
            <?php endif; ?>
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
