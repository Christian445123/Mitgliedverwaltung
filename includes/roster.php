<?php

declare(strict_types=1);

/**
 * Roster (alphabetische Spielerliste) als Excel (.xlsx) und PDF.
 *
 * Ohne externe Bibliotheken: Excel wird als ZIP aus XML-Dateien geschrieben (ZipArchive),
 * das PDF direkt geschrieben (Standardschrift Helvetica, A4 hochkant, mit Seitenumbruch).
 * Sortierung: Nachname, dann Vorname (A-Z).
 */

require_once __DIR__ . '/functions.php';

/** Titel der Liste (.env: TEAM_NAME, z. B. "AFBÖ U19"). */
function roster_team_name(): string
{
    $name = trim((string) getenv('TEAM_NAME'));
    return $name !== '' ? $name : 'AFBÖ U19';
}

/**
 * Spalten des Rosters. 'key' verweist auf die Mitgliedsfelder ("name" = "Nachname Vorname").
 *
 * @return array<int, array<string, mixed>>
 */
function roster_columns(): array
{
    return [
        ['key' => 'jersey_nr', 'label' => 'Nr.', 'width' => 11.0, 'align' => 'C', 'xl' => 7.0],
        ['key' => 'name', 'label' => 'Name', 'width' => 48.0, 'align' => 'L', 'xl' => 30.0],
        ['key' => 'position', 'label' => 'Position', 'width' => 38.0, 'align' => 'L', 'xl' => 18.0],
        ['key' => 'verein', 'label' => 'Verein', 'width' => 32.0, 'align' => 'L', 'xl' => 24.0],
        ['key' => 'geburtsdatum', 'label' => 'Geboren', 'width' => 22.0, 'align' => 'C', 'xl' => 12.0],
        ['key' => 'groesse_cm', 'label' => 'Größe (cm)', 'pdf_label' => 'Größe', 'width' => 16.0, 'align' => 'R', 'xl' => 11.0],
        ['key' => 'gewicht_kg', 'label' => 'Gewicht (kg)', 'pdf_label' => 'Gewicht', 'width' => 19.0, 'align' => 'R', 'xl' => 13.0],
    ];
}

/**
 * Baut die Tabelle für den Roster: Kopfzeile, Zeilen (nur Text) und die verwendeten Spalten.
 *
 * @param array<int, array<string, mixed>> $members   bereits alphabetisch sortiert
 * @param array<int, string> $excludeKeys             Spalten, die nicht ausgegeben werden dürfen (Feld-Rechte)
 * @return array{columns: array<int, array<string, mixed>>, rows: array<int, array<int, string>>}
 */
function roster_table(array $members, array $excludeKeys = [], ?array $allColumns = null): array
{
    $hiddenForName = in_array('nachname', $excludeKeys, true) || in_array('vorname', $excludeKeys, true);
    $columns = array_values(array_filter(
        $allColumns ?? roster_columns(),
        static fn (array $c) => $c['key'] === 'name' ? !$hiddenForName : !in_array($c['key'], $excludeKeys, true)
    ));

    $rows = [];
    foreach (array_values($members) as $i => $m) {
        $line = [];
        foreach ($columns as $c) {
            $value = match ($c['key']) {
                'lfd' => (string) ($i + 1),
                'name' => member_full_name($m),
                'geburtsdatum' => !empty($m['geburtsdatum']) ? date('d.m.Y', (int) strtotime((string) $m['geburtsdatum'])) : '',
                default => trim((string) ($m[$c['key']] ?? '')),
            };
            $line[] = $value;
        }
        $rows[] = $line;
    }

    return ['columns' => $columns, 'rows' => $rows];
}

/** Zeichen für XML (Steuerzeichen entfernen, Sonderzeichen maskieren). */
function roster_xml(string $text): string
{
    $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Spaltenbuchstabe: 0 = A, 1 = B, ... */
function roster_xl_col(int $index): string
{
    $letters = '';
    for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
        $letters = chr(65 + ($i - 1) % 26) . $letters;
    }
    return $letters;
}

/** Zellenstile der Excel-Datei (siehe roster_xlsx_sheet: 0 normal, 1 Titel, 2 Untertitel, 3 Kopfzeile, 4-6 Zellen, 7-9 mit Zebra). */
function roster_xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF11152A"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF11152A"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F8"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE3E5EC"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * XML eines Tabellenblatts (Titel, Untertitel, Kopfzeile, Zeilen mit Zebra, fixierte Kopfzeile, Filter).
 *
 * @param array{columns: array<int, array<string, mixed>>, rows: array<int, array<int, string>>} $table
 * @return array{0: string, 1: int} [XML, Nummer der Kopfzeile]
 */
