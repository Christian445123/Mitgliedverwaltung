<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/member_repository.php';

/**
 * @return array<string, mixed>|null
 */
function edit_token_lookup(string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM edit_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    if (new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable()) {
        return null;
    }

    return $row;
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($token === '') {
    flash_set('error', 'Ungültiger oder fehlender Link.');
    redirect('index.php');
}

$editToken = edit_token_lookup($token);

if ($editToken === null) {
    flash_set('error', 'Dieser Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Link an.');
    redirect('index.php');
}

$email = $editToken['email'];
$existing = member_find_by_email($email);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $data = member_collect_input($existing ?: []);
        $data['email'] = $email; // E-Mail ist über den Link fix vorgegeben

        member_upsert($data, $existing ? (int) $existing['id'] : null);

        $markUsed = db()->prepare('UPDATE edit_tokens SET used_at = NOW() WHERE id = ?');
        $markUsed->execute([$editToken['id']]);

        redirect('mitglied-gespeichert.php');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $existing = array_merge($existing ?: [], $_POST);
    }
}

$m = $existing ?: ['email' => $email];
$emailLocked = true;
$showAdminFields = false;
$isNew = $existing === false;
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AFBÖ U19 – Meine Daten</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
<main class="card wide">
    <div class="brand">
        <span class="brand-badge">🏈</span>
        <h1><?= $isNew ? 'Mitgliedsdaten neu anlegen' : 'Mitgliedsdaten bearbeiten' ?></h1>
        <p class="muted">Angemeldet als <strong><?= h($email) ?></strong></p>
    </div>

    <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

    <form method="post" action="mitglied-formular.php?token=<?= h($token) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <?php require __DIR__ . '/includes/member_fields.php'; ?>

        <div class="form-actions">
            <button type="submit">Speichern</button>
        </div>
    </form>
</main>
</div>
</body>
</html>
