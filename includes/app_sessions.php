<?php

declare(strict_types=1);

/**
 * Anmeldung der Desktop-Anwendung (C#) mit den Benutzerdaten des Web-Panels.
 *
 * - POST /api/auth/login prüft Benutzername und Passwort wie der Web-Login (Tabelle admins) und liefert einen
 *   Sitzungs-Token. Die Anwendung schickt ihn bei jeder Anfrage im Header "X-User-Token" mit.
 * - Der Server prüft dann bei jeder Anfrage die Rechte dieses Benutzers (Rollen und Einzelrechte wie im Web-Panel).
 * - Sitzungen laufen bei Nichtbenutzung ab (12 Stunden, mit "Angemeldet bleiben" 30 Tage) und werden beim
 *   Ändern des Passworts oder Löschen des Benutzers beendet.
 * - Die Tokens werden nur als SHA-256 gespeichert.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/permissions.php';

const APP_SESSION_TTL_SHORT = 12 * 3600;
const APP_SESSION_TTL_LONG = 30 * 86400;

function app_sessions_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS app_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            ttl_seconds INT UNSIGNED NOT NULL,
            machine_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY uniq_app_session_token (token_hash),
            KEY idx_app_session_admin (admin_id),
            CONSTRAINT fk_app_sessions_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * Prüft Benutzername und Passwort wie der Web-Login.
 *
 * @return array{ok: bool, admin: ?array<string, mixed>, reason: string}
 */
function app_user_authenticate(string $username, string $password): array
{
    $stmt = db()->prepare('SELECT id, username, password_hash, role, must_change_password FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin === false || !password_verify($password, (string) $admin['password_hash'])) {
        app_log('auth.login_failed', 'App-Anmeldung fehlgeschlagen', ['actor' => $username, 'username' => $username, 'reason' => $admin === false ? 'unbekannter Benutzer' : 'falsches Passwort', 'via' => 'app'], 'warning');
        usleep(400000);
        return ['ok' => false, 'admin' => null, 'reason' => 'invalid'];
    }
    if ((int) $admin['must_change_password'] === 1) {
        return ['ok' => false, 'admin' => $admin, 'reason' => 'must_change_password'];
    }
    return ['ok' => true, 'admin' => $admin, 'reason' => 'ok'];
}

/**
 * Legt eine Sitzung an und liefert den Klartext-Token (nur jetzt sichtbar).
 *
 * @return array{token: string, expires_at: string}
 */
function app_session_create(int $adminId, string $machineName, bool $remember): array
{
    app_sessions_ensure_table();
    $token = bin2hex(random_bytes(32));
    $ttl = $remember ? APP_SESSION_TTL_LONG : APP_SESSION_TTL_SHORT;
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $stmt = db()->prepare('INSERT INTO app_sessions (admin_id, token_hash, ttl_seconds, machine_name, expires_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$adminId, hash('sha256', $token), $ttl, mb_substr(trim($machineName), 0, 150), $expires]);

    // abgelaufene Sitzungen aufräumen
    db()->exec('DELETE FROM app_sessions WHERE expires_at < NOW()');

    return ['token' => $token, 'expires_at' => $expires];
}

/**
 * Benutzer zu einem Sitzungs-Token oder null (unbekannt/abgelaufen). Verlängert die Sitzung bei Benutzung.
 *
 * @return array{id: int, username: string, role: string, session_id: int}|null
 */
function app_session_verify(string $token): ?array
{
    app_sessions_ensure_table();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT s.id AS session_id, s.ttl_seconds, s.last_used_at, a.id, a.username, a.role
         FROM app_sessions s JOIN admins a ON a.id = s.admin_id
         WHERE s.token_hash = ? AND s.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    // höchstens einmal pro Minute verlängern
    if (time() - (int) strtotime((string) $row['last_used_at']) > 60) {
        $upd = db()->prepare('UPDATE app_sessions SET last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?');
        $upd->execute([(int) $row['ttl_seconds'], (int) $row['session_id']]);
    }

    return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'role' => (string) $row['role'], 'session_id' => (int) $row['session_id']];
}

function app_session_delete(string $token): void
{
    app_sessions_ensure_table();
    $stmt = db()->prepare('DELETE FROM app_sessions WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
}

/** Beendet alle App-Sitzungen eines Benutzers (z. B. nach Passwortänderung). */
function app_sessions_revoke_all(int $adminId): void
{
    try {
        app_sessions_ensure_table();
        $stmt = db()->prepare('DELETE FROM app_sessions WHERE admin_id = ?');
        $stmt->execute([$adminId]);
    } catch (Throwable $e) {
        error_log('app_sessions_revoke_all: ' . $e->getMessage());
    }
}

/**
 * Welche Berechtigung braucht die Anfrage? null = keine besondere (z. B. ping, license/validate, auth/*).
 * Entspricht den Rechten im Web-Panel (siehe permissions_registry()).
 */
function api_required_permission(string $method, string $path): ?string
{
    if (str_starts_with($path, 'admin/') || str_starts_with($path, 'manage/')) {
        return null; // wird im Endpunkt selbst geprüft (users.manage, fields.manage, camps.manage, logs.view ...)
    }
    if ($path === 'camps') {
        return 'members.view';
    }
    if ($path === 'staff.csv') {
        return null; // wird im Endpunkt geprüft (staff.view + members.export)
    }
    if (preg_match('#^members/\d+/link$#', $path) === 1) {
        return 'members.links';
    }
    if ($path === '' || $path === 'ping' || $path === 'license/validate' || str_starts_with($path, 'auth/')) {
        return null;
    }
    if ($path === 'members.csv' || $path === 'template.csv' || preg_match('#^roster(-ifaf|-bekleidung|-vereine)?\.(pdf|xlsx)$#', $path) === 1) {
        return 'members.export';
    }
    if ($path === 'import') {
        return 'members.import';
    }
    if ($path === 'update') {
        return 'system.update';
    }
    if ($path === 'members/bulk-delete') {
        return 'members.delete'; // "alle löschen" wird im Endpunkt zusätzlich mit members.delete_all geprüft
    }
    if (preg_match('#^members/\d+/document-flags$#', $path) === 1) {
        return 'members.edit';
    }
    if (preg_match('#^members/\d+/documents/[a-z_]+$#', $path) === 1) {
        return $method === 'GET' ? 'documents.view' : 'members.edit';
    }
    if (preg_match('#^staff/\d+/documents/[a-z_]+$#', $path) === 1) {
        return $method === 'GET' ? 'documents.view' : 'staff.edit';
    }
    if (preg_match('#^staff(/\d+)?$#', $path) === 1) {
        return match ($method) {
            'GET' => 'staff.view',
            'DELETE' => 'staff.delete',
            default => 'staff.edit',
        };
    }
    if (preg_match('#^members(/\d+)?$#', $path) === 1) {
        return match ($method) {
            'GET' => 'members.view',
            'POST' => 'members.create',
            'DELETE' => 'members.delete',
            default => 'members.edit',
        };
    }
    return null;
}
