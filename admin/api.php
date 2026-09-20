<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_tokens.php';

require_permission('api.manage');

$newToken = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = post_str('name');
        if ($name === null || mb_strlen($name) > 100) {
            $error = 'Bitte eine Bezeichnung angeben (max. 100 Zeichen), z.B. "Excel Büro-PC".';
        } else {
            $newToken = api_token_create($name, ($_POST['access'] ?? '') === 'write', current_admin_id());
            app_log('api_token.create', 'API-Zugang erstellt', ['name' => $name, 'write' => ($_POST['access'] ?? '') === 'write']);
        }
    } elseif ($action === 'delete') {
        api_token_delete((int) ($_POST['id'] ?? 0));
        app_log('api_token.delete', 'API-Zugang widerrufen', ['target_type' => 'api_token', 'target_id' => (int) ($_POST['id'] ?? 0)], 'warning');
        flash_set('info', 'Zugang wurde widerrufen.');
        redirect('api.php');
    }
}

$tokens = api_token_list();
$baseUrl = (APP_BASE_URL !== '' ? APP_BASE_URL : (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'example.org')) . '/api';

$pageTitle = 'API-Zugang';
require __DIR__ . '/../includes/admin_header.php';
$info = flash_get('info');
?>
<div class="content-header">
    <h1>API-Zugang</h1>
</div>

<p>Mit einem API-Zugang können PC-Anwendungen (z.&nbsp;B. Excel, PowerShell oder eigene Programme)
direkt auf die Mitgliederdaten zugreifen – ohne Browser-Anmeldung. Jeder Zugang hat einen eigenen
Schlüssel, der jederzeit widerrufen werden kann.</p>

<?php if ($info): ?><p class="alert alert-success"><?= h($info) ?></p><?php endif; ?>
<?php if ($error): ?><p class="alert alert-error"><?= h($error) ?></p><?php endif; ?>

<?php if ($newToken !== null): ?>
    <div class="alert alert-warning">
        <p><strong>Neuer API-Schlüssel (wird nur jetzt einmalig angezeigt):</strong></p>
        <div class="link-box">
            <input type="text" readonly value="<?= h($newToken) ?>" id="newToken" data-select-on-click>
            <button type="button" class="btn" data-copy-target="newToken">Kopieren</button>
        </div>
        <p>Bitte sicher aufbewahren. Bei Verlust den Zugang widerrufen und einen neuen erstellen.</p>
    </div>
<?php endif; ?>

<div class="panel-grid">
    <section class="panel">
        <h2 class="section-title">Neuen Zugang erstellen</h2>
        <form method="post" action="api.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="name">Bezeichnung</label>
                <input type="text" id="name" name="name" required maxlength="100" placeholder="z.B. Excel Büro-PC">
            </div>
            <div class="form-group">
                <label for="access">Berechtigung</label>
                <select id="access" name="access">
                    <option value="read">Nur lesen</option>
                    <option value="write">Lesen &amp; Schreiben (anlegen, ändern, löschen)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Zugang erstellen</button>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">Verwendung</h2>
        <p class="muted">Basis-Adresse: <code><?= h($baseUrl) ?></code></p>
        <p class="alert alert-warning">Die API akzeptiert nur noch verschlüsselte Anfragen (Desktop-Anwendung). Die Beispiele unten
        (PowerShell, Excel) funktionieren nur, wenn in der .env <code>API_ALLOW_PLAIN=true</code> gesetzt ist – das wird nicht empfohlen.</p>
        <p><strong>PowerShell</strong></p>
<pre class="code-block">$h = @{ Authorization = "Bearer &lt;SCHLÜSSEL&gt;" }
Invoke-RestMethod "<?= h($baseUrl) ?>/members?limit=500" -Headers $h |
  Select-Object -ExpandProperty data | Export-Csv mitglieder.csv</pre>
        <p><strong>Excel</strong> (Daten → Aus dem Web → Erweitert): URL
            <code><?= h($baseUrl) ?>/members.csv</code>, HTTP-Header <code>Authorization</code> =
            <code>Bearer &lt;SCHLÜSSEL&gt;</code>.</p>
        <p class="muted">Endpunkte: <code>GET /members</code>, <code>GET /members/{id}</code>,
            <code>POST /members</code>, <code>PUT /members/{id}</code>, <code>DELETE /members/{id}</code>,
            <code>GET /members.csv</code>. Felder entsprechen den Spaltennamen (z.&nbsp;B.
            <code>nachname</code>, <code>email</code>, <code>geburtsdatum</code>).</p>
    </section>
