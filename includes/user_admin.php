<?php

declare(strict_types=1);

/**
 * Benutzer-, Rollen- und Rechteverwaltung für die API (Desktop-App).
 *
 * Es gelten dieselben Regeln wie in den Web-Seiten users.php, user-form.php, user-delete.php und roles.php:
 *  - Nur Administratoren dürfen Administratoren und das eigene Konto ändern, die Administrator-Rolle vergeben
 *    und Rechte vergeben, die sie selbst nicht haben.
 *  - Mindestens ein Administrator muss bestehen bleiben, man löscht sich nicht selbst.
 *  - Eingebaute Rollen (und die Administrator-Rolle) sind geschützt, benutzte Rollen lassen sich nicht löschen.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/app_sessions.php';

function ua_is_admin(int $actorId): bool
{
    $stmt = db()->prepare('SELECT role FROM admins WHERE id = ?');
    $stmt->execute([$actorId]);
    return $stmt->fetchColumn() === 'administrator';
}

/** @return array<int, array{key: string, label: string, group: string}> */
function ua_permission_list(): array
{
    $out = [];
    foreach (permissions_registry() as $key => [$label, $group]) {
        $out[] = ['key' => $key, 'label' => $label, 'group' => $group];
    }
    return $out;
}

/** @return array<int, array<string, mixed>> */
function ua_roles(): array
{
    $registry = array_keys(permissions_registry());
    $out = [];
    foreach (roles_all() as $r) {
        $out[] = [
            'id' => (int) $r['id'],
            'key' => $r['role_key'],
            'name' => (string) $r['name'],
            'description' => (string) ($r['description'] ?? ''),
            'is_system' => (int) $r['is_system'] === 1,
            'users' => (int) $r['users'],
            'permissions' => $r['role_key'] === 'administrator' ? $registry : role_permissions((int) $r['id']),
        ];
    }
    return $out;
}

/**
 * @param array<int, string> $permissions
 * @throws RuntimeException
 */
function ua_role_save(int $actorId, int $roleId, string $name, string $description, array $permissions): int
{
    $registry = permissions_registry();
    $selected = array_values(array_intersect(array_keys($registry), array_map('strval', $permissions)));
    $name = trim($name);
    $description = trim($description);

    if (!ua_is_admin($actorId)) {
        $own = array_keys(user_permissions($actorId));
        $before = $roleId > 0 ? role_permissions($roleId) : [];
        if (count(array_diff(array_diff($selected, $before), $own)) > 0) {
            throw new RuntimeException('Sie können einer Rolle nur Rechte hinzufügen, die Sie selbst haben.');
        }
    }
    if ($name === '' || mb_strlen($name) > 60) {
        throw new RuntimeException('Bitte einen Namen für die Rolle angeben (max. 60 Zeichen).');
    }

    try {
        if ($roleId === 0) {
            db()->prepare('INSERT INTO roles (name, description, is_system) VALUES (?, ?, 0)')->execute([$name, $description !== '' ? $description : null]);
            $roleId = (int) db()->lastInsertId();
            app_log('role.create', 'Rolle angelegt', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $name, 'rechte' => $selected]);
        } else {
            $stmt = db()->prepare('SELECT role_key FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $current = $stmt->fetch();
            if ($current === false) {
                throw new RuntimeException('Rolle nicht gefunden.');
            }
            if ($current['role_key'] === 'administrator') {
                throw new RuntimeException('Die Rolle „Administrator“ ist fest und nicht änderbar.');
            }
            db()->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?')->execute([$name, $description !== '' ? $description : null, $roleId]);
            app_log('role.update', 'Rolle geändert', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $name, 'rechte' => $selected]);
        }
    } catch (PDOException $e) {
        throw new RuntimeException((int) ($e->errorInfo[1] ?? 0) === 1062 ? 'Eine Rolle mit diesem Namen gibt es schon.' : 'Fehler beim Speichern.');
    }

    db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    $insert = db()->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
    foreach ($selected as $permission) {
        $insert->execute([$roleId, $permission]);
    }
    return $roleId;
}

