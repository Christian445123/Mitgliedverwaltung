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

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
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
}
