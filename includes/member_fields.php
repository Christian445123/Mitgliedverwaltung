<?php

declare(strict_types=1);

/**
 * Gemeinsames Formular-Fragment für Mitgliedsdaten.
 * Feldreihenfolge folgt exakt der Original-Tabelle (Spalte für Spalte).
 *
 * Erwartet im Scope:
 *   array $m                Mitgliedsdaten (Schlüssel = Spaltennamen, fehlende Werte werden als '' behandelt)
 *   bool  $showAdminFields  true = zusätzliche Felder, die nur der Admin sieht/setzt (Status)
 *   bool  $isRegistration   true = öffentliches Anmeldeformular für neue Mitglieder (registrieren.php):
 *                           Jersey-Nr., Camps und Ausrüstungsgrößen sind hier ausgeblendet, weil das
 *                           erst der Verein nach der Prüfung/Zuweisung festlegt.
 *   array $requiredKeys    Schlüssel (wie member_collect_input() sie liefert), die bei der
 *                           Spieler-Neuanmeldung Pflicht sind - einstellbar unter "Neue Mitglieder"
 *                           → Pflichtfelder (siehe includes/registration_fields.php). Leer bei der
 *                           Datenprüfung eines bereits bestehenden Mitglieds (mitglied-formular.php)
 *                           und im Admin-Formular (dort bleibt es bei Nachname/Vorname/Telefon/Mail).
 */

if (!isset($m) || !is_array($m)) {
    $m = [];
}
$showAdminFields = $showAdminFields ?? false;
$isRegistration = $isRegistration ?? false;
$requiredKeys = $requiredKeys ?? [];
$reqOf = static fn (string $key): string => in_array($key, $requiredKeys, true) ? ' required' : '';
$reqMark = static fn (string $key): string => $reqOf($key) !== '' ? ' *' : '';

$v = static fn (string $key) => h((string) ($m[$key] ?? ''));
$checked = static fn (string $key) => !empty($m[$key]) ? 'checked' : '';

// Hinweis am Upload-Feld: "vorhanden" (für Admins mit Link zum Dokument)
$docNote = static function (string $column, string $type) use ($m, $showAdminFields): string {
    if (empty($m[$column])) {
        return '';
    }
    $link = ($showAdminFields && !empty($m['id']) && user_can('documents.view'))
        ? ' – <a href="document.php?id=' . (int) $m['id'] . '&amp;type=' . h($type) . '" target="_blank" rel="noopener">ansehen</a>'
        : '';
    return ' (vorhanden' . $link . ' – neu hochladen zum Ersetzen)';
};

// Erziehungsberechtigte-Angaben: im öffentlichen Formular nur bei
// Minderjährigen einblenden (Admin sieht/bearbeitet sie immer).
$isMinor = true; // solange kein Geburtsdatum bekannt ist, sicherheitshalber anzeigen
if (!empty($m['geburtsdatum'])) {
    try {
        $isMinor = (new DateTime($m['geburtsdatum']))->diff(new DateTime())->y < 18;
    } catch (Exception $e) {
        $isMinor = true;
    }
}
$showGuardianSection = $showAdminFields || $isMinor;