function roster_xlsx_sheet(array $table, string $title, string $subtitle): array
{
    $columns = $table['columns'];
    $lastCol = roster_xl_col(max(0, count($columns) - 1));
    $numeric = ['no', 'jersey_nr', 'groesse_cm', 'gewicht_kg'];

    $cell = static function (int $col, int $row, string $value, int $style, bool $number = false): string {
        $ref = roster_xl_col($col) . $row;
        if ($value === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        if ($number && preg_match('/^\d+(\.\d+)?$/', $value)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . roster_xml($value) . '</t></is></c>';
    };

    $sheetRows = '<row r="1" ht="26" customHeight="1">' . $cell(0, 1, $title, 1) . '</row>';
    $sheetRows .= '<row r="2">' . $cell(0, 2, $subtitle, 2) . '</row>';

    $headerRow = 4;
    $headerCells = '';
    foreach ($columns as $i => $c) {
        $headerCells .= $cell($i, $headerRow, (string) $c['label'], 3);
    }
    $sheetRows .= '<row r="' . $headerRow . '" ht="30" customHeight="1">' . $headerCells . '</row>';

    $r = $headerRow;
    foreach ($table['rows'] as $n => $line) {
        $r++;
        $zebra = $n % 2 === 1 ? 3 : 0; // Zebra: +3 auf die Stile 4-6
        $cells = '';
        foreach ($columns as $i => $c) {
            $align = $c['align'] === 'C' ? 5 : ($c['align'] === 'R' ? 6 : 4);
            $cells .= $cell($i, $r, (string) $line[$i], $align + $zebra, in_array($c['key'], $numeric, true));
        }
        $sheetRows .= '<row r="' . $r . '">' . $cells . '</row>';
    }
    $lastRow = max($headerRow, $r);

    $cols = '';
    foreach ($columns as $i => $c) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $c['xl'] . '" customWidth="1"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
        . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A' . ($headerRow + 1) . '" sqref="A' . ($headerRow + 1) . '"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<autoFilter ref="A' . $headerRow . ':' . $lastCol . $lastRow . '"/>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
        . '<headerFooter><oddFooter>&amp;LStand ' . date('d.m.Y') . '&amp;RSeite &amp;P von &amp;N</oddFooter></headerFooter>'
        . '</worksheet>';

    return [$xml, $headerRow];
}

/**
 * Excel-Datei (.xlsx) als Binärstring.
 *
 * @param array{columns: array<int, array<string, mixed>>, rows: array<int, array<int, string>>} $table
 * @param array<int, array{name: string, table: array<string, mixed>, title: string, subtitle: string}> $extraSheets weitere Tabellenblätter
 * @throws RuntimeException wenn die PHP-Erweiterung "zip" fehlt
 */
function roster_build_xlsx(array $table, string $title, string $subtitle, string $sheetName = 'Roster', array $extraSheets = []): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Der Excel-Export ist auf diesem Server nicht verfügbar (PHP-Erweiterung "zip" fehlt).');
    }

    $sheets = array_merge(
        [['name' => $sheetName, 'table' => $table, 'title' => $title, 'subtitle' => $subtitle]],
        $extraSheets
    );

    $files = [];
    $overrides = '';
    $sheetTags = '';
    $sheetRels = '';
    $names = '';
    foreach ($sheets as $i => $s) {
        $n = $i + 1;
        [$xml, $headerRow] = roster_xlsx_sheet($s['table'], $s['title'], $s['subtitle']);
        $files['xl/worksheets/sheet' . $n . '.xml'] = $xml;
        $safeName = str_replace(['\\', '/', '?', '*', '[', ']', ':', "'"], '', mb_substr($s['name'], 0, 31));
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetTags .= '<sheet name="' . roster_xml($safeName) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $sheetRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">\'' . $safeName . '\'!$' . $headerRow . ':$' . $headerRow . '</definedName>';
    }
    $stylesId = count($sheets) + 1;

    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $overrides
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetTags . '</sheets>'
        . '<definedNames>' . $names . '</definedNames>'
        . '</workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $sheetRels
        . '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $files['xl/styles.xml'] = roster_xlsx_styles();

    $tmp = tempnam(sys_get_temp_dir(), 'roster');
    if ($tmp === false) {
        throw new RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Excel-Datei konnte nicht erstellt werden.');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    $binary = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

/** Breite eines Zeichens der Standardschrift Helvetica in 1/1000 Punkt (Näherung für Umlaute). */
function roster_pdf_char_width(string $ch): int
{
    static $w = null;
    if ($w === null) {
        $w = [];
        $table = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
            '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584,
            '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778,
            'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278,
            '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556,
            'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
            'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500,
            'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
        ];
        foreach ($table as $c => $width) {
            $w[$c] = $width;
        }
        foreach (str_split('0123456789') as $d) {
            $w[$d] = 556;
        }
    }
    if (isset($w[$ch])) {
        return $w[$ch];
    }
    // Umlaute/Akzente ~ Grundbuchstabe, ß breiter
    return match ($ch) {
        'Ä', 'Á', 'À', 'Â' => 667, 'Ö', 'Ó', 'Ò', 'Ô' => 778, 'Ü', 'Ú', 'Ù', 'Û' => 722, 'ß' => 611,
        default => 556,
    };
}

/** Textbreite in Punkt. */
function roster_pdf_text_width(string $text, float $size, bool $bold = false): float
{
    $sum = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $sum += roster_pdf_char_width($ch);
    }
    return $sum / 1000 * $size * ($bold ? 1.06 : 1.0);
}

