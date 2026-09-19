<?php

declare(strict_types=1);

/**
 * Feld-Rechte: Welche Felder sehen/ändern
 *   - Spieler (über den persönlichen Link):  ausgeblendet | nur ansehen | ansehen & ändern
 *   - Bearbeiter (Rolle "editor" im Admin-Bereich): sichtbar | ausgeblendet
 * Administratoren sehen und ändern immer alles.
 *
 * Die Einstellungen liegen in der Tabelle "field_permissions" und gelten für alle Mitglieder.
 * Durchgesetzt wird doppelt: (1) das Formular wird ohne die gesperrten Felder ausgeliefert,
 * (2) beim Speichern werden gesperrte Felder auf den bisherigen Wert zurückgesetzt - auch bei
 * manipulierten Formulardaten (siehe field_access_overlay_post()).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/camps.php';

/**
 * Alle steuerbaren Felder: Schlüssel => [Beschriftung, Gruppe, Formularfelder (name="…"), Optionen].
 * Optionen: 'admin' => true  Feld gibt es nur im Admin-Formular (für Spieler nicht steuerbar)
 *           'core'  => true  Bearbeiter sehen das Feld immer (Stammdaten der Liste)
 *
 * @return array<string, array{0: string, 1: string, 2: array<int, string>, 3?: array<string, bool>}>
 */
