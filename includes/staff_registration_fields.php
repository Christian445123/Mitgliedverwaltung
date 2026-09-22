<?php

declare(strict_types=1);

/**
 * Formular-Fragment für die öffentliche Staff-Anmeldung (registrieren.php, Staff-Einladungslink).
 * Bewusst schlanker als das Spieler-Formular (includes/member_fields.php) - Nachname, Vorname, Telefon
 * und Mail sind immer Pflicht, alle anderen Felder je nach Einstellung unter "Neue Mitglieder" →
 * Pflichtfelder (siehe includes/registration_fields.php, dort auch neue Felder ergänzen).
 *
 * Erwartet im Scope:
 *   array $m             POST-Daten bei einem Fehler (sonst leer)
 *   array $requiredKeys  aktuell als Pflicht eingestellte Schlüssel (registration_required_keys('staff'))
 */

$m = isset($m) && is_array($m) ? $m : [];
$requiredKeys = $requiredKeys ?? [];

$v = static fn (string $key) => h((string) ($m[$key] ?? ''));
$reqOf = static fn (string $key): string => in_array($key, $requiredKeys, true) ? ' required' : '';
$reqMark = static fn (string $key): string => $reqOf($key) !== '' ? ' *' : '';
?>
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
        <div class="form-group">
            <label for="position">Position (z. B. HC, OC, DC, TM)<?= $reqMark('position') ?></label>
            <input type="text" id="position" name="position" value="<?= $v('position') ?>" maxlength="100"<?= $reqOf('position') ?>>
        </div>
        <div class="form-group">
            <label for="geburtsdatum">Geburtsdatum<?= $reqMark('geburtsdatum') ?></label>
            <input type="date" id="geburtsdatum" name="geburtsdatum" value="<?= $v('geburtsdatum') ?>"<?= $reqOf('geburtsdatum') ?>>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Kontakt</legend>
    <div class="form-row">
        <div class="form-group">
            <label for="telefon">Telefon *</label>
            <input type="tel" id="telefon" name="telefon" value="<?= $v('telefon') ?>" required maxlength="50">
        </div>
        <div class="form-group">
            <label for="email">Mail *</label>
            <input type="email" id="email" name="email" value="<?= $v('email') ?>" required maxlength="190">
        </div>
    </div>
    <div class="form-group">
        <label for="telefon_angehoeriger">Telefonnummer Angehörige<?= $reqMark('telefon_angehoeriger') ?></label>
        <input type="tel" id="telefon_angehoeriger" name="telefon_angehoeriger" value="<?= $v('telefon_angehoeriger') ?>" maxlength="50"<?= $reqOf('telefon_angehoeriger') ?>>
    </div>
</fieldset>

<fieldset>
    <legend>Sozialversicherung &amp; E-Card</legend>
    <div class="form-group">
        <label for="sozialversicherungsnummer">Sozial Ver. Nr.<?= $reqMark('sozialversicherungsnummer') ?></label>
        <input type="text" id="sozialversicherungsnummer" name="sozialversicherungsnummer" value="<?= $v('sozialversicherungsnummer') ?>" maxlength="20"<?= $reqOf('sozialversicherungsnummer') ?>>
    </div>
    <div class="form-group">
        <label for="ecard_dokument">E-Card (Foto)<?= $reqMark('ecard_foto_pfad') ?></label>
        <input type="file" id="ecard_dokument" name="ecard_dokument" accept=".jpg,.jpeg,.png,.pdf"<?= $reqOf('ecard_foto_pfad') ?>>
    </div>
</fieldset>

