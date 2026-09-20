<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roster.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/field_access.php';

require_permission('members.export');

$type = in_array($_GET['type'] ?? '', ['ifaf', 'clothing', 'clubs', 'missing', 'expired', 'staff'], true) ? (string) $_GET['type'] : 'alpha';
$format = in_array($_GET['format'] ?? '', ['pdf', 'xlsx'], true) ? (string) $_GET['format'] : null;
$error = null;

// Prüfen (Vorschau): zeigt den Roster als Tabelle, leere Felder sind markiert
$check = null;
if (($_GET['format'] ?? '') === 'check' && !in_array($type, ['ifaf', 'missing', 'expired', 'staff'], true)) {
    $kaderCheck = in_array($_GET['kader'] ?? 'kader', ['kader', 'nicht_im_kader'], true) ? (string) ($_GET['kader'] ?? 'kader') : null;
    $statusCheck = ($_GET['status'] ?? 'aktiv') === 'alle' ? null : 'aktiv';
    $excludeCheck = field_access_admin_audience() === 'admin' ? [] : field_access_hidden_export_keys('editor');
    $check = roster_check($type, $kaderCheck, $statusCheck, $excludeCheck);
}

// IFAF-Angaben: zuletzt verwendete Werte merken
$ifaf = [
    'competition' => trim((string) ($_GET['competition'] ?? app_setting_get('ifaf_competition', 'IFAF European Championship 2026/27'))),
    'game' => trim((string) ($_GET['game'] ?? app_setting_get('ifaf_game', ''))),
    'team' => trim((string) ($_GET['team'] ?? app_setting_get('ifaf_team', 'Austria'))),
];

