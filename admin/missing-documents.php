<?php

declare(strict_types=1);

/**
 * Fehlende Dokumente in einem eigenen Reiter: Spieler (Pflichtdokumente) und Staff (freiwillige Dokumente) getrennt.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_permission('members.view');

$docsMissing = documents_missing_report();
$showStaff = user_can('staff.view');
$playerCount = count($docsMissing['players']);
$staffCount = $showStaff ? count($docsMissing['staff']) : 0;

$pageTitle = 'Fehlende Dokumente';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Fehlende Dokumente</h1>
    <div class="header-actions">
        <?php if (user_can('members.export') && $showStaff): ?>
        <a class="btn btn-primary" href="roster.php?type=missing&amp;kader=kader&amp;format=pdf">Liste als PDF</a>
        <a class="btn" href="roster.php?type=missing&amp;kader=kader&amp;format=xlsx">Liste als Excel</a>
        <?php endif; ?>
    </div>
</div>

<p class="muted">Ein Dokument fehlt, wenn keine Datei hochgeladen ist oder es mit „Fehlt“ markiert wurde. E-Card und Reisepass haben nur eine Vorderseite.
    Beim Staff sind alle Dokumente freiwillig (nur Hinweis).</p>

<div class="tabs" role="tablist" data-tabs>
    <button type="button" class="tab active" role="tab" data-tab-target="missing-tab-players">Spieler (<?= $playerCount ?>)</button>
    <?php if ($showStaff): ?>
    <button type="button" class="tab" role="tab" data-tab-target="missing-tab-staff">Staff (<?= $staffCount ?>)</button>
    <?php endif; ?>
</div>

<div class="tab-panel active" id="missing-tab-players" role="tabpanel">
    <section class="panel">
        <?php if ($playerCount === 0): ?>
            <p class="alert alert-success">✓ Bei allen Spielern im Kader sind die Dokumente vollständig.</p>
        <?php else: ?>
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
        <?php endif; ?>
    </section>
</div>

<?php if ($showStaff): ?>
<div class="tab-panel" id="missing-tab-staff" role="tabpanel">
    <section class="panel">
        <?php if ($staffCount === 0): ?>
            <p class="alert alert-success">✓ Beim Staff ist alles hochgeladen (die Dokumente sind freiwillig).</p>
        <?php else: ?>
        <ul class="missing-list">
            <?php foreach ($docsMissing['staff'] as $s): ?>
                <li><a href="staff-form.php?id=<?= (int) $s['id'] ?>"><?= h($s['name']) ?></a> <?php foreach ($s['open'] as $openDoc): ?><span class="badge badge-gray"><?= h($openDoc) ?></span> <?php endforeach; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
