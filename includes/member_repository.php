<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/field_access.php';
require_once __DIR__ . '/camps.php';

const MEMBER_UPLOAD_DIR = __DIR__ . '/../uploads';
const MEMBER_UPLOAD_PUBLIC_PREFIX = '/uploads';

/**
 * Spalten je Teiltabelle. Die Kern-Tabelle "members" führt nur Stamm- und
 * Suchfelder, alles andere ist 1:1 über member_id ausgelagert.
 */
const MEMBERS_COLUMNS = ['jersey_nr', 'nachname', 'vorname', 'sz', 'bezirk', 'position', 'geburtsdatum', 'verein', 'groesse_cm', 'gewicht_kg', 'telefon', 'email', 'kader'];
const CAMPS_COLUMNS = ['camp_1', 'spanien', 'camp_2', 'tschechien'];
const GUARDIAN_COLUMNS = ['erz_name', 'erz_telefon', 'erz_email'];
const CONSENT_COLUMNS = ['rechte_pflichten_akzeptiert', 'rechte_pflichten_am'];
const DOCUMENT_COLUMNS = ['bild_ecard_pfad', 'sozialversicherungsnummer', 'nada_zertifikat', 'nada_gueltig_bis', 'pass_foto_pfad', 'reisepass_nr', 'reisepass_ausgestellt_am', 'reisepass_gueltig_bis', 'geburtsland', 'geburtsort', 'ausstellungsbehoerde', 'nada_dokument_pfad', 'rechte_pflichten_dokument_pfad', 'bild_ecard_hinten_pfad', 'pass_foto_hinten_pfad', 'fehlt_ecard', 'fehlt_pass', 'fehlt_nada', 'fehlt_rechte'];
const ADDRESS_COLUMNS = ['plz', 'ort', 'strasse'];
const EQUIPMENT_COLUMNS = ['essen', 'game_jersey_groesse', 'game_hosen_groesse', 'helm_groesse', 'helm_eigener', 'tshirt_polo_groesse', 'hoodie_groesse', 'mesh_shorts_groesse', 'socken_groesse', 'zimmer_nr', 'pract_jersey_nr', 'pract_hose_groesse'];

/**
 * Dokumenttypen: Schlüssel (API/URL) => Spalte, Upload-Unterordner, Beschriftung.
 */
const MEMBER_DOCUMENT_TYPES = [
    'ecard' => ['column' => 'bild_ecard_pfad', 'dir' => 'ecard', 'label' => 'E-Card Vorderseite'],
    'ecard_back' => ['column' => 'bild_ecard_hinten_pfad', 'dir' => 'ecard', 'label' => 'E-Card Rückseite'],
    'pass' => ['column' => 'pass_foto_pfad', 'dir' => 'pass', 'label' => 'Reisepass Vorderseite'],
    'pass_back' => ['column' => 'pass_foto_hinten_pfad', 'dir' => 'pass', 'label' => 'Reisepass Rückseite'],
    'nada' => ['column' => 'nada_dokument_pfad', 'dir' => 'nada', 'label' => 'NADA-Zertifikat'],
    'rechte' => ['column' => 'rechte_pflichten_dokument_pfad', 'dir' => 'rechte', 'label' => 'Rechte & Pflichten'],
];

