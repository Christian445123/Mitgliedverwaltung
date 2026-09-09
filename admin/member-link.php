<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';
require_once __DIR__ . '/../includes/Mailer.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$member = member_find_by_id($id);

if ($member === false) {
    flash_set('error', 'Mitglied nicht gefunden.');
    redirect('index.php');
}

// Zugangscode aus der Neuanlage / letzten Aktion einmalig anzeigen.
$generatedPassword = null;
if (!empty($_SESSION['generated_password'])) {
    $generatedPassword = $_SESSION['generated_password'];
    unset($_SESSION['generated_password']);
}

$mailNotice = null;
$info = flash_get('info');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_link') {
        $newToken = member_regenerate_token($id);
        $member['verify_token'] = $newToken;
        $member['verified_at'] = null;
        $member['failed_verify_attempts'] = 0;
        $member['verify_locked_until'] = null;
    } elseif ($action === 'regenerate_password') {
        $generatedPassword = member_regenerate_access_password($id);
        $member['failed_verify_attempts'] = 0;
        $member['verify_locked_until'] = null;
    } elseif ($action === 'send_email') {
        if (empty($member['email'])) {
            $mailNotice = ['type' => 'error', 'text' => 'Für dieses Mitglied ist keine E-Mail-Adresse hinterlegt.'];
        } else {
            // Neuer Zugangscode wird bei jedem Versand mitgeschickt (alter wird dabei ungültig).
            $freshPassword = member_regenerate_access_password($id);
            $member['failed_verify_attempts'] = 0;
            $member['verify_locked_until'] = null;
            $link = build_member_link($member['verify_token']);

            $html = '<p>Hallo ' . h($member['vorname']) . ',</p>'
                . '<p>hier ist dein persönlicher Link zur Mitgliederverwaltung des AFBÖ U19. Damit kannst du deine hinterlegten Daten prüfen und bei Bedarf korrigieren:</p>'
                . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
                . '<p>Zum Öffnen benötigst du zusätzlich deine E-Mail-Adresse und folgenden Zugangscode:</p>'
                . '<p style="font-size:1.2em;font-weight:bold;letter-spacing:1px;">' . h($freshPassword) . '</p>'
                . '<p>Falls du diesen Link nicht angefordert hast, wende dich bitte an den Verein.</p>';

            try {
                (new Mailer())->send($member['email'], $member['vorname'] . ' ' . $member['nachname'], 'Dein Zugang zur Mitgliederverwaltung – AFBÖ U19', $html);
                $generatedPassword = $freshPassword;
                $mailNotice = ['type' => 'success', 'text' => 'Link und Zugangscode wurden an ' . $member['email'] . ' gesendet.'];
            } catch (Throwable $e) {
                $mailNotice = ['type' => 'error', 'text' => 'E-Mail konnte nicht gesendet werden: ' . (APP_DEBUG ? $e->getMessage() : 'Bitte später erneut versuchen.')];
            }
        }
    }
}

$link = build_member_link($member['verify_token']);
$pageTitle = 'Zugangslink';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Zugangslink</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>

<p>Für <strong><?= h($member['vorname'] . ' ' . $member['nachname']) ?></strong> (<?= h($member['email']) ?>).</p>

<p>Dieser Link kann dem Mitglied geschickt werden, damit es seine hinterlegten Daten
prüfen und bei Bedarf korrigieren kann. Zum Öffnen benötigt das Mitglied zusätzlich
seine E-Mail-Adresse und den Zugangscode.</p>

<div class="link-box">
    <input type="text" readonly value="<?= h($link) ?>" id="memberLink" data-select-on-click>
    <button type="button" class="btn" data-copy-target="memberLink">Kopieren</button>
</div>

<?php if ($mailNotice): ?>
    <p class="alert alert-<?= $mailNotice['type'] === 'success' ? 'success' : 'error' ?>"><?= h($mailNotice['text']) ?></p>
<?php endif; ?>

<form method="post" action="member-link.php?id=<?= (int) $id ?>" style="margin-bottom:20px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_email">
    <button type="submit" class="btn btn-secondary">Link &amp; Zugangscode per E-Mail senden</button>
</form>

<?php if ($generatedPassword): ?>
    <div class="alert alert-warning">
        <p><strong>Zugangscode (wird aus Sicherheitsgründen nur jetzt einmalig angezeigt):</strong></p>
        <div class="link-box">
            <input type="text" readonly value="<?= h($generatedPassword) ?>" id="accessPassword" data-select-on-click>
            <button type="button" class="btn" data-copy-target="accessPassword">Kopieren</button>
        </div>
        <p>Er wird nirgends im Klartext gespeichert. Bei Verlust einfach unten neu generieren
        bzw. erneut per E-Mail senden – dabei wird automatisch ein neuer Code erzeugt.</p>
    </div>
<?php else: ?>
    <p class="alert alert-warning">Der aktuelle Zugangscode wird aus Sicherheitsgründen nicht mehr angezeigt
        (es ist nur ein Hash gespeichert). Bei Bedarf unten einen neuen generieren oder per E-Mail senden.</p>
<?php endif; ?>

<?php if ($member['verified_at']): ?>
    <p class="alert alert-success">Das Mitglied hat die Daten zuletzt am
        <?= h(date('d.m.Y H:i', strtotime($member['verified_at']))) ?> Uhr bestätigt.</p>
<?php else: ?>
    <p class="alert alert-warning">Die Daten wurden von diesem Mitglied noch nicht bestätigt.</p>
<?php endif; ?>

<?php if ((int) $member['failed_verify_attempts'] > 0): ?>
    <p class="alert alert-error">
        <?= (int) $member['failed_verify_attempts'] ?> fehlgeschlagene(r) Zugangsversuch(e).
        <?php if ($member['verify_locked_until'] && strtotime($member['verify_locked_until']) > time()): ?>
            Aktuell gesperrt bis <?= h(date('d.m.Y H:i', strtotime($member['verify_locked_until']))) ?> Uhr.
        <?php endif; ?>
    </p>
<?php endif; ?>

<form method="post" action="member-link.php?id=<?= (int) $id ?>"
      data-confirm="Neuen Link erzeugen? Der alte Link funktioniert danach nicht mehr.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regenerate_link">
    <button type="submit" class="btn btn-secondary">Neuen Link erzeugen (alten ungültig machen)</button>
</form>

<form method="post" action="member-link.php?id=<?= (int) $id ?>"
      data-confirm="Neuen Zugangscode generieren? Der alte Code funktioniert danach nicht mehr.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="regenerate_password">
    <button type="submit" class="btn btn-secondary">Neuen Zugangscode generieren</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
