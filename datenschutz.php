<?php

declare(strict_types=1);

/** Öffentliche Datenschutzerklärung (Vorlage im Code, Angaben des Verantwortlichen im Webpanel unter „Datenschutz“). */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dsgvo.php';

$pageTitle = 'Datenschutzerklärung';
require __DIR__ . '/includes/public_header.php';
?>
<div class="verify-box">
    <h1>Datenschutzerklärung</h1>
    <p class="muted">Fassung vom <?= h(dsgvo_policy_version()) ?></p>
    <p>Die allgemeine Datenschutzerklärung des Verbands findest du unter
        <a href="https://football.at/privacy-policy/" target="_blank" rel="noopener">football.at/privacy-policy</a>.
        Für die Mitgliederverwaltung des Vereins gilt zusätzlich die folgende Erklärung:</p>
    <?php foreach (dsgvo_policy_sections() as [$heading, $html]): ?>
        <h2><?= h($heading) ?></h2>
        <?= $html ?>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
