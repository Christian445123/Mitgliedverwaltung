<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_permission('members.view');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? member_find_by_id($id) : false;

if ($id !== null && $existing === false) {
    flash_set('error', 'Mitglied nicht gefunden.');
    redirect('index.php');
}

// Berechtigungen: Ansehen reicht zum Öffnen; Anlegen/Speichern braucht members.create bzw. members.edit
$mayChange = $id === null ? user_can('members.create') : user_can('members.edit');
if (!$mayChange && ($id === null || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    require_permission($id === null ? 'members.create' : 'members.edit');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $data = member_collect_input($existing ?: [], field_access_admin_audience());
        $statusPost = (string) ($_POST['status'] ?? 'aktiv');
        // "neu" bleibt nur erhalten, wenn es explizit gesendet wurde (Status-Feld zeigt die Option nur
        // bei bereits so markierten Anmeldungen an) - neu angelegte Mitglieder starten immer als "aktiv".
        $status = in_array($statusPost, ['aktiv', 'inaktiv', 'neu'], true) ? $statusPost : 'aktiv';

        $savedId = member_upsert($data, $id, $status);
        app_log($id === null ? 'member.create' : 'member.update', $id === null ? 'Mitglied angelegt' : 'Mitglied geändert', ['target_type' => 'member', 'target_id' => $savedId, 'status' => null]);

        if ($id === null) {
            // Neues Mitglied: gleich einen Zugangscode für den persönlichen Link erzeugen.
            $_SESSION['generated_password'] = member_regenerate_access_password($savedId);
            flash_set('info', 'Mitglied wurde angelegt. Link und Zugangscode können nun verschickt werden.');
            redirect(user_can('members.links') ? 'member-link.php?id=' . $savedId : 'member-form.php?id=' . $savedId);
        }

        flash_set('info', 'Mitglied wurde gespeichert.');
        redirect('member-form.php?id=' . $savedId);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $existing = array_merge($existing ?: [], $_POST);
    }
}

$m = $existing ?: [];
$showAdminFields = true;
$groupTabs = true; // Felder in Reitern wie beim Staff-Formular (admin/staff-form.php)
$isNew = $id === null;

$pageTitle = $isNew ? 'Neues Mitglied' : 'Mitglied bearbeiten';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
?>
<div class="content-header">
    <h1><?= $isNew ? 'Neues Mitglied' : 'Mitglied bearbeiten' ?></h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Liste</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="member-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" class="member-form" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <?php require __DIR__ . '/../includes/member_fields.php'; ?>

    <?php if (!$mayChange): ?><p class="alert alert-warning">Sie dürfen dieses Mitglied ansehen, aber nicht ändern.</p><?php endif; ?>

    <?php if ($mayChange): ?><button type="submit" class="btn btn-primary"><?= $isNew ? 'Mitglied anlegen' : 'Änderungen speichern' ?></button><?php endif; ?>
</form>

<?php if (!$isNew && user_can('members.delete')): ?>
<form method="post" action="member-delete.php" class="inline-form" data-confirm="Mitglied &quot;<?= h(($m['vorname'] ?? '') . ' ' . ($m['nachname'] ?? '')) ?>&quot; wirklich unwiderruflich löschen?" style="margin-top:12px;">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" class="link-button danger">Mitglied löschen</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