<fieldset>
    <legend>Reisepass</legend>
    <div class="form-group">
        <label for="pass_dokument">Reisepass (Foto)<?= $reqMark('pass_foto_pfad') ?></label>
        <input type="file" id="pass_dokument" name="pass_dokument" accept=".jpg,.jpeg,.png,.pdf"<?= $reqOf('pass_foto_pfad') ?>>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="reisepass_nr">Reisepass Nr<?= $reqMark('reisepass_nr') ?></label>
            <input type="text" id="reisepass_nr" name="reisepass_nr" value="<?= $v('reisepass_nr') ?>" maxlength="50"<?= $reqOf('reisepass_nr') ?>>
        </div>
        <div class="form-group">
            <label for="reisepass_ausgestellt_am">ausgestellt am<?= $reqMark('reisepass_ausgestellt_am') ?></label>
            <input type="date" id="reisepass_ausgestellt_am" name="reisepass_ausgestellt_am" value="<?= $v('reisepass_ausgestellt_am') ?>"<?= $reqOf('reisepass_ausgestellt_am') ?>>
        </div>
        <div class="form-group">
            <label for="reisepass_gueltig_bis">gültig bis<?= $reqMark('reisepass_gueltig_bis') ?></label>
            <input type="date" id="reisepass_gueltig_bis" name="reisepass_gueltig_bis" value="<?= $v('reisepass_gueltig_bis') ?>"<?= $reqOf('reisepass_gueltig_bis') ?>>
        </div>
    </div>
    <div class="form-group">
        <label for="geburtsland">Geburtsland<?= $reqMark('geburtsland') ?></label>
        <input type="text" id="geburtsland" name="geburtsland" value="<?= $v('geburtsland') ?>" maxlength="100"<?= $reqOf('geburtsland') ?>>
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
    <legend>Kontodaten</legend>
    <div class="form-group">
        <label for="kontoinhaber">Kontoinhaber<?= $reqMark('kontoinhaber') ?></label>
        <input type="text" id="kontoinhaber" name="kontoinhaber" value="<?= $v('kontoinhaber') ?>" maxlength="150"<?= $reqOf('kontoinhaber') ?>>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="iban">IBAN<?= $reqMark('iban') ?></label>
            <input type="text" id="iban" name="iban" value="<?= $v('iban') ?>" maxlength="42"<?= $reqOf('iban') ?>>
        </div>
        <div class="form-group">
            <label for="bic">BIC<?= $reqMark('bic') ?></label>
            <input type="text" id="bic" name="bic" value="<?= $v('bic') ?>" maxlength="15"<?= $reqOf('bic') ?>>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Essen &amp; Ausrüstung</legend>
    <div class="form-group">
        <label for="essen">Essen (Allergien / besondere Wünsche)<?= $reqMark('essen') ?></label>
        <textarea id="essen" name="essen" maxlength="255" rows="2"<?= $reqOf('essen') ?>><?= $v('essen') ?></textarea>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="tshirt_polo_groesse">T-Shirt / Polo Größe<?= $reqMark('tshirt_polo_groesse') ?></label>
            <input type="text" id="tshirt_polo_groesse" name="tshirt_polo_groesse" value="<?= $v('tshirt_polo_groesse') ?>" maxlength="10"<?= $reqOf('tshirt_polo_groesse') ?>>
        </div>
        <div class="form-group">
            <label for="hoodie_groesse">Hoodie Größe<?= $reqMark('hoodie_groesse') ?></label>
            <input type="text" id="hoodie_groesse" name="hoodie_groesse" value="<?= $v('hoodie_groesse') ?>" maxlength="10"<?= $reqOf('hoodie_groesse') ?>>
        </div>
        <div class="form-group">
            <label for="jacken_groesse">Jacken Größe<?= $reqMark('jacken_groesse') ?></label>
            <input type="text" id="jacken_groesse" name="jacken_groesse" value="<?= $v('jacken_groesse') ?>" maxlength="10"<?= $reqOf('jacken_groesse') ?>>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="short_groesse">Short Größe<?= $reqMark('short_groesse') ?></label>
            <input type="text" id="short_groesse" name="short_groesse" value="<?= $v('short_groesse') ?>" maxlength="10"<?= $reqOf('short_groesse') ?>>
        </div>
        <div class="form-group">
            <label for="shorts_anzahl">Wie viele Shorts besitzt du?<?= $reqMark('shorts_anzahl') ?></label>
            <input type="text" id="shorts_anzahl" name="shorts_anzahl" value="<?= $v('shorts_anzahl') ?>" maxlength="20"<?= $reqOf('shorts_anzahl') ?>>
        </div>
        <div class="form-group">
            <label for="coaching_hosen_lang_groesse">Coaching Hosen (lang) Größe<?= $reqMark('coaching_hosen_lang_groesse') ?></label>
            <input type="text" id="coaching_hosen_lang_groesse" name="coaching_hosen_lang_groesse" value="<?= $v('coaching_hosen_lang_groesse') ?>" maxlength="10"<?= $reqOf('coaching_hosen_lang_groesse') ?>>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Rechte &amp; Pflichten (freiwillig)</legend>
    <?php if (function_exists('rechte_template_exists') && rechte_template_exists()): ?>
    <p class="muted">
        <a href="<?= h(rechte_template_url()) ?>" target="_blank" rel="noopener">Vorlage „Rechte &amp; Pflichten“ herunterladen (PDF)</a>
        – bitte ausdrucken, unterschreiben und unten wieder hochladen.
    </p>
    <?php endif; ?>
    <div class="form-group">
        <label for="rechte_dokument">Unterschriebenes Dokument Rechte &amp; Pflichten<?= $reqMark('rechte_dokument_pfad') ?></label>
        <input type="file" id="rechte_dokument" name="rechte_dokument" accept=".jpg,.jpeg,.png,.pdf"<?= $reqOf('rechte_dokument_pfad') ?>>
    </div>
</fieldset>
