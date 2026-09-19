<?php

declare(strict_types=1);

/**
 * Rollen und Berechtigungen für den Admin-Bereich (Dashboard).
 *
 * Aufbau:
 *   - Berechtigungen: feste Liste (siehe permissions_registry()), z. B. "members.delete".
 *   - Rollen: Sammlungen von Berechtigungen. Fest eingebaut: Administrator (darf immer alles),
 *     Bearbeiter und Nur Lesen (änderbar). Eigene Rollen lassen sich anlegen.
 *   - Pro Benutzer: eine Rolle plus einzelne Ausnahmen ("Erlauben" / "Verbieten"), die Vorrang
 *     vor der Rolle haben.
 *
 * Wirksame Rechte = (Rechte der Rolle + erlaubte Ausnahmen) - verbotene Ausnahmen.
 * Administratoren haben immer alle Rechte, Ausnahmen gelten für sie nicht.
 *
 * Durchgesetzt wird serverseitig in jeder Seite (require_permission) und bei den Schaltflächen (user_can).
 */

require_once __DIR__ . '/../db.php';

/**
 * Alle Berechtigungen: Schlüssel => [Beschriftung, Gruppe].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function permissions_registry(): array
{
    return [
        'members.view' => ['Mitgliederliste und Mitgliederdaten ansehen', 'Mitglieder'],
        'members.create' => ['Neue Mitglieder anlegen', 'Mitglieder'],
        'members.edit' => ['Mitglieder bearbeiten und speichern', 'Mitglieder'],
        'members.delete' => ['Mitglieder löschen (einzeln und Auswahl)', 'Mitglieder'],
        'members.delete_all' => ['ALLE Daten löschen', 'Mitglieder'],
        'members.links' => ['Persönliche Links & Zugangscodes verwalten, per E-Mail senden', 'Mitglieder'],
        'documents.view' => ['Hochgeladene Dokumente ansehen (E-Card, Pass, NADA, Rechte & Pflichten)', 'Mitglieder'],

        'members.import' => ['Import aus CSV/Excel', 'Daten'],
        'members.export' => ['Export als CSV', 'Daten'],

        'camps.manage' => ['Camps anlegen und umbenennen', 'Verwaltung'],
        'camps.delete' => ['Camps löschen', 'Verwaltung'],
        'fields.manage' => ['Feld-Rechte verwalten (welche Felder Spieler/Bearbeiter sehen)', 'Verwaltung'],

        'users.manage' => ['Benutzer und Rollen verwalten', 'System'],
        'api.manage' => ['API-Zugänge verwalten', 'System'],
        'logs.view' => ['Protokoll ansehen und exportieren', 'System'],
        'logs.purge' => ['Protokoll bereinigen/löschen', 'System'],
        'system.update' => ['Anwendung aktualisieren (Update per git pull)', 'System'],
    ];
}

/**
 * Standard-Berechtigungen der eingebauten Rollen (beim ersten Anlegen).
 *
 * @return array<int, string>
 */
function permissions_role_defaults(string $roleKey): array
{
    return match ($roleKey) {
        'administrator' => array_keys(permissions_registry()),
        'editor' => ['members.view', 'members.create', 'members.edit', 'members.delete', 'members.links',
            'documents.view', 'members.import', 'members.export', 'camps.manage'],
        'viewer' => ['members.view', 'documents.view', 'members.export'],
        default => [],
    };
}

