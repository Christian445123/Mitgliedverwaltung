<?php

declare(strict_types=1);

/**
 * Einrichtung der Verschlüsselung (einmal auf dem Server ausführen, danach bei Bedarf erneut):
 *
 *   php tools/setup-encryption.php            richtet alles ein
 *   php tools/setup-encryption.php --show-key zeigt nur den Verschlüsselungsschlüssel für die Desktop-App
 *
 * Was passiert:
 *   1. Ein Masterschlüssel wird erzeugt und AUSSERHALB des Webverzeichnisses abgelegt
 *      (Standard: Ordner ".u19-keys" neben dem Anwendungsordner, oder Pfad aus APP_KEY_FILE).
 *   2. Geheimnisse in der .env (Datenbank-Passwort, SMTP-Passwort, Tokens) werden verschlüsselt ("enc:v1:...").
 *      Wer nur die .env besitzt, kann damit nichts anfangen.
 *   3. Die Datenbankverbindung wird mit TLS getestet; bei Erfolg wird DB_SSL=true gesetzt.
 *   4. Bereits hochgeladene Dokumente werden verschlüsselt.
 *   5. Der Verschlüsselungsschlüssel für die Desktop-App wird angezeigt.
 *
 * WICHTIG: Den Ordner mit dem Schlüssel (Datei master.key) zusätzlich sicher sichern, z. B. im Passwortmanager.
 * Ohne diesen Schlüssel sind die verschlüsselten Passwörter und Dokumente nicht mehr lesbar.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/crypto.php';

$root = dirname(__DIR__);
$envFile = $root . '/.env';

/** @return array<string, string> Roh-Zeilen: Name => Wert (wie in der Datei) */
function env_raw(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $t, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}

// APP_KEY_FILE aus der .env berücksichtigen
$raw = is_readable($envFile) ? env_raw($envFile) : [];
if (!empty($raw['APP_KEY_FILE'])) {
    putenv('APP_KEY_FILE=' . $raw['APP_KEY_FILE']);
}

if (in_array('--show-key', $argv, true)) {
    echo "Verschlüsselungsschlüssel für die Desktop-App:\n" . crypto_transport_key_b64() . "\n";
    exit(0);
}

if (!is_writable($envFile)) {
    fwrite(STDERR, "Die .env ist nicht beschreibbar: {$envFile}\n");
    exit(1);
}

// 1. Schlüssel
$keyFile = crypto_key_create();
echo "1. Masterschlüssel: {$keyFile}\n";
if (str_starts_with(realpath($keyFile) ?: $keyFile, realpath($root) ?: $root)) {
    echo "   WARNUNG: Die Schlüsseldatei liegt im Anwendungsordner. Bitte APP_KEY_FILE auf einen Pfad außerhalb des Webverzeichnisses setzen.\n";
}

// 2. .env verschlüsseln
$secretNames = static fn (string $n): bool => (bool) preg_match('/(^DB_PASS$|PASSWORD|SECRET|TOKEN|API_KEY)/i', $n);
$lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
$encrypted = [];
foreach ($lines as $i => $line) {
    $t = trim($line);
    if ($t === '' || $t[0] === '#' || !str_contains($t, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $t, 2);
    $k = trim($k);
    $v = trim(trim($v), "\"'");
    if ($v === '' || !$secretNames($k) || str_starts_with($v, CRYPTO_ENV_PREFIX)) {
        continue;
    }
    $enc = crypto_env_encode($v);
    if (crypto_env_decode($enc) !== $v) {
        fwrite(STDERR, "Prüfung der Verschlüsselung für {$k} fehlgeschlagen.\n");
        exit(1);
    }
    $lines[$i] = $k . '=' . $enc;
    $encrypted[] = $k;
}
$set = static function (string $name, string $value) use (&$lines): void {
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*=/', $line)) {
            $lines[$i] = $name . '=' . $value;
            return;
        }
    }
    $lines[] = $name . '=' . $value;
};

// 3. Datenbank-TLS testen
$env = array_map('crypto_env_decode', array_merge($raw, env_raw($envFile)));
$host = $env['DB_HOST'] ?? '';
$dsn = 'mysql:host=' . $host . ';port=' . ($env['DB_PORT'] ?? '3306') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8mb4';
if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    echo "3. Datenbank: läuft lokal auf dem Server, keine Netzwerkübertragung (TLS nicht nötig).\n";
} else {
    try {
        $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            (int) constant(class_exists("Pdo\Mysql", false) ? "Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT" : "PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT") => false,
            (int) constant(class_exists("Pdo\Mysql", false) ? "Pdo\Mysql::ATTR_SSL_CIPHER" : "PDO::MYSQL_ATTR_SSL_CIPHER") => "DEFAULT",
        ]);
        $cipher = (string) ($pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '');
        if ($cipher !== '') {
            $set('DB_SSL', 'true');
            echo "3. Datenbank: TLS aktiv ({$cipher}), DB_SSL=true gesetzt.\n";
        } else {
            echo "3. WARNUNG: Der Datenbankserver bietet kein TLS an. Die Verbindung zu {$host} ist NICHT verschlüsselt.\n"
                . "   Bitte TLS auf dem MySQL-Server aktivieren (oder die Datenbank auf denselben Server legen und DB_HOST=127.0.0.1 setzen).\n";
        }
    } catch (Throwable $e) {
        echo '3. Datenbank-Test nicht möglich: ' . $e->getMessage() . "\n";
    }
}

if (file_put_contents($envFile, implode("\n", $lines) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Die .env konnte nicht geschrieben werden.\n");
    exit(1);
}
echo '2. .env: ' . ($encrypted === [] ? 'nichts zu verschlüsseln (bereits erledigt)' : 'verschlüsselt: ' . implode(', ', $encrypted)) . "\n";

// 4. Dokumente verschlüsseln
$count = 0;
$uploads = $root . '/uploads';
if (is_dir($uploads)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getFilename() !== '.gitkeep' && !crypto_file_is_encrypted($file->getPathname())) {
            crypto_file_encrypt($file->getPathname());
            $count++;
        }
    }
}
echo "4. Dokumente: {$count} Datei(en) verschlüsselt.\n\n";

echo "5. Verschlüsselungsschlüssel für die Desktop-App (in den Einstellungen der App eintragen):\n   " . crypto_transport_key_b64() . "\n\n";
echo "Bitte die Schlüsseldatei {$keyFile} zusätzlich sicher aufbewahren.\n";
