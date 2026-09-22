<?php

declare(strict_types=1);

/**
 * Öffentliche Anmeldeseite für neue Mitglieder ODER neue Staff-Personen (je nachdem, welcher Link
 * verwendet wurde, siehe admin/registrations.php - "Neue Mitglieder"). Spieler-Link: alle Felder wie
 * beim persönlichen Bestätigungs-Link (mitglied-formular.php), Pflichtfelder einstellbar unter
 * "Neue Mitglieder" → Pflichtfelder. Staff-Link: schlankeres Formular (includes/staff_registration_fields.php),
 * Nachname/Vorname/Telefon/Mail immer Pflicht, alles andere ebenfalls dort einstellbar.
 *
 * Abgleich: Ist die angegebene E-Mail-Adresse bereits bei einem Mitglied bzw. einer Staff-Person
 * hinterlegt, wird kein zweiter Datensatz angelegt. Stattdessen bekommt die bestehende Person
 * automatisch einen Link zum Aktualisieren ihrer Daten, und der Systemadministrator wird benachrichtigt.
 *
 * Neue Anmeldungen landen mit status = 'neu' und müssen im Webpanel manuell freigegeben werden, bevor
 * sie in der normalen Mitglieder- bzw. Staff-Liste erscheinen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/member_repository.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/registration.php';
require_once __DIR__ . '/includes/registration_fields.php';
require_once __DIR__ . '/includes/dsgvo.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$link = registration_find_by_token($token);

if ($link === false) {
    http_response_code(404);
    $pageTitle = 'Ungültiger Link';
    require __DIR__ . '/includes/public_header.php';
    echo '<div class="verify-box"><p class="alert alert-error">Dieser Registrierungslink ist ungültig, deaktiviert oder abgelaufen. Bitte beim Verein einen neuen Link anfordern.</p></div>';
    require __DIR__ . '/includes/public_footer.php';
    exit;
}

$isStaff = ($link['link_type'] ?? 'player') === 'staff';
$requiredKeys = registration_required_keys($isStaff ? 'staff' : 'player');

$error = null;
$duplicateNotice = null;
// Nach erfolgreichem Speichern wird umgeleitet (Post/Redirect/Get): sonst bleibt der Browser auf der
// POST-Anfrage stehen - "Zurück" funktioniert dann nicht sauber, und ein Neuladen sendet das Formular
// (mit denselben Daten) erneut, was bei der bereits angelegten E-Mail-Adresse wie ein Fehler aussieht.
$saved = ($_GET['done'] ?? '') === '1';
if (($_GET['notice'] ?? '') === 'duplicate') {
    $duplicateNotice = $isStaff
        ? 'Für diese E-Mail-Adresse besteht bereits eine Person im Staff. Sie wurde automatisch per E-Mail gebeten, ihre Daten zu prüfen/aktualisieren, und der Verein wurde informiert. Bitte bei Fragen an den Verein wenden.'
        : 'Für diese E-Mail-Adresse besteht bereits ein Mitglied. Die Person wurde automatisch per E-Mail gebeten, ihre Daten zu prüfen/aktualisieren, und der Verein wurde informiert. Bitte bei Fragen an den Verein wenden.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $consent = !empty($_POST['consent']);
    $guardianConsent = !empty($_POST['guardian_consent']);
    $guardianName = trim((string) ($_POST['guardian_name'] ?? ''));

    try {
        if (!$consent) {
            throw new RuntimeException('Bitte stimme der Datenschutzerklärung zu, um dich anzumelden.');
        }

        if ($isStaff) {
            $data = staff_collect_input_public();
            staff_require_fields($data, $requiredKeys);

            $existing = staff_find_existing(['email' => $data['email']]);
            if ($existing !== false) {
                registration_handle_duplicate_staff($existing);
                redirect('registrieren.php?token=' . $token . '&notice=duplicate');
            }

            $data['status'] = 'neu';
            $newId = staff_upsert($data, null);
            foreach (['pass' => 'pass_dokument', 'ecard' => 'ecard_dokument', 'rechte' => 'rechte_dokument'] as $docType => $inputName) {
                staff_set_document($newId, $docType, $inputName);
            }
            dsgvo_consent_record('staff', $newId, null);
            registration_record_use((int) $link['id']);
            app_log('staff.self_register', 'Neue Staff-Anmeldung über Einladungslink', ['actor' => 'staff:' . $newId, 'target_type' => 'staff', 'target_id' => $newId, 'link_id' => $link['id']]);
            $newStaff = staff_find_by_id($newId);
            registration_notify_new_staff($newStaff !== false ? $newStaff : $data);
            redirect('registrieren.php?token=' . $token . '&done=1');
        }

        $data = member_collect_input([], 'player', $requiredKeys);

        $minor = dsgvo_is_minor('members', $data);
        if ($minor && (!$guardianConsent || $guardianName === '')) {
            throw new RuntimeException('Da du minderjährig bist (oder kein Geburtsdatum angegeben hast), müssen die Erziehungsberechtigten zustimmen: bitte bestätigen und den Namen angeben.');
        }

        $existing = member_find_by_email((string) $data['email']);
        if ($existing !== false) {
            registration_handle_duplicate($existing);
            redirect('registrieren.php?token=' . $token . '&notice=duplicate');
        }

        $newId = member_upsert($data, null, 'neu');
        dsgvo_consent_record('members', $newId, $minor ? mb_substr($guardianName, 0, 150) : null);
        registration_record_use((int) $link['id']);
        app_log('member.self_register', 'Neue Anmeldung über Registrierungslink', ['actor' => 'member:' . $newId, 'target_type' => 'member', 'target_id' => $newId, 'link_id' => $link['id']]);
        $newMember = member_find_by_id($newId);
        registration_notify_new($newMember !== false ? $newMember : $data);
        redirect('registrieren.php?token=' . $token . '&done=1');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$m = $saved || $duplicateNotice !== null ? [] : $_POST;
$showAdminFields = false;
$isRegistration = true; // blendet Jersey-Nr., Camps und Ausrüstungsgrößen aus (legt der Verein erst nach der Zuweisung fest)

$pageTitle = $isStaff ? 'Anmeldung – Neuer Staff' : 'Anmeldung – Neues Mitglied';
require __DIR__ . '/includes/public_header.php';
?>
<div class="verify-box">
    <h1><?= $isStaff ? 'Anmeldung als Staff (Trainer/Betreuer)' : 'Anmeldung als neues Mitglied' ?></h1>

    <?php if ($saved): ?>
        <p class="alert alert-success">Danke! Deine Anmeldung ist eingegangen und wird vom Verein geprüft.
        <?= $isStaff ? '' : 'Sobald sie zugewiesen ist, bekommst du deinen persönlichen Link zur weiteren Verwaltung deiner Daten.' ?></p>
        <p><a href="registrieren.php?token=<?= h($token) ?>" class="btn btn-primary">Noch eine Person anmelden</a></p>
    <?php else: ?>
        <p>Pflichtfelder sind mit * markiert.<?= $isStaff ? '' : ' Jersey-Nummer, Camps und Ausrüstungsgrößen legt der Verein nach der Zuweisung mit dir fest.' ?></p>

        <?php if ($duplicateNotice): ?><p class="alert alert-warning"><?= h($duplicateNotice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

        <form method="post" action="registrieren.php?token=<?= h($token) ?>" class="member-form" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <?php if ($isStaff): ?>
                <?php require __DIR__ . '/includes/staff_registration_fields.php'; ?>
            <?php else: ?>
                <?php require __DIR__ . '/includes/member_fields.php'; ?>
            <?php endif; ?>

            <fieldset>
                <legend>Datenschutz</legend>
                <label style="font-weight:400;display:block;margin:12px 0;">
                    <input type="checkbox" name="consent" value="1" <?= !empty($_POST['consent']) ? 'checked' : '' ?> required>
                    Ich habe die <a href="datenschutz.php" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und willige in die Verarbeitung meiner Daten ein.
                    Ich kann diese Einwilligung jederzeit widerrufen. *
                </label>
                <?php if (!$isStaff): ?>
                <p class="muted">Nur falls die angemeldete Person minderjährig ist:</p>
                <label style="font-weight:400;display:block;margin:8px 0;">
                    <input type="checkbox" name="guardian_consent" value="1" <?= !empty($_POST['guardian_consent']) ? 'checked' : '' ?>>
                    Ich bin erziehungsberechtigt und stimme der Verarbeitung der Daten der oben angemeldeten Person zu.
                </label>
                <label for="guardian_name">Name der/des Erziehungsberechtigten</label>
                <input type="text" id="guardian_name" name="guardian_name" maxlength="150" value="<?= h((string) ($_POST['guardian_name'] ?? '')) ?>">
                <?php endif; ?>
            </fieldset>

            <button type="submit" class="btn btn-primary">Anmeldung absenden</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