/** Legt Tabellen an und spielt Standardrollen ein. Nie innerhalb einer Transaktion aufrufen (DDL). */
function permissions_ensure_tables(PDO $pdo): void
{
    $present = $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name IN ('roles', 'role_permissions', 'user_permissions')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $present = array_map('strtolower', $present);

    if (!in_array('roles', $present, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                role_key VARCHAR(30) DEFAULT NULL,
                name VARCHAR(60) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_key (role_key),
                UNIQUE KEY uniq_role_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    if (!in_array('role_permissions', $present, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission VARCHAR(60) NOT NULL,
                PRIMARY KEY (role_id, permission),
                CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    if (!in_array('user_permissions', $present, true)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_permissions (
                user_id INT UNSIGNED NOT NULL,
                permission VARCHAR(60) NOT NULL,
                allowed TINYINT(1) NOT NULL,
                PRIMARY KEY (user_id, permission),
                CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admins (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    $columns = $pdo->query('SHOW COLUMNS FROM admins')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('role_id', $columns, true)) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN role_id INT UNSIGNED DEFAULT NULL');
    }

    // Eingebaute Rollen anlegen (nur wenn noch nicht vorhanden)
    $builtIn = [
        'administrator' => ['Administrator', 'Voller Zugriff auf alles (nicht änderbar)'],
        'editor' => ['Bearbeiter', 'Mitgliederverwaltung ohne System- und Benutzerverwaltung'],
        'viewer' => ['Nur Lesen', 'Mitglieder ansehen und exportieren, nichts ändern'],
    ];
    $haveBuiltIn = (int) $pdo->query("SELECT COUNT(*) FROM roles WHERE role_key IN ('administrator', 'editor', 'viewer')")->fetchColumn();
    foreach ($haveBuiltIn >= 3 ? [] : $builtIn as $key => [$name, $description]) {
        $exists = $pdo->prepare('SELECT id FROM roles WHERE role_key = ?');
        $exists->execute([$key]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $pdo->prepare('INSERT IGNORE INTO roles (role_key, name, description, is_system) VALUES (?, ?, ?, 1)')->execute([$key, $name, $description]);
        $roleId = (int) $pdo->lastInsertId();
        if ($roleId > 0 && $key !== 'administrator') {
            $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?, ?)');
            foreach (permissions_role_defaults($key) as $permission) {
                $insert->execute([$roleId, $permission]);
            }
        }
    }

    // Bestehende Benutzer der bisherigen Rolle (administrator/editor) der neuen Rolle zuordnen
    if ((int) $pdo->query('SELECT COUNT(*) FROM admins WHERE role_id IS NULL')->fetchColumn() > 0) {
        $pdo->exec('UPDATE admins a JOIN roles r ON r.role_key = a.role SET a.role_id = r.id WHERE a.role_id IS NULL');
    }
}

/**
 * Wirksame Berechtigungen eines Benutzers: Berechtigung => true.
 *
 * @return array<string, true>
 */
function user_permissions(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $all = array_fill_keys(array_keys(permissions_registry()), true);

    try {
        $stmt = db()->prepare(
            'SELECT a.role AS legacy_role, a.role_id, r.role_key
             FROM admins a LEFT JOIN roles r ON r.id = a.role_id WHERE a.id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            return $cache[$userId] = [];
        }

// Administratoren dürfen IMMER alles - egal, welche Rolle zugeordnet ist oder welche Einzelrechte gesetzt sind        if ($user['role_key'] === 'administrator' || $user['legacy_role'] === 'administrator') {
            return $cache[$userId] = $all;
        }

        if ($user['role_id'] !== null) {
            $stmt = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
            $stmt->execute([(int) $user['role_id']]);
            $granted = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        } else {
            $granted = array_fill_keys(permissions_role_defaults('editor'), true);
        }

        $stmt = db()->prepare('SELECT permission, allowed FROM user_permissions WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['allowed'] === 1) {
                $granted[$row['permission']] = true;
            } else {
                unset($granted[$row['permission']]);
            }
        }

        return $cache[$userId] = array_intersect_key($granted, $all);
    } catch (Throwable $e) {
        // Tabellen nicht lesbar: sicherer Rückfall auf die bisherige Rolle
        error_log('user_permissions: ' . $e->getMessage());
        $role = (string) ($_SESSION['admin_role'] ?? 'editor');
        return $cache[$userId] = $role === 'administrator' ? $all : array_fill_keys(permissions_role_defaults('editor'), true);
    }
}

/** Darf der angemeldete Benutzer das? */
function user_can(string $permission): bool
{
    $id = $_SESSION['admin_id'] ?? null;
    return $id !== null && isset(user_permissions((int) $id)[$permission]);
}

/**
 * Bricht mit einer verständlichen 403-Seite ab, wenn dem Benutzer die Berechtigung fehlt.
 * Meldet vorher die Anmeldung und das Pflicht-Passwort-Ändern ab (require_admin()).
 */
function require_permission(string $permission): void
{
    require_admin();

    if (user_can($permission)) {
        return;
    }

    $label = permissions_registry()[$permission][0] ?? $permission;
    app_log('auth.forbidden', 'Zugriff verweigert: ' . $permission, ['permission' => $permission], 'warning');
    http_response_code(403);
    $pageTitle = 'Kein Zugriff';
    require __DIR__ . '/admin_header.php';
    echo '<div class="content-header"><h1>Kein Zugriff</h1></div>'
        . '<p class="alert alert-error">Für diese Aktion fehlt Ihnen die Berechtigung: <strong>' . h($label) . '</strong>.'
        . ' Bitte wenden Sie sich an einen Administrator.</p>'
        . '<a href="index.php" class="btn">Zurück zur Mitgliederliste</a>';
    require __DIR__ . '/admin_footer.php';
    exit;
}

/**
 * @return array<int, array{id: int, role_key: ?string, name: string, description: ?string, is_system: int, users: int}>
 */
function roles_all(): array
{
    return db()->query(
        'SELECT r.id, r.role_key, r.name, r.description, r.is_system,
                (SELECT COUNT(*) FROM admins a WHERE a.role_id = r.id) AS users
         FROM roles r ORDER BY r.is_system DESC, r.id'
    )->fetchAll();
}

/**
 * @return array<int, string> Berechtigungen einer Rolle
 */
function role_permissions(int $roleId): array
{
    $stmt = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$roleId]);
    return array_values(array_intersect($stmt->fetchAll(PDO::FETCH_COLUMN), array_keys(permissions_registry())));
}

/**
 * Ausnahmen eines Benutzers: Berechtigung => true (erlaubt) / false (verboten).
 *
 * @return array<string, bool>
 */
function user_permission_overrides(int $userId): array
{
    $stmt = db()->prepare('SELECT permission, allowed FROM user_permissions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $overrides = [];
    foreach ($stmt->fetchAll() as $row) {
        $overrides[$row['permission']] = (int) $row['allowed'] === 1;
    }
    return $overrides;
}

/**
 * Speichert die Ausnahmen eines Benutzers (Standard = keine Ausnahme).
 *
 * @param array<string, string> $choices Berechtigung => 'default' | 'allow' | 'deny'
 */
function user_permission_overrides_save(int $userId, array $choices): void
{
    db()->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
    $insert = db()->prepare('INSERT INTO user_permissions (user_id, permission, allowed) VALUES (?, ?, ?)');
    foreach (permissions_registry() as $permission => $_) {
        $choice = $choices[$permission] ?? 'default';
        if ($choice === 'allow' || $choice === 'deny') {
            $insert->execute([$userId, $permission, $choice === 'allow' ? 1 : 0]);
        }
    }
}
