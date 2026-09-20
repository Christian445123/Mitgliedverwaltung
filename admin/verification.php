<?php

declare(strict_types=1);

/**
 * Massenaktionen zur Datenbestätigung: Bestätigung zurücksetzen und/oder Link + Zugangscode per E-Mail senden
 * (für eine Auswahl, alle noch nicht bestätigten oder alle aktiven Spieler bzw. Staff-Personen).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/verification.php';

$entity = verif_entity((string) ($_POST['entity'] ?? $_GET['entity'] ?? 'members'));
$isStaff = $entity === 'staff';
require_permission($isStaff ? 'staff.edit' : 'members.links');

$noun = $isStaff ? 'Staff-Personen' : 'Spieler';
$backUrl = $isStaff ? 'staff.php' : 'index.php';

$selected = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i) => $i > 0)));
$results = null;
$resetCount = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    verify_csrf();
    $mode = (string) ($_POST['mode'] ?? '');
    $doReset = !empty($_POST['do_reset']);
    $doMail = !empty($_POST['do_mail']);

    if (!in_array($mode, ['selected', 'pending', 'all'], true)) {
        $error = 'Bitte einen Umfang wählen.';
    } elseif (!$doReset && !$doMail) {
        $error = 'Bitte mindestens eine Aktion wählen (Bestätigung zurücksetzen und/oder E-Mail senden).';
    } else {
        $ids = $mode === 'selected' ? $selected : verif_ids($entity, $mode);
        if ($ids === []) {
            $error = 'Es gibt keine Personen für diese Auswahl.';
        } else {
            if ($doReset) {
                $resetCount = verif_reset($entity, $ids);
            }
            if ($doMail) {
                $results = verif_send_many($entity, $ids);
            }
        }
    }
}

$allIds = verif_ids($entity, 'all');
$pendingIds = verif_ids($entity, 'pending');
$mailAll = verif_count_with_email($entity, $allIds);
$mailPending = verif_count_with_email($entity, $pendingIds);
$mailSel = verif_count_with_email($entity, $selected);

$defaultMode = $selected !== [] ? 'selected' : 'all';
$mode = (string) ($_POST['mode'] ?? $defaultMode);

$pageTitle = 'Daten bestätigen lassen';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Daten bestätigen lassen – <?= h($noun) ?></h1>
    <a href="<?= h($backUrl) ?>" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<p>Setzt die Bestätigung zurück, damit jede Person ihre Daten über den persönlichen Link erneut prüfen und bestätigen muss,
und/oder sendet allen Link und einen neuen Zugangscode per E-Mail (Massenmail). Personen ohne E-Mail-Adresse erhalten keine Mail.</p>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<?php if ($resetCount !== null): ?>
    <p class="alert alert-success">Bestätigung zurückgesetzt bei <?= (int) $resetCount ?> Person(en).</p>
<?php endif; ?>

<?php if ($results !== null): ?>
    <?php
    $sent = count(array_filter($results, static fn (array $r) => $r['status'] === 'sent'));
    $noMail = count(array_filter($results, static fn (array $r) => $r['status'] === 'no_email'));
    $failed = count($results) - $sent - $noMail;
    ?>
    <p class="alert alert-<?= $failed === 0 ? 'success' : 'warning' ?>">
        E-Mails: <?= $sent ?> gesendet, <?= $noMail ?> ohne E-Mail-Adresse, <?= $failed ?> fehlgeschlagen.
    </p>
    <?php $problems = array_filter($results, static fn (array $r) => $r['status'] !== 'sent'); ?>
    <?php if ($problems !== []): ?>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Name</th><th>Ergebnis</th></tr></thead>
        <tbody>
        <?php foreach ($problems as $r): ?>
            <tr><td><?= h($r['name']) ?></td><td><?= h($r['message']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post" action="verification.php" class="member-form" data-confirm="Aktion jetzt ausführen?">
    <?= csrf_field() ?>
    <input type="hidden" name="entity" value="<?= h($entity) ?>">
    <input type="hidden" name="run" value="1">
    <?php foreach ($selected as $sid): ?><input type="hidden" name="ids[]" value="<?= (int) $sid ?>"><?php endforeach; ?>

    <fieldset>
        <legend>Für wen?</legend>
        <?php if ($selected !== []): ?>
            <label style="font-weight:400;display:block;margin-bottom:6px;">
                <input type="radio" name="mode" value="selected" <?= $mode === 'selected' ? 'checked' : '' ?>>
                Ausgewählte (<?= count($selected) ?>, davon <?= $mailSel ?> mit E-Mail)
            </label>
        <?php endif; ?>
        <label style="font-weight:400;display:block;margin-bottom:6px;">
            <input type="radio" name="mode" value="pending" <?= $mode === 'pending' ? 'checked' : '' ?>>
            Alle aktiven ohne Bestätigung (<?= count($pendingIds) ?>, davon <?= $mailPending ?> mit E-Mail)
        </label>
        <label style="font-weight:400;display:block;">
            <input type="radio" name="mode" value="all" <?= $mode === 'all' ? 'checked' : '' ?>>
            Alle aktiven <?= h($noun) ?> (<?= count($allIds) ?>, davon <?= $mailAll ?> mit E-Mail)
        </label>
    </fieldset>

    <fieldset>
        <legend>Aktion</legend>
        <label style="font-weight:400;display:block;margin-bottom:6px;">
            <input type="checkbox" name="do_reset" value="1" <?= (!empty($_POST['run']) ? !empty($_POST['do_reset']) : true) ? 'checked' : '' ?>>
            Bestätigung zurücksetzen (alle müssen ihre Daten erneut bestätigen)
        </label>
        <label style="font-weight:400;display:block;">
            <input type="checkbox" name="do_mail" value="1" <?= (!empty($_POST['run']) ? !empty($_POST['do_mail']) : true) ? 'checked' : '' ?>>
            Link &amp; neuen Zugangscode per E-Mail senden (Massenmail)
        </label>
    </fieldset>

    <button type="submit" class="btn btn-primary">Ausführen</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