require_once __DIR__ . '/field_access.php';
$faAudience = $showAdminFields ? field_access_admin_audience() : 'player';
ob_start(); // Formular puffern, damit gesperrte Felder (Feld-Rechte) am Ende entfernt werden können
?>
<?php if (!$isRegistration): ?>
<fieldset>
    <legend>Nummer &amp; Camps</legend>

    <div class="form-group form-group-small">
        <label for="jersey_nr">Jersey Nr.</label>
        <input type="text" id="jersey_nr" name="jersey_nr" value="<?= $v('jersey_nr') ?>" maxlength="10">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label><input type="checkbox" name="camp_1" value="1" <?= $checked('camp_1') ?>> Camp 1</label>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="spanien" value="1" <?= $checked('spanien') ?>> Spanien</label>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="camp_2" value="1" <?= $checked('camp_2') ?>> Camp 2</label>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="tschechien" value="1" <?= $checked('tschechien') ?>> Tschechien</label>
        </div>
    </div>

    <?php
    // Weitere Camps (zusätzlich zu den vier festen): Häkchen je Camp + neue Camps direkt anlegen
    $extraCamps = camps_all();
    ?>
    <div class="form-group" hidden><input type="hidden" name="camps_present" value="1"></div>
    <?php if ($extraCamps !== []): ?>
    <div class="form-row camps-extra">
        <?php foreach ($extraCamps as $camp): ?>
        <div class="form-group">
            <label><input type="checkbox" name="camp[<?= (int) $camp['id'] ?>]" value="1" <?= !empty($m['camp:' . $camp['id']]) ? 'checked' : '' ?>> <?= h($camp['name']) ?></label>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($showAdminFields): ?>
    <div class="form-group new-camps" data-new-camps>
        <label for="new_camp_1">Neues Camp hinzufügen</label>
        <div class="new-camp-row">
            <input type="text" id="new_camp_1" name="new_camps[]" maxlength="100" placeholder="Name des neuen Camps, z. B. Camp 3">
        </div>
        <button type="button" class="btn btn-sm" data-add-camp>+ Weiteres Camp</button>
        <p class="muted">Das neue Camp wird beim Speichern angelegt, dem Spieler zugeordnet und steht danach bei allen Spielern zur Auswahl.
            Verwalten und löschen: <a href="camps.php">Camps</a>.</p>
    </div>
    <?php endif; ?>
</fieldset>
<?php endif; ?>

<fieldset>
    <legend>Persönliche Daten</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="nachname">Nachname *</label>
            <input type="text" id="nachname" name="nachname" value="<?= $v('nachname') ?>" required maxlength="100">
        </div>
        <div class="form-group">
            <label for="vorname">Vorname *</label>
            <input type="text" id="vorname" name="vorname" value="<?= $v('vorname') ?>" required maxlength="100">
        </div>
    </div>

    <?php if ($showAdminFields && !empty($m['id'])): ?>
    <div class="form-group">
        <label for="name_vorname_auto">Name &amp; Vorname (automatisch)</label>
        <input type="text" id="name_vorname_auto" value="<?= h(member_full_name($m)) ?>" readonly tabindex="-1">
    </div>
    <?php endif; ?>

    <div class="form-row">
        <div class="form-group form-group-small">
            <label for="sz">Selbstzahler<?= $reqMark('sz') ?></label>
            <input type="text" id="sz" name="sz" value="<?= $v('sz') ?>" maxlength="50"<?= $reqOf('sz') ?>>
        </div>
        <div class="form-group form-group-small">
            <label for="bezirk">Bez.<?= $reqMark('bezirk') ?></label>
            <input type="text" id="bezirk" name="bezirk" value="<?= $v('bezirk') ?>" maxlength="100"<?= $reqOf('bezirk') ?>>
        </div>
        <div class="form-group">
            <label for="position">Position<?= $reqMark('position') ?></label>
            <input type="text" id="position" name="position" value="<?= $v('position') ?>" maxlength="100"<?= $reqOf('position') ?>>
        </div>
        <div class="form-group">
            <label for="geburtsdatum">Geburtsdatum<?= $reqMark('geburtsdatum') ?></label>
            <input type="date" id="geburtsdatum" name="geburtsdatum" value="<?= $v('geburtsdatum') ?>"<?= $reqOf('geburtsdatum') ?>>
        </div>
    </div>

    <div class="form-group">
        <label for="verein">Verein<?= $reqMark('verein') ?></label>
        <input type="text" id="verein" name="verein" value="<?= $v('verein') ?>" maxlength="150"<?= $reqOf('verein') ?>>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="groesse_cm">Größe (cm)<?= $reqMark('groesse_cm') ?></label>
            <input type="number" id="groesse_cm" name="groesse_cm" value="<?= $v('groesse_cm') ?>" min="0" max="300"<?= $reqOf('groesse_cm') ?>>
        </div>
        <div class="form-group">
            <label for="gewicht_kg">Gewicht (KG)<?= $reqMark('gewicht_kg') ?></label>
            <input type="number" id="gewicht_kg" name="gewicht_kg" value="<?= $v('gewicht_kg') ?>" min="0" max="400"<?= $reqOf('gewicht_kg') ?>>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Kontakt</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="telefon">Telefon Spieler *</label>
            <input type="tel" id="telefon" name="telefon" value="<?= $v('telefon') ?>" required maxlength="50">
        </div>
        <div class="form-group">
            <label for="email">Mail *</label>
            <input type="email" id="email" name="email" value="<?= $v('email') ?>" required maxlength="190">
        </div>
    </div>
