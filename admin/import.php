<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_import.php';

require_admin();

$error = null;
$preview = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'preview') {
            $file = $_FILES['file'] ?? null;
            if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Bitte eine Datei auswählen.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Fehler beim Datei-Upload (Code ' . (int) $file['error'] . ').');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('Die Datei ist zu groß (maximal 5 MB).');
            }

            $updateExisting = post_checkbox('update_existing');
            $table = spreadsheet_read($file['tmp_name'], (string) $file['name']);
            $preview = member_import_analyze($table, $updateExisting);
            $preview['filename'] = (string) $file['name'];
            $preview['update_existing'] = $updateExisting;

            $_SESSION['member_import'] = [
                'rows' => $preview['rows'],
                'update_existing' => $updateExisting,
            ];
        } elseif ($action === 'commit') {
            $pending = $_SESSION['member_import'] ?? null;
            unset($_SESSION['member_import']);
            if (!is_array($pending)) {
                throw new RuntimeException('Keine Import-Vorschau vorhanden. Bitte Datei erneut hochladen.');
            }
            $result = member_import_commit($pending['rows'], (bool) $pending['update_existing']);
        } elseif ($action === 'cancel') {
            unset($_SESSION['member_import']);
            redirect('import.php');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Import';
require __DIR__ . '/../includes/admin_header.php';

$actionLabels = ['create' => 'Neu', 'update' => 'Aktualisieren', 'skip' => 'Übersprungen', 'error' => 'Fehler'];
$actionBadges = ['create' => 'green', 'update' => 'blue', 'skip' => 'gray', 'error' => 'orange'];
?>
<div class="content-header">
    <h1>Import &amp; Export</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<?php if ($result !== null): ?>
    <p class="alert alert-success">
        Import abgeschlossen: <strong><?= (int) $result['created'] ?></strong> neu angelegt,
        <strong><?= (int) $result['updated'] ?></strong> aktualisiert<?= $result['failed'] ? ', <strong>' . count($result['failed']) . '</strong> fehlgeschlagen' : '' ?>.
    </p>
    <?php if ($result['failed']): ?>
        <div class="alert alert-error"><ul>
            <?php foreach ($result['failed'] as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <a href="index.php" class="btn btn-primary">Zur Mitgliederliste</a>

<?php elseif ($preview !== null): ?>
    <h2 class="section-title">Vorschau: <?= h($preview['filename']) ?></h2>

    <div class="stat-grid">
        <?php foreach ($actionLabels as $key => $label): ?>
            <div class="stat-card">
                <span class="stat-value"><?= (int) $preview['counts'][$key] ?></span>
                <span class="stat-label"><?= h($label) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($preview['unknown']): ?>
        <p class="alert alert-warning">Diese Spalten wurden nicht erkannt und werden ignoriert:
            <?= h(implode(', ', $preview['unknown'])) ?></p>
    <?php endif; ?>

    <?php $importable = $preview['counts']['create'] + $preview['counts']['update']; ?>

    <div class="table-scroll">
    <table class="table">
        <thead>
            <tr><th>Zeile</th><th>Aktion</th><th>Name</th><th>E-Mail</th><th>Hinweis</th></tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($preview['rows'], 0, 200) as $row): ?>
            <tr>
                <td><?= (int) $row['line'] ?></td>
                <td><span class="badge badge-<?= $actionBadges[$row['action']] ?>"><?= h($actionLabels[$row['action']]) ?></span></td>
                <td><?= h(($row['data']['nachname'] ?? '') . ', ' . ($row['data']['vorname'] ?? '')) ?></td>
                <td><?= h((string) ($row['data']['email'] ?? '')) ?></td>
                <td><?= h(implode('; ', $row['errors']) ?: ($row['action'] === 'skip' ? 'Existiert bereits (Aktualisieren war nicht aktiviert)' : '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($preview['rows']) > 200): ?>
        <p class="muted">Es werden die ersten 200 von <?= count($preview['rows']) ?> Zeilen angezeigt.</p>
    <?php endif; ?>

    <div class="filter-bar" style="margin-top:16px;">
        <?php if ($importable > 0): ?>
        <form method="post" action="import.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <button type="submit" class="btn btn-primary"><?= (int) $importable ?> Zeile(n) importieren</button>
        </form>
        <?php endif; ?>
        <form method="post" action="import.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn">Abbrechen</button>
        </form>
    </div>

<?php else: ?>
    <div class="panel-grid">
        <section class="panel">
            <h2 class="section-title">Import aus CSV / Excel</h2>
            <p>Unterstützt <strong>.xlsx</strong> und <strong>.csv</strong> (Excel-CSV mit Semikolon oder Komma).
               Die erste Zeile muss die Spaltenüberschriften enthalten; Pflichtspalten sind
               <em>Nachname</em>, <em>Vorname</em> und <em>Mail</em>. Vor dem Speichern gibt es eine Vorschau.</p>

            <form method="post" action="import.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="preview">
                <div class="form-group">
                    <label for="file">Datei</label>
                    <input type="file" id="file" name="file" accept=".csv,.xlsx,.txt" required>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" name="update_existing" value="1" checked>
                        Vorhandene Mitglieder (gleiche E-Mail) aktualisieren – leere Zellen überschreiben nichts</label>
                </div>
                <button type="submit" class="btn btn-primary">Vorschau anzeigen</button>
            </form>
        </section>

        <section class="panel">
            <h2 class="section-title">Export &amp; Vorlage</h2>
            <p>Der Export enthält alle Mitgliedsdaten und lässt sich direkt in Excel öffnen –
               und nach dem Bearbeiten wieder importieren.</p>
            <div class="stack">
                <a class="btn" href="export.php">Alle Mitglieder (CSV)</a>
                <a class="btn" href="export.php?status=aktiv">Nur aktive (CSV)</a>
                <a class="btn" href="export.php?template=1">Leere Import-Vorlage (CSV)</a>
            </div>
        </section>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
