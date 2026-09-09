<?php

declare(strict_types=1);

function env_load(string $path): void
{
    if (!is_readable($path)) {
        throw new RuntimeException(".env Datei nicht gefunden oder nicht lesbar: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

env_load(__DIR__ . '/.env');

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
