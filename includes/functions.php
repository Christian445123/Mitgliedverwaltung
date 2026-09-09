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

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fehler beim Datei-Upload (Code ' . $file['error'] . ').');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Die Datei ist zu groß (maximal 5 MB erlaubt).');
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
