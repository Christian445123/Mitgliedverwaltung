<?php

declare(strict_types=1);

/**
 * Staff (Trainer, Betreuer, Funktionäre) - eigener Bereich neben den Spielern.
 * Eine Tabelle "staff" mit den Spalten der Staff-Liste des Vereins.
 *
 * Feldtypen wie bei den Spielern: str | int | date | bool | status
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';

/**
 * Import-/Export-Felder des Staffs (Reihenfolge = Reihenfolge der Excel-Liste): Schlüssel => [Beschriftung, Typ].
 */
const STAFF_IO_COLUMNS = [
    'nachname' => ['Nachname', 'str'],
    'vorname' => ['Vorname', 'str'],
    'position' => ['Position', 'str'],
    'nada' => ['Nada', 'bool'],
    'geburtsdatum' => ['Geburtsdatum', 'date'],
    'telefon' => ['Telefon', 'str'],
    'email' => ['Mail', 'str'],
    'telefon_angehoeriger' => ['Telefonnummer Angehörige', 'str'],
    'sozialversicherungsnummer' => ['Sozial Ver. Nr.', 'str'],
    'reisepass_nr' => ['Reisepass Nr', 'str'],
    'reisepass_ausgestellt_am' => ['Reisepass ausgestellt am', 'date'],
    'reisepass_gueltig_bis' => ['Reisepass gültig bis', 'date'],
    'geburtsland' => ['Geburtsland', 'str'],
    'ausstellungsbehoerde' => ['Ausstellungsbehörde', 'str'],
    'plz' => ['PLZ', 'str'],
    'ort' => ['Ort', 'str'],
    'strasse' => ['Straße', 'str'],
    'kontoinhaber' => ['Kontoinhaber', 'str'],
    'iban' => ['IBAN', 'str'],
    'bic' => ['BIC', 'str'],
    'essen' => ['Essen', 'str'],
    'tshirt_polo_groesse' => ['T-Shirt / Polo Größe', 'str'],
    'hoodie_groesse' => ['Hoodie Größe', 'str'],
    'jacken_groesse' => ['Jacken Größe', 'str'],
    'short_groesse' => ['Short Größe', 'str'],
    'shorts_anzahl' => ['Wie viele Shorts besitzt du?', 'str'],
    'coaching_hosen_lang_groesse' => ['Coaching Hosen (lang) Größe', 'str'],
    'status' => ['Status', 'status'],
];

/** Gruppen für das Formular: Überschrift => Feldschlüssel. */
const STAFF_FORM_GROUPS = [
    'Person' => ['nachname', 'vorname', 'position', 'nada', 'geburtsdatum'],
    'Kontakt' => ['telefon', 'email', 'telefon_angehoeriger'],
    'Sozialversicherung' => ['sozialversicherungsnummer'],
    'Reisepass' => ['reisepass_nr', 'reisepass_ausgestellt_am', 'reisepass_gueltig_bis', 'geburtsland', 'ausstellungsbehoerde'],
    'Adresse' => ['plz', 'ort', 'strasse'],
    'Kontodaten' => ['kontoinhaber', 'iban', 'bic'],
    'Essen' => ['essen'],
    'Ausrüstungsgrößen' => ['tshirt_polo_groesse', 'hoodie_groesse', 'jacken_groesse', 'short_groesse', 'shorts_anzahl', 'coaching_hosen_lang_groesse'],
];

/** Feldschlüssel der Tabelle (ohne id/Zeitstempel). */
function staff_columns(): array
{
    return array_keys(STAFF_IO_COLUMNS);
}

