<?php

declare(strict_types=1);

/**
 * Logik für das Aktualisieren der Anwendung per `git pull` (genutzt vom
 * Update-Button im Admin-Bereich, admin/update.php).
 */

/**
 * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
 */
function run_shell_command(string $command, string $cwd): array
{
    if (!function_exists('proc_open')) {
        return [1, '', 'proc_open() ist auf diesem Server deaktiviert – Updates sind daher nur per SSH/Kommandozeile möglich.'];
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        return [1, '', "Konnte Befehl nicht starten: {$command}"];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

/**
 * Führt das Update durch: prüft auf lokale Änderungen an bereits
 * versionierten Dateien und macht dann `git pull --ff-only`. Bricht
 * kontrolliert ab (success = false), statt irgendetwas zu überschreiben,
 * falls solche Änderungen vorliegen oder der Pull nicht als reines
 * Fast-Forward möglich ist.
 *
 * Rein unversionierte Dateien (z. B. hochgeladene Uploads, alte manuell
 * kopierte Dateien) blockieren den Pull NICHT - das entspricht dem
 * Verhalten von `git pull` selbst, das nur bei einer echten Kollision mit
 * einer eingehenden Datei abbricht. Sie werden lediglich im Log aufgelistet.
 *
 * @return array{success: bool, log: string}
 */
function perform_update(string $projectRoot): array
{
    $log = "== Prüfe auf lokale Änderungen ==\n";
    [$code, $out, $err] = run_shell_command('git status --porcelain', $projectRoot);
    if ($code !== 0) {
        return ['success' => false, 'log' => $log . "Fehler beim Prüfen des Git-Status (ist das Projektverzeichnis ein Git-Checkout?).\n{$err}"];
    }

    $lines = array_filter(explode("\n", trim($out)), static fn (string $line) => $line !== '');
    $trackedChanges = [];
    $untracked = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '??')) {
            $untracked[] = $line;
        } else {
            $trackedChanges[] = $line;
        }
    }

    if ($trackedChanges !== []) {
        return ['success' => false, 'log' => $log . "Abgebrochen: nicht committete Änderungen an versionierten Dateien:\n"
            . implode("\n", $trackedChanges) . "\n\n"
            . "Bitte zuerst sichern/committen oder verwerfen, dann erneut versuchen.\n"];
    }

    if ($untracked !== []) {
        $log .= "Unversionierte Dateien (blockieren den Pull nicht, werden ignoriert):\n"
            . implode("\n", $untracked) . "\n\n";
    } else {
        $log .= "OK, keine lokalen Änderungen.\n\n";
    }

    $log .= "== git pull --ff-only ==\n";
    [$code, $out, $err] = run_shell_command('git pull --ff-only', $projectRoot);
    $log .= $out;
    if ($code !== 0) {
        $log .= $err . "\ngit pull fehlgeschlagen (Exit-Code {$code}). Möglicherweise ist die Historie"
            . " divergiert – dann per SSH manuell prüfen (`git fetch` / `git log`).\n";
        return ['success' => false, 'log' => $log];
    }

    $log .= "\n== Update abgeschlossen ==\n"
        . "Hinweis: Datenbank-Änderungen werden hier NICHT automatisch eingespielt.\n"
        . "Falls sich schema.sql geändert hat, die Änderungen manuell in die Datenbank übernehmen.\n";

    return ['success' => true, 'log' => $log];
}
