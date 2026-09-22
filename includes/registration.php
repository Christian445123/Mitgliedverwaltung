<?php

declare(strict_types=1);

/**
 * Selbstregistrierung neuer Mitglieder über einen generierten Link (siehe registrieren.php und
 * admin/registrations.php). Neue Anmeldungen landen mit members.status = 'neu' und erscheinen NICHT
 * in der normalen Mitgliederliste (siehe member_where() in member_repository.php), bis sie manuell
 * dem Kader oder "nicht im Kader" zugewiesen (= freigegeben) werden.
 *
 * Abgleich: Meldet sich jemand mit einer bereits vorhandenen E-Mail-Adresse an, wird kein zweiter
 * Datensatz angelegt. Stattdessen bekommt das bestehende Mitglied einen neuen Zugangslink zum
 * Aktualisieren seiner Daten (wie beim Datenprüfungs-Versand), und der Systemadministrator wird
 * per E-Mail benachrichtigt (sofern eine Benachrichtigungs-Adresse hinterlegt ist).
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/member_repository.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/verification.php';

// ── Registrierungslinks ──────────────────────────────────────────────

function registration_ensure_tables(?PDO $pdo = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ($pdo ?? db())->exec(
        'CREATE TABLE IF NOT EXISTS registration_links (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL,
            label VARCHAR(150) DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            use_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_registration_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** @return array<int, array<string, mixed>> */
function registration_links_all(): array
{
    registration_ensure_tables();
    return db()->query('SELECT * FROM registration_links ORDER BY active DESC, created_at DESC')->fetchAll();
}

/** @return array{id: int, token: string} */
function registration_link_create(string $label, ?string $createdBy, ?string $expiresAt = null): array
{
    registration_ensure_tables();
    $token = random_token(32);
    $stmt = db()->prepare('INSERT INTO registration_links (token, label, created_by, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$token, $label !== '' ? $label : null, $createdBy, $expiresAt !== '' ? $expiresAt : null]);
    $id = (int) db()->lastInsertId();
    app_log('registration.link_create', 'Registrierungslink erzeugt' . ($label !== '' ? " ({$label})" : ''), ['target_type' => 'registration_link', 'target_id' => $id]);
    return ['id' => $id, 'token' => $token];
}

function registration_link_set_active(int $id, bool $active): void
{
    registration_ensure_tables();
    db()->prepare('UPDATE registration_links SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    app_log('registration.link_toggle', 'Registrierungslink ' . ($active ? 'aktiviert' : 'deaktiviert'), ['target_type' => 'registration_link', 'target_id' => $id]);
}

function registration_link_delete(int $id): void
{
    registration_ensure_tables();
    db()->prepare('DELETE FROM registration_links WHERE id = ?')->execute([$id]);
    app_log('registration.link_delete', 'Registrierungslink gelöscht', ['target_type' => 'registration_link', 'target_id' => $id]);
}

/** @return array<string, mixed>|false */
function registration_find_by_token(string $token)
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    registration_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM registration_links WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row === false || (int) $row['active'] !== 1) {
        return false;
    }
    if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
        return false;
    }
    return $row;
}

function registration_build_url(string $token): string
{
    return APP_BASE_URL . '/registrieren.php?token=' . $token;
}

function registration_record_use(int $id): void
{
    registration_ensure_tables();
    db()->prepare('UPDATE registration_links SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?')->execute([$id]);
}

// ── Ausstehende Anmeldungen (Bereich "Neue Mitglieder") ──────────────

/** @return array<int, array<string, mixed>> */
function member_registrations_pending(): array
{
    $stmt = db()->query(MEMBER_JOIN_SQL . " WHERE m.status = 'neu' ORDER BY m.created_at DESC");
    return member_attach_camps($stmt->fetchAll());
}

function member_registrations_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'neu'")->fetchColumn();
}

/** Übernimmt eine Anmeldung: weist Kader/"nicht im Kader" zu und setzt den Status auf aktiv. */
function member_registration_approve(int $id, string $kader): bool
{
    $kader = $kader === 'nicht_im_kader' ? 'nicht_im_kader' : 'kader';
    $stmt = db()->prepare("UPDATE members SET status = 'aktiv', kader = ? WHERE id = ? AND status = 'neu'");
    $stmt->execute([$kader, $id]);
    $ok = $stmt->rowCount() > 0;
    if ($ok) {
        app_log('member.registration_approve', 'Neue Anmeldung übernommen (' . ($kader === 'kader' ? 'Kader' : 'nicht im Kader') . ')', ['target_type' => 'member', 'target_id' => $id]);
    }
    return $ok;
}

