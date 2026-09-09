<?php

declare(strict_types=1);

/**
 * Öffentliche Seite, die per persönlichem Link aufgerufen wird (kein Login
 * nötig). Zum Schutz der Daten reicht der Link allein nicht: Erst nach
 * Eingabe von E-Mail-Adresse + Zugangscode werden die Daten angezeigt.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/member_repository.php';

const MAX_VERIFY_ATTEMPTS = 5;
const VERIFY_LOCKOUT_MINUTES = 15;

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    $pageTitle = 'Ungültiger Link';
    require __DIR__ . '/includes/public_header.php';
    echo '<div class="verify-box"><p class="alert alert-error">Dieser Link ist ungültig.</p></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$member = member_find_by_token($token);

if ($member === false) {
    http_response_code(404);
    $pageTitle = 'Ungültiger Link';
    require __DIR__ . '/includes/public_header.php';
    echo '<div class="verify-box"><p class="alert alert-error">Dieser Link ist ungültig oder wurde bereits erneuert. Bitte wende dich an den Verein, um einen neuen Link zu erhalten.</p></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$memberId = (int) $member['id'];
$sessionKey = 'verify_unlocked_' . $memberId;
$unlocked = !empty($_SESSION[$sessionKey]);

// --- Stufe 1: Zugang per E-Mail + Zugangscode freischalten ------------------
if (!$unlocked) {
    $unlockError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') === 'unlock') {
        verify_csrf();

        $lockedUntil = $member['verify_locked_until'] ? strtotime($member['verify_locked_until']) : null;

        if ($lockedUntil && $lockedUntil > time()) {
            $minutesLeft = max(1, (int) ceil(($lockedUntil - time()) / 60));
            $unlockError = "Zu viele Fehlversuche. Bitte in etwa {$minutesLeft} Minute(n) erneut versuchen.";
        } else {
            $emailInput = trim((string) ($_POST['email'] ?? ''));
            $passwordInput = (string) ($_POST['password'] ?? '');

            $emailMatches = !empty($member['email']) && strcasecmp(trim($member['email']), $emailInput) === 0;
            $passwordMatches = !empty($member['access_password_hash']) && password_verify($passwordInput, $member['access_password_hash']);

            if ($emailMatches && $passwordMatches) {
                $_SESSION[$sessionKey] = true;
                member_record_verify_success($memberId);
                redirect('mitglied-formular.php?token=' . $token);
            }

            member_record_verify_failure($memberId, (int) $member['failed_verify_attempts'], MAX_VERIFY_ATTEMPTS, VERIFY_LOCKOUT_MINUTES);
            $unlockError = 'E-Mail-Adresse oder Zugangscode ist falsch.';
        }
    }

    $pageTitle = 'Zugang zu meinen Daten';
    ?>
    <!doctype html>
    <html lang="de">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> – AFBÖ U19</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= asset_version('style.css') ?>">
    </head>
    <body>
    <div class="split-auth">
        <div class="split-auth-brand">
            <span class="brand-mark">U19</span>
            <h1>AFBÖ U19</h1>
            <p>Hallo <?= h($member['vorname']) ?>! Bitte bestätige deine Identität, um deine Mitgliedsdaten zu bearbeiten.</p>
        </div>
        <div class="split-auth-form">
            <div class="split-auth-card">
                <h2>Zugang zu deinen Daten</h2>
                <p class="sub">Zum Schutz deiner Daten benötigen wir zusätzlich zum Link deine
                   hinterlegte E-Mail-Adresse und den per E-Mail mitgeteilten Zugangscode.</p>

                <?php if ($unlockError): ?>
                    <p class="alert alert-error"><?= h($unlockError) ?></p>
                <?php endif; ?>

                <form method="post" action="mitglied-formular.php?token=<?= h($token) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="stage" value="unlock">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" required autofocus>
                    <label for="password">Zugangscode</label>
                    <input type="text" id="password" name="password" required autocomplete="off">
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">Zugang prüfen</button>
                </form>

                <p class="privacy-note">
                    Hinweis zum Datenschutz: Deine Angaben werden ausschließlich zur Mitgliederverwaltung
                    des Vereins verarbeitet und nicht an Dritte weitergegeben.
                </p>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Stufe 2: freigeschaltet - Daten anzeigen/bearbeiten --------------------
$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') !== 'unlock') {
    verify_csrf();

    try {
        $data = member_collect_input($member);
        member_upsert($data, $memberId);
        $member = member_find_by_id($memberId);
        $saved = true;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $member = array_merge($member, $_POST);
    }
}

$m = $member;
$showAdminFields = false;

$pageTitle = 'Meine Mitgliedsdaten';
require __DIR__ . '/includes/public_header.php';
?>
<div class="verify-box">
    <h1>Deine Mitgliedsdaten</h1>
    <p>Bitte prüfe deine Daten und korrigiere sie bei Bedarf.</p>

    <?php if ($saved): ?><p class="alert alert-success">Danke! Deine Daten wurden gespeichert.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="mitglied-formular.php?token=<?= h($token) ?>" class="member-form" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <?php require __DIR__ . '/includes/member_fields.php'; ?>

        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
