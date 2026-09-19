<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/camps.php';

require_admin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            camp_create((string) ($_POST['name'] ?? ''));
            flash_set('info', 'Camp wurde angelegt.');
            redirect('camps.php');
        } elseif ($action === 'rename') {
            camp_rename((int) ($_POST['id'] ?? 0), (string) ($_POST['name'] ?? ''));
            flash_set('info', 'Camp wurde umbenannt.');
            redirect('camps.php');
        } elseif ($action === 'delete') {
            if (!is_administrator()) {
                http_response_code(403);
                $error = 'Nur Administratoren dürfen Camps löschen.';
            } else {
                camp_delete((int) ($_POST['id'] ?? 0));
                flash_set('info', 'Camp wurde gelöscht (inklusive der Teilnahmen).');
                redirect('camps.php');
            }
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$camps = db()->query(
    'SELECT c.id, c.name, COUNT(e.member_id) AS members
     FROM camps c LEFT JOIN member_camp_entries e ON e.camp_id = c.id
     GROUP BY c.id, c.name, c.sort_order ORDER BY c.sort_order, c.name'
)->fetchAll();

$pageTitle = 'Camps';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>Camps</h1>
</div>

<p>Neben den festen Camps (<?= h(implode(', ', CAMPS_FIXED_NAMES)) ?>) können hier beliebig viele weitere Camps angelegt werden.
Jedes Camp erscheint bei allen Spielern als Ja/Nein-Feld, im Import/Export als eigene Spalte und in der API.
Neue Camps lassen sich auch direkt im Mitgliederformular unter „Nummer &amp; Camps“ hinzufügen.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="camps.php" class="panel" style="max-width:560px; margin-bottom:22px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <h2 class="section-title">Neues Camp</h2>
    <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="100" placeholder="z. B. Camp 3">
    </div>
    <button type="submit" class="btn btn-primary">Camp anlegen</button>
</form>

<div class="table-scroll">
<table class="table table-cards">
    <thead>
        <tr><th>Camp</th><th>Teilnehmer</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($camps)): ?>
        <tr><td colspan="3" class="empty">Noch keine weiteren Camps angelegt.</td></tr>
    <?php endif; ?>
    <?php foreach ($camps as $c): ?>
        <tr>
            <td data-label="Camp">
                <form method="post" action="camps.php" class="inline-rename">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <input type="text" name="name" value="<?= h((string) $c['name']) ?>" maxlength="100" required aria-label="Name des Camps">
                    <button type="submit" class="btn btn-sm">Umbenennen</button>
                </form>
            </td>
            <td data-label="Teilnehmer"><?= (int) $c['members'] ?></td>
            <td class="actions" data-label="">
                <?php if (is_administrator()): ?>
                <form method="post" action="camps.php" class="inline-form"
                      data-confirm="Camp &quot;<?= h((string) $c['name']) ?>&quot; löschen? Die Teilnahmen von <?= (int) $c['members'] ?> Spieler(n) werden ebenfalls gelöscht.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