</fieldset>

<fieldset id="guardian-fieldset" <?= $showGuardianSection ? '' : 'hidden' ?>>
    <legend>Erziehungsberechtigte (falls minderjährig)</legend>

    <?php $reqGuardian = static fn (string $key): string => $isMinor ? $reqOf($key) : ''; ?>
    <div class="form-group">
        <label for="erz_name">Name Erziehungsberechtigter<?= $reqGuardian('erz_name') !== '' ? ' *' : '' ?></label>
        <input type="text" id="erz_name" name="erz_name" value="<?= $v('erz_name') ?>" maxlength="150"<?= $reqGuardian('erz_name') ?>>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="erz_telefon">Telefon Erzieh<?= $reqGuardian('erz_telefon') !== '' ? ' *' : '' ?></label>
            <input type="tel" id="erz_telefon" name="erz_telefon" value="<?= $v('erz_telefon') ?>" maxlength="50"<?= $reqGuardian('erz_telefon') ?>>
        </div>
        <div class="form-group">
            <label for="erz_email">Mail Erzieh<?= $reqGuardian('erz_email') !== '' ? ' *' : '' ?></label>
            <input type="email" id="erz_email" name="erz_email" value="<?= $v('erz_email') ?>" maxlength="190"<?= $reqGuardian('erz_email') ?>>
        </div>
    </div>
</fieldset>

<?php if (!$showAdminFields): ?>
<script>
(function () {
    var dateInput = document.getElementById('geburtsdatum');
    var fieldset = document.getElementById('guardian-fieldset');
    if (!dateInput || !fieldset) {
        return;
    }

    function isMinor(value) {
        if (!value) {
            return true; // Geburtsdatum unbekannt -> sicherheitshalber anzeigen
        }
        var birthDate = new Date(value);
        if (isNaN(birthDate.getTime())) {
            return true;
        }
        var today = new Date();
        var age = today.getFullYear() - birthDate.getFullYear();
        var monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age < 18;
    }

    function update() {
        fieldset.hidden = !isMinor(dateInput.value);
    }

    dateInput.addEventListener('change', update);
    dateInput.addEventListener('input', update);
    update();
})();
</script>
<?php endif; ?>

