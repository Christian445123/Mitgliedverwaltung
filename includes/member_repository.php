<?php

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';

const MEMBER_UPLOAD_DIR = __DIR__ . '/../uploads';
const MEMBER_UPLOAD_PUBLIC_PREFIX = '/uploads';

/**
 * @return array<string, mixed>|false
 */
function member_find_by_id(int $id)
{
    $stmt = db()->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * @return array<string, mixed>|false
 */
function member_find_by_email(string $email)
{
    $stmt = db()->prepare('SELECT * FROM members WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    return $stmt->fetch();
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
 * Legt ein Mitglied neu an oder aktualisiert es (per $id).
 *
 * @param array<string, mixed> $data
 * @throws RuntimeException bei doppelter E-Mail-Adresse
 */
function member_upsert(array $data, ?int $id, ?string $status = null): int
{
    if ($status !== null) {
        $data['status'] = $status;
    }

    $columns = array_keys($data);

    try {
        if ($id === null) {
            $placeholders = implode(', ', array_map(static fn (string $c) => ':' . $c, $columns));
            $columnList = implode(', ', $columns);
            $stmt = db()->prepare("INSERT INTO members ({$columnList}) VALUES ({$placeholders})");
            $stmt->execute($data);
            return (int) db()->lastInsertId();
        }

        $setClause = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", $columns));
        $stmt = db()->prepare("UPDATE members SET {$setClause} WHERE id = :id");
        $stmt->execute($data + ['id' => $id]);
        return $id;
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            throw new RuntimeException('Diese E-Mail-Adresse ist bereits für ein anderes Mitglied registriert.');
        }
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

    $stmt = db()->prepare('DELETE FROM members WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function member_search(string $query, int $limit, int $offset): array
{
    if ($query === '') {
        $stmt = db()->prepare('SELECT * FROM members ORDER BY nachname, vorname LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $like = '%' . $query . '%';
    $stmt = db()->prepare(
        'SELECT * FROM members
         WHERE nachname LIKE :q OR vorname LIKE :q OR email LIKE :q OR verein LIKE :q OR jersey_nr LIKE :q
         ORDER BY nachname, vorname LIMIT :limit OFFSET :offset'
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
