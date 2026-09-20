<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dsgvo.php';

require_permission('dsgvo.manage');
dsgvo_ensure_tables();

$tabs = [
    'uebersicht' => 'Übersicht',
    'einstellungen' => 'Verantwortlicher',
    'verzeichnis' => 'Verzeichnis (Art. 30)',
    'fristen' => 'Löschfristen',
    'anfragen' => 'Anfragen',
    'pannen' => 'Datenpannen',
];
$tab = (string) ($_GET['tab'] ?? 'uebersicht');
if (!isset($tabs[$tab])) {
    $tab = 'uebersicht';
}
$self = static fn (string $t): string => 'dsgvo.php?tab=' . $t;

// ── Aktionen (POST) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        dsgvo_settings_save($_POST);
        if (!empty($_POST['bump_version'])) {
            app_setting_set('dsgvo_policy_version', date('Y-m-d'));
            app_log('privacy.policy_version', 'Neue Fassung der Datenschutzerklärung (Zustimmung erneut nötig)', ['version' => date('Y-m-d')], 'warning');
        }
        app_log('privacy.settings', 'Datenschutz-Angaben geändert');
        flash_set('info', 'Angaben gespeichert.');
        redirect($self('einstellungen'));
    }

    if ($action === 'retention_delete') {
        $entity = dsgvo_entity((string) ($_POST['entity'] ?? 'members'));
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i) => $i > 0));
        $perm = $entity === 'staff' ? 'staff.delete' : 'members.delete';
        if (!user_can($perm)) {
            require_permission($perm);
        }
        $deleted = $entity === 'staff' ? staff_delete_many($ids) : member_delete_many($ids);
        dsgvo_cleanup_orphans();
        app_log('privacy.retention_delete', 'Löschfrist: ' . $deleted . ' Person(en) gelöscht (' . $entity . ')', ['deleted' => $deleted, 'entity' => $entity], 'warning');
        flash_set('info', $deleted . ' Person(en) endgültig gelöscht.');
        redirect($self('fristen'));
    }

    if ($action === 'request_done') {
        $stmt = db()->prepare("UPDATE privacy_requests SET status = 'erledigt', done_at = NOW(), done_by = ? WHERE id = ?");
        $stmt->execute([current_admin_username(), (int) ($_POST['id'] ?? 0)]);
        flash_set('info', 'Anfrage als erledigt markiert.');
        redirect($self('anfragen'));
    }

    if ($action === 'breach_add') {
        $when = (string) ($_POST['discovered_at'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($description === '' || strtotime($when) === false) {
            flash_set('error', 'Bitte Zeitpunkt der Kenntnis und eine Beschreibung angeben.');
        } else {
            $stmt = db()->prepare('INSERT INTO data_breaches (discovered_at, description, data_categories, persons_affected, risk, measures, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                date('Y-m-d H:i:s', (int) strtotime($when)), $description,
                trim((string) ($_POST['data_categories'] ?? '')) ?: null, trim((string) ($_POST['persons_affected'] ?? '')) ?: null,
                in_array($_POST['risk'] ?? '', ['gering', 'mittel', 'hoch'], true) ? $_POST['risk'] : 'gering',
                trim((string) ($_POST['measures'] ?? '')) ?: null, current_admin_username(),
            ]);
            app_log('privacy.breach', 'Datenpanne erfasst', ['breach' => (int) db()->lastInsertId()], 'error');
            flash_set('info', 'Datenpanne erfasst. Meldefrist an die Datenschutzbehörde: 72 Stunden ab Kenntnis.');
        }
        redirect($self('pannen'));
    }

    if ($action === 'breach_update') {
        $id = (int) ($_POST['id'] ?? 0);
        $field = ($_POST['field'] ?? '') === 'persons_notified_at' ? 'persons_notified_at' : 'authority_reported_at';
        db()->prepare("UPDATE data_breaches SET {$field} = NOW() WHERE id = ?")->execute([$id]);
        flash_set('info', 'Vermerkt.');
        redirect($self('pannen'));
    }
}

$settings = dsgvo_settings();
$info = flash_get('info');
$error = flash_get('error');
$pageTitle = 'Datenschutz';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="content-header">
    <h1>Datenschutz (DSGVO)</h1>
</div>

