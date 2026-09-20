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

function crypto_key_file(): string
{
    $custom = getenv('APP_KEY_FILE');
    if (is_string($custom) && $custom !== '') {
        return $custom;
    }
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.u19-keys' . DIRECTORY_SEPARATOR . 'master.key';
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
    if (file_put_contents($file, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
        throw new RuntimeException("Schlüsseldatei konnte nicht geschrieben werden: {$file}");
    }
    @chmod($file, 0600);
    return $file;
}

/** @throws RuntimeException wenn kein gültiger Schlüssel vorhanden ist */
function crypto_master_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = crypto_key_file();
    $raw = is_readable($file) ? base64_decode(trim((string) file_get_contents($file)), true) : false;
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('Der Schlüssel für die Verschlüsselung fehlt oder ist ungültig (' . $file . '). Bitte tools/setup-encryption.php ausführen.');
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
