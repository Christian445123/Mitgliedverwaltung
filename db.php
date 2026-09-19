<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = (string) getenv('DB_HOST');
        $name = (string) getenv('DB_NAME');
        $user = (string) getenv('DB_USER');
        $pass = (string) getenv('DB_PASS');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

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
}
