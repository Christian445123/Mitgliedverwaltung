<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';
require_once __DIR__ . '/../includes/registration.php';

require_permission('members.registrations');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_link') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $expiresAt = post_date('expires_at');
        $created = registration_link_create($label, current_admin_username(), $expiresAt !== null ? $expiresAt . ' 23:59:59' : null);
        flash_set('info', 'Registrierungslink wurde erzeugt.');
        redirect('registrations.php#link-' . $created['id']);
    } elseif ($action === 'toggle_link') {
        $id = (int) ($_POST['id'] ?? 0);
        registration_link_set_active($id, !empty($_POST['active']));
        flash_set('info', 'Registrierungslink wurde aktualisiert.');
        redirect('registrations.php');
    } elseif ($action === 'delete_link') {
        registration_link_delete((int) ($_POST['id'] ?? 0));
        flash_set('info', 'Registrierungslink wurde gelöscht.');
        redirect('registrations.php');
    } elseif ($action === 'save_notify_email') {
        $email = trim((string) ($_POST['notify_email'] ?? ''));
        if ($email !== '' && !is_valid_email($email)) {
            flash_set('error', 'Bitte eine gültige E-Mail-Adresse angeben (oder leer lassen, um keine Benachrichtigungen zu erhalten).');
        } else {
            registration_notify_email_set($email);
            flash_set('info', 'Benachrichtigungs-Adresse wurde gespeichert.');
        }
        redirect('registrations.php');
    } elseif ($action === 'upload_template') {
        try {
            registration_save_rechte_template($_FILES['rechte_template'] ?? []);
            flash_set('info', 'Vorlage „Rechte & Pflichten“ wurde hochgeladen.');
        } catch (RuntimeException $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('registrations.php');
    } elseif ($action === 'delete_template') {
        registration_delete_rechte_template();
        flash_set('info', 'Vorlage wurde entfernt.');
        redirect('registrations.php');
    } elseif ($action === 'approve') {
        $id = (int) ($_POST['id'] ?? 0);
        $kader = ($_POST['kader'] ?? '') === 'nicht_im_kader' ? 'nicht_im_kader' : 'kader';
        if (member_registration_approve($id, $kader)) {
            flash_set('info', 'Anmeldung wurde übernommen (' . ($kader === 'kader' ? 'Kader' : 'nicht im Kader') . ').');
        } else {
            flash_set('error', 'Anmeldung nicht gefunden oder bereits bearbeitet.');
        }
        redirect('registrations.php');
    } elseif ($action === 'reject') {
        $id = (int) ($_POST['id'] ?? 0);
        if (member_registration_reject($id)) {
            flash_set('info', 'Anmeldung wurde abgelehnt und gelöscht.');
        } else {
            flash_set('error', 'Anmeldung nicht gefunden oder bereits bearbeitet.');
        }
        redirect('registrations.php');
    }
}

$pending = member_registrations_pending();
$links = registration_links_all();
$notifyEmail = registration_notify_email();
$hasTemplate = rechte_template_exists();

$pageTitle = 'Neue Mitglieder';
require __DIR__ . '/../includes/admin_header.php';

$info = flash_get('info');
$error = flash_get('error');
?>
<div class="content-header">
    <h1>Neue Mitglieder</h1>
    <a href="index.php" class="btn btn-link">&larr; Zurück zur Mitgliederliste</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<p>Über einen Registrierungslink können sich neue Mitglieder selbst anmelden – mit denselben Feldern wie
im persönlichen Bestätigungs-Link eines bereits eingetragenen Spielers. Jede Anmeldung landet hier und
muss manuell dem <strong>Kader</strong> oder <strong>nicht im Kader</strong> zugewiesen werden, bevor sie
in der normalen Mitgliederliste erscheint.</p>

