<?php

declare(strict_types=1);

/**
 * Lizenzsystem für die Desktop-Anwendung (C#).
 *
 * - Lizenzschlüssel werden im Admin-Bereich erzeugt (Tabelle "licenses") und in der C#-Anwendung hinterlegt.
 * - Die Anwendung fragt regelmäßig über die API (POST /api/license/validate), ob der Schlüssel noch gilt.
 * - Jeder Schlüssel ist an eine begrenzte Zahl Geräte gebunden (Tabelle "license_activations").
 * - Bei erfolgreicher Prüfung erhält die Anwendung eine signierte Offline-Freigabe ("Lease"), die höchstens
 *   3 Tage gilt. Bricht die Verbindung ab, läuft die Anwendung damit weiter. Die Signatur (RSA/SHA-256) verhindert,
 *   dass die Freigabe lokal verlängert wird. Das Schlüsselpaar wird beim ersten Bedarf erzeugt (Tabelle app_settings).
 *
 * .env (optional): LICENSE_OFFLINE_HOURS (Standard 72, höchstens 72)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/settings.php';

const LICENSE_KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O, 1/I
const LICENSE_MAX_OFFLINE_HOURS = 72;

function license_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS licenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_key VARCHAR(40) NOT NULL,
            name VARCHAR(150) NOT NULL,
            status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
            expires_at DATE DEFAULT NULL,
            max_devices SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_license_key (license_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    db()->exec(
        'CREATE TABLE IF NOT EXISTS license_activations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_id INT UNSIGNED NOT NULL,
            machine_id VARCHAR(64) NOT NULL,
            machine_name VARCHAR(150) DEFAULT NULL,
            app_version VARCHAR(20) DEFAULT NULL,
            last_ip VARCHAR(45) DEFAULT NULL,
            first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_license_machine (license_id, machine_id),
            CONSTRAINT fk_license_activations_license FOREIGN KEY (license_id) REFERENCES licenses (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** Neuer Schlüssel im Format U19-XXXX-XXXX-XXXX-XXXX. */
function license_generate_key(): string
{
    $max = strlen(LICENSE_KEY_ALPHABET) - 1;
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= LICENSE_KEY_ALPHABET[random_int(0, $max)];
        }
        $groups[] = $group;
    }
    return 'U19-' . implode('-', $groups);
}

/** Vereinheitlicht die Eingabe (Groß-/Kleinschreibung, Leerzeichen). */
function license_normalize_key(string $key): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($key)) ?? '');
}

/** Gültigkeit der Offline-Freigabe in Sekunden (höchstens 3 Tage). */
function license_offline_seconds(): int
{
    $hours = (int) (getenv('LICENSE_OFFLINE_HOURS') ?: LICENSE_MAX_OFFLINE_HOURS);
    return max(1, min(LICENSE_MAX_OFFLINE_HOURS, $hours)) * 3600;
}

/**
 * @throws RuntimeException bei ungültigen Angaben
 */
function license_create(string $name, ?string $expiresAt, int $maxDevices): int
{
    license_ensure_tables();
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Bitte einen Namen bzw. Verwendungszweck angeben (z. B. „Büro Wien“).');
    }
    if (mb_strlen($name) > 150) {
        throw new RuntimeException('Der Name ist zu lang (höchstens 150 Zeichen).');
    }
    $expires = null;
    if ($expiresAt !== null && trim($expiresAt) !== '') {
        $date = DateTime::createFromFormat('Y-m-d', trim($expiresAt));
        if ($date === false) {
            throw new RuntimeException('Ungültiges Ablaufdatum.');
        }
        $expires = $date->format('Y-m-d');
    }
    $maxDevices = max(1, min(50, $maxDevices));

    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $stmt = db()->prepare('INSERT INTO licenses (license_key, name, expires_at, max_devices) VALUES (?, ?, ?, ?)');
            $stmt->execute([license_generate_key(), $name, $expires, $maxDevices]);
            return (int) db()->lastInsertId();
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e; // nur bei doppeltem Schlüssel (praktisch ausgeschlossen) erneut versuchen
            }
        }
    }
    throw new RuntimeException('Es konnte kein eindeutiger Schlüssel erzeugt werden.');
}

