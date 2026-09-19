<?php

declare(strict_types=1);

/**
 * Zentrales Protokoll (Audit- und Zugriffslog) für Web-Anwendung und API.
 *
 * - Speicherung in der Tabelle "activity_log" (wird bei Bedarf automatisch angelegt).
 * - Protokollieren darf die Anwendung nie stören: alle Fehler werden abgefangen.
 * - Passwörter, Tokens, Zugangscodes und Hashes werden NIE gespeichert (siehe log_redact()).
 * - Alte Einträge werden automatisch gelöscht (LOG_RETENTION_DAYS, Standard 180 Tage).
 *
 * Nutzung:  app_log('member.update', 'Mitglied geändert', ['target_type' => 'member', 'target_id' => 12]);
 */

require_once __DIR__ . '/../db.php';

const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];

/** Quelle dieses Requests: 'web' (Standard) oder 'api' (definiert api/index.php vor dem Laden). */
function log_source(): string
{
    return defined('LOG_SOURCE') ? (string) LOG_SOURCE : 'web';
}

function log_retention_days(): int
{
    $days = (int) (getenv('LOG_RETENTION_DAYS') ?: 180);
    return $days > 0 ? $days : 180;
}

function log_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS activity_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source VARCHAR(10) NOT NULL,
            level VARCHAR(10) NOT NULL DEFAULT \'info\',
            action VARCHAR(60) NOT NULL,
            actor VARCHAR(100) DEFAULT NULL,
            target_type VARCHAR(30) DEFAULT NULL,
            target_id VARCHAR(40) DEFAULT NULL,
            message VARCHAR(500) NOT NULL DEFAULT \'\',
            details TEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            http_method VARCHAR(8) DEFAULT NULL,
            path VARCHAR(255) DEFAULT NULL,
            status_code SMALLINT UNSIGNED DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            KEY idx_created (created_at),
            KEY idx_source_created (source, created_at),
            KEY idx_level (level),
            KEY idx_actor (actor),
            KEY idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Wer handelt? Web: angemeldeter Admin, API: "token:<Name>" (wird von api/index.php gesetzt). */
function log_actor(): ?string
{
    if (!empty($GLOBALS['log_actor']) && is_string($GLOBALS['log_actor'])) {
        return $GLOBALS['log_actor'];
    }
    return isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : null;
}

/** IP-Adresse des Clients (hinter einem Reverse-Proxy aus X-Forwarded-For, sonst REMOTE_ADDR). */
function client_ip(): ?string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isInternal = $remote !== '' && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isInternal && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return $remote !== '' ? $remote : null;
}

/**
 * Entfernt geheime Werte rekursiv (Passwörter, Tokens, Codes, Hashes).
 *
 * @param mixed $value
 * @return mixed
 */
function log_redact($value, int $depth = 0)
{
    if (!is_array($value) || $depth > 4) {
        return is_scalar($value) || $value === null ? $value : '[…]';
    }
    $clean = [];
    foreach ($value as $key => $item) {
        $clean[$key] = is_string($key) && preg_match('/pass|token|secret|code|hash|authorization|api[-_]?key|csrf/i', $key)
            ? '***'
            : log_redact($item, $depth + 1);
    }
    return $clean;
}

/**
 * Schreibt einen Protokolleintrag. Wirft nie eine Exception.
 *
 * Besondere Schlüssel in $context: actor, target_type, target_id, status, duration_ms, source.
 * Alles andere landet (bereinigt) als JSON in "details".
 *
 * @param array<string, mixed> $context
 */