<fieldset>
    <legend>Rechte &amp; Pflicht</legend>
    <?php if (rechte_template_exists()): ?>
    <p class="muted">
        <a href="<?= h(rechte_template_url()) ?>" target="_blank" rel="noopener">Vorlage „Rechte &amp; Pflichten“ herunterladen (PDF)</a>
        – bitte ausdrucken, unterschreiben und unten wieder hochladen.
    </p>
    <?php endif; ?>
    <div class="form-group">
        <label>
            <input type="checkbox" name="rechte_pflichten_akzeptiert" value="1" <?= $checked('rechte_pflichten_akzeptiert') ?> <?= $showAdminFields ? '' : 'required' ?>>
            Ich akzeptiere die Rechte und Pflichten des Vereins *
        </label>
    </div>
    <?php $reqDoc = static fn (string $key, string $column): string => empty($m[$column]) ? $reqOf($key) : ''; ?>
    <div class="form-group">
        <label for="rechte_pflichten_dokument">Unterschriebenes Dokument Rechte &amp; Pflichten<?= $docNote('rechte_pflichten_dokument_pfad', 'rechte') ?><?= $reqDoc('rechte_pflichten_dokument_pfad', 'rechte_pflichten_dokument_pfad') !== '' ? ' *' : '' ?></label>
        <input type="file" id="rechte_pflichten_dokument" name="rechte_pflichten_dokument" accept=".jpg,.jpeg,.png,.pdf"<?= $reqDoc('rechte_pflichten_dokument_pfad', 'rechte_pflichten_dokument_pfad') ?>>
        <?php if ($showAdminFields): ?><input type="hidden" name="docflags_present" value="1"><?php endif; ?>
        <?php if ($showAdminFields): ?><label class="doc-missing"><input type="checkbox" name="fehlt_rechte" value="1" <?= $checked("fehlt_rechte") ?>> Fehlt</label><?php endif; ?>
    </div>
</fieldset>

<fieldset>
    <legend>Bild E-Card &amp; Sozialversicherung</legend>

    <div class="form-group">
        <label for="bild_ecard">E-Card<?= $docNote('bild_ecard_pfad', 'ecard') ?><?= $reqDoc('bild_ecard_pfad', 'bild_ecard_pfad') !== '' ? ' *' : '' ?></label>
        <input type="file" id="bild_ecard" name="bild_ecard" accept=".jpg,.jpeg,.png,.pdf"<?= $reqDoc('bild_ecard_pfad', 'bild_ecard_pfad') ?>>
        <?php if ($showAdminFields): ?><label class="doc-missing"><input type="checkbox" name="fehlt_ecard" value="1" <?= $checked("fehlt_ecard") ?>> Fehlt</label><?php endif; ?>
    </div>
    <div class="form-group">
        <label for="sozialversicherungsnummer">Sozial Ver. Nr.<?= $reqMark('sozialversicherungsnummer') ?></label>
        <input type="text" id="sozialversicherungsnummer" name="sozialversicherungsnummer" value="<?= $v('sozialversicherungsnummer') ?>" maxlength="20"<?= $reqOf('sozialversicherungsnummer') ?>>
    </div>
</fieldset>

<fieldset>
    <legend>NADA (Anti-Doping)</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="nada_zertifikat">Nada Zertifikat<?= $reqMark('nada_zertifikat') ?></label>
            <input type="text" id="nada_zertifikat" name="nada_zertifikat" value="<?= $v('nada_zertifikat') ?>" maxlength="100"<?= $reqOf('nada_zertifikat') ?>>
        </div>
        <div class="form-group">
            <label for="nada_gueltig_bis">Nada gültig bis<?= $reqMark('nada_gueltig_bis') ?></label>
            <input type="date" id="nada_gueltig_bis" name="nada_gueltig_bis" value="<?= $v('nada_gueltig_bis') ?>"<?= $reqOf('nada_gueltig_bis') ?>>
        </div>
    </div>

    <div class="form-group">
        <label for="nada_dokument">NADA-Zertifikat (Dokument)<?= $docNote('nada_dokument_pfad', 'nada') ?><?= $reqDoc('nada_dokument_pfad', 'nada_dokument_pfad') !== '' ? ' *' : '' ?></label>
        <input type="file" id="nada_dokument" name="nada_dokument" accept=".jpg,.jpeg,.png,.pdf"<?= $reqDoc('nada_dokument_pfad', 'nada_dokument_pfad') ?>>
        <?php if ($showAdminFields): ?><label class="doc-missing"><input type="checkbox" name="fehlt_nada" value="1" <?= $checked("fehlt_nada") ?>> Fehlt</label><?php endif; ?>
    </div>
</fieldset>