if ($format !== null) {
    try {
        if ($type === 'ifaf') {
            require_permission('staff.view'); // der IFAF-Roster enthält auch die Staff-Liste
            foreach ($ifaf as $key => $value) {
                $ifaf[$key] = mb_substr($value, 0, 120);
                app_setting_set('ifaf_' . $key, $ifaf[$key]);
            }
            [$contentType, $filename, $binary] = roster_generate_ifaf($format, $ifaf);
            app_log('export.roster', 'IFAF-Roster erstellt (' . $format . ')', ['team' => $ifaf['team'], 'game' => $ifaf['game']]);
        } else {
            $kader = in_array($_GET['kader'] ?? 'kader', ['kader', 'nicht_im_kader'], true) ? (string) ($_GET['kader'] ?? 'kader') : null;
            $status = ($_GET['status'] ?? 'aktiv') === 'alle' ? null : 'aktiv';
            // Bearbeiter erhalten nur die Spalten, die für sie freigegeben sind (Feld-Rechte)
            $exclude = field_access_admin_audience() === 'admin' ? [] : field_access_hidden_export_keys('editor');
            if (in_array($type, ['missing', 'expired', 'staff'], true)) {
                require_permission('staff.view'); // die Listen enthalten Spieler und Staff
            }
            $generate = ['clothing' => 'roster_generate_clothing', 'clubs' => 'roster_generate_clubs', 'missing' => 'roster_generate_missing', 'expired' => 'roster_generate_expired', 'staff' => 'roster_generate_staff'][$type] ?? 'roster_generate_alphabetical';
            [$contentType, $filename, $binary] = $generate($format, $kader, $status, $exclude);
            app_log('export.roster', (['clothing' => 'Bekleidungs-Roster', 'clubs' => 'Vereins-Roster', 'missing' => 'Liste fehlende Dokumente', 'expired' => 'Liste abgelaufene Dokumente', 'staff' => 'Staff-Roster'][$type] ?? 'Alphabetischer Roster') . ' erstellt (' . $format . ')', ['kader' => $kader, 'status' => $status]);
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store');
        echo $binary;
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        // Unerwarteter Fehler: melden statt leerer Seite (Ursache und Stelle im Protokoll)
        error_log('Roster: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        app_log('export.roster_failed', 'Roster konnte nicht erstellt werden: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()], 'error');
        $error = 'Die Liste konnte nicht erstellt werden: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}

$pageTitle = 'Roster';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Roster</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<?php if ($check !== null): ?>
<section class="panel">
    <h2 class="section-title">Prüfung: <?= h($check['title']) ?> (<?= (int) $check['total'] ?> Spieler)</h2>
    <p class="muted">So wird der Roster befüllt. <span class="cell-empty" style="padding:0 6px;border-radius:4px">Rot</span> = Feld leer.
        Fehlende Werte trägst du beim jeweiligen Spieler nach.</p>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <?php foreach ($check['table']['columns'] as $i => $c): ?>
                    <th><?= h((string) $c['label']) ?><br><small class="muted"><?= (int) $check['filled'][$i] ?> / <?= (int) $check['total'] ?> befüllt</small></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($check['table']['rows'] as $row): ?>
                <tr>
                    <?php foreach ($row as $value): ?>
                        <td<?= trim($value) === '' ? ' class="cell-empty"' : '' ?>><?= h($value) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<div class="panel-grid">
    <section class="panel">
        <h2 class="section-title">Alphabetischer Roster</h2>
        <p class="muted">Alle Spieler von A bis Z nach Nachname: Nr., Name, Position, Verein, Geburtsdatum, Größe, Gewicht.
            Als PDF (druckfertig) oder als Excel-Datei.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="alpha">
            <div class="form-group">
                <label for="kader">Welche Spieler?</label>
                <select id="kader" name="kader">
                    <option value="kader">Nur Spieler im Kader</option>
                    <option value="alle">Alle Spieler</option>
                    <option value="nicht_im_kader">Nur Spieler nicht im Kader</option>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="aktiv">Nur aktive Mitglieder</option>
                    <option value="alle">Aktive und inaktive</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
                <button type="submit" name="format" value="check" class="btn">Prüfen (Vorschau)</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">Rosterbekleidung</h2>
        <p class="muted">Alphabetische Liste für Bestellung und Ausgabe: Nachname, Vorname, Shirt, Short, Socken,
            Practice Hose, Practice Jersey Nr. und Jersey Größe.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="clothing">
            <div class="form-group">
                <label for="kader_c">Welche Spieler?</label>
                <select id="kader_c" name="kader">
                    <option value="kader">Nur Spieler im Kader</option>
                    <option value="alle">Alle Spieler</option>
                    <option value="nicht_im_kader">Nur Spieler nicht im Kader</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
                <button type="submit" name="format" value="check" class="btn">Prüfen (Vorschau)</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">Roster Vereine</h2>
        <p class="muted">Liste nach Verein sortiert (dann Nachname): ID, Nachname, Vorname, Verein.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="clubs">
            <div class="form-group">
                <label for="kader_v">Welche Spieler?</label>
                <select id="kader_v" name="kader">
                    <option value="kader">Nur Spieler im Kader</option>
                    <option value="alle">Alle Spieler</option>
                    <option value="nicht_im_kader">Nur Spieler nicht im Kader</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
                <button type="submit" name="format" value="check" class="btn">Prüfen (Vorschau)</button>
            </div>
        </form>
    </section>

    <?php if (user_can('staff.view')): ?>
    <section class="panel">
        <h2 class="section-title">Staff-Roster</h2>
        <p class="muted">Alle Trainer, Betreuer und Funktionäre von A bis Z nach Nachname: Nr., Name, Position, Geburtsdatum,
            Telefon und Mail – wie der alphabetische Roster, aber nur mit dem Staff.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="staff">
            <div class="form-group">
                <label for="status_s">Status</label>
                <select id="status_s" name="status">
                    <option value="aktiv">Nur aktive Staff-Personen</option>
                    <option value="alle">Aktive und inaktive</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if (user_can('staff.view')): ?>
    <section class="panel">
        <h2 class="section-title">Fehlende Dokumente</h2>
        <p class="muted">Liste, bei wem welche Dokumente fehlen (NADA-Zertifikat, Reisepass, E-Card, Rechte &amp; Pflichten) –
            <strong>Spieler und Staff getrennt</strong>. Staff: Rechte &amp; Pflichten. Excel mit je einem Blatt für Spieler und Staff.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="missing">
            <div class="form-group">
                <label for="kader_m">Welche Spieler?</label>
                <select id="kader_m" name="kader">
                    <option value="kader">Nur Spieler im Kader</option>
                    <option value="alle">Alle Spieler</option>
                    <option value="nicht_im_kader">Nur Spieler nicht im Kader</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">Abgelaufene Dokumente</h2>
        <p class="muted">Liste, bei wem NADA-Zertifikat oder Reisepass abgelaufen sind bzw. bald ablaufen –
            <strong>Spieler und Staff getrennt</strong>, dringendste zuerst. Staff hat nur den Reisepass.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="expired">
            <div class="form-group">
                <label for="kader_e">Welche Spieler?</label>
                <select id="kader_e" name="kader">
                    <option value="kader">Nur Spieler im Kader</option>
                    <option value="alle">Alle Spieler</option>
                    <option value="nicht_im_kader">Nur Spieler nicht im Kader</option>
                </select>
            </div>
            <div class="form-group">
                <label for="nur_e">Umfang</label>
                <select id="nur_e" name="nur">
                    <option value="">Abgelaufene und bald ablaufende</option>
                    <option value="abgelaufen">Nur bereits abgelaufene</option>
                </select>
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">Excel erstellen</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <?php if (user_can('staff.view')): ?>
    <section class="panel">
        <h2 class="section-title">IFAF-Roster</h2>
        <p class="muted">Offizielles Formular: Spielerliste (nur <strong>aktive Spieler im Kader</strong>, alphabetisch), dazu die
            Staff-Seite mit Funktion sowie Unterschriftszeilen für Chef-de-Mission und Head Coach.</p>
        <form method="get" action="roster.php">
            <input type="hidden" name="type" value="ifaf">
            <div class="form-group">
                <label for="competition">Wettbewerb</label>
                <input type="text" id="competition" name="competition" value="<?= h($ifaf['competition']) ?>" maxlength="120">
            </div>
            <div class="form-group">
                <label for="game">Spiel (Game)</label>
                <input type="text" id="game" name="game" value="<?= h($ifaf['game']) ?>" maxlength="120" placeholder="z. B. Austria v Czechia">
            </div>
            <div class="form-group">
                <label for="team">Team</label>
                <input type="text" id="team" name="team" value="<?= h($ifaf['team']) ?>" maxlength="120">
            </div>
            <div class="filter-bar">
                <button type="submit" name="format" value="pdf" class="btn btn-primary">IFAF-PDF erstellen</button>
                <button type="submit" name="format" value="xlsx" class="btn">IFAF-Excel erstellen</button>
            </div>
            <p class="muted">Die Angaben werden für das nächste Mal gemerkt. Die Funktionen (z. B. HC, OC, DC, TM) stammen aus
                dem Feld „Position“ im <a href="staff.php">Staff</a>.</p>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
