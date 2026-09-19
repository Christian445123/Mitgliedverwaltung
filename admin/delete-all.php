<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_administrator();

const DELETE_ALL_PHRASE = 'ALLE LÖSCHEN';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (trim((string) ($_POST['confirm'] ?? '')) !== DELETE_ALL_PHRASE) {
        $error = 'Die Bestätigung stimmt nicht. Bitte genau "' . DELETE_ALL_PHRASE . '" eingeben.';
    } else {
        $count = member_delete_all();
        flash_set('info', 'Alle Mitglieder wurden gelöscht (' . $count . ').');
        redirect('index.php');
    }
}

$total = member_count('');
$pageTitle = 'Alle Daten löschen';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Alle Daten löschen</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<div class="alert alert-error">
    <p><strong>Achtung: Diese Aktion kann nicht rückgängig gemacht werden.</strong></p>
    <p>Es werden <strong><?= (int) $total ?> Mitglieder</strong> mit allen Angaben und allen hochgeladenen
       Dokumenten (E-Card, Pass, NADA, Rechte &amp; Pflichten) endgültig gelöscht.
       Benutzer und API-Zugänge bleiben erhalten.</p>
    <p>Tipp: Vorher unter „Import / Export“ die Daten als CSV sichern.</p>
</div>

<form method="post" action="delete-all.php" class="panel" style="max-width:520px;">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="confirm">Zur Bestätigung <code><?= h(DELETE_ALL_PHRASE) ?></code> eingeben</label>
        <input type="text" id="confirm" name="confirm" autocomplete="off" required>
    </div>
    <button type="submit" class="btn btn-danger">Alle Mitglieder endgültig löschen</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
