<?php

declare(strict_types=1);

require_once __DIR__ . '/member_columns.php';
require_once __DIR__ . '/spreadsheet.php';
require_once __DIR__ . '/member_repository.php';

const MEMBER_IMPORT_MAX_ROWS = 5000;

/**
 * Findet die Überschriftenzeile: unter den ersten 15 Zeilen diejenige, in der die
 * meisten Spalten erkannt werden (Titelzeilen über der Tabelle sind dadurch egal).
 *
 * @param array<int, array<int, string>> $table Zeilennummer => Zellen
 * @return int|null Zeilennummer der Überschriftenzeile
 */
function member_import_find_header(array $table): ?int
{
    $best = null;
    $bestCount = 0;
    $checked = 0;
    foreach ($table as $rowNo => $cells) {
        if (++$checked > 15) {
            break;
        }
        $count = count(io_map_headers($cells)['map']);
        if ($count > $bestCount) {
            $best = $rowNo;
            $bestCount = $count;
        }
    }
    return $bestCount >= 3 ? $best : null;
}

/**
 * Prüft eine eingelesene Tabelle und bestimmt je Zeile, was beim Import passieren würde.
 * Alle Spalten mit erkannter Überschrift werden übernommen; Spalten, die nicht
 * zugeordnet werden können, aber Werte enthalten, werden ausdrücklich gemeldet.
 *
 * $kaderDefault: 'kader' oder 'nicht_im_kader' (Auswahl im Import-Dialog), null = nichts erzwingen.
 * $overrides: manuelle Spaltenzuordnung (Spaltenindex => Feldname, '-' = nicht importieren).
 *
 * Zeilen-Aktionen: create | update | skip | error
 *
 * @param array<int, array<int, string>> $table Zeilennummer (wie in Excel) => Zellen
 * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, unknown: array<int, string>, counts: array<string, int>, header_row: int}
 * @throws RuntimeException wenn die Datei grundsätzlich nicht verwendbar ist
 */
