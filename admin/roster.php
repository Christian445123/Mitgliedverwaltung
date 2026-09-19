<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/roster.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/field_access.php';

require_permission('members.export');

$type = ($_GET['type'] ?? '') === 'ifaf' ? 'ifaf' : 'alpha';
$format = in_array($_GET['format'] ?? '', ['pdf', 'xlsx'], true) ? (string) $_GET['format'] : null;
$error = null;

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
            [$contentType, $filename, $binary] = roster_generate_alphabetical($format, $kader, $status, $exclude);
            app_log('export.roster', 'Alphabetischer Roster erstellt (' . $format . ')', ['kader' => $kader, 'status' => $status]);
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store');
        echo $binary;
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
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
            </div>
        </form>
    </section>

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
