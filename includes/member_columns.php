<?php

declare(strict_types=1);

/**
 * Zentrale Spaltendefinition für Import (CSV/Excel), Export und REST-API.
 * Reihenfolge = Spaltenreihenfolge im Export.
 *
 * Typen: str | int | date | bool | status
 */
const MEMBER_IO_COLUMNS = [
    'jersey_nr' => ['Jersey Nr.', 'str'],
    'camp_1' => ['Camp 1', 'bool'],
    'spanien' => ['Spanien', 'bool'],
    'camp_2' => ['Camp 2', 'bool'],
    'tschechien' => ['Tschechien', 'bool'],
    'nachname' => ['Nachname', 'str'],
    'vorname' => ['Vorname', 'str'],
    'sz' => ['SZ', 'str'],
    'bezirk' => ['Bez.', 'str'],
    'position' => ['Position', 'str'],
    'geburtsdatum' => ['Geburtsdatum', 'date'],
    'verein' => ['Verein', 'str'],
    'groesse_cm' => ['Größe (cm)', 'int'],
    'gewicht_kg' => ['Gewicht (KG)', 'int'],
    'telefon' => ['Telefon', 'str'],
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
];

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
 * @param array<int, string> $headers
 * @return array{map: array<int, string>, unknown: array<int, string>}
 */
function io_map_headers(array $headers): array
{
    $aliases = [];
    foreach (MEMBER_IO_COLUMNS as $key => [$label]) {
        $aliases[io_normalize_header($key)] = $key;
        $aliases[io_normalize_header($label)] = $key;
    }
    // Schreibweisen aus der bisherigen Excel-Liste und häufige Varianten
    $extra = [
        'Jersy Nr.' => 'jersey_nr', 'Jersey Nr' => 'jersey_nr', 'Trikotnummer' => 'jersey_nr',
        'Email' => 'email', 'E-Mail' => 'email', 'Mail' => 'email', 'E-Mail-Adresse' => 'email',
        'Bez.' => 'bezirk', 'Pos' => 'position', 'Pos.' => 'position',
        'cm' => 'groesse_cm', 'Größe' => 'groesse_cm', 'KG' => 'gewicht_kg', 'Gewicht' => 'gewicht_kg',
        'Name Erziehungsberechtigter' => 'erz_name', 'Name Erziehungsberechtigte' => 'erz_name',
        'Rechte + Pflichten' => 'rechte_pflichten_akzeptiert', 'Rechte & Pflichten' => 'rechte_pflichten_akzeptiert',
        'Sozial Ver. Nr.' => 'sozialversicherungsnummer', 'SV-Nr' => 'sozialversicherungsnummer',
        'Helm verwendest du' => 'helm_eigener', 'Helm verwendest du (eigenen Helm)' => 'helm_eigener',
        'Game Jersey Grösse' => 'game_jersey_groesse', 'Game Hosen Grösse' => 'game_hosen_groesse',
    ];
    foreach ($extra as $alias => $key) {
        $aliases[io_normalize_header($alias)] = $aliases[io_normalize_header($alias)] ?? $key;
    }

    // Spalten der Excel-Liste, die es hier nicht als Import-Feld gibt (bewusst ohne Warnung ignoriert)
    $ignored = array_map('io_normalize_header', ['ID', 'Name & Vorname', 'Bild E-Card', 'Pass Foto']);

    $map = [];
    $unknown = [];
    foreach ($headers as $i => $header) {
        $norm = io_normalize_header((string) $header);
        if ($norm === '' || in_array($norm, $ignored, true)) {
            continue;
        }
        if (isset($aliases[$norm]) && !in_array($aliases[$norm], $map, true)) {
            $map[$i] = $aliases[$norm];
        } else {
            $unknown[] = (string) $header;
        }
    }
    return ['map' => $map, 'unknown' => $unknown];
}

/**
 * Wandelt einen Rohwert (CSV-Zelle, Excel-Zelle oder JSON-Wert) in den
 * DB-Wert um. Leere Werte ergeben null.
 *
 * @param mixed $raw
 * @return mixed
 * @throws InvalidArgumentException bei ungültigem Wert
 */
function io_parse_value(string $type, $raw, string $key = '')
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
            if (in_array($v, ['1', 'ja', 'j', 'x', 'true', 'wahr', 'yes', 'y', 'ok', '1.0'], true)) {
                return 1;
            }
            if (in_array($v, ['0', 'nein', 'n', 'false', 'falsch', 'no', '-', '0.0'], true)) {
                return 0;
            }
            throw new InvalidArgumentException("Ungültiger Ja/Nein-Wert \"{$raw}\"");

        case 'int':
            if (!preg_match('/^\d+([.,]0+)?$/', $raw)) {
                throw new InvalidArgumentException("Ungültige Zahl \"{$raw}\"");
            }
            return (int) $raw;

        case 'date':
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
 * @param array<string, mixed> $raw
 * @return array{data: array<string, mixed>, errors: array<int, string>}
 */
function io_convert_row(array $raw, bool $clearEmpty = false): array
{
    $data = [];
    $errors = [];
    foreach ($raw as $key => $value) {
        if (!isset(MEMBER_IO_COLUMNS[$key])) {
            continue;
        }
        [$label, $type] = MEMBER_IO_COLUMNS[$key];
        try {
            $parsed = io_parse_value($type, $value, $key);
        } catch (InvalidArgumentException $e) {
            $errors[] = $label . ': ' . $e->getMessage();
            continue;
        }
        if ($parsed === null && $clearEmpty) {
            if (in_array($key, ["nachname", "vorname", "email"], true)) {
                $errors[] = $label . " darf nicht leer sein";
            } elseif ($type === "bool" && $key !== "helm_eigener") {
                $data[$key] = 0;
            } elseif ($type !== "status") {
                $data[$key] = null;
            }
        } elseif ($parsed !== null) {
            if ($key === "telefon" || $key === "erz_telefon") {
                try {
                    $parsed = normalize_phone((string) $parsed);
                } catch (InvalidArgumentException $e) {
                    $errors[] = $label . ": " . $e->getMessage();
                    continue;
                }
            }
            $data[$key] = $parsed;
        }
    }

    if (isset($data['email']) && !is_valid_email((string) $data['email'])) {
        $errors[] = 'Mail: ungültige E-Mail-Adresse "' . $data['email'] . '"';
    }
    if (isset($data['erz_email']) && !is_valid_email((string) $data['erz_email'])) {
        $errors[] = 'Mail Erzieh: ungültige E-Mail-Adresse';
    }
    return ['data' => $data, 'errors' => $errors];
}

/**
 * Wert einer Mitglieds-Zeile für Export/API in Textform.
 *
 * @param array<string, mixed> $row
 */
function io_export_value(string $key, array $row): string
{
    $type = MEMBER_IO_COLUMNS[$key][1];
    $value = $row[$key] ?? null;
    if ($value === null || $value === '') {
        return '';
    }
    if ($type === 'bool') {
        return (int) $value === 1 ? 'Ja' : 'Nein';
    }
    if ($type === 'date') {
        $ts = strtotime((string) $value);
        return $ts === false ? (string) $value : date('d.m.Y', $ts);
    }
    return (string) $value;
}