/** @throws RuntimeException */
function ua_role_delete(int $roleId): void
{
    $current = null;
    foreach (roles_all() as $r) {
        if ((int) $r['id'] === $roleId) {
            $current = $r;
        }
    }
    if ($current === null) {
        throw new RuntimeException('Rolle nicht gefunden.');
    }
    if ((int) $current['is_system'] === 1) {
        throw new RuntimeException('Eingebaute Rollen können nicht gelöscht werden.');
    }
    if ((int) $current['users'] > 0) {
        throw new RuntimeException('Die Rolle wird noch von ' . (int) $current['users'] . ' Benutzer(n) verwendet. Bitte diesen Benutzern zuerst eine andere Rolle zuweisen.');
    }
    db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
    app_log('role.delete', 'Rolle gelöscht', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $current['name']], 'warning');
}

/** @return array<int, array<string, mixed>> */
function ua_users(): array
{
    $rows = db()->query(
        'SELECT a.id, a.username, a.role, a.role_id, a.must_change_password, a.created_at, r.name AS role_name,
                (SELECT COUNT(*) FROM user_permissions up WHERE up.user_id = a.id) AS overrides
         FROM admins a LEFT JOIN roles r ON r.id = a.role_id ORDER BY a.username'
    )->fetchAll();
    $out = [];
    foreach ($rows as $a) {
        $out[] = [
            'id' => (int) $a['id'],
            'username' => (string) $a['username'],
            'is_admin' => $a['role'] === 'administrator',
            'role_id' => $a['role_id'] !== null ? (int) $a['role_id'] : null,
            'role_name' => (string) ($a['role_name'] ?? ($a['role'] === 'administrator' ? 'Administrator' : 'Bearbeiter')),
            'overrides' => (int) $a['overrides'],
            'must_change_password' => (int) $a['must_change_password'] === 1,
            'created_at' => (string) $a['created_at'],
        ];
    }
    return $out;
}

/**
 * Einzelner Benutzer mit seinen Einzelrechten ('allow' | 'deny').
 *
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function ua_user_get(int $actorId, int $id): array
{
    $stmt = db()->prepare('SELECT id, username, role, role_id FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if ($user === false) {
        throw new RuntimeException('Benutzer nicht gefunden.');
    }
    if (!ua_is_admin($actorId) && ($user['role'] === 'administrator' || $id === $actorId)) {
        throw new RuntimeException('Administratoren und das eigene Konto können nur von einem Administrator geändert werden.');
    }
    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'is_admin' => $user['role'] === 'administrator',
        'role_id' => $user['role_id'] !== null ? (int) $user['role_id'] : null,
        'overrides' => array_map(static fn (bool $allowed): string => $allowed ? 'allow' : 'deny', user_permission_overrides($id)),
    ];
}

/**
 * Legt einen Benutzer an ($id = 0) oder ändert ihn.
 *
 * @param array<string, string> $choices Berechtigung => 'allow' | 'deny' | 'default'
 * @throws RuntimeException
 */
