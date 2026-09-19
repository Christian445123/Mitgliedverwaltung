<?php

declare(strict_types=1);

/**
 * REST-API für PC-Anwendungen (Excel Power Query, PowerShell, eigene Tools).
 *
 * Ohne Umleitung erreichbar über /api/index.php?path=members (statt /api/members).
 *
 * Authentifizierung: "Authorization: Bearer <token>" (oder Header "X-API-Key").
 * Tokens werden im Admin-Bereich unter "API-Zugang" erstellt.
 *
 *   GET    /api/members?q=&status=&limit=&offset=   Liste (JSON)
 *   GET    /api/members.csv[?status=aktiv]          Export (CSV, Excel-tauglich)
 *   GET    /api/members/{id}                        Einzelnes Mitglied
 *   POST   /api/members                             Anlegen        (Schreib-Token)
 *   PUT    /api/members/{id}                        Ändern         (Schreib-Token)
 *   DELETE /api/members/{id}                        Löschen        (Schreib-Token)
 *   POST   /api/members/bulk-delete                 Mehrere löschen {ids:[..]} oder alle {all:true, confirm:"ALLE LÖSCHEN"} (Schreib-Token)
 *   POST   /api/import                              CSV/XLSX-Import (Schreib-Token; commit=1 speichert)
 *   POST   /api/update                              Server-Update per git pull --ff-only (Schreib-Token)
 *   GET    /api/template.csv                        Import-Vorlage
 *   GET    /api/members/{id}/documents/{typ}        Dokument laden (typ: ecard, pass, nada, rechte)
 *   POST   /api/members/{id}/documents/{typ}        Dokument hochladen (multipart, Feld "file"; Schreib-Token)
 *   DELETE /api/members/{id}/documents/{typ}        Dokument entfernen (Schreib-Token)
 */

// Quelle für das Protokoll (muss vor config.php definiert sein)
define('LOG_SOURCE', 'api');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/api_tokens.php';
require_once __DIR__ . '/../includes/member_import.php';
require_once __DIR__ . '/../includes/updater.php';

// Die API nutzt keine Sitzung; eine evtl. von config.php gestartete Session wird sofort freigegeben.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function api_json(int $status, $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(int $status, string $message, array $details = []): never
{
    api_json($status, ['error' => $message] + ($details !== [] ? ['details' => $details] : []));
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function api_member(array $row): array
{
    $out = ['id' => (int) $row['id']];
    foreach (member_io_columns() as $key => [, $type]) {
        $value = $row[$key] ?? null;
        if ($value !== null && $value !== '') {
            if ($type === 'int') {
                $value = (int) $value;
            } elseif ($type === 'bool') {
                $value = (int) $value === 1;
            }
        } else {
            $value = $type === 'bool' && $key !== 'helm_eigener' ? false : null;
        }
        $out[$key] = $value;
    }
    $out['name_vorname'] = member_full_name($row); // automatisch: "Nachname Vorname"
    $out['weitere_camps'] = array_values(array_map(static fn (array $c) => $c['name'], array_filter(camps_all(), static fn (array $c) => !empty($row['camp:' . $c['id']]))));
    $out['dokumente'] = member_documents_present($row);
    $out['bestaetigt_am'] = $row['verified_at'] ?? null;
    $out['angelegt_am'] = $row['created_at'] ?? null;
    $out['geaendert_am'] = $row['updated_at'] ?? null;
    return $out;
}

// ── Authentifizierung ─────────────────────────────────────────────
$header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : trim($header);

$auth = $token !== '' ? api_token_verify($token) : null;
if ($auth === null) {
    app_log('api.auth_failed', 'Ungültiger oder fehlender API-Token', [], 'warning');
    usleep(300000);
    header('WWW-Authenticate: Bearer realm="U19"');
    api_error(401, 'Ungültiger oder fehlender API-Token.');
}
$canWrite = (int) $auth['can_write'] === 1;
$GLOBALS['log_actor'] = 'token:' . $auth['name'];

// ── Routing ───────────────────────────────────────────────────────
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/\\');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = trim((string) preg_replace('#^/?index\.php#', '', $path), '/');
// Ohne Server-Umleitung (z.B. nginx): /api/index.php?path=members/12
if (isset($_GET['path']) && is_string($_GET['path'])) {
    $path = trim($_GET['path'], '/');
}
$method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
$kaderFilter = in_array($_GET['kader'] ?? '', ['kader', 'nicht_im_kader'], true) ? $_GET['kader'] : null;

if ($path === '' || $path === 'ping') {
    api_json(200, ['ok' => true, 'token' => $auth['name'], 'write' => $canWrite]);
}

if ($path === 'members.csv' && $method === 'GET') {
    $status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mitglieder-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    member_export_csv($out, member_all($status, $kaderFilter));
    fclose($out);
    exit;
}

$requireWrite = static function () use ($canWrite): void {
    if (!$canWrite) {
        api_error(403, 'Dieser Token hat nur Leserechte.');
    }
};

if ($path === 'template.csv' && $method === 'GET') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mitglieder-vorlage.csv"');
    $out = fopen('php://output', 'w');
    member_export_csv($out, []);
    fclose($out);
    exit;
}