<p class="alert alert-warning">Die Texte und Listen auf dieser Seite sind eine <strong>Vorlage</strong> auf Basis der tatsächlich gespeicherten Daten. Sie müssen vom Verein
(Verantwortlicher) geprüft und angepasst werden; sie ersetzen keine Rechtsberatung.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<p class="chip-group" style="margin-bottom:16px;">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="chip <?= $tab === $key ? 'active' : '' ?>" href="<?= h($self($key)) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</p>

<?php if ($tab === 'uebersicht'): ?>
    <?php
    dsgvo_cleanup_orphans();
    $openRequests = (int) db()->query("SELECT COUNT(*) FROM privacy_requests WHERE status = 'offen'")->fetchColumn();
    $consentsPlayers = dsgvo_consent_map('members');
    $consentsStaff = dsgvo_consent_map('staff');
    $totalPlayers = (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'aktiv'")->fetchColumn();
    $totalStaff = (int) db()->query("SELECT COUNT(*) FROM staff WHERE status = 'aktiv'")->fetchColumn();
    $noConsentPlayers = db()->query("SELECT id, vorname, nachname, email FROM members WHERE status = 'aktiv' ORDER BY nachname, vorname")->fetchAll();
    $noConsentPlayers = array_values(array_filter($noConsentPlayers, static fn (array $p) => !isset($consentsPlayers[(string) $p['id']])));
    $noConsentStaff = db()->query("SELECT id, vorname, nachname, email FROM staff WHERE status = 'aktiv' ORDER BY nachname, vorname")->fetchAll();
    $noConsentStaff = array_values(array_filter($noConsentStaff, static fn (array $p) => !isset($consentsStaff[(string) $p['id']])));
    $breachOpen = array_filter(dsgvo_breaches(), static fn (array $b) => $b['authority_reported_at'] === null && $b['risk'] !== 'gering');
    ?>
    <div class="panel-grid">
        <section class="panel">
            <h2 class="section-title">Einwilligungen (Fassung <?= h(dsgvo_policy_version()) ?>)</h2>
            <p>Spieler: <strong><?= $totalPlayers - count($noConsentPlayers) ?></strong> von <?= $totalPlayers ?> aktiven haben zugestimmt.<br>
               Staff: <strong><?= $totalStaff - count($noConsentStaff) ?></strong> von <?= $totalStaff ?> aktiven haben zugestimmt.</p>
            <p class="muted">Die Zustimmung wird beim ersten Öffnen des persönlichen Links eingeholt (bei Minderjährigen durch Erziehungsberechtigte).
            Über „Bestätigung …“ und die Massenmail lässt sich das anstoßen.</p>
        </section>
        <section class="panel">
            <h2 class="section-title">Offene Aufgaben</h2>
            <ul>
                <li>Offene Anfragen von Betroffenen: <strong><?= $openRequests ?></strong> (<a href="<?= h($self('anfragen')) ?>">ansehen</a>)</li>
                <li>Nicht gemeldete Datenpannen mit mittlerem/hohem Risiko: <strong><?= count($breachOpen) ?></strong> (<a href="<?= h($self('pannen')) ?>">ansehen</a>)</li>
                <li>Verantwortlicher eingetragen: <strong><?= $settings['org_email'] !== '' ? 'ja' : 'nein – bitte ergänzen' ?></strong> (<a href="<?= h($self('einstellungen')) ?>">bearbeiten</a>)</li>
            </ul>
            <p><a href="../datenschutz.php" target="_blank" rel="noopener">Öffentliche Datenschutzerklärung ansehen</a></p>
        </section>
    </div>
    <?php if ($noConsentPlayers || $noConsentStaff): ?>
        <h2 class="section-title" style="margin-top:20px;">Ohne aktuelle Einwilligung (<?= count($noConsentPlayers) + count($noConsentStaff) ?>)</h2>
        <div class="table-scroll"><table class="table">
            <thead><tr><th>Name</th><th>Art</th><th>E-Mail</th></tr></thead>
            <tbody>
            <?php foreach ($noConsentPlayers as $p): ?><tr><td><?= h($p['nachname'] . ' ' . $p['vorname']) ?></td><td>Spieler</td><td><?= h((string) $p['email']) ?></td></tr><?php endforeach; ?>
            <?php foreach ($noConsentStaff as $p): ?><tr><td><?= h($p['nachname'] . ' ' . $p['vorname']) ?></td><td>Staff</td><td><?= h((string) $p['email']) ?></td></tr><?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'einstellungen'): ?>
    <form method="post" action="<?= h($self('einstellungen')) ?>" class="member-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <fieldset>
            <legend>Verantwortlicher (erscheint in der Datenschutzerklärung)</legend>
            <div class="form-group"><label for="org_name">Name des Vereins / Verbands</label><input type="text" id="org_name" name="org_name" value="<?= h($settings['org_name']) ?>"></div>
            <div class="form-group"><label for="org_address">Anschrift</label><textarea id="org_address" name="org_address" rows="3"><?= h($settings['org_address']) ?></textarea></div>
            <div class="form-group"><label for="org_email">Kontakt-E-Mail für Datenschutzanfragen (erhält Benachrichtigungen)</label><input type="email" id="org_email" name="org_email" value="<?= h($settings['org_email']) ?>"></div>
            <div class="form-group"><label for="org_phone">Telefon</label><input type="text" id="org_phone" name="org_phone" value="<?= h($settings['org_phone']) ?>"></div>
            <div class="form-group"><label for="dpo">Datenschutzbeauftragte(r), falls vorhanden</label><input type="text" id="dpo" name="dpo" value="<?= h($settings['dpo']) ?>"></div>
        </fieldset>
        <fieldset>
            <legend>Dienstleister und Empfänger</legend>
            <div class="form-group"><label for="hoster">Hosting-Anbieter (Server/Datenbank) – mit Auftragsverarbeitungsvertrag</label><input type="text" id="hoster" name="hoster" value="<?= h($settings['hoster']) ?>"></div>
            <div class="form-group"><label for="mail_provider">E-Mail-Anbieter (Versand) – mit Auftragsverarbeitungsvertrag</label><input type="text" id="mail_provider" name="mail_provider" value="<?= h($settings['mail_provider']) ?>"></div>
            <div class="form-group"><label for="recipients">Empfänger der Daten (Text für die Datenschutzerklärung)</label><textarea id="recipients" name="recipients" rows="4"><?= h($settings['recipients']) ?></textarea></div>
        </fieldset>
        <fieldset>
            <legend>Löschfristen</legend>
            <div class="form-group"><label for="retention_months">Inaktive Personen löschen nach (Monaten)</label><input type="number" id="retention_months" name="retention_months" min="1" max="240" value="<?= h($settings['retention_months']) ?>"></div>
            <p class="muted">Das Protokoll wird nach <?= (int) log_retention_days() ?> Tagen automatisch gelöscht (.env: LOG_RETENTION_DAYS).</p>
        </fieldset>
        <fieldset>
            <legend>Fassung der Datenschutzerklärung</legend>
            <label style="font-weight:400;"><input type="checkbox" name="bump_version" value="1"> Inhalt wurde wesentlich geändert: alle Personen müssen beim nächsten Öffnen ihres Links <strong>erneut zustimmen</strong> (neue Fassung: heute)</label>
        </fieldset>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

