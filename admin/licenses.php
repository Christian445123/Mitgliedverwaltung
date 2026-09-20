<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/licenses.php';

require_permission('license.manage');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'create') {
            $newId = license_create(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['expires_at'] ?? ''),
                (int) ($_POST['max_devices'] ?? 1)
            );
            app_log('license.create', 'Lizenz angelegt', ['target_type' => 'license', 'target_id' => $newId]);
            flash_set('info', 'Lizenz wurde angelegt. Den Schlüssel finden Sie in der Liste.');
        } elseif ($action === 'revoke' || $action === 'activate') {
            license_set_status($id, $action === 'revoke' ? 'revoked' : 'active');
            app_log($action === 'revoke' ? 'license.revoke' : 'license.activate', $action === 'revoke' ? 'Lizenz gesperrt' : 'Lizenz freigegeben', ['target_type' => 'license', 'target_id' => $id], 'warning');
            flash_set('info', $action === 'revoke' ? 'Lizenz wurde gesperrt. Die Anwendung erkennt das spätestens bei der nächsten Prüfung.' : 'Lizenz ist wieder aktiv.');
        } elseif ($action === 'release') {
            license_release_device((int) ($_POST['activation_id'] ?? 0));
            app_log('license.release', 'Lizenz-Gerät freigegeben', ['target_type' => 'license', 'target_id' => $id]);
            flash_set('info', 'Gerät wurde freigegeben. Der Schlüssel kann auf einem neuen Gerät aktiviert werden.');
        } elseif ($action === 'delete') {
            license_delete($id);
            app_log('license.delete', 'Lizenz gelöscht', ['target_type' => 'license', 'target_id' => $id], 'warning');
            flash_set('info', 'Lizenz wurde gelöscht.');
        }
        redirect('licenses.php');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$licenses = license_all();
$today = date('Y-m-d');

$pageTitle = 'Lizenzen';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>Lizenzen</h1>
</div>

<p>Die Desktop-Anwendung (C#) startet nur mit einem gültigen Lizenzschlüssel. Sie prüft ihn regelmäßig hier im Web-System.
Bricht die Verbindung ab, läuft sie mit der zuletzt erhaltenen Freigabe <strong>höchstens <?= (int) (license_offline_seconds() / 86400) ?> Tage</strong> weiter.
Ein gesperrter, abgelaufener oder gelöschter Schlüssel wird bei der nächsten Prüfung abgelehnt.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<form method="post" action="licenses.php" class="panel" style="max-width:640px; margin-bottom:22px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <h2 class="section-title">Neuer Lizenzschlüssel</h2>
    <div class="form-group">
        <label for="name">Name / Verwendung *</label>
        <input type="text" id="name" name="name" required maxlength="150" placeholder="z. B. Büro Wien">
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="expires_at">Gültig bis (optional)</label>
            <input type="date" id="expires_at" name="expires_at">
        </div>
        <div class="form-group form-group-small">
            <label for="max_devices">Geräte</label>
            <input type="number" id="max_devices" name="max_devices" value="1" min="1" max="50">
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Schlüssel erzeugen</button>
</form>

<table class="table table-cards">
    <thead>
        <tr><th>Name</th><th>Lizenzschlüssel</th><th>Status</th><th>Gültig bis</th><th>Geräte</th><th>Zuletzt geprüft</th><th>Aktionen</th></tr>
    </thead>
    <tbody>
    <?php if ($licenses === []): ?>
        <tr><td colspan="7" class="empty">Noch keine Lizenzen angelegt.</td></tr>
    <?php endif; ?>
    <?php foreach ($licenses as $l): ?>
        <?php
        $expired = !empty($l['expires_at']) && $l['expires_at'] < $today;
        $activations = (int) $l['devices'] > 0 ? license_activations((int) $l['id']) : [];
        ?>
        <tr>
            <td data-label="Name"><?= h($l['name']) ?></td>
            <td data-label="Lizenzschlüssel"><code style="user-select:all;"><?= h($l['license_key']) ?></code></td>
            <td data-label="Status">
                <?php if ($l['status'] !== 'active'): ?><span class="badge badge-red">Gesperrt</span>
                <?php elseif ($expired): ?><span class="badge badge-orange">Abgelaufen</span>
                <?php else: ?><span class="badge badge-green">Aktiv</span><?php endif; ?>
            </td>
            <td data-label="Gültig bis"><?= !empty($l['expires_at']) ? h(date('d.m.Y', strtotime((string) $l['expires_at']))) : 'unbegrenzt' ?></td>
            <td data-label="Geräte"><?= (int) $l['devices'] ?> / <?= (int) $l['max_devices'] ?></td>
            <td data-label="Zuletzt geprüft"><?= !empty($l['last_seen']) ? h(date('d.m.Y H:i', strtotime((string) $l['last_seen']))) : '–' ?></td>
            <td class="actions" data-label="">
                <form method="post" action="licenses.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <?php if ($l['status'] === 'active'): ?>
                        <button type="submit" name="action" value="revoke" class="link-button danger" data-confirm-button="Lizenz „<?= h($l['name']) ?>“ sperren? Die Anwendung wird bei der nächsten Prüfung gesperrt.">Sperren</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="activate" class="link-button">Freigeben</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="delete" class="link-button danger" data-confirm-button="Lizenz „<?= h($l['name']) ?>“ endgültig löschen?">Löschen</button>
                </form>
            </td>
        </tr>
        <?php foreach ($activations as $a): ?>
        <tr>
            <td colspan="7" style="background:var(--table-head, #f7f7fb); font-size:0.85rem;">
                ↳ <strong><?= h($a['machine_name'] ?: 'Unbekanntes Gerät') ?></strong>
                <span class="muted">· Version <?= h((string) $a['app_version']) ?> · zuletzt <?= h(date('d.m.Y H:i', strtotime((string) $a['last_seen']))) ?> · IP <?= h((string) $a['last_ip']) ?> · aktiviert <?= h(date('d.m.Y', strtotime((string) $a['first_seen']))) ?></span>
                <form method="post" action="licenses.php" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="release">
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <input type="hidden" name="activation_id" value="<?= (int) $a['id'] ?>">
                    <button type="submit" class="link-button" data-confirm-button="Dieses Gerät freigeben? Der Schlüssel kann dann auf einem anderen Gerät aktiviert werden.">Gerät freigeben</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
