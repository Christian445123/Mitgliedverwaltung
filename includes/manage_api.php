<?php

declare(strict_types=1);

/**
 * Verwaltungsfunktionen für die API (Desktop-App): Feld-Rechte, Camps, Protokoll, eigenes Passwort und
 * persönliche Zugangslinks. Es gelten dieselben Regeln und Berechtigungen wie in den Web-Seiten
 * permissions.php, camps.php, logs.php, account.php und member-link.php.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/field_access.php';
require_once __DIR__ . '/camps.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/app_sessions.php';

// ── Feld-Rechte ───────────────────────────────────────────────────────────

/** @return array<int, array<string, mixed>> */
function mg_field_permissions(): array
{
    $settings = field_access_settings();
    $out = [];
    foreach (field_access_registry() as $key => $def) {
        $out[] = [
            'key' => $key,
            'label' => $def[0],
            'group' => $def[1],
            'admin_only' => !empty($def[3]['admin']),
            'core' => !empty($def[3]['core']),
            'player' => $settings[$key]['player'],
            'editor' => !empty($def[3]['core']) ? true : (bool) $settings[$key]['editor'],
        ];
    }
    return $out;
}

/**
 * @param array<string, mixed> $input key => ['player' => edit|view|hidden, 'editor' => bool]
 * @return int Anzahl geänderter Felder
 */
function mg_field_permissions_save(array $input, string $by): int
{
    $before = field_access_settings();
    $registry = field_access_registry();
    $new = [];
    foreach ($registry as $key => $def) {
        $row = is_array($input[$key] ?? null) ? $input[$key] : [];
        $player = (string) ($row['player'] ?? $before[$key]['player']);
        $new[$key] = [
            'player' => in_array($player, ['edit', 'view', 'hidden'], true) ? $player : $before[$key]['player'],
            'editor' => array_key_exists('editor', $row) ? (bool) $row['editor'] : (bool) $before[$key]['editor'],
        ];
    }
    field_access_save($new, $by);

    $changes = [];
    foreach ($new as $key => $s) {
        if (!empty($registry[$key][3]['core'])) {
            $s['editor'] = true;
        }
        if ($s['player'] !== $before[$key]['player'] || $s['editor'] !== $before[$key]['editor']) {
            $changes[$key] = ['spieler' => $s['player'], 'bearbeiter_sichtbar' => $s['editor']];
        }
    }
    app_log('permissions.update', count($changes) . ' Feld-Rechte geändert (Desktop-App)', ['changes' => $changes]);
    return count($changes);
}

// ── Camps ─────────────────────────────────────────────────────────────────

/** @return array<int, array{id: int, name: string, members: int}> */
function mg_camps(): array
{
    $rows = db()->query(
        'SELECT c.id, c.name, COUNT(e.member_id) AS members
         FROM camps c LEFT JOIN member_camp_entries e ON e.camp_id = c.id
         GROUP BY c.id, c.name, c.sort_order ORDER BY c.sort_order, c.name'
    )->fetchAll();
    return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'members' => (int) $r['members']], $rows);
}

// ── Protokoll ─────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $query
 * @return array{0: string, 1: array<int, string>, 2: array<string, string>} WHERE-Teil, Parameter, bereinigte Filter
 */
function mg_log_filters(array $query): array
{
    $filters = [
        'source' => in_array($query['source'] ?? '', ['web', 'api'], true) ? (string) $query['source'] : '',
        'level' => in_array($query['level'] ?? '', LOG_LEVELS, true) ? (string) $query['level'] : '',
        'action' => trim((string) ($query['action'] ?? '')),
        'actor' => trim((string) ($query['actor'] ?? '')),
        'q' => trim((string) ($query['q'] ?? '')),
        'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($query['from'] ?? '')) === 1 ? (string) $query['from'] : '',
        'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($query['to'] ?? '')) === 1 ? (string) $query['to'] : '',
    ];

    $where = [];
    $params = [];
    if ($filters['source'] !== '') {
        $where[] = 'source = ?';
        $params[] = $filters['source'];
    }
    if ($filters['level'] !== '') {
        $where[] = 'level = ?';
        $params[] = $filters['level'];
    }
    if ($filters['action'] !== '') {
        $where[] = 'action LIKE ?';
        $params[] = $filters['action'] . '%';
    }
    if ($filters['actor'] !== '') {
        $where[] = 'actor LIKE ?';
        $params[] = '%' . $filters['actor'] . '%';
    }
    if ($filters['q'] !== '') {
        $where[] = '(message LIKE ? OR path LIKE ? OR details LIKE ? OR target_id = ? OR ip = ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $filters['q'], $filters['q']);
    }
    if ($filters['from'] !== '') {
        $where[] = 'created_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $filters['to'];
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $filters];
}

