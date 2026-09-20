<?php

declare(strict_types=1);

/**
 * Verschlüsselte Übertragung der API (zusätzlich zu HTTPS).
 *
 * Die Desktop-App schickt jede Anfrage als POST mit Header "X-Enc: 1". Der Body ist ein AES-256-GCM-Paket
 * (Schlüssel: Teilschlüssel "transport", siehe includes/crypto.php) mit folgendem Klartext:
 *
 *   uint32 (Big Endian) Länge der Metadaten | Metadaten (JSON) | Nutzdaten
 *
 * Metadaten der Anfrage: ts (Unix-Zeit), rid (Zufallskennung), method, uri (Pfad + Query, z. B. "members?limit=5"),
 * headers (Authorization, X-User-Token), ct (ursprünglicher Content-Type), post (Formularfelder) und
 * files ([{field, name, type, len}] - die Dateiinhalte folgen hintereinander als Nutzdaten).
 * Methode, Pfad, Suchbegriffe, Zugangsdaten und Inhalte sind damit nie im Klartext sichtbar.
 *
 * Die Antwort ist ebenso ein Paket (Metadaten: rid, status, ct, cd). Die HTTP-Statusnummer bleibt sichtbar.
 * Anfragen ohne Verschlüsselung werden abgelehnt (Ausnahme: .env API_ALLOW_PLAIN=true für Excel/PowerShell-Zugriffe).
 */

require_once __DIR__ . '/../includes/crypto.php';

const TRANSPORT_MAX_SKEW = 900; // Sekunden

/** Body der Anfrage (entschlüsselt oder, im Klartext-Modus, roh). */
function api_body(): string
{
    return $GLOBALS['api_raw_body'] ?? (string) file_get_contents('php://input');
}

function transport_reject(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Entschlüsselt die Anfrage und stellt $_SERVER/$_GET/$_POST/$_FILES wieder her; verschlüsselt die Antwort beim Beenden. */
function transport_bootstrap(): void
{
    $encrypted = (string) ($_SERVER['HTTP_X_ENC'] ?? '') === '1';
    if (!$encrypted) {
        if ((getenv('API_ALLOW_PLAIN') ?: 'false') === 'true') {
            return;
        }
        transport_reject(400, 'Die Anfrage muss verschlüsselt werden. Bitte die aktuelle Desktop-Anwendung verwenden.');
    }
    if (strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
        transport_reject(400, 'Verschlüsselte Anfragen werden per POST gesendet.');
    }

    try {
        $plain = crypto_decrypt((string) file_get_contents('php://input'), 'transport', 'U19-REQ');
    } catch (RuntimeException $e) {
        transport_reject(503, 'Die Verschlüsselung ist auf dem Server nicht eingerichtet.');
    }
    if ($plain === false || strlen($plain) < 4) {
        transport_reject(400, 'Die Anfrage konnte nicht entschlüsselt werden (falscher Verschlüsselungsschlüssel?).');
    }
    $metaLen = unpack('N', substr($plain, 0, 4))[1];
    $meta = json_decode(substr($plain, 4, $metaLen), true);
    $payload = substr($plain, 4 + $metaLen);
    if (!is_array($meta) || !isset($meta['ts'], $meta['rid'], $meta['method'], $meta['uri'])) {
        transport_reject(400, 'Ungültige Anfrage.');
    }
    if (abs(time() - (int) $meta['ts']) > TRANSPORT_MAX_SKEW) {
        transport_reject(400, 'Die Uhrzeit des Computers weicht zu stark von der des Servers ab (oder die Anfrage ist veraltet).');
    }

    // Anfrage wiederherstellen
    $uri = (string) $meta['uri'];
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $base = rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/\\');
    $_SERVER['REQUEST_METHOD'] = strtoupper((string) $meta['method']);
    $_SERVER['REQUEST_URI'] = $base . '/' . ltrim($path, '/'); // ohne Query: Suchbegriffe landen nicht im Protokoll
    $_SERVER['QUERY_STRING'] = '';
    $_GET = [];
    parse_str($query, $_GET);
    $_GET['path'] = trim($path, '/');
    foreach ((array) ($meta['headers'] ?? []) as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = (string) $value;
    }
    $_SERVER['CONTENT_TYPE'] = (string) ($meta['ct'] ?? '');
    unset($_SERVER['HTTP_X_ENC']);
    $_POST = is_array($meta['post'] ?? null) ? $meta['post'] : [];
    $_FILES = [];
    $GLOBALS['api_raw_body'] = '';

    $files = is_array($meta['files'] ?? null) ? $meta['files'] : [];
    if ($files === []) {
        $GLOBALS['api_raw_body'] = $payload;
    } else {
        $offset = 0;
        foreach ($files as $f) {
            $len = (int) ($f['len'] ?? 0);
            $tmp = tempnam(sys_get_temp_dir(), 'u19up');
            file_put_contents($tmp, substr($payload, $offset, $len));
            $offset += $len;
            $_FILES[(string) $f['field']] = ['name' => basename((string) ($f['name'] ?? 'datei')), 'type' => (string) ($f['type'] ?? ''), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => $len];
            $GLOBALS['api_envelope_files'][$tmp] = true;
        }
        register_shutdown_function(static function (): void {
            foreach (array_keys($GLOBALS['api_envelope_files'] ?? []) as $tmp) {
                @unlink($tmp);
            }
        });
    }

    // Antwort abfangen und verschlüsselt senden
    $rid = (string) $meta['rid'];
    $level = ob_get_level();
    ob_start();
    register_shutdown_function(static function () use ($rid, $level): void {
        $body = '';
        while (ob_get_level() > $level) {
            $body = (string) ob_get_clean() . $body;
        }
        $status = http_response_code() ?: 200;
        $ct = 'application/json';
        $cd = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0) {
                $ct = trim(substr($h, 13));
            } elseif (stripos($h, 'Content-Disposition:') === 0) {
                $cd = trim(substr($h, 20));
            }
        }
        foreach (['Content-Type', 'Content-Disposition', 'Content-Length', 'Cache-Control'] as $name) {
            header_remove($name);
        }
        $metaJson = json_encode(['rid' => $rid, 'status' => $status, 'ct' => $ct, 'cd' => $cd], JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-store');
        header('X-Enc: 1');
        echo crypto_encrypt(pack('N', strlen($metaJson)) . $metaJson . $body, 'transport', 'U19-RES');
    });
}
