<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$passwordError = null;
$passwordSuccess = null;
$newAdminError = null;
$newAdminSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    if ($_POST['action'] === 'change_password') {
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

    if ($_POST['action'] === 'add_admin') {
        $username = post_str('new_username');
        $password = (string) ($_POST['new_admin_password'] ?? '');

        if ($username === null || strlen($username) < 3) {
            $newAdminError = 'Benutzername muss mindestens 3 Zeichen haben.';
        } elseif (strlen($password) < 8) {
            $newAdminError = 'Passwort muss mindestens 8 Zeichen haben.';
        } else {
            try {
                $insert = db()->prepare('INSERT INTO admins (username, password_hash, must_change_password) VALUES (?, ?, 1)');
                $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $newAdminSuccess = "Admin-Konto „{$username}“ wurde angelegt.";
            } catch (PDOException $e) {
                $newAdminError = (int) $e->errorInfo[1] === 1062
                    ? 'Dieser Benutzername existiert bereits.'
                    : 'Fehler beim Anlegen des Kontos.';
            }
        }
    }
}

$mustChange = !empty($_SESSION['must_change_password']);
$pageTitle = 'Mein Konto';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Mein Konto</h1>

<?php if ($mustChange): ?>
    <p class="alert alert-error">Es ist noch das Standard-Passwort aktiv. Bitte jetzt ein eigenes Passwort setzen.</p>
<?php endif; ?>

<section class="panel">
    <h2>Passwort ändern</h2>
    <?php if ($passwordError): ?><p class="alert alert-error"><?= h($passwordError) ?></p><?php endif; ?>
    <?php if ($passwordSuccess): ?><p class="alert alert-success"><?= h($passwordSuccess) ?></p><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="field">
            <label for="current_password">Aktuelles Passwort</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="grid grid-2">
            <div class="field">
                <label for="new_password">Neues Passwort</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="new_password_repeat">Neues Passwort wiederholen</label>
                <input type="password" id="new_password_repeat" name="new_password_repeat" required minlength="8" autocomplete="new-password">
            </div>
        </div>
        <button type="submit">Passwort ändern</button>
    </form>
</section>

<section class="panel">
    <h2>Weiteren Admin anlegen</h2>
    <?php if ($newAdminError): ?><p class="alert alert-error"><?= h($newAdminError) ?></p><?php endif; ?>
    <?php if ($newAdminSuccess): ?><p class="alert alert-success"><?= h($newAdminSuccess) ?></p><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_admin">
        <div class="grid grid-2">
            <div class="field">
                <label for="new_username">Benutzername</label>
                <input type="text" id="new_username" name="new_username" required minlength="3" maxlength="60">
            </div>
            <div class="field">
                <label for="new_admin_password">Passwort</label>
                <input type="password" id="new_admin_password" name="new_admin_password" required minlength="8">
            </div>
        </div>
        <button type="submit" class="btn-secondary">Admin-Konto anlegen</button>
    </form>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
