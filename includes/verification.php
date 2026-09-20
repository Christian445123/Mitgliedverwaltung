<?php

declare(strict_types=1);

/**
 * Bestätigung der Daten durch Spieler und Staff, Zugangslinks und Massenmail.
 *
 * Ein Spieler/Staff-Mitglied "bestätigt" seine Daten, indem es den persönlichen Link mit E-Mail-Adresse und Zugangscode
 * öffnet (Zeitpunkt = verified_at). Der Verein kann die Bestätigung zurücksetzen, damit alle ihre Daten erneut prüfen
 * müssen, und Link samt neuem Zugangscode per E-Mail an eine Auswahl oder alle senden.
 *
 *   Spieler: Tabelle member_access (siehe member_repository.php, Seite mitglied-formular.php)
 *   Staff:   Tabelle staff_access  (Seite staff-formular.php); Identität = E-Mail-Adresse, ohne E-Mail der Nachname
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_repository.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/Mailer.php';

// ── Staff-Zugang (Tabelle staff_access) ─────────────────────────────────

function staff_access_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    staff_ensure_table(db());
    db()->exec(
        'CREATE TABLE IF NOT EXISTS staff_access (
            staff_id INT UNSIGNED PRIMARY KEY,
            verify_token VARCHAR(64) NOT NULL,
            access_password_hash VARCHAR(255) DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            failed_verify_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            verify_locked_until DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_staff_verify_token (verify_token),
            CONSTRAINT fk_staff_access_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Legt bei Bedarf den Zugang (Token) einer Person im Staff an und liefert ihn. @return array<string, mixed> */
function staff_access_row(int $staffId): array
{
    staff_access_ensure_table();
    $stmt = db()->prepare('SELECT * FROM staff_access WHERE staff_id = ?');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch();
    if ($row === false) {
        $insert = db()->prepare('INSERT IGNORE INTO staff_access (staff_id, verify_token) VALUES (?, ?)');
        $insert->execute([$staffId, random_token(32)]);
        $stmt->execute([$staffId]);
        $row = $stmt->fetch();
    }
    return $row;
}

/** Person im Staff samt Zugang zu einem Token oder false. @return array<string, mixed>|false */
function staff_find_by_token(string $token)
{
    staff_access_ensure_table();
    $stmt = db()->prepare(
        'SELECT s.*, a.verify_token, a.access_password_hash, a.verified_at, a.failed_verify_attempts, a.verify_locked_until
         FROM staff s JOIN staff_access a ON a.staff_id = s.id WHERE a.verify_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/** @return array<int, string|null> Staff-ID => Zeitpunkt der Bestätigung (null = ausstehend) */
function staff_verified_map(): array
{
    staff_access_ensure_table();
    $map = [];
    foreach (db()->query('SELECT staff_id, verified_at FROM staff_access')->fetchAll() as $r) {
        $map[(int) $r['staff_id']] = $r['verified_at'];
    }
    return $map;
}

function staff_build_link(string $token): string
{
    return APP_BASE_URL . '/staff-formular.php?token=' . $token;
}

// ── Gemeinsame Funktionen für Spieler ('members') und Staff ('staff') ───

function verif_entity(string $entity): string
{
    return $entity === 'staff' ? 'staff' : 'members';
}

/** @return array{0: string, 1: string} Tabelle und ID-Spalte des Zugangs */
function verif_table(string $entity): array
{
    return verif_entity($entity) === 'staff' ? ['staff_access', 'staff_id'] : ['member_access', 'member_id'];
}

/**
 * Setzt die Bestätigung zurück. $ids = null: bei Spielern alle aktiven, bei Staff alle aktiven Personen.
 *
 * @param array<int, int>|null $ids
 * @return int Anzahl zurückgesetzter Personen
 */
function verif_reset(string $entity, ?array $ids): int
{
    $entity = verif_entity($entity);
    if ($entity === 'staff') {
        staff_access_ensure_table();
        // Zeilen für alle Personen anlegen, damit sie einen Token haben
        foreach (db()->query('SELECT id FROM staff')->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            staff_access_row((int) $sid);
        }
    }
    [$table, $col] = verif_table($entity);
    $base = $entity === 'staff' ? 'staff' : 'members';

    if ($ids === null) {
        $stmt = db()->prepare("UPDATE {$table} t JOIN {$base} b ON b.id = t.{$col} SET t.verified_at = NULL WHERE b.status = 'aktiv' AND t.verified_at IS NOT NULL");
        $stmt->execute();
        $count = $stmt->rowCount();
    } else {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE {$table} SET verified_at = NULL WHERE {$col} IN ({$marks}) AND verified_at IS NOT NULL");
        $stmt->execute($ids);
        $count = $stmt->rowCount();
    }
    app_log('verification.reset', 'Bestätigung zurückgesetzt (' . ($entity === 'staff' ? 'Staff' : 'Spieler') . ', ' . $count . ')', ['entity' => $entity, 'count' => $count, 'alle' => $ids === null], 'warning');
    return $count;
}

/**
 * Sendet Link und einen neuen Zugangscode per E-Mail (der alte Code wird ungültig).
 *
 * @return array{id: int, name: string, status: string, message: string} status: sent | no_email | error
 */
function verif_send_link(string $entity, int $id): array
{
    $entity = verif_entity($entity);
    if ($entity === 'staff') {
        $person = staff_find_by_id($id);
        if ($person === false) {
            return ['id' => $id, 'name' => '', 'status' => 'error', 'message' => 'Person nicht gefunden.'];
        }
        $access = staff_access_row($id);
        $token = (string) $access['verify_token'];
        $link = staff_build_link($token);
    } else {
        $person = member_find_by_id($id);
        if ($person === false) {
            return ['id' => $id, 'name' => '', 'status' => 'error', 'message' => 'Mitglied nicht gefunden.'];
        }
        $token = (string) $person['verify_token'];
        $link = build_member_link($token);
    }
    $name = trim((string) $person['vorname'] . ' ' . (string) $person['nachname']);

    if (empty($person['email'])) {
        return ['id' => $id, 'name' => $name, 'status' => 'no_email', 'message' => 'Keine E-Mail-Adresse hinterlegt.'];
    }

    $code = verif_regenerate_password($entity, $id);
    $what = $entity === 'staff'
        ? 'Damit kannst du deine hinterlegten Daten als Trainer/Betreuer prüfen, bestätigen und bei Bedarf korrigieren:'
        : 'Damit kannst du deine hinterlegten Daten prüfen, bestätigen und bei Bedarf korrigieren:';
    $html = '<p>Hallo ' . h((string) $person['vorname']) . ',</p>'
        . '<p>hier ist dein persönlicher Link zur Mitgliederverwaltung des AFBÖ U19. ' . $what . '</p>'
        . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
        . '<p>Zum Öffnen benötigst du zusätzlich deine E-Mail-Adresse und folgenden Zugangscode:</p>'
        . '<p style="font-size:1.2em;font-weight:bold;letter-spacing:1px;">' . h($code) . '</p>'
        . '<p>Beim ersten Öffnen bittet dich der Verein, der <a href="' . h(APP_BASE_URL . '/datenschutz.php') . '">Datenschutzerklärung</a> zuzustimmen (bei Minderjährigen: durch die Erziehungsberechtigten). Bitte prüfe danach deine Angaben und speichere sie. Falls du diesen Link nicht erwartest, wende dich bitte an den Verein.</p>';

    try {
        (new Mailer())->send((string) $person['email'], $name, 'Bitte prüfe deine Daten – AFBÖ U19', $html);
    } catch (Throwable $e) {
        return ['id' => $id, 'name' => $name, 'status' => 'error', 'message' => 'E-Mail konnte nicht gesendet werden: ' . (APP_DEBUG ? $e->getMessage() : 'Bitte später erneut versuchen.')];
    }
    app_log('member.email_sent', 'Zugangslink per E-Mail versendet (' . ($entity === 'staff' ? 'Staff' : 'Spieler') . ')', ['target_type' => $entity === 'staff' ? 'staff' : 'member', 'target_id' => $id]);
    return ['id' => $id, 'name' => $name, 'status' => 'sent', 'message' => 'Gesendet an ' . $person['email']];
}

/** Erzeugt einen neuen Zugangscode und liefert ihn im Klartext (nur einmal sichtbar). */
function verif_regenerate_password(string $entity, int $id): string
{
    if (verif_entity($entity) === 'staff') {
        staff_access_row($id);
        $code = generate_access_password();
        $stmt = db()->prepare('UPDATE staff_access SET access_password_hash = ?, failed_verify_attempts = 0, verify_locked_until = NULL WHERE staff_id = ?');
        $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $id]);
        return $code;
    }
    return member_regenerate_access_password($id);
}

