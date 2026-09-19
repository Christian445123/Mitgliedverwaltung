<?php

declare(strict_types=1);

/**
 * Kleine Schlüssel/Wert-Einstellungen in der Datenbank (Tabelle app_settings), z. B. die zuletzt
 * verwendeten Angaben für den IFAF-Roster. Die Tabelle wird bei Bedarf angelegt.
 */

require_once __DIR__ . '/../db.php';

function app_setting_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

function app_setting_get(string $key, string $default = ''): string
{
    try {
        app_setting_ensure_table();
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function app_setting_set(string $key, string $value): void
{
    app_setting_ensure_table();
    db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}
