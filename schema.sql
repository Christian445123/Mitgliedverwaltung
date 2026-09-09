-- AFBÖ U19 Mitgliederverwaltung
-- Schema importieren z.B. via phpMyAdmin oder: mysql -u <user> -p mitglieddb < schema.sql

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('administrator', 'editor') NOT NULL DEFAULT 'administrator',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotente Migration für bereits bestehende Installationen (falls die
-- Tabelle "admins" schon ohne die Spalte "role" existiert). Benötigt
-- MariaDB (unterstützt "ADD COLUMN IF NOT EXISTS"); auf reinem MySQL bei
-- Bedarf manuell prüfen/anpassen.
ALTER TABLE admins ADD COLUMN IF NOT EXISTS role ENUM('administrator', 'editor') NOT NULL DEFAULT 'administrator' AFTER password_hash;

-- Kern-Tabelle: Stammdaten + Felder, die für Liste/Suche im Admin-Dashboard
-- gebraucht werden. Alles andere ist in Teiltabellen ausgelagert (1:1 über
-- member_id), damit die Tabelle übersichtlich bleibt.
CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    jersey_nr VARCHAR(10) DEFAULT NULL,
    nachname VARCHAR(100) NOT NULL,
    vorname VARCHAR(100) NOT NULL,
    sz VARCHAR(50) DEFAULT NULL,
    bezirk VARCHAR(100) DEFAULT NULL,
    position VARCHAR(100) DEFAULT NULL,
    geburtsdatum DATE DEFAULT NULL,
    verein VARCHAR(150) DEFAULT NULL,
    groesse_cm SMALLINT UNSIGNED DEFAULT NULL,
    gewicht_kg SMALLINT UNSIGNED DEFAULT NULL,
    telefon VARCHAR(50) DEFAULT NULL,
    email VARCHAR(190) NOT NULL,

    status ENUM('aktiv', 'inaktiv') NOT NULL DEFAULT 'aktiv',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Camp-/Turnier-Teilnahmen
CREATE TABLE IF NOT EXISTS member_camps (
    member_id INT UNSIGNED PRIMARY KEY,
    camp_1 TINYINT(1) NOT NULL DEFAULT 0,
    spanien TINYINT(1) NOT NULL DEFAULT 0,
    camp_2 TINYINT(1) NOT NULL DEFAULT 0,
    tschechien TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_member_camps_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Erziehungsberechtigte (falls minderjährig)
CREATE TABLE IF NOT EXISTS member_guardians (
    member_id INT UNSIGNED PRIMARY KEY,
    erz_name VARCHAR(150) DEFAULT NULL,
    erz_telefon VARCHAR(50) DEFAULT NULL,
    erz_email VARCHAR(190) DEFAULT NULL,
    CONSTRAINT fk_member_guardians_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zustimmung Rechte & Pflichten
CREATE TABLE IF NOT EXISTS member_consents (
    member_id INT UNSIGNED PRIMARY KEY,
    rechte_pflichten_akzeptiert TINYINT(1) NOT NULL DEFAULT 0,
    rechte_pflichten_am DATETIME DEFAULT NULL,
    CONSTRAINT fk_member_consents_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dokumente: E-Card, Sozialversicherung, NADA, Reisepass
CREATE TABLE IF NOT EXISTS member_documents (
    member_id INT UNSIGNED PRIMARY KEY,
    bild_ecard_pfad VARCHAR(255) DEFAULT NULL,
    sozialversicherungsnummer VARCHAR(20) DEFAULT NULL,
    nada_zertifikat VARCHAR(100) DEFAULT NULL,
    nada_gueltig_bis DATE DEFAULT NULL,
    pass_foto_pfad VARCHAR(255) DEFAULT NULL,
    reisepass_nr VARCHAR(50) DEFAULT NULL,
    reisepass_ausgestellt_am DATE DEFAULT NULL,
    reisepass_gueltig_bis DATE DEFAULT NULL,
    geburtsland VARCHAR(100) DEFAULT NULL,
    geburtsort VARCHAR(100) DEFAULT NULL,
    ausstellungsbehoerde VARCHAR(150) DEFAULT NULL,
    CONSTRAINT fk_member_documents_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adresse
CREATE TABLE IF NOT EXISTS member_addresses (
    member_id INT UNSIGNED PRIMARY KEY,
    plz VARCHAR(10) DEFAULT NULL,
    ort VARCHAR(100) DEFAULT NULL,
    strasse VARCHAR(150) DEFAULT NULL,
    CONSTRAINT fk_member_addresses_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Essen & Ausrüstungsgrößen
CREATE TABLE IF NOT EXISTS member_equipment (
    member_id INT UNSIGNED PRIMARY KEY,
    essen VARCHAR(255) DEFAULT NULL,
    game_jersey_groesse VARCHAR(10) DEFAULT NULL,
    game_hosen_groesse VARCHAR(10) DEFAULT NULL,
    helm_groesse VARCHAR(10) DEFAULT NULL,
    helm_eigener TINYINT(1) DEFAULT NULL,
    tshirt_polo_groesse VARCHAR(10) DEFAULT NULL,
    hoodie_groesse VARCHAR(10) DEFAULT NULL,
    mesh_shorts_groesse VARCHAR(10) DEFAULT NULL,
    socken_groesse VARCHAR(10) DEFAULT NULL,
    CONSTRAINT fk_member_equipment_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Persönlicher Zugangslink (Link + Zugangscode), bis er neu generiert wird
CREATE TABLE IF NOT EXISTS member_access (
    member_id INT UNSIGNED PRIMARY KEY,
    verify_token VARCHAR(64) NOT NULL,
    access_password_hash VARCHAR(255) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    failed_verify_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    verify_locked_until DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_verify_token (verify_token),
    CONSTRAINT fk_member_access_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
