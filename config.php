<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/crypto.php";

function env_load(string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException(".env Datei nicht gefunden oder nicht lesbar: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $keyLine) { // Pfad der Schlüsseldatei zuerst setzen, damit verschlüsselte Werte entschlüsselt werden können
        if (preg_match('/^\s*APP_KEY_FILE\s*=\s*["\']?(.+?)["\']?\s*$/', $keyLine, $km) && getenv('APP_KEY_FILE') === false) {
            putenv('APP_KEY_FILE=' . $km[1]);
        }
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim(trim($value), "\"'");

        if ($name !== '' && getenv($name) === false) {
            $value = crypto_env_decode($value); // "enc:v1:..." (verschlüsselte Geheimnisse) entschlüsseln
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

env_load(__DIR__ . '/.env');

define('APP_ROOT', __DIR__);
define('APP_DEBUG', (getenv('DEBUG') ?: 'false') === 'true');
define('APP_BASE_URL', rtrim((string) (getenv('BASE_URL') ?: ''), '/'));
define('APP_FORCE_HTTPS_COOKIE', (getenv('FORCE_HTTPS_COOKIE') ?: 'false') === 'true');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

date_default_timezone_set('Europe/Vienna');

if (session_status() === PHP_SESSION_NONE) {
    session_name('u19_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => APP_FORCE_HTTPS_COOKIE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Protokollierung (Fehler, Requests, Audit) für alle Seiten und die API aktivieren
require_once __DIR__ . '/includes/logger.php';
log_register_handlers();

// ── Übertragung nur verschlüsselt (HTTPS) ────────────────────────────────
if (PHP_SAPI !== 'cli' && str_starts_with(APP_BASE_URL, 'https://')) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    if (!$isHttps) {
        $host = (string) parse_url(APP_BASE_URL, PHP_URL_HOST);
        header('Location: https://' . $host . (string) ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}