<fieldset>
    <legend>Reisepass</legend>

    <div class="form-group">
        <label for="pass_foto">Reisepass (Foto)<?= $docNote('pass_foto_pfad', 'pass') ?><?= $reqDoc('pass_foto_pfad', 'pass_foto_pfad') !== '' ? ' *' : '' ?></label>
        <input type="file" id="pass_foto" name="pass_foto" accept=".jpg,.jpeg,.png,.pdf"<?= $reqDoc('pass_foto_pfad', 'pass_foto_pfad') ?>>
        <?php if ($showAdminFields): ?><label class="doc-missing"><input type="checkbox" name="fehlt_pass" value="1" <?= $checked("fehlt_pass") ?>> Fehlt</label><?php endif; ?>
    </div>

    <div class="form-group">
        <label for="reisepass_nr">Reisepass Nr<?= $reqMark('reisepass_nr') ?></label>
        <input type="text" id="reisepass_nr" name="reisepass_nr" value="<?= $v('reisepass_nr') ?>" maxlength="50"<?= $reqOf('reisepass_nr') ?>>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="reisepass_ausgestellt_am">Reisepass ausgestellt am<?= $reqMark('reisepass_ausgestellt_am') ?></label>
            <input type="date" id="reisepass_ausgestellt_am" name="reisepass_ausgestellt_am" value="<?= $v('reisepass_ausgestellt_am') ?>"<?= $reqOf('reisepass_ausgestellt_am') ?>>
        </div>
        <div class="form-group">
            <label for="reisepass_gueltig_bis">Reisepass gültig bis<?= $reqMark('reisepass_gueltig_bis') ?></label>
            <input type="date" id="reisepass_gueltig_bis" name="reisepass_gueltig_bis" value="<?= $v('reisepass_gueltig_bis') ?>"<?= $reqOf('reisepass_gueltig_bis') ?>>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="geburtsland">Geburtsland<?= $reqMark('geburtsland') ?></label>
            <input type="text" id="geburtsland" name="geburtsland" value="<?= $v('geburtsland') ?>" maxlength="100"<?= $reqOf('geburtsland') ?>>
        </div>
        <div class="form-group">
            <label for="geburtsort">Geburtsort<?= $reqMark('geburtsort') ?></label>
            <input type="text" id="geburtsort" name="geburtsort" value="<?= $v('geburtsort') ?>" maxlength="100"<?= $reqOf('geburtsort') ?>>
        </div>
    </div>

    <div class="form-group">
        <label for="ausstellungsbehoerde">Ausstellungsbehörde<?= $reqMark('ausstellungsbehoerde') ?></label>
        <input type="text" id="ausstellungsbehoerde" name="ausstellungsbehoerde" value="<?= $v('ausstellungsbehoerde') ?>" maxlength="150"<?= $reqOf('ausstellungsbehoerde') ?>>
    </div>
</fieldset>

<fieldset>
    <legend>Adresse</legend>

    <div class="form-row">
        <div class="form-group form-group-small">
            <label for="plz">PLZ<?= $reqMark('plz') ?></label>
            <input type="text" id="plz" name="plz" value="<?= $v('plz') ?>" maxlength="10"<?= $reqOf('plz') ?>>
        </div>
        <div class="form-group">
            <label for="ort">Ort<?= $reqMark('ort') ?></label>
            <input type="text" id="ort" name="ort" value="<?= $v('ort') ?>" maxlength="100"<?= $reqOf('ort') ?>>
        </div>
        <div class="form-group">
            <label for="strasse">Straße<?= $reqMark('strasse') ?></label>
            <input type="text" id="strasse" name="strasse" value="<?= $v('strasse') ?>" maxlength="150"<?= $reqOf('strasse') ?>>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Essen</legend>
    <div class="form-group">
        <label for="essen">Essen (Allergien / besondere Wünsche)<?= $reqMark('essen') ?></label>
        <textarea id="essen" name="essen" maxlength="255" rows="2"<?= $reqOf('essen') ?>><?= $v('essen') ?></textarea>
    </div>
</fieldset>

