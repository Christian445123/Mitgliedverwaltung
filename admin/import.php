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
            $kaderDefault = in_array($_POST['kader_default'] ?? '', ['kader', 'nicht_im_kader'], true) ? $_POST['kader_default'] : null;

            // Für die manuelle Spaltenzuordnung merken wir uns die eingelesene Tabelle in der Sitzung
            $_SESSION['member_import_source'] = [
                'table' => $table,
                'filename' => (string) $file['name'],
                'update' => $updateExisting,
                'kader' => $kaderDefault,
            ];

            $preview = member_import_analyze($table, $updateExisting, $kaderDefault);
            $preview['filename'] = (string) $file['name'];
            $preview['update_existing'] = $updateExisting;

            $_SESSION['member_import'] = [
                'rows' => $preview['rows'],
                'update_existing' => $updateExisting,
            ];
        } elseif ($action === 'remap') {
            $source = $_SESSION['member_import_source'] ?? null;
            if (!is_array($source)) {
                throw new RuntimeException('Die Datei ist nicht mehr verfügbar. Bitte erneut hochladen.');
            }

            $overrides = [];
            foreach ((array) ($_POST['map'] ?? []) as $index => $target) {
                if (is_string($target) && ctype_digit((string) $index)) {
                    $overrides[(int) $index] = $target;
                }
            }

            $preview = member_import_analyze($source['table'], (bool) $source['update'], $source['kader'], $overrides);
            $preview['filename'] = (string) $source['filename'];
            $preview['update_existing'] = (bool) $source['update'];

            $_SESSION['member_import'] = [
                'rows' => $preview['rows'],
                'update_existing' => (bool) $source['update'],
            ];
        } elseif ($action === 'commit') {
            $pending = $_SESSION['member_import'] ?? null;
            unset($_SESSION['member_import'], $_SESSION['member_import_source']);
            if (!is_array($pending)) {
                throw new RuntimeException('Keine Import-Vorschau vorhanden. Bitte Datei erneut hochladen.');
            }
            $result = member_import_commit($pending['rows'], (bool) $pending['update_existing']);
        } elseif ($action === 'cancel') {
            unset($_SESSION['member_import'], $_SESSION['member_import_source']);
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


    <?php if ($preview['missing_required']): ?>
        <p class="alert alert-error"><strong>Pflichtfeld nicht zugeordnet:</strong> <?= h(implode(', ', $preview['missing_required'])) ?>.
            Bitte unten in der Spaltenzuordnung eine Spalte dafür auswählen.</p>
    <?php endif; ?>
    <?php if ($preview['unknown']): ?>
        <p class="alert alert-error"><strong>Diese Spalten enthalten Daten, sind aber keinem Feld zugeordnet und werden NICHT übernommen:</strong>
            <?= h(implode(', ', $preview['unknown'])) ?>.
            Ordnen Sie sie unten in der Spaltenzuordnung einem Feld zu.</p>
    <?php endif; ?>
    <?php if ($preview['counts']['warning'] > 0): ?>
        <p class="alert alert-warning"><?= (int) $preview['counts']['warning'] ?> Zeile(n) werden importiert, haben aber Hinweise
            (z. B. nicht lesbares Datum oder gekürzter Text) – siehe Spalte „Hinweis“.</p>
    <?php endif; ?>

    <form method="post" action="import.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remap">
        <details class="panel" style="margin-bottom:16px;" <?= ($preview['unknown'] || $preview['missing_required']) ? 'open' : '' ?>>
            <summary><strong>Spaltenzuordnung</strong> – jede Spalte der Datei einem Feld zuordnen (Überschriftenzeile: <?= (int) $preview['header_row'] ?>)</summary>
            <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Spalte in der Datei</th><th>Wird übernommen als</th><th>Gefüllte Zellen</th></tr></thead>
                <tbody>
                <?php foreach ($preview['columns'] as $col): ?>
                    <tr>
                        <td><?= h($col['header']) ?></td>
                        <td>
                            <select name="map[<?= (int) $col['index'] ?>]">
                                <option value="-" <?= $col['key'] === null ? 'selected' : '' ?>>— nicht importieren —</option>
                                <?php foreach ($preview['fields'] as $field): ?>
                                    <option value="<?= h($field['key']) ?>" <?= $col['key'] === $field['key'] ? 'selected' : '' ?>><?= h($field['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <?= (int) $col['values'] ?>
                            <?php if ($col['state'] === 'unmapped' && $col['values'] > 0): ?><span class="badge badge-orange">nicht zugeordnet</span><?php endif; ?>
                            <?php if ($col['state'] === 'skipped'): ?><span class="badge badge-gray">übersprungen</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Zuordnung übernehmen &amp; Vorschau aktualisieren</button>
        </details>
    </form>

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
                <td><?= h(implode('; ', $row['errors']) ?: ($row['action'] === 'skip' ? 'Existiert bereits (Aktualisieren war nicht aktiviert)' : implode('; ', $row['warnings'] ?? []))) ?></td>
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
               Die Überschriftenzeile wird automatisch gefunden (Titelzeilen darüber stören nicht); Pflichtspalten sind
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
                <div class="form-group">
                    <label>Kader-Status der importierten Spieler</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="kader_default" value="kader">
                            Alle importierten Spieler <strong>sind im Kader</strong></label>
                        <label class="radio-option"><input type="radio" name="kader_default" value="nicht_im_kader">
                            Alle importierten Spieler sind <strong>nicht im Kader</strong></label>
                        <label class="radio-option"><input type="radio" name="kader_default" value="" checked>
                            Aus der Datei übernehmen (Spalte „Kader“), sonst „Im Kader“</label>
                    </div>
                    <p class="muted">Gilt für alle Zeilen ohne eigenen Wert in einer Spalte „Kader“ – auch für bereits vorhandene Spieler, die aktualisiert werden.</p>
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