// Import einer CSV/XLSX-Datei (multipart: file, update_existing, commit=1 zum Speichern, sonst nur Vorschau)
if ($path === 'import' && $method === 'POST') {
    $requireWrite();
    $file = $_FILES['file'] ?? null;
    if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
        api_error(400, 'Datei fehlt oder Upload ist fehlgeschlagen.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        api_error(413, 'Die Datei ist zu groß (maximal 5 MB).');
    }
    $update = ($_POST['update_existing'] ?? '1') === '1';
    $commit = ($_POST['commit'] ?? '0') === '1';

    try {
        $kaderDefault = in_array($_POST['kader_default'] ?? '', ['kader', 'nicht_im_kader'], true) ? $_POST['kader_default'] : null;
        // Manuelle Spaltenzuordnung: JSON-Objekt {"<Spaltenindex>": "<Feld>" oder "-"}
        $overrides = [];
        $mapping = json_decode((string) ($_POST['mapping'] ?? ''), true);
        if (is_array($mapping)) {
            foreach ($mapping as $index => $target) {
                if (is_string($target) && ctype_digit((string) $index)) {
                    $overrides[(int) $index] = $target;
                }
            }
        }
        $analysis = member_import_analyze(spreadsheet_read($file['tmp_name'], (string) $file['name']), $update, $kaderDefault, $overrides);
        $response = [
            'counts' => $analysis['counts'],
            'unknown_columns' => $analysis['unknown'],
            'columns' => $analysis['columns'],
            'header_row' => $analysis['header_row'],
            'fields' => $analysis['fields'],
            'missing_required' => $analysis['missing_required'],
            'rows' => array_map(static fn (array $r) => [
                'line' => $r['line'],
                'action' => $r['action'],
                'name' => trim(($r['data']['nachname'] ?? '') . ', ' . ($r['data']['vorname'] ?? ''), ', '),
                'email' => $r['data']['email'] ?? '',
                'errors' => $r['errors'],
                'warnings' => $r['warnings'],
            ], $analysis['rows']),
        ];
        if ($commit) {
            $response['result'] = member_import_commit($analysis['rows'], $update);
            app_log('import.commit', 'Import per API', ['created' => $response['result']['created'], 'updated' => $response['result']['updated'], 'failed' => count($response['result']['failed']), 'file' => (string) $file['name']]);
        }
        api_json(200, $response);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
}

// Server-Update (git pull --ff-only), wie der Update-Button im Admin-Bereich
if ($path === 'update' && $method === 'POST') {
    $requireWrite();
    $result = perform_update(APP_ROOT);
    app_log('system.update', $result['success'] ? 'Server-Update per API erfolgreich' : 'Server-Update per API fehlgeschlagen', ['success' => $result['success']], $result['success'] ? 'info' : 'error');
    api_json(200, $result);
}

// Dokumente eines Mitglieds (E-Card, Pass, NADA, Rechte & Pflichten)
if (preg_match('#^members/(\d+)/documents/(ecard|pass|nada|rechte)$#', $path, $dm)) {
    $docId = (int) $dm[1];
    $docType = $dm[2];
    $docMember = member_find_by_id($docId);
    if ($docMember === false) {
        api_error(404, 'Mitglied nicht gefunden.');
    }

    try {
        if ($method === 'GET') {
            if (member_document_path($docMember, $docType) === null) {
                api_error(404, 'Dokument nicht vorhanden.');
            }
            member_document_send($docMember, $docType, false);
        }
        if ($method === 'POST') {
            $requireWrite();
            member_set_document($docId, $docType, 'file');
            app_log('document.upload', 'Dokument per API hochgeladen', ['target_type' => 'member', 'target_id' => $docId, 'type' => $docType]);
            api_json(200, ['dokumente' => member_documents_present(member_find_by_id($docId))]);
        }
        if ($method === 'DELETE') {
            $requireWrite();
            member_set_document($docId, $docType, null);
            app_log('document.delete', 'Dokument per API entfernt', ['target_type' => 'member', 'target_id' => $docId, 'type' => $docType]);
            api_json(200, ['dokumente' => member_documents_present(member_find_by_id($docId))]);
        }
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    header('Allow: GET, POST, DELETE');
    api_error(405, 'Methode nicht erlaubt.');
}

// Mehrere Mitglieder löschen: {"ids": [1, 2, 3]} oder alle: {"all": true, "confirm": "ALLE LÖSCHEN"}
if ($path === 'members/bulk-delete' && $method === 'POST') {
    $requireWrite();
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit "ids" oder "all" und "confirm".');
    }

    try {
        if (($body['all'] ?? false) === true) {
            if (($body['confirm'] ?? '') !== 'ALLE LÖSCHEN') {
                api_error(422, 'Zum Löschen aller Mitglieder muss "confirm" genau "ALLE LÖSCHEN" lauten.');
            }
            $deleted = member_delete_all();
            app_log('member.delete_all', 'ALLE Mitglieder per API gelöscht (' . $deleted . ')', ['deleted' => $deleted], 'warning');
            api_json(200, ['deleted' => $deleted]);
        }
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            api_error(422, '"ids" muss eine nicht leere Liste von Mitglieds-IDs sein.');
        }
        $deleted = member_delete_many($ids);
        app_log('member.delete_bulk', $deleted . ' Mitglieder per API gelöscht', ['ids' => $ids, 'deleted' => $deleted], 'warning');
        api_json(200, ['deleted' => $deleted]);
    } catch (RuntimeException $e) {
        api_error(409, $e->getMessage());
    }
}

