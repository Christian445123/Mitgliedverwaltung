<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$existing = $id !== null ? member_find_by_id($id) : false;

if ($id !== null && $existing === false) {
    flash_set('error', 'Mitglied nicht gefunden.');
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $data = member_collect_input($existing ?: []);
        $status = ($_POST['status'] ?? 'aktiv') === 'inaktiv' ? 'inaktiv' : 'aktiv';

        $savedId = member_upsert($data, $id, $status);

        flash_set('info', 'Mitglied wurde gespeichert.');
        redirect('member-form.php?id=' . $savedId);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $existing = array_merge($existing ?: [], $_POST);
    }
}

$m = $existing ?: [];
$emailLocked = false;
$showAdminFields = true;
$isNew = $id === null;

$pageTitle = $isNew ? 'Neues Mitglied' : 'Mitglied bearbeiten';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
?>
<h1><?= $isNew ? 'Neues Mitglied anlegen' : 'Mitglied bearbeiten' ?></h1>
<p><a class="muted" href="index.php">&larr; Zurück zur Übersicht</a></p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="member-form.php<?= $id !== null ? '?id=' . (int) $id : '' ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <?php require __DIR__ . '/../includes/member_fields.php'; ?>

    <div class="form-actions">
        <button type="submit">Speichern</button>
    </div>
</form>

<?php if (!$isNew): ?>
<form method="post" action="member-delete.php" onsubmit="return confirm('Mitglied wirklich unwiderruflich löschen?');" style="margin-top:0.5rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" class="btn btn-danger">Mitglied löschen</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
