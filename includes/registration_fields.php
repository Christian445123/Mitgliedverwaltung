<?php

declare(strict_types=1);

/**
 * Pflichtfelder bei der Selbstanmeldung über einen Einladungslink (registrieren.php), getrennt für
 * Spieler und Staff. Nachname/Vorname/Telefon/Mail sind immer Pflicht (fest im Formular verankert,
 * hier nicht gelistet). Alle anderen Felder lassen sich unter "Neue Mitglieder" → Pflichtfelder
 * (admin/registration-fields.php) einzeln an- und abhaken, inklusive neu hinzugekommener Felder -
 * dafür hier einfach eine Zeile in der passenden Registry-Funktion ergänzen.
 *
 * Gespeichert wird nur die Liste der aktuell angehakten Schlüssel (JSON) in app_settings.
 */

require_once __DIR__ . '/settings.php';

/**
 * Spieler-Felder, die bei der Neuanmeldung als Pflicht markiert werden können: Schlüssel (wie
 * member_collect_input() sie liefert) => [Beschriftung, Formularfeld-Name (für Feld-Rechte-Sperren)].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function registration_player_field_registry(): array
{
    return [
        'sz' => ['Selbstzahler', 'sz'],
        'bezirk' => ['Bez.', 'bezirk'],
        'position' => ['Position', 'position'],
        'geburtsdatum' => ['Geburtsdatum', 'geburtsdatum'],
        'verein' => ['Verein', 'verein'],
        'groesse_cm' => ['Größe (cm)', 'groesse_cm'],
        'gewicht_kg' => ['Gewicht (KG)', 'gewicht_kg'],
        'sozialversicherungsnummer' => ['Sozial Ver. Nr.', 'sozialversicherungsnummer'],
        'bild_ecard_pfad' => ['E-Card (Dokument)', 'bild_ecard'],
        'nada_zertifikat' => ['Nada Zertifikat', 'nada_zertifikat'],
        'nada_gueltig_bis' => ['Nada gültig bis', 'nada_gueltig_bis'],
        'nada_dokument_pfad' => ['NADA-Zertifikat (Dokument)', 'nada_dokument'],
        'pass_foto_pfad' => ['Reisepass (Foto)', 'pass_foto'],
        'reisepass_nr' => ['Reisepass Nr', 'reisepass_nr'],
        'reisepass_ausgestellt_am' => ['Reisepass ausgestellt am', 'reisepass_ausgestellt_am'],
        'reisepass_gueltig_bis' => ['Reisepass gültig bis', 'reisepass_gueltig_bis'],
        'geburtsland' => ['Geburtsland', 'geburtsland'],
        'geburtsort' => ['Geburtsort', 'geburtsort'],
        'ausstellungsbehoerde' => ['Ausstellungsbehörde', 'ausstellungsbehoerde'],
        'plz' => ['PLZ', 'plz'],
        'ort' => ['Ort', 'ort'],
        'strasse' => ['Straße', 'strasse'],
        'rechte_pflichten_dokument_pfad' => ['Rechte & Pflichten (Dokument)', 'rechte_pflichten_dokument'],
        'essen' => ['Essen', 'essen'],
    ];
}

/**
 * Staff-Felder, die bei der Neuanmeldung als Pflicht markiert werden können.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function registration_staff_field_registry(): array
{
    return [
        'telefon_angehoeriger' => ['Telefonnummer Angehörige', 'telefon_angehoeriger'],
        'position' => ['Position', 'position'],
        'geburtsdatum' => ['Geburtsdatum', 'geburtsdatum'],
        'ecard_foto_pfad' => ['E-Card (Dokument)', 'ecard_dokument'],
        'pass_foto_pfad' => ['Reisepass (Foto, Dokument)', 'pass_dokument'],
        'reisepass_nr' => ['Reisepass Nr', 'reisepass_nr'],
        'reisepass_ausgestellt_am' => ['Reisepass ausgestellt am', 'reisepass_ausgestellt_am'],
        'reisepass_gueltig_bis' => ['Reisepass gültig bis', 'reisepass_gueltig_bis'],
        'geburtsland' => ['Geburtsland', 'geburtsland'],
        'ausstellungsbehoerde' => ['Ausstellungsbehörde', 'ausstellungsbehoerde'],
        'plz' => ['PLZ', 'plz'],
        'ort' => ['Ort', 'ort'],
        'strasse' => ['Straße', 'strasse'],
        'essen' => ['Essen', 'essen'],
        'tshirt_polo_groesse' => ['T-Shirt / Polo Größe', 'tshirt_polo_groesse'],
        'hoodie_groesse' => ['Hoodie Größe', 'hoodie_groesse'],
        'jacken_groesse' => ['Jacken Größe', 'jacken_groesse'],
        'short_groesse' => ['Short Größe', 'short_groesse'],
        'shorts_anzahl' => ['Wie viele Shorts besitzt du?', 'shorts_anzahl'],
        'coaching_hosen_lang_groesse' => ['Coaching Hosen (lang) Größe', 'coaching_hosen_lang_groesse'],
        'coaching_hosen_lang_anzahl' => ['Coaching Hosen (lang) besitzt du?', 'coaching_hosen_lang_anzahl'],
        'geburtsort' => ['Geburtsort', 'geburtsort'],
        'sozialversicherungsnummer' => ['SVNR', 'sozialversicherungsnummer'],
        'iban' => ['Bankverbindung (IBAN)', 'iban'],
        'rechte_dokument_pfad' => ['Rechte & Pflichten (Dokument)', 'rechte_dokument'],
    ];
}

/** @return array<string, array{0: string, 1: string}> */
function registration_field_registry(string $type): array
{
    return $type === 'staff' ? registration_staff_field_registry() : registration_player_field_registry();
}