/** Legt die Tabelle an, falls sie fehlt. Nie innerhalb einer Transaktion aufrufen (DDL). */
function staff_ensure_table(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'staff'")->fetchColumn() !== false) {
        // Nachrüsten: Ablaufdatum des NADA-Zertifikats
        if ($pdo->query("SHOW COLUMNS FROM staff LIKE 'nada_gueltig_bis'")->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE staff ADD COLUMN nada_gueltig_bis DATE DEFAULT NULL AFTER nada');
        }
        if ($pdo->query("SHOW COLUMNS FROM staff LIKE 'rechte_dokument_pfad'")->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE staff ADD COLUMN rechte_dokument_pfad VARCHAR(255) DEFAULT NULL');
        }
        foreach (['pass_foto_pfad', 'ecard_foto_pfad'] as $photoColumn) {
            if ($pdo->query("SHOW COLUMNS FROM staff LIKE '{$photoColumn}'")->fetchColumn() === false) {
                $pdo->exec("ALTER TABLE staff ADD COLUMN {$photoColumn} VARCHAR(255) DEFAULT NULL");
            }
        }
        if ($pdo->query("SHOW COLUMNS FROM staff LIKE 'sozialversicherungsnummer'")->fetchColumn() === false) {
            $pdo->exec('ALTER TABLE staff ADD COLUMN sozialversicherungsnummer VARCHAR(20) DEFAULT NULL');
        }
        foreach (['kontoinhaber' => 'VARCHAR(150)', 'iban' => 'VARCHAR(42)', 'bic' => 'VARCHAR(15)'] as $col => $def) {
            if ($pdo->query("SHOW COLUMNS FROM staff LIKE '{$col}'")->fetchColumn() === false) {
                $pdo->exec("ALTER TABLE staff ADD COLUMN {$col} {$def} DEFAULT NULL");
            }
        }
        // Nada ist jetzt Ja/Nein: bisherige Texte auf 1 (Ja) bzw. 0 (Nein) umstellen (läuft nur, solange es andere Werte gibt)
        $pdo->exec("UPDATE staff SET nada = CASE WHEN nada IS NULL OR TRIM(nada) = '' OR LOWER(TRIM(nada)) IN ('nein', 'no', 'n', '0', 'false', '-') THEN '0' ELSE '1' END WHERE nada IS NULL OR nada NOT IN ('0', '1')");
        // Status "neu" (Selbstanmeldung über den Staff-Einladungslink, wartet auf Freigabe) ergänzen
        $statusCol = $pdo->query("SHOW COLUMNS FROM staff LIKE 'status'")->fetch();
        if ($statusCol !== false && !str_contains((string) $statusCol['Type'], "'neu'")) {
            $pdo->exec("ALTER TABLE staff MODIFY COLUMN status ENUM('aktiv','inaktiv','neu') NOT NULL DEFAULT 'aktiv'");
        }
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nachname VARCHAR(100) NOT NULL,
            vorname VARCHAR(100) NOT NULL,
            name_vorname VARCHAR(255) GENERATED ALWAYS AS (CONCAT(nachname, ' ', vorname)) STORED,
            position VARCHAR(100) DEFAULT NULL,
            nada VARCHAR(100) DEFAULT NULL,
            nada_gueltig_bis DATE DEFAULT NULL,
            geburtsdatum DATE DEFAULT NULL,
            telefon VARCHAR(50) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            telefon_angehoeriger VARCHAR(50) DEFAULT NULL,
            reisepass_nr VARCHAR(50) DEFAULT NULL,
            reisepass_ausgestellt_am DATE DEFAULT NULL,
            reisepass_gueltig_bis DATE DEFAULT NULL,
            geburtsland VARCHAR(100) DEFAULT NULL,
            ausstellungsbehoerde VARCHAR(150) DEFAULT NULL,
            plz VARCHAR(10) DEFAULT NULL,
            ort VARCHAR(100) DEFAULT NULL,
            strasse VARCHAR(150) DEFAULT NULL,
            kontoinhaber VARCHAR(150) DEFAULT NULL,
            iban VARCHAR(42) DEFAULT NULL,
            bic VARCHAR(15) DEFAULT NULL,
            essen VARCHAR(255) DEFAULT NULL,
            tshirt_polo_groesse VARCHAR(10) DEFAULT NULL,
            hoodie_groesse VARCHAR(10) DEFAULT NULL,
            jacken_groesse VARCHAR(10) DEFAULT NULL,
            short_groesse VARCHAR(10) DEFAULT NULL,
            shorts_anzahl VARCHAR(20) DEFAULT NULL,
            coaching_hosen_lang_groesse VARCHAR(10) DEFAULT NULL,
            rechte_dokument_pfad VARCHAR(255) DEFAULT NULL,
            pass_foto_pfad VARCHAR(255) DEFAULT NULL,
            ecard_foto_pfad VARCHAR(255) DEFAULT NULL,
            sozialversicherungsnummer VARCHAR(20) DEFAULT NULL,
            status ENUM('aktiv', 'inaktiv', 'neu') NOT NULL DEFAULT 'aktiv',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** @return array<string, mixed>|false */
function staff_find_by_id(int $id)
{
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Vorhandene Person zu Importdaten: zuerst per E-Mail, sonst per Nachname + Vorname.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>|false
 */
function staff_find_existing(array $data)
{
    if (!empty($data['email'])) {
        $stmt = db()->prepare('SELECT * FROM staff WHERE email = ? LIMIT 1');
        $stmt->execute([$data['email']]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }
    }
    if (!empty($data['nachname']) && !empty($data['vorname'])) {
        $stmt = db()->prepare('SELECT * FROM staff WHERE nachname = ? AND vorname = ? LIMIT 1');
        $stmt->execute([$data['nachname'], $data['vorname']]);
        return $stmt->fetch();
    }
    return false;
}

/**
 * @param array<string, string> $params
 * @return array{0: string, 1: array<string, string>}
 */
function staff_where(string $query, ?string $status, ?string $camp = null): array
{
    $conditions = [];
    $params = [];
    if ($query !== '') {
        $conditions[] = '(nachname LIKE :q1 OR vorname LIKE :q2 OR email LIKE :q3 OR position LIKE :q4)';
        foreach (['q1', 'q2', 'q3', 'q4'] as $name) {
            $params[$name] = '%' . $query . '%';
        }
    }
    if ($status === 'aktiv' || $status === 'inaktiv') {
        $conditions[] = 'status = :status';
        $params['status'] = $status;
    } else {
        // Ohne expliziten Status-Filter nie neue, noch nicht geprüfte Staff-Anmeldungen mitzeigen -
        // die haben einen eigenen Bereich (admin/registrations.php), bis sie manuell freigegeben werden.
        $conditions[] = "status <> 'neu'";
    }

    require_once __DIR__ . '/camps.php';
    $campCondition = camp_filter_condition('staff', 'staff.id', $camp, $params);
    if ($campCondition !== null) {
        $conditions[] = $campCondition;
    }

    return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
}

/** @return array<int, array<string, mixed>> */
function staff_search(string $query, int $limit, int $offset, ?string $status = null, ?string $camp = null): array
{
    [$where, $params] = staff_where($query, $status, $camp);
    $stmt = db()->prepare('SELECT * FROM staff' . $where . ' ORDER BY nachname, vorname LIMIT :limit OFFSET :offset');
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, $name === 'camp' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function staff_count(string $query = '', ?string $status = null, ?string $camp = null): int
{
    [$where, $params] = staff_where($query, $status, $camp);
    $stmt = db()->prepare('SELECT COUNT(*) FROM staff' . $where);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, $name === 'camp' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/** @return array<int, array<string, mixed>> alle (optional nur aktive), alphabetisch */
function staff_all(?string $status = null, ?string $camp = null): array
{
    return staff_search('', PHP_INT_MAX >> 1, 0, $status, $camp);
}

/**
 * Legt eine Person an oder aktualisiert sie ($id). Es werden nur Felder geschrieben, die in $data vorkommen.
 *
 * @param array<string, mixed> $data
 * @throws RuntimeException bei doppelter E-Mail-Adresse
 */
function staff_upsert(array $data, ?int $id): int
{
    $values = array_intersect_key($data, array_flip(staff_columns()));
    if ($values === [] && $id !== null) {
        return $id;
    }

    try {
        if ($id === null) {
            $cols = array_keys($values);
            $stmt = db()->prepare(
                'INSERT INTO staff (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn (string $c) => ':' . $c, $cols)) . ')'
            );
            $stmt->execute($values);
            return (int) db()->lastInsertId();
        }

        $set = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($values)));
        $stmt = db()->prepare("UPDATE staff SET {$set} WHERE id = :id");
        $stmt->execute($values + ['id' => $id]);
        return $id;
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            throw new RuntimeException('Diese E-Mail-Adresse ist bereits für eine andere Person im Staff eingetragen.');
        }
        throw $e;
    }
}