<?php if (!$isRegistration): ?>
<fieldset>
    <legend>Ausrüstungsgrößen</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="game_jersey_groesse">Game Jersey Größe (MACRON)</label>
            <input type="text" id="game_jersey_groesse" name="game_jersey_groesse" value="<?= $v('game_jersey_groesse') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label for="game_hosen_groesse">Game Hosen Größe (MACRON)</label>
            <input type="text" id="game_hosen_groesse" name="game_hosen_groesse" value="<?= $v('game_hosen_groesse') ?>" maxlength="10">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="helm_groesse">Helm Größe</label>
            <input type="text" id="helm_groesse" name="helm_groesse" value="<?= $v('helm_groesse') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label style="margin-top:32px;">
                <input type="checkbox" name="helm_eigener" value="1" <?= $checked('helm_eigener') ?>>
                Helm verwendest du (eigenen Helm)
            </label>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="tshirt_polo_groesse">T-Shirt &amp; Polo Größe (MACRON)</label>
            <input type="text" id="tshirt_polo_groesse" name="tshirt_polo_groesse" value="<?= $v('tshirt_polo_groesse') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label for="hoodie_groesse">Hoodie Größe (MACRON)</label>
            <input type="text" id="hoodie_groesse" name="hoodie_groesse" value="<?= $v('hoodie_groesse') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label for="mesh_shorts_groesse">Mesh Shorts Größe (MACRON)</label>
            <input type="text" id="mesh_shorts_groesse" name="mesh_shorts_groesse" value="<?= $v('mesh_shorts_groesse') ?>" maxlength="10">
        </div>
    </div>

    <div class="form-group">
        <label for="socken_groesse">Socken Größe</label>
        <input type="text" id="socken_groesse" name="socken_groesse" value="<?= $v('socken_groesse') ?>" maxlength="10">
    </div>
</fieldset>
<?php endif; ?>

<?php if ($showAdminFields): ?>
<fieldset>
    <legend>Verwaltung (nur Admin)</legend>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="aktiv" <?= ($m['status'] ?? 'aktiv') === 'aktiv' ? 'selected' : '' ?>>Aktiv</option>
            <option value="inaktiv" <?= ($m['status'] ?? '') === 'inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
            <?php if (($m['status'] ?? '') === 'neu'): ?>
            <option value="neu" selected>Neu (Anmeldung, noch nicht zugewiesen)</option>
            <?php endif; ?>
        </select>
        <?php if (($m['status'] ?? '') === 'neu'): ?>
        <p class="muted">Diese Person hat sich über den öffentlichen Registrierungslink selbst angemeldet und wartet auf Zuweisung.
            Am schnellsten geht das im Bereich <a href="registrations.php">Neue Mitglieder</a> (Kader / nicht im Kader).</p>
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label for="kader">Kader</label>
        <select id="kader" name="kader">
            <option value="kader" <?= ($m['kader'] ?? 'kader') !== 'nicht_im_kader' ? 'selected' : '' ?>>Im Kader</option>
            <option value="nicht_im_kader" <?= ($m['kader'] ?? '') === 'nicht_im_kader' ? 'selected' : '' ?>>Spieler nicht im Kader</option>
        </select>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="zimmer_nr">Zimmer Nr</label>
            <input type="text" id="zimmer_nr" name="zimmer_nr" value="<?= $v('zimmer_nr') ?>" maxlength="20">
        </div>
        <div class="form-group">
            <label for="pract_jersey_nr">Pract. Jersey Nr.</label>
            <input type="text" id="pract_jersey_nr" name="pract_jersey_nr" value="<?= $v('pract_jersey_nr') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label for="pract_hose_groesse">Pract. Hose Größe</label>
            <input type="text" id="pract_hose_groesse" name="pract_hose_groesse" value="<?= $v('pract_hose_groesse') ?>" maxlength="10">
        </div>
    </div>
</fieldset>
<?php endif; ?>

<?php echo field_access_apply_html((string) ob_get_clean(), $faAudience); ?>
