<?php
/** Erwartet: string $pageTitle */
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#11152a">
<title><?= h($pageTitle ?? 'Mitgliederverwaltung') ?> – AFBÖ U19</title>
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/app-icon.png">
<link rel="stylesheet" href="assets/style.css?v=<?= asset_version('style.css') ?>">
</head>
<body class="public-page">
<main class="content">
