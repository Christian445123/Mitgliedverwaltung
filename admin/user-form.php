<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_permission('users.manage');

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
$registry = permissions_registry();
$roles = roles_all();

// Nur Administratoren dürfen Administratoren bearbeiten, die Administrator-Rolle vergeben und eigene Rechte ändern.
// So kann sich niemand über "Benutzer verwalten" mehr Rechte verschaffen, als er selbst hat.
if (!is_administrator()) {
    if (!$isNew && ($existing['role'] === 'administrator' || (int) $id === current_admin_id())) {
        flash_set('error', 'Administratoren und das eigene Konto können nur von einem Administrator geändert werden.');
        redirect('users.php');
    }
    $roles = array_values(array_filter($roles, static fn (array $r) => $r['role_key'] !== 'administrator'));
}
$rolePermissions = [];
foreach ($roles as $r) {
    $rolePermissions[(int) $r['id']] = $r['role_key'] === 'administrator' ? array_keys($registry) : role_permissions((int) $r['id']);
}

// Vorbelegung: bestehende Rolle bzw. "Bearbeiter" für neue Benutzer
$defaultRoleId = 0;
foreach ($roles as $r) {
    if ($r['role_key'] === 'editor') {
        $defaultRoleId = (int) $r['id'];
    }
}
$username = $existing['username'] ?? '';
$roleId = (int) ($existing['role_id'] ?? $defaultRoleId);
$choices = $isNew ? [] : array_map(static fn (bool $allowed) => $allowed ? 'allow' : 'deny', user_permission_overrides((int) $id));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = post_str('username') ?? '';
    $password = (string) ($_POST['password'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $choices = [];
    foreach (array_keys($registry) as $permission) {
        $choice = (string) ($_POST['perm'][$permission] ?? 'default');
        $choices[$permission] = in_array($choice, ['allow', 'deny'], true) ? $choice : 'default';
    }

    $roleRow = null;
    foreach ($roles as $r) {
        if ((int) $r['id'] === $roleId) {
            $roleRow = $r;
        }
    }
    $isAdminRole = $roleRow !== null && $roleRow['role_key'] === 'administrator';
    $legacyRole = $isAdminRole ? 'administrator' : 'editor';

    $demotesLastAdministrator = false;
    if (!$isNew && $legacyRole !== 'administrator' && $existing['role'] === 'administrator') {
        $countStmt = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'administrator' AND id != ?");
        $countStmt->execute([$id]);
        $demotesLastAdministrator = (int) $countStmt->fetchColumn() === 0;
    }

    if (strlen($username) < 3) {
        $error = 'Benutzername muss mindestens 3 Zeichen haben.';
    } elseif ($roleRow === null) {
        $error = 'Bitte eine Rolle auswählen.';
    } elseif (!is_administrator() && count(array_filter(array_keys(array_filter($choices, static fn (string $c) => $c === 'allow')), static fn (string $p) => !user_can($p))) > 0) {
        $error = 'Sie können nur Rechte erlauben, die Sie selbst haben.';
    } elseif (!is_administrator() && count(array_diff(role_permissions((int) $roleRow['id']), array_keys(user_permissions((int) current_admin_id())))) > 0) {
        $error = 'Diese Rolle enthält Rechte, die Sie selbst nicht haben. Sie können sie nicht vergeben.';
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
                    'INSERT INTO admins (username, password_hash, role, role_id, must_change_password) VALUES (?, ?, ?, ?, 1)'
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $legacyRole, $roleId]);
                $savedId = (int) db()->lastInsertId();
                flash_set('info', "Benutzer „{$username}“ wurde angelegt.");
                app_log('user.create', 'Benutzer angelegt', ['target_type' => 'admin', 'target_id' => $savedId, 'username' => $username, 'role' => $roleRow['name']]);
            } else {
                $savedId = (int) $id;
                if ($password !== '') {
                    $stmt = db()->prepare('UPDATE admins SET username = ?, password_hash = ?, role = ?, role_id = ?, must_change_password = 1 WHERE id = ?');
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $legacyRole, $roleId, $id]);
                } else {
                    $stmt = db()->prepare('UPDATE admins SET username = ?, role = ?, role_id = ? WHERE id = ?');
                    $stmt->execute([$username, $legacyRole, $roleId, $id]);
                }
                flash_set('info', 'Benutzer wurde aktualisiert.');
                app_log('user.update', 'Benutzer geändert', ['target_type' => 'admin', 'target_id' => $id, 'username' => $username, 'role' => $roleRow['name'], 'password_changed' => $password !== '']);
            }

            // Einzelrechte (Ausnahmen) speichern - für Administratoren gibt es keine Ausnahmen
            $overrides = $isAdminRole ? [] : $choices;
            user_permission_overrides_save($savedId, $overrides);
            $granted = array_keys(array_filter($overrides, static fn (string $c) => $c === 'allow'));
            $denied = array_keys(array_filter($overrides, static fn (string $c) => $c === 'deny'));
            if ($granted !== [] || $denied !== []) {
                app_log('user.permissions', 'Einzelrechte gesetzt', ['target_type' => 'admin', 'target_id' => $savedId, 'erlaubt' => $granted, 'verboten' => $denied]);
            }

            redirect('users.php');
        } catch (PDOException $e) {
            $error = (int) $e->errorInfo[1] === 1062
                ? 'Dieser Benutzername existiert bereits.'
                : 'Fehler beim Speichern.';
        }
    }
}