function member_import_analyze(array $table, bool $updateExisting, ?string $kaderDefault = null, array $overrides = []): array
{
    // Ohne erkannte Überschriften gilt die erste Zeile als Überschriftenzeile; die Spalten lassen sich dann manuell zuordnen
    $headerRow = member_import_find_header($table) ?? (int) array_key_first($table);

    $header = $table[$headerRow];
    $dataRows = array_filter($table, static fn (int $rowNo) => $rowNo > $headerRow, ARRAY_FILTER_USE_KEY);
    if (count($dataRows) === 0) {
        throw new RuntimeException('Unter der Überschriftenzeile (Zeile ' . $headerRow . ') stehen keine Daten.');
    }
    if (count($dataRows) > MEMBER_IMPORT_MAX_ROWS) {
        throw new RuntimeException('Zu viele Zeilen (maximal ' . MEMBER_IMPORT_MAX_ROWS . ' pro Import).');
    }

    ['map' => $map, 'unknown' => $unknownCols, 'ignored' => $ignoredCols, 'skipped' => $skippedCols] = io_map_headers($header, $overrides);

    $mapped = array_values($map);
    $missing = [];
    foreach (['nachname', 'vorname', 'email'] as $required) {
        if (!in_array($required, $mapped, true)) {
            $missing[] = MEMBER_IO_COLUMNS[$required][0];
        }
    }

    // Spaltenübersicht (für die manuelle Zuordnung): jede Spalte der Überschriftenzeile mit Status
    $clean = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));
    $columns = [];
    $unknown = [];
    foreach ($header as $col => $title) {
        if (trim((string) $title) === '') {
            continue;
        }
        $filled = 0;
        foreach ($dataRows as $cells) {
            if (trim((string) ($cells[$col] ?? '')) !== '') {
                $filled++;
            }
        }

        $state = 'unmapped';
        $key = null;
        if (isset($map[$col])) {
            $state = 'mapped';
            $key = $map[$col];
        } elseif (isset($skippedCols[$col])) {
            $state = 'skipped';
        } elseif (isset($ignoredCols[$col])) {
            $state = 'ignored';
        } elseif ($filled > 0) {
            $unknown[] = $clean((string) $title) . ' (' . $filled . ' Werte)';
        }

        $columns[] = [
            'index' => (int) $col,
            'header' => $clean((string) $title),
            'key' => $key,
            'field' => $key !== null ? MEMBER_IO_COLUMNS[$key][0] : null,
            'values' => $filled,
            'state' => $state,
        ];
    }

    $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warning' => 0];
    $seenEmails = [];
    $rows = [];

    foreach ($dataRows as $line => $cells) {
        // Jede Zelle wird über ihren Spaltenindex genau der Überschrift derselben Spalte zugeordnet
        $raw = [];
        foreach ($map as $col => $key) {
            $raw[$key] = $cells[$col] ?? '';
        }

        ['data' => $data, 'errors' => $errors, 'warnings' => $warnings] = io_convert_row($raw, false, true);

        // Gewählter Kader-Status gilt für alle Zeilen, die keinen eigenen Wert in der Datei haben
        if ($kaderDefault !== null && !isset($data['kader'])) {
            $data['kader'] = $kaderDefault;
        }

        foreach (['nachname', 'vorname', 'email'] as $required) {
            if (!isset($data[$required])) {
                $errors[] = MEMBER_IO_COLUMNS[$required][0] . ' fehlt';
            }
        }

        $action = 'error';
        if ($errors === []) {
            $email = mb_strtolower((string) $data['email']);
            if (isset($seenEmails[$email])) {
                $errors[] = 'E-Mail-Adresse kommt in Zeile ' . $seenEmails[$email] . ' bereits vor';
            } else {
                $seenEmails[$email] = $line;
                $action = member_find_by_email((string) $data['email']) !== false
                    ? ($updateExisting ? 'update' : 'skip')
                    : 'create';
            }
        }
        if ($errors !== []) {
            $action = 'error';
        }

        $counts[$action]++;
        if ($warnings !== [] && ($action === 'create' || $action === 'update')) {
            $counts['warning']++;
        }
        $rows[] = ['line' => $line, 'action' => $action, 'data' => $data, 'errors' => $errors, 'warnings' => $warnings];
    }

    return [
        'rows' => $rows,
        'columns' => $columns,
        'unknown' => $unknown,
        'counts' => $counts,
        'header_row' => $headerRow,
        'missing_required' => $missing,
        'fields' => array_map(static fn (string $k) => ['key' => $k, 'label' => MEMBER_IO_COLUMNS[$k][0]], array_keys(MEMBER_IO_COLUMNS)),
    ];
}

/**
 * Führt einen zuvor analysierten Import aus. Fehlerhafte/übersprungene
 * Zeilen werden nicht geschrieben; jedes Mitglied läuft in eigener Transaktion.
 *
 * @param array<int, array<string, mixed>> $rows Ergebnis von member_import_analyze()['rows']
 * @return array{created: int, updated: int, failed: array<int, string>}
 */
function member_import_commit(array $rows, bool $updateExisting): array
{
    $result = ['created' => 0, 'updated' => 0, 'failed' => []];

    foreach ($rows as $row) {
        if ($row['action'] !== 'create' && $row['action'] !== 'update') {
            continue;
        }
        try {
            $outcome = member_import_save($row['data'], $updateExisting);
            $result[$outcome === 'created' ? 'created' : 'updated']++;
        } catch (Throwable $e) {
            $result['failed'][] = 'Zeile ' . $row['line'] . ': ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Datenbankfehler');
        }
    }

    return $result;
}

/**
 * Schreibt Mitglieder als Excel-taugliche CSV (Semikolon, UTF-8 mit BOM).
 * Ohne $rows wird nur die Kopfzeile geschrieben (Import-Vorlage).
 *
 * @param resource $out
 * @param array<int, array<string, mixed>> $rows
 */
function member_export_csv($out, array $rows): void
{
    fwrite($out, "\xEF\xBB\xBF");
    // "ID" steht wie in der bisherigen Excel-Liste vorn; beim Import wird die Spalte ignoriert.
    spreadsheet_write_csv_row($out, array_merge(['ID'], array_map(static fn (array $c) => $c[0], array_values(MEMBER_IO_COLUMNS))));
    foreach ($rows as $row) {
        $line = [(string) ($row['id'] ?? '')];
        foreach (array_keys(MEMBER_IO_COLUMNS) as $key) {
            $line[] = io_export_value($key, $row);
        }
        spreadsheet_write_csv_row($out, $line);
    }
}
