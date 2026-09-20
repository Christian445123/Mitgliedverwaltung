<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';
require_once __DIR__ . '/../includes/expiry.php';

require_permission('members.view');

$query = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
$kader = in_array($_GET['kader'] ?? '', ['kader', 'nicht_im_kader'], true) ? $_GET['kader'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total = member_count($query, $status, $kader);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$members = member_search($query, $perPage, ($page - 1) * $perPage, $status, $kader);
$stats = member_stats();
$expiry = expiry_report();
$canDelete = user_can('members.delete');
$canSelect = $canDelete || user_can('members.links');

$pageTitle = 'Mitgliederübersicht';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
$error = flash_get('error');
$listUrl = static fn (array $extra = []) => 'index.php?' . http_build_query(array_filter(
    array_merge(['q' => $_GET['q'] ?? '', 'status' => $status, 'kader' => $kader], $extra),
    static fn ($v) => $v !== null && $v !== ''
));
?>
<div class="content-header">
    <h1>Mitglieder</h1>
    <div class="header-actions">
        <?php if (user_can('members.import')): ?><a href="import.php" class="btn">Import</a><?php endif; ?>
        <?php if (user_can('members.export')): ?><a href="<?= h('export.php' . ($status ? '?status=' . $status : '')) ?>" class="btn">Export CSV</a><?php endif; ?>
        <?php if (user_can('members.export')): ?><a href="roster.php" class="btn">Roster</a><?php endif; ?>
        <?php if (user_can('members.create')): ?><a href="member-form.php" class="btn btn-primary">+ Neues Mitglied</a><?php endif; ?>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= $stats['total'] ?></span><span class="stat-label">Mitglieder</span></div>
    <div class="stat-card"><span class="stat-value"><?= $stats['aktiv'] ?></span><span class="stat-label">Aktiv</span></div>
    <div class="stat-card"><span class="stat-value"><?= $stats['bestaetigt'] ?></span><span class="stat-label">Daten bestätigt</span></div>
    <div class="stat-card stat-warn"><span class="stat-value"><?= $stats['ausstehend'] ?></span><span class="stat-label">Bestätigung offen</span></div>
</div>

<?php if ($expiry['counts']['total'] > 0): ?>
<section class="panel expiry-panel">
    <h2 class="section-title">⚠ Ablaufende Dokumente (<?= (int) $expiry['counts']['total'] ?>)</h2>
    <div class="expiry-columns">
        <?php foreach (['nada' => 'NADA-Zertifikat (gelb ab ' . expiry_nada_months() . ' Monat vorher, blau ab ' . expiry_nada_urgent_days() . ' Tagen, rot am Ablauftag)', 'pass' => 'Reisepass (Hinweis ' . expiry_pass_months() . ' Monate vorher)', 'staff_nada' => 'Staff: NADA-Zertifikat (gelb ab ' . expiry_nada_months() . ' Monat vorher, blau ab ' . expiry_nada_urgent_days() . ' Tagen, rot am Ablauftag)', 'staff_pass' => 'Staff: Reisepass (Hinweis ' . expiry_pass_months() . ' Monate vorher)'] as $type => $title): ?>
            <?php if (!empty($expiry[$type])): ?>
            <div>
                <h3><?= h($title) ?></h3>
                <ul class="expiry-list">
                    <?php foreach ($expiry[$type] as $item): ?>
                        <li class="expiry-<?= h($item['state']) ?>">
                            <a href="<?= $type === 'staff_pass' ? 'staff-form.php' : 'member-form.php' ?>?id=<?= (int) $item['id'] ?>"><?= h($item['name']) ?></a>
                            <span class="badge badge-<?= expiry_badge_class($item['state']) ?>"><?= h(expiry_badge_label($item)) ?></span>
                            <span class="muted"><?= h(date('d.m.Y', strtotime($item['date']))) ?> – <?= h(expiry_text($item)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php $docsMissing = documents_missing_report(); ?>
<?php if ($docsMissing['total'] > 0): ?>
<section class="panel expiry-panel">
    <h2 class="section-title">📄 Fehlende Dokumente (<?= (int) $docsMissing['total'] ?>)</h2>
    <p class="muted">Aktive Spieler im Kader, bei denen NADA-Zertifikat, Reisepass, E-Card oder Rechte &amp; Pflichten fehlen
        (keine Datei hochgeladen oder mit „Fehlt“ markiert). Bei E-Card und Reisepass genügt Vorder- <em>oder</em> Rückseite.</p>
    <div class="expiry-columns">
        <?php if ($docsMissing['players'] !== []): ?>
        <div>
            <h3>Spieler (<?= count($docsMissing['players']) ?>)</h3>
            <ul class="missing-list">
                <?php foreach ($docsMissing['players'] as $p): ?>
                    <li>
                        <a href="member-form.php?id=<?= (int) $p['id'] ?>"><?= h($p['name']) ?></a>
                        <?php foreach ($p['missing'] as $d): ?>
                            <span class="badge <?= $d['marked'] ? 'badge-marked' : 'badge-orange' ?>" title="<?= $d['marked'] ? 'Von Hand als fehlend markiert' : 'Keine Datei hochgeladen' ?>"><?= h($d['label']) ?></span>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if ($docsMissing['staff'] !== []): ?>
        <div>
            <h3>Staff: Rechte &amp; Pflichten (<?= count($docsMissing['staff']) ?>)</h3>
            <ul class="missing-list">
                <?php foreach ($docsMissing['staff'] as $s): ?>
                    <li><a href="staff-form.php?id=<?= (int) $s['id'] ?>"><?= h($s['name']) ?></a> <span class="badge badge-orange">Rechte &amp; Pflichten</span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>


<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="get" action="index.php" class="filter-bar">
    <input type="text" name="q" placeholder="Suche: Name, E-Mail, Verein, Jersey Nr." value="<?= h($query) ?>">
    <select name="kader" data-autosubmit aria-label="Kader">
        <option value="">Alle Spieler</option>
        <option value="kader" <?= $kader === 'kader' ? 'selected' : '' ?>>Im Kader</option>
        <option value="nicht_im_kader" <?= $kader === 'nicht_im_kader' ? 'selected' : '' ?>>Spieler nicht im Kader</option>
    </select>
    <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
    <button type="submit" class="btn">Suchen</button>
    <?php if ($query !== ''): ?><a href="<?= h($listUrl(['q' => ''])) ?>" class="btn btn-link">Zurücksetzen</a><?php endif; ?>
    <span class="chip-group">
        <a class="chip <?= $status === null ? 'active' : '' ?>" href="<?= h($listUrl(['status' => null])) ?>">Alle</a>
        <a class="chip <?= $status === 'aktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'aktiv'])) ?>">Aktiv</a>
        <a class="chip <?= $status === 'inaktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'inaktiv'])) ?>">Inaktiv</a>
    </span>
</form>

<form method="post" action="members-delete.php" id="bulk-form" data-confirm="Die ausgewählten Mitglieder samt Dokumenten wirklich endgültig löschen?">
    <?= csrf_field() ?>
    <div class="filter-bar bulk-bar">
        <?php if ($canDelete): ?><button type="submit" class="btn btn-danger" id="bulk-delete" disabled>Ausgewählte löschen (<span id="bulk-count">0</span>)</button><?php endif; ?>
        <?php if (user_can('members.links')): ?>
            <button type="submit" class="btn" formaction="verification.php" data-no-form-confirm data-needs-selection disabled title="Bestätigung zurücksetzen und/oder Link per E-Mail senden">Ausgewählte: Daten bestätigen lassen</button>
            <a href="verification.php?entity=members" class="btn">Alle Spieler: Daten bestätigen lassen …</a>
        <?php endif; ?>
        <?php if (user_can('members.delete_all')): ?>
            <a href="delete-all.php" class="btn btn-danger-outline">Alle Daten löschen …</a>
        <?php endif; ?>
    </div>

<div class="table-scroll">
<table class="table table-cards">
    <thead>
        <tr>
            <?php if ($canSelect): ?><th class="check-col"><input type="checkbox" data-select-all aria-label="Alle auf dieser Seite auswählen"></th><?php endif; ?>
            <th>Name &amp; Vorname</th>
            <th>Verein</th>
            <th>Position</th>
            <th>Jersey Nr.</th>
            <th>E-Mail</th>
            <th>Status</th>
            <th>Bestätigt</th>
            <th class="actions-sticky">Aktionen</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($members)): ?>
        <tr><td colspan="9" class="empty">Keine Mitglieder gefunden.</td></tr>
    <?php endif; ?>
    <?php foreach ($members as $mRow): ?>
        <tr>
            <?php if ($canSelect): ?><td class="check-col" data-label="Auswahl"><input type="checkbox" name="ids[]" value="<?= (int) $mRow['id'] ?>" data-row-check aria-label="Mitglied auswählen"></td><?php endif; ?>
            <td data-label="Name & Vorname"><?= h(member_full_name($mRow)) ?><?php if (($mRow['kader'] ?? 'kader') === 'nicht_im_kader'): ?> <span class="badge badge-gray">nicht im Kader</span><?php endif; ?>
                <?php $exp = expiry_states_for_row($mRow); ?>
                <?php if ($exp['nada']): ?><span class="badge badge-<?= expiry_badge_class($exp['nada']['state']) ?>" title="NADA-Zertifikat: <?= h(expiry_text($exp['nada'])) ?>">NADA <?= h(expiry_badge_label($exp['nada'])) ?></span><?php endif; ?>
                <?php if ($exp['pass']): ?><span class="badge badge-<?= expiry_badge_class($exp['pass']['state']) ?>" title="Reisepass: <?= h(expiry_text($exp['pass'])) ?>">Pass <?= h(expiry_badge_label($exp['pass'])) ?></span><?php endif; ?>
                <?php $docMiss = member_documents_missing($mRow); if ($docMiss !== []): ?><span class="badge badge-orange" title="Fehlt: <?= h(implode(", ", array_column($docMiss, "label"))) ?>">Doku fehlt (<?= count($docMiss) ?>)</span><?php endif; ?>
            </td>
            <td data-label="Verein"><?= h($mRow['verein'] ?? '') ?></td>
            <td data-label="Position"><?= h($mRow['position'] ?? '') ?></td>
            <td data-label="Jersey Nr."><?= h($mRow['jersey_nr'] ?? '') ?></td>
            <td data-label="E-Mail"><?= h($mRow['email']) ?></td>
            <td data-label="Status"><span class="badge badge-<?= $mRow['status'] === 'aktiv' ? 'green' : 'gray' ?>"><?= h(ucfirst($mRow['status'])) ?></span></td>
            <td data-label="Bestätigt">
                <?php if ($mRow['verified_at']): ?>
                    <span class="badge badge-green" title="Bestätigt am <?= h($mRow['verified_at']) ?>">✓</span>
                <?php else: ?>
                    <span class="badge badge-orange">Ausstehend</span>
                <?php endif; ?>
            </td>
            <td class="actions actions-sticky" data-label="">
                <a href="member-form.php?id=<?= (int) $mRow['id'] ?>" class="btn btn-sm"><?= user_can('members.edit') ? 'Bearbeiten' : 'Ansehen' ?></a>
                <?php if (user_can('members.links')): ?><a href="member-link.php?id=<?= (int) $mRow['id'] ?>" class="btn btn-sm btn-primary" title="Persönlichen Link und Zugangscode anzeigen oder per E-Mail senden">Link senden</a><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</form>

<?php if ($totalPages > 1): ?>
<div class="filter-bar" style="margin-top:16px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-primary' : '' ?>" href="<?= h($listUrl(['page' => $p])) ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