function field_access_registry(): array
{
    return [
        'jersey_nr' => ['Jersey Nr.', 'Nummer & Camps', ['jersey_nr']],
        'camp_1' => ['Camp 1', 'Nummer & Camps', ['camp_1']],
        'spanien' => ['Spanien', 'Nummer & Camps', ['spanien']],
        'camp_2' => ['Camp 2', 'Nummer & Camps', ['camp_2']],
        'tschechien' => ['Tschechien', 'Nummer & Camps', ['tschechien']],
        'extra_camps' => ['Weitere Camps (neue Camps)', 'Nummer & Camps', array_merge(['camps_present', 'new_camps[]'], array_map(static fn (array $c) => 'camp[' . $c['id'] . ']', camps_all()))],

        'nachname' => ['Nachname', 'Persönliche Daten', ['nachname'], ['core' => true]],
        'vorname' => ['Vorname', 'Persönliche Daten', ['vorname'], ['core' => true]],
        'sz' => ['Selbstzahler', 'Persönliche Daten', ['sz']],
        'bezirk' => ['Bezirk', 'Persönliche Daten', ['bezirk']],
        'position' => ['Position', 'Persönliche Daten', ['position']],
        'geburtsdatum' => ['Geburtsdatum', 'Persönliche Daten', ['geburtsdatum']],
        'verein' => ['Verein', 'Persönliche Daten', ['verein']],
        'groesse_cm' => ['Größe (cm)', 'Persönliche Daten', ['groesse_cm']],
        'gewicht_kg' => ['Gewicht (kg)', 'Persönliche Daten', ['gewicht_kg']],

        'telefon' => ['Telefon Spieler', 'Kontakt', ['telefon']],
        'email' => ['Mail', 'Kontakt', ['email'], ['core' => true]],

        'erz_name' => ['Name Erziehungsberechtigter', 'Erziehungsberechtigte', ['erz_name']],
        'erz_telefon' => ['Telefon Erzieh', 'Erziehungsberechtigte', ['erz_telefon']],
        'erz_email' => ['Mail Erzieh', 'Erziehungsberechtigte', ['erz_email']],

        'rechte_pflichten_akzeptiert' => ['Rechte & Pflichten akzeptiert', 'Rechte & Pflicht', ['rechte_pflichten_akzeptiert']],
        'rechte_pflichten_dokument' => ['Rechte & Pflichten (Dokument)', 'Rechte & Pflicht', ['rechte_pflichten_dokument']],

        'bild_ecard' => ['Bild E-Card (Dokument)', 'E-Card & Sozialversicherung', ['bild_ecard']],
        'sozialversicherungsnummer' => ['Sozialversicherungsnummer', 'E-Card & Sozialversicherung', ['sozialversicherungsnummer']],

        'nada_zertifikat' => ['NADA Zertifikat', 'NADA', ['nada_zertifikat']],
        'nada_gueltig_bis' => ['NADA gültig bis', 'NADA', ['nada_gueltig_bis']],
        'nada_dokument' => ['NADA-Zertifikat (Dokument)', 'NADA', ['nada_dokument']],

        'pass_foto' => ['Pass Foto (Dokument)', 'Reisepass', ['pass_foto']],
        'reisepass_nr' => ['Reisepass Nr', 'Reisepass', ['reisepass_nr']],
        'reisepass_ausgestellt_am' => ['Reisepass ausgestellt am', 'Reisepass', ['reisepass_ausgestellt_am']],
        'reisepass_gueltig_bis' => ['Reisepass gültig bis', 'Reisepass', ['reisepass_gueltig_bis']],
        'geburtsland' => ['Geburtsland', 'Reisepass', ['geburtsland']],
        'geburtsort' => ['Geburtsort', 'Reisepass', ['geburtsort']],
        'ausstellungsbehoerde' => ['Ausstellungsbehörde', 'Reisepass', ['ausstellungsbehoerde']],

        'plz' => ['PLZ', 'Adresse', ['plz']],
        'ort' => ['Ort', 'Adresse', ['ort']],
        'strasse' => ['Straße', 'Adresse', ['strasse']],

        'essen' => ['Essen (Allergien/Wünsche)', 'Essen', ['essen']],

        'game_jersey_groesse' => ['Game Jersey Größe', 'Ausrüstungsgrößen', ['game_jersey_groesse']],
        'game_hosen_groesse' => ['Game Hosen Größe', 'Ausrüstungsgrößen', ['game_hosen_groesse']],
        'helm_groesse' => ['Helm Größe', 'Ausrüstungsgrößen', ['helm_groesse']],
        'helm_eigener' => ['Eigener Helm', 'Ausrüstungsgrößen', ['helm_eigener']],
        'tshirt_polo_groesse' => ['T-Shirt & Polo Größe', 'Ausrüstungsgrößen', ['tshirt_polo_groesse']],
        'hoodie_groesse' => ['Hoodie Größe', 'Ausrüstungsgrößen', ['hoodie_groesse']],
        'mesh_shorts_groesse' => ['Mesh Shorts Größe', 'Ausrüstungsgrößen', ['mesh_shorts_groesse']],
        'socken_groesse' => ['Socken Größe', 'Ausrüstungsgrößen', ['socken_groesse']],

        'status' => ['Status (aktiv/inaktiv)', 'Verwaltung (nur Admin-Formular)', ['status'], ['admin' => true]],
        'kader' => ['Kader', 'Verwaltung (nur Admin-Formular)', ['kader'], ['admin' => true]],
        'zimmer_nr' => ['Zimmer Nr', 'Verwaltung (nur Admin-Formular)', ['zimmer_nr'], ['admin' => true]],
        'pract_jersey_nr' => ['Pract. Jersey Nr.', 'Verwaltung (nur Admin-Formular)', ['pract_jersey_nr'], ['admin' => true]],
        'pract_hose_groesse' => ['Pract. Hose Größe', 'Verwaltung (nur Admin-Formular)', ['pract_hose_groesse'], ['admin' => true]],
    ];
}

/** Formularfelder, die Dateien hochladen (werden über $_FILES gesperrt). */
const FIELD_ACCESS_FILE_INPUTS = ['bild_ecard', 'pass_foto', 'nada_dokument', 'rechte_pflichten_dokument'];
/** Ja/Nein-Felder (Checkboxen): fehlender Wert bedeutet "nein". */
const FIELD_ACCESS_BOOL_INPUTS = ['camp_1', 'spanien', 'camp_2', 'tschechien', 'rechte_pflichten_akzeptiert', 'helm_eigener'];

