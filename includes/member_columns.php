<?php

declare(strict_types=1);

require_once __DIR__ . '/camps.php';
require_once __DIR__ . '/staff.php';

/**
 * Zentrale Spaltendefinition für Import (CSV/Excel), Export und REST-API.
 * Reihenfolge = Spaltenreihenfolge im Export.
 *
 * Typen: str | int | date | bool | status | kader
 */
const MEMBER_IO_BASE_COLUMNS = [
    'jersey_nr' => ['Jersey Nr.', 'str'],
    'camp_1' => ['Camp 1', 'bool'],
    'spanien' => ['Spanien', 'bool'],
    'camp_2' => ['Camp 2', 'bool'],
    'tschechien' => ['Tschechien', 'bool'],
    'nachname' => ['Nachname', 'str'],
    'vorname' => ['Vorname', 'str'],
    'sz' => ['Selbstzahler', 'str'],
    'bezirk' => ['Bez.', 'str'],
    'position' => ['Position', 'str'],
    'geburtsdatum' => ['Geburtsdatum', 'date'],
    'verein' => ['Verein', 'str'],
    'groesse_cm' => ['Größe (cm)', 'int'],
    'gewicht_kg' => ['Gewicht (KG)', 'int'],
    'telefon' => ['Telefon Spieler', 'str'],
    'email' => ['Mail', 'str'],
    'erz_name' => ['Name Erziehungsberechtigter', 'str'],
    'erz_telefon' => ['Telefon Erzieh', 'str'],
    'erz_email' => ['Mail Erzieh', 'str'],
    'rechte_pflichten_akzeptiert' => ['Rechte & Pflicht akzeptiert', 'bool'],
    'sozialversicherungsnummer' => ['Sozial Ver. Nr.', 'str'],
    'nada_zertifikat' => ['Nada Zertifikat', 'str'],
    'nada_gueltig_bis' => ['Nada gültig bis', 'date'],
    'reisepass_nr' => ['Reisepass Nr', 'str'],
    'reisepass_ausgestellt_am' => ['Reisepass ausgestellt am', 'date'],
    'reisepass_gueltig_bis' => ['Reisepass gültig bis', 'date'],
    'geburtsland' => ['Geburtsland', 'str'],
    'geburtsort' => ['Geburtsort', 'str'],
    'ausstellungsbehoerde' => ['Ausstellungsbehörde', 'str'],
    'plz' => ['PLZ', 'str'],
    'ort' => ['Ort', 'str'],
    'strasse' => ['Straße', 'str'],
    'essen' => ['Essen', 'str'],
    'game_jersey_groesse' => ['Game Jersey Größe', 'str'],
    'game_hosen_groesse' => ['Game Hosen Größe', 'str'],
    'helm_groesse' => ['Helm Größe', 'str'],
    'helm_eigener' => ['Eigener Helm', 'bool'],
    'tshirt_polo_groesse' => ['T-Shirt & Polo Größe', 'str'],
    'hoodie_groesse' => ['Hoodie Größe', 'str'],
    'mesh_shorts_groesse' => ['Mesh Shorts Größe', 'str'],
    'socken_groesse' => ['Socken Größe', 'str'],
    'zimmer_nr' => ['Zimmer Nr', 'str'],
    'pract_jersey_nr' => ['Pract. Jersey Nr.', 'str'],
    'pract_hose_groesse' => ['Pract. Hose Größe', 'str'],
    'status' => ['Status', 'status'],
    'kader' => ['Kader', 'kader'],
];