$groups = [];
foreach ($registry as $permission => [$label, $group]) {
    $groups[$group][$permission] = $label;
}

$pageTitle = $isNew ? 'Neuer Benutzer' : 'Benutzer bearbeiten';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1><?= $isNew ? 'Neuer Benutzer' : 'Benutzer bearbeiten' ?></h1>
    <a href="users.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="user-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" id="user-form">
    <?= csrf_field() ?>

    <div class="panel" style="max-width:520px;">
        <div class="form-group">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" value="<?= h($username) ?>" required minlength="3" maxlength="60">
        </div>

        <div class="form-group">
            <label for="password"><?= $isNew ? 'Passwort' : 'Neues Passwort (leer lassen = unverändert)' ?></label>
            <input type="password" id="password" name="password" minlength="8" <?= $isNew ? 'required' : '' ?> autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="role_id">Rolle</label>
            <select id="role_id" name="role_id" data-role-select>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int) $r['id'] ?>"
                            data-admin="<?= $r['role_key'] === 'administrator' ? '1' : '0' ?>"
                            data-perms="<?= h((string) json_encode($rolePermissions[(int) $r['id']])) ?>"
                            <?= (int) $r['id'] === $roleId ? 'selected' : '' ?>>
                        <?= h((string) $r['name']) ?><?= $r['description'] ? ' – ' . h((string) $r['description']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="muted">Die Rollen und ihre Rechte verwalten Sie unter <a href="roles.php">Rollen</a>.</p>
        </div>
    </div>

    <h2 class="section-title" style="margin-top:24px;">Was darf dieser Benutzer?</h2>
    <p class="muted">Die Rolle legt die Grundrechte fest. Hier können Sie für diesen Benutzer einzelne Rechte
        <strong>zusätzlich erlauben</strong> oder <strong>verbieten</strong>. Verbote haben Vorrang vor der Rolle.
        Administratoren dürfen immer alles.</p>

    <p class="alert alert-info" data-admin-note hidden>Die Rolle „Administrator“ darf immer alles, Einzelrechte gelten dafür nicht.</p>

    <?php foreach ($groups as $groupName => $items): ?>
        <h3 class="perm-group"><?= h($groupName) ?></h3>
        <div class="table-scroll">
        <table class="table table-cards perm-table">
            <thead>
                <tr><th>Recht</th><th>Durch Rolle</th><th>Für diesen Benutzer</th><th>Ergebnis</th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $permission => $label): ?>
                <tr data-permission="<?= h($permission) ?>">
                    <td data-label="Recht"><?= h($label) ?></td>
                    <td data-label="Durch Rolle" data-role-cell></td>
                    <td data-label="Für diesen Benutzer">
                        <select name="perm[<?= h($permission) ?>]" data-perm-select aria-label="<?= h($label) ?>">
                            <option value="default" <?= ($choices[$permission] ?? 'default') === 'default' ? 'selected' : '' ?>>Wie Rolle</option>
                            <option value="allow" <?= ($choices[$permission] ?? '') === 'allow' ? 'selected' : '' ?>>Zusätzlich erlauben</option>
                            <option value="deny" <?= ($choices[$permission] ?? '') === 'deny' ? 'selected' : '' ?>>Verbieten</option>
                        </select>
                    </td>
                    <td data-label="Ergebnis" data-result-cell></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>

    <div class="filter-bar" style="margin-top:20px;">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