/**
 * Massenmail: sendet an mehrere Personen nacheinander (Ausführungszeit unbegrenzt, da SMTP je Mail dauert).
 *
 * @param array<int, int> $ids
 * @return array<int, array{id: int, name: string, status: string, message: string}>
 */
function verif_send_many(string $entity, array $ids): array
{
    @set_time_limit(0);
    $results = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i) => $i > 0))) as $id) {
        $results[] = verif_send_link($entity, $id);
    }
    $sent = count(array_filter($results, static fn (array $r) => $r['status'] === 'sent'));
    app_log('verification.mass_mail', 'Massenmail zur Datenprüfung (' . (verif_entity($entity) === 'staff' ? 'Staff' : 'Spieler') . '): ' . $sent . ' von ' . count($results) . ' gesendet', ['entity' => verif_entity($entity), 'sent' => $sent, 'total' => count($results)]);
    return $results;
}

/**
 * IDs für die Massenaktion: 'all' = alle aktiven, 'pending' = alle aktiven ohne Bestätigung.
 *
 * @return array<int, int>
 */
function verif_ids(string $entity, string $mode): array
{
    if (verif_entity($entity) === 'staff') {
        staff_access_ensure_table();
        $sql = 'SELECT b.id FROM staff b LEFT JOIN staff_access t ON t.staff_id = b.id';
    } else {
        $sql = 'SELECT b.id FROM members b LEFT JOIN member_access t ON t.member_id = b.id';
    }
    $sql .= " WHERE b.status = 'aktiv'" . ($mode === 'pending' ? ' AND t.verified_at IS NULL' : '') . ' ORDER BY b.id';
    return array_map('intval', db()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/** Anzahl der Personen mit E-Mail-Adresse unter den IDs (nur diese erhalten eine Mail). @param array<int, int> $ids */
function verif_count_with_email(string $entity, array $ids): int
{
    if ($ids === []) {
        return 0;
    }
    $table = verif_entity($entity) === 'staff' ? 'staff' : 'members';
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM {$table} WHERE id IN ({$marks}) AND email IS NOT NULL AND email <> ''");
    $stmt->execute(array_values($ids));
    return (int) $stmt->fetchColumn();
}
