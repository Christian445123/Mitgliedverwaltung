<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Gespeichert';
require __DIR__ . '/includes/public_header.php';
?>
<div class="verify-box">
    <h1>Danke!</h1>
    <p class="alert alert-success">Deine Daten wurden erfolgreich gespeichert.</p>
    <p>Du kannst dieses Fenster nun schließen oder <a href="index.php">zur Startseite</a> zurückkehren.</p>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
