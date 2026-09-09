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
<h1>Mitglieder (<?= (int) $total ?>)</h1>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<div class="actions-row">
    <form class="search-form" method="get">
        <input type="text" name="q" value="<?= h($query) ?>" placeholder="Suche nach Name, E-Mail, Verein, Jersey Nr.">
        <button type="submit" class="btn-small">Suchen</button>
        <?php if ($query !== ''): ?><a class="btn btn-secondary btn-small" href="index.php">Zurücksetzen</a><?php endif; ?>
    </form>
    <a class="btn" href="member-form.php">+ Neues Mitglied</a>
</div>

<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Verein</th>
            <th>Position</th>
            <th>Jersey Nr.</th>
            <th>E-Mail</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($members)): ?>
        <tr><td colspan="7" class="muted">Keine Mitglieder gefunden.</td></tr>
    <?php endif; ?>
    <?php foreach ($members as $mRow): ?>
        <tr>
            <td><?= h($mRow['nachname']) ?>, <?= h($mRow['vorname']) ?></td>
            <td><?= h($mRow['verein'] ?? '') ?></td>
            <td><?= h($mRow['position'] ?? '') ?></td>
            <td><?= h($mRow['jersey_nr'] ?? '') ?></td>
            <td><?= h($mRow['email']) ?></td>
            <td><span class="badge badge-<?= h($mRow['status']) ?>"><?= h($mRow['status']) ?></span></td>
            <td>
                <a class="btn btn-small" href="member-form.php?id=<?= (int) $mRow['id'] ?>">Bearbeiten</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="btn btn-small <?= $p === $page ? '' : 'btn-secondary' ?>"
           href="index.php?q=<?= urlencode($query) ?>&page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
