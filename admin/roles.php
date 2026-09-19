<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_permission('users.manage');

$registry = permissions_registry();
$error = null;
$roles = roles_all();

$editId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$creating = isset($_GET['new']);
$editing = null;
foreach ($roles as $r) {
    if ($editId !== null && (int) $r['id'] === $editId) {
        $editing = $r;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $roleId = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $selected = array_values(array_intersect(array_keys($registry), array_keys((array) ($_POST['perm'] ?? []))));

            if ($name === '' || mb_strlen($name) > 60) {
                throw new RuntimeException('Bitte einen Namen für die Rolle angeben (max. 60 Zeichen).');
            }

            if ($roleId === 0) {
                db()->prepare('INSERT INTO roles (name, description, is_system) VALUES (?, ?, 0)')->execute([$name, $description !== '' ? $description : null]);
                $roleId = (int) db()->lastInsertId();
                app_log('role.create', 'Rolle angelegt', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $name, 'rechte' => $selected]);
            } else {
                $current = null;
                foreach ($roles as $r) {
                    if ((int) $r['id'] === $roleId) {
                        $current = $r;
                    }
                }
                if ($current === null) {
                    throw new RuntimeException('Rolle nicht gefunden.');
                }
                if ($current['role_key'] === 'administrator') {
                    throw new RuntimeException('Die Rolle „Administrator“ ist fest und nicht änderbar.');
                }
                db()->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?')->execute([$name, $description !== '' ? $description : null, $roleId]);
                app_log('role.update', 'Rolle geändert', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $name, 'rechte' => $selected]);
            }

            db()->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $insert = db()->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
            foreach ($selected as $permission) {
                $insert->execute([$roleId, $permission]);
            }

            flash_set('info', 'Rolle „' . $name . '“ wurde gespeichert. Die Rechte gelten sofort.');
            redirect('roles.php');
        } elseif ($action === 'delete') {
            $roleId = (int) ($_POST['id'] ?? 0);
            $current = null;
            foreach ($roles as $r) {
                if ((int) $r['id'] === $roleId) {
                    $current = $r;
                }
            }
            if ($current === null) {
                throw new RuntimeException('Rolle nicht gefunden.');
            }
            if ((int) $current['is_system'] === 1) {
                throw new RuntimeException('Eingebaute Rollen können nicht gelöscht werden.');
            }
            if ((int) $current['users'] > 0) {
                throw new RuntimeException('Die Rolle wird noch von ' . (int) $current['users'] . ' Benutzer(n) verwendet. Bitte diesen Benutzern zuerst eine andere Rolle zuweisen.');
            }
            db()->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
            app_log('role.delete', 'Rolle gelöscht', ['target_type' => 'role', 'target_id' => $roleId, 'name' => $current['name']], 'warning');
            flash_set('info', 'Rolle wurde gelöscht.');
            redirect('roles.php');
        }
    } catch (PDOException $e) {
        $error = (int) $e->errorInfo[1] === 1062 ? 'Eine Rolle mit diesem Namen gibt es schon.' : 'Fehler beim Speichern.';
        $creating = $creating || (int) ($_POST['id'] ?? 0) === 0;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$groups = [];
foreach ($registry as $permission => [$label, $group]) {
    $groups[$group][$permission] = $label;
}

$pageTitle = 'Rollen';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
$showForm = $creating || $editing !== null;
$formPerms = $editing !== null ? ($editing['role_key'] === 'administrator' ? array_keys($registry) : role_permissions((int) $editing['id'])) : [];
$locked = $editing !== null && $editing['role_key'] === 'administrator';
?>
<div class="content-header">
    <h1>Rollen</h1>
    <div class="header-actions">
        <a href="users.php" class="btn">Benutzer</a>
        <a href="roles.php?new=1" class="btn btn-primary">+ Neue Rolle</a>
    </div>
</div>

<p>Eine Rolle bündelt Rechte für das Dashboard (z. B. „darf Mitglieder ansehen, aber nicht löschen“). Jedem Benutzer wird eine
Rolle zugewiesen; bei Bedarf lassen sich für einzelne Benutzer Rechte zusätzlich erlauben oder verbieten.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<?php if ($showForm): ?>
<form method="post" action="roles.php" class="panel" style="margin-bottom:24px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing !== null ? (int) $editing['id'] : 0 ?>">
    <h2 class="section-title"><?= $editing !== null ? 'Rolle bearbeiten: ' . h((string) $editing['name']) : 'Neue Rolle' ?></h2>

    <?php if ($locked): ?>
        <p class="alert alert-info">Die Rolle „Administrator“ darf immer alles und ist nicht änderbar.</p>
    <?php endif; ?>

    <div class="form-row">
        <div class="form-group">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= h((string) ($editing['name'] ?? ($_POST['name'] ?? ''))) ?>" required maxlength="60" <?= $locked ? 'disabled' : '' ?>>
        </div>
        <div class="form-group">
            <label for="description">Beschreibung (optional)</label>
            <input type="text" id="description" name="description" value="<?= h((string) ($editing['description'] ?? ($_POST['description'] ?? ''))) ?>" maxlength="255" <?= $locked ? 'disabled' : '' ?>>
        </div>
    </div>

    <?php foreach ($groups as $groupName => $items): ?>
        <h3 class="perm-group"><?= h($groupName) ?></h3>
        <div class="perm-checks">
            <?php foreach ($items as $permission => $label): ?>
                <label class="inline-check perm-check">
                    <input type="checkbox" name="perm[<?= h($permission) ?>]" value="1"
                           <?= in_array($permission, $formPerms, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                    <?= h($label) ?>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="filter-bar" style="margin-top:16px;">
        <?php if (!$locked): ?><button type="submit" class="btn btn-primary">Speichern</button><?php endif; ?>
        <a href="roles.php" class="btn btn-link">Abbrechen</a>
    </div>
</form>
<?php endif; ?>

<div class="table-scroll">
<table class="table table-cards">
    <thead>
        <tr><th>Rolle</th><th>Beschreibung</th><th>Rechte</th><th>Benutzer</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($roles as $r): ?>
        <?php $count = $r['role_key'] === 'administrator' ? count($registry) : count(role_permissions((int) $r['id'])); ?>
        <tr>
            <td data-label="Rolle"><strong><?= h((string) $r['name']) ?></strong>
                <?php if ((int) $r['is_system'] === 1): ?><span class="badge badge-gray">eingebaut</span><?php endif; ?></td>
            <td data-label="Beschreibung"><?= h((string) ($r['description'] ?? '')) ?></td>
            <td data-label="Rechte"><?= $count ?> von <?= count($registry) ?></td>
            <td data-label="Benutzer"><?= (int) $r['users'] ?></td>
            <td class="actions" data-label="">
                <a href="roles.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm"><?= $r['role_key'] === 'administrator' ? 'Ansehen' : 'Bearbeiten' ?></a>
                <?php if ((int) $r['is_system'] === 0): ?>
                <form method="post" action="roles.php" class="inline-form"
                      data-confirm="Rolle &quot;<?= h((string) $r['name']) ?>&quot; wirklich löschen?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger-outline">Löschen</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
