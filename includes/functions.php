<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        exit('Ungültige Anfrage (CSRF-Token fehlt oder ungültig). Bitte Seite neu laden und erneut versuchen.');
    }
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!empty($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Cache-Busting für statische Assets: hängt die letzte Änderungszeit der
 * Datei als Query-Parameter an, damit Browser/CDN nach einem Deployment
 * automatisch die neue Version laden statt eine alte CSS/JS-Datei zu
 * cachen (Dateiname bleibt sonst bei jedem Update identisch).
 */
function asset_version(string $relativeToAssets): string
{
    $path = APP_ROOT . '/assets/' . $relativeToAssets;
    return (string) (is_file($path) ? filemtime($path) : time());
}

/**
 * Liest einen POST-Wert als getrimmten String, oder null falls leer.
 */
function post_str(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function post_checkbox(string $key): bool
{
    return isset($_POST[$key]) && $_POST[$key] === '1';
}

function post_int(string $key): ?int
{
    $value = post_str($key);
    if ($value === null || !ctype_digit($value)) {
        return null;
    }
    return (int) $value;
}

/**
 * Validiert und normalisiert ein Datumsfeld (Format Y-m-d aus <input type="date">).
 */
function post_date(string $key): ?string
{
    $value = post_str($key);
    if ($value === null) {
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return null;
    }
    return $date->format('Y-m-d');
}

/** Maximale Größe eines Dokument-Uploads in Bytes (.env: UPLOAD_MAX_MB, Standard 15). */
function upload_max_bytes(): int
{
    $mb = (int) (getenv('UPLOAD_MAX_MB') ?: 15);
    return max(1, $mb) * 1024 * 1024;
}

/**
 * Verarbeitet einen Datei-Upload (Bild/PDF), validiert Typ & Größe
 * und speichert die Datei in $targetDir. Gibt den relativen Pfad zurück
 * oder null, falls kein neuer Upload vorhanden war.
 *
 * @throws RuntimeException bei ungültigem Upload
 */
function handle_upload(string $inputName, string $targetDir, string $publicPrefix): ?string
{
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$inputName];

    $maxMb = intdiv(upload_max_bytes(), 1024 * 1024);
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Die Datei ist zu groß für den Server (PHP-Grenze upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Bitte verkleinern oder die Server-Grenze erhöhen.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fehler beim Datei-Upload (Code ' . $file['error'] . ').');
    }

    if ($file['size'] > upload_max_bytes()) {
        throw new RuntimeException('Die Datei ist zu groß (maximal ' . $maxMb . ' MB erlaubt).');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Ungültiger Dateityp. Erlaubt sind JPG, PNG oder PDF.');
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Zielverzeichnis für Upload konnte nicht angelegt werden.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $targetPath = rtrim($targetDir, '/\\') . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    }

    return rtrim($publicPrefix, '/') . '/' . $filename;
}

/**
 * Vereinheitlicht Telefonnummern (Standard-Vorwahl +43):
 *   0660 1234567 / 0043 660 1234567 / 660 1234567 -> +43 660 1234567
 * Andere Länder (führendes "+") werden nur aufgeräumt.
 *
 * @throws InvalidArgumentException wenn keine plausible Nummer erkennbar ist (6-15 Ziffern)
 */
function normalize_phone(string $raw): string
{
    $raw = trim($raw);
    $plus = str_starts_with($raw, '+');
    $raw = (string) preg_replace('/\(0\)/', '', $raw); // "+43 (0) 660" -> "+43 660"
    $digits = (string) preg_replace('/\D+/', '', $raw);

    if (strlen($digits) < 6 || strlen($digits) > 15) {
        throw new InvalidArgumentException('Ungültige Telefonnummer "' . $raw . '"');
    }

    if (!$plus && str_starts_with($digits, '00')) {
        $plus = true;
        $digits = substr($digits, 2);
    } elseif (!$plus && str_starts_with($digits, '43') && strlen($digits) >= 11) {
        $plus = true; // "436601234567" (Excel ohne "+")
    }

    if (!$plus) {
        $digits = '43' . ltrim($digits, '0'); // 0660... oder 660... -> Österreich
        $plus = true;
    }

    if (str_starts_with($digits, '43')) {
        $rest = ltrim(substr($digits, 2), '0');
        // Mobilnetz (z.B. 650, 660, 664, 676, 699): Vorwahl abtrennen
        if (strlen($rest) > 3 && $rest[0] === '6') {
            return '+43 ' . substr($rest, 0, 3) . ' ' . substr($rest, 3);
        }
        return '+43 ' . $rest;
    }

    return '+' . $digits;
}

/**
 * Automatisch gebildeter Name: "Nachname Vorname" (z. B. "Walch Jakob").
 *
 * @param array<string, mixed> $row
 */
function member_full_name(array $row): string
{
    return trim(((string) ($row['nachname'] ?? '')) . ' ' . ((string) ($row['vorname'] ?? '')));
}
