<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';

const DEFAULT_ADMIN_USERNAME = 'admin';
const DEFAULT_ADMIN_PASSWORD = 'AFBOE-U19-2026!';

/**
 * Legt beim allerersten Aufruf (noch keine Admins in der DB) automatisch
 * ein Standard-Admin-Konto an, damit man ohne separaten Einrichtungsschritt
 * ins Dashboard kommt. Muss beim ersten Login das Passwort ändern.
 */
function ensure_default_admin(): bool
{
    $count = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count > 0) {
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO admins (username, password_hash, must_change_password) VALUES (?, ?, 1)'
    );
    $stmt->execute([DEFAULT_ADMIN_USERNAME, password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT)]);

    return true;
}

function admin_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, username, password_hash, must_change_password FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin === false || !password_verify($password, $admin['password_hash'])) {
        usleep(300000);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['must_change_password'] = (bool) $admin['must_change_password'];

    return true;
}

function admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        redirect(APP_BASE_URL . '/admin/login.php');
    }

    if (!empty($_SESSION['must_change_password']) && basename((string) $_SERVER['SCRIPT_NAME']) !== 'account.php') {
        flash_set('info', 'Bitte ändere zuerst dein Passwort (Standard-Passwort ist noch aktiv).');
        redirect(APP_BASE_URL . '/admin/account.php');
    }
}

function current_admin_username(): ?string
{
    return $_SESSION['admin_username'] ?? null;
}

function current_admin_id(): ?int
{
    return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
}