/** Maximale Länge je Textfeld (entspricht dem Schema), um SQL-Fehler früh abzufangen. */
const MEMBER_IO_MAXLEN = [
    'jersey_nr' => 10, 'nachname' => 100, 'vorname' => 100, 'sz' => 50, 'bezirk' => 100,
    'position' => 100, 'verein' => 150, 'telefon' => 50, 'email' => 190,
    'erz_name' => 150, 'erz_telefon' => 50, 'erz_email' => 190,
    'sozialversicherungsnummer' => 20, 'nada_zertifikat' => 100, 'reisepass_nr' => 50,
    'geburtsland' => 100, 'geburtsort' => 100, 'ausstellungsbehoerde' => 150,
    'plz' => 10, 'ort' => 100, 'strasse' => 150, 'essen' => 255,
    'game_jersey_groesse' => 10, 'game_hosen_groesse' => 10, 'helm_groesse' => 10,
    'tshirt_polo_groesse' => 10, 'hoodie_groesse' => 10, 'mesh_shorts_groesse' => 10, 'socken_groesse' => 10,
    'zimmer_nr' => 20, 'pract_jersey_nr' => 10, 'pract_hose_groesse' => 10,
    'nada' => 100, 'telefon_angehoeriger' => 50, 'jacken_groesse' => 10, 'short_groesse' => 10,
    'shorts_anzahl' => 20, 'coaching_hosen_lang_groesse' => 10,
];

/**
 * Aktuelle Datenart für Import/Export: 'members' (Spieler, Standard) oder 'staff'.
 * Mit Argument setzen, ohne Argument abfragen.
 */
function io_entity(?string $set = null): string
{
    static $entity = 'members';
    if ($set !== null) {
        $entity = $set === 'staff' ? 'staff' : 'members';
    }
    return $entity;
}

/**
 * Pflichtfelder je Datenart. Spieler brauchen eine Mail (eindeutig), Staff nur Nachname und Vorname.
 *
 * @return array<int, string>
 */
function io_required_keys(): array
{
    return io_entity() === 'staff' ? ['nachname', 'vorname'] : ['nachname', 'vorname', 'email'];
}

/** Felder, die als Telefonnummer vereinheitlicht werden. */
function io_phone_keys(): array
{
    return ['telefon', 'erz_telefon', 'telefon_angehoeriger'];
}

/**
 * Alle Import-/Export-Felder: die festen Felder plus je ein Ja/Nein-Feld pro weiterem Camp
 * (Schlüssel "camp:<id>", direkt nach "Tschechien").
 *
 * @return array<string, array{0: string, 1: string}>
 */
function member_io_columns(): array
{
    // Staff hat eigene Felder (siehe includes/staff.php)
    if (io_entity() === 'staff') {
        return STAFF_IO_COLUMNS;
    }

    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $columns = [];
    foreach (MEMBER_IO_BASE_COLUMNS as $key => $definition) {
        $columns[$key] = $definition;
        if ($key === 'tschechien') {
            foreach (camps_all() as $camp) {
                $columns['camp:' . $camp['id']] = [$camp['name'], 'bool'];
            }
        }
    }
    return $cache = $columns;
}

