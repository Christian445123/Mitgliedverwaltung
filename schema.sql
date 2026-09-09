-- AFBÖ U19 Mitgliederverwaltung
-- Schema importieren z.B. via phpMyAdmin oder: mysql -u <user> -p mitglieddb < schema.sql

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Ausrüstung / Nummer
    jersey_nr VARCHAR(10) DEFAULT NULL,
    camp_1 TINYINT(1) NOT NULL DEFAULT 0,
    spanien TINYINT(1) NOT NULL DEFAULT 0,
    camp_2 TINYINT(1) NOT NULL DEFAULT 0,
    tschechien TINYINT(1) NOT NULL DEFAULT 0,

    -- Persönliche Daten
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

    -- Erziehungsberechtigte
    erz_name VARCHAR(150) DEFAULT NULL,
    erz_telefon VARCHAR(50) DEFAULT NULL,
    erz_email VARCHAR(190) DEFAULT NULL,

    -- Zustimmung
    rechte_pflichten_akzeptiert TINYINT(1) NOT NULL DEFAULT 0,
    rechte_pflichten_am DATETIME DEFAULT NULL,

    -- Dokumente
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

    -- Adresse
    plz VARCHAR(10) DEFAULT NULL,
    ort VARCHAR(100) DEFAULT NULL,
    strasse VARCHAR(150) DEFAULT NULL,

    -- Sonstiges
    essen VARCHAR(255) DEFAULT NULL,

    -- Ausrüstungsgrößen
    game_jersey_groesse VARCHAR(10) DEFAULT NULL,
    game_hosen_groesse VARCHAR(10) DEFAULT NULL,
    helm_groesse VARCHAR(10) DEFAULT NULL,
    helm_eigener TINYINT(1) DEFAULT NULL,
    tshirt_polo_groesse VARCHAR(10) DEFAULT NULL,
    hoodie_groesse VARCHAR(10) DEFAULT NULL,
    mesh_shorts_groesse VARCHAR(10) DEFAULT NULL,
    socken_groesse VARCHAR(10) DEFAULT NULL,

    -- Admin-Verwaltung
    status ENUM('aktiv', 'inaktiv') NOT NULL DEFAULT 'aktiv',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS edit_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token_hash (token_hash),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