/** Kürzt Text mit "…" (als "...") auf die verfügbare Breite. */
function roster_pdf_fit(string $text, float $size, float $maxWidth, bool $bold = false): string
{
    if (roster_pdf_text_width($text, $size, $bold) <= $maxWidth) {
        return $text;
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    while ($chars !== [] && roster_pdf_text_width(implode('', $chars) . '...', $size, $bold) > $maxWidth) {
        array_pop($chars);
    }
    return implode('', $chars) . '...';
}

/** UTF-8 -> Windows-1252, als PDF-String maskiert. */
function roster_pdf_string(string $text): string
{
    $converted = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($text, 'Windows-1252', 'UTF-8')
        : (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    return '(' . str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $converted) . ')';
}

/**
 * PDF (A4 hochkant) als Binärstring: Titel, Tabelle mit Kopfzeile auf jeder Seite, Seitenzahlen.
 *
 * @param array{columns: array<int, array<string, mixed>>, rows: array<int, array<int, string>>} $table
 */
function roster_build_pdf(array $table, string $title, string $subtitle): string
{
    $mm = 72 / 25.4;
    $pageW = 210 * $mm;
    $pageH = 297 * $mm;
    $margin = 12 * $mm;
    $usable = $pageW - 2 * $margin;
    $rowH = 6.6 * $mm;
    $fontSize = 9.0;
    $columns = $table['columns'];
    $rows = $table['rows'];

    // Spaltenbreiten auf die nutzbare Breite skalieren
    $totalWidth = array_sum(array_map(static fn (array $c) => (float) $c['width'], $columns)) ?: 1.0;
    $widths = array_map(static fn (array $c) => (float) $c['width'] / $totalWidth * $usable, $columns);

    $firstTop = 34 * $mm;   // Abstand vom oberen Rand bis zur Kopfzeile auf Seite 1
    $otherTop = 18 * $mm;   // ... auf Folgeseiten
    $bottom = 16 * $mm;

    // Zeilen auf Seiten verteilen
    $pages = [];
    $i = 0;
    $total = count($rows);
    do {
        $top = $pages === [] ? $firstTop : $otherTop;
        $capacity = max(1, (int) floor(($pageH - $top - $bottom - $rowH) / $rowH));
        $pages[] = array_slice($rows, $i, $capacity);
        $i += $capacity;
    } while ($i < $total);
    $pageCount = count($pages);

    $rg = static fn (int $r, int $g, int $b): string => sprintf('%.3f %.3f %.3f rg', $r / 255, $g / 255, $b / 255);
    $text = static function (float $x, float $y, float $size, bool $bold, string $s, string $color = '0 0 0 rg'): string {
        return sprintf("%s BT /%s %.2f Tf %.2f %.2f Td %s Tj ET\n", $color, $bold ? 'F2' : 'F1', $size, $x, $y, roster_pdf_string($s));
    };

    $contents = [];
    foreach ($pages as $p => $pageRows) {
        $c = '';
        $top = $p === 0 ? $firstTop : $otherTop;

        if ($p === 0) {
            $c .= $text($margin, $pageH - 18 * $mm, 20, true, $title, $rg(0x11, 0x15, 0x2A));
            $c .= $text($margin, $pageH - 25 * $mm, 10, false, $subtitle, $rg(0x6B, 0x72, 0x80));
            $c .= $rg(0xF9, 0x73, 0x16) . sprintf(" %.2f %.2f %.2f %.2f re f\n", $margin, $pageH - 28 * $mm, 24 * $mm, 1.2 * $mm);
        } else {
            $c .= $text($margin, $pageH - 12 * $mm, 10, true, $title, $rg(0x11, 0x15, 0x2A));
        }

        // Kopfzeile
        $y = $pageH - $top - $rowH;
        $c .= $rg(0x11, 0x15, 0x2A) . sprintf(" %.2f %.2f %.2f %.2f re f\n", $margin, $y, $usable, $rowH);
        $x = $margin;
        foreach ($columns as $k => $col) {
            $label = roster_pdf_fit((string) ($col['pdf_label'] ?? $col['label']), $fontSize, $widths[$k] - 4 * $mm, true);
            $tw = roster_pdf_text_width($label, $fontSize, true);
            $tx = $col['align'] === 'R' ? $x + $widths[$k] - 2 * $mm - $tw : ($col['align'] === 'C' ? $x + ($widths[$k] - $tw) / 2 : $x + 2 * $mm);
            $c .= $text($tx, $y + 2.0 * $mm, $fontSize, true, $label, '1 1 1 rg');
            $x += $widths[$k];
        }

        // Datenzeilen
        foreach ($pageRows as $n => $line) {
            $y -= $rowH;
            if ($n % 2 === 1) {
                $c .= $rg(0xF3, 0xF4, 0xF8) . sprintf(" %.2f %.2f %.2f %.2f re f\n", $margin, $y, $usable, $rowH);
            }
            $c .= '0.890 0.898 0.925 RG 0.4 w ' . sprintf("%.2f %.2f m %.2f %.2f l S\n", $margin, $y, $margin + $usable, $y);
            $x = $margin;
            foreach ($columns as $k => $col) {
                $value = roster_pdf_fit((string) $line[$k], $fontSize, $widths[$k] - 4 * $mm);
                $tw = roster_pdf_text_width($value, $fontSize);
                $tx = $col['align'] === 'R' ? $x + $widths[$k] - 2 * $mm - $tw : ($col['align'] === 'C' ? $x + ($widths[$k] - $tw) / 2 : $x + 2 * $mm);
                $c .= $text($tx, $y + 2.0 * $mm, $fontSize, false, $value);
                $x += $widths[$k];
            }
        }

        // Fußzeile
        $footerLeft = 'Stand ' . date('d.m.Y') . ' - ' . $total . ' Spieler';
        $footerRight = 'Seite ' . ($p + 1) . ' von ' . $pageCount;
        $c .= $text($margin, 9 * $mm, 8, false, $footerLeft, $rg(0x6B, 0x72, 0x80));
        $c .= $text($pageW - $margin - roster_pdf_text_width($footerRight, 8), 9 * $mm, 8, false, $footerRight, $rg(0x6B, 0x72, 0x80));

        $contents[] = $c;
    }

    // Objekte zusammenbauen: 1 Katalog, 2 Seitenbaum, 3/4 Schriften, danach je Seite (Seite, Inhalt)
    $objects = [];
    $kids = [];
    foreach ($contents as $p => $content) {
        $pageObj = 5 + $p * 2;
        $kids[] = $pageObj . ' 0 R';
        $objects[$pageObj] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
            $pageW,
            $pageH,
            $pageObj + 1
        );
        $objects[$pageObj + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = max(array_keys($objects)) + 1;
    $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
    for ($n = 1; $n < $count; $n++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
    }
    $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info << /Title " . roster_pdf_string($title) . " /Producer (Mitgliederverwaltung) >> >>\nstartxref\n{$xref}\n%%EOF\n";

    return $pdf;
}

/**
 * Erzeugt einen alphabetischen Roster und liefert [Inhaltstyp, Dateiname, Binärdaten].
 *
 * @param 'pdf'|'xlsx' $format
 * @param array<int, string> $excludeKeys Spalten, die nicht ausgegeben werden dürfen
 * @return array{0: string, 1: string, 2: string}
 */
function roster_generate_alphabetical(string $format, ?string $kader, ?string $status, array $excludeKeys = []): array
{
    require_once __DIR__ . '/member_repository.php';

    $members = member_all($status, $kader); // SQL: ORDER BY nachname, vorname
    $table = roster_table($members, $excludeKeys);
    $title = 'Roster ' . roster_team_name();
    $scope = $kader === 'kader' ? 'Spieler im Kader' : ($kader === 'nicht_im_kader' ? 'Spieler nicht im Kader' : 'Alle Spieler');
    $subtitle = 'Alphabetisch nach Nachname · ' . $scope . ' · ' . count($members) . ' Spieler · Größe in cm, Gewicht in kg';
    $base = 'roster-alphabetisch-' . date('Y-m-d');

    if ($format === 'xlsx') {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $base . '.xlsx', roster_build_xlsx($table, $title, $subtitle)];
    }
    return ['application/pdf', $base . '.pdf', roster_build_pdf($table, $title, $subtitle)];
}

/** Spalten des Bekleidungs-Rosters (Größen für Bestellung/Ausgabe). */
function roster_columns_clothing(): array
{
    return [
        ['key' => 'lfd', 'label' => 'Nr.', 'width' => 10.0, 'align' => 'C', 'xl' => 6.0],
        ['key' => 'nachname', 'label' => 'Nachname', 'width' => 34.0, 'align' => 'L', 'xl' => 22.0],
        ['key' => 'vorname', 'label' => 'Vorname', 'width' => 32.0, 'align' => 'L', 'xl' => 20.0],
        ['key' => 'tshirt_polo_groesse', 'label' => 'Shirt', 'width' => 15.0, 'align' => 'C', 'xl' => 9.0],
        ['key' => 'mesh_shorts_groesse', 'label' => 'Short', 'width' => 15.0, 'align' => 'C', 'xl' => 9.0],
        ['key' => 'socken_groesse', 'label' => 'Socken', 'width' => 17.0, 'align' => 'C', 'xl' => 10.0],
        ['key' => 'pract_hose_groesse', 'label' => 'Practice Hose', 'pdf_label' => 'Pr. Hose', 'width' => 19.0, 'align' => 'C', 'xl' => 14.0],
        ['key' => 'pract_jersey_nr', 'label' => 'Practice Jersey Nr.', 'pdf_label' => 'Pr. Nr.', 'width' => 17.0, 'align' => 'C', 'xl' => 18.0],
        ['key' => 'game_jersey_groesse', 'label' => 'Jersey Größe', 'pdf_label' => 'Jersey', 'width' => 19.0, 'align' => 'C', 'xl' => 13.0],
    ];
}

/**
 * Erzeugt den Bekleidungs-Roster (alphabetisch) und liefert [Inhaltstyp, Dateiname, Binärdaten].
 *
 * @param array<int, string> $excludeKeys Spalten, die nicht ausgegeben werden dürfen
 * @return array{0: string, 1: string, 2: string}
 */
function roster_generate_clothing(string $format, ?string $kader, ?string $status, array $excludeKeys = []): array
{
    require_once __DIR__ . '/member_repository.php';

    $members = member_all($status, $kader);
    $table = roster_table($members, $excludeKeys, roster_columns_clothing());
    $title = 'Rosterbekleidung ' . roster_team_name();
    $scope = $kader === 'kader' ? 'Spieler im Kader' : ($kader === 'nicht_im_kader' ? 'Spieler nicht im Kader' : 'Alle Spieler');
    $subtitle = 'Alphabetisch nach Nachname · ' . $scope . ' · ' . count($members) . ' Spieler';
    $base = 'roster-bekleidung-' . date('Y-m-d');

    if ($format === 'xlsx') {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $base . '.xlsx', roster_build_xlsx($table, $title, $subtitle, 'Bekleidung')];
    }
    return ['application/pdf', $base . '.pdf', roster_build_pdf($table, $title, $subtitle)];
}