/** Lehnt eine Anmeldung ab (löscht den Datensatz samt Dokumenten). */
function member_registration_reject(int $id): bool
{
    $stmt = db()->prepare("SELECT id FROM members WHERE id = ? AND status = 'neu'");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === false) {
        return false;
    }
    app_log('member.registration_reject', 'Neue Anmeldung abgelehnt und gelöscht', ['target_type' => 'member', 'target_id' => $id], 'warning');
    member_delete($id);
    return true;
}

// ── Benachrichtigungs-E-Mail (Systemadministrator) ───────────────────

function registration_notify_email(): string
{
    return app_setting_get('registration_notify_email', '');
}

function registration_notify_email_set(string $email): void
{
    app_setting_set('registration_notify_email', trim($email));
}

/** Sendet eine formlose Benachrichtigung an den Systemadministrator (Fehler landen nur im Log, nie beim Nutzer). */
function registration_notify_admin(string $subject, string $html): void
{
    $to = registration_notify_email();
    if ($to === '' || !is_valid_email($to)) {
        return;
    }
    try {
        (new Mailer())->send($to, 'Systemadministrator', $subject, $html);
    } catch (Throwable $e) {
        error_log('registration_notify_admin: ' . $e->getMessage());
    }
}

// ── Abgleich bei doppelter E-Mail-Adresse ────────────────────────────

/**
 * Ein Registrierungsversuch trifft auf ein bereits bestehendes Mitglied: das bestehende Mitglied
 * bekommt per E-Mail einen neuen Zugangslink zum Prüfen/Aktualisieren seiner Daten, und der
 * Systemadministrator wird informiert (falls eine Benachrichtigungs-Adresse hinterlegt ist).
 *
 * @param array<string, mixed> $existing
 */
function registration_handle_duplicate(array $existing): void
{
    $existingId = (int) $existing['id'];
    $name = member_full_name($existing);

    $mailResult = verif_send_link('members', $existingId); // regeneriert Link+Code, sendet an das Mitglied

    app_log('member.registration_duplicate', 'Registrierungsversuch mit bereits vorhandener E-Mail-Adresse (' . $name . ')', [
        'target_type' => 'member',
        'target_id' => $existingId,
        'mail_status' => $mailResult['status'],
    ], 'warning');

    $html = '<p>Es gab einen Registrierungsversuch über den öffentlichen Anmeldelink mit einer E-Mail-Adresse, die bereits einem Mitglied zugeordnet ist:</p>'
        . '<p><strong>' . h($name) . '</strong> (' . h((string) $existing['email']) . ')</p>'
        . '<p>' . ($mailResult['status'] === 'sent'
            ? 'Das Mitglied wurde automatisch per E-Mail gebeten, seine Daten zu prüfen und zu aktualisieren.'
            : 'Es konnte keine E-Mail an das Mitglied gesendet werden (' . h($mailResult['message']) . ').') . '</p>'
        . '<p>Bei Bedarf im Webpanel prüfen: <a href="' . h(APP_BASE_URL . '/admin/member-form.php?id=' . $existingId) . '">Mitglied ansehen</a></p>';

    registration_notify_admin('Registrierungsversuch mit bereits vorhandener E-Mail – AFBÖ U19', $html);
}

/** Benachrichtigt den Systemadministrator über eine neue, noch zu prüfende Anmeldung. @param array<string, mixed> $member */
function registration_notify_new(array $member): void
{
    $html = '<p>Es ist eine neue Anmeldung über den öffentlichen Registrierungslink eingegangen:</p>'
        . '<p><strong>' . h(member_full_name($member)) . '</strong> (' . h((string) ($member['email'] ?? '')) . ')</p>'
        . '<p>Bitte im Webpanel prüfen und dem Kader oder "nicht im Kader" zuweisen: '
        . '<a href="' . h(APP_BASE_URL . '/admin/registrations.php') . '">Neue Mitglieder</a></p>';
    registration_notify_admin('Neue Anmeldung wartet auf Freigabe – AFBÖ U19', $html);
}

// ── Vorlage "Rechte & Pflichten" (PDF zum Herunterladen/Unterschreiben) ──

/** @param array<string, mixed> $file $_FILES-Eintrag @throws RuntimeException */
function registration_save_rechte_template(array $file): void
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Bitte eine PDF-Datei auswählen.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fehler beim Datei-Upload (Code ' . $file['error'] . ').');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
        throw new RuntimeException('Bitte eine PDF-Datei hochladen.');
    }

    $target = rechte_template_path();
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Zielverzeichnis konnte nicht angelegt werden.');
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    }
    @chmod($target, 0644);
    app_log('registration.template_upload', 'Vorlage „Rechte & Pflichten“ hochgeladen (PDF)');
}

function registration_delete_rechte_template(): void
{
    if (is_file(rechte_template_path())) {
        @unlink(rechte_template_path());
        app_log('registration.template_delete', 'Vorlage „Rechte & Pflichten“ entfernt');
    }
}
