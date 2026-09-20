<?php

declare(strict_types=1);

/**
 * Öffentliche Seite für Trainer/Betreuer (Staff), aufgerufen per persönlichem Link (kein Login nötig).
 * Wie bei den Spielern reicht der Link allein nicht: Erst nach Eingabe von E-Mail-Adresse
 * (ohne hinterlegte E-Mail: Nachname) und Zugangscode werden die Daten angezeigt.
 * Das erfolgreiche Entsperren gilt als Bestätigung (Zeitpunkt in staff_access.verified_at).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/verification.php';
require_once __DIR__ . '/includes/member_columns.php';

const STAFF_MAX_VERIFY_ATTEMPTS = 5;
const STAFF_VERIFY_LOCKOUT_MINUTES = 15;

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

$invalid = static function (string $text): never {
    http_response_code(404);
    $pageTitle = 'Ungültiger Link';
    require __DIR__ . '/includes/public_header.php';
    echo '<div class="verify-box"><p class="alert alert-error">' . h($text) . '</p></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
};

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $invalid('Dieser Link ist ungültig.');
}

$person = staff_find_by_token($token);
if ($person === false) {
    $invalid('Dieser Link ist ungültig oder wurde bereits erneuert. Bitte wende dich an den Verein, um einen neuen Link zu erhalten.');
}

$staffId = (int) $person['id'];
$sessionKey = 'staff_verify_unlocked_' . $staffId;
$unlocked = !empty($_SESSION[$sessionKey]);

// --- Stufe 1: Zugang per E-Mail (bzw. Nachname) + Zugangscode ----------------
if (!$unlocked) {
    $unlockError = null;
    $hasEmail = !empty($person['email']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') === 'unlock') {
        verify_csrf();

        $lockedUntil = $person['verify_locked_until'] ? strtotime((string) $person['verify_locked_until']) : null;

        if ($lockedUntil && $lockedUntil > time()) {
            $minutesLeft = max(1, (int) ceil(($lockedUntil - time()) / 60));
            $unlockError = "Zu viele Fehlversuche. Bitte in etwa {$minutesLeft} Minute(n) erneut versuchen.";
        } else {
            $identInput = trim((string) ($_POST['email'] ?? ''));
            $passwordInput = (string) ($_POST['password'] ?? '');

            $expected = $hasEmail ? trim((string) $person['email']) : trim((string) $person['nachname']);
            $identMatches = $expected !== '' && strcasecmp($expected, $identInput) === 0;
            $passwordMatches = !empty($person['access_password_hash']) && password_verify($passwordInput, (string) $person['access_password_hash']);

            if ($identMatches && $passwordMatches) {
                $_SESSION[$sessionKey] = true;
                $stmt = db()->prepare('UPDATE staff_access SET verified_at = NOW(), failed_verify_attempts = 0, verify_locked_until = NULL WHERE staff_id = ?');
                $stmt->execute([$staffId]);
                app_log('staff.self_verify', 'Staff-Person hat den Zugang bestätigt', ['actor' => 'staff:' . $staffId, 'target_type' => 'staff', 'target_id' => $staffId]);
                redirect('staff-formular.php?token=' . $token);
            }

            $attempts = (int) $person['failed_verify_attempts'] + 1;
            if ($attempts >= STAFF_MAX_VERIFY_ATTEMPTS) {
                $stmt = db()->prepare('UPDATE staff_access SET failed_verify_attempts = ?, verify_locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE staff_id = ?');
                $stmt->execute([$attempts, STAFF_VERIFY_LOCKOUT_MINUTES, $staffId]);
            } else {
                $stmt = db()->prepare('UPDATE staff_access SET failed_verify_attempts = ? WHERE staff_id = ?');
                $stmt->execute([$attempts, $staffId]);
            }
            app_log('staff.self_verify_failed', 'Falscher Zugangscode (Staff)', ['actor' => 'staff:' . $staffId, 'target_type' => 'staff', 'target_id' => $staffId], 'warning');
            $unlockError = ($hasEmail ? 'E-Mail-Adresse' : 'Nachname') . ' oder Zugangscode ist falsch.';
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
    <link rel="icon" href="assets/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
    <link rel="apple-touch-icon" href="assets/app-icon.png">
    <link rel="stylesheet" href="assets/style.css?v=<?= asset_version('style.css') ?>">
    </head>
    <body>
    <div class="split-auth">
        <div class="split-auth-brand">
            <span class="brand-mark">U19</span>
            <h1>AFBÖ U19</h1>
            <p>Hallo <?= h((string) $person['vorname']) ?>! Bitte bestätige deine Identität, um deine Daten zu prüfen.</p>
        </div>
        <div class="split-auth-form">
            <div class="split-auth-card">
                <h2>Zugang zu deinen Daten</h2>
                <p class="sub">Zum Schutz deiner Daten benötigen wir zusätzlich zum Link
                   <?= $hasEmail ? 'deine hinterlegte E-Mail-Adresse' : 'deinen Nachnamen' ?> und den per E-Mail mitgeteilten Zugangscode.</p>

                <?php if ($unlockError): ?>
                    <p class="alert alert-error"><?= h($unlockError) ?></p>
                <?php endif; ?>

                <form method="post" action="staff-formular.php?token=<?= h($token) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="stage" value="unlock">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <label for="email"><?= $hasEmail ? 'E-Mail-Adresse' : 'Nachname' ?></label>
                    <input type="<?= $hasEmail ? 'email' : 'text' ?>" id="email" name="email" required autofocus>
                    <label for="password">Zugangscode</label>
                    <input type="text" id="password" name="password" required autocomplete="off">
                    <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">Zugang prüfen</button>
                </form>

                <p class="privacy-note">
                    Hinweis zum Datenschutz: Deine Angaben werden ausschließlich zur Verwaltung
                    des Vereins verarbeitet und nicht an Dritte weitergegeben. <a href="datenschutz.php">Datenschutzerklärung</a>
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
require_once __DIR__ . '/includes/privacy_gate.php';
privacy_gate('staff', $staffId, $person, 'staff-formular.php?token=' . $token); // Einwilligung, Auskunft, Löschantrag

$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stage'] ?? '') !== 'unlock') {
    verify_csrf();

    try {
        $data = staff_collect_input();
        unset($data['status']); // Status ändert nur der Verein
        staff_upsert($data, $staffId);
        app_log('staff.self_update', 'Staff-Person hat ihre Daten selbst geändert', ['actor' => 'staff:' . $staffId, 'target_type' => 'staff', 'target_id' => $staffId]);
        $person = array_merge($person, $data);
        $saved = true;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $person = array_merge($person, $_POST);
    }
}

$v = static fn (string $key): string => h((string) ($person[$key] ?? ''));

$pageTitle = 'Meine Daten';
require __DIR__ . '/includes/public_header.php';
?>
<div class="verify-box">
    <h1>Deine Daten</h1>
    <p>Bitte prüfe deine Daten und korrigiere sie bei Bedarf.</p>

    <?php if ($saved): ?><p class="alert alert-success">Danke! Deine Daten wurden gespeichert.</p><?php endif; ?>
    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="staff-formular.php?token=<?= h($token) ?>" class="member-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <?php foreach (STAFF_FORM_GROUPS as $group => $keys): ?>
            <fieldset>
                <legend><?= h($group) ?></legend>
                <div class="form-row" style="flex-wrap:wrap;">
                    <?php foreach ($keys as $key): ?>
                        <?php [$label, $type] = STAFF_IO_COLUMNS[$key]; $required = in_array($key, ['nachname', 'vorname'], true); ?>
                        <div class="form-group">
                            <label for="<?= h($key) ?>"><?= h($label) ?><?= $required ? ' *' : '' ?></label>
                            <?php if ($key === 'nada'): ?>
                            <?php $nadaYes = in_array(strtolower((string) ($person['nada'] ?? '')), ['1', 'ja', 'true', 'yes'], true); ?>
                            <select id="nada" name="nada"><option value="0" <?= $nadaYes ? '' : 'selected' ?>>Nein</option><option value="1" <?= $nadaYes ? 'selected' : '' ?>>Ja</option></select>
                        <?php elseif ($type === 'date'): ?>
                                <input type="date" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= $v($key) ?>">
                            <?php elseif ($key === 'essen'): ?>
                                <textarea id="essen" name="essen" rows="2" maxlength="255"><?= $v('essen') ?></textarea>
                            <?php else: ?>
                                <input type="<?= $key === 'email' ? 'email' : ($key === 'telefon' || $key === 'telefon_angehoeriger' ? 'tel' : 'text') ?>"
                                       id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= $v($key) ?>" maxlength="<?= (int) (MEMBER_IO_MAXLEN[$key] ?? 100) ?>" <?= $required ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>
<?php privacy_self_service_box('staff-formular.php?token=' . $token); ?>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
