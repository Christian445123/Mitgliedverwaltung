<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';

const MEMBER_UPLOAD_DIR = __DIR__ . '/../uploads';
const MEMBER_UPLOAD_PUBLIC_PREFIX = '/uploads';

/**
 * Spalten je Teiltabelle. Die Kern-Tabelle "members" führt nur Stamm- und
 * Suchfelder, alles andere ist 1:1 über member_id ausgelagert.
 */
const MEMBERS_COLUMNS = ['jersey_nr', 'nachname', 'vorname', 'sz', 'bezirk', 'position', 'geburtsdatum', 'verein', 'groesse_cm', 'gewicht_kg', 'telefon', 'email'];
const CAMPS_COLUMNS = ['camp_1', 'spanien', 'camp_2', 'tschechien'];
const GUARDIAN_COLUMNS = ['erz_name', 'erz_telefon', 'erz_email'];
const CONSENT_COLUMNS = ['rechte_pflichten_akzeptiert', 'rechte_pflichten_am'];
const DOCUMENT_COLUMNS = ['bild_ecard_pfad', 'sozialversicherungsnummer', 'nada_zertifikat', 'nada_gueltig_bis', 'pass_foto_pfad', 'reisepass_nr', 'reisepass_ausgestellt_am', 'reisepass_gueltig_bis', 'geburtsland', 'geburtsort', 'ausstellungsbehoerde'];
const ADDRESS_COLUMNS = ['plz', 'ort', 'strasse'];
const EQUIPMENT_COLUMNS = ['essen', 'game_jersey_groesse', 'game_hosen_groesse', 'helm_groesse', 'helm_eigener', 'tshirt_polo_groesse', 'hoodie_groesse', 'mesh_shorts_groesse', 'socken_groesse'];

const MEMBER_JOIN_SQL = '
    SELECT m.*,
        c.camp_1, c.spanien, c.camp_2, c.tschechien,
        g.erz_name, g.erz_telefon, g.erz_email,
        co.rechte_pflichten_akzeptiert, co.rechte_pflichten_am,
        d.bild_ecard_pfad, d.sozialversicherungsnummer, d.nada_zertifikat, d.nada_gueltig_bis,
        d.pass_foto_pfad, d.reisepass_nr, d.reisepass_ausgestellt_am, d.reisepass_gueltig_bis,
        d.geburtsland, d.geburtsort, d.ausstellungsbehoerde,
        a.plz, a.ort, a.strasse,
        e.essen, e.game_jersey_groesse, e.game_hosen_groesse, e.helm_groesse, e.helm_eigener,
        e.tshirt_polo_groesse, e.hoodie_groesse, e.mesh_shorts_groesse, e.socken_groesse,
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
    return $stmt->fetch();
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_email(string $email)
{
    $stmt = db()->prepare(MEMBER_JOIN_SQL . ' WHERE m.email = ? LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_token(string $token)
{
    $stmt = db()->prepare(MEMBER_JOIN_SQL . ' WHERE ac.verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch();
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
function member_collect_input(array $existing = []): array
{
    $nachname = post_str('nachname');
    $vorname = post_str('vorname');
    $email = post_str('email');

    if ($nachname === null || $vorname === null || $email === null) {
        throw new RuntimeException('Nachname, Vorname und E-Mail-Adresse sind Pflichtfelder.');
    }

    if (!is_valid_email($email)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    }

    $bildEcardPfad = handle_upload('bild_ecard', MEMBER_UPLOAD_DIR . '/ecard', MEMBER_UPLOAD_PUBLIC_PREFIX . '/ecard')
        ?? ($existing['bild_ecard_pfad'] ?? null);

    $passFotoPfad = handle_upload('pass_foto', MEMBER_UPLOAD_DIR . '/pass', MEMBER_UPLOAD_PUBLIC_PREFIX . '/pass')
        ?? ($existing['pass_foto_pfad'] ?? null);

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
        'telefon' => post_str('telefon'),
        'email' => $email,

        'erz_name' => post_str('erz_name'),
        'erz_telefon' => post_str('erz_telefon'),
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
    ];
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

    if ($insert) {
        $cols = array_merge(['member_id'], array_keys($values));
        $placeholders = array_map(static fn (string $c) => ':' . $c, $cols);
        $stmt = db()->prepare(
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($values + ['member_id' => $memberId]);
        return;
    }

    $setClause = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($values)));
    $stmt = db()->prepare("UPDATE {$table} SET {$setClause} WHERE member_id = :member_id");
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

    foreach (['bild_ecard_pfad', 'pass_foto_pfad'] as $field) {
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
 * @return array<int, array<string, mixed>>
 */
function member_search(string $query, int $limit, int $offset): array
{
    if ($query === '') {
        $stmt = db()->prepare(MEMBER_JOIN_SQL . ' ORDER BY m.nachname, m.vorname LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $like = '%' . $query . '%';
    $stmt = db()->prepare(
        MEMBER_JOIN_SQL . '
         WHERE m.nachname LIKE :q OR m.vorname LIKE :q OR m.email LIKE :q OR m.verein LIKE :q OR m.jersey_nr LIKE :q
         ORDER BY m.nachname, m.vorname LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue('q', $like, PDO::PARAM_STR);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function member_count(string $query): int
{
    if ($query === '') {
        return (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn();
    }

    $like = '%' . $query . '%';
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM members
         WHERE nachname LIKE :q OR vorname LIKE :q OR email LIKE :q OR verein LIKE :q OR jersey_nr LIKE :q'
    );
    $stmt->bindValue('q', $like, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}
