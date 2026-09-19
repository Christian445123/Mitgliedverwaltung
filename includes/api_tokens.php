<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';

/**
 * API-Tokens für den Zugriff von PC-Anwendungen (Excel Power Query,
 * PowerShell, eigene Tools). In der DB liegt nur der SHA-256-Hash; der
 * Klartext-Token wird einmalig bei der Erstellung angezeigt.
 */
function api_tokens_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS api_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            can_write TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_token_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * @return string Klartext-Token (nur jetzt verfügbar)
 */
function api_token_create(string $name, bool $canWrite, ?int $adminId): string
{
    api_tokens_ensure_table();
    $token = 'u19_' . random_token(24);
    $stmt = db()->prepare('INSERT INTO api_tokens (name, token_hash, can_write, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, hash('sha256', $token), $canWrite ? 1 : 0, $adminId]);
    return $token;
}

/**
 * @return array<string, mixed>|null
 */
function api_token_verify(string $token): ?array
{
    api_tokens_ensure_table();
    $stmt = db()->prepare('SELECT id, name, can_write FROM api_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return $row;
}

/**
 * @return array<int, array<string, mixed>>
 */
function api_token_list(): array
{
    api_tokens_ensure_table();
    return db()->query(
        'SELECT t.id, t.name, t.can_write, t.created_at, t.last_used_at, a.username AS created_by_name
         FROM api_tokens t LEFT JOIN admins a ON a.id = t.created_by
         ORDER BY t.created_at DESC'
    )->fetchAll();
}

function api_token_delete(int $id): void
{
    api_tokens_ensure_table();
    db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
}