<?php elseif ($tab === 'verzeichnis'): ?>
    <p>Verzeichnis der Verarbeitungstätigkeiten nach Art. 30 DSGVO (Stand <?= h(date('d.m.Y')) ?>). Zum Ausdrucken oder Speichern die Browser-Funktion „Drucken → Als PDF“ nutzen.</p>
    <?php foreach (dsgvo_processing_register() as $i => $row): ?>
        <section class="panel" style="margin-bottom:16px;">
            <h2 class="section-title"><?= ($i + 1) . '. ' . h($row['name']) ?></h2>
            <table class="table">
                <tbody>
                <tr><th style="width:26%">Zweck</th><td><?= h($row['zweck']) ?></td></tr>
                <tr><th>Betroffene Personen</th><td><?= h($row['personen']) ?></td></tr>
                <tr><th>Datenkategorien</th><td><?= h($row['daten']) ?></td></tr>
                <tr><th>Rechtsgrundlage</th><td><?= h($row['rechtsgrundlage']) ?></td></tr>
                <tr><th>Empfänger</th><td><?= h($row['empfaenger']) ?></td></tr>
                <tr><th>Drittlandübermittlung</th><td><?= h($row['drittland']) ?></td></tr>
                <tr><th>Löschfrist</th><td><?= h($row['loeschfrist']) ?></td></tr>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
    <section class="panel">
        <h2 class="section-title">Technische und organisatorische Maßnahmen (Art. 32)</h2>
        <ul><?php foreach (dsgvo_measures() as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul>
        <p class="muted">Organisatorisch zu ergänzen (vom Verein): Zugriffsberechtigte benennen und verpflichten, Auftragsverarbeitungsverträge abschließen, Passwort-/Zugangsregeln, Umgang mit Exporten auf privaten PCs.</p>
    </section>

<?php elseif ($tab === 'fristen'): ?>
    <?php $months = (int) $settings['retention_months']; ?>
    <p>Inaktive Personen, deren letzte Änderung länger als <strong><?= $months ?> Monate</strong> zurückliegt, sollten gelöscht werden
    (Frist änderbar unter „Verantwortlicher“). Das Löschen entfernt die Person und alle Dokumente endgültig.</p>
    <?php foreach (['members' => 'Spieler', 'staff' => 'Staff'] as $entity => $label): ?>
        <?php $candidates = dsgvo_retention_candidates($entity, $months); ?>
        <h2 class="section-title"><?= h($label) ?> (<?= count($candidates) ?>)</h2>
        <?php if ($candidates === []): ?>
            <p class="muted">Keine Löschkandidaten.</p>
        <?php else: ?>
            <form method="post" action="<?= h($self('fristen')) ?>" data-confirm="Die ausgewählten Personen endgültig löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="retention_delete">
                <input type="hidden" name="entity" value="<?= h($entity) ?>">
                <div class="table-scroll"><table class="table">
                    <thead><tr><th></th><th>Name</th><th>Zuletzt geändert</th></tr></thead>
                    <tbody>
                    <?php foreach ($candidates as $c): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" checked></td>
                            <td><?= h($c['nachname'] . ' ' . $c['vorname']) ?></td>
                            <td><?= h(date('d.m.Y', (int) strtotime((string) $c['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <button type="submit" class="btn btn-danger">Ausgewählte endgültig löschen</button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>

<?php elseif ($tab === 'anfragen'): ?>
    <?php $requests = db()->query('SELECT * FROM privacy_requests ORDER BY (status = \'offen\') DESC, created_at DESC')->fetchAll(); ?>
    <p>Anfragen von Betroffenen (z. B. Löschung über den persönlichen Link). Frist zur Beantwortung: <strong>ein Monat</strong> (Art. 12 Abs. 3 DSGVO).
    Eine Auskunft kannst du hier je Person als Datei erzeugen.</p>
    <div class="table-scroll"><table class="table">
        <thead><tr><th>Eingang</th><th>Person</th><th>Art</th><th>Anmerkung</th><th>Frist</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if ($requests === []): ?><tr><td colspan="7" class="empty">Keine Anfragen.</td></tr><?php endif; ?>
        <?php foreach ($requests as $r): ?>
            <?php $deadline = (new DateTimeImmutable((string) $r['created_at']))->modify('+1 month'); ?>
            <tr>
                <td><?= h(date('d.m.Y H:i', (int) strtotime((string) $r['created_at']))) ?></td>
                <td><?= h((string) $r['person_name']) ?> <span class="muted">(<?= $r['entity'] === 'staff' ? 'Staff' : 'Spieler' ?>)</span></td>
                <td><?= h($r['kind'] === 'loeschung' ? 'Löschung' : (string) $r['kind']) ?></td>
                <td><?= h((string) $r['note']) ?></td>
                <td><?= h($deadline->format('d.m.Y')) ?></td>
                <td><span class="badge <?= $r['status'] === 'offen' ? 'badge-orange' : 'badge-green' ?>"><?= h((string) $r['status']) ?></span></td>
                <td class="actions">
                    <a class="btn btn-sm" href="dsgvo-export.php?entity=<?= h((string) $r['entity']) ?>&amp;id=<?= (int) $r['person_id'] ?>&amp;format=html">Auskunft</a>
                    <?php if ($r['status'] === 'offen'): ?>
                        <form method="post" action="<?= h($self('anfragen')) ?>" class="inline-form">
                            <?= csrf_field() ?><input type="hidden" name="action" value="request_done"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm">Erledigt</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted">Zum Erfüllen einer Löschanfrage die Person unter „Mitglieder“ bzw. „Staff“ löschen. Berichtigungen lassen sich direkt im Formular der Person vornehmen.</p>

<?php elseif ($tab === 'pannen'): ?>
    <p class="alert alert-warning"><strong>Ablauf bei einer Datenpanne (Art. 33/34 DSGVO):</strong> 1. Vorfall sofort eindämmen (Zugänge sperren, Passwörter/Schlüssel ändern) und hier erfassen.
    2. Risiko bewerten. 3. Bei Risiko für Betroffene <strong>binnen 72 Stunden</strong> ab Kenntnis an die Datenschutzbehörde melden (Österreich:
    <a href="https://www.dsb.gv.at" target="_blank" rel="noopener">dsb.gv.at</a>). 4. Bei hohem Risiko auch die Betroffenen benachrichtigen. 5. Maßnahmen dokumentieren.
    <?= $settings['breach_contact'] !== '' ? 'Ansprechperson: ' . h($settings['breach_contact']) : '' ?></p>

    <form method="post" action="<?= h($self('pannen')) ?>" class="member-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="breach_add">
        <fieldset>
            <legend>Datenpanne erfassen</legend>
            <div class="form-row" style="flex-wrap:wrap;">
                <div class="form-group"><label for="discovered_at">Zeitpunkt der Kenntnis</label><input type="datetime-local" id="discovered_at" name="discovered_at" value="<?= h(date('Y-m-d\TH:i')) ?>" required></div>
                <div class="form-group"><label for="risk">Risiko für Betroffene</label>
                    <select id="risk" name="risk"><option value="gering">gering</option><option value="mittel">mittel</option><option value="hoch">hoch</option></select></div>
            </div>
            <div class="form-group"><label for="description">Was ist passiert?</label><textarea id="description" name="description" rows="3" required></textarea></div>
            <div class="form-group"><label for="data_categories">Betroffene Datenarten</label><input type="text" id="data_categories" name="data_categories" placeholder="z. B. Namen, Adressen, Reisepassdaten"></div>
            <div class="form-group"><label for="persons_affected">Betroffene Personen (Anzahl/Gruppe)</label><input type="text" id="persons_affected" name="persons_affected"></div>
            <div class="form-group"><label for="measures">Ergriffene Maßnahmen</label><textarea id="measures" name="measures" rows="2"></textarea></div>
        </fieldset>
        <button type="submit" class="btn btn-primary">Erfassen</button>
    </form>

    <h2 class="section-title" style="margin-top:24px;">Erfasste Datenpannen</h2>
    <div class="table-scroll"><table class="table">
        <thead><tr><th>Kenntnis</th><th>Beschreibung</th><th>Risiko</th><th>Meldefrist (72 h)</th><th>Behörde gemeldet</th><th>Betroffene informiert</th></tr></thead>
        <tbody>
        <?php $breaches = dsgvo_breaches(); if ($breaches === []): ?><tr><td colspan="6" class="empty">Keine Einträge.</td></tr><?php endif; ?>
        <?php foreach ($breaches as $b): ?>
            <?php $deadline = dsgvo_breach_deadline($b); $overdue = $b['authority_reported_at'] === null && $deadline < new DateTimeImmutable('now'); ?>
            <tr>
                <td><?= h(date('d.m.Y H:i', (int) strtotime((string) $b['discovered_at']))) ?></td>
                <td><?= h((string) $b['description']) ?><br><span class="muted"><?= h((string) $b['data_categories']) ?> · <?= h((string) $b['persons_affected']) ?></span></td>
                <td><span class="badge <?= $b['risk'] === 'hoch' ? 'badge-red' : ($b['risk'] === 'mittel' ? 'badge-orange' : 'badge-gray') ?>"><?= h((string) $b['risk']) ?></span></td>
                <td><?= h($deadline->format('d.m.Y H:i')) ?><?= $overdue ? ' <strong style="color:#dc2626">überfällig</strong>' : '' ?></td>
                <td><?php if ($b['authority_reported_at']): ?><?= h(date('d.m.Y H:i', (int) strtotime((string) $b['authority_reported_at']))) ?>
                    <?php else: ?><form method="post" action="<?= h($self('pannen')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="breach_update"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="field" value="authority_reported_at"><button class="btn btn-sm" type="submit">Als gemeldet vermerken</button></form><?php endif; ?></td>
                <td><?php if ($b['persons_notified_at']): ?><?= h(date('d.m.Y H:i', (int) strtotime((string) $b['persons_notified_at']))) ?>
                    <?php else: ?><form method="post" action="<?= h($self('pannen')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="breach_update"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="field" value="persons_notified_at"><button class="btn btn-sm" type="submit">Als informiert vermerken</button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