// ══════════════════════════════════════════════════════════════════════════
// Allgemeiner PDF-Baukasten (A4 hochkant, Standardschrift Helvetica) und IFAF-Roster
// ══════════════════════════════════════════════════════════════════════════

/**
 * Minimaler PDF-Schreiber. Koordinaten in Millimetern von links oben.
 */
final class RosterPdf
{
    private const MM = 72 / 25.4;

    private float $pageW;
    private float $pageH;
    /** @var array<int, string> Inhalt je Seite */
    private array $pages = [];
    private int $current = -1;
    /** @var array<string, array{w: int, h: int, cs: string, data: string}> */
    private array $images = [];

    public function __construct()
    {
        $this->pageW = 210 * self::MM;
        $this->pageH = 297 * self::MM;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    private function emit(string $ops): void
    {
        $this->pages[$this->current] .= $ops;
    }

    /** @param array<int, int> $c */
    private static function rgb(array $c): string
    {
        return sprintf('%.3f %.3f %.3f', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    /**
     * Text mit Grundlinie bei $yMm. $align: L links, C zentriert, R rechts (innerhalb $widthMm ab $xMm).
     *
     * @param array<int, int> $rgb
     */
    public function text(float $xMm, float $yMm, string $s, float $size = 9.0, bool $bold = false, string $align = 'L', ?float $widthMm = null, array $rgb = [0, 0, 0]): void
    {
        if ($s === '') {
            return;
        }
        $tw = roster_pdf_text_width($s, $size, $bold) / self::MM;
        $x = $xMm;
        if ($widthMm !== null && $align === 'C') {
            $x = $xMm + ($widthMm - $tw) / 2;
        } elseif ($widthMm !== null && $align === 'R') {
            $x = $xMm + $widthMm - $tw;
        }
        $this->emit(sprintf(
            "%s rg BT /%s %.2f Tf %.2f %.2f Td %s Tj ET\n",
            self::rgb($rgb),
            $bold ? 'F2' : 'F1',
            $size,
            $x * self::MM,
            $this->pageH - $yMm * self::MM,
            roster_pdf_string($s)
        ));
    }

    /** @param array<int, int> $rgb */
    public function line(float $x1, float $y1, float $x2, float $y2, float $lineWidth = 0.4, array $rgb = [0, 0, 0], bool $dotted = false): void
    {
        $this->emit(sprintf(
            "%s RG %.2f w %s %.2f %.2f m %.2f %.2f l S [] 0 d\n",
            self::rgb($rgb),
            $lineWidth,
            $dotted ? '[1 2] 0 d' : '[] 0 d',
            $x1 * self::MM,
            $this->pageH - $y1 * self::MM,
            $x2 * self::MM,
            $this->pageH - $y2 * self::MM
        ));
    }

    /**
     * Rechteck (links oben $x/$y) mit optionaler Füllung und/oder Rahmen.
     *
     * @param array<int, int>|null $fill
     * @param array<int, int>|null $stroke
     */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.4): void
    {
        $ops = '';
        if ($fill !== null) {
            $ops .= self::rgb($fill) . ' rg ';
        }
        if ($stroke !== null) {
            $ops .= self::rgb($stroke) . ' RG ' . sprintf('%.2f w ', $lineWidth);
        }
        $ops .= sprintf('%.2f %.2f %.2f %.2f re ', $x * self::MM, $this->pageH - ($y + $h) * self::MM, $w * self::MM, $h * self::MM);
        $ops .= $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->emit($ops . "\n");
    }

    /** Registriert ein JPEG-Bild. Liefert false, wenn die Daten kein gültiges JPEG sind. */
    public function addJpeg(string $name, string $data): bool
    {
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            return false;
        }
        $channels = (int) ($info['channels'] ?? 3);
        $this->images[$name] = [
            'w' => (int) $info[0],
            'h' => (int) $info[1],
            'cs' => $channels === 1 ? '/DeviceGray' : ($channels === 4 ? '/DeviceCMYK' : '/DeviceRGB'),
            'data' => $data,
        ];
        return true;
    }

    public function image(string $name, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$name])) {
            return;
        }
        $this->emit(sprintf(
            "q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
            $w * self::MM,
            $h * self::MM,
            $x * self::MM,
            $this->pageH - ($y + $h) * self::MM,
            $name
        ));
    }

    public function output(string $title): string
    {
        // Objekte: 1 Katalog, 2 Seitenbaum, 3/4 Schriften, danach Bilder, danach je Seite (Seite, Inhalt)
        $objects = [];
        $next = 5;
        $imageRefs = [];
        foreach ($this->images as $name => $img) {
            $objects[$next] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $img['w'],
                $img['h'],
                $img['cs'],
                strlen($img['data']),
                $img['data']
            );
            $imageRefs[] = '/' . $name . ' ' . $next . ' 0 R';
            $next++;
        }
        $xobjects = $imageRefs === [] ? '' : ' /XObject << ' . implode(' ', $imageRefs) . ' >>';

        $kids = [];
        foreach ($this->pages as $content) {
            $pageObj = $next++;
            $contentObj = $next++;
            $kids[] = $pageObj . ' 0 R';
            $objects[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> /Contents %d 0 R >>',
                $this->pageW,
                $this->pageH,
                $xobjects,
                $contentObj
            );
            $objects[$contentObj] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R /Info << /Title " . roster_pdf_string($title) . " /Producer (Mitgliederverwaltung) >> >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}

