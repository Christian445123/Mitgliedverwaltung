<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_admin();

$query = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total = member_count($query);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$members = member_search($query, $perPage, ($page - 1) * $perPage);

$pageTitle = 'Mitgliederübersicht';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
$error = flash_get('error');
?>
<div class="content-header">
    <h1>Mitglieder (<?= (int) $total ?>)</h1>
    <a href="member-form.php" class="btn btn-primary">+ Neues Mitglied</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="get" action="index.php" class="filter-bar">
    <input type="text" name="q" placeholder="Suche: Name, E-Mail, Verein, Jersey Nr." value="<?= h($query) ?>">
    <button type="submit" class="btn">Suchen</button>
    <?php if ($query !== ''): ?><a href="index.php" class="btn btn-link">Zurücksetzen</a><?php endif; ?>
</form>

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

<?php if ($totalPages > 1): ?>
<div class="filter-bar" style="margin-top:16px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-primary' : '' ?>"
           href="index.php?q=<?= urlencode($query) ?>&page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
