<?php

declare(strict_types=1);

/**
 * Pflichtfelder bei der Selbstanmeldung (Spieler- bzw. Staff-Einladungslink), einzeln an- und
 * abhakbar. Nachname/Vorname/Telefon/Mail sind immer Pflicht und stehen deshalb nicht in der Liste.
 * Neue Felder ergänzen: includes/registration_fields.php (registration_player_field_registry() bzw.
 * registration_staff_field_registry()) - sie erscheinen dann automatisch hier.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration_fields.php';

require_permission('members.registrations');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    registration_required_keys_set('player', (array) ($_POST['player'] ?? []));
    registration_required_keys_set('staff', (array) ($_POST['staff'] ?? []));
    flash_set('info', 'Pflichtfelder wurden gespeichert.');
    redirect('registration-fields.php');
}

$requiredPlayer = registration_required_keys('player');
$requiredStaff = registration_required_keys('staff');

$pageTitle = 'Pflichtfelder bei der Anmeldung';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>Pflichtfelder bei der Anmeldung</h1>
    <a href="registrations.php" class="btn btn-link">&larr; Zurück zu Neue Mitglieder</a>
</div>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>

<p>Nachname, Vorname, Telefon und Mail sind bei beiden Anmeldelinks immer Pflicht (dazu bei Spielern,
falls minderjährig, die Zustimmung der Erziehungsberechtigten, und bei beiden die Zustimmung zur
Datenschutzerklärung). Alle anderen Felder lassen sich hier einzeln an- und abhaken - für den
Spieler-Link und den Staff-Link getrennt. Kommt später ein neues Feld dazu (z. B. eine neue Kategorie
bei Staff), erscheint es automatisch in der passenden Liste und ist bis zur Freigabe hier optional.</p>

<form method="post" action="registration-fields.php">
    <?= csrf_field() ?>
    <div class="form-row" style="align-items:flex-start;gap:32px;">
        <section class="panel" style="flex:1;min-width:280px;">
            <h2 class="section-title">Spieler-Link</h2>
            <?php foreach (registration_field_labels('player') as $key => $label): ?>
                <label style="font-weight:400;display:block;margin:6px 0;">
                    <input type="checkbox" name="player[]" value="<?= h($key) ?>" <?= in_array($key, $requiredPlayer, true) ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
            <?php endforeach; ?>
        </section>
        <section class="panel" style="flex:1;min-width:280px;">
            <h2 class="section-title">Staff-Link</h2>
            <?php foreach (registration_field_labels('staff') as $key => $label): ?>
                <label style="font-weight:400;display:block;margin:6px 0;">
                    <input type="checkbox" name="staff[]" value="<?= h($key) ?>" <?= in_array($key, $requiredStaff, true) ? 'checked' : '' ?>>
                    <?= h($label) ?>
                </label>
            <?php endforeach; ?>
        </section>
    </div>
    <p style="margin-top:16px;"><button type="submit" class="btn btn-primary">Speichern</button></p>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