/**
 * Kopf einer IFAF-Seite: Wettbewerb, Überschrift, Spiel, Team und Logo.
 *
 * @param array{competition: string, game: string, team: string} $opt
 */
function roster_ifaf_header(RosterPdf $pdf, array $opt, string $heading, bool $logo): void
{
    $pdf->text(21, 21, (string) $opt['competition'], 15, true);
    $pdf->text(21, 29.5, $heading, 12, true);

    $pdf->text(21, 41, 'Game:', 9, true);
    $pdf->text(44, 41, (string) $opt['game'], 14, true, 'C', 68);
    $pdf->line(44, 42.4, 112, 42.4, 0.6);

    $pdf->text(21, 50.5, 'Team:', 9, true);
    $pdf->text(44, 50.5, (string) $opt['team'], 14, true, 'C', 68);
    $pdf->line(44, 51.9, 112, 51.9, 0.6);

    if ($logo) {
        $pdf->image('Logo', 134, 20, 42, 28.6);
    }
}

/**
 * Zeichnet eine Tabelle mit Gitterlinien.
 *
 * @param array<int, array{w: float, head: string, align: string, bold?: bool}> $cols  Kopf mit "\n" = zweizeilig
 * @param array<int, array<int, string>> $rows
 * @return float y-Position (mm) unter der Tabelle
 */
