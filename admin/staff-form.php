<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/member_columns.php';

require_permission('staff.view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? staff_find_by_id($id) : false;

if ($id !== null && $existing === false) {
    flash_set('error', 'Person nicht gefunden.');
    redirect('staff.php');
}

$mayChange = user_can('staff.edit');
if ($id === null && !$mayChange) {
    require_permission('staff.edit');
}

$error = null;
$isNew = $id === null;
$values = $existing !== false ? $existing : ['status' => 'aktiv'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$mayChange) {
        require_permission('staff.edit');
    }

    try {
        $data = staff_collect_input();
        $savedId = staff_upsert($data, $id);
        if (!empty($_POST['remove_rechte']) && $id !== null) {
            staff_set_document($savedId, 'rechte', null);
        }
        staff_set_document($savedId, 'rechte', 'rechte_dokument'); // nur bei neu gewählter Datei
        app_log($isNew ? 'staff.create' : 'staff.update', $isNew ? 'Staff-Person angelegt' : 'Staff-Person geändert', ['target_type' => 'staff', 'target_id' => $savedId]);
        flash_set('info', $isNew ? 'Person wurde angelegt.' : 'Änderungen wurden gespeichert.');
        redirect('staff-form.php?id=' . $savedId);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $values = array_merge($values, $_POST);
    }
}

$pageTitle = $isNew ? 'Neue Person (Staff)' : 'Staff bearbeiten';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');

$v = static fn (string $key): string => h((string) ($values[$key] ?? ''));
?>
<div class="content-header">
    <h1><?= $isNew ? 'Neue Person (Staff)' : 'Staff: ' . h(member_full_name($values)) ?></h1>
    <a href="staff.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="staff-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" class="member-form" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <fieldset class="form-locked" style="border:0;padding:0;margin:0;background:none;" <?= $mayChange ? '' : 'disabled' ?>>
    <?php foreach (STAFF_FORM_GROUPS as $group => $keys): ?>
        <fieldset>
            <legend><?= h($group) ?></legend>
            <div class="form-row" style="flex-wrap:wrap;">
                <?php foreach ($keys as $key): ?>
                    <?php [$label, $type] = STAFF_IO_COLUMNS[$key]; $required = in_array($key, ['nachname', 'vorname'], true); ?>
                    <div class="form-group">
                        <label for="<?= h($key) ?>"><?= h($label) ?><?= $required ? ' *' : '' ?></label>
                        <?php if ($key === 'nada'): ?>
                            <?php $nadaYes = in_array(strtolower((string) ($values['nada'] ?? '')), ['1', 'ja', 'true', 'yes'], true); ?>
                            <select id="nada" name="nada"><option value="0" <?= $nadaYes ? '' : 'selected' ?>>Nein</option><option value="1" <?= $nadaYes ? 'selected' : '' ?>>Ja</option></select>
                        <?php elseif ($type === 'date'): ?>
                            <input type="date" id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= $v($key) ?>">
                        <?php elseif ($key === 'essen'): ?>
                            <textarea id="essen" name="essen" rows="2" maxlength="255"><?= $v('essen') ?></textarea>
                        <?php else: ?>
                            <input type="<?= $key === 'email' ? 'email' : ($key === 'telefon' || $key === 'telefon_angehoeriger' ? 'tel' : 'text') ?>"
                                   id="<?= h($key) ?>" name="<?= h($key) ?>" value="<?= $v($key) ?>" maxlength="<?= (int) (MEMBER_IO_MAXLEN[$key] ?? 100) ?>" <?= $required ? 'required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endforeach; ?>

    <fieldset>
        <legend>Rechte &amp; Pflichten</legend>
        <div class="form-group">
            <?php $hasRechte = !empty($values['rechte_dokument_pfad']); ?>
            <label for="rechte_dokument">Unterschriebenes Dokument Rechte &amp; Pflichten (PDF, JPG, PNG)<?php if ($hasRechte): ?>
                (vorhanden<?php if (!$isNew && user_can('documents.view')): ?> – <a href="staff-document.php?id=<?= (int) $id ?>&amp;type=rechte" target="_blank" rel="noopener">ansehen</a><?php endif; ?>
                – neu hochladen zum Ersetzen)<?php endif; ?></label>
            <input type="file" id="rechte_dokument" name="rechte_dokument" accept=".jpg,.jpeg,.png,.pdf">
            <?php if ($hasRechte && $mayChange): ?>
                <label style="font-weight:400;margin-top:6px;"><input type="checkbox" name="remove_rechte" value="1"> Vorhandenes Dokument entfernen</label>
            <?php endif; ?>
        </div>
    </fieldset>

    <fieldset>
        <legend>Verwaltung</legend>
        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="aktiv" <?= ($values['status'] ?? 'aktiv') === 'aktiv' ? 'selected' : '' ?>>Aktiv</option>
                <option value="inaktiv" <?= ($values['status'] ?? '') === 'inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
            </select>
        </div>
    </fieldset>
    </fieldset>

    <?php if (!$mayChange): ?><p class="alert alert-warning">Sie dürfen diese Person ansehen, aber nicht ändern.</p><?php endif; ?>
    <?php if ($mayChange): ?><button type="submit" class="btn btn-primary"><?= $isNew ? 'Person anlegen' : 'Änderungen speichern' ?></button><?php endif; ?>
</form>

<?php if (!$isNew && user_can('staff.delete')): ?>
<form method="post" action="staff-delete.php" class="inline-form" data-confirm="&quot;<?= h(member_full_name($values)) ?>&quot; wirklich unwiderruflich löschen?" style="margin-top:12px;">
    <?= csrf_field() ?>
    <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
    <button type="submit" class="link-button danger">Person löschen</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
