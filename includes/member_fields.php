<?php

declare(strict_types=1);

/**
 * Gemeinsames Formular-Fragment für Mitgliedsdaten.
 * Feldreihenfolge folgt exakt der Original-Tabelle (Spalte für Spalte).
 *
 * Erwartet im Scope:
 *   array $m              Mitgliedsdaten (Schlüssel = Spaltennamen, fehlende Werte werden als '' behandelt)
 *   bool  $emailLocked    true = E-Mail-Feld nur Anzeige (öffentliches Formular)
 *   bool  $showAdminFields true = zusätzliche Felder, die nur der Admin sieht/setzt (Status)
 */

if (!isset($m) || !is_array($m)) {
    $m = [];
}
$emailLocked = $emailLocked ?? false;
$showAdminFields = $showAdminFields ?? false;

$v = static fn (string $key) => h((string) ($m[$key] ?? ''));
$checked = static fn (string $key) => !empty($m[$key]) ? 'checked' : '';
?>
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
</fieldset>

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

    <div class="form-row">
        <div class="form-group form-group-small">
            <label for="sz">SZ</label>
            <input type="text" id="sz" name="sz" value="<?= $v('sz') ?>" maxlength="50">
        </div>
        <div class="form-group form-group-small">
            <label for="bezirk">Bez.</label>
            <input type="text" id="bezirk" name="bezirk" value="<?= $v('bezirk') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="position">Position</label>
            <input type="text" id="position" name="position" value="<?= $v('position') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="geburtsdatum">Geburtsdatum</label>
            <input type="date" id="geburtsdatum" name="geburtsdatum" value="<?= $v('geburtsdatum') ?>">
        </div>
    </div>

    <div class="form-group">
        <label for="verein">Verein</label>
        <input type="text" id="verein" name="verein" value="<?= $v('verein') ?>" maxlength="150">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="groesse_cm">Größe (cm)</label>
            <input type="number" id="groesse_cm" name="groesse_cm" value="<?= $v('groesse_cm') ?>" min="0" max="300">
        </div>
        <div class="form-group">
            <label for="gewicht_kg">Gewicht (KG)</label>
            <input type="number" id="gewicht_kg" name="gewicht_kg" value="<?= $v('gewicht_kg') ?>" min="0" max="400">
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Kontakt</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="telefon">Telefon</label>
            <input type="tel" id="telefon" name="telefon" value="<?= $v('telefon') ?>" maxlength="50">
        </div>
        <div class="form-group">
            <label for="email">Mail *</label>
            <?php if ($emailLocked): ?>
                <input type="email" value="<?= $v('email') ?>" disabled>
                <input type="hidden" name="email" value="<?= $v('email') ?>">
            <?php else: ?>
                <input type="email" id="email" name="email" value="<?= $v('email') ?>" required maxlength="190">
            <?php endif; ?>
        </div>
    </div>
    <?php if ($emailLocked): ?>
        <p style="font-size:0.82rem;color:var(--color-muted);margin:4px 0 0;">Diese Adresse ist über deinen Link festgelegt und kann hier nicht geändert werden.</p>
    <?php endif; ?>
</fieldset>

<fieldset>
    <legend>Erziehungsberechtigte (falls minderjährig)</legend>

    <div class="form-group">
        <label for="erz_name">Name Erziehungsberechtigter</label>
        <input type="text" id="erz_name" name="erz_name" value="<?= $v('erz_name') ?>" maxlength="150">
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="erz_telefon">Telefon Erzieh</label>
            <input type="tel" id="erz_telefon" name="erz_telefon" value="<?= $v('erz_telefon') ?>" maxlength="50">
        </div>
        <div class="form-group">
            <label for="erz_email">Mail Erzieh</label>
            <input type="email" id="erz_email" name="erz_email" value="<?= $v('erz_email') ?>" maxlength="190">
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Rechte &amp; Pflicht</legend>
    <div class="form-group">
        <label>
            <input type="checkbox" name="rechte_pflichten_akzeptiert" value="1" <?= $checked('rechte_pflichten_akzeptiert') ?> <?= $showAdminFields ? '' : 'required' ?>>
            Ich akzeptiere die Rechte und Pflichten des Vereins *
        </label>
    </div>
</fieldset>

