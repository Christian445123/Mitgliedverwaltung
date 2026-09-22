<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/updater.php';

require_permission('system.update');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $result = perform_update(dirname(__DIR__));
    app_log('system.update', $result['success'] ? 'Server-Update erfolgreich' : 'Server-Update fehlgeschlagen', ['success' => $result['success']], $result['success'] ? 'info' : 'error');
    // Als eigene Anfrage neu laden (Post/Redirect/Get), statt admin_header.php direkt im selben
    // PHP-Prozess wie das gerade ausgeführte "git pull" zu laden: Dateien, die schon vor dem Pull in
    // diesem Prozess eingebunden wurden (z. B. über db.php), bleiben sonst mit ihrem alten Stand im
    // Speicher, auch wenn sie auf der Festplatte gerade aktualisiert wurden - das kann bei neuen
    // Funktionen in solchen Dateien zu einem einmaligen Fehler direkt nach einem erfolgreichen Update
    // führen. Mit einer frischen Anfrage werden alle Dateien garantiert vom aktualisierten Stand geladen.
    $_SESSION['update_result'] = $result;
    redirect('update.php');
}

$result = $_SESSION['update_result'] ?? null;
unset($_SESSION['update_result']);

$pageTitle = 'Anwendung aktualisieren';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Anwendung aktualisieren</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<p>Zieht den neuesten Code aus dem Git-Repository (<code>git pull --ff-only</code>). Bricht
kontrolliert ab, ohne etwas zu verändern, falls auf dem Server nicht committete Änderungen
liegen oder der Pull nicht als reines Fast-Forward möglich ist. Datenbank-Änderungen
(<code>schema.sql</code>) müssen danach ggf. manuell eingespielt werden.</p>

<p class="muted">Ist der GitHub-Webhook eingerichtet (<code>deploy-webhook.php</code>, <code>DEPLOY_WEBHOOK_SECRET</code>
in der .env), passiert das automatisch nach jedem Push - dieser Button ist dann nur noch für
manuelle Sonderfälle nötig.</p>

<?php if ($result): ?>
    <p class="alert alert-<?= $result['success'] ? 'success' : 'error' ?>">
        <?= $result['success'] ? 'Update erfolgreich.' : 'Update fehlgeschlagen – siehe Log unten.' ?>
    </p>
    <pre style="background:#1e1e1e;color:#e5e5e5;padding:12px;border-radius:6px;overflow-x:auto;white-space:pre-wrap;"><?= h($result['log']) ?></pre>
<?php endif; ?>

<form method="post" action="update.php" data-confirm="Jetzt den neuesten Code per git pull einspielen?">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary">Jetzt aktualisieren (git pull)</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
