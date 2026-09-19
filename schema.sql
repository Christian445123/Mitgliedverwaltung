-- AFBÖ U19 Mitgliederverwaltung
-- Schema importieren z.B. via phpMyAdmin oder: mysql -u <user> -p mitglieddb < schema.sql

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('administrator', 'editor') NOT NULL DEFAULT 'administrator',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    role_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


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
    kader ENUM('kader', 'nicht_im_kader') NOT NULL DEFAULT 'kader',
    -- Wird von der Datenbank automatisch gepflegt: "Nachname Vorname" (z. B. "Walch Jakob")
    name_vorname VARCHAR(255) GENERATED ALWAYS AS (CONCAT(nachname, ' ', vorname)) STORED,

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
    nada_dokument_pfad VARCHAR(255) DEFAULT NULL,
    rechte_pflichten_dokument_pfad VARCHAR(255) DEFAULT NULL,
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
    zimmer_nr VARCHAR(20) DEFAULT NULL,
    pract_jersey_nr VARCHAR(10) DEFAULT NULL,
    pract_hose_groesse VARCHAR(10) DEFAULT NULL,
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

-- API-Zugänge für PC-Anwendungen (werden bei Bedarf auch automatisch angelegt)
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    can_write TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Protokoll (Audit- und Zugriffslog für Web-Anwendung und API)
CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source VARCHAR(10) NOT NULL,
    level VARCHAR(10) NOT NULL DEFAULT 'info',
    action VARCHAR(60) NOT NULL,
    actor VARCHAR(100) DEFAULT NULL,
    target_type VARCHAR(30) DEFAULT NULL,
    target_id VARCHAR(40) DEFAULT NULL,
    message VARCHAR(500) NOT NULL DEFAULT '',
    details TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    http_method VARCHAR(8) DEFAULT NULL,
    path VARCHAR(255) DEFAULT NULL,
    status_code SMALLINT UNSIGNED DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    KEY idx_created (created_at),
    KEY idx_source_created (source, created_at),
    KEY idx_level (level),
    KEY idx_actor (actor),
    KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Feld-Rechte: welche Felder Spieler (persönlicher Link) und Bearbeiter sehen/ändern dürfen
CREATE TABLE IF NOT EXISTS field_permissions (
    field_key VARCHAR(60) NOT NULL PRIMARY KEY,
    player_access ENUM('edit', 'view', 'hidden') NOT NULL DEFAULT 'edit',
    editor_visible TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weitere Camps (zusätzlich zu den vier festen Camps in member_camps)
CREATE TABLE IF NOT EXISTS camps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_camp_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS member_camp_entries (
    member_id INT UNSIGNED NOT NULL,
    camp_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (member_id, camp_id),
    CONSTRAINT fk_camp_entries_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_camp_entries_camp FOREIGN KEY (camp_id) REFERENCES camps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollen und Berechtigungen (Standardrollen "Administrator", "Bearbeiter", "Nur Lesen" legt die
-- Anwendung beim ersten Aufruf selbst an und ordnet bestehende Benutzer zu)
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(30) DEFAULT NULL,
    name VARCHAR(60) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_role_key (role_key),
    UNIQUE KEY uniq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission VARCHAR(60) NOT NULL,
    PRIMARY KEY (role_id, permission),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(60) NOT NULL,
    allowed TINYINT(1) NOT NULL,
    PRIMARY KEY (user_id, permission),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff (Trainer, Betreuer, Funktionäre) - getrennt von den Spielern
CREATE TABLE IF NOT EXISTS staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nachname VARCHAR(100) NOT NULL,
    vorname VARCHAR(100) NOT NULL,
    name_vorname VARCHAR(255) GENERATED ALWAYS AS (CONCAT(nachname, ' ', vorname)) STORED,
    position VARCHAR(100) DEFAULT NULL,
    nada VARCHAR(100) DEFAULT NULL,
    nada_gueltig_bis DATE DEFAULT NULL,
    geburtsdatum DATE DEFAULT NULL,
    telefon VARCHAR(50) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    telefon_angehoeriger VARCHAR(50) DEFAULT NULL,
    reisepass_nr VARCHAR(50) DEFAULT NULL,
    reisepass_ausgestellt_am DATE DEFAULT NULL,
    reisepass_gueltig_bis DATE DEFAULT NULL,
    geburtsland VARCHAR(100) DEFAULT NULL,
    ausstellungsbehoerde VARCHAR(150) DEFAULT NULL,
    plz VARCHAR(10) DEFAULT NULL,
    ort VARCHAR(100) DEFAULT NULL,
    strasse VARCHAR(150) DEFAULT NULL,
    essen VARCHAR(255) DEFAULT NULL,
    tshirt_polo_groesse VARCHAR(10) DEFAULT NULL,
    hoodie_groesse VARCHAR(10) DEFAULT NULL,
    jacken_groesse VARCHAR(10) DEFAULT NULL,
    short_groesse VARCHAR(10) DEFAULT NULL,
    shorts_anzahl VARCHAR(20) DEFAULT NULL,
    coaching_hosen_lang_groesse VARCHAR(10) DEFAULT NULL,
    status ENUM('aktiv', 'inaktiv') NOT NULL DEFAULT 'aktiv',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