<fieldset>
    <legend>Bild E-Card &amp; Sozialversicherung</legend>

    <div class="form-group">
        <label for="bild_ecard">Bild E-Card <?= !empty($m['bild_ecard_pfad']) ? '(vorhanden – neu hochladen zum Ersetzen)' : '' ?></label>
        <input type="file" id="bild_ecard" name="bild_ecard" accept=".jpg,.jpeg,.png,.pdf">
    </div>
    <div class="form-group">
        <label for="sozialversicherungsnummer">Sozial Ver. Nr.</label>
        <input type="text" id="sozialversicherungsnummer" name="sozialversicherungsnummer" value="<?= $v('sozialversicherungsnummer') ?>" maxlength="20">
    </div>
</fieldset>

<fieldset>
    <legend>NADA (Anti-Doping)</legend>

    <div class="form-row">
        <div class="form-group">
            <label for="nada_zertifikat">Nada Zertifikat</label>
            <input type="text" id="nada_zertifikat" name="nada_zertifikat" value="<?= $v('nada_zertifikat') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="nada_gueltig_bis">Nada gültig bis</label>
            <input type="date" id="nada_gueltig_bis" name="nada_gueltig_bis" value="<?= $v('nada_gueltig_bis') ?>">
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Reisepass</legend>

    <div class="form-group">
        <label for="pass_foto">Pass Foto <?= !empty($m['pass_foto_pfad']) ? '(vorhanden – neu hochladen zum Ersetzen)' : '' ?></label>
        <input type="file" id="pass_foto" name="pass_foto" accept=".jpg,.jpeg,.png,.pdf">
    </div>

    <div class="form-group">
        <label for="reisepass_nr">Reisepass Nr</label>
        <input type="text" id="reisepass_nr" name="reisepass_nr" value="<?= $v('reisepass_nr') ?>" maxlength="50">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="reisepass_ausgestellt_am">Reisepass ausgestellt am</label>
            <input type="date" id="reisepass_ausgestellt_am" name="reisepass_ausgestellt_am" value="<?= $v('reisepass_ausgestellt_am') ?>">
        </div>
        <div class="form-group">
            <label for="reisepass_gueltig_bis">Reisepass gültig bis</label>
            <input type="date" id="reisepass_gueltig_bis" name="reisepass_gueltig_bis" value="<?= $v('reisepass_gueltig_bis') ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="geburtsland">Geburtsland</label>
            <input type="text" id="geburtsland" name="geburtsland" value="<?= $v('geburtsland') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="geburtsort">Geburtsort</label>
            <input type="text" id="geburtsort" name="geburtsort" value="<?= $v('geburtsort') ?>" maxlength="100">
        </div>
    </div>

    <div class="form-group">
        <label for="ausstellungsbehoerde">Ausstellungsbehörde</label>
        <input type="text" id="ausstellungsbehoerde" name="ausstellungsbehoerde" value="<?= $v('ausstellungsbehoerde') ?>" maxlength="150">
    </div>
</fieldset>

<fieldset>
    <legend>Adresse</legend>

    <div class="form-row">
        <div class="form-group form-group-small">
            <label for="plz">PLZ</label>
            <input type="text" id="plz" name="plz" value="<?= $v('plz') ?>" maxlength="10">
        </div>
        <div class="form-group">
            <label for="ort">Ort</label>
            <input type="text" id="ort" name="ort" value="<?= $v('ort') ?>" maxlength="100">
        </div>
        <div class="form-group">
            <label for="strasse">Straße</label>
            <input type="text" id="strasse" name="strasse" value="<?= $v('strasse') ?>" maxlength="150">
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Essen</legend>
    <div class="form-group">
        <label for="essen">Essen (Allergien / besondere Wünsche)</label>
        <textarea id="essen" name="essen" maxlength="255" rows="2"><?= $v('essen') ?></textarea>
    </div>
</fieldset>

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

<?php if ($showAdminFields): ?>
<fieldset>
    <legend>Verwaltung (nur Admin)</legend>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="aktiv" <?= ($m['status'] ?? 'aktiv') === 'aktiv' ? 'selected' : '' ?>>Aktiv</option>
            <option value="inaktiv" <?= ($m['status'] ?? '') === 'inaktiv' ? 'selected' : '' ?>>Inaktiv</option>
        </select>
    </div>
</fieldset>
<?php endif; ?>
