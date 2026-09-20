<?php

declare(strict_types=1);

/**
 * Neueste Version der Desktop-Anwendung (C#) von GitHub Releases, für die Download-Seite im Web-Panel.
 *
 * Das Repository steht in der .env (APP_GITHUB_REPO, Standard: Christian445123/MitgliederverwaltungU19C-).
 * Das Ergebnis wird 10 Minuten zwischengespeichert (Tabelle app_settings), damit GitHub nicht bei jedem
 * Seitenaufruf gefragt wird. Bei einem Fehler (GitHub nicht erreichbar) wird der letzte gute Stand gezeigt.
 */

require_once __DIR__ . '/settings.php';

const APP_RELEASE_CACHE_SECONDS = 600;

function app_release_repo(): string
{
    $repo = trim((string) getenv('APP_GITHUB_REPO'));
    return preg_match('#^[\w.\-]+/[\w.\-]+$#', $repo) === 1 ? $repo : 'Christian445123/MitgliederverwaltungU19C-';
}

/**
 * Einfacher GET auf die GitHub-API.
 *
 * @return array{status: int, body: string}
 */
function app_release_http_get(string $url): array
{
    $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: MitgliederverwaltungU19-Web'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 8, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

/**
 * Fragt GitHub nach dem neuesten Release.
 *
 * @return array{release: ?array<string, mixed>, error: ?string}
 */
function app_release_fetch(string $repo): array
{
    $response = app_release_http_get('https://api.github.com/repos/' . $repo . '/releases/latest');

    if ($response['status'] === 404) {
        return ['release' => null, 'error' => 'Für dieses Repository wurde noch kein Release veröffentlicht.'];
    }
    if ($response['status'] !== 200) {
        return ['release' => null, 'error' => $response['status'] === 0
            ? 'GitHub ist vom Server aus nicht erreichbar.'
            : 'GitHub antwortete mit Fehler ' . $response['status'] . '.'];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        return ['release' => null, 'error' => 'Ungültige Antwort von GitHub.'];
    }

    // Installationsdatei: die .msi (bei mehreren die x64-Variante)
    $asset = null;
    foreach ((array) ($data['assets'] ?? []) as $a) {
        $name = (string) ($a['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.msi')) {
            continue;
        }
        if ($asset === null || stripos($name, 'x64') !== false) {
            $asset = [
                'name' => $name,
                'size' => (int) ($a['size'] ?? 0),
                'url' => (string) ($a['browser_download_url'] ?? ''),
                'downloads' => (int) ($a['download_count'] ?? 0),
            ];
        }
    }

    $tag = (string) ($data['tag_name'] ?? '');
    return ['release' => [
        'tag' => $tag,
        'version' => ltrim($tag, 'vV'),
        'name' => (string) ($data['name'] ?? $tag),
        'notes' => (string) ($data['body'] ?? ''),
        'published_at' => (string) ($data['published_at'] ?? ''),
        'html_url' => (string) ($data['html_url'] ?? ''),
        'asset' => $asset,
    ], 'error' => null];
}

/**
 * Neuestes Release (zwischengespeichert).
 *
 * @return array{release: ?array<string, mixed>, error: ?string, fetched_at: int, stale: bool}
 */
function app_release_latest(bool $force = false): array
{
    $repo = app_release_repo();
    $cache = json_decode(app_setting_get('app_release_cache'), true);
    $cacheValid = is_array($cache) && ($cache['repo'] ?? '') === $repo && is_array($cache['data'] ?? null);

    if (!$force && $cacheValid && time() - (int) ($cache['fetched_at'] ?? 0) < APP_RELEASE_CACHE_SECONDS) {
        return $cache['data'] + ['fetched_at' => (int) $cache['fetched_at'], 'stale' => false];
    }

    $fresh = app_release_fetch($repo);
    if ($fresh['release'] !== null) {
        try {
            app_setting_set('app_release_cache', (string) json_encode(['repo' => $repo, 'fetched_at' => time(), 'data' => $fresh]));
        } catch (Throwable $e) {
            // Zwischenspeicher nicht möglich: kein Problem
        }
        return $fresh + ['fetched_at' => time(), 'stale' => false];
    }

    // Fehler: den letzten guten Stand zeigen, falls vorhanden
    if ($cacheValid && ($cache['data']['release'] ?? null) !== null) {
        return ['release' => $cache['data']['release'], 'error' => $fresh['error'], 'fetched_at' => (int) $cache['fetched_at'], 'stale' => true];
    }
    return $fresh + ['fetched_at' => time(), 'stale' => false];
}

/** Größe lesbar: 44,6 MB */
function app_release_size(int $bytes): string
{
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}
