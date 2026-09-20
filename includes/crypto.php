<?php

declare(strict_types=1);

/**
 * Verschlüsselung (AES-256-GCM über OpenSSL) für Geheimnisse in der .env, hochgeladene Dokumente und
 * die API-Übertragung.
 *
 * Der Masterschlüssel liegt NICHT in der .env und nicht im Webverzeichnis, sondern in einer Schlüsseldatei
 * (Standard: <Elternordner der Anwendung>/.u19-keys/master.key, änderbar per Umgebungsvariable APP_KEY_FILE).
 * Daraus werden per HKDF getrennte Teilschlüssel abgeleitet:
 *   secrets   - Werte in der .env  ("enc:v1:...")
 *   files     - hochgeladene Dokumente
 *   transport - Übertragung zwischen Desktop-App und API (wird der App als "Verschlüsselungsschlüssel" mitgegeben)
 *
 * Diese Datei darf keine Abhängigkeit zu config.php haben (wird von dort beim Einlesen der .env benötigt).
 */

const CRYPTO_ENV_PREFIX = 'enc:v1:';
const CRYPTO_FILE_MAGIC = "U19ENC1\n";

/** Schutzzeile am Anfang der Schlüsseldatei: Wird die Datei versehentlich über das Web aufgerufen, führt PHP sie nur aus und gibt nichts aus. */
const CRYPTO_KEY_GUARD = "<?php exit; ?>\n";

/**
 * Mögliche Orte der Schlüsseldatei in der Reihenfolge der Suche: APP_KEY_FILE aus der .env, dann der Ordner neben dem
 * Anwendungsordner, dann ein Ordner im Anwendungsordner (falls PHP nicht außerhalb des Website-Ordners lesen darf).
 *
 * @return array<int, string>
 */
function crypto_key_candidates(): array
{
    $list = [];
    $custom = getenv('APP_KEY_FILE');
    if (is_string($custom) && $custom !== '') {
        $list[] = $custom;
    }
    $list[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.u19-keys' . DIRECTORY_SEPARATOR . 'master.key';
    $list[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.u19-keys' . DIRECTORY_SEPARATOR . 'master.key';
    return array_values(array_unique($list));
}

/** Die verwendete Schlüsseldatei (erste lesbare), sonst der erste Kandidat (Ort zum Anlegen). */
function crypto_key_file(): string
{
    foreach (crypto_key_candidates() as $file) {
        if (@is_readable($file) && @is_file($file)) {
            return $file;
        }
    }
    return crypto_key_candidates()[0];
}

/** Liest 32 Byte aus einer Schlüsseldatei (mit oder ohne Schutzzeile) oder false. */
function crypto_key_parse(string $file): string|false
{
    $content = @file_get_contents($file);
    if ($content === false) {
        return false;
    }
    if (str_starts_with($content, CRYPTO_KEY_GUARD)) {
        $content = substr($content, strlen(CRYPTO_KEY_GUARD));
    }
    $raw = base64_decode(trim($content), true);
    return $raw !== false && strlen($raw) === 32 ? $raw : false;
}

/** Legt den Masterschlüssel an, falls noch keiner existiert (nur für das Einrichtungsskript). */
function crypto_key_create(): string
{
    $file = crypto_key_file();
    if (is_file($file)) {
        return $file;
    }
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Ordner für den Schlüssel konnte nicht angelegt werden: {$dir} (Alternativ APP_KEY_FILE setzen.)");
    }
    if (file_put_contents($file, CRYPTO_KEY_GUARD . base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Schlüsseldatei konnte nicht geschrieben werden: {$file}");
    }
    @chmod($file, 0600);
    return $file;
}

/**
 * Zustand der Schlüsseldatei für die Fehlersuche (Webpanel).
 *
 * @return array{tried: array<int, array{path: string, exists: bool, readable: bool, valid: bool}>, open_basedir: string}
 */
function crypto_key_status(): array
{
    $tried = [];
    foreach (crypto_key_candidates() as $file) {
        $tried[] = [
            'path' => $file,
            'exists' => @file_exists($file),
            'readable' => @is_readable($file),
            'valid' => @is_readable($file) && crypto_key_parse($file) !== false,
        ];
    }
    return ['tried' => $tried, 'open_basedir' => (string) ini_get('open_basedir')];
}

/** @throws RuntimeException wenn kein gültiger Schlüssel vorhanden ist */
function crypto_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = crypto_key_file();
    $raw = @is_readable($file) ? crypto_key_parse($file) : false;
    if ($raw === false) {
        throw new RuntimeException('Der Schlüssel für die Verschlüsselung fehlt oder ist ungültig (gesucht: ' . implode(', ', crypto_key_candidates()) . '). Bitte tools/setup-encryption.php ausführen oder die Datei master.key hochladen.');
    }
    return $key = $raw;
}

function crypto_subkey(string $label): string
{
    static $cache = [];
    return $cache[$label] ??= hash_hkdf('sha256', crypto_master_key(), 32, 'u19-' . $label . '-v1');
}

/** Verschlüsselungsschlüssel für die Desktop-App (Base64). */
function crypto_transport_key_b64(): string
{
    return base64_encode(crypto_subkey('transport'));
}

/** @return string nonce(12) | tag(16) | ciphertext */
function crypto_encrypt(string $plain, string $label, string $aad = ''): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', crypto_subkey($label), OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($cipher === false) {
        throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
    }
    return $nonce . $tag . $cipher;
}