function ua_user_save(int $actorId, int $id, string $username, string $password, int $roleId, array $choices): int
{
    $isNew = $id === 0;
    $actorIsAdmin = ua_is_admin($actorId);
    $registry = permissions_registry();
    $username = trim($username);

    $existing = null;
    if (!$isNew) {
        $stmt = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if ($existing === false) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }
        if (!$actorIsAdmin && ($existing['role'] === 'administrator' || $id === $actorId)) {
            throw new RuntimeException('Administratoren und das eigene Konto können nur von einem Administrator geändert werden.');
        }
    }

    $roleRow = null;
    foreach (roles_all() as $r) {
        if ((int) $r['id'] === $roleId && ($actorIsAdmin || $r['role_key'] !== 'administrator')) {
            $roleRow = $r;
        }
    }
    $isAdminRole = $roleRow !== null && $roleRow['role_key'] === 'administrator';
    $legacyRole = $isAdminRole ? 'administrator' : 'editor';

    $clean = [];
    foreach (array_keys($registry) as $permission) {
        $choice = (string) ($choices[$permission] ?? 'default');
        $clean[$permission] = in_array($choice, ['allow', 'deny'], true) ? $choice : 'default';
    }

    $demotesLastAdministrator = false;
    if (!$isNew && $legacyRole !== 'administrator' && $existing['role'] === 'administrator') {
        $count = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'administrator' AND id != ?");
        $count->execute([$id]);
        $demotesLastAdministrator = (int) $count->fetchColumn() === 0;
    }

    if (strlen($username) < 3) {
        throw new RuntimeException('Benutzername muss mindestens 3 Zeichen haben.');
    }
    if ($roleRow === null) {
        throw new RuntimeException('Bitte eine Rolle auswählen.');
    }
    if (!$actorIsAdmin) {
        $own = user_permissions($actorId);
        foreach ($clean as $permission => $choice) {
            if ($choice === 'allow' && !isset($own[$permission])) {
                throw new RuntimeException('Sie können nur Rechte erlauben, die Sie selbst haben.');
            }
        }
        if (count(array_diff(role_permissions((int) $roleRow['id']), array_keys($own))) > 0) {
            throw new RuntimeException('Diese Rolle enthält Rechte, die Sie selbst nicht haben. Sie können sie nicht vergeben.');
        }
    }
    if ($isNew && strlen($password) < 8) {
        throw new RuntimeException('Passwort muss mindestens 8 Zeichen haben.');
    }
    if ($password !== '' && strlen($password) < 8) {
        throw new RuntimeException('Neues Passwort muss mindestens 8 Zeichen haben.');
    }
    if ($demotesLastAdministrator) {
        throw new RuntimeException('Mindestens ein Administrator muss bestehen bleiben.');
    }

    try {
        if ($isNew) {
            $stmt = db()->prepare('INSERT INTO admins (username, password_hash, role, role_id, must_change_password) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $legacyRole, $roleId]);
            $savedId = (int) db()->lastInsertId();
            app_log('user.create', 'Benutzer angelegt', ['target_type' => 'admin', 'target_id' => $savedId, 'username' => $username, 'role' => $roleRow['name']]);
        } else {
            $savedId = $id;
            if ($password !== '') {
                $stmt = db()->prepare('UPDATE admins SET username = ?, password_hash = ?, role = ?, role_id = ?, must_change_password = 1 WHERE id = ?');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $legacyRole, $roleId, $id]);
                app_sessions_revoke_all($id);
            } else {
                $stmt = db()->prepare('UPDATE admins SET username = ?, role = ?, role_id = ? WHERE id = ?');
                $stmt->execute([$username, $legacyRole, $roleId, $id]);
            }
            app_log('user.update', 'Benutzer geändert', ['target_type' => 'admin', 'target_id' => $id, 'username' => $username, 'role' => $roleRow['name'], 'password_changed' => $password !== '']);
        }
    } catch (PDOException $e) {
        throw new RuntimeException((int) ($e->errorInfo[1] ?? 0) === 1062 ? 'Dieser Benutzername existiert bereits.' : 'Fehler beim Speichern.');
    }

    // Einzelrechte (Ausnahmen); für Administratoren gibt es keine
    $overrides = $isAdminRole ? [] : $clean;
    user_permission_overrides_save($savedId, $overrides);
    $granted = array_keys(array_filter($overrides, static fn (string $c) => $c === 'allow'));
    $denied = array_keys(array_filter($overrides, static fn (string $c) => $c === 'deny'));
    if ($granted !== [] || $denied !== []) {
        app_log('user.permissions', 'Einzelrechte gesetzt', ['target_type' => 'admin', 'target_id' => $savedId, 'erlaubt' => $granted, 'verboten' => $denied]);
    }
    return $savedId;
}

/** @throws RuntimeException */
function ua_user_delete(int $actorId, int $id): void
{
    if ($id === $actorId) {
        throw new RuntimeException('Du kannst dich nicht selbst löschen.');
    }
    $stmt = db()->prepare('SELECT role, username FROM admins WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target === false) {
        throw new RuntimeException('Benutzer nicht gefunden.');
    }
    if ($target['role'] === 'administrator' && !ua_is_admin($actorId)) {
        throw new RuntimeException('Administratoren können nur von einem Administrator gelöscht werden.');
    }
    if ($target['role'] === 'administrator') {
        $count = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'administrator' AND id != ?");
        $count->execute([$id]);
        if ((int) $count->fetchColumn() === 0) {
            throw new RuntimeException('Mindestens ein Administrator muss bestehen bleiben.');
        }
    }
    db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
    app_log('user.delete', 'Benutzer gelöscht', ['target_type' => 'admin', 'target_id' => $id, 'username' => $target['username'] ?? null], 'warning');
}
