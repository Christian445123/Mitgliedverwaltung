<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$passwordError = null;
$passwordSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verify_csrf();

    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $repeat = (string) ($_POST['new_password_repeat'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([current_admin_id()]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($current, $row['password_hash'])) {
        $passwordError = 'Aktuelles Passwort ist falsch.';
    } elseif (strlen($new) < 8) {
        $passwordError = 'Neues Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($new !== $repeat) {
        $passwordError = 'Die Passwörter stimmen nicht überein.';
    } else {
        $update = db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        $update->execute([password_hash($new, PASSWORD_DEFAULT), current_admin_id()]);
        $_SESSION['must_change_password'] = false;
        $passwordSuccess = 'Passwort wurde geändert.';
    }
}

$mustChange = !empty($_SESSION['must_change_password']);
$redirectInfo = flash_get('info');
$pageTitle = 'Mein Konto';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Mein Konto</h1>
</div>

<p class="muted" style="margin-top:-8px;color:var(--color-muted);">
    Angemeldet als <strong><?= h(current_admin_username() ?? '') ?></strong>
    &middot; Rolle: <span class="badge <?= is_administrator() ? 'badge-green' : 'badge-gray' ?>"><?= is_administrator() ? 'Administrator' : 'Bearbeiter' ?></span>
</p>

<?php if ($mustChange): ?>
    <p class="alert alert-error">Es ist noch das Standard-Passwort aktiv. Bitte jetzt ein eigenes Passwort setzen.</p>
<?php elseif ($redirectInfo): ?>
    <p class="alert alert-success"><?= h($redirectInfo) ?></p>
<?php endif; ?>

<fieldset>
    <legend>Passwort ändern</legend>
    <?php if ($passwordError): ?><p class="alert alert-error"><?= h($passwordError) ?></p><?php endif; ?>
    <?php if ($passwordSuccess): ?><p class="alert alert-success"><?= h($passwordSuccess) ?></p><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
            <label for="current_password">Aktuelles Passwort</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="new_password">Neues Passwort</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="new_password_repeat">Neues Passwort wiederholen</label>
                <input type="password" id="new_password_repeat" name="new_password_repeat" required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Passwort ändern</button>
    </form>
</fieldset>

<?php if (is_administrator()): ?>
<p class="muted" style="color:var(--color-muted);">Weitere Benutzer verwaltest du unter <a href="users.php">Benutzer</a>.</p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