/** @return string|false false bei falschem Schlüssel oder manipulierten Daten */
function crypto_decrypt(string $blob, string $label, string $aad = ''): string|false
{
    if (strlen($blob) < 28) {
        return false;
    }
    return openssl_decrypt(substr($blob, 28), 'aes-256-gcm', crypto_subkey($label), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $aad);
}

// ── Geheimnisse in der .env ──────────────────────────────────────────

function crypto_env_encode(string $plain): string
{
    return CRYPTO_ENV_PREFIX . base64_encode(crypto_encrypt($plain, 'secrets'));
}

/** Entschlüsselt "enc:v1:..."-Werte; andere Werte bleiben unverändert. */
function crypto_env_decode(string $value): string
{
    if (!str_starts_with($value, CRYPTO_ENV_PREFIX)) {
        return $value;
    }
    $blob = base64_decode(substr($value, strlen(CRYPTO_ENV_PREFIX)), true);
    $plain = $blob === false ? false : crypto_decrypt($blob, 'secrets');
    if ($plain === false) {
        throw new RuntimeException('Ein verschlüsselter Wert in der .env kann nicht entschlüsselt werden (falscher oder fehlender Schlüssel).');
    }
    return $plain;
}

// ── Dateien (hochgeladene Dokumente) ─────────────────────────────────

function crypto_file_is_encrypted(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = fread($fh, strlen(CRYPTO_FILE_MAGIC));
    fclose($fh);
    return $head === CRYPTO_FILE_MAGIC;
}

/** Verschlüsselt die Datei an Ort und Stelle (idempotent). */
function crypto_file_encrypt(string $path): void
{
    if (crypto_file_is_encrypted($path)) {
        return;
    }
    $plain = file_get_contents($path);
    if ($plain === false) {
        throw new RuntimeException('Datei konnte nicht gelesen werden.');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, CRYPTO_FILE_MAGIC . crypto_encrypt($plain, 'files', basename($path)), LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Datei konnte nicht verschlüsselt gespeichert werden.');
    }
}

/** Inhalt der Datei im Klartext (auch für noch unverschlüsselte Altdateien). */
function crypto_file_read(string $path): string
{
    $data = file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Datei konnte nicht gelesen werden.');
    }
    if (!str_starts_with($data, CRYPTO_FILE_MAGIC)) {
        return $data;
    }
    $plain = crypto_decrypt(substr($data, strlen(CRYPTO_FILE_MAGIC)), 'files', basename($path));
    if ($plain === false) {
        throw new RuntimeException('Datei konnte nicht entschlüsselt werden.');
    }
    return $plain;
}