function app_log(string $action, string $message = '', array $context = [], string $level = 'info'): void
{
    try {
        if (!in_array($level, LOG_LEVELS, true)) {
            $level = 'info';
        }
        log_ensure_table();

        $actor = array_key_exists('actor', $context) ? $context['actor'] : log_actor();
        $source = (string) ($context['source'] ?? log_source());
        $targetType = $context['target_type'] ?? null;
        $targetId = isset($context['target_id']) ? (string) $context['target_id'] : null;
        $status = isset($context['status']) ? (int) $context['status'] : null;
        $duration = isset($context['duration_ms']) ? (int) $context['duration_ms'] : null;
        unset($context['actor'], $context['source'], $context['target_type'], $context['target_id'], $context['status'], $context['duration_ms']);

        $details = $context === [] ? null : json_encode(log_redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (is_string($details) && strlen($details) > 60000) {
            $details = substr($details, 0, 60000) . '…';
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $stmt = db()->prepare(
            'INSERT INTO activity_log (source, level, action, actor, target_type, target_id, message, details, ip, user_agent, http_method, path, status_code, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $source,
            $level,
            mb_substr($action, 0, 60),
            $actor !== null ? mb_substr((string) $actor, 0, 100) : null,
            $targetType !== null ? mb_substr((string) $targetType, 0, 30) : null,
            $targetId !== null ? mb_substr($targetId, 0, 40) : null,
            mb_substr($message, 0, 500),
            $details === false ? null : $details,
            client_ip(),
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            isset($_SERVER['REQUEST_METHOD']) ? mb_substr((string) $_SERVER['REQUEST_METHOD'], 0, 8) : null,
            $uri !== '' ? mb_substr($uri, 0, 255) : null,
            $status,
            $duration,
        ]);

        // Gelegentlich alte Einträge aufräumen (ca. jeder 200. Eintrag)
        if (random_int(1, 200) === 1) {
            log_purge(log_retention_days());
        }
    } catch (Throwable $e) {
        error_log('app_log fehlgeschlagen: ' . $e->getMessage());
    }
}

/** Löscht Einträge, die älter als $days Tage sind (0 = alle). Liefert die Anzahl gelöschter Einträge. */
function log_purge(int $days): int
{
    log_ensure_table();
    if ($days <= 0) {
        return (int) db()->exec('DELETE FROM activity_log');
    }
    $stmt = db()->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

/**
 * Fehler- und Request-Protokollierung für diesen Request aktivieren
 * (wird am Ende von config.php aufgerufen).
 */
function log_register_handlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $warnings = 0;

    // PHP-Warnungen/Notices protokollieren (Standardbehandlung läuft weiter)
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$warnings): bool {
        if (!(error_reporting() & $errno) || $warnings >= 20) {
            return false;
        }
        $warnings++;
        app_log('php.warning', $errstr, ['file' => basename($errfile) . ':' . $errline, 'errno' => $errno], 'warning');
        return false;
    });

    // Nicht abgefangene Exceptions protokollieren und eine neutrale Fehlerantwort senden
    set_exception_handler(static function (Throwable $e): void {
        app_log('error.exception', $e->getMessage(), [
            'class' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
            'trace' => mb_substr($e->getTraceAsString(), 0, 4000),
        ], 'error');

        if (!headers_sent()) {
            http_response_code(500);
        }
        if (log_source() === 'api') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => APP_DEBUG ? $e->getMessage() : 'Interner Serverfehler.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo APP_DEBUG ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : 'Ein interner Fehler ist aufgetreten. Bitte später erneut versuchen.';
        }
    });

    // Am Ende des Requests: fatale Fehler und Request-Protokoll
    register_shutdown_function(static function () use ($start): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_log('error.fatal', (string) $error['message'], ['file' => basename((string) $error['file']) . ':' . $error['line']], 'error');
        }

        $status = (int) (http_response_code() ?: 200);
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $duration = (int) round((microtime(true) - $start) * 1000);

        // API: jede Anfrage. Web: nur ändernde Anfragen (POST) und Fehler (>= 400), damit das Protokoll lesbar bleibt.
        if (log_source() === 'api') {
            $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
            app_log('api.request', $method . ' ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . ' → ' . $status, ['status' => $status, 'duration_ms' => $duration], $level);
        } elseif ($method !== 'GET' || $status >= 400) {
            $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
            app_log('web.request', $method . ' ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . ' → ' . $status, ['status' => $status, 'duration_ms' => $duration], $level);
        }
    });
}
