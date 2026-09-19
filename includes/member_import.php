<?php

declare(strict_types=1);

require_once __DIR__ . '/member_columns.php';
require_once __DIR__ . '/spreadsheet.php';
require_once __DIR__ . '/member_repository.php';

const MEMBER_IMPORT_MAX_ROWS = 5000;

/**
 * Prüft eine eingelesene Tabelle (erste Zeile = Überschriften) und
 * bestimmt je Zeile, was beim Import passieren würde.
 *
 * Zeilen-Aktionen: create | update | skip | error
 *
 * @param array<int, array<int, string>> $table
 * @return array{rows: array<int, array<string, mixed>>, columns: array<int, string>, unknown: array<int, string>, counts: array<string, int>}
 * @throws RuntimeException wenn die Datei grundsätzlich nicht verwendbar ist
 */
function member_import_analyze(array $table, bool $updateExisting): array
{
    if (count($table) < 2) {
        throw new RuntimeException('Die Datei enthält keine Datenzeilen (erste Zeile muss die Spaltenüberschriften enthalten).');
    }
    if (count($table) - 1 > MEMBER_IMPORT_MAX_ROWS) {
        throw new RuntimeException('Zu viele Zeilen (maximal ' . MEMBER_IMPORT_MAX_ROWS . ' pro Import).');
    }

    $header = array_shift($table);
    ['map' => $map, 'unknown' => $unknown] = io_map_headers($header);

    $mapped = array_values($map);
    foreach (['nachname', 'vorname', 'email'] as $required) {
        if (!in_array($required, $mapped, true)) {
            throw new RuntimeException('Pflichtspalte fehlt: "' . MEMBER_IO_COLUMNS[$required][0] . '". Tipp: Vorlage über "Vorlage herunterladen" verwenden.');
        }
    }

    $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
    $seenEmails = [];
    $rows = [];

    foreach ($table as $i => $cells) {
        $line = $i + 2; // 1 = Überschrift
        $raw = [];
        foreach ($map as $col => $key) {
            $raw[$key] = $cells[$col] ?? '';
        }

        ['data' => $data, 'errors' => $errors] = io_convert_row($raw);

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
                $exists = member_find_by_email((string) $data['email']) !== false;
                if ($exists) {
                    $action = $updateExisting ? 'update' : 'skip';
                } else {
                    $action = 'create';
                }
            }
        }
        if ($errors !== []) {
            $action = 'error';
        }

        $counts[$action]++;
        $rows[] = ['line' => $line, 'action' => $action, 'data' => $data, 'errors' => $errors];
    }

    return ['rows' => $rows, 'columns' => array_values(array_unique($mapped)), 'unknown' => $unknown, 'counts' => $counts];
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