</div>

<h2 class="section-title" style="margin-top:28px;">Verschlüsselung</h2>
<p><strong>Übertragung:</strong> Die Desktop-Anwendung verschlüsselt jede Anfrage und Antwort automatisch mit einem Schlüssel, der aus dem
API-Schlüssel abgeleitet wird. Es muss nichts zusätzlich eingetragen werden; ein widerrufener API-Schlüssel sperrt auch die Verschlüsselung.</p>
<?php $keyStatus = crypto_key_status(); $keyOk = array_filter($keyStatus['tried'], static fn (array $t) => $t['valid']) !== []; ?>
<?php if ($keyOk): ?>
    <p class="alert alert-success"><strong>Passwörter in der .env und hochgeladene Dokumente:</strong> Die Schlüsseldatei ist vorhanden, Dokumente werden verschlüsselt gespeichert.</p>
<?php else: ?>
    <p class="alert alert-warning"><strong>Passwörter in der .env und hochgeladene Dokumente:</strong> Noch nicht verschlüsselt, weil die Schlüsseldatei
    <code>master.key</code> fehlt. Das betrifft nicht die Anmeldung oder die App. Zum Einrichten <code>server-upload</code>-Paket hochladen oder auf dem Server
    <code>php tools/setup-encryption.php</code> ausführen. Der Server sucht die Datei an diesen Orten:</p>
    <div class="table-scroll">
    <table class="table">
        <thead><tr><th>Ort</th><th>Vorhanden</th><th>Lesbar</th><th>Gültig</th></tr></thead>
        <tbody>
        <?php foreach ($keyStatus['tried'] as $t): ?>
            <tr>
                <td><code><?= h($t['path']) ?></code></td>
                <td><?= $t['exists'] ? 'ja' : 'nein' ?></td>
                <td><?= $t['readable'] ? 'ja' : 'nein' ?></td>
                <td><?= $t['valid'] ? 'ja' : 'nein' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($keyStatus['open_basedir'] !== ''): ?>
        <p class="muted">PHP darf nur in diesen Ordnern lesen (open_basedir): <code><?= h($keyStatus['open_basedir']) ?></code></p>
    <?php endif; ?>
<?php endif; ?>

<h2 class="section-title" style="margin-top:28px;">Aktive Zugänge (<?= count($tokens) ?>)</h2>
<div class="table-scroll">
<table class="table">
    <thead>
        <tr><th>Bezeichnung</th><th>Berechtigung</th><th>Erstellt</th><th>Zuletzt verwendet</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (empty($tokens)): ?>
        <tr><td colspan="5" class="empty">Noch keine API-Zugänge.</td></tr>
    <?php endif; ?>
    <?php foreach ($tokens as $t): ?>
        <tr>
            <td><?= h($t['name']) ?></td>
            <td><span class="badge <?= (int) $t['can_write'] === 1 ? 'badge-orange' : 'badge-gray' ?>"><?= (int) $t['can_write'] === 1 ? 'Lesen &amp; Schreiben' : 'Nur lesen' ?></span></td>
            <td><?= h(date('d.m.Y', strtotime($t['created_at']))) ?><?= $t['created_by_name'] ? ' · ' . h($t['created_by_name']) : '' ?></td>
            <td><?= $t['last_used_at'] ? h(date('d.m.Y H:i', strtotime($t['last_used_at']))) : 'noch nie' ?></td>
            <td class="actions">
                <form method="post" action="api.php" class="inline-form"
                      data-confirm="Zugang &quot;<?= h($t['name']) ?>&quot; widerrufen? Anwendungen mit diesem Schlüssel verlieren sofort den Zugriff.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="link-button danger">Widerrufen</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