/** @return array<int, array<string, mixed>> */
function license_all(): array
{
    license_ensure_tables();
    return db()->query(
        'SELECT l.*, COUNT(a.id) AS devices, MAX(a.last_seen) AS last_seen
         FROM licenses l LEFT JOIN license_activations a ON a.license_id = l.id
         GROUP BY l.id ORDER BY l.created_at DESC, l.id DESC'
    )->fetchAll();
}

/** @return array<int, array<string, mixed>> */
function license_activations(int $licenseId): array
{
    license_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM license_activations WHERE license_id = ? ORDER BY last_seen DESC');
    $stmt->execute([$licenseId]);
    return $stmt->fetchAll();
}

function license_set_status(int $id, string $status): void
{
    license_ensure_tables();
    $stmt = db()->prepare('UPDATE licenses SET status = ? WHERE id = ?');
    $stmt->execute([$status === 'revoked' ? 'revoked' : 'active', $id]);
}

function license_delete(int $id): void
{
    license_ensure_tables();
    $stmt = db()->prepare('DELETE FROM licenses WHERE id = ?');
    $stmt->execute([$id]);
}

/** Gibt ein Gerät frei (z. B. nach Rechnerwechsel), der Schlüssel kann danach auf einem neuen Gerät aktiviert werden. */
function license_release_device(int $activationId): void
{
    license_ensure_tables();
    $stmt = db()->prepare('DELETE FROM license_activations WHERE id = ?');
    $stmt->execute([$activationId]);
}

/**
 * Schlüsselpaar für die Signatur der Offline-Freigaben (wird beim ersten Bedarf erzeugt).
 *
 * @return array{private: string, public: string}
 * @throws RuntimeException wenn die PHP-Erweiterung "openssl" fehlt
 */
function license_keypair(): array
{
    $private = app_setting_get('license_private_key');
    $public = app_setting_get('license_public_key');
    if ($private !== '' && $public !== '') {
        return ['private' => $private, 'public' => $public];
    }

    if (!function_exists('openssl_pkey_new')) {
        throw new RuntimeException('Für das Lizenzsystem fehlt die PHP-Erweiterung "openssl" auf dem Server.');
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false) {
        throw new RuntimeException('Der Signatur-Schlüssel konnte nicht erzeugt werden (OpenSSL-Konfiguration prüfen): ' . (string) openssl_error_string());
    }
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    $public = (string) ($details['key'] ?? '');
    app_setting_set('license_private_key', (string) $private);
    app_setting_set('license_public_key', $public);
    return ['private' => (string) $private, 'public' => $public];
}

/** SHA-256 des Schlüssels (die Freigabe enthält nie den Klartext-Schlüssel). */
function license_key_hash(string $key): string
{
    return hash('sha256', $key);
}

/** Text, der signiert wird. Die C#-Anwendung baut ihn aus denselben Feldern nach. */
function license_lease_payload(string $keyHash, string $machineId, int $issuedAt, int $validUntil): string
{
    return 'U19L1|' . $keyHash . '|' . $machineId . '|' . $issuedAt . '|' . $validUntil;
}

