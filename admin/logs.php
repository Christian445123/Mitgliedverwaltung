<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

require_permission('logs.view');
log_ensure_table();

// ── Aufräumen (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purge') {
    if (!user_can('logs.purge')) {
        require_permission('logs.purge');
    }
    verify_csrf();
    $days = (int) ($_POST['days'] ?? 0);
    $deleted = log_purge($days);
    app_log('log.purge', $days > 0 ? "Protokoll bereinigt (älter als {$days} Tage)" : 'Protokoll komplett geleert', ['deleted' => $deleted, 'days' => $days], 'warning');
    flash_set('info', $deleted . ' Protokolleinträge wurden gelöscht.');
    redirect('logs.php');
}

// ── Filter ─────────────────────────────────────────────────────────
$filters = [
    'source' => in_array($_GET['source'] ?? '', ['web', 'api'], true) ? (string) $_GET['source'] : '',
    'level' => in_array($_GET['level'] ?? '', LOG_LEVELS, true) ? (string) $_GET['level'] : '',
    'action' => trim((string) ($_GET['action'] ?? '')),
    'actor' => trim((string) ($_GET['actor'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '',
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
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

// ── CSV-Export (max. 10.000 Zeilen, mit den aktuellen Filtern) ─────
if (isset($_GET['export'])) {
    $stmt = db()->prepare('SELECT created_at, source, level, action, actor, target_type, target_id, message, ip, http_method, path, status_code, duration_ms, details FROM activity_log' . $whereSql . ' ORDER BY id DESC LIMIT 10000');
    $stmt->execute($params);
    app_log('log.export', 'Protokoll als CSV exportiert', ['filters' => array_filter($filters)]);

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

// ── Liste ──────────────────────────────────────────────────────────
$perPage = 50;
$countStmt = db()->prepare('SELECT COUNT(*) FROM activity_log' . $whereSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare('SELECT * FROM activity_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$listStmt->execute($params);
$entries = $listStmt->fetchAll();

$stats = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(action IN ('api.request', 'web.request')), 0) AS requests,
            COALESCE(SUM(level = 'error'), 0) AS errors,
            COALESCE(SUM(level = 'warning'), 0) AS warnings,
            COALESCE(SUM(action IN ('auth.login_failed', 'api.auth_failed')), 0) AS failed_logins
     FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
)->fetch();
$actions = db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);

$pageUrl = static function (array $extra = []) use ($filters): string {
    return 'logs.php?' . http_build_query(array_filter(array_merge($filters, $extra), static fn ($v) => $v !== '' && $v !== null));
};
$levelBadge = ['debug' => 'gray', 'info' => 'blue', 'warning' => 'orange', 'error' => 'red'];

$pageTitle = 'Protokoll';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>Protokoll</h1>
    <div class="header-actions">
        <a href="<?= h($pageUrl(['export' => '1'])) ?>" class="btn">Export CSV</a>
    </div>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= (int) $stats['requests'] ?></span><span class="stat-label">Anfragen (24 h)</span></div>
    <div class="stat-card"><span class="stat-value"><?= (int) $stats['failed_logins'] ?></span><span class="stat-label">Fehlgeschlagene Anmeldungen</span></div>
    <div class="stat-card stat-warn"><span class="stat-value"><?= (int) $stats['warnings'] ?></span><span class="stat-label">Warnungen</span></div>
    <div class="stat-card stat-warn"><span class="stat-value"><?= (int) $stats['errors'] ?></span><span class="stat-label">Fehler</span></div>
</div>

<form method="get" action="logs.php" class="panel log-filter">
    <div class="log-filter-grid">
        <div class="form-group">
            <label for="f-source">Quelle</label>
            <select id="f-source" name="source">
                <option value="">Alle</option>
                <option value="web" <?= $filters['source'] === 'web' ? 'selected' : '' ?>>Web</option>
                <option value="api" <?= $filters['source'] === 'api' ? 'selected' : '' ?>>API</option>
            </select>
        </div>
        <div class="form-group">
            <label for="f-level">Stufe</label>
            <select id="f-level" name="level">
                <option value="">Alle</option>
                <?php foreach (LOG_LEVELS as $lvl): ?>
                    <option value="<?= h($lvl) ?>" <?= $filters['level'] === $lvl ? 'selected' : '' ?>><?= h(ucfirst($lvl)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="f-action">Aktion</label>
            <input type="text" id="f-action" name="action" list="action-list" value="<?= h($filters['action']) ?>" placeholder="z. B. member.">
            <datalist id="action-list">
                <?php foreach ($actions as $a): ?><option value="<?= h((string) $a) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div class="form-group">
            <label for="f-actor">Benutzer</label>
            <input type="text" id="f-actor" name="actor" value="<?= h($filters['actor']) ?>" placeholder="Name oder token:…">
        </div>
        <div class="form-group">
            <label for="f-from">Von</label>
            <input type="date" id="f-from" name="from" value="<?= h($filters['from']) ?>">
        </div>
        <div class="form-group">
            <label for="f-to">Bis</label>
            <input type="date" id="f-to" name="to" value="<?= h($filters['to']) ?>">
        </div>
        <div class="form-group log-filter-wide">
            <label for="f-q">Suche</label>
            <input type="text" id="f-q" name="q" value="<?= h($filters['q']) ?>" placeholder="Meldung, Pfad, Details, IP, Objekt-ID">
        </div>
    </div>
    <div class="filter-bar">
        <button type="submit" class="btn btn-primary">Filtern</button>
        <a href="logs.php" class="btn btn-link">Zurücksetzen</a>
        <span class="muted"><?= (int) $total ?> Einträge</span>
    </div>
</form>

<div class="table-scroll">
<table class="table table-cards">
    <thead>
        <tr><th>Zeit</th><th>Quelle</th><th>Stufe</th><th>Aktion</th><th>Benutzer</th><th>Meldung</th><th>IP</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php if (empty($entries)): ?>
        <tr><td colspan="8" class="empty">Keine Einträge gefunden.</td></tr>
    <?php endif; ?>
    <?php foreach ($entries as $e): ?>
        <tr>
            <td data-label="Zeit"><?= h(date('d.m.Y H:i:s', strtotime((string) $e['created_at']))) ?></td>
            <td data-label="Quelle"><?= h(strtoupper((string) $e['source'])) ?></td>
            <td data-label="Stufe"><span class="badge badge-<?= h($levelBadge[$e['level']] ?? 'gray') ?>"><?= h((string) $e['level']) ?></span></td>
            <td data-label="Aktion"><code><?= h((string) $e['action']) ?></code></td>
            <td data-label="Benutzer"><?= h((string) ($e['actor'] ?? '–')) ?></td>
            <td data-label="Meldung" class="log-message">
                <?= h((string) $e['message']) ?>
                <?php if ($e['target_type'] !== null): ?><span class="muted"> · <?= h((string) $e['target_type']) ?> <?= h((string) $e['target_id']) ?></span><?php endif; ?>
                <?php if ($e['details'] !== null || $e['user_agent'] !== null || $e['path'] !== null): ?>
                    <details class="log-details">
                        <summary>Details</summary>
                        <?php if ($e['path'] !== null): ?><p><strong><?= h((string) $e['http_method']) ?></strong> <?= h((string) $e['path']) ?></p><?php endif; ?>
                        <?php if ($e['details'] !== null): ?>
                            <?php $decoded = json_decode((string) $e['details'], true); ?>
                            <pre><?= h($decoded !== null ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $e['details']) ?></pre>
                        <?php endif; ?>
                        <?php if ($e['user_agent'] !== null): ?><p class="muted"><?= h((string) $e['user_agent']) ?></p><?php endif; ?>
                    </details>
                <?php endif; ?>
            </td>
            <td data-label="IP"><?= h((string) ($e['ip'] ?? '')) ?></td>
            <td data-label="Status"><?= $e['status_code'] !== null ? (int) $e['status_code'] . ($e['duration_ms'] !== null ? ' · ' . (int) $e['duration_ms'] . ' ms' : '') : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
    <?php if ($page > 1): ?><a class="btn" href="<?= h($pageUrl(['page' => $page - 1])) ?>">&larr; Neuer</a><?php endif; ?>
    <span class="muted">Seite <?= (int) $page ?> von <?= (int) $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a class="btn" href="<?= h($pageUrl(['page' => $page + 1])) ?>">Älter &rarr;</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if (user_can('logs.purge')): ?>
<form method="post" action="logs.php" class="panel log-purge" data-confirm="Protokolleinträge wirklich endgültig löschen?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="purge">
    <h2 class="section-title">Protokoll aufräumen</h2>
    <p class="muted">Einträge werden automatisch nach <?= (int) log_retention_days() ?> Tagen gelöscht
        (einstellbar über <code>LOG_RETENTION_DAYS</code> in der <code>.env</code>).</p>
    <div class="filter-bar">
        <select name="days" aria-label="Aufbewahrung">
            <option value="30">Älter als 30 Tage löschen</option>
            <option value="90">Älter als 90 Tage löschen</option>
            <option value="180">Älter als 180 Tage löschen</option>
            <option value="0">Alles löschen</option>
        </select>
        <button type="submit" class="btn btn-danger-outline">Löschen</button>
    </div>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