function roster_ifaf_table(RosterPdf $pdf, float $x, float $y, array $cols, array $rows, float $headH, float $rowH, float $fontSize): float
{
    // Kopfzeile
    $cx = $x;
    foreach ($cols as $c) {
        $pdf->rect($cx, $y, $c['w'], $headH, null, [0, 0, 0], 0.5);
        $lines = explode("\n", $c['head']);
        $baseY = $y + $headH / 2 + (count($lines) === 1 ? 1.1 : -0.7);
        foreach ($lines as $i => $line) {
            $pdf->text($cx, $baseY + $i * 3.2, $line, $fontSize, true, 'C', $c['w']);
        }
        $cx += $c['w'];
    }

    // Zeilen
    $ry = $y + $headH;
    foreach ($rows as $row) {
        $cx = $x;
        foreach ($cols as $i => $c) {
            $pdf->rect($cx, $ry, $c['w'], $rowH, null, [0, 0, 0], 0.4);
            $value = roster_pdf_fit((string) ($row[$i] ?? ''), $fontSize, ($c['w'] - 2) * 72 / 25.4, !empty($c['bold']));
            $pdf->text($c['align'] === 'L' ? $cx + 1.0 : $cx, $ry + $rowH / 2 + 1.05, $value, $fontSize, !empty($c['bold']), $c['align'], $c['w']);
            $cx += $c['w'];
        }
        $ry += $rowH;
    }

    return $ry;
}

/**
 * IFAF-Roster als PDF: Spielerliste (ggf. mehrere Seiten), Staff-Seite und Unterschriftszeilen.
 *
 * @param array<int, array<string, mixed>> $players alphabetisch nach Nachname
 * @param array<int, array<string, mixed>> $staff   in Funktionsreihenfolge
 * @param array{competition: string, game: string, team: string} $opt
 */