/**
 * Import: Person anlegen oder (bei $updateExisting) aktualisieren.
 *
 * @param array<string, mixed> $data
 * @return string 'created' | 'updated'
 * @throws RuntimeException wenn die Person existiert und nicht aktualisiert werden soll
 */
function staff_import_save(array $data, bool $updateExisting): string
{
    $existing = staff_find_existing($data);
    if ($existing !== false) {
        if (!$updateExisting) {
            throw new RuntimeException('Diese Person existiert bereits.');
        }
        staff_upsert($data, (int) $existing['id']);
        return 'updated';
    }
    staff_upsert($data, null);
    return 'created';
}

/**
 * @param array<int, int|string> $ids
 * @return int Anzahl gelöschter Personen
 */
function staff_delete_many(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));

    // Hochgeladene Dokumente mit löschen
    $files = db()->prepare("SELECT * FROM staff WHERE id IN ({$marks})");
    $files->execute($ids);
    $toDelete = [];
    foreach ($files->fetchAll() as $row) {
        foreach (array_keys(STAFF_DOCUMENT_TYPES) as $type) {
            $path = staff_document_path($row, $type);
            if ($path !== null) {
                $toDelete[] = $path;
            }
        }
    }

    $stmt = db()->prepare("DELETE FROM staff WHERE id IN ({$marks})");
    $stmt->execute($ids);
    foreach ($toDelete as $file) {
        @unlink($file);
    }
    return $stmt->rowCount();
}

