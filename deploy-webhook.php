<?php

declare(strict_types=1);

/**
 * GitHub-Webhook: aktualisiert die Anwendung automatisch nach jedem Push auf den Hauptbranch
 * (macht auf dem Server dasselbe wie der Button "Jetzt aktualisieren" unter admin/update.php,
 * siehe includes/updater.php: git pull --ff-only).
 *
 * Einrichtung (einmalig):
 *   1. In der .env auf dem Server eine Zeile DEPLOY_WEBHOOK_SECRET=<zufälliger, langer Wert> eintragen.
 *   2. Auf GitHub: Repository > Settings > Webhooks > Add webhook
 *        Payload URL:   https://<domain>/deploy-webhook.php
 *        Content type:  application/json
 *        Secret:        derselbe Wert wie DEPLOY_WEBHOOK_SECRET
 *        Ereignisse:    nur "Just the push event"
 *
 * Ohne eingerichtetes Secret lehnt dieses Skript JEDE Anfrage ab (fail closed).
 * Ausgelöst wird nur bei einem Push auf den Branch aus DEPLOY_BRANCH (.env, Standard "main").
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/updater.php';

header('Content-Type: application/json; charset=utf-8');

function deploy_webhook_fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_webhook_fail(405, 'Nur POST erlaubt.');
}

$secret = (string) getenv('DEPLOY_WEBHOOK_SECRET');
if ($secret === '') {
    // Bewusst nicht ausführlicher: ein Angreifer soll nicht erfahren, woran es genau liegt.
    error_log('deploy-webhook: DEPLOY_WEBHOOK_SECRET ist nicht gesetzt - Anfrage abgelehnt.');
    deploy_webhook_fail(503, 'Webhook ist nicht eingerichtet.');
}

$payload = (string) file_get_contents('php://input');
$signatureHeader = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if ($signatureHeader === '' || !hash_equals($expected, $signatureHeader)) {
    app_log('deploy.webhook_rejected', 'Deploy-Webhook: ungültige oder fehlende Signatur', [], 'warning');
    deploy_webhook_fail(401, 'Ungültige Signatur.');
}

// GitHub sendet beim Einrichten/Testen des Webhooks ein "ping" ohne Push - einfach bestätigen.
$event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
if ($event === 'ping') {
    echo json_encode(['ok' => true, 'message' => 'pong']);
    exit;
}
if ($event !== 'push') {
    echo json_encode(['ok' => true, 'message' => 'Ereignis ignoriert: ' . $event]);
    exit;
}

$data = json_decode($payload, true);
$ref = is_array($data) ? (string) ($data['ref'] ?? '') : '';
$branch = (string) (getenv('DEPLOY_BRANCH') ?: 'main');

if ($ref !== 'refs/heads/' . $branch) {
    echo json_encode(['ok' => true, 'message' => 'Push auf anderen Branch ignoriert (' . $ref . ').']);
    exit;
}

// Nur ein Update gleichzeitig (z. B. bei mehreren schnellen Pushes hintereinander).
$lockDir = APP_ROOT . '/logs';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/deploy.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    app_log('deploy.webhook_busy', 'Deploy-Webhook: läuft bereits, Anfrage übersprungen', [], 'warning');
    echo json_encode(['ok' => true, 'message' => 'Ein Update läuft bereits, übersprungen.']);
    exit;
}

try {
    $result = perform_update(APP_ROOT);
    app_log(
        'deploy.webhook',
        'Automatisches Update per GitHub-Webhook ' . ($result['success'] ? 'erfolgreich' : 'fehlgeschlagen'),
        ['success' => $result['success'], 'ref' => $ref],
        $result['success'] ? 'info' : 'error'
    );
    echo json_encode(['ok' => $result['success'], 'log' => $result['log']], JSON_UNESCAPED_SLASHES);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
