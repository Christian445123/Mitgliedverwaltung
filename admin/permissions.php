<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/field_access.php';

require_permission('fields.manage');

$registry = field_access_registry();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reset') {
        field_access_reset();
        app_log('permissions.reset', 'Feld-Rechte auf Standard zurückgesetzt', [], 'warning');
        flash_set('info', 'Feld-Rechte wurden auf den Standard zurückgesetzt (Spieler dürfen alles sehen und ändern).');
        redirect('permissions.php');
    }

    if ($action === 'save') {
        $before = field_access_settings();
        $new = [];
        foreach ($registry as $key => $def) {
            $player = (string) ($_POST['player'][$key] ?? $before[$key]['player']);
            $new[$key] = [
                'player' => in_array($player, ['edit', 'view', 'hidden'], true) ? $player : $before[$key]['player'],
                'editor' => isset($_POST['editor'][$key]),
            ];
        }
        field_access_save($new, current_admin_username());

        $changes = [];
        foreach ($new as $key => $s) {
            if (!empty($registry[$key][3]['core'])) {
                $s['editor'] = true;
            }
            if ($s['player'] !== $before[$key]['player'] || $s['editor'] !== $before[$key]['editor']) {
                $changes[$key] = ['spieler' => $s['player'], 'bearbeiter_sichtbar' => $s['editor']];
            }
        }
        app_log('permissions.update', count($changes) . ' Feld-Rechte geändert', ['changes' => $changes]);
        flash_set('info', 'Feld-Rechte wurden gespeichert. Sie gelten sofort für alle Links und den Bearbeiter-Zugang.');
        redirect('permissions.php');
    }
}

$settings = field_access_settings();
$groups = [];
foreach ($registry as $key => $def) {
    $groups[$def[1]][$key] = $def;
}
$counts = ['edit' => 0, 'view' => 0, 'hidden' => 0];
foreach ($registry as $key => $def) {
    if (empty($def[3]['admin'])) {
        $counts[$settings[$key]['player']]++;
    }
}

$pageTitle = 'Feld-Rechte';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>Feld-Rechte</h1>
</div>

<p>Hier legen Sie fest, <strong>welche Felder Spieler</strong> in ihrem persönlichen Link sehen oder ändern dürfen
und <strong>welche Felder Bearbeiter</strong> (Rolle „Bearbeiter“) im Admin-Bereich sehen. Administratoren sehen und
ändern immer alles. Ausgeblendete Felder werden nicht ausgeliefert und lassen sich auch nicht über manipulierte
Formulare ändern.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= (int) $counts['edit'] ?></span><span class="stat-label">Spieler: ansehen &amp; ändern</span></div>
    <div class="stat-card"><span class="stat-value"><?= (int) $counts['view'] ?></span><span class="stat-label">Spieler: nur ansehen</span></div>
    <div class="stat-card stat-warn"><span class="stat-value"><?= (int) $counts['hidden'] ?></span><span class="stat-label">Spieler: ausgeblendet</span></div>
</div>

<form method="post" action="permissions.php" id="permissions-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="panel bulk-actions">
        <strong>Alle Spieler-Felder auf einmal:</strong>
        <div class="filter-bar">
            <button type="button" class="btn btn-sm" data-bulk-player="edit">Alle ansehen &amp; ändern</button>
            <button type="button" class="btn btn-sm" data-bulk-player="view">Alle nur ansehen</button>
            <button type="button" class="btn btn-sm" data-bulk-player="hidden">Alle ausblenden</button>
        </div>
        <p class="muted">Danach einzelne Felder anpassen und unten auf „Speichern“ klicken.</p>
    </div>

    <?php foreach ($groups as $groupName => $fields): ?>
        <h2 class="section-title" style="margin-top:22px;"><?= h($groupName) ?></h2>
        <div class="table-scroll">
        <table class="table table-cards">
            <thead>
                <tr><th>Feld</th><th>Spieler (persönlicher Link)</th><th>Bearbeiter</th></tr>
            </thead>
            <tbody>
            <?php foreach ($fields as $key => $def): ?>
                <?php $isAdminOnly = !empty($def[3]['admin']); $isCore = !empty($def[3]['core']); ?>
                <tr>
                    <td data-label="Feld"><strong><?= h($def[0]) ?></strong></td>
                    <td data-label="Spieler">
                        <?php if ($isAdminOnly): ?>
                            <span class="muted">immer ausgeblendet</span>
                        <?php else: ?>
                            <select name="player[<?= h($key) ?>]" data-player-select aria-label="Spieler-Recht für <?= h($def[0]) ?>">
                                <option value="edit" <?= $settings[$key]['player'] === 'edit' ? 'selected' : '' ?>>Ansehen &amp; ändern</option>
                                <option value="view" <?= $settings[$key]['player'] === 'view' ? 'selected' : '' ?>>Nur ansehen</option>
                                <option value="hidden" <?= $settings[$key]['player'] === 'hidden' ? 'selected' : '' ?>>Ausgeblendet</option>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td data-label="Bearbeiter">
                        <label class="inline-check">
                            <input type="checkbox" name="editor[<?= h($key) ?>]" value="1" <?= $settings[$key]['editor'] || $isCore ? 'checked' : '' ?> <?= $isCore ? 'disabled' : '' ?>>
                            <?= $isCore ? 'immer sichtbar' : 'sichtbar' ?>
                        </label>
                        <?php if ($isCore): ?><input type="hidden" name="editor[<?= h($key) ?>]" value="1"><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>

    <div class="filter-bar" style="margin-top:20px;">
        <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
</form>

<form method="post" action="permissions.php" class="panel" style="margin-top:24px; max-width:560px;"
      data-confirm="Alle Feld-Rechte auf den Standard zurücksetzen? Spieler dürfen danach wieder alle Felder sehen und ändern.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <h2 class="section-title">Auf Standard zurücksetzen</h2>
    <p class="muted">Spieler sehen und ändern wieder alle Felder, Bearbeiter sehen alles.</p>
    <button type="submit" class="btn btn-danger-outline">Zurücksetzen</button>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