if (!preg_match('#^members(?:/(\d+))?$#', $path, $m)) {
    api_error(404, 'Unbekannter Endpunkt.');
}
$id = isset($m[1]) ? (int) $m[1] : null;

/** @return array<string, mixed> */
$readBody = static function (): array {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body) || $body === [] || array_is_list($body)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit Mitgliedsfeldern im Request-Body.');
    }
    return $body;
};

try {
    if ($method === 'GET' && $id === null) {
        $q = trim((string) ($_GET['q'] ?? ''));
        $status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
        $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        api_json(200, [
            'total' => member_count($q, $status, $kaderFilter),
            'limit' => $limit,
            'offset' => $offset,
            'data' => array_map('api_member', member_search($q, $limit, $offset, $status, $kaderFilter)),
        ]);
    }

    if ($method === 'GET') {
        $member = member_find_by_id($id);
        $member === false ? api_error(404, 'Mitglied nicht gefunden.') : api_json(200, api_member($member));
    }

    if ($method === 'POST' && $id === null) {
        $requireWrite();
        ['data' => $data, 'errors' => $errors] = io_convert_row($readBody());
        foreach (['nachname', 'vorname', 'email'] as $required) {
            if (!isset($data[$required])) {
                $errors[] = member_io_columns()[$required][0] . ' ist ein Pflichtfeld';
            }
        }
        if ($errors !== []) {
            api_error(422, 'Ungültige Daten.', $errors);
        }
        if (member_find_by_email((string) $data['email']) !== false) {
            api_error(409, 'Ein Mitglied mit dieser E-Mail-Adresse existiert bereits.');
        }
        member_import_save($data, false);
        $created = member_find_by_email((string) $data['email']);
        app_log('member.create', 'Mitglied per API angelegt', ['target_type' => 'member', 'target_id' => $created['id'] ?? null]);
        api_json(201, api_member($created));
    }

    if (($method === 'PUT' || $method === 'PATCH') && $id !== null) {
        $requireWrite();
        $existing = member_find_by_id($id);
        if ($existing === false) {
            api_error(404, 'Mitglied nicht gefunden.');
        }
        ['data' => $data, 'errors' => $errors] = io_convert_row($readBody(), true);
        if ($errors !== []) {
            api_error(422, 'Ungültige Daten.', $errors);
        }
        $data = member_apply_consent_timestamp($data, $existing);
        $status = $data['status'] ?? null;
        unset($data['status']);
        member_upsert($data, $id, $status);
        app_log('member.update', 'Mitglied per API geändert', ['target_type' => 'member', 'target_id' => $id, 'fields' => array_keys($data)]);
        api_json(200, api_member(member_find_by_id($id)));
    }

    if ($method === 'DELETE' && $id !== null) {
        $requireWrite();
        if (member_find_by_id($id) === false) {
            api_error(404, 'Mitglied nicht gefunden.');
        }
        member_delete($id);
        app_log('member.delete', 'Mitglied per API gelöscht', ['target_type' => 'member', 'target_id' => $id], 'warning');
        api_json(200, ['deleted' => $id]);
    }

    header('Allow: GET, POST, PUT, PATCH, DELETE');
    api_error(405, 'Methode nicht erlaubt.');
} catch (RuntimeException $e) {
    api_error(409, $e->getMessage());
} catch (Throwable $e) {
    api_error(500, APP_DEBUG ? $e->getMessage() : 'Interner Serverfehler.');
}
