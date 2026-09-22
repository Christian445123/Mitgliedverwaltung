<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = (string) getenv('DB_HOST');
        $port = (string) (getenv("DB_PORT") ?: "3306");
        $name = (string) getenv('DB_NAME');
        $user = (string) getenv('DB_USER');
        $pass = (string) getenv('DB_PASS');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Verbindung zur Datenbank per TLS verschlüsseln (bei fremdem Datenbankserver wichtig).
        // DB_SSL=true erzwingt TLS, false schaltet es ab, "auto" (Standard) versucht TLS und fällt bei
        // Fehlern zurück. Mit DB_SSL_CA (Pfad zum CA-Zertifikat) wird zusätzlich das Serverzertifikat geprüft.
        $sslMode = strtolower((string) (getenv('DB_SSL') ?: 'auto'));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $sslOptions = [];
        if ($sslMode !== 'false' && !$isLocal) {
            $ca = (string) getenv('DB_SSL_CA');
            if ($ca !== '') {
                $sslOptions[db_mysql_option("SSL_CA")] = $ca;
                $sslOptions[db_mysql_option("SSL_VERIFY_SERVER_CERT")] = true;
            } else {
                $sslOptions[db_mysql_option("SSL_VERIFY_SERVER_CERT")] = false; // verschlüsselt, Server aber nicht geprüft
                $sslOptions[db_mysql_option("SSL_CIPHER")] = 'DEFAULT';
            }
        }
        try {
            $pdo = new PDO($dsn, $user, $pass, $options + $sslOptions);
        } catch (PDOException $e) {
            if ($sslOptions === [] || $sslMode === 'true') {
                throw $e;
            }
            error_log('Datenbank-Verbindung mit TLS fehlgeschlagen, Rückfall ohne TLS: ' . $e->getMessage());
            $pdo = new PDO($dsn, $user, $pass, $options);
        }
    }

    static $checked = false;
    if (!$checked) {
        $checked = true;
        db_ensure_columns($pdo);
    }

    return $pdo;
}

/**
 * Ergänzt nachträglich hinzugekommene Spalten in bestehenden Installationen
 * (schema.sql legt nur bei Neuinstallation alles an). Läuft je Anfrage nur
 * eine schnelle Prüfung; ändert nie vorhandene Spalten oder Daten.
 */
function db_ensure_columns(PDO $pdo): void
{
    $needed = [
        'members' => [
            'kader' => "ENUM('kader','nicht_im_kader') NOT NULL DEFAULT 'kader'",
        ],
        'member_equipment' => [
            'zimmer_nr' => 'VARCHAR(20) DEFAULT NULL',
            'pract_jersey_nr' => 'VARCHAR(10) DEFAULT NULL',
            'pract_hose_groesse' => 'VARCHAR(10) DEFAULT NULL',
        ],
        'member_documents' => [
            'nada_dokument_pfad' => 'VARCHAR(255) DEFAULT NULL',
            'rechte_pflichten_dokument_pfad' => 'VARCHAR(255) DEFAULT NULL',
            'bild_ecard_hinten_pfad' => 'VARCHAR(255) DEFAULT NULL',
            'pass_foto_hinten_pfad' => 'VARCHAR(255) DEFAULT NULL',
            'fehlt_ecard' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fehlt_pass' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fehlt_nada' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'fehlt_rechte' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
    ];

    foreach ($needed as $table => $columns) {
        try {
            $existing = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        } catch (PDOException $e) {
            // Tabelle fehlt (Erstinstallation vor schema.sql) oder keine ALTER-Rechte:
            // die Anwendung läuft weiter, neue Felder sind dann erst nach schema.sql-Import nutzbar.
        }
    }

    // Automatisch gepflegtes Feld "Name & Vorname" (Nachname + Vorname) - von der Datenbank berechnet
    try {
        $memberColumns = $pdo->query('SHOW COLUMNS FROM members')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('name_vorname', $memberColumns, true)) {
            $pdo->exec("ALTER TABLE members ADD COLUMN name_vorname VARCHAR(255) GENERATED ALWAYS AS (CONCAT(nachname, ' ', vorname)) STORED");
        }
    } catch (PDOException $e) {
        // Nicht kritisch: die Anwendung berechnet den Namen zusätzlich selbst (member_full_name()).
    }

    // Tabellen für weitere Camps (falls noch nicht vorhanden)
    try {
        require_once __DIR__ . '/includes/camps.php';
        camps_ensure_tables($pdo);
    } catch (Throwable $e) {
        error_log('camps_ensure_tables: ' . $e->getMessage());
    }

    // Rollen und Berechtigungen (Tabellen, Standardrollen, Zuordnung bestehender Benutzer)
    try {
        require_once __DIR__ . '/includes/permissions.php';
        permissions_ensure_tables($pdo);
    } catch (Throwable $e) {
        error_log('permissions_ensure_tables: ' . $e->getMessage());
    }

    // Staff-Tabelle (Trainer, Betreuer)
    try {
        require_once __DIR__ . '/includes/staff.php';
        staff_ensure_table($pdo);
    } catch (Throwable $e) {
        error_log('staff_ensure_table: ' . $e->getMessage());
    }

    // Status "neu" (Selbstregistrierung, wartet auf manuelle Zuweisung) zur bestehenden Spalte ergänzen
    try {
        $statusCol = $pdo->query("SHOW COLUMNS FROM members LIKE 'status'")->fetch();
        if ($statusCol !== false && !str_contains((string) $statusCol['Type'], "'neu'")) {
            $pdo->exec("ALTER TABLE members MODIFY COLUMN status ENUM('aktiv','inaktiv','neu') NOT NULL DEFAULT 'aktiv'");
        }
    } catch (PDOException $e) {
        // Tabelle fehlt oder keine ALTER-Rechte
    }

    // Registrierungslinks für neue Mitglieder (Tabelle bei Bedarf anlegen)
    try {
        require_once __DIR__ . '/includes/registration.php';
        registration_ensure_tables($pdo);
    } catch (Throwable $e) {
        error_log('registration_ensure_tables: ' . $e->getMessage());
    }
}

/** MySQL-Verbindungsoption: ab PHP 8.5 als Pdo\Mysql::ATTR_*, davor als PDO::MYSQL_ATTR_* (die alten Namen sind veraltet). */
function db_mysql_option(string $name): int
{
    return (int) constant(class_exists('Pdo\Mysql', false) ? 'Pdo\Mysql::ATTR_' . $name : 'PDO::MYSQL_ATTR_' . $name);
}