function roster_ifaf_pdf(array $players, array $staff, array $opt, ?string $logoPath): string
{
    $pdf = new RosterPdf();
    $hasLogo = false;
    if ($logoPath !== null && is_file($logoPath)) {
        $hasLogo = $pdf->addJpeg('Logo', (string) file_get_contents($logoPath));
    }

    // ── Spielerseite(n) ─────────────────────────────────────────────
    $playerCols = [
        ['w' => 8.0, 'head' => 'No.', 'align' => 'C'],
        ['w' => 12.0, 'head' => "Jersey\nNo.", 'align' => 'C'],
        ['w' => 28.0, 'head' => 'Last name', 'align' => 'L'],
        ['w' => 32.0, 'head' => 'First name(s)', 'align' => 'L'],
        ['w' => 24.0, 'head' => 'Date of Birth', 'align' => 'C'],
        ['w' => 15.0, 'head' => 'Position', 'align' => 'C', 'bold' => true],
        ['w' => 14.0, 'head' => "Height\n(cm)", 'align' => 'C'],
        ['w' => 14.0, 'head' => "Weight\n(kg)", 'align' => 'C'],
        ['w' => 37.0, 'head' => 'Club Team', 'align' => 'L', 'bold' => true],
    ];
    $playerRows = [];
    foreach ($players as $i => $m) {
        $playerRows[] = [
            (string) ($i + 1),
            trim((string) ($m['jersey_nr'] ?? '')),
            trim((string) ($m['nachname'] ?? '')),
            trim((string) ($m['vorname'] ?? '')),
            !empty($m['geburtsdatum']) ? date('d.m.Y', (int) strtotime((string) $m['geburtsdatum'])) : '',
            trim((string) ($m['position'] ?? '')),
            trim((string) ($m['groesse_cm'] ?? '')),
            trim((string) ($m['gewicht_kg'] ?? '')),
            trim((string) ($m['verein'] ?? '')),
        ];
    }

    $rowH = 4.7;
    $headH = 9.0;
    $left = 13.0;
    $firstTop = 58.0;
    $otherTop = 20.0;
    $bottom = 14.0;

    $offset = 0;
    $total = count($playerRows);
    $first = true;
    do {
        $pdf->addPage();
        if ($first) {
            roster_ifaf_header($pdf, $opt, $total . ' player roster', $hasLogo);
        } else {
            $pdf->text($left, 14, (string) $opt['competition'] . ' - player roster (continued)', 10, true);
        }
        $top = $first ? $firstTop : $otherTop;
        $capacity = max(1, (int) floor((297 - $top - $bottom - $headH) / $rowH));
        roster_ifaf_table($pdf, $left, $top, $playerCols, array_slice($playerRows, $offset, $capacity), $headH, $rowH, 8.0);
        $offset += $capacity;
        $first = false;
    } while ($offset < $total);

    // ── Staff-Seite ────────────────────────────────────────────────
    $staffCols = [
        ['w' => 12.0, 'head' => 'Nr.', 'align' => 'C'],
        ['w' => 25.0, 'head' => 'Function', 'align' => 'L', 'bold' => true],
        ['w' => 38.0, 'head' => 'Last Name', 'align' => 'L', 'bold' => true],
        ['w' => 55.0, 'head' => 'First Name', 'align' => 'C', 'bold' => true],
    ];
    $staffRows = [];
    foreach ($staff as $i => $p) {
        $staffRows[] = [(string) ($i + 1), trim((string) ($p['position'] ?? '')), trim((string) ($p['nachname'] ?? '')), trim((string) ($p['vorname'] ?? ''))];
    }

    $pdf->addPage();
    roster_ifaf_header($pdf, $opt, 'Staff', $hasLogo);
    $pdf->text(21, 66, 'Coaches and staff', 9, true);

    $staffTop = 69.0;
    $staffRowH = 5.0;
    $capacity = max(1, (int) floor((297 - $staffTop - 100) / $staffRowH)); // Platz für Unterschriften lassen
    $shown = array_slice($staffRows, 0, $capacity);
    $end = roster_ifaf_table($pdf, 21, $staffTop, $staffCols, $shown, 7.0, $staffRowH, 7.5);

    $sigY = max($end + 28, 190.0);
    $pdf->text(13, $sigY - 22, 'I state that all information is correct:', 9, true);
    $pdf->line(13, $sigY, 92, $sigY, 0.5, [0, 0, 0], true);
    $pdf->line(118, $sigY, 197, $sigY, 0.5, [0, 0, 0], true);
    $pdf->text(13, $sigY + 4.5, 'Signature of Chef-de-Mission', 8.5, true, 'C', 79);
    $pdf->text(118, $sigY + 4.5, 'Signature of head coach', 8.5, true, 'C', 79);

    // Weitere Staff-Zeilen (falls mehr als auf die Seite passen)
    $rest = array_slice($staffRows, $capacity);
    while ($rest !== []) {
        $pdf->addPage();
        $pdf->text(21, 14, (string) $opt['competition'] . ' - staff (continued)', 10, true);
        $cap = max(1, (int) floor((297 - 20 - 14 - 7) / $staffRowH));
        roster_ifaf_table($pdf, 21, 20, $staffCols, array_slice($rest, 0, $cap), 7.0, $staffRowH, 7.5);
        $rest = array_slice($rest, $cap);
    }

    return $pdf->output('IFAF Roster ' . $opt['team']);
}