function io_normalize_header(string $header): string
{
    $header = mb_strtolower(trim($header));
    $header = strtr($header, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', '&' => 'und']);
    $header = (string) preg_replace('/[^a-z0-9]+/', '', $header);
    // ae/oe/ue-Schreibweise (Größe = Groesse = Grösse) vereinheitlichen
    return str_replace(['ae', 'oe', 'ue'], ['a', 'o', 'u'], $header);
}

/**
 * Ordnet Spaltenüberschriften den internen Feldnamen zu (akzeptiert
 * sowohl das deutsche Label als auch den technischen Schlüssel sowie
 * die Schreibweisen aus der bisherigen Excel-Liste).
 *
 * $overrides = manuelle Zuordnung (Spaltenindex => Feldname oder '-' für "nicht importieren").
 * Sie hat Vorrang vor der automatischen Erkennung.
 *
 * @param array<int, string> $headers
 * @param array<int, string> $overrides
 * @return array{map: array<int, string>, unknown: array<int, string>, ignored: array<int, string>, skipped: array<int, string>}
 *   map = Spaltenindex => Feld; unknown = nicht zugeordnet; ignored = bewusst ohne Warnung
 *   übergangen (ID, Name & Vorname); skipped = manuell auf "nicht importieren" gesetzt
 */
function io_map_headers(array $headers, array $overrides = []): array
{
    $aliases = [];
    foreach (member_io_columns() as $key => [$label]) {
        $aliases[io_normalize_header($key)] = $key;
        $aliases[io_normalize_header($label)] = $key;
    }
    // Schreibweisen aus der bisherigen Excel-Liste und häufige Varianten
    $extra = io_entity() === 'staff' ? io_staff_aliases() : [
        'Telefon' => 'telefon', 'Tel' => 'telefon', 'Telefon Spieler' => 'telefon', 'Handy' => 'telefon',
        'Kaderstatus' => 'kader', 'Im Kader' => 'kader',
        'SZ' => 'sz', 'Selbst zahler' => 'sz',
        'Jersy Nr.' => 'jersey_nr', 'Jersey Nr' => 'jersey_nr', 'Trikotnummer' => 'jersey_nr',
        'Email' => 'email', 'E-Mail' => 'email', 'Mail' => 'email', 'E-Mail-Adresse' => 'email',
        'Bez.' => 'bezirk', 'Pos' => 'position', 'Pos.' => 'position',
        'cm' => 'groesse_cm', 'Größe' => 'groesse_cm', 'KG' => 'gewicht_kg', 'Gewicht' => 'gewicht_kg',
        'Name Erziehungsberechtigter' => 'erz_name', 'Name Erziehungsberechtigte' => 'erz_name',
        'Rechte + Pflichten' => 'rechte_pflichten_akzeptiert', 'Rechte & Pflichten' => 'rechte_pflichten_akzeptiert',
        'Sozial Ver. Nr.' => 'sozialversicherungsnummer', 'SV-Nr' => 'sozialversicherungsnummer',
        'Helm verwendest du' => 'helm_eigener', 'Helm verwendest du (eigenen Helm)' => 'helm_eigener',
        'Game Jersey Grösse' => 'game_jersey_groesse', 'Game Hosen Grösse' => 'game_hosen_groesse',
        // Schreibweisen der Bekleidungs-Liste
        'Practice Hose' => 'pract_hose_groesse', 'Practise Hose' => 'pract_hose_groesse', 'Practice Hose Größe' => 'pract_hose_groesse',
        'Practise Hose Größe' => 'pract_hose_groesse', 'Pract Hose' => 'pract_hose_groesse', 'Pract. Hose' => 'pract_hose_groesse',
        'Practice Jersey Nr.' => 'pract_jersey_nr', 'Practise Jersey Nr.' => 'pract_jersey_nr', 'Practice Jersey Nr' => 'pract_jersey_nr',
        'Practice Jersey' => 'pract_jersey_nr', 'Pract Jersey Nr' => 'pract_jersey_nr',
        'Jersey Größe' => 'game_jersey_groesse', 'Shirt' => 'tshirt_polo_groesse', 'Short' => 'mesh_shorts_groesse',
        'Shorts' => 'mesh_shorts_groesse', 'Socken' => 'socken_groesse',
    ];
    foreach ($extra as $alias => $key) {
        if (!isset(member_io_columns()[$key])) {
            continue; // Alias gehört zu einem Feld, das diese Datenart nicht hat
        }
        $aliases[io_normalize_header($alias)] = $aliases[io_normalize_header($alias)] ?? $key;
    }

    // Spalten der Excel-Liste, die es hier nicht als Import-Feld gibt (bewusst ohne Warnung ignoriert)
    $ignoredNames = array_map('io_normalize_header', ['ID', 'Name & Vorname']);

    $map = [];
    $unknown = [];
    $ignored = [];
    foreach ($headers as $i => $header) {
        $norm = io_normalize_header((string) $header);
        if ($norm === '') {
            continue;
        }
        if (in_array($norm, $ignoredNames, true)) {
            $ignored[$i] = (string) $header;
        } elseif (isset($aliases[$norm]) && !in_array($aliases[$norm], $map, true)) {
            $map[$i] = $aliases[$norm];
        } else {
            $unknown[$i] = (string) $header; // Spaltenindex => Überschrift
        }
    }

    // Manuelle Zuordnung hat Vorrang
    $skipped = [];
    foreach ($overrides as $i => $target) {
        $i = (int) $i;
        if (!isset($headers[$i]) || io_normalize_header((string) $headers[$i]) === '') {
            continue;
        }
        unset($map[$i], $unknown[$i], $ignored[$i]);
        if ($target === '-' || $target === '') {
            $skipped[$i] = (string) $headers[$i];
            continue;
        }
        if (!isset(member_io_columns()[$target])) {
            continue;
        }
        // Ein Feld kann nur aus einer Spalte kommen: andere Spalte, die es bisher hatte, wird frei
        foreach ($map as $other => $key) {
            if ($key === $target && $other !== $i) {
                unset($map[$other]);
                $unknown[$other] = (string) $headers[$other];
            }
        }
        $map[$i] = $target;
    }

    return ['map' => $map, 'unknown' => $unknown, 'ignored' => $ignored, 'skipped' => $skipped];
}

/**
 * Wandelt einen Rohwert (CSV-Zelle, Excel-Zelle oder JSON-Wert) in den
 * DB-Wert um. Leere Werte ergeben null.
 *
 * Mit $lenient (Import aus Excel) werden übliche Schreibweisen großzügig
 * akzeptiert: "185 cm", "80,5 kg", Datum mit Uhrzeit, beliebiger Text in
 * Ja/Nein-Feldern (= Ja, außer eindeutig "nein"/"kein"/"leih").
 *
 * @param mixed $raw
 * @return mixed
 * @throws InvalidArgumentException bei ungültigem Wert
 */
function io_parse_value(string $type, $raw, string $key = '', bool $lenient = false)
{
    if (is_array($raw) || is_object($raw)) {
        throw new InvalidArgumentException('Verschachtelte Werte sind nicht erlaubt');
    }
    if (is_bool($raw)) {
        $raw = $raw ? '1' : '0';
    }
    $raw = trim((string) $raw);
    if (str_starts_with($raw, "'")) {
        $raw = trim(substr($raw, 1)); // Excel-Textmarker
    }
    if ($raw === '') {
        return null;
    }

    switch ($type) {
        case 'bool':
            $v = mb_strtolower($raw);
            if (in_array($v, ['1', 'ja', 'j', 'x', 'true', 'wahr', 'yes', 'y', 'ok', '1.0', '✓', '✔', '√'], true)) {
                return 1;
            }
            if (in_array($v, ['0', 'nein', 'n', 'false', 'falsch', 'no', '-', '0.0', '–'], true)) {
                return 0;
            }
            if ($lenient) {
                foreach (['nein', 'kein', 'nicht', 'leih'] as $negative) {
                    if (str_contains($v, $negative)) {
                        return 0;
                    }
                }
                return 1; // irgendein Eintrag (Datum, "Ja, bezahlt", ...) = trifft zu
            }
            throw new InvalidArgumentException("Ungültiger Ja/Nein-Wert \"{$raw}\"");

        case 'int':
            $number = $lenient ? trim((string) preg_replace('/\s*(cm|kg|kgs|m)\.?$/i', '', $raw)) : $raw;
            if (!preg_match('/^\d+([.,]\d+)?$/', $number) || (!$lenient && !preg_match('/^\d+([.,]0+)?$/', $number))) {
                throw new InvalidArgumentException("Ungültige Zahl \"{$raw}\"");
            }
            return (int) round((float) str_replace(',', '.', $number));

        case 'date':
            if ($lenient) {
                $raw = trim((string) preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $raw)); // Uhrzeit abschneiden
            }
            // Excel-Seriennummer (z.B. 38718)
            if (preg_match('/^\d{4,6}(\.\d+)?$/', $raw) && (float) $raw > 1 && (float) $raw < 80000) {
                return gmdate('Y-m-d', (int) round(((float) $raw - 25569) * 86400));
            }
            foreach (['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y', 'Y/m/d', 'd-m-Y'] as $format) {
                $d = DateTime::createFromFormat('!' . $format, $raw);
                $errors = DateTime::getLastErrors();
                if ($d !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $d->format('Y-m-d');
                }
            }
            throw new InvalidArgumentException("Ungültiges Datum \"{$raw}\" (erwartet TT.MM.JJJJ)");

        case 'kader':
            $v = mb_strtolower($raw);
            if (in_array($v, ['kader', 'im kader', 'ja', 'j', '1', 'x', 'true', 'aktiv'], true)) {
                return 'kader';
            }
            if (in_array($v, ['nicht im kader', 'spieler nicht im kader', 'nicht_im_kader', 'nein', 'n', '0', 'false', 'kein kader'], true)) {
                return 'nicht_im_kader';
            }
            if ($lenient) {
                return str_contains($v, 'nicht') || str_contains($v, 'kein') ? 'nicht_im_kader' : 'kader';
            }
            throw new InvalidArgumentException("Ungültiger Kader-Wert \"{$raw}\" (Im Kader / Spieler nicht im Kader)");

        case 'status':
            $v = mb_strtolower($raw);
            if (in_array($v, ['aktiv', 'active', '1', 'ja'], true)) {
                return 'aktiv';
            }
            if (in_array($v, ['inaktiv', 'inactive', '0', 'nein'], true)) {
                return 'inaktiv';
            }
            throw new InvalidArgumentException("Ungültiger Status \"{$raw}\" (aktiv/inaktiv)");

        default:
            if (isset(MEMBER_IO_MAXLEN[$key]) && mb_strlen($raw) > MEMBER_IO_MAXLEN[$key]) {
                throw new InvalidArgumentException('Wert zu lang (max. ' . MEMBER_IO_MAXLEN[$key] . " Zeichen) in \"{$raw}\"");
            }
            return $raw;
    }
}

