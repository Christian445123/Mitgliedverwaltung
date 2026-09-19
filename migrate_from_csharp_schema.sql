-- Einmalige Migration: Datenbank der alten C#-Anwendung -> Schema der Web-Anwendung
--
-- Voraussetzung: Die alten Tabellen "admins" und "members" wurden zuvor in
-- "admins_backup_csharp" und "members_backup_csharp" umbenannt und das neue Schema
-- (schema.sql) ist angelegt. Die Backup-Tabellen bleiben unverändert erhalten.
-- IDs werden übernommen (Mitglieder behalten ihre Nummer).
-- Wiederholbar: bereits vorhandene Zeilen werden übersprungen (INSERT IGNORE).

INSERT IGNORE INTO admins (id, username, password_hash, role, must_change_password, created_at)
SELECT id, username, password_hash,
       IF(role = 'administrator', 'administrator', 'editor'),
       0, created_at
FROM admins_backup_csharp;

INSERT IGNORE INTO members (id, jersey_nr, nachname, vorname, sz, bezirk, position, geburtsdatum, verein,
                     groesse_cm, gewicht_kg, telefon, email, status, created_at, updated_at)
SELECT id, spielernummer, nachname, vorname, sz, bezirk, spielposition, geburtsdatum, herkunftsverein,
       koerpergroesse_cm, gewicht_kg, telefon,
       COALESCE(NULLIF(TRIM(email), ''), CONCAT('ohne-mail-', id, '@example.invalid')),
       status, created_at, updated_at
FROM members_backup_csharp;

INSERT IGNORE INTO member_camps (member_id, camp_1, spanien, camp_2, tschechien)
SELECT id,
       IF(camp_1 IS NULL OR LOWER(TRIM(camp_1)) IN ('', '0', 'nein'), 0, 1),
       IF(camp_spanien IS NULL OR LOWER(TRIM(camp_spanien)) IN ('', '0', 'nein'), 0, 1),
       IF(camp_2 IS NULL OR LOWER(TRIM(camp_2)) IN ('', '0', 'nein'), 0, 1),
       IF(camp_tschechien IS NULL OR LOWER(TRIM(camp_tschechien)) IN ('', '0', 'nein'), 0, 1)
FROM members_backup_csharp;

INSERT IGNORE INTO member_guardians (member_id, erz_name, erz_telefon, erz_email)
SELECT id, erziehungsberechtigter, erziehungsberechtigter_telefon, erziehungsberechtigter_email
FROM members_backup_csharp;

INSERT IGNORE INTO member_consents (member_id, rechte_pflichten_akzeptiert, rechte_pflichten_am)
SELECT id, IF(rechte_pflichten_akzeptiert_at IS NULL, 0, 1), rechte_pflichten_akzeptiert_at
FROM members_backup_csharp;

INSERT IGNORE INTO member_documents (member_id, sozialversicherungsnummer, nada_gueltig_bis, pass_foto_pfad,
                              reisepass_nr, reisepass_ausgestellt_am, reisepass_gueltig_bis,
                              geburtsland, geburtsort, ausstellungsbehoerde)
SELECT id, sozialversicherungsnummer, nada_zertifikat_gueltig_bis, pass_foto_pfad,
       COALESCE(reisepass_nr, passnummer), reisepass_ausgestellt_am, reisepass_gueltig_bis,
       geburtsland, geburtsort, reisepass_ausstellungsbehoerde
FROM members_backup_csharp;

INSERT IGNORE INTO member_addresses (member_id, plz, ort, strasse)
SELECT id, plz, ort, strasse
FROM members_backup_csharp;

INSERT IGNORE INTO member_equipment (member_id, essen, game_jersey_groesse, game_hosen_groesse, helm_groesse, helm_eigener,
                              tshirt_polo_groesse, hoodie_groesse, mesh_shorts_groesse, socken_groesse)
SELECT id, LEFT(COALESCE(essen, allergien), 255), jersey_groesse, hosen_groesse, helm_groesse,
       IF(helm_vorhanden = 'ja', 1, IF(helm_vorhanden = 'nein', 0, NULL)),
       tshirt_polo_groesse, hoodie_groesse, mesh_shorts_groesse, socken_groesse
FROM members_backup_csharp;

INSERT IGNORE INTO member_access (member_id, verify_token, access_password_hash, verified_at, failed_verify_attempts, verify_locked_until)
SELECT id, COALESCE(NULLIF(verify_token, ''), SHA2(CONCAT(id, RAND(), NOW(6)), 256)),
       access_password_hash, verified_at, failed_verify_attempts, verify_locked_until
FROM members_backup_csharp;