/**
 * Reihenfolge der Staff-Funktionen wie im IFAF-Formular (Cheftrainer zuerst, Betreuer zuletzt),
 * unbekannte Funktionen danach alphabetisch.
 *
 * @param array<int, array<string, mixed>> $staff
 * @return array<int, array<string, mixed>>
 */
function roster_staff_sort(array $staff): array
{
    $order = ['HC', 'ST', 'OC', 'DC', 'QB', 'RB', 'WR', 'OL', 'DL', 'LB', 'DB', 'K', 'TM', 'TM ASS', 'ASS TM'];
    $weight = static function (array $p) use ($order): int {
        $pos = mb_strtoupper(trim((string) ($p['position'] ?? '')));
        $i = array_search($pos, $order, true);
        return $i === false ? 100 : (int) $i;
    };
    usort($staff, static function (array $a, array $b) use ($weight): int {
        return [$weight($a), mb_strtolower((string) $a['nachname']), mb_strtolower((string) $a['vorname'])]
            <=> [$weight($b), mb_strtolower((string) $b['nachname']), mb_strtolower((string) $b['vorname'])];
    });
    return $staff;
}

/**
 * IFAF-Roster (Spieler im Kader + aktiver Staff) als PDF oder Excel: [Inhaltstyp, Dateiname, Binärdaten].
 *
 * @param 'pdf'|'xlsx' $format
 * @param array{competition: string, game: string, team: string} $opt
 * @return array{0: string, 1: string, 2: string}
 */
function roster_generate_ifaf(string $format, array $opt): array
{
    require_once __DIR__ . '/member_repository.php';
    require_once __DIR__ . '/staff.php';

    $players = member_all('aktiv', 'kader'); // Nachname, Vorname (A-Z)
    $staff = roster_staff_sort(staff_all('aktiv'));
    $base = 'IFAF-Roster-' . preg_replace('/[^A-Za-z0-9]+/', '-', $opt['team']) . '-' . date('Y-m-d');

    if ($format === 'xlsx') {
        $pRows = [];
        foreach ($players as $i => $m) {
            $pRows[] = [
                (string) ($i + 1), trim((string) ($m['jersey_nr'] ?? '')), trim((string) $m['nachname']), trim((string) $m['vorname']),
                !empty($m['geburtsdatum']) ? date('d.m.Y', (int) strtotime((string) $m['geburtsdatum'])) : '',
                trim((string) ($m['position'] ?? '')), trim((string) ($m['groesse_cm'] ?? '')), trim((string) ($m['gewicht_kg'] ?? '')),
                trim((string) ($m['verein'] ?? '')),
            ];
        }
        $playerTable = ['columns' => [
            ['key' => 'no', 'label' => 'No.', 'align' => 'C', 'xl' => 6.0],
            ['key' => 'jersey_nr', 'label' => 'Jersey No.', 'align' => 'C', 'xl' => 10.0],
            ['key' => 'nachname', 'label' => 'Last name', 'align' => 'L', 'xl' => 22.0],
            ['key' => 'vorname', 'label' => 'First name(s)', 'align' => 'L', 'xl' => 26.0],
            ['key' => 'geburtsdatum', 'label' => 'Date of Birth', 'align' => 'C', 'xl' => 14.0],
            ['key' => 'position', 'label' => 'Position', 'align' => 'C', 'xl' => 10.0],
            ['key' => 'groesse_cm', 'label' => 'Height (cm)', 'align' => 'C', 'xl' => 9.0],
            ['key' => 'gewicht_kg', 'label' => 'Weight (kg)', 'align' => 'C', 'xl' => 9.0],
            ['key' => 'verein', 'label' => 'Club Team', 'align' => 'L', 'xl' => 26.0],
        ], 'rows' => $pRows];

        $sRows = [];
        foreach ($staff as $i => $p) {
            $sRows[] = [(string) ($i + 1), trim((string) ($p['position'] ?? '')), trim((string) $p['nachname']), trim((string) $p['vorname'])];
        }
        $staffTable = ['columns' => [
            ['key' => 'no', 'label' => 'Nr.', 'align' => 'C', 'xl' => 6.0],
            ['key' => 'position', 'label' => 'Function', 'align' => 'L', 'xl' => 14.0],
            ['key' => 'nachname', 'label' => 'Last Name', 'align' => 'L', 'xl' => 24.0],
            ['key' => 'vorname', 'label' => 'First Name', 'align' => 'L', 'xl' => 30.0],
        ], 'rows' => $sRows];

        $head = $opt['competition'] . ' · Game: ' . $opt['game'] . ' · Team: ' . $opt['team'];
        $binary = roster_build_xlsx($playerTable, count($players) . ' player roster', $head, 'Players', [
            ['name' => 'Staff', 'table' => $staffTable, 'title' => 'Staff - Coaches and staff', 'subtitle' => $head],
        ]);
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $base . '.xlsx', $binary];
    }

    $logo = dirname(__DIR__) . '/assets/ifaf-logo.jpg';
    return ['application/pdf', $base . '.pdf', roster_ifaf_pdf($players, $staff, $opt, is_file($logo) ? $logo : null)];
}
