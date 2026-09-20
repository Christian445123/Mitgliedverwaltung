<?php

declare(strict_types=1);

/**
 * Datenschutz (DSGVO): Einwilligungen, Betroffenenanfragen (Auskunft/Löschung), Datenpannen, Löschfristen,
 * Verzeichnis der Verarbeitungstätigkeiten und Vorlage der Datenschutzerklärung.
 *
 * Wichtig: Die Texte sind eine fachlich vorbereitete VORLAGE. Sie müssen vom Verein (Verantwortlicher) geprüft, mit den
 * tatsächlichen Empfängern/Fristen angepasst und bei Bedarf rechtlich abgestimmt werden.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/member_repository.php';
require_once __DIR__ . '/staff.php';

/** Version der Datenschutzerklärung; bei inhaltlichen Änderungen erhöhen, dann müssen alle erneut zustimmen. */
function dsgvo_policy_version(): string
{
    return app_setting_get('dsgvo_policy_version', '2026-09-20');
}

function dsgvo_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS privacy_consents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity VARCHAR(10) NOT NULL,
            person_id INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT \'data\',
            policy_version VARCHAR(30) NOT NULL,
            guardian_name VARCHAR(150) DEFAULT NULL,
            accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_consent_person (entity, person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS privacy_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity VARCHAR(10) NOT NULL,
            person_id INT UNSIGNED NOT NULL,
            person_name VARCHAR(200) NOT NULL DEFAULT \'\',
            kind VARCHAR(20) NOT NULL,
            note TEXT DEFAULT NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'offen\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME DEFAULT NULL,
            done_by VARCHAR(100) DEFAULT NULL,
            KEY idx_request_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS data_breaches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            discovered_at DATETIME NOT NULL,
            description TEXT NOT NULL,
            data_categories VARCHAR(255) DEFAULT NULL,
            persons_affected VARCHAR(100) DEFAULT NULL,
            risk VARCHAR(12) NOT NULL DEFAULT \'gering\',
            measures TEXT DEFAULT NULL,
            authority_reported_at DATETIME DEFAULT NULL,
            persons_notified_at DATETIME DEFAULT NULL,
            created_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

// ── Einstellungen des Verantwortlichen ──────────────────────────────────

/** @return array<string, string> */
function dsgvo_settings(): array
{
    $defaults = [
        'org_name' => 'Verein / Verband (bitte eintragen)',
        'org_address' => '',
        'org_email' => '',
        'org_phone' => '',
        'dpo' => '',
        'hoster' => '',
        'mail_provider' => '',
        'recipients' => 'Österreichischer Verband für American Football (AFBÖ) sowie internationale Veranstalter (z. B. IFAF) für Anmeldungen zu Bewerben, Camps und Reisen; Fluglinien/Unterkünfte nur soweit für eine Reise erforderlich.',
        'retention_months' => '24',
        'breach_contact' => '',
    ];
    $out = [];
    foreach ($defaults as $key => $default) {
        $out[$key] = app_setting_get('dsgvo_' . $key, $default);
    }
    return $out;
}

/** @param array<string, string> $values */
function dsgvo_settings_save(array $values): void
{
    foreach (array_keys(dsgvo_settings()) as $key) {
        if (array_key_exists($key, $values)) {
            $value = trim((string) $values[$key]);
            if ($key === 'retention_months') {
                $value = (string) max(1, min(240, (int) $value));
            }
            app_setting_set('dsgvo_' . $key, $value);
        }
    }
}

// ── Personen ───────────────────────────────────────────────────────

function dsgvo_entity(string $entity): string
{
    return $entity === 'staff' ? 'staff' : 'members';
}

/** @return array<string, mixed>|false */
function dsgvo_person(string $entity, int $id)
{
    return dsgvo_entity($entity) === 'staff' ? staff_find_by_id($id) : member_find_by_id($id);
}

function dsgvo_person_name(array $person): string
{
    return trim((string) ($person['vorname'] ?? '') . ' ' . (string) ($person['nachname'] ?? ''));
}

/** Minderjährig (unter 18) oder – bei Spielern ohne Geburtsdatum – Erziehungsberechtigte hinterlegt. */
function dsgvo_is_minor(string $entity, array $person): bool
{
    if (dsgvo_entity($entity) === 'staff') {
        return false;
    }
    if (!empty($person['geburtsdatum'])) {
        try {
            return (new DateTimeImmutable((string) $person['geburtsdatum']))->diff(new DateTimeImmutable('today'))->y < 18;
        } catch (Throwable $e) {
            return false;
        }
    }
    return !empty($person['erz_name']);
}

// ── Einwilligungen ──────────────────────────────────────────────────

/** @return array<string, mixed>|null die aktuelle Einwilligung (gleiche Fassung der Datenschutzerklärung) */
function dsgvo_consent_current(string $entity, int $id): ?array
{
    dsgvo_ensure_tables();
    $stmt = db()->prepare('SELECT * FROM privacy_consents WHERE entity = ? AND person_id = ? AND policy_version = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([dsgvo_entity($entity), $id, dsgvo_policy_version()]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function dsgvo_consent_record(string $entity, int $id, ?string $guardianName): void
{
    dsgvo_ensure_tables();
    $stmt = db()->prepare('INSERT INTO privacy_consents (entity, person_id, kind, policy_version, guardian_name) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([dsgvo_entity($entity), $id, $guardianName !== null ? 'guardian' : 'data', dsgvo_policy_version(), $guardianName]);
    app_log('privacy.consent', 'Einwilligung erteilt (' . (dsgvo_entity($entity) === 'staff' ? 'Staff' : 'Spieler') . ')', [
        'actor' => (dsgvo_entity($entity) === 'staff' ? 'staff:' : 'member:') . $id,
        'target_type' => dsgvo_entity($entity) === 'staff' ? 'staff' : 'member',
        'target_id' => $id,
        'version' => dsgvo_policy_version(),
        'guardian' => $guardianName !== null,
    ]);
}

/** @return array<string, string> Personen-ID => Datum der aktuellen Einwilligung */
function dsgvo_consent_map(string $entity): array
{
    dsgvo_ensure_tables();
    $stmt = db()->prepare('SELECT person_id, MAX(accepted_at) FROM privacy_consents WHERE entity = ? AND policy_version = ? GROUP BY person_id');
    $stmt->execute([dsgvo_entity($entity), dsgvo_policy_version()]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$pid, $when]) {
        $map[(string) $pid] = (string) $when;
    }
    return $map;
}

/** Entfernt Einwilligungen und Anfragen gelöschter Personen (Datensparsamkeit). */
function dsgvo_cleanup_orphans(): void
{
    dsgvo_ensure_tables();
    db()->exec("DELETE c FROM privacy_consents c LEFT JOIN members m ON c.entity = 'members' AND m.id = c.person_id LEFT JOIN staff s ON c.entity = 'staff' AND s.id = c.person_id WHERE m.id IS NULL AND s.id IS NULL");
}

// ── Auskunft / Datenexport (Art. 15, 20) ──────────────────────────────

/**
 * Alle zu einer Person gespeicherten Daten in lesbarer Form.
 *
 * @return array<string, mixed>
 */
function dsgvo_export(string $entity, int $id): array
{
    $entity = dsgvo_entity($entity);
    $person = dsgvo_person($entity, $id);
    if ($person === false) {
        throw new RuntimeException('Person nicht gefunden.');
    }

    $fields = [];
    if ($entity === 'staff') {
        foreach (STAFF_IO_COLUMNS as $key => [$label]) {
            $fields[$label] = $person[$key] ?? null;
        }
        $documents = array_map(static fn ($v) => $v ? 'vorhanden' : 'nicht vorhanden', staff_documents_present($person));
    } else {
        require_once __DIR__ . '/member_columns.php';
        foreach (member_io_columns() as $key => [$label]) {
            if (str_starts_with((string) $key, 'camp:')) {
                continue;
            }
            $fields[$label] = $person[$key] ?? null;
        }
        $documents = array_map(static fn ($v) => $v ? 'vorhanden' : 'nicht vorhanden', member_documents_present($person));
    }
    foreach ($fields as $label => $value) {
        if ($value === '' || $value === null) {
            unset($fields[$label]);
        }
    }

    dsgvo_ensure_tables();
    $consents = db()->prepare('SELECT kind, policy_version, guardian_name, accepted_at FROM privacy_consents WHERE entity = ? AND person_id = ? ORDER BY id');
    $consents->execute([$entity, $id]);
    $requests = db()->prepare('SELECT kind, status, created_at, done_at FROM privacy_requests WHERE entity = ? AND person_id = ? ORDER BY id');
    $requests->execute([$entity, $id]);

    return [
        'erstellt_am' => date('Y-m-d H:i:s'),
        'verantwortlicher' => dsgvo_settings()['org_name'],
        'art' => $entity === 'staff' ? 'Staff (Trainer/Betreuer)' : 'Spieler',
        'daten' => $fields,
        'dokumente' => $documents,
        'einwilligungen' => $consents->fetchAll(),
        'anfragen' => $requests->fetchAll(),
        'hinweis' => 'Zu Ihrer Sicherheit enthält diese Auskunft keine hochgeladenen Dokumente (Dateien), sondern nur den Hinweis, ob welche vorhanden sind. Zugangscodes und interne Protokolle sind nicht enthalten.',
    ];
}

function dsgvo_export_html(array $export): string
{
    $rows = '';
    foreach ($export['daten'] as $label => $value) {
        $rows .= '<tr><th>' . h((string) $label) . '</th><td>' . h((string) (is_bool($value) ? ($value ? 'ja' : 'nein') : $value)) . '</td></tr>';
    }
    $docs = '';
    foreach ($export['dokumente'] as $type => $state) {
        $docs .= '<tr><th>' . h((string) $type) . '</th><td>' . h((string) $state) . '</td></tr>';
    }
    $consents = '';
    foreach ($export['einwilligungen'] as $c) {
        $consents .= '<tr><td>' . h((string) $c['accepted_at']) . '</td><td>Fassung ' . h((string) $c['policy_version']) . '</td><td>'
            . h($c['kind'] === 'guardian' ? 'Erziehungsberechtigte(r): ' . (string) $c['guardian_name'] : 'Person selbst') . '</td></tr>';
    }
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Auskunft über gespeicherte Daten</title>'
        . '<style>body{font-family:Segoe UI,Arial,sans-serif;max-width:800px;margin:24px auto;padding:0 16px;color:#171923}'
        . 'table{border-collapse:collapse;width:100%;margin:8px 0 24px}th,td{border:1px solid #ddd;padding:6px 10px;text-align:left;vertical-align:top}th{background:#f3f4f8;width:36%}</style></head><body>'
        . '<h1>Auskunft über gespeicherte Daten</h1>'
        . '<p>Verantwortlicher: <strong>' . h((string) $export['verantwortlicher']) . '</strong><br>Erstellt am: ' . h((string) $export['erstellt_am']) . '<br>Art: ' . h((string) $export['art']) . '</p>'
        . '<h2>Gespeicherte Angaben</h2><table>' . $rows . '</table>'
        . '<h2>Hochgeladene Dokumente</h2><table>' . ($docs ?: '<tr><td>keine</td></tr>') . '</table>'
        . '<h2>Einwilligungen</h2><table>' . ($consents ?: '<tr><td>keine erfasst</td></tr>') . '</table>'
        . '<p>' . h((string) $export['hinweis']) . '</p></body></html>';
}

// ── Betroffenenanfragen ─────────────────────────────────────────────

function dsgvo_request_create(string $entity, int $id, string $kind, string $note = ''): int
{
    dsgvo_ensure_tables();
    $person = dsgvo_person($entity, $id);
    $name = $person === false ? '' : dsgvo_person_name($person);
    $stmt = db()->prepare('INSERT INTO privacy_requests (entity, person_id, person_name, kind, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([dsgvo_entity($entity), $id, $name, $kind, $note !== '' ? mb_substr($note, 0, 2000) : null]);
    $requestId = (int) db()->lastInsertId();
    app_log('privacy.request', 'Datenschutzanfrage: ' . $kind, ['target_type' => dsgvo_entity($entity) === 'staff' ? 'staff' : 'member', 'target_id' => $id, 'request' => $requestId], 'warning');

    // Verantwortlichen per E-Mail informieren (Frist: 1 Monat, Art. 12 Abs. 3 DSGVO)
    $to = dsgvo_settings()['org_email'];
    if ($to !== '') {
        try {
            require_once __DIR__ . '/Mailer.php';
            (new Mailer())->send($to, 'Datenschutz', 'Neue Datenschutzanfrage: ' . $kind,
                '<p>Es liegt eine neue Datenschutzanfrage vor (<strong>' . h($kind) . '</strong>) von ' . h($name) . '.</p>'
                . '<p>Bitte im Webpanel unter „Datenschutz → Anfragen“ bearbeiten. Frist zur Antwort: ein Monat.</p>');
        } catch (Throwable $e) {
            error_log('dsgvo_request_create mail: ' . $e->getMessage());
        }
    }
    return $requestId;
}

// ── Löschfristen ───────────────────────────────────────────────────

/**
 * Inaktive Personen, deren letzte Änderung länger als $months Monate zurückliegt (Kandidaten zum Löschen).
 *
 * @return array<int, array<string, mixed>>
 */
function dsgvo_retention_candidates(string $entity, int $months): array
{
    $table = dsgvo_entity($entity) === 'staff' ? 'staff' : 'members';
    $stmt = db()->prepare("SELECT id, vorname, nachname, updated_at FROM {$table} WHERE status = 'inaktiv' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MONTH) ORDER BY updated_at");
    $stmt->execute([$months]);
    return $stmt->fetchAll();
}

// ── Datenpannen (Art. 33/34) ─────────────────────────────────────────

/** @return array<int, array<string, mixed>> */
function dsgvo_breaches(): array
{
    dsgvo_ensure_tables();
    return db()->query('SELECT * FROM data_breaches ORDER BY discovered_at DESC, id DESC')->fetchAll();
}

/** Frist zur Meldung an die Datenschutzbehörde: 72 Stunden nach Kenntnis. */
function dsgvo_breach_deadline(array $breach): DateTimeImmutable
{
    return (new DateTimeImmutable((string) $breach['discovered_at']))->modify('+72 hours');
}

// ── Verzeichnis der Verarbeitungstätigkeiten (Art. 30) ────────────────

/** @return array<int, array<string, string>> */
function dsgvo_processing_register(): array
{
    $s = dsgvo_settings();
    $retention = $s['retention_months'] . ' Monate nach Austritt/Inaktivsetzung (danach Löschung bzw. Anonymisierung), gesetzliche Aufbewahrungspflichten bleiben unberührt';
    return [
        [
            'name' => 'Mitgliederverwaltung Spieler (U19)',
            'zweck' => 'Verwaltung der Kader- und Vereinsmitgliedschaft, Anmeldung zu Bewerben und Camps, Ausrüstung, Reiseorganisation',
            'personen' => 'Spieler (überwiegend Minderjährige) und deren Erziehungsberechtigte',
            'daten' => 'Stammdaten (Name, Geburtsdatum, Adresse, Kontakt), Verein/Position/Trikotnummer, Größe/Gewicht, Reisepassdaten, Sozialversicherungsnummer, NADA-Zertifikat, Angaben zu Ernährung/Unverträglichkeiten (können Gesundheitsdaten sein), Konfektionsgrößen, Dokumente (Reisepass, E-Card, NADA-Zertifikat, Rechte & Pflichten), Angaben der Erziehungsberechtigten',
            'rechtsgrundlage' => 'Art. 6 Abs. 1 lit. b (Mitgliedschaft/Vertrag), lit. a (Einwilligung, bei Minderjährigen durch Erziehungsberechtigte), Art. 9 Abs. 2 lit. a (Gesundheitsdaten)',
            'empfaenger' => $s['recipients'] . ' Auftragsverarbeiter: Hosting' . ($s['hoster'] !== '' ? ' (' . $s['hoster'] . ')' : '') . ', E-Mail-Versand' . ($s['mail_provider'] !== '' ? ' (' . $s['mail_provider'] . ')' : ''),
            'drittland' => 'keine Übermittlung in Drittländer vorgesehen (bei internationalen Veranstaltern im Einzelfall prüfen)',
            'loeschfrist' => $retention,
        ],
        [
            'name' => 'Staff-Verwaltung (Trainer, Betreuer, Funktionäre)',
            'zweck' => 'Organisation von Betreuung, Reisen und Ausrüstung',
            'personen' => 'Trainer, Betreuer, Funktionäre',
            'daten' => 'Name, Geburtsdatum, Kontakt, Adresse, Position, Reisepassdaten, NADA-Angaben, Ernährungshinweise, Konfektionsgrößen, Dokument „Rechte & Pflichten“',
            'rechtsgrundlage' => 'Art. 6 Abs. 1 lit. b (Vereinsfunktion), lit. a (Einwilligung), Art. 9 Abs. 2 lit. a (Gesundheitsdaten)',
            'empfaenger' => 'wie oben',
            'drittland' => 'keine',
            'loeschfrist' => $retention,
        ],
        [
            'name' => 'Zugangslinks und Datenprüfung',
            'zweck' => 'Betroffene können ihre Daten selbst prüfen, bestätigen und korrigieren',
            'personen' => 'Spieler, Erziehungsberechtigte, Staff',
            'daten' => 'E-Mail-Adresse, Zugangscode (nur als Hash), Zeitpunkt der Bestätigung, Einwilligungen',
            'rechtsgrundlage' => 'Art. 6 Abs. 1 lit. b, lit. f (Richtigkeit der Daten)',
            'empfaenger' => 'E-Mail-Anbieter (Versand)',
            'drittland' => 'keine',
            'loeschfrist' => 'mit der Person',
        ],
        [
            'name' => 'Benutzerkonten, Protokoll und Sicherheit',
            'zweck' => 'Zugriffsschutz, Nachvollziehbarkeit, Fehlersuche',
            'personen' => 'Benutzer des Webpanels und der Desktop-Anwendung',
            'daten' => 'Benutzername, Passwort-Hash, Rolle, IP-Adresse, Zeitpunkte und Aktionen (Protokoll)',
            'rechtsgrundlage' => 'Art. 6 Abs. 1 lit. f (Sicherheit der Verarbeitung, Art. 32)',
            'empfaenger' => 'Hosting-Anbieter (Auftragsverarbeiter)',
            'drittland' => 'keine',
            'loeschfrist' => 'Protokoll: ' . log_retention_days() . ' Tage (automatisch)',
        ],
    ];
}

/** @return array<int, string> technische und organisatorische Maßnahmen (Art. 32) laut Umsetzung in dieser Anwendung */
function dsgvo_measures(): array
{
    return [
        'Übertragung ausschließlich per HTTPS (HSTS); die Desktop-Anwendung verschlüsselt jede Anfrage und Antwort zusätzlich (AES-256-GCM).',
        'Verbindung zur Datenbank per TLS (soweit vom Datenbankserver angeboten).',
        'Passwörter in der Konfiguration (.env) sind verschlüsselt; der Schlüssel liegt getrennt in einer geschützten Datei.',
        'Hochgeladene Dokumente (Reisepass, E-Card, NADA, Rechte & Pflichten) werden verschlüsselt gespeichert.',
        'Zugriff nur nach Anmeldung; Rollen und Einzelrechte je Benutzer; Feld-Rechte für Spieler und Bearbeiter.',
        'Passwörter werden nur als Hash gespeichert; Sperre nach Fehlversuchen beim persönlichen Zugangslink.',
        'Protokoll aller wesentlichen Aktionen mit automatischer Löschung nach ' . log_retention_days() . ' Tagen.',
        'Zugangscodes und Sitzungs-Token werden nur als Hash gespeichert; Sitzungen laufen ab.',
        'Datensparsamkeit: Personenbezogene Daten werden nach Löschung einer Person vollständig entfernt (inkl. Dokumente).',
    ];
}

// ── Vorlage der Datenschutzerklärung ────────────────────────────────

/** @return array<int, array{0: string, 1: string}> Überschrift und Text (mit HTML-Absätzen) */
function dsgvo_policy_sections(): array
{
    $s = dsgvo_settings();
    $org = h($s['org_name']);
    $contact = trim($s['org_address'] . "\n" . ($s['org_email'] !== '' ? 'E-Mail: ' . $s['org_email'] : '') . "\n" . ($s['org_phone'] !== '' ? 'Telefon: ' . $s['org_phone'] : ''));
    $contactHtml = nl2br(h($contact));
    $dpo = $s['dpo'] !== '' ? '<p>Datenschutzbeauftragte(r): ' . h($s['dpo']) . '</p>' : '';
    $months = h($s['retention_months']);
    $log = (string) log_retention_days();

    return [
        ['1. Verantwortlicher', '<p><strong>' . $org . '</strong></p><p>' . $contactHtml . '</p>' . $dpo],
        ['2. Wofür wir Ihre Daten verwenden', '<p>Wir verarbeiten Ihre Daten, um die Mitgliedschaft im Kader zu verwalten, Sie für Bewerbe, Camps und Reisen anzumelden, Ausrüstung zu organisieren und Sie in Notfällen sowie bei organisatorischen Fragen zu erreichen. Über einen persönlichen Link können Sie Ihre Daten selbst prüfen, bestätigen und korrigieren.</p>'],
        ['3. Welche Daten wir verarbeiten', '<p>Stammdaten (Name, Geburtsdatum, Adresse, Telefon, E-Mail), Angaben zum Verein und zur Position, Körpergröße und Gewicht, Reisepassdaten und Sozialversicherungsnummer (für Anmeldungen und Reisen), NADA-Angaben, Ernährungshinweise oder Unverträglichkeiten, Konfektionsgrößen sowie – soweit hochgeladen – Kopien von Reisepass, E-Card, NADA-Zertifikat und das unterschriebene Dokument „Rechte &amp; Pflichten“. Bei Minderjährigen zusätzlich Name und Kontakt der Erziehungsberechtigten.</p><p>Ernährungshinweise oder Unverträglichkeiten können Gesundheitsdaten sein; sie verarbeiten wir nur mit Ihrer ausdrücklichen Einwilligung.</p>'],
        ['4. Rechtsgrundlagen', '<p>Die Verarbeitung erfolgt zur Erfüllung der Mitgliedschaft bzw. Vereinsfunktion (Art. 6 Abs. 1 lit. b DSGVO), aufgrund Ihrer Einwilligung (Art. 6 Abs. 1 lit. a, für Gesundheitsdaten Art. 9 Abs. 2 lit. a DSGVO) sowie aus berechtigtem Interesse an der Sicherheit unserer Systeme (Art. 6 Abs. 1 lit. f DSGVO). Bei Personen unter 14 Jahren ist immer die Einwilligung der Erziehungsberechtigten erforderlich; bei Personen unter 18 Jahren holen wir sie vorsorglich ein.</p>'],
        ['5. Empfänger', '<p>' . h($s['recipients']) . '</p><p>Technische Dienstleister (Hosting' . ($s['hoster'] !== '' ? ': ' . h($s['hoster']) : '') . ', E-Mail-Versand' . ($s['mail_provider'] !== '' ? ': ' . h($s['mail_provider']) : '') . ') verarbeiten Daten nur in unserem Auftrag auf Grundlage von Auftragsverarbeitungsverträgen. Eine Weitergabe in Länder außerhalb der EU/des EWR ist nicht vorgesehen.</p>'],
        ['6. Speicherdauer', '<p>Wir speichern Ihre Daten, solange Sie im Kader bzw. in Ihrer Funktion aktiv sind. Nach Ihrem Ausscheiden löschen wir sie nach spätestens ' . $months . ' Monaten, soweit keine gesetzlichen Aufbewahrungspflichten entgegenstehen. Protokolldaten (z. B. IP-Adresse) werden nach ' . h($log) . ' Tagen gelöscht.</p>'],
        ['7. Sicherheit', '<p>Die Übertragung erfolgt verschlüsselt (HTTPS), hochgeladene Dokumente und Zugangsdaten werden verschlüsselt gespeichert. Der Zugriff ist auf berechtigte Personen beschränkt und wird protokolliert.</p>'],
        ['8. Ihre Rechte', '<p>Sie haben das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21 DSGVO). Erteilte Einwilligungen können Sie jederzeit mit Wirkung für die Zukunft widerrufen. Über Ihren persönlichen Link können Sie eine Auskunft herunterladen und die Löschung beantragen; alternativ wenden Sie sich an die oben genannte Stelle.</p><p>Sie haben außerdem das Recht auf Beschwerde bei der Datenschutzbehörde (Österreich: <a href="https://www.dsb.gv.at" target="_blank" rel="noopener">www.dsb.gv.at</a>).</p>'],
    ];
}