/**
 * Eine Seite des Protokolls (50 Einträge) mit Kennzahlen der letzten 24 Stunden.
 *
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function mg_logs(array $query): array
{
    log_ensure_table();
    [$whereSql, $params] = mg_log_filters($query);
    $perPage = 50;

    $count = db()->prepare('SELECT COUNT(*) FROM activity_log' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) ($query['page'] ?? 1)), $pages);

    $list = db()->prepare('SELECT * FROM activity_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $list->execute($params);

    $stats = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(action IN ('api.request', 'web.request')), 0) AS requests,
                COALESCE(SUM(level = 'error'), 0) AS errors,
                COALESCE(SUM(level = 'warning'), 0) AS warnings,
                COALESCE(SUM(action IN ('auth.login_failed', 'api.auth_failed')), 0) AS failed_logins
         FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    )->fetch();

    return [
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'entries' => $list->fetchAll(),
        'stats' => array_map('intval', (array) $stats),
        'actions' => db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action LIMIT 200')->fetchAll(PDO::FETCH_COLUMN),
    ];
}

/** Gibt das Protokoll (höchstens 10.000 Zeilen, mit den Filtern) als CSV aus. */
function mg_logs_csv(array $query): never
{
    log_ensure_table();
    [$whereSql, $params, $filters] = mg_log_filters($query);
    $stmt = db()->prepare('SELECT created_at, source, level, action, actor, target_type, target_id, message, ip, http_method, path, status_code, duration_ms, details FROM activity_log' . $whereSql . ' ORDER BY id DESC LIMIT 10000');
    $stmt->execute($params);
    app_log('log.export', 'Protokoll als CSV exportiert (Desktop-App)', ['filters' => array_filter($filters)]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="protokoll-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Zeit', 'Quelle', 'Stufe', 'Aktion', 'Benutzer', 'Objekt', 'Objekt-ID', 'Meldung', 'IP', 'Methode', 'Pfad', 'Status', 'Dauer (ms)', 'Details'], ';', '"', '');
    while ($row = $stmt->fetch()) {
        fputcsv($out, array_map(static fn ($v) => $v === null ? '' : (string) $v, array_values($row)), ';', '"', '');
    }
    fclose($out);
    exit;
}

// ── Eigenes Passwort ──────────────────────────────────────────────────────

/** @throws RuntimeException */
function mg_password_change(int $adminId, string $current, string $new, int $keepSessionId): void
{
    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([$adminId]);
    $hash = $stmt->fetchColumn();
    if ($hash === false || !password_verify($current, (string) $hash)) {
        usleep(400000);
        throw new RuntimeException('Das aktuelle Passwort ist falsch.');
    }
    if (strlen($new) < 8) {
        throw new RuntimeException('Das neue Passwort muss mindestens 8 Zeichen haben.');
    }
    if ($new === $current) {
        throw new RuntimeException('Das neue Passwort muss sich vom alten unterscheiden.');
    }
    $update = db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?');
    $update->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

    // andere App-Sitzungen (mit dem alten Passwort) beenden, die aktuelle bleibt bestehen
    app_sessions_ensure_table();
    $del = db()->prepare('DELETE FROM app_sessions WHERE admin_id = ? AND id <> ?');
    $del->execute([$adminId, $keepSessionId]);
    app_log('auth.password_change', 'Passwort geändert (Desktop-App)', ['target_type' => 'admin', 'target_id' => $adminId]);
}

// ── Persönlicher Zugangslink ──────────────────────────────────────────────

/**
 * Link und Status eines Mitglieds; mit $action = regenerate_link | regenerate_password | send_email.
 *
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function mg_member_link(int $id, string $action = ''): array
{
    return mg_person_link('members', $id, $action);
}

/**
 * Link und Status einer Person (Spieler 'members' oder 'staff'); $action = regenerate_link | regenerate_password |
 * send_email | reset_verification.
 *
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function mg_person_link(string $entity, int $id, string $action = ''): array
{
    require_once __DIR__ . '/verification.php';

    $staff = verif_entity($entity) === 'staff';
    $label = $staff ? 'Person' : 'Mitglied';
    $person = $staff ? staff_find_by_id($id) : member_find_by_id($id);
    if ($person === false) {
        throw new RuntimeException($label . ' nicht gefunden.');
    }
    $targetType = $staff ? 'staff' : 'member';
    $access = $staff ? staff_access_row($id) : $person;

    $password = null;
    $message = '';

    if ($action === 'regenerate_link') {
        if ($staff) {
            $stmt = db()->prepare('UPDATE staff_access SET verify_token = ?, verified_at = NULL, failed_verify_attempts = 0, verify_locked_until = NULL WHERE staff_id = ?');
            $stmt->execute([random_token(32), $id]);
        } else {
            member_regenerate_token($id);
        }
        app_log($targetType . '.link_regenerate', 'Zugangslink neu erzeugt (Desktop-App)', ['target_type' => $targetType, 'target_id' => $id]);
        $message = 'Ein neuer Link wurde erzeugt. Der alte Link ist ungültig.';
    } elseif ($action === 'regenerate_password') {
        $password = verif_regenerate_password($entity, $id);
        app_log($targetType . '.code_regenerate', 'Zugangscode neu erzeugt (Desktop-App)', ['target_type' => $targetType, 'target_id' => $id]);
        $message = 'Ein neuer Zugangscode wurde erzeugt. Er wird nur jetzt angezeigt.';
    } elseif ($action === 'reset_verification') {
        verif_reset($entity, [$id]);
        $message = 'Die Bestätigung wurde zurückgesetzt. Die Person muss ihre Daten erneut bestätigen.';
    } elseif ($action === 'send_email') {
        $result = verif_send_link($entity, $id);
        if ($result['status'] === 'no_email') {
            throw new RuntimeException('Für diese Person ist keine E-Mail-Adresse hinterlegt.');
        }
        if ($result['status'] !== 'sent') {
            throw new RuntimeException($result['message']);
        }
        $message = 'Link und Zugangscode wurden gesendet. ' . $result['message'];
    }

    // Aktuellen Zustand neu lesen (Token/Bestätigung können sich geändert haben)
    if ($staff) {
        $access = staff_access_row($id);
        $token = (string) $access['verify_token'];
        $link = staff_build_link($token);
    } else {
        $fresh = member_find_by_id($id);
        $access = $fresh !== false ? $fresh : $person;
        $link = build_member_link((string) $access['verify_token']);
    }

    return [
        'name' => trim((string) $person['vorname'] . ' ' . (string) $person['nachname']),
        'email' => (string) ($person['email'] ?? ''),
        'link' => $link,
        'verified_at' => $access['verified_at'] ?? null,
        'password' => $password,
        'message' => $message,
    ];
}