const MEMBER_JOIN_SQL = '
    SELECT m.*,
        c.camp_1, c.spanien, c.camp_2, c.tschechien,
        g.erz_name, g.erz_telefon, g.erz_email,
        co.rechte_pflichten_akzeptiert, co.rechte_pflichten_am,
        d.bild_ecard_pfad, d.sozialversicherungsnummer, d.nada_zertifikat, d.nada_gueltig_bis,
        d.pass_foto_pfad, d.reisepass_nr, d.reisepass_ausgestellt_am, d.reisepass_gueltig_bis,
        d.geburtsland, d.geburtsort, d.ausstellungsbehoerde, d.nada_dokument_pfad, d.rechte_pflichten_dokument_pfad, d.bild_ecard_hinten_pfad, d.pass_foto_hinten_pfad, d.fehlt_ecard, d.fehlt_pass, d.fehlt_nada, d.fehlt_rechte,
        a.plz, a.ort, a.strasse,
        e.essen, e.game_jersey_groesse, e.game_hosen_groesse, e.helm_groesse, e.helm_eigener,
        e.tshirt_polo_groesse, e.hoodie_groesse, e.mesh_shorts_groesse, e.socken_groesse, e.zimmer_nr, e.pract_jersey_nr, e.pract_hose_groesse,
        ac.verify_token, ac.access_password_hash, ac.verified_at, ac.failed_verify_attempts, ac.verify_locked_until
    FROM members m
    LEFT JOIN member_camps c ON c.member_id = m.id
    LEFT JOIN member_guardians g ON g.member_id = m.id
    LEFT JOIN member_consents co ON co.member_id = m.id
    LEFT JOIN member_documents d ON d.member_id = m.id
    LEFT JOIN member_addresses a ON a.member_id = m.id
    LEFT JOIN member_equipment e ON e.member_id = m.id
    LEFT JOIN member_access ac ON ac.member_id = m.id
';

/**
 * @param array<string, mixed> $data
 * @param array<int, string> $keys
 * @return array<string, mixed>
 */
