<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/Mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();

$email = trim((string) ($_POST['email'] ?? ''));

if (!is_valid_email($email)) {
    flash_set('error', 'Bitte gib eine gültige E-Mail-Adresse ein.');
    redirect('index.php');
}

// Einfacher Spam-/Missbrauchsschutz: nicht mehrfach in kurzer Zeit an dieselbe Adresse senden.
$stmt = db()->prepare(
    "SELECT COUNT(*) FROM edit_tokens WHERE email = ? AND created_at > (NOW() - INTERVAL 5 MINUTE)"
);
$stmt->execute([$email]);
$recentCount = (int) $stmt->fetchColumn();

if ($recentCount === 0) {
    $token = random_token(32);
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');

    $insert = db()->prepare('INSERT INTO edit_tokens (email, token_hash, expires_at) VALUES (?, ?, ?)');
    $insert->execute([$email, $tokenHash, $expiresAt]);

    $link = APP_BASE_URL . '/mitglied-formular.php?token=' . $token;

    $html = '<p>Hallo,</p>'
        . '<p>mit folgendem Link kannst du deine Mitgliedsdaten beim AFBÖ U19 anlegen oder bearbeiten:</p>'
        . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
        . '<p>Der Link ist 2 Stunden gültig. Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail einfach ignorieren.</p>';

    try {
        (new Mailer())->send($email, $email, 'Dein Link zur Mitgliederverwaltung – AFBÖ U19', $html);
    } catch (Throwable $e) {
        if (APP_DEBUG) {
            throw $e;
        }
        flash_set('error', 'E-Mail konnte gerade nicht versendet werden. Bitte später erneut versuchen.');
        redirect('index.php');
    }
}

flash_set('info', 'Falls die Adresse gültig ist, wurde ein Link an dich versendet. Bitte prüfe dein Postfach (ggf. auch den Spam-Ordner).');
redirect('index.php');
