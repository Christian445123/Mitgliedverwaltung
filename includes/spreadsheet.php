<?php

declare(strict_types=1);

/**
 * Liest eine CSV- oder XLSX-Datei als Tabelle (Liste von Zeilen, jede
 * Zeile eine Liste von Strings; Schlüssel = Zeilennummer in der Datei). Leere Zeilen werden übersprungen.
 *
 * @return array<int, array<int, string>>
 * @throws RuntimeException bei nicht lesbarer/nicht unterstützter Datei
 */
function spreadsheet_read(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        return spreadsheet_read_xlsx($path);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return spreadsheet_read_csv($path);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Das alte Excel-Format (.xls) wird nicht unterstützt. Bitte in Excel als .xlsx oder "CSV (Trennzeichen-getrennt)" speichern.');
    }
    throw new RuntimeException('Nicht unterstütztes Dateiformat. Erlaubt sind .csv und .xlsx.');
}

/**
 * @return array<int, array<int, string>>
 */
function spreadsheet_read_csv(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Datei konnte nicht gelesen werden.');
    }

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    } elseif (!mb_check_encoding($content, 'UTF-8')) {
        // Excel (Windows) speichert CSV oft in Windows-1252
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    // Trennzeichen anhand der ersten Zeile erkennen
    $firstLine = strtok($content, "\r\n") ?: '';
    $best = ';';
    $bestCount = -1;
    foreach ([';', ',', "\t"] as $candidate) {
        $count = substr_count($firstLine, $candidate);
        if ($count > $bestCount) {
            $best = $candidate;
            $bestCount = $count;
        }
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);

    $rows = [];
    $lineNo = 0;
    while (($cells = fgetcsv($stream, 0, $best, '"', '')) !== false) {
        $lineNo++;
        if ($cells === [null] || count(array_filter($cells, static fn ($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        $rows[$lineNo] = array_map(static fn ($c) => trim((string) $c), $cells);
    }
    fclose($stream);

    return $rows;
}

/**
 * Minimaler XLSX-Reader (erstes Tabellenblatt) auf Basis von ZipArchive.
 *
 * @return array<int, array<int, string>>
 */
function spreadsheet_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX-Import ist auf diesem Server nicht verfügbar (PHP-Erweiterung "zip" fehlt). Bitte als CSV speichern.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Die Excel-Datei konnte nicht geöffnet werden.');
    }

    try {
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $wb = simplexml_load_string($workbook, 'SimpleXMLElement', LIBXML_NONET);
            $rl = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
            if ($wb !== false && $rl !== false && isset($wb->sheets->sheet[0])) {
                $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                foreach ($rl->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $sheetPath = 'xl/' . ltrim((string) $rel['Target'], '/');
                        $sheetPath = str_replace('xl/xl/', 'xl/', $sheetPath);
                        break;
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('Kein Tabellenblatt in der Excel-Datei gefunden.');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $ss = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);
            if ($ss !== false) {
                foreach ($ss->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } else {
                        foreach ($si->r as $run) {
                            $text .= (string) $run->t;
                        }
                    }
                    $shared[] = $text;
                }
            }
        }
    } finally {
        $zip->close();
    }

    $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if ($sheet === false) {
        throw new RuntimeException('Das Tabellenblatt konnte nicht gelesen werden.');
    }

    $rows = [];
    $lastRowNo = 0;
    foreach ($sheet->sheetData->row as $row) {
        // Echte Excel-Zeilennummer beibehalten (leere Zeilen werden übersprungen, die Nummern bleiben stimmig)
        $rowNo = isset($row['r']) ? (int) $row['r'] : $lastRowNo + 1;
        $lastRowNo = $rowNo;

        $cells = [];
        $maxCol = -1;
        $nextCol = 0;
        foreach ($row->c as $c) {
            $col = isset($c['r']) ? spreadsheet_column_index((string) $c['r']) : $nextCol;
            $nextCol = $col + 1;
            $type = (string) $c['t'];
            if ($type === 's') {
                $value = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = '';
                if (isset($c->is->t)) {
                    $value = (string) $c->is->t;
                } else {
                    foreach ($c->is->r as $run) {
                        $value .= (string) $run->t;
                    }
                }
            } elseif ($type === 'b') {
                $value = (string) $c->v === '1' ? '1' : '0';
            } elseif ($type === 'e') {
                $value = ''; // Excel-Fehlerwerte (#N/A, #DIV/0!) sind keine Daten
            } else {
                $value = (string) $c->v;
                if ($type !== 'str' && preg_match('/^-?\d+\.0+$/', $value)) {
                    $value = (string) (int) $value; // 12.0 -> 12
                } elseif ($type !== 'str' && preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $value)) {
                    $value = sprintf('%.0f', (float) $value); // 1.23E+10 -> 12300000000
                }
            }
            $cells[$col] = trim($value);
            $maxCol = max($maxCol, $col);
        }
        if ($maxCol < 0) {
            continue;
        }
        $line = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        if (count(array_filter($line, static fn ($v) => $v !== '')) === 0) {
            continue;
        }
        $rows[$rowNo] = $line;
    }

    return $rows;
}

function spreadsheet_column_index(string $cellRef): int
{
    $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $cellRef));
    $index = 0;
    for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

/**
 * Schreibt Zeilen als Excel-taugliche CSV (UTF-8 mit BOM, Semikolon).
 *
 * @param resource $out
 * @param array<int, string> $row
 */
function spreadsheet_write_csv_row($out, array $row): void
{
    // Schutz vor CSV/Formel-Injection in Excel
    $row = array_map(static function (string $cell): string {
        return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false && !is_numeric($cell) && !preg_match('/^\+[\d\s\/()-]+$/', $cell)
            ? "'" . $cell
            : $cell;
    }, $row);
    fputcsv($out, $row, ';', '"', '');
}