function pick_columns(array $data, array $keys): array
{
    return array_intersect_key($data, array_flip($keys));
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_id(int $id)
{
    $stmt = db()->prepare(MEMBER_JOIN_SQL . ' WHERE m.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? false : member_attach_camps([$row])[0];
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_email(string $email)
{
    $stmt = db()->prepare(MEMBER_JOIN_SQL . ' WHERE m.email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row === false ? false : member_attach_camps([$row])[0];
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_token(string $token)
{
    $stmt = db()->prepare(MEMBER_JOIN_SQL . ' WHERE ac.verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row === false ? false : member_attach_camps([$row])[0];
}

function build_member_link(string $token): string
{
    return APP_BASE_URL . '/mitglied-formular.php?token=' . $token;
}

/**
 * Zufälliger Zugangscode ohne leicht verwechselbare Zeichen (0/O, 1/l/I).
 */
function generate_access_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

/**
 * Erzeugt einen neuen persönlichen Link (alter Link wird ungültig) und
 * setzt Bestätigungs-/Sperr-Status zurück.
 */
function member_regenerate_token(int $id): string
{
    $token = random_token(32);
    $stmt = db()->prepare(
        'UPDATE member_access SET verify_token = ?, verified_at = NULL, failed_verify_attempts = 0, verify_locked_until = NULL WHERE member_id = ?'
    );
    $stmt->execute([$token, $id]);
    return $token;
}

/**
 * Erzeugt einen neuen Zugangscode (alter Code wird ungültig), gibt ihn im
 * Klartext zurück (wird nur einmalig gespeichert/angezeigt, danach nur Hash).
 */
function member_regenerate_access_password(int $id): string
{
    $password = generate_access_password();
    $stmt = db()->prepare(
        'UPDATE member_access SET access_password_hash = ?, failed_verify_attempts = 0, verify_locked_until = NULL WHERE member_id = ?'
    );
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    return $password;
}

function member_record_verify_success(int $id): void
{
    $stmt = db()->prepare(
        'UPDATE member_access SET verified_at = NOW(), failed_verify_attempts = 0, verify_locked_until = NULL WHERE member_id = ?'
    );
    $stmt->execute([$id]);
}

function member_record_verify_failure(int $id, int $currentAttempts, int $maxAttempts, int $lockoutMinutes): void
{
    $attempts = $currentAttempts + 1;

    if ($attempts >= $maxAttempts) {
        $stmt = db()->prepare(
            'UPDATE member_access SET failed_verify_attempts = ?, verify_locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE member_id = ?'
        );
        $stmt->execute([$attempts, $lockoutMinutes, $id]);
        return;
    }

    $stmt = db()->prepare('UPDATE member_access SET failed_verify_attempts = ? WHERE member_id = ?');
    $stmt->execute([$attempts, $id]);
}

/**
 * Liest die Formulardaten aus $_POST / $_FILES, validiert sie grob und
 * merged Datei-Uploads mit vorhandenen Pfaden (falls kein neuer Upload da ist).
 *
 * @param array<string, mixed> $existing bereits vorhandene Mitgliedsdaten (für Upload-Fallback)
 * @return array<string, mixed>
 * @throws RuntimeException bei ungültigen Pflichtfeldern oder Upload-Fehlern
 */
function member_collect_input(array $existing = [], string $audience = 'admin'): array
{
    // Gesperrte Felder (Feld-Rechte) auf den bisherigen Wert zurücksetzen, auch bei manipulierten Formulardaten
    field_access_overlay_post($existing, $audience);

    $nachname = post_str('nachname');
    $vorname = post_str('vorname');
    $email = post_str('email');
    $telefon = post_str('telefon');
    $erzTelefon = post_str('erz_telefon');
    try {
        $telefon = $telefon === null ? null : normalize_phone($telefon);
        $erzTelefon = $erzTelefon === null ? null : normalize_phone($erzTelefon);
    } catch (InvalidArgumentException $e) {
        throw new RuntimeException($e->getMessage());
    }

    if ($nachname === null || $vorname === null || $email === null || $telefon === null) {
        throw new RuntimeException('Nachname, Vorname, Telefon und E-Mail-Adresse sind Pflichtfelder.');
    }

    if (!is_valid_email($email)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    }

    $bildEcardPfad = handle_upload('bild_ecard', MEMBER_UPLOAD_DIR . '/ecard', MEMBER_UPLOAD_PUBLIC_PREFIX . '/ecard')
        ?? ($existing['bild_ecard_pfad'] ?? null);

    $passFotoPfad = handle_upload('pass_foto', MEMBER_UPLOAD_DIR . '/pass', MEMBER_UPLOAD_PUBLIC_PREFIX . '/pass')
        ?? ($existing['pass_foto_pfad'] ?? null);

    $bildEcardHintenPfad = handle_upload('bild_ecard_hinten', MEMBER_UPLOAD_DIR . '/ecard', MEMBER_UPLOAD_PUBLIC_PREFIX . '/ecard')
        ?? ($existing['bild_ecard_hinten_pfad'] ?? null);

    $passFotoHintenPfad = handle_upload('pass_foto_hinten', MEMBER_UPLOAD_DIR . '/pass', MEMBER_UPLOAD_PUBLIC_PREFIX . '/pass')
        ?? ($existing['pass_foto_hinten_pfad'] ?? null);

    $nadaDokumentPfad = handle_upload('nada_dokument', MEMBER_UPLOAD_DIR . '/nada', MEMBER_UPLOAD_PUBLIC_PREFIX . '/nada')
        ?? ($existing['nada_dokument_pfad'] ?? null);

    $rechteDokumentPfad = handle_upload('rechte_pflichten_dokument', MEMBER_UPLOAD_DIR . '/rechte', MEMBER_UPLOAD_PUBLIC_PREFIX . '/rechte')
        ?? ($existing['rechte_pflichten_dokument_pfad'] ?? null);

    $rechteAkzeptiert = post_checkbox('rechte_pflichten_akzeptiert');

    return [
        'jersey_nr' => post_str('jersey_nr'),
        'camp_1' => post_checkbox('camp_1') ? 1 : 0,
        'spanien' => post_checkbox('spanien') ? 1 : 0,
        'camp_2' => post_checkbox('camp_2') ? 1 : 0,
        'tschechien' => post_checkbox('tschechien') ? 1 : 0,

        'nachname' => $nachname,
        'vorname' => $vorname,
        'sz' => post_str('sz'),
        'bezirk' => post_str('bezirk'),
        'position' => post_str('position'),
        'geburtsdatum' => post_date('geburtsdatum'),
        'verein' => post_str('verein'),
        'groesse_cm' => post_int('groesse_cm'),
        'gewicht_kg' => post_int('gewicht_kg'),
        'telefon' => $telefon,
        'email' => $email,

        'erz_name' => post_str('erz_name'),
        'erz_telefon' => $erzTelefon,
        'erz_email' => post_str('erz_email'),

        'rechte_pflichten_akzeptiert' => $rechteAkzeptiert ? 1 : 0,
        'rechte_pflichten_am' => $rechteAkzeptiert
            ? (($existing['rechte_pflichten_am'] ?? null) ?: (new DateTimeImmutable())->format('Y-m-d H:i:s'))
            : null,

        'bild_ecard_pfad' => $bildEcardPfad,
        'sozialversicherungsnummer' => post_str('sozialversicherungsnummer'),

        'nada_zertifikat' => post_str('nada_zertifikat'),
        'nada_gueltig_bis' => post_date('nada_gueltig_bis'),

        'pass_foto_pfad' => $passFotoPfad,
        'bild_ecard_hinten_pfad' => $bildEcardHintenPfad,
        'pass_foto_hinten_pfad' => $passFotoHintenPfad,
        'nada_dokument_pfad' => $nadaDokumentPfad,
        'rechte_pflichten_dokument_pfad' => $rechteDokumentPfad,
        'reisepass_nr' => post_str('reisepass_nr'),
        'reisepass_ausgestellt_am' => post_date('reisepass_ausgestellt_am'),
        'reisepass_gueltig_bis' => post_date('reisepass_gueltig_bis'),
        'geburtsland' => post_str('geburtsland'),
        'geburtsort' => post_str('geburtsort'),
        'ausstellungsbehoerde' => post_str('ausstellungsbehoerde'),

        'plz' => post_str('plz'),
        'ort' => post_str('ort'),
        'strasse' => post_str('strasse'),

        'essen' => post_str('essen'),

        'game_jersey_groesse' => post_str('game_jersey_groesse'),
        'game_hosen_groesse' => post_str('game_hosen_groesse'),
        'helm_groesse' => post_str('helm_groesse'),
        'helm_eigener' => post_checkbox('helm_eigener') ? 1 : 0,
        'tshirt_polo_groesse' => post_str('tshirt_polo_groesse'),
        'hoodie_groesse' => post_str('hoodie_groesse'),
        'mesh_shorts_groesse' => post_str('mesh_shorts_groesse'),
        'socken_groesse' => post_str('socken_groesse'),
    ] + member_collect_admin_equipment() + member_collect_doc_flags() + member_collect_camps();
}

/**
 * "Fehlt"-Häkchen der Dokumente (nur im Admin-Formular): werden nur übernommen, wenn das Formular
 * sie mitgesendet hat (Marker docflags_present), damit der persönliche Spieler-Link sie nicht leert.
 *
 * @return array<string, mixed>
 */
function member_collect_doc_flags(): array
{
    if (!isset($_POST['docflags_present'])) {
        return [];
    }
    $data = [];
    foreach (array_keys(MEMBER_DOCUMENT_REQUIRED) as $type) {
        $data['fehlt_' . $type] = post_checkbox('fehlt_' . $type) ? 1 : 0;
    }
    return $data;
}

/**
 * Pflichtdokumente: Typ => Beschriftung und die Dateien, von denen mindestens eine vorhanden sein muss
 * (bei E-Card und Reisepass Vorder- oder Rückseite).
 */
const MEMBER_DOCUMENT_REQUIRED = [
    'nada' => ['label' => 'NADA-Zertifikat', 'files' => ['nada']],
    'pass' => ['label' => 'Reisepass', 'files' => ['pass', 'pass_back']],
    'ecard' => ['label' => 'E-Card', 'files' => ['ecard', 'ecard_back']],
    'rechte' => ['label' => 'Rechte & Pflichten', 'files' => ['rechte']],
];

/**
 * Fehlende Pflichtdokumente eines Mitglieds. Ein Dokument fehlt, wenn keine Datei hochgeladen ist
 * oder es von Hand mit "Fehlt" markiert wurde.
 *
 * @param array<string, mixed> $member
 * @return array<string, array{label: string, marked: bool, missing_file: bool}> Typ => Details
 */
function member_documents_missing(array $member): array
{
    $missing = [];
    foreach (MEMBER_DOCUMENT_REQUIRED as $type => $def) {
        $hasFile = false;
        foreach ($def['files'] as $fileType) {
            if (!empty($member[MEMBER_DOCUMENT_TYPES[$fileType]['column']])) {
                $hasFile = true;
                break;
            }
        }
        $marked = !empty($member['fehlt_' . $type]);
        if (!$hasFile || $marked) {
            $missing[$type] = ['label' => $def['label'], 'marked' => $marked, 'missing_file' => !$hasFile];
        }
    }
    return $missing;
}

/**
 * Aktive Spieler im Kader mit fehlenden Dokumenten (für die Meldung im Dashboard) und Staff ohne
 * Rechte-&-Pflichten-Dokument.
 *
 * @return array{players: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>, total: int}
 */
function documents_missing_report(): array
{
    $players = [];
    foreach (member_all('aktiv', 'kader') as $m) {
        $missing = member_documents_missing($m);
        if ($missing !== []) {
            $players[] = ['id' => (int) $m['id'], 'name' => member_full_name($m), 'missing' => $missing];
        }
    }

    $staff = [];
    try {
        require_once __DIR__ . '/staff.php';
        $stmt = db()->query("SELECT * FROM staff WHERE status = 'aktiv' ORDER BY nachname, vorname");
        foreach ($stmt->fetchAll() as $row) {
            if (staff_document_path($row, 'rechte') === null) {
                $staff[] = ['id' => (int) $row['id'], 'name' => member_full_name($row)];
            }
        }
    } catch (Throwable $e) {
        // Staff-Tabelle existiert noch nicht
    }

    return ['players' => $players, 'staff' => $staff, 'total' => count($players) + count($staff)];
}

/**
 * Nur-Admin-Felder (Zimmer, Practice-Ausrüstung): werden nur übernommen, wenn sie
 * im Formular mitgesendet wurden, damit die öffentliche Mitglieder-Seite sie nicht leert.
 *
 * @return array<string, mixed>
 */
function member_collect_admin_equipment(): array
{
    $data = [];
    foreach (['zimmer_nr', 'pract_jersey_nr', 'pract_hose_groesse'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $data[$key] = post_str($key);
        }
    }
    if (in_array($_POST['kader'] ?? null, ['kader', 'nicht_im_kader'], true)) {
        $data['kader'] = $_POST['kader'];
    }
    return $data;
}

/**
 * Aktualisiert (oder legt bei $insert = true neu an) eine Teiltabellen-Zeile
 * für $memberId, aber nur wenn $data mindestens eine ihrer Spalten enthält.
 */
function upsert_child_row(string $table, array $columns, int $memberId, array $data, bool $insert): void
{
    $values = pick_columns($data, $columns);
    if ($values === []) {
        return;
    }

    // Immer als Upsert: legt fehlende Teiltabellen-Zeilen (z.B. bei Import/API-Updates) an.
    $cols = array_merge(['member_id'], array_keys($values));
    $placeholders = array_map(static fn (string $c) => ':' . $c, $cols);
    $updates = implode(', ', array_map(static fn (string $c) => "{$c} = VALUES({$c})", array_keys($values)));
    $stmt = db()->prepare(
        "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ") ON DUPLICATE KEY UPDATE {$updates}"
    );
    $stmt->execute($values + ['member_id' => $memberId]);
}

/**
 * Legt ein Mitglied neu an oder aktualisiert es (per $id), verteilt auf alle
 * Teiltabellen. Läuft komplett in einer Transaktion.
 *
 * @param array<string, mixed> $data
 * @throws RuntimeException bei doppelter E-Mail-Adresse
 */
function member_upsert(array $data, ?int $id, ?string $status = null): int
{
    // Weitere Camps: Schlüssel "camp:<id>" (1/0) und "new_camps" (Namen neuer Camps)
    $campChanges = [];
    foreach ($data as $key => $value) {
        if (is_string($key) && preg_match('/^camp:(\d+)$/', $key, $m) === 1) {
            $campChanges[(int) $m[1]] = (int) $value === 1;
        }
    }
    $newCampNames = array_values(array_filter((array) ($data['new_camps'] ?? []), static fn ($name) => trim((string) $name) !== ''));

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $membersData = pick_columns($data, MEMBERS_COLUMNS);
        if ($status !== null) {
            $membersData['status'] = $status;
        }

        if ($id === null) {
            $columns = array_keys($membersData);
            $placeholders = implode(', ', array_map(static fn (string $c) => ':' . $c, $columns));
            $stmt = $pdo->prepare('INSERT INTO members (' . implode(', ', $columns) . ") VALUES ({$placeholders})");
            $stmt->execute($membersData);
            $memberId = (int) $pdo->lastInsertId();
        } else {
            $memberId = $id;
            if ($membersData !== []) {
                $setClause = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($membersData)));
                $stmt = $pdo->prepare("UPDATE members SET {$setClause} WHERE id = :id");
                $stmt->execute($membersData + ['id' => $memberId]);
            }
        }

        $isNew = $id === null;
        upsert_child_row('member_camps', CAMPS_COLUMNS, $memberId, $data, $isNew);
        upsert_child_row('member_guardians', GUARDIAN_COLUMNS, $memberId, $data, $isNew);
        upsert_child_row('member_consents', CONSENT_COLUMNS, $memberId, $data, $isNew);
        upsert_child_row('member_documents', DOCUMENT_COLUMNS, $memberId, $data, $isNew);
        upsert_child_row('member_addresses', ADDRESS_COLUMNS, $memberId, $data, $isNew);
        upsert_child_row('member_equipment', EQUIPMENT_COLUMNS, $memberId, $data, $isNew);

        if ($isNew) {
            $stmt = $pdo->prepare('INSERT INTO member_access (member_id, verify_token) VALUES (?, ?)');
            $stmt->execute([$memberId, $data['verify_token'] ?? random_token(32)]);
        }

        // Teilnahme an weiteren Camps (Formular, Import, API)
        if ($campChanges !== [] || $newCampNames !== []) {
            member_apply_camp_changes($memberId, $campChanges, $newCampNames);
        }

        $pdo->commit();
        return $memberId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int) $e->errorInfo[1] === 1062) {
            throw new RuntimeException('Diese E-Mail-Adresse ist bereits für ein anderes Mitglied registriert.');
        }
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function member_delete(int $id): void
{
    $member = member_find_by_id($id);
    if ($member === false) {
        return;
    }

    foreach (array_column(MEMBER_DOCUMENT_TYPES, 'column') as $field) {
        if (!empty($member[$field])) {
            $path = __DIR__ . '/..' . $member[$field];
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // Teiltabellen-Zeilen werden per ON DELETE CASCADE automatisch mitgelöscht.
    $stmt = db()->prepare('DELETE FROM members WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Baut WHERE-Klausel + Parameter für Suche (Freitext) und Statusfilter.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function member_where(string $query, ?string $status, ?string $kader = null): array
{
    $conditions = [];
    $params = [];

    if ($query !== '') {
        $conditions[] = '(m.nachname LIKE :q1 OR m.vorname LIKE :q2 OR m.email LIKE :q3 OR m.verein LIKE :q4 OR m.jersey_nr LIKE :q5)';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $name) {
            $params[$name] = '%' . $query . '%'; // native Prepares erlauben keinen Parameternamen mehrfach
        }
    }
    if ($status === 'aktiv' || $status === 'inaktiv') {
        $conditions[] = 'm.status = :status';
        $params['status'] = $status;
    }

    if ($kader === 'kader' || $kader === 'nicht_im_kader') {
        $conditions[] = 'm.kader = :kader';
        $params['kader'] = $kader;
    }

    return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
}

/**
 * @return array<int, array<string, mixed>>
 */
function member_search(string $query, int $limit, int $offset, ?string $status = null, ?string $kader = null): array
{
    [$where, $params] = member_where($query, $status, $kader);
    $stmt = db()->prepare(MEMBER_JOIN_SQL . $where . ' ORDER BY m.nachname, m.vorname LIMIT :limit OFFSET :offset');
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return member_attach_camps($stmt->fetchAll());
}

function member_count(string $query, ?string $status = null, ?string $kader = null): int
{
    [$where, $params] = member_where($query, $status, $kader);
    $stmt = db()->prepare('SELECT COUNT(*) FROM members m' . $where);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * Kennzahlen für das Dashboard.
 *
 * @return array{total: int, aktiv: int, bestaetigt: int, ausstehend: int}
 */
function member_stats(): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(m.status = 'aktiv'), 0) AS aktiv,
                COALESCE(SUM(ac.verified_at IS NOT NULL), 0) AS bestaetigt
         FROM members m LEFT JOIN member_access ac ON ac.member_id = m.id"
    )->fetch();

    $total = (int) $row['total'];
    return [
        'total' => $total,
        'aktiv' => (int) $row['aktiv'],
        'bestaetigt' => (int) $row['bestaetigt'],
        'ausstehend' => $total - (int) $row['bestaetigt'],
    ];
}

/**
 * Alle Mitglieder (für Export), sortiert nach Name.
 *
 * @return array<int, array<string, mixed>>
 */
function member_all(?string $status = null, ?string $kader = null): array
{
    return member_search('', PHP_INT_MAX >> 1, 0, $status, $kader);
}

/**
 * Setzt den Zeitpunkt der Zustimmung passend zum Häkchen (bestehender
 * Zeitpunkt bleibt erhalten).
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed>|null $existing
 * @return array<string, mixed>
 */
function member_apply_consent_timestamp(array $data, ?array $existing): array
{
    if (!isset($data['rechte_pflichten_akzeptiert'])) {
        return $data;
    }
    if ((int) $data['rechte_pflichten_akzeptiert'] === 1) {
        $data['rechte_pflichten_am'] = ($existing['rechte_pflichten_am'] ?? null) ?: (new DateTimeImmutable())->format('Y-m-d H:i:s');
    } else {
        $data['rechte_pflichten_am'] = null;
    }
    return $data;
}

/**
 * Für den Import: Mitglied anlegen oder (per E-Mail) aktualisieren.
 *
 * @param array<string, mixed> $data Werte laut io_convert_row()
 * @return string 'created' | 'updated'
 */
function member_import_save(array $data, bool $updateExisting): string
{
    $existing = member_find_by_email((string) $data['email']);
    $data = member_apply_consent_timestamp($data, $existing === false ? null : $existing);

    $status = $data['status'] ?? null;
    unset($data['status']);

    if ($existing !== false) {
        if (!$updateExisting) {
            throw new RuntimeException('Mitglied mit dieser E-Mail existiert bereits.');
        }
        member_upsert($data, (int) $existing['id'], $status);
        return 'updated';
    }

    member_upsert($data, null, $status);
    return 'created';
}

/**
 * Absoluter Dateipfad eines Mitglieds-Dokuments oder null (nicht vorhanden / außerhalb von uploads/).
 *
 * @param array<string, mixed> $member
 */
function member_document_path(array $member, string $type): ?string
{
    if (!isset(MEMBER_DOCUMENT_TYPES[$type]) || empty($member[MEMBER_DOCUMENT_TYPES[$type]['column']])) {
        return null;
    }

    $path = realpath(__DIR__ . '/..' . $member[MEMBER_DOCUMENT_TYPES[$type]['column']]);
    $root = realpath(MEMBER_UPLOAD_DIR);
    if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    return $path;
}

/**
 * Welche Dokumente sind vorhanden? [Typ => bool]
 *
 * @param array<string, mixed> $member
 * @return array<string, bool>
 */
function member_documents_present(array $member): array
{
    $present = [];
    foreach (array_keys(MEMBER_DOCUMENT_TYPES) as $type) {
        $present[$type] = !empty($member[MEMBER_DOCUMENT_TYPES[$type]['column']]);
    }
    return $present;
}

/**
 * Speichert (ersetzt) oder entfernt ein Dokument. $inputName = Name des Upload-Feldes in $_FILES,
 * null = Dokument entfernen. Die alte Datei wird gelöscht.
 *
 * @throws RuntimeException bei ungültigem Upload
 */
function member_set_document(int $memberId, string $type, ?string $inputName): void
{
    $def = MEMBER_DOCUMENT_TYPES[$type] ?? null;
    if ($def === null) {
        throw new RuntimeException('Unbekannter Dokumenttyp.');
    }

    $member = member_find_by_id($memberId);
    if ($member === false) {
        throw new RuntimeException('Mitglied nicht gefunden.');
    }

    $newPath = null;
    if ($inputName !== null) {
        $newPath = handle_upload($inputName, MEMBER_UPLOAD_DIR . '/' . $def['dir'], MEMBER_UPLOAD_PUBLIC_PREFIX . '/' . $def['dir']);
        if ($newPath === null) {
            throw new RuntimeException('Keine Datei übermittelt.');
        }
    }

    $old = member_document_path($member, $type);
    upsert_child_row('member_documents', DOCUMENT_COLUMNS, $memberId, [$def['column'] => $newPath], false);
    if ($old !== null) {
        @unlink($old);
    }
}

/**
 * Sendet ein Dokument an den Browser/Client (bricht mit 404 ab, falls nicht vorhanden).
 *
 * @param array<string, mixed> $member
 */
function member_document_send(array $member, string $type, bool $inline = true): never
{
    $path = member_document_path($member, $type);
    if ($path === null) {
        http_response_code(404);
        exit('Dokument nicht vorhanden.');
    }

    $data = crypto_file_read($path); // hochgeladene Dokumente liegen verschlüsselt auf dem Server
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $type . '-' . ($member['nachname'] ?? '') . '-' . ($member['vorname'] ?? '')) . '.' . $ext;

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

/**
 * Löscht mehrere Mitglieder samt hochgeladener Dokumente.
 *
 * @param array<int, int|string> $ids
 * @return int Anzahl tatsächlich gelöschter Mitglieder
 */
function member_delete_many(array $ids): int
{
    $deleted = 0;
    foreach (array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)) as $id) {
        if (member_find_by_id($id) !== false) {
            member_delete($id);
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Löscht ALLE Mitglieder samt Dokumenten (Admin-Benutzer und API-Zugänge bleiben erhalten).
 *
 * @return int Anzahl gelöschter Mitglieder
 */
function member_delete_all(): int
{
    $ids = db()->query('SELECT id FROM members')->fetchAll(PDO::FETCH_COLUMN);
    return member_delete_many($ids);
}

/**
 * Liest die Camp-Auswahl aus dem Formular: Häkchen je weiterem Camp und Namen neuer Camps.
 * Ohne Formular-Marker "camps_present" (z. B. ausgeblendetes Feld) wird nichts verändert.
 *
 * @return array<string, mixed>
 */
function member_collect_camps(): array
{
    if (!isset($_POST['camps_present'])) {
        return [];
    }

    $data = [];
    foreach (camps_all() as $camp) {
        $data['camp:' . $camp['id']] = isset($_POST['camp'][$camp['id']]) ? 1 : 0;
    }
    $names = [];
    foreach ((array) ($_POST['new_camps'] ?? []) as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    if ($names !== []) {
        $data['new_camps'] = $names;
    }
    return $data;
}
