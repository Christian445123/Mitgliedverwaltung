<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/dsgvo.php';

require_permission('staff.edit');

$id = (int) ($_GET['id'] ?? 0);
$person = staff_find_by_id($id);

if ($person === false) {
    flash_set('error', 'Person nicht gefunden.');
    redirect('staff.php');
}

$access = staff_access_row($id);
$generatedPassword = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_link') {
        $newToken = random_token(32);
        $stmt = db()->prepare('UPDATE staff_access SET verify_token = ?, verified_at = NULL, failed_verify_attempts = 0, verify_locked_until = NULL WHERE staff_id = ?');
        $stmt->execute([$newToken, $id]);
        app_log('staff.link_regenerate', 'Zugangslink (Staff) neu erzeugt', ['target_type' => 'staff', 'target_id' => $id]);
    } elseif ($action === 'regenerate_password') {
        $generatedPassword = verif_regenerate_password('staff', $id);
        app_log('staff.code_regenerate', 'Zugangscode (Staff) neu erzeugt', ['target_type' => 'staff', 'target_id' => $id]);
    } elseif ($action === 'reset_verification') {
        verif_reset('staff', [$id]);
        $notice = ['type' => 'success', 'text' => 'Die Bestätigung wurde zurückgesetzt. Die Person muss ihre Daten erneut bestätigen.'];
    } elseif ($action === 'send_email') {
        $result = verif_send_link('staff', $id);
        $notice = ['type' => $result['status'] === 'sent' ? 'success' : 'error', 'text' => $result['message']];
    }
    $access = staff_access_row($id);
}

$link = staff_build_link((string) $access['verify_token']);
$pageTitle = 'Zugangslink (Staff)';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Zugangslink (Staff)</h1>
    <a href="staff.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<p>Für <strong><?= h($person['vorname'] . ' ' . $person['nachname']) ?></strong> (<?= h((string) $person['email']) ?>).</p>

<p>Mit diesem Link kann die Person ihre hinterlegten Daten prüfen, bestätigen und bei Bedarf korrigieren.
Zum Öffnen benötigt sie zusätzlich <?= !empty($person['email']) ? 'ihre E-Mail-Adresse' : 'ihren Nachnamen (keine E-Mail hinterlegt)' ?> und den Zugangscode.</p>

<div class="link-box">
    <input type="text" readonly value="<?= h($link) ?>" id="memberLink" data-select-on-click>
    <button type="button" class="btn" data-copy-target="memberLink">Kopieren</button>
</div>

<?php if ($notice): ?>
    <p class="alert alert-<?= $notice['type'] === 'success' ? 'success' : 'error' ?>"><?= h($notice['text']) ?></p>
<?php endif; ?>

<form method="post" action="staff-link.php?id=<?= (int) $id ?>" style="margin-bottom:20px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_email">
    <button type="submit" class="btn btn-secondary">Link &amp; Zugangscode per E-Mail senden</button>
</form>

<?php if ($generatedPassword): ?>
    <div class="alert alert-warning">
        <p><strong>Zugangscode (wird nur jetzt einmalig angezeigt):</strong></p>
        <div class="link-box">
            <input type="text" readonly value="<?= h($generatedPassword) ?>" id="accessPassword" data-select-on-click>
            <button type="button" class="btn" data-copy-target="accessPassword">Kopieren</button>
        </div>
    </div>
<?php else: ?>
    <p class="alert alert-warning">Der aktuelle Zugangscode wird nicht angezeigt (nur ein Hash ist gespeichert).
        Bei Bedarf unten einen neuen generieren oder per E-Mail senden.</p>
<?php endif; ?>

<?php if ($access['verified_at']): ?>
    <p class="alert alert-success">Die Person hat die Daten zuletzt am <?= h(date('d.m.Y H:i', strtotime((string) $access['verified_at']))) ?> Uhr bestätigt.</p>
<?php else: ?>
    <p class="alert alert-warning">Die Daten wurden von dieser Person noch nicht bestätigt.</p>
<?php endif; ?>

<?php if (user_can("dsgvo.manage")): $consentRow = dsgvo_consent_current("staff", (int) $id); ?>
    <p class="alert alert-<?= $consentRow ? "success" : "warning" ?>">Datenschutz-Einwilligung: <?= $consentRow ? "erteilt am " . h(date("d.m.Y H:i", (int) strtotime((string) $consentRow["accepted_at"]))) : "noch nicht erteilt" ?>
    · <a href="dsgvo-export.php?entity=staff&amp;id=<?= (int) $id ?>&amp;format=html">Auskunft (Datei)</a></p>
<?php endif; ?>
<?php if ($access['verified_at']): ?>
<form method="post" action="staff-link.php?id=<?= (int) $id ?>"
      data-confirm="Bestätigung zurücksetzen? Die Person muss ihre Daten danach erneut bestätigen.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_verification">
    <button type="submit" class="btn btn-secondary">Bestätigung zurücksetzen</button>
</form>
<?php endif; ?>

<form method="post" action="staff-link.php?id=<?= (int) $id ?>"
      data-confirm="Neuen Link erzeugen? Der alte Link funktioniert danach nicht mehr.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regenerate_link">
    <button type="submit" class="btn btn-secondary">Neuen Link erzeugen (alten ungültig machen)</button>
</form>

<form method="post" action="staff-link.php?id=<?= (int) $id ?>"
      data-confirm="Neuen Zugangscode generieren? Der alte Code funktioniert danach nicht mehr.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regenerate_password">
    <button type="submit" class="btn btn-secondary">Neuen Zugangscode generieren</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
