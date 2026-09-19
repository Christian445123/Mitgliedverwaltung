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

    // Häufigste Ursache für scheiternde Updates: .git enthält Dateien, die einem anderen Benutzer
    // gehören (z.B. nach "git fetch/pull/reset" als root per SSH) - der Webserver darf dann nicht schreiben.
    $blocked = find_unwritable_git_paths($projectRoot . '/.git');
    if ($blocked !== []) {
        return ['success' => false, 'log' => $log . permission_problem_help($projectRoot, $blocked)];
    }

    $log .= "== git pull --ff-only ==\n";
    [$code, $out, $err] = run_shell_command('git pull --ff-only', $projectRoot);
    $log .= $out;
    if ($code !== 0) {
        $log .= $err . "\ngit pull fehlgeschlagen (Exit-Code {$code}).\n";
        if (stripos($err, 'permission') !== false || stripos($err, 'unpack-objects failed') !== false) {
            $log .= permission_problem_help($projectRoot, find_unwritable_git_paths($projectRoot . '/.git'));
        } else {
            $log .= "Möglicherweise ist die Historie divergiert – dann per SSH manuell prüfen (`git fetch` / `git log`).\n";
        }
        return ['success' => false, 'log' => $log];
    }

    $log .= "\n== Update abgeschlossen ==\n"
        . "Hinweis: Datenbank-Änderungen werden hier NICHT automatisch eingespielt.\n"
        . "Falls sich schema.sql geändert hat, die Änderungen manuell in die Datenbank übernehmen.\n";

    return ['success' => true, 'log' => $log];
}

/**
 * Name des Benutzers, unter dem PHP gerade läuft.
 */
function current_php_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string) $info['name'];
        }
    }
    return get_current_user() ?: 'www-data';
}

/**
 * Sucht Dateien/Ordner in .git, in die der Webserver-Benutzer nicht schreiben darf.
 *
 * @return array<int, string> die ersten Fundstellen (max. $limit)
 */
function find_unwritable_git_paths(string $gitDir, int $limit = 5): array
{
    if (!is_dir($gitDir)) {
        return [];
    }

    $found = [];
    if (!is_writable($gitDir)) {
        $found[] = $gitDir;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($gitDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $path => $info) {
            // Ordner müssen beschreibbar sein (neue Objekte); Dateien außerhalb von objects/ ebenfalls
            // (index, FETCH_HEAD, refs ...). Objekt-/Pack-Dateien sind normalerweise schreibgeschützt.
            $unwritable = $info->isDir()
                ? !is_writable((string) $path)
                : (!str_contains(str_replace(DIRECTORY_SEPARATOR, '/', (string) $path), '/objects/') && !is_writable((string) $path));
            if ($unwritable) {
                $found[] = (string) $path;
                if (count($found) >= $limit) {
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        // Nicht lesbare Ordner sind selbst ein Fund
        $found[] = $gitDir . ' (nicht lesbar)';
    }

    return $found;
}

/**
 * Verständliche Anleitung bei Rechteproblemen im Git-Ordner.
 *
 * @param array<int, string> $blocked
 */
function permission_problem_help(string $projectRoot, array $blocked): string
{
    $user = current_php_user();
    $text = "\n== Berechtigungsproblem ==\n"
        . "Der Webserver läuft als Benutzer \"{$user}\" und darf im Git-Ordner nicht schreiben.\n"
        . "Ursache: Dateien wurden zuvor von einem anderen Benutzer angelegt (typisch: git-Befehle per SSH als root).\n";

    if ($blocked !== []) {
        $text .= "Betroffen z.B.:\n";
        foreach ($blocked as $path) {
            $owner = '?';
            if (function_exists('posix_getpwuid') && is_string($path) && file_exists($path)) {
                $info = posix_getpwuid((int) fileowner($path));
                $owner = is_array($info) ? (string) $info['name'] : (string) fileowner($path);
            }
            $text .= "  - {$path} (Besitzer: {$owner})\n";
        }
    }

    $text .= "\nLösung per SSH (einmalig, als root):\n"
        . "  chown -R {$user}:{$user} " . escapeshellarg($projectRoot) . "\n"
        . "Danach git-Befehle per SSH nur noch als dieser Benutzer ausführen, z.B.:\n"
        . "  sudo -u {$user} git -C " . escapeshellarg($projectRoot) . " pull\n";

    return $text;
}
