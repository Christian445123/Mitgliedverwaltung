<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_administrator();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = null;

if ($id !== null) {
    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();

    if ($existing === false) {
        flash_set('error', 'Benutzer nicht gefunden.');
        redirect('users.php');
    }
}

$isNew = $id === null;
$error = null;
$username = $existing['username'] ?? '';
$role = $existing['role'] ?? 'editor';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = post_str('username') ?? '';
    $password = (string) ($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['administrator', 'editor'], true) ? $_POST['role'] : 'editor';

    $demotesLastAdministrator = false;
    if (!$isNew && $role !== 'administrator' && $existing['role'] === 'administrator') {
        $countStmt = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'administrator' AND id != ?");
        $countStmt->execute([$id]);
        $demotesLastAdministrator = (int) $countStmt->fetchColumn() === 0;
    }

    if (strlen($username) < 3) {
        $error = 'Benutzername muss mindestens 3 Zeichen haben.';
    } elseif ($isNew && strlen($password) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Neues Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($demotesLastAdministrator) {
        $error = 'Mindestens ein Administrator muss bestehen bleiben.';
    } else {
        try {
            if ($isNew) {
                $stmt = db()->prepare(
                    'INSERT INTO admins (username, password_hash, role, must_change_password) VALUES (?, ?, ?, 1)'
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash_set('info', "Benutzer „{$username}“ wurde angelegt.");
            } else {
                if ($password !== '') {
                    $stmt = db()->prepare('UPDATE admins SET username = ?, password_hash = ?, role = ?, must_change_password = 1 WHERE id = ?');
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
                } else {
                    $stmt = db()->prepare('UPDATE admins SET username = ?, role = ? WHERE id = ?');
                    $stmt->execute([$username, $role, $id]);
                }
                flash_set('info', 'Benutzer wurde aktualisiert.');
            }

            redirect('users.php');
        } catch (PDOException $e) {
            $error = (int) $e->errorInfo[1] === 1062
                ? 'Dieser Benutzername existiert bereits.'
                : 'Fehler beim Speichern.';
        }
    }
}

$pageTitle = $isNew ? 'Neuer Benutzer' : 'Benutzer bearbeiten';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1><?= $isNew ? 'Neuer Benutzer' : 'Benutzer bearbeiten' ?></h1>
    <a href="users.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="user-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" style="max-width:420px;">
    <?= csrf_field() ?>

    <div class="form-group">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" value="<?= h($username) ?>" required minlength="3" maxlength="60">
    </div>

    <div class="form-group">
        <label for="password"><?= $isNew ? 'Passwort' : 'Neues Passwort (leer lassen = unverändert)' ?></label>
        <input type="password" id="password" name="password" minlength="8" <?= $isNew ? 'required' : '' ?>>
    </div>

    <div class="form-group">
        <label for="role">Rolle</label>
        <select id="role" name="role">
            <option value="administrator" <?= $role === 'administrator' ? 'selected' : '' ?>>Administrator (voller Zugriff inkl. Benutzerverwaltung &amp; Update)</option>
            <option value="editor" <?= $role === 'editor' ? 'selected' : '' ?>>Bearbeiter (nur Mitgliederverwaltung)</option>
        </select>
    </div>

    <button type="submit" class="btn btn-primary"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