/** @return array<string, string> Schlüssel => Beschriftung */
function registration_field_labels(string $type): array
{
    $labels = [];
    foreach (registration_field_registry($type) as $key => [$label, ]) {
        $labels[$key] = $label;
    }
    return $labels;
}

/** @return array<string, string> Schlüssel => Formularfeld-Name (für field_access_locked_inputs()) */
function registration_field_input_names(string $type): array
{
    $names = [];
    foreach (registration_field_registry($type) as $key => [, $input]) {
        $names[$key] = $input;
    }
    return $names;
}

/**
 * Voreinstellung, solange der Admin noch nichts eingestellt hat: bei Spielern ist alles Pflicht,
 * bei Staff nur Telefonnummer Angehörige sowie Reisepass- und E-Card-Dokument (zusätzlich zu
 * Nachname/Vorname/Telefon/Mail/DSGVO, die immer Pflicht sind).
 *
 * @return array<int, string>
 */
function registration_default_required_keys(string $type): array
{
    if ($type === 'staff') {
        return ['telefon_angehoeriger', 'pass_foto_pfad', 'ecard_foto_pfad'];
    }
    return array_keys(registration_player_field_registry());
}

/** @return array<int, string> */
function registration_required_keys(string $type): array
{
    $type = $type === 'staff' ? 'staff' : 'player';
    $registry = registration_field_registry($type);
    $raw = app_setting_get('registration_required_' . $type, '');
    if ($raw === '') {
        return registration_default_required_keys($type);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return registration_default_required_keys($type);
    }
    // Nur noch existierende Schlüssel behalten (falls ein Feld inzwischen aus der Registry entfernt wurde)
    return array_values(array_intersect($decoded, array_keys($registry)));
}

/** @param array<int, string> $keys */
function registration_required_keys_set(string $type, array $keys): void
{
    $type = $type === 'staff' ? 'staff' : 'player';
    $registry = registration_field_registry($type);
    $keys = array_values(array_intersect($keys, array_keys($registry)));
    app_setting_set('registration_required_' . $type, json_encode($keys, JSON_UNESCAPED_UNICODE));
    app_log('registration.required_fields_save', 'Pflichtfelder bei der Anmeldung geändert (' . ($type === 'staff' ? 'Staff' : 'Spieler') . ')', ['type' => $type, 'keys' => $keys]);
}