/**
 * Formulardaten (POST) in DB-Werte umwandeln.
 *
 * @return array<string, mixed>
 * @throws RuntimeException bei fehlenden Pflichtfeldern oder ungültigen Werten
 */
function staff_collect_input(): array
{
    require_once __DIR__ . '/member_columns.php';

    $previous = io_entity();
    io_entity('staff');
    try {
        $raw = [];
        foreach (staff_columns() as $key) {
            if ($key === 'status') {
                continue;
            }
            $raw[$key] = (string) ($_POST[$key] ?? '');
        }
        $result = io_convert_row($raw, true, false);
    } finally {
        io_entity($previous);
    }

    if ($result['errors'] !== []) {
        throw new RuntimeException(implode(' ', $result['errors']));
    }
    $data = $result['data'];
    // "neu" bleibt nur erhalten, wenn es explizit gesendet wurde (Status-Feld zeigt die Option nur bei
    // bereits so markierten Staff-Anmeldungen an) - neu angelegte Personen starten immer als "aktiv".
    $statusPost = (string) ($_POST['status'] ?? 'aktiv');
    $data['status'] = in_array($statusPost, ['aktiv', 'inaktiv', 'neu'], true) ? $statusPost : 'aktiv';
    return $data;
}

/**
 * Wie staff_collect_input(), aber für den öffentlichen Staff-Einladungslink (registrieren.php): anders
 * als im Admin-Formular sind hier zusätzlich Telefon und Mail immer Pflicht (die Datei-Uploads und
 * weiteren Felder prüft staff_require_fields() gegen die eingestellten Pflichtfelder). Der Status wird
 * bewusst nicht übernommen, die Anmeldung landet immer als "neu" (siehe registrieren.php).
 *
 * @throws RuntimeException wenn Nachname, Vorname, Telefon oder Mail fehlen bzw. die Mail ungültig ist
 */
function staff_collect_input_public(): array
{
    $data = staff_collect_input();
    unset($data['status']);

    if (($data['telefon'] ?? '') === '' || ($data['telefon'] ?? null) === null) {
        throw new RuntimeException('Telefon ist ein Pflichtfeld.');
    }
    $email = trim((string) ($data['email'] ?? ''));
    if ($email === '') {
        throw new RuntimeException('Mail ist ein Pflichtfeld.');
    }
    if (!is_valid_email($email)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    }

    return $data;
}

/**
 * Prüft die bei der Staff-Neuanmeldung (Einladungslink) als Pflicht eingestellten Felder (siehe
 * includes/registration_fields.php). Dokument-Felder werden über den tatsächlichen Datei-Upload
 * geprüft (die Datei selbst wird erst nach dem Anlegen der Person gespeichert, siehe registrieren.php).
 *
 * @param array<string, mixed> $data
 * @param array<int, string> $requiredKeys
 * @throws RuntimeException wenn Pflichtfelder fehlen
 */
