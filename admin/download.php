<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app_release.php';

require_admin();

$result = app_release_latest(isset($_GET['refresh']));
$release = $result['release'];
$asset = $release['asset'] ?? null;

$pageTitle = 'Download';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Desktop-Anwendung herunterladen</h1>
    <a href="download.php?refresh=1" class="btn btn-link">Neu prüfen</a>
</div>

<?php if ($release === null): ?>
    <p class="alert alert-warning"><?= h((string) $result['error']) ?></p>
    <p class="muted">Sobald auf GitHub unter „Releases“ ein Release mit der Installationsdatei (.msi) veröffentlicht ist, erscheint sie hier automatisch.</p>
<?php else: ?>
    <?php if ($result['error'] !== null): ?>
        <p class="alert alert-warning">GitHub ist gerade nicht erreichbar (<?= h((string) $result['error']) ?>). Angezeigt wird der zuletzt bekannte Stand von <?= h(date('d.m.Y H:i', (int) $result['fetched_at'])) ?>.</p>
    <?php endif; ?>

    <section class="panel" style="max-width:760px;">
        <h2 class="section-title">Aktuelle Version <?= h($release['version']) ?></h2>
        <p class="muted">
            <?php if ($release['published_at'] !== ''): ?>Veröffentlicht am <?= h(date('d.m.Y', (int) strtotime($release['published_at']))) ?><?php endif; ?>
            <?php if ($asset !== null): ?> · <?= h(app_release_size($asset['size'])) ?> · Windows 10/11 (64 Bit)<?php endif; ?>
        </p>

        <?php if ($asset !== null && $asset['url'] !== ''): ?>
            <p>
                <a href="<?= h($asset['url']) ?>" class="btn btn-primary" rel="noopener">MSI-Installer herunterladen (<?= h($asset['name']) ?>)</a>
            </p>
        <?php else: ?>
            <p class="alert alert-warning">Zu dieser Version wurde noch keine Installationsdatei (.msi) hochgeladen.</p>
        <?php endif; ?>

        <?php if (trim($release['notes']) !== ''): ?>
            <h3>Neu in dieser Version</h3>
            <pre style="white-space:pre-wrap; font-family:inherit; margin:0 0 14px;"><?= h(trim($release['notes'])) ?></pre>
        <?php endif; ?>

        <h3>Installation</h3>
        <ol>
            <li>Die Datei ausführen (Administratorrechte nötig). Sie installiert nach <code>C:\Mitgliederverwaltung</code> und legt eine Desktop-Verknüpfung an.</li>
            <li>Im Installer die <strong>API-Adresse</strong>, den <strong>API-Schlüssel</strong> (Menü „API-Zugang“) und den <strong>Lizenzschlüssel</strong> (Menü „Lizenzen“) eintragen.</li>
            <li>Bei neuen Versionen meldet sich die Anwendung selbst (Button „Update“) und aktualisiert sich; die Datei hier ist immer die neueste Version.</li>
        </ol>
        <?php if ($release['html_url'] !== ''): ?>
            <p class="muted"><a href="<?= h($release['html_url']) ?>" target="_blank" rel="noopener">Release auf GitHub ansehen</a></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
