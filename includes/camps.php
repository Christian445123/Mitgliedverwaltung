<?php

declare(strict_types=1);

/**
 * Weitere Camps: Zusätzlich zu den vier festen Camps (Camp 1, Spanien, Camp 2, Tschechien) können
 * beliebig viele neue Camps angelegt werden. Sie stehen dann bei jedem Spieler als Ja/Nein-Feld zur
 * Verfügung (Formular, Import, Export, API).
 *
 * Tabellen: camps (Stammliste) und member_camp_entries (Teilnahme je Mitglied).
 */

require_once __DIR__ . '/../db.php';

/** Namen der festen Camps (dürfen nicht doppelt angelegt werden). */
const CAMPS_FIXED_NAMES = ['Camp 1', 'Spanien', 'Camp 2', 'Tschechien'];

/** Legt die Tabellen an, falls sie fehlen. Nie innerhalb einer Transaktion aufrufen (DDL). */
function camps_ensure_tables(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'camps'")->fetchColumn();
    if ($exists === false) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS camps (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_camp_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    $exists = $pdo->query("SHOW TABLES LIKE 'member_camp_entries'")->fetchColumn();
    if ($exists === false) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS member_camp_entries (
                member_id INT UNSIGNED NOT NULL,
                camp_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (member_id, camp_id),
                CONSTRAINT fk_camp_entries_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
                CONSTRAINT fk_camp_entries_camp FOREIGN KEY (camp_id) REFERENCES camps (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

/**
 * Dasselbe für Staff: Camps gelten auch für Trainer/Betreuer, die mitfahren.
 * Erst aufrufen, nachdem die Tabelle "staff" existiert (staff_ensure_table).
 */
function staff_camps_ensure_tables(PDO $pdo): void
{
    $exists = $pdo->query("SHOW TABLES LIKE 'staff_camps'")->fetchColumn();
    if ($exists === false) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_camps (
                staff_id INT UNSIGNED PRIMARY KEY,
                camp_1 TINYINT(1) NOT NULL DEFAULT 0,
                spanien TINYINT(1) NOT NULL DEFAULT 0,
                camp_2 TINYINT(1) NOT NULL DEFAULT 0,
                tschechien TINYINT(1) NOT NULL DEFAULT 0,
                CONSTRAINT fk_staff_camps_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
    $exists = $pdo->query("SHOW TABLES LIKE 'staff_camp_entries'")->fetchColumn();
    if ($exists === false) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_camp_entries (
                staff_id INT UNSIGNED NOT NULL,
                camp_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (staff_id, camp_id),
                CONSTRAINT fk_staff_camp_entries_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE,
                CONSTRAINT fk_staff_camp_entries_camp FOREIGN KEY (camp_id) REFERENCES camps (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

/**
 * Referenz auf den internen Cache von camps_all(), damit camp_create()/camp_rename()/camp_delete()
 * ihn nach einer Änderung leeren können (sonst würde z.B. ein gerade neu angelegtes Camp innerhalb
 * derselben Anfrage noch als "unbekannt" gelten).
 */
function &camps_cache_ref(): ?array
{
    static $cache = null;
    return $cache;
}

/**
 * Alle weiteren Camps in Anzeigereihenfolge.
 *
 * @return array<int, array{id: int, name: string}>
 */
function camps_all(): array
{
    $cache = &camps_cache_ref();
    if ($cache !== null) {
        return $cache;
    }
    try {
        $rows = db()->query('SELECT id, name FROM camps ORDER BY sort_order, name')->fetchAll();
    } catch (Throwable $e) {
        return $cache = []; // Tabelle fehlt noch (keine weiteren Camps) - Anwendung läuft normal weiter
    }
    return $cache = array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
}

function camps_cache_clear(): void
{
    $cache = &camps_cache_ref();
    $cache = null;
}


/**
 * Legt ein neues Camp an oder liefert die ID eines gleichnamigen.
 *
 * @throws RuntimeException bei ungültigem Namen
 */
function camp_create(string $name): int
{
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) > 100) {
        throw new RuntimeException('Der Name des Camps muss 1 bis 100 Zeichen lang sein.');
    }
    foreach (CAMPS_FIXED_NAMES as $fixed) {
        if (mb_strtolower($fixed) === mb_strtolower($name)) {
            throw new RuntimeException("\"{$name}\" ist bereits ein festes Camp.");
        }
    }

    $stmt = db()->prepare('SELECT id FROM camps WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM camps')->fetchColumn();
    db()->prepare('INSERT INTO camps (name, sort_order) VALUES (?, ?)')->execute([$name, $max + 1]);
    $id = (int) db()->lastInsertId();
    camps_cache_clear();
    app_log('camp.create', 'Camp angelegt', ['target_type' => 'camp', 'target_id' => $id, 'name' => $name]);
    return $id;
}

function camp_delete(int $id): void
{
    db()->prepare('DELETE FROM camps WHERE id = ?')->execute([$id]); // Teilnahmen werden per ON DELETE CASCADE entfernt
    camps_cache_clear();
    app_log('camp.delete', 'Camp gelöscht', ['target_type' => 'camp', 'target_id' => $id], 'warning');
}

function camp_rename(int $id, string $name): void
{
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) > 100) {
        throw new RuntimeException('Der Name des Camps muss 1 bis 100 Zeichen lang sein.');
    }
    foreach (CAMPS_FIXED_NAMES as $fixed) {
        if (mb_strtolower($fixed) === mb_strtolower($name)) {
            throw new RuntimeException("\"{$name}\" ist bereits ein festes Camp.");
        }
    }
    try {
        db()->prepare('UPDATE camps SET name = ? WHERE id = ?')->execute([$name, $id]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            throw new RuntimeException("Ein Camp \"{$name}\" gibt es schon.");
        }
        throw $e;
    }
    camps_cache_clear();
    app_log('camp.rename', 'Camp umbenannt', ['target_type' => 'camp', 'target_id' => $id, 'name' => $name]);
}

/**
 * Alle Camps (fest + weitere) als Auswahlliste für Filter/Massenzuweisung: Wert => Beschriftung.
 * Feste Camps haben als Wert ihren Spaltennamen (z. B. "camp_1"), weitere Camps "c<id>".
 *
 * @return array<string, string>
 */
function camps_options(): array
{
    $options = [];
    foreach (CAMPS_COLUMNS as $i => $column) {
        $options[$column] = CAMPS_FIXED_NAMES[$i];
    }
    foreach (camps_all() as $camp) {
        $options['c' . $camp['id']] = $camp['name'];
    }
    return $options;
}

/**
 * SQL-Bedingung (ohne führendes "AND"), um Zeilen auf Teilnahme an einem Camp zu filtern - für Mitglieder
 * ($entity = 'members', $idExpr z. B. "m.id") und Staff ($entity = 'staff', $idExpr z. B. "staff.id").
 * $camp ist ein Wert aus camps_options() (Spaltenname oder "c<id>") oder null/"" (kein Filter).
 *
 * @param array<string, int> $params wird bei Bedarf um ":camp" ergänzt
 */
function camp_filter_condition(string $entity, string $idExpr, ?string $camp, array &$params): ?string
{
    if ($camp === null || $camp === '') {
        return null;
    }
    [$campsTable, $idCol] = $entity === 'staff' ? ['staff_camps', 'staff_id'] : ['member_camps', 'member_id'];
    $entriesTable = $entity === 'staff' ? 'staff_camp_entries' : 'member_camp_entries';

    if (in_array($camp, CAMPS_COLUMNS, true)) {
        return "EXISTS (SELECT 1 FROM {$campsTable} cf WHERE cf.{$idCol} = {$idExpr} AND cf.{$camp} = 1)";
    }
    if (preg_match('/^c(\d+)$/', $camp, $m)) {
        $params['camp'] = (int) $m[1];
        return "EXISTS (SELECT 1 FROM {$entriesTable} cf WHERE cf.{$idCol} = {$idExpr} AND cf.camp_id = :camp)";
    }
    return null;
}

/**
 * Weist ein Camp mehreren Mitgliedern oder Staff-Personen auf einmal zu bzw. entfernt es wieder.
 *
 * @param array<int, int> $ids
 * @return int Anzahl der bearbeiteten Datensätze
 */
function camp_bulk_assign(string $entity, array $ids, string $camp, bool $add): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
    if ($ids === [] || !array_key_exists($camp, camps_options())) {
        return 0;
    }
    [$campsTable, $idCol] = $entity === 'staff' ? ['staff_camps', 'staff_id'] : ['member_camps', 'member_id'];
    $entriesTable = $entity === 'staff' ? 'staff_camp_entries' : 'member_camp_entries';

    if (in_array($camp, CAMPS_COLUMNS, true)) {
        $stmt = db()->prepare(
            "INSERT INTO {$campsTable} ({$idCol}, {$camp}) VALUES (:id, :val) ON DUPLICATE KEY UPDATE {$camp} = VALUES({$camp})"
        );
        foreach ($ids as $id) {
            $stmt->execute(['id' => $id, 'val' => $add ? 1 : 0]);
        }
        return count($ids);
    }

    $campId = (int) substr($camp, 1);
    $stmt = $add
        ? db()->prepare("INSERT IGNORE INTO {$entriesTable} ({$idCol}, camp_id) VALUES (:id, :camp)")
        : db()->prepare("DELETE FROM {$entriesTable} WHERE {$idCol} = :id AND camp_id = :camp");
    foreach ($ids as $id) {
        $stmt->execute(['id' => $id, 'camp' => $campId]);
    }
    return count($ids);
}

/**
 * Ergänzt Mitglieds-Zeilen um die Teilnahme an weiteren Camps: je Camp ein Schlüssel "camp:<id>" (1/0).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function member_attach_camps(array $rows): array
{
    $camps = camps_all();
    if ($camps === [] || $rows === []) {
        return $rows;
    }

    $ids = array_map(static fn (array $r) => (int) $r['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT member_id, camp_id FROM member_camp_entries WHERE member_id IN ({$marks})");
    $stmt->execute($ids);
    $entries = [];
    foreach ($stmt->fetchAll() as $e) {
        $entries[(int) $e['member_id']][(int) $e['camp_id']] = true;
    }

    foreach ($rows as &$row) {
        foreach ($camps as $camp) {
            $row['camp:' . $camp['id']] = isset($entries[(int) $row['id']][$camp['id']]) ? 1 : 0;
        }
    }
    unset($row);

    return $rows;
}

/**
 * Wendet Änderungen an den weiteren Camps eines Mitglieds an (innerhalb der Transaktion des Aufrufers).
 *
 * @param array<int, bool> $changes camp_id => teilgenommen (true) oder nicht (false)
 * @param array<int, string> $newNames Namen neuer Camps, an denen das Mitglied teilnimmt
 */
function member_apply_camp_changes(int $memberId, array $changes, array $newNames): void
{
    foreach ($newNames as $name) {
        if (trim((string) $name) !== '') {
            $changes[camp_create((string) $name)] = true;
        }
    }
    foreach ($changes as $campId => $takesPart) {
        if ($takesPart) {
            db()->prepare('INSERT IGNORE INTO member_camp_entries (member_id, camp_id) VALUES (?, ?)')->execute([$memberId, $campId]);
        } else {
            db()->prepare('DELETE FROM member_camp_entries WHERE member_id = ? AND camp_id = ?')->execute([$memberId, $campId]);
        }
    }
}