function field_access_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS field_permissions (
            field_key VARCHAR(60) NOT NULL PRIMARY KEY,
            player_access ENUM(\'edit\', \'view\', \'hidden\') NOT NULL DEFAULT \'edit\',
            editor_visible TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/**
 * Aktuelle Einstellungen je Feld (fehlende Einträge = Standard: Spieler darf alles ändern,
 * Bearbeiter sieht alles).
 *
 * @return array<string, array{player: string, editor: bool}>
 */
function field_access_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $settings = [];
    foreach (field_access_registry() as $key => $def) {
        $isAdminOnly = !empty($def[3]['admin']);
        $settings[$key] = ['player' => $isAdminOnly ? 'hidden' : 'edit', 'editor' => true];
    }

    try {
        field_access_ensure_table();
        foreach (db()->query('SELECT field_key, player_access, editor_visible FROM field_permissions')->fetchAll() as $row) {
            if (isset($settings[$row['field_key']])) {
                $settings[$row['field_key']] = ['player' => (string) $row['player_access'], 'editor' => (int) $row['editor_visible'] === 1];
            }
        }
    } catch (Throwable $e) {
        // Tabelle nicht lesbar: sichere Standardwerte (siehe oben) verwenden
        error_log('field_access_settings: ' . $e->getMessage());
    }

    return $cache = $settings;
}

/**
 * @param array<string, array{player: string, editor: bool}> $settings
 */
function field_access_save(array $settings, ?string $by): void
{
    field_access_ensure_table();
    $stmt = db()->prepare(
        'INSERT INTO field_permissions (field_key, player_access, editor_visible, updated_by) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE player_access = VALUES(player_access), editor_visible = VALUES(editor_visible), updated_by = VALUES(updated_by)'
    );
    foreach (field_access_registry() as $key => $def) {
        $s = $settings[$key] ?? null;
        if ($s === null) {
            continue;
        }
        $player = in_array($s['player'], ['edit', 'view', 'hidden'], true) ? $s['player'] : 'edit';
        // Kern-Felder bleiben für Bearbeiter immer sichtbar
        $editor = !empty($def[3]['core']) ? true : (bool) $s['editor'];
        $stmt->execute([$key, $player, $editor ? 1 : 0, $by]);
    }
}

function field_access_reset(): void
{
    field_access_ensure_table();
    db()->exec('DELETE FROM field_permissions');
}

/** Zielgruppe der aktuellen Seite: 'admin' (alles), 'editor' (Bearbeiter) oder 'player' (Link). */
function field_access_admin_audience(): string
{
    return function_exists('is_administrator') && is_administrator() ? 'admin' : 'editor';
}

/**
 * Formularfelder (name="…"), die die Zielgruppe nicht sehen darf.
 *
 * @return array<int, string>
 */
function field_access_hidden_inputs(string $audience): array
{
    if ($audience === 'admin') {
        return [];
    }
    $hidden = [];
    foreach (field_access_registry() as $key => $def) {
        $s = field_access_settings()[$key];
        $isHidden = $audience === 'player'
            ? (!empty($def[3]['admin']) || $s['player'] === 'hidden')
            : !$s['editor'];
        if ($isHidden) {
            array_push($hidden, ...$def[2]);
        }
    }
    return $hidden;
}

/**
 * Sichtbare, aber nicht änderbare Formularfelder (nur für Spieler: "nur ansehen").
 *
 * @return array<int, string>
 */
function field_access_readonly_inputs(): array
{
    $readonly = [];
    foreach (field_access_registry() as $key => $def) {
        if (empty($def[3]['admin']) && field_access_settings()[$key]['player'] === 'view') {
            array_push($readonly, ...$def[2]);
        }
    }
    return $readonly;
}

/**
 * Alle Formularfelder, die die Zielgruppe NICHT ändern darf (ausgeblendet + nur ansehen).
 *
 * @return array<int, string>
 */
function field_access_locked_inputs(string $audience): array
{
    if ($audience === 'admin') {
        return [];
    }
    return array_values(array_unique(array_merge(
        field_access_hidden_inputs($audience),
        $audience === 'player' ? field_access_readonly_inputs() : []
    )));
}

/**
 * Überschreibt gesperrte Felder in $_POST/$_FILES mit dem bisherigen Wert des Mitglieds, damit sie
 * sich auch mit manipulierten Formulardaten nicht ändern lassen. Muss VOR dem Auslesen der
 * Formulardaten (member_collect_input) aufgerufen werden.
 *
 * @param array<string, mixed> $existing bisherige Mitgliedsdaten (leer bei neuem Mitglied)
 */
function field_access_overlay_post(array $existing, string $audience): void
{
    foreach (field_access_locked_inputs($audience) as $name) {
        // Weitere Camps: Formular-Marker entfernen, dann bleibt die Teilnahme unverändert
        if ($name === 'camps_present' || $name === 'new_camps[]' || str_starts_with($name, 'camp[')) {
            unset($_POST['camps_present'], $_POST['new_camps'], $_POST['camp']);
            continue;
        }

        if (in_array($name, FIELD_ACCESS_FILE_INPUTS, true)) {
            unset($_FILES[$name]); // bisheriger Dateipfad bleibt erhalten
            continue;
        }

        $value = $existing[$name] ?? null;
        if (in_array($name, FIELD_ACCESS_BOOL_INPUTS, true)) {
            if ((int) $value === 1) {
                $_POST[$name] = '1';
            } else {
                unset($_POST[$name]);
            }
            continue;
        }

        if ($value === null || $value === '') {
            unset($_POST[$name]);
        } else {
            $_POST[$name] = (string) $value;
        }
    }
}

/**
 * Entfernt gesperrte Felder aus dem fertig gerenderten Formular-HTML und setzt "nur ansehen"-Felder
 * auf schreibgeschützt. Fail-closed: ohne PHP-Erweiterung "dom" wird das Formular nicht ausgeliefert.
 */
function field_access_apply_html(string $html, string $audience): string
{
    if ($audience === 'admin') {
        return $html;
    }
    $hidden = field_access_hidden_inputs($audience);
    $readonly = $audience === 'player' ? field_access_readonly_inputs() : [];
    if ($hidden === [] && $readonly === []) {
        return $html;
    }
    if (!class_exists('DOMDocument')) {
        return '<p class="alert alert-error">Das Formular ist aus Sicherheitsgründen nicht verfügbar (PHP-Erweiterung "dom" fehlt auf dem Server).</p>';
    }

    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="fa-root">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xp = new DOMXPath($doc);

    $group = static function (DOMNode $node): ?DOMElement {
        for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p->getAttribute('id') === 'fa-root') {
                return null;
            }
            if ($p->tagName === 'div' && in_array('form-group', preg_split('/\s+/', $p->getAttribute('class')) ?: [], true)) {
                return $p;
            }
        }
        return null;
    };

    foreach ($hidden as $name) {
        foreach (iterator_to_array($xp->query('//*[@name="' . $name . '"]')) as $el) {
            $target = $group($el) ?? $el;
            $target->parentNode?->removeChild($target);
        }
    }

    foreach ($readonly as $name) {
        foreach ($xp->query('//*[@name="' . $name . '"]') as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $type = strtolower($el->getAttribute('type'));
            $el->removeAttribute('required');
            if ($el->tagName === 'select' || in_array($type, ['checkbox', 'radio', 'file'], true)) {
                $el->setAttribute('disabled', 'disabled');
            } else {
                $el->setAttribute('readonly', 'readonly');
            }
            $g = $group($el);
            if ($g !== null) {
                $g->setAttribute('class', trim($g->getAttribute('class') . ' field-locked'));
            }
        }
    }

    // Leer gewordene Zeilen und Bereiche entfernen
    foreach (iterator_to_array($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " form-row ")]')) as $row) {
        if ($xp->query('.//div[contains(concat(" ", normalize-space(@class), " "), " form-group ")]', $row)->length === 0) {
            $row->parentNode?->removeChild($row);
        }
    }
    foreach (iterator_to_array($xp->query('//fieldset')) as $fieldset) {
        if ($xp->query('.//div[contains(concat(" ", normalize-space(@class), " "), " form-group ")]', $fieldset)->length === 0) {
            $fieldset->parentNode?->removeChild($fieldset);
        }
    }

    $root = $xp->query('//div[@id="fa-root"]')->item(0);
    if ($root === null) {
        return '';
    }
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/**
 * Import-/Export-Schlüssel, die die Zielgruppe nicht sehen darf (für den CSV-Export von Bearbeitern).
 *
 * @return array<int, string>
 */
function field_access_hidden_export_keys(string $audience): array
{
    $hidden = field_access_hidden_inputs($audience);
    $keys = array_values(array_intersect(array_keys(member_io_columns()), $hidden));
    if (in_array('camps_present', $hidden, true)) {
        foreach (array_keys(member_io_columns()) as $key) {
            if (str_starts_with((string) $key, 'camp:')) {
                $keys[] = $key;
            }
        }
    }
    return $keys;
}