<section class="panel">
    <h2 class="section-title">Ausstehende Anmeldungen (<?= count($pending) ?>)</h2>

    <?php if ($pending === []): ?>
        <p class="muted">Aktuell keine neuen Anmeldungen.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table table-cards table-list">
        <thead>
            <tr>
                <th>Name &amp; Vorname</th>
                <th>E-Mail</th>
                <th>Telefon</th>
                <th>Verein</th>
                <th>Angemeldet am</th>
                <th class="actions-sticky">Zuweisung</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
            <tr>
                <td data-label="Name & Vorname"><a href="member-form.php?id=<?= (int) $r['id'] ?>"><?= h(member_full_name($r)) ?></a></td>
                <td data-label="E-Mail"><?= h($r['email'] ?? '') ?></td>
                <td data-label="Telefon"><?= h($r['telefon'] ?? '') ?></td>
                <td data-label="Verein"><?= h($r['verein'] ?? '') ?></td>
                <td data-label="Angemeldet am"><?= h(date('d.m.Y H:i', strtotime((string) $r['created_at']))) ?></td>
                <td class="actions actions-sticky" data-label="Zuweisung">
                    <form method="post" action="registrations.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="kader" value="kader">
                        <button type="submit" class="btn btn-sm btn-primary">In den Kader</button>
                    </form>
                    <form method="post" action="registrations.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="kader" value="nicht_im_kader">
                        <button type="submit" class="btn btn-sm">Nicht im Kader</button>
                    </form>
                    <form method="post" action="registrations.php" class="inline-form" data-confirm="Anmeldung von &quot;<?= h(member_full_name($r)) ?>&quot; wirklich ablehnen und löschen?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="link-button danger">Ablehnen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="section-title">Registrierungslinks</h2>
    <p>Diesen Link an neue Mitglieder weitergeben (z. B. per WhatsApp, E-Mail oder QR-Code). Ein Link kann
    von beliebig vielen Personen zur Anmeldung genutzt werden, bis er deaktiviert oder gelöscht wird.</p>

    <form method="post" action="registrations.php" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_link">
        <input type="text" name="label" placeholder="Bezeichnung, z. B. Saison 2026" maxlength="150">
        <label for="expires_at" class="muted" style="align-self:center;">Gültig bis (optional)</label>
        <input type="date" id="expires_at" name="expires_at">
        <button type="submit" class="btn btn-primary">Neuen Link erzeugen</button>
    </form>

    <?php if ($links === []): ?>
        <p class="muted">Noch kein Registrierungslink vorhanden.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="table table-cards table-list">
        <thead>
            <tr>
                <th>Bezeichnung</th>
                <th>Link</th>
                <th>Verwendet</th>
                <th>Gültig bis</th>
                <th>Status</th>
                <th class="actions-sticky">Aktionen</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($links as $l): ?>
            <?php $linkId = 'link-' . (int) $l['id']; $url = registration_build_url((string) $l['token']); ?>
            <tr id="<?= h($linkId) ?>">
                <td data-label="Bezeichnung"><?= h($l['label'] ?? '(ohne Bezeichnung)') ?></td>
                <td data-label="Link">
                    <div class="link-box">
                        <input type="text" readonly value="<?= h($url) ?>" id="<?= h($linkId) ?>-url" data-select-on-click>
                        <button type="button" class="btn btn-sm" data-copy-target="<?= h($linkId) ?>-url">Kopieren</button>
                    </div>
                </td>
                <td data-label="Verwendet"><?= (int) $l['use_count'] ?>×<?php if ($l['last_used_at']): ?><br><span class="muted">zuletzt <?= h(date('d.m.Y H:i', strtotime((string) $l['last_used_at']))) ?></span><?php endif; ?></td>
                <td data-label="Gültig bis"><?= $l['expires_at'] ? h(date('d.m.Y', strtotime((string) $l['expires_at']))) : '–' ?></td>
                <td data-label="Status"><span class="badge badge-<?= (int) $l['active'] === 1 ? 'green' : 'gray' ?>"><?= (int) $l['active'] === 1 ? 'Aktiv' : 'Deaktiviert' ?></span></td>
                <td class="actions actions-sticky" data-label="Aktionen">
                    <form method="post" action="registrations.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_link">
                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <input type="hidden" name="active" value="<?= (int) $l['active'] === 1 ? '0' : '1' ?>">
                        <button type="submit" class="btn btn-sm"><?= (int) $l['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                    </form>
                    <form method="post" action="registrations.php" class="inline-form" data-confirm="Link &quot;<?= h($l['label'] ?? '(ohne Bezeichnung)') ?>&quot; wirklich löschen? Er funktioniert danach nicht mehr.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_link">
                        <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                        <button type="submit" class="link-button danger">Löschen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="section-title">Benachrichtigung bei neuen bzw. doppelten Anmeldungen</h2>
    <p>An diese Adresse geht eine E-Mail, wenn eine neue Anmeldung zur Prüfung wartet, oder wenn sich
    jemand mit einer bereits vorhandenen E-Mail-Adresse anmelden will (die betroffene Person bekommt dann
    automatisch einen Link zum Aktualisieren ihrer Daten).</p>
    <form method="post" action="registrations.php" class="filter-bar">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_notify_email">
        <input type="email" name="notify_email" placeholder="admin@verein.at" value="<?= h($notifyEmail) ?>" style="min-width:260px;">
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</section>

<section class="panel">
    <h2 class="section-title">Vorlage „Rechte &amp; Pflichten“</h2>
    <p>Diese PDF-Vorlage können neue Mitglieder im Anmeldeformular (und bestehende über ihren persönlichen
    Link) herunterladen, ausdrucken, unterschreiben und danach wieder hochladen.</p>
    <?php if ($hasTemplate): ?>
        <p class="alert alert-success">Vorlage ist hinterlegt: <a href="<?= h(rechte_template_url()) ?>" target="_blank" rel="noopener">ansehen/herunterladen</a></p>
        <form method="post" action="registrations.php" data-confirm="Vorlage wirklich entfernen? Der Download-Link im Formular verschwindet dann.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_template">
            <button type="submit" class="link-button danger">Vorlage entfernen</button>
        </form>
    <?php else: ?>
        <p class="alert alert-warning">Noch keine Vorlage hinterlegt.</p>
    <?php endif; ?>
    <form method="post" action="registrations.php" enctype="multipart/form-data" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_template">
        <input type="file" name="rechte_template" accept="application/pdf" required>
        <button type="submit" class="btn"><?= $hasTemplate ? 'Vorlage ersetzen' : 'Vorlage hochladen' ?></button>
    </form>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
