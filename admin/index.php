<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_admin();

$query = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total = member_count($query, $status);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$members = member_search($query, $perPage, ($page - 1) * $perPage, $status);
$stats = member_stats();

$pageTitle = 'Mitgliederübersicht';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
$error = flash_get('error');
$listUrl = static fn (array $extra = []) => 'index.php?' . http_build_query(array_filter(
    array_merge(['q' => $_GET['q'] ?? '', 'status' => $status], $extra),
    static fn ($v) => $v !== null && $v !== ''
));
?>
<div class="content-header">
    <h1>Mitglieder</h1>
    <div class="header-actions">
        <a href="import.php" class="btn">Import</a>
        <a href="<?= h('export.php' . ($status ? '?status=' . $status : '')) ?>" class="btn">Export CSV</a>
        <a href="member-form.php" class="btn btn-primary">+ Neues Mitglied</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= $stats['total'] ?></span><span class="stat-label">Mitglieder</span></div>
    <div class="stat-card"><span class="stat-value"><?= $stats['aktiv'] ?></span><span class="stat-label">Aktiv</span></div>
    <div class="stat-card"><span class="stat-value"><?= $stats['bestaetigt'] ?></span><span class="stat-label">Daten bestätigt</span></div>
    <div class="stat-card stat-warn"><span class="stat-value"><?= $stats['ausstehend'] ?></span><span class="stat-label">Bestätigung offen</span></div>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="get" action="index.php" class="filter-bar">
    <input type="text" name="q" placeholder="Suche: Name, E-Mail, Verein, Jersey Nr." value="<?= h($query) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
    <button type="submit" class="btn">Suchen</button>
    <?php if ($query !== ''): ?><a href="<?= h($listUrl(['q' => ''])) ?>" class="btn btn-link">Zurücksetzen</a><?php endif; ?>
    <span class="chip-group">
        <a class="chip <?= $status === null ? 'active' : '' ?>" href="<?= h($listUrl(['status' => null])) ?>">Alle</a>
        <a class="chip <?= $status === 'aktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'aktiv'])) ?>">Aktiv</a>
        <a class="chip <?= $status === 'inaktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'inaktiv'])) ?>">Inaktiv</a>
    </span>
</form>

<div class="table-scroll">
<table class="table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Verein</th>
            <th>Position</th>
            <th>Jersey Nr.</th>
            <th>E-Mail</th>
            <th>Status</th>
            <th>Bestätigt</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($members)): ?>
        <tr><td colspan="8" class="empty">Keine Mitglieder gefunden.</td></tr>
    <?php endif; ?>
    <?php foreach ($members as $mRow): ?>
        <tr>
            <td><?= h($mRow['nachname']) ?>, <?= h($mRow['vorname']) ?></td>
            <td><?= h($mRow['verein'] ?? '') ?></td>
            <td><?= h($mRow['position'] ?? '') ?></td>
            <td><?= h($mRow['jersey_nr'] ?? '') ?></td>
            <td><?= h($mRow['email']) ?></td>
            <td><span class="badge badge-<?= $mRow['status'] === 'aktiv' ? 'green' : 'gray' ?>"><?= h(ucfirst($mRow['status'])) ?></span></td>
            <td>
                <?php if ($mRow['verified_at']): ?>
                    <span class="badge badge-green" title="Bestätigt am <?= h($mRow['verified_at']) ?>">✓</span>
                <?php else: ?>
                    <span class="badge badge-orange">Ausstehend</span>
                <?php endif; ?>
            </td>
            <td class="actions">
                <a href="member-form.php?id=<?= (int) $mRow['id'] ?>">Bearbeiten</a>
                <a href="member-link.php?id=<?= (int) $mRow['id'] ?>">Link</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="filter-bar" style="margin-top:16px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-primary' : '' ?>" href="<?= h($listUrl(['page' => $p])) ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