function license_sign(string $payload): string
{
    $pair = license_keypair();
    if (!openssl_sign($payload, $signature, $pair['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Die Lizenz-Freigabe konnte nicht signiert werden.');
    }
    return base64_encode($signature);
}

/**
 * Prüft einen Schlüssel für ein Gerät und liefert die Antwort für die Anwendung.
 * Ein Gerät wird beim ersten Mal automatisch aktiviert, solange die erlaubte Geräteanzahl nicht erreicht ist.
 *
 * @return array<string, mixed> valid, reason, message [, license, lease, signature, public_key]
 */
function license_check(string $rawKey, string $machineId, string $machineName, string $appVersion, string $ip): array
{
    license_ensure_tables();
    $key = license_normalize_key($rawKey);
    $invalid = static fn (string $reason, string $message): array => ['valid' => false, 'reason' => $reason, 'message' => $message];

    if (!preg_match('/^[a-f0-9]{16,64}$/', $machineId)) {
        return $invalid('bad_request', 'Ungültige Geräte-Kennung.');
    }
    if ($key === '' || strlen($key) > 40) {
        return $invalid('unknown_key', 'Der Lizenzschlüssel ist ungültig.');
    }

    $stmt = db()->prepare('SELECT * FROM licenses WHERE license_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $license = $stmt->fetch();
    if ($license === false) {
        return $invalid('unknown_key', 'Der Lizenzschlüssel ist ungültig.');
    }
    if ($license['status'] !== 'active') {
        return $invalid('revoked', 'Diese Lizenz wurde gesperrt. Bitte an den Administrator wenden.');
    }

    $now = time();
    $expiresAt = null;
    if (!empty($license['expires_at'])) {
        $expiresAt = strtotime($license['expires_at'] . ' 23:59:59');
        if ($expiresAt !== false && $expiresAt < $now) {
            return $invalid('expired', 'Die Lizenz ist am ' . date('d.m.Y', strtotime($license['expires_at'])) . ' abgelaufen.');
        }
    }

    // Gerät aktivieren bzw. aktualisieren
    $licenseId = (int) $license['id'];
    $machineName = mb_substr(trim($machineName), 0, 150);
    $appVersion = mb_substr(trim($appVersion), 0, 20);
    $find = db()->prepare('SELECT id FROM license_activations WHERE license_id = ? AND machine_id = ?');
    $find->execute([$licenseId, $machineId]);
    $activationId = $find->fetchColumn();

    if ($activationId === false) {
        $count = db()->prepare('SELECT COUNT(*) FROM license_activations WHERE license_id = ?');
        $count->execute([$licenseId]);
        if ((int) $count->fetchColumn() >= (int) $license['max_devices']) {
            return $invalid(
                'device_limit',
                'Diese Lizenz ist bereits auf ' . (int) $license['max_devices'] . ' Gerät(en) aktiviert. Der Administrator kann im Web-Bereich unter „Lizenzen“ ein Gerät freigeben.'
            );
        }
        $insert = db()->prepare('INSERT INTO license_activations (license_id, machine_id, machine_name, app_version, last_ip) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$licenseId, $machineId, $machineName, $appVersion, $ip]);
    } else {
        $update = db()->prepare('UPDATE license_activations SET machine_name = ?, app_version = ?, last_ip = ?, last_seen = NOW() WHERE id = ?');
        $update->execute([$machineName, $appVersion, $ip, (int) $activationId]);
    }

    // Signierte Offline-Freigabe: höchstens 3 Tage, nie über das Lizenz-Ablaufdatum hinaus
    $validUntil = $now + license_offline_seconds();
    if ($expiresAt !== null && $expiresAt !== false) {
        $validUntil = min($validUntil, $expiresAt);
    }
    $keyHash = license_key_hash($key);
    $payload = license_lease_payload($keyHash, $machineId, $now, $validUntil);

    return [
        'valid' => true,
        'reason' => 'ok',
        'message' => 'Lizenz gültig.',
        'license' => [
            'name' => (string) $license['name'],
            'expires_at' => $license['expires_at'],
            'max_devices' => (int) $license['max_devices'],
        ],
        'lease' => [
            'key_hash' => $keyHash,
            'machine_id' => $machineId,
            'issued_at' => $now,
            'valid_until' => $validUntil,
        ],
        'signature' => license_sign($payload),
        'public_key' => license_keypair()['public'],
        'server_time' => $now,
    ];
}
