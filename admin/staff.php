<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/expiry.php';

require_permission('staff.view');

$query = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? (string) $_GET['status'] : null;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$total = staff_count($query, $status);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$people = staff_search($query, $perPage, ($page - 1) * $perPage, $status);
$activeCount = staff_count('', 'aktiv');
$canEdit = user_can('staff.edit');
$canDelete = user_can('staff.delete');
$canSelect = $canDelete || $canEdit;
require_once __DIR__ . "/../includes/verification.php";
$verified = staff_verified_map();

$listUrl = static fn (array $extra = []) => 'staff.php?' . http_build_query(array_filter(
    array_merge(['q' => $_GET['q'] ?? '', 'status' => $status], $extra),
    static fn ($v) => $v !== null && $v !== ''
));

$pageTitle = 'Staff';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
$error = flash_get('error');
?>
<div class="content-header">
    <h1>Staff</h1>
    <div class="header-actions">
        <?php if ($canEdit && user_can('members.import')): ?><a href="import.php?entity=staff" class="btn">Import</a><?php endif; ?>
        <?php if (user_can('members.export')): ?><a href="export.php?entity=staff<?= $status ? '&amp;status=' . h($status) : '' ?>" class="btn">Export CSV</a><?php endif; ?>
        <?php if ($canEdit): ?><a href="staff-form.php" class="btn btn-primary">+ Neue Person</a><?php endif; ?>
    </div>
</div>

<p class="muted">Trainer, Betreuer und Funktionäre – getrennt von den Spielern. Alle Personen: <?= (int) staff_count() ?> · aktiv: <?= (int) $activeCount ?>.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="get" action="staff.php" class="filter-bar">
    <input type="text" name="q" placeholder="Suche: Name, Mail, Position" value="<?= h($query) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= h($status) ?>"><?php endif; ?>
    <button type="submit" class="btn">Suchen</button>
    <?php if ($query !== ''): ?><a href="<?= h($listUrl(['q' => ''])) ?>" class="btn btn-link">Zurücksetzen</a><?php endif; ?>
    <span class="chip-group">
        <a class="chip <?= $status === null ? 'active' : '' ?>" href="<?= h($listUrl(['status' => null])) ?>">Alle</a>
        <a class="chip <?= $status === 'aktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'aktiv'])) ?>">Aktiv</a>
        <a class="chip <?= $status === 'inaktiv' ? 'active' : '' ?>" href="<?= h($listUrl(['status' => 'inaktiv'])) ?>">Inaktiv</a>
    </span>
</form>

<form method="post" action="staff-delete.php" id="bulk-form" data-confirm="Die ausgewählten Personen wirklich endgültig löschen?">
    <?= csrf_field() ?>
    <?php if ($canSelect): ?>
    <div class="filter-bar bulk-bar">
        <?php if ($canDelete): ?><button type="submit" class="btn btn-danger" id="bulk-delete" disabled>Ausgewählte löschen (<span id="bulk-count">0</span>)</button><?php endif; ?>
        <?php if ($canEdit): ?>
            <button type="submit" class="btn" formaction="verification.php" data-no-form-confirm data-needs-selection disabled title="Bestätigung zurücksetzen und/oder Link per E-Mail senden">Ausgewählte: Daten bestätigen lassen</button>
            <a href="verification.php?entity=staff" class="btn">Alle Staff: Daten bestätigen lassen …</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<div class="table-scroll">
<table class="table table-cards">
    <thead>
        <tr>
            <?php if ($canSelect): ?><th class="check-col"><input type="checkbox" data-select-all aria-label="Alle auf dieser Seite auswählen"></th><?php endif; ?>
            <th>Name &amp; Vorname</th>
            <th>Position</th>
            <th>Telefon</th>
            <th>Mail</th>
            <th>NADA gültig bis</th>
            <th>Reisepass</th>
            <th>Status</th>
            <th>Bestätigt</th>
            <th class="actions-sticky">Aktionen</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($people)): ?>
        <tr><td colspan="10" class="empty">Keine Personen gefunden.</td></tr>
    <?php endif; ?>
    <?php foreach ($people as $p): ?>
        <?php $passState = expiry_pass_state($p['reisepass_gueltig_bis'] ?? null); ?>
        <?php $nadaState = expiry_nada_state($p['nada_gueltig_bis'] ?? null); ?>
        <tr>
            <?php if ($canSelect): ?><td class="check-col" data-label="Auswahl"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" data-row-check aria-label="Person auswählen"></td><?php endif; ?>
            <td data-label="Name &amp; Vorname"><?= h(member_full_name($p)) ?></td>
            <td data-label="Position"><?= h((string) ($p['position'] ?? '')) ?></td>
            <td data-label="Telefon"><?= h((string) ($p['telefon'] ?? '')) ?></td>
            <td data-label="Mail"><?= h((string) ($p['email'] ?? '')) ?></td>
            <td data-label="NADA gültig bis">
                <?= !empty($p['nada_gueltig_bis']) ? h(date('d.m.Y', (int) strtotime((string) $p['nada_gueltig_bis']))) : '' ?>
                <?php if ($nadaState): ?><span class="badge badge-<?= expiry_badge_class($nadaState['state']) ?>" title="<?= h(expiry_text($nadaState)) ?>"><?= h(expiry_badge_label($nadaState)) ?></span><?php endif; ?>
            </td>
            <td data-label="Reisepass">
                <?= !empty($p['reisepass_gueltig_bis']) ? h(date('d.m.Y', (int) strtotime((string) $p['reisepass_gueltig_bis']))) : '' ?>
                <?php if ($passState): ?><span class="badge badge-<?= expiry_badge_class($passState['state']) ?>" title="<?= h(expiry_text($passState)) ?>"><?= h(expiry_badge_label($passState)) ?></span><?php endif; ?>
            </td>
            <td data-label="Status"><span class="badge badge-<?= $p['status'] === 'aktiv' ? 'green' : 'gray' ?>"><?= h(ucfirst((string) $p['status'])) ?></span></td>
            <td data-label="Bestätigt">
                <?php if (!empty($verified[(int) $p['id']])): ?>
                    <span class="badge badge-green" title="Bestätigt am <?= h((string) $verified[(int) $p['id']]) ?>">✓</span>
                <?php else: ?>
                    <span class="badge badge-orange">Ausstehend</span>
                <?php endif; ?>
            </td>
            <td class="actions actions-sticky" data-label="">
                <a href="staff-form.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm"><?= $canEdit ? 'Bearbeiten' : 'Ansehen' ?></a>
                <?php if ($canEdit): ?><a href="staff-link.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-primary" title="Persönlichen Link und Zugangscode anzeigen oder per E-Mail senden">Link senden</a><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</form>

<?php if ($totalPages > 1): ?>
<div class="filter-bar" style="margin-top:16px;">
    <?php for ($n = 1; $n <= $totalPages; $n++): ?>
        <a class="btn <?= $n === $page ? 'btn-primary' : '' ?>" href="<?= h($listUrl(['page' => $n])) ?>"><?= $n ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