function staff_require_fields(array $data, array $requiredKeys): void
{
    require_once __DIR__ . '/registration_fields.php';
    $labels = registration_field_labels('staff');
    $inputNames = registration_field_input_names('staff');

    $missing = [];
    foreach ($requiredKeys as $key) {
        if (str_ends_with($key, '_pfad')) {
            $inputName = $inputNames[$key] ?? $key;
            $hasFile = isset($_FILES[$inputName]) && ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$hasFile) {
                $missing[] = $labels[$key] ?? $key;
            }
            continue;
        }
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            $missing[] = $labels[$key] ?? $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Bitte alle Pflichtfelder ausfüllen. Es fehlt noch: ' . implode(', ', $missing) . '.');
    }
}

// ── Dokumente (Rechte & Pflichten, unterschrieben) ────────────────────────

const STAFF_UPLOAD_DIR = __DIR__ . '/../uploads/staff';
const STAFF_UPLOAD_PUBLIC_PREFIX = '/uploads/staff';

/** Dokumenttypen des Staffs: Schlüssel (API/URL) => Spalte und Beschriftung. */
const STAFF_DOCUMENT_TYPES = [
    'rechte' => ['column' => 'rechte_dokument_pfad', 'label' => 'Rechte & Pflichten'],
    'pass' => ['column' => 'pass_foto_pfad', 'label' => 'Reisepass (Foto)'],
    'ecard' => ['column' => 'ecard_foto_pfad', 'label' => 'E-Card'],
];

/**
 * Absoluter Dateipfad eines Staff-Dokuments oder null (nicht vorhanden / außerhalb von uploads/).
 *
 * @param array<string, mixed> $row
 */
function staff_document_path(array $row, string $type): ?string
{
    if (!isset(STAFF_DOCUMENT_TYPES[$type]) || empty($row[STAFF_DOCUMENT_TYPES[$type]['column']])) {
        return null;
    }
    $path = realpath(__DIR__ . '/..' . $row[STAFF_DOCUMENT_TYPES[$type]['column']]);
    $root = realpath(__DIR__ . '/../uploads');
    if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    return $path;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, bool>
 */
function staff_documents_present(array $row): array
{
    $present = [];
    foreach (STAFF_DOCUMENT_TYPES as $type => $def) {
        $present[$type] = !empty($row[$def['column']]);
    }
    return $present;
}

/**
 * Speichert (ersetzt) oder entfernt ein Dokument. $inputName = Name des Upload-Feldes in $_FILES,
 * null = Dokument entfernen. Die alte Datei wird gelöscht.
 *
 * @throws RuntimeException bei ungültigem Upload
 */
function staff_set_document(int $id, string $type, ?string $inputName): void
{
    $def = STAFF_DOCUMENT_TYPES[$type] ?? null;
    if ($def === null) {
        throw new RuntimeException('Unbekannter Dokumenttyp.');
    }
    $row = staff_find_by_id($id);
    if ($row === false) {
        throw new RuntimeException('Person nicht gefunden.');
    }

    $newPath = null;
    if ($inputName !== null) {
        require_once __DIR__ . '/functions.php';
        $newPath = handle_upload($inputName, STAFF_UPLOAD_DIR, STAFF_UPLOAD_PUBLIC_PREFIX);
        if ($newPath === null) {
            return; // keine neue Datei gewählt
        }
    }

    $old = staff_document_path($row, $type);
    $stmt = db()->prepare('UPDATE staff SET ' . $def['column'] . ' = ? WHERE id = ?');
    $stmt->execute([$newPath, $id]);
    if ($old !== null) {
        @unlink($old);
    }
}

/**
 * Sendet ein Staff-Dokument an den Browser/Client (404, falls nicht vorhanden).
 *
 * @param array<string, mixed> $row
 */
function staff_document_send(array $row, string $type, bool $inline = true): never
{
    $path = staff_document_path($row, $type);
    if ($path === null) {
        http_response_code(404);
        exit('Dokument nicht vorhanden.');
    }
    $data = crypto_file_read($path); // hochgeladene Dokumente liegen verschlüsselt auf dem Server
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'staff-' . $type . '-' . ($row['nachname'] ?? '') . '-' . ($row['vorname'] ?? '')) . '.' . pathinfo($path, PATHINFO_EXTENSION);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}
