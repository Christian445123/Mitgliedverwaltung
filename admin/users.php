<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_administrator();

$admins = db()->query('SELECT id, username, role, must_change_password, created_at FROM admins ORDER BY username')->fetchAll();

$pageTitle = 'Benutzerverwaltung';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
$error = flash_get('error');
?>
<div class="content-header">
    <h1>Benutzer (<?= count($admins) ?>)</h1>
    <a href="user-form.php" class="btn btn-primary">+ Neuer Benutzer</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<table class="table">
    <thead>
        <tr>
            <th>Benutzername</th>
            <th>Rolle</th>
            <th>Status</th>
            <th>Angelegt am</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($admins as $a): ?>
        <tr>
            <td>
                <?= h($a['username']) ?>
                <?php if ((int) $a['id'] === current_admin_id()): ?><span class="badge badge-blue">Du</span><?php endif; ?>
            </td>
            <td><span class="badge <?= $a['role'] === 'administrator' ? 'badge-green' : 'badge-gray' ?>"><?= $a['role'] === 'administrator' ? 'Administrator' : 'Bearbeiter' ?></span></td>
            <td>
                <?php if ((int) $a['must_change_password'] === 1): ?>
                    <span class="badge badge-orange">Passwort ausstehend</span>
                <?php else: ?>
                    <span class="badge badge-green">Aktiv</span>
                <?php endif; ?>
            </td>
            <td><?= h(date('d.m.Y', strtotime($a['created_at']))) ?></td>
            <td class="actions">
                <a href="user-form.php?id=<?= (int) $a['id'] ?>">Bearbeiten</a>
                <?php if ((int) $a['id'] !== current_admin_id()): ?>
                    <form method="post" action="user-delete.php" class="inline-form"
                          data-confirm="Benutzer &quot;<?= h($a['username']) ?>&quot; wirklich löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="link-button danger">Löschen</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