/**
 * Konvertiert eine Zeile aus [Feldname => Rohwert] in DB-Werte. Leere
 * Zellen werden ausgelassen (überschreiben beim Update also nichts);
 * mit $clearEmpty (API-Änderungen) leeren sie das Feld stattdessen.
 *
 * $lenient (Import): Formatprobleme in optionalen Feldern verwerfen NICHT die
 * Zeile, sondern werden als Warnung gemeldet - zu lange Texte werden gekürzt,
 * nicht erkennbare Telefonnummern unverändert übernommen. Fehler gibt es dann
 * nur bei Nachname, Vorname und Mail.
 *
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
 */
function io_convert_row(array $raw, bool $clearEmpty = false, bool $lenient = false): array
{
    $data = [];
    $errors = [];
    $warnings = [];
    $required = io_required_keys();

    foreach ($raw as $key => $value) {
        if (!isset(member_io_columns()[$key])) {
            continue;
        }
        [$label, $type] = member_io_columns()[$key];
        $isRequired = in_array($key, $required, true);

        // Zu lange Texte kürzen statt die Zeile abzulehnen (nur Import)
        if ($lenient && !$isRequired && $type === 'str' && isset(MEMBER_IO_MAXLEN[$key]) && is_scalar($value)
            && mb_strlen(trim((string) $value)) > MEMBER_IO_MAXLEN[$key]) {
            $warnings[] = $label . ': auf ' . MEMBER_IO_MAXLEN[$key] . ' Zeichen gekürzt';
            $value = mb_substr(trim((string) $value), 0, MEMBER_IO_MAXLEN[$key]);
        }

        try {
            $parsed = io_parse_value($type, $value, $key, $lenient);
        } catch (InvalidArgumentException $e) {
            if ($lenient && !$isRequired) {
                $warnings[] = $label . ': ' . $e->getMessage() . ' - Wert nicht übernommen';
            } else {
                $errors[] = $label . ': ' . $e->getMessage();
            }
            continue;
        }

        if ($parsed === null && $clearEmpty) {
            if ($isRequired) {
                $errors[] = $label . ' darf nicht leer sein';
            } elseif ($type === 'bool' && $key !== 'helm_eigener') {
                $data[$key] = 0;
            } elseif (!in_array($type, ['status', 'kader'], true)) {
                $data[$key] = null;
            }
        } elseif ($parsed !== null) {
            if (in_array($key, io_phone_keys(), true)) {
                try {
                    $parsed = normalize_phone((string) $parsed);
                } catch (InvalidArgumentException $e) {
                    if (!$lenient) {
                        $errors[] = $label . ': ' . $e->getMessage();
                        continue;
                    }
                    $warnings[] = $label . ': "' . $parsed . '" nicht als Nummer erkannt - unverändert übernommen';
                }
            }
            $data[$key] = $parsed;
        }
    }

    if (isset($data['email']) && !is_valid_email((string) $data['email'])) {
        $errors[] = 'Mail: ungültige E-Mail-Adresse "' . $data['email'] . '"';
    }
    if (isset($data['erz_email']) && !is_valid_email((string) $data['erz_email'])) {
        $message = 'Mail Erzieh: "' . $data['erz_email'] . '" ist keine gültige E-Mail-Adresse';
        if ($lenient) {
            $warnings[] = $message . ' - unverändert übernommen';
        } else {
            $errors[] = $message;
        }
    }

    return ['data' => $data, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * Wert einer Mitglieds-Zeile für Export/API in Textform.
 *
 * @param array<string, mixed> $row
 */
function io_export_value(string $key, array $row): string
{
    $type = member_io_columns()[$key][1];
    $value = $row[$key] ?? null;
    if ($value === null || $value === '') {
        return '';
    }
    if ($type === 'bool') {
        return (int) $value === 1 ? 'Ja' : 'Nein';
    }
    if ($type === 'kader') {
        return $value === 'nicht_im_kader' ? 'Spieler nicht im Kader' : 'Im Kader';
    }
    if ($type === 'date') {
        $ts = strtotime((string) $value);
        return $ts === false ? (string) $value : date('d.m.Y', $ts);
    }
    return (string) $value;
}

/**
 * Zusätzliche Überschriften-Schreibweisen für die Staff-Liste (Excel-Spalten des Vereins).
 *
 * @return array<string, string>
 */
function io_staff_aliases(): array
{
    return [
        'Telefon Angehoeriger' => 'telefon_angehoeriger', 'Tel. Angehöriger' => 'telefon_angehoeriger', 'Notfallkontakt' => 'telefon_angehoeriger',
        'Telefonnummer Angehörige' => 'telefon_angehoeriger', 'Telefonnummer Angehoerige' => 'telefon_angehoeriger', 'Telefon Angehörige' => 'telefon_angehoeriger', 'Telefon Erzieh' => 'telefon_angehoeriger', 'Telefon Erzieher' => 'telefon_angehoeriger',
        'Email' => 'email', 'E-Mail' => 'email', 'Handy' => 'telefon', 'Tel' => 'telefon',
        'Funktion' => 'position', 'Pos' => 'position', 'Rolle' => 'position',
        'NADA' => 'nada', 'NADA Gültigkeit' => 'nada_gueltig_bis', 'NADA gültig bis' => 'nada_gueltig_bis', 'Straße' => 'strasse', 'Strasse' => 'strasse', 'Ort' => 'ort',
        'T-Shirt / Polo Grösse' => 'tshirt_polo_groesse', 'T-Shirt & Polo Größe' => 'tshirt_polo_groesse',
        'Hoodie Grösse' => 'hoodie_groesse', 'Jacken Grösse' => 'jacken_groesse', 'Short Grösse' => 'short_groesse',
        'Wie viele Shorts besitzt du' => 'shorts_anzahl', 'Anzahl Shorts' => 'shorts_anzahl',
        'Coaching Hosen (lang) Grösse' => 'coaching_hosen_lang_groesse', 'Coaching Hosen lang Grösse' => 'coaching_hosen_lang_groesse',
    ];
}
