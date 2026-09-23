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
 *   POST   /api/license/validate                    Lizenzschlüssel der Desktop-Anwendung prüfen (signierte Offline-Freigabe, max. 3 Tage)
 *   POST   /api/auth/login, /api/auth/logout      Anmeldung der Desktop-App mit den Web-Benutzerdaten (Header X-User-Token bei allen weiteren Anfragen)
 *   GET/POST/PUT/DELETE /api/admin/users|roles[/{id}], GET /api/admin/permissions   Benutzer, Rollen und Rechte (Recht users.manage, nur mit Benutzeranmeldung)
 *   GET/POST/PUT/DELETE /api/staff[/{id}]           Staff (Coaches/Betreuer) lesen, anlegen, ändern, löschen
 *   GET/POST/DELETE     /api/staff/{id}/documents/rechte  Staff: unterschriebenes Dokument Rechte & Pflichten
 *   GET/POST /api/members|staff/{id}/link          Zugangslink (POST: regenerate_link, regenerate_password, send_email, reset_verification)
 *   POST /api/members|staff/verification/reset    Bestätigung zurücksetzen {ids:[..]};  POST /api/members|staff/send-links  Massenmail {ids:[..≤10]}
 *   POST /api/members|staff/camp-assign            Camp-Massenzuweisung {ids:[..], camp:"camp_1"|"c12", action:"add"|"remove"} (Schreib-Token + members.edit/staff.edit)
 *   POST/PUT            /api/members/{id}/document-flags     "Fehlt"-Markierung {nada, pass, ecard, rechte: true/false}
 *   GET    /api/roster.pdf|xlsx                     Alphabetischer Roster;  /api/roster-ifaf.pdf|xlsx?competition=&game=&team= IFAF-Roster
 *   GET    /api/members/{id}/documents/{typ}        Dokument laden (typ: ecard, pass, nada, rechte)
 *   POST   /api/members/{id}/documents/{typ}        Dokument hochladen (multipart, Feld "file"; Schreib-Token)
 *   DELETE /api/members/{id}/documents/{typ}        Dokument entfernen (Schreib-Token)
 *   GET    /api/registrations                       Neue, noch nicht zugewiesene Spieler-Anmeldungen (Recht members.registrations)
 *   POST   /api/registrations/{id}/approve           {"target":"kader"|"nicht_im_kader"|"staff"} übernehmen (Schreib-Token)
 *   POST   /api/registrations/{id}/reject            Anmeldung ablehnen und löschen (Schreib-Token)
 *   GET    /api/registrations/staff                  Neue, noch nicht freigegebene Staff-Anmeldungen (Recht members.registrations)
 *   POST   /api/registrations/staff/{id}/approve      Staff-Anmeldung freigeben (Schreib-Token)
 *   POST   /api/registrations/staff/{id}/reject       Staff-Anmeldung ablehnen und löschen (Schreib-Token)
 *   GET/POST/PUT/PATCH/DELETE /api/registration-links[/{id}]  Registrierungslinks verwalten (Schreib-Token für Änderungen);
 *          POST-Body optional mit "link_type":"player"|"staff" (Standard player)
 *   GET/PUT /api/registration-settings               Benachrichtigungs-Adresse (notify_email) lesen/setzen
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
require_once __DIR__ . '/transport.php';
transport_bootstrap(); // verschlüsselte Anfrage entschlüsseln, Antwort beim Beenden verschlüsseln


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
    foreach ($GLOBALS['api_hidden_keys'] ?? [] as $hiddenKey) {
        unset($out[$hiddenKey]); // Feld-Rechte: für Bearbeiter ausgeblendete Felder
    }
    $out['weitere_camps'] = array_values(array_map(static fn (array $c) => $c['name'], array_filter(camps_all(), static fn (array $c) => !empty($row['camp:' . $c['id']]))));
    $out['dokumente'] = member_documents_present($row);
    $out['dokumente_fehlen'] = array_keys(member_documents_missing($row)); // fehlende Pflichtdokumente
    $out['dokumente_markiert_fehlt'] = [];
    foreach (array_keys(MEMBER_DOCUMENT_REQUIRED) as $reqType) {
        $out['dokumente_markiert_fehlt'][$reqType] = !empty($row['fehlt_' . $reqType]);
    }
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

// ── Angemeldeter Benutzer (Desktop-App): Header X-User-Token ──────────────
require_once __DIR__ . '/../includes/app_sessions.php';

/** @return array<string, mixed> */
function api_user_info(int $id, string $username, string $role): array
{
    return ['id' => $id, 'username' => $username, 'role' => $role, 'permissions' => array_keys(user_permissions($id))];
}

$apiUser = null;
$userTokenHeader = trim((string) ($_SERVER['HTTP_X_USER_TOKEN'] ?? ''));
if ($userTokenHeader !== '') {
    $apiUser = app_session_verify($userTokenHeader);
    if ($apiUser === null) {
        api_json(401, ['error' => 'Die Anmeldung ist abgelaufen. Bitte neu anmelden.', 'code' => 'session_expired']);
    }
    $GLOBALS['log_actor'] = 'user:' . $apiUser['username'];
    require_once __DIR__ . '/../includes/field_access.php';
    if ($apiUser['role'] !== 'administrator') {
        $GLOBALS['api_hidden_keys'] = field_access_hidden_export_keys('editor'); // Felder, die Bearbeiter nicht sehen/ändern dürfen (Feld-Rechte)
    }
    // Schreiben nur, wenn Token UND Benutzer es dürfen
    $userPermissions = user_permissions($apiUser['id']);
    $canWrite = $canWrite && (isset($userPermissions['members.edit']) || isset($userPermissions['members.create']) || isset($userPermissions['members.delete']) || isset($userPermissions['members.links']) || isset($userPermissions['staff.edit']));
}

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

// ── Anmeldung der Desktop-Anwendung mit den Web-Benutzerdaten ─────────────
$apiCan = static function (string $permission) use ($apiUser): bool {
    return $apiUser === null || isset(user_permissions((int) $apiUser['id'])[$permission]);
};

if ($path === 'auth/login' && $method === 'POST') {
    $loginBody = json_decode((string) api_body(), true);
    if (!is_array($loginBody)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit username und password.');
    }
    $loginResult = app_user_authenticate(trim((string) ($loginBody['username'] ?? '')), (string) ($loginBody['password'] ?? ''));
    if (!$loginResult['ok']) {
        if ($loginResult['reason'] === 'must_change_password') {
            api_json(403, ['error' => 'Bitte zuerst im Web-Panel anmelden und das Passwort ändern.', 'code' => 'must_change_password']);
        }
        api_json(401, ['error' => 'Benutzername oder Passwort ist falsch.', 'code' => 'invalid_credentials']);
    }
    $loginAdmin = $loginResult['admin'];
    $session = app_session_create((int) $loginAdmin['id'], (string) ($loginBody['machine_name'] ?? ''), ($loginBody['remember'] ?? false) === true);
    app_log('auth.login', 'Anmeldung erfolgreich (Desktop-App)', ['actor' => $loginAdmin['username'], 'target_type' => 'admin', 'target_id' => $loginAdmin['id'], 'via' => 'app', 'machine' => (string) ($loginBody['machine_name'] ?? '')]);
    api_json(200, [
        'token' => $session['token'],
        'expires_at' => $session['expires_at'],
        'user' => api_user_info((int) $loginAdmin['id'], (string) $loginAdmin['username'], (string) $loginAdmin['role']),
    ]);
}

// Erstes Passwort festlegen: Benutzer, die im Web-Panel neu angelegt wurden, müssen ihr Passwort ändern, bevor sie sich
// anmelden dürfen. Die App macht das mit Benutzername, bisherigem Passwort und neuem Passwort (ohne Sitzung).
if ($path === 'auth/first-password' && $method === 'POST') {
    require_once __DIR__ . '/../includes/manage_api.php';
    $fpBody = json_decode((string) api_body(), true);
    if (!is_array($fpBody)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit username, password und new_password.');
    }
    $fpResult = app_user_authenticate(trim((string) ($fpBody['username'] ?? '')), (string) ($fpBody['password'] ?? ''));
    if (!$fpResult['ok'] && $fpResult['reason'] !== 'must_change_password') {
        api_json(401, ['error' => 'Benutzername oder Passwort ist falsch.', 'code' => 'invalid_credentials']);
    }
    try {
        mg_password_change((int) $fpResult['admin']['id'], (string) $fpBody['password'], (string) ($fpBody['new_password'] ?? ''), 0);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    api_json(200, ['ok' => true]);
}

if ($path === 'auth/logout' && $method === 'POST') {
    if ($userTokenHeader !== '') {
        app_session_delete($userTokenHeader);
        app_log('auth.logout', 'Abgemeldet (Desktop-App)', ['via' => 'app']);
    }
    api_json(200, ['ok' => true]);
}

// Rechte des angemeldeten Benutzers prüfen (Anfragen nur mit API-Token, z. B. Excel, bleiben unverändert)
if ($apiUser !== null) {
    $neededPermission = api_required_permission($method, $path);
    if ($neededPermission !== null && !$apiCan($neededPermission)) {
        app_log('auth.forbidden', 'Zugriff verweigert (Desktop-App): ' . $neededPermission, ['permission' => $neededPermission, 'path' => $path], 'warning');
        api_error(403, 'Keine Berechtigung: ' . (permissions_registry()[$neededPermission][0] ?? $neededPermission));
    }
}

// ── Benutzer-, Rollen- und Rechteverwaltung (nur mit Benutzeranmeldung und Recht "users.manage") ──
if (str_starts_with($path, 'admin/')) {
    if ($apiUser === null) {
        api_error(403, 'Die Benutzerverwaltung ist nur mit Benutzeranmeldung möglich.');
    }
    if (!$apiCan('users.manage')) {
        api_error(403, 'Keine Berechtigung: ' . (permissions_registry()['users.manage'][0] ?? 'users.manage'));
    }
    require_once __DIR__ . '/../includes/user_admin.php';
    $actorId = (int) $apiUser['id'];
    $adminBody = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? json_decode((string) api_body(), true) : null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && !is_array($adminBody)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt.');
    }

    try {
        if ($path === 'admin/permissions' && $method === 'GET') {
            api_json(200, ['permissions' => ua_permission_list()]);
        }
        if ($path === 'admin/roles' && $method === 'GET') {
            api_json(200, ['roles' => ua_roles()]);
        }
        if (preg_match('#^admin/roles(?:/(\d+))?$#', $path, $rm2) === 1) {
            $roleIdParam = isset($rm2[1]) ? (int) $rm2[1] : 0;
            if ($method === 'POST' && $roleIdParam === 0 || (($method === 'PUT' || $method === 'PATCH') && $roleIdParam > 0)) {
                $savedRole = ua_role_save($actorId, $roleIdParam, (string) ($adminBody['name'] ?? ''), (string) ($adminBody['description'] ?? ''), (array) ($adminBody['permissions'] ?? []));
                api_json($method === 'POST' ? 201 : 200, ['id' => $savedRole, 'roles' => ua_roles()]);
            }
            if ($method === 'DELETE' && $roleIdParam > 0) {
                ua_role_delete($roleIdParam);
                api_json(200, ['deleted' => $roleIdParam]);
            }
        }
        if ($path === 'admin/users' && $method === 'GET') {
            api_json(200, ['users' => ua_users(), 'you' => $actorId, 'you_are_admin' => ua_is_admin($actorId)]);
        }
        if (preg_match('#^admin/users(?:/(\d+))?$#', $path, $um) === 1) {
            $userIdParam = isset($um[1]) ? (int) $um[1] : 0;
            if ($method === 'GET' && $userIdParam > 0) {
                api_json(200, ua_user_get($actorId, $userIdParam));
            }
            if (($method === 'POST' && $userIdParam === 0) || (($method === 'PUT' || $method === 'PATCH') && $userIdParam > 0)) {
                $savedUser = ua_user_save(
                    $actorId,
                    $userIdParam,
                    (string) ($adminBody['username'] ?? ''),
                    (string) ($adminBody['password'] ?? ''),
                    (int) ($adminBody['role_id'] ?? 0),
                    (array) ($adminBody['overrides'] ?? [])
                );
                api_json($method === 'POST' ? 201 : 200, ['id' => $savedUser, 'users' => ua_users()]);
            }
            if ($method === 'DELETE' && $userIdParam > 0) {
                ua_user_delete($actorId, $userIdParam);
                api_json(200, ['deleted' => $userIdParam]);
            }
        }
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    api_error(404, 'Unbekannter Endpunkt.');
}

// ── Weitere Verwaltungsfunktionen der Desktop-App ─────────────────────────
if ($path === 'auth/password' && $method === 'POST') {
    if ($apiUser === null) {
        api_error(403, 'Das Passwort lässt sich nur mit Benutzeranmeldung ändern.');
    }
    $pwBody = json_decode((string) api_body(), true);
    require_once __DIR__ . '/../includes/manage_api.php';
    try {
        mg_password_change((int) $apiUser['id'], (string) ($pwBody['current_password'] ?? ''), (string) ($pwBody['new_password'] ?? ''), (int) $apiUser['session_id']);
        api_json(200, ['ok' => true]);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
}

// Camps zum Ankreuzen im Mitgliedsformular (lesen); "options" = vollständige Auswahlliste (fest + weitere)
// für Filter/Massenzuweisung, Wert => Beschriftung (siehe camps_options()).
if ($path === 'camps' && $method === 'GET') {
    require_once __DIR__ . '/../includes/camps.php';
    api_json(200, ['camps' => camps_all(), 'fixed' => CAMPS_FIXED_NAMES, 'options' => camps_options()]);
}

// Persönlicher Zugangslink: GET = ansehen, POST {action: regenerate_link|regenerate_password|send_email|reset_verification}
// für Spieler (members/{id}/link) und Staff (staff/{id}/link)
if (preg_match('#^(members|staff)/(\d+)/link$#', $path, $lm) === 1 && in_array($method, ['GET', 'POST'], true)) {
    require_once __DIR__ . '/../includes/manage_api.php';
    $linkAction = '';
    if ($method === 'POST') {
        if (!$canWrite) {
            api_error(403, 'Dieser Zugang hat nur Leserechte.');
        }
        $linkBody = json_decode((string) api_body(), true);
        $linkAction = in_array($linkBody['action'] ?? '', ['regenerate_link', 'regenerate_password', 'send_email', 'reset_verification'], true) ? (string) $linkBody['action'] : '';
    }
    try {
        api_json(200, mg_person_link($lm[1], (int) $lm[2], $linkAction));
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
}

// Auskunft (Art. 15/20): alle gespeicherten Daten einer Person als JSON (Recht "dsgvo.manage")
if (preg_match('#^(members|staff)/(\d+)/dsgvo$#', $path, $dm) === 1 && $method === 'GET') {
    require_once __DIR__ . '/../includes/dsgvo.php';
    try {
        app_log('privacy.export', 'Auskunft erstellt (Desktop-App)', ['target_type' => $dm[1] === 'staff' ? 'staff' : 'member', 'target_id' => (int) $dm[2]]);
        api_json(200, dsgvo_export($dm[1], (int) $dm[2]));
    } catch (RuntimeException $e) {
        api_error(404, $e->getMessage());
    }
}

// Bestätigung zurücksetzen: POST {ids:[..]}  -> {reset: n}
// Massenmail (Link + neuer Zugangscode): POST {ids:[..]} (höchstens 10 pro Aufruf) -> {results:[{id,name,status,message}]}
if (preg_match('#^(members|staff)/(verification/reset|send-links)$#', $path, $vm) === 1 && $method === 'POST') {
    if (!$canWrite) {
        api_error(403, 'Dieser Zugang hat nur Leserechte.');
    }
    require_once __DIR__ . '/../includes/verification.php';
    $vBody = json_decode((string) api_body(), true);
    $vIds = is_array($vBody) && is_array($vBody['ids'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $vBody['ids']), static fn (int $i) => $i > 0))) : [];
    if ($vIds === []) {
        api_error(422, '"ids" muss eine nicht leere Liste von IDs sein.');
    }
    try {
        if ($vm[2] === 'send-links') {
            if (count($vIds) > 10) {
                api_error(422, 'Höchstens 10 Empfänger pro Aufruf.');
            }
            $vNote = is_array($vBody) && is_string($vBody['note'] ?? null) ? trim($vBody['note']) : '';
            api_json(200, ['results' => verif_send_many($vm[1], $vIds, $vNote !== '' ? $vNote : null)]);
        }
        api_json(200, ['reset' => verif_reset($vm[1], $vIds)]);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
}

// Camp-Massenzuweisung: POST {ids:[..], camp:"camp_1"|"c12", action:"add"|"remove"} -> {changed: n}
if (preg_match('#^(members|staff)/camp-assign$#', $path, $cam) === 1 && $method === 'POST') {
    if (!$canWrite) {
        api_error(403, 'Dieser Zugang hat nur Leserechte.');
    }
    require_once __DIR__ . '/../includes/camps.php';
    $caBody = json_decode((string) api_body(), true);
    $caIds = is_array($caBody) && is_array($caBody['ids'] ?? null) ? array_values(array_unique(array_filter(array_map('intval', $caBody['ids']), static fn (int $i) => $i > 0))) : [];
    $caCamp = is_array($caBody) ? (string) ($caBody['camp'] ?? '') : '';
    $caAdd = !is_array($caBody) || ($caBody['action'] ?? 'add') !== 'remove';
    if ($caIds === [] || !array_key_exists($caCamp, camps_options())) {
        api_error(422, '"ids" (nicht leer) und ein gültiges "camp" werden benötigt.');
    }
    $changed = camp_bulk_assign($cam[1], $caIds, $caCamp, $caAdd);
    app_log(($cam[1] === 'staff' ? 'staff' : 'member') . '.camp_bulk', 'Camp-Massenzuweisung (Desktop-App): ' . $changed . ' ' . ($caAdd ? 'zugewiesen' : 'entfernt'), ['entity' => $cam[1], 'camp' => $caCamp, 'add' => $caAdd, 'count' => $changed]);
    api_json(200, ['changed' => $changed]);
}

if (str_starts_with($path, 'manage/')) {
    if ($apiUser === null) {
        api_error(403, 'Diese Verwaltung ist nur mit Benutzeranmeldung möglich.');
    }
    require_once __DIR__ . '/../includes/manage_api.php';
    $denied = static function (string $permission): never {
        api_error(403, 'Keine Berechtigung: ' . (permissions_registry()[$permission][0] ?? $permission));
    };
    $manageBody = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? json_decode((string) api_body(), true) : null;

    try {
        // Feld-Rechte
        if ($path === 'manage/field-permissions') {
            if (!$apiCan('fields.manage')) {
                $denied('fields.manage');
            }
            if ($method === 'GET') {
                api_json(200, ['fields' => mg_field_permissions()]);
            }
            if ($method === 'PUT' && is_array($manageBody)) {
                $changed = mg_field_permissions_save((array) ($manageBody['fields'] ?? []), (string) $apiUser['username']);
                api_json(200, ['changed' => $changed, 'fields' => mg_field_permissions()]);
            }
        }
        if ($path === 'manage/field-permissions/reset' && $method === 'POST') {
            if (!$apiCan('fields.manage')) {
                $denied('fields.manage');
            }
            field_access_reset();
            app_log('permissions.reset', 'Feld-Rechte auf Standard zurückgesetzt (Desktop-App)', [], 'warning');
            api_json(200, ['fields' => mg_field_permissions()]);
        }

        // Camps
        if (preg_match('#^manage/camps(?:/(\d+))?$#', $path, $cm) === 1) {
            if (!$apiCan('camps.manage')) {
                $denied('camps.manage');
            }
            $campId = isset($cm[1]) ? (int) $cm[1] : 0;
            if ($method === 'GET' && $campId === 0) {
                api_json(200, ['fixed' => CAMPS_FIXED_NAMES, 'camps' => mg_camps()]);
            }
            if ($method === 'POST' && $campId === 0 && is_array($manageBody)) {
                camp_create((string) ($manageBody['name'] ?? ''));
                api_json(201, ['camps' => mg_camps()]);
            }
            if (($method === 'PUT' || $method === 'PATCH') && $campId > 0 && is_array($manageBody)) {
                camp_rename($campId, (string) ($manageBody['name'] ?? ''));
                api_json(200, ['camps' => mg_camps()]);
            }
            if ($method === 'DELETE' && $campId > 0) {
                if (!$apiCan('camps.delete')) {
                    $denied('camps.delete');
                }
                camp_delete($campId);
                api_json(200, ['camps' => mg_camps()]);
            }
        }

        // API-Zugänge (Token für PC-Anwendungen, siehe admin/api.php)
        if (preg_match('#^manage/api-tokens(?:/(\d+))?$#', $path, $tm) === 1) {
            if (!$apiCan('api.manage')) {
                $denied('api.manage');
            }
            require_once __DIR__ . '/../includes/api_tokens.php';
            $tokenId = isset($tm[1]) ? (int) $tm[1] : 0;
            if ($method === 'GET' && $tokenId === 0) {
                api_json(200, ['tokens' => api_token_list()]);
            }
            if ($method === 'POST' && $tokenId === 0 && is_array($manageBody)) {
                $tName = trim((string) ($manageBody['name'] ?? ''));
                if ($tName === '' || mb_strlen($tName) > 100) {
                    api_error(422, 'Bitte eine Bezeichnung angeben (max. 100 Zeichen).');
                }
                $tNewToken = api_token_create($tName, !empty($manageBody['can_write']), (int) $apiUser['id']);
                app_log('api_token.create', 'API-Zugang erstellt (Desktop-App)', ['name' => $tName, 'write' => !empty($manageBody['can_write'])]);
                api_json(201, ['token' => $tNewToken, 'tokens' => api_token_list()]);
            }
            if ($method === 'DELETE' && $tokenId > 0) {
                api_token_delete($tokenId);
                app_log('api_token.delete', 'API-Zugang widerrufen (Desktop-App)', ['target_type' => 'api_token', 'target_id' => $tokenId], 'warning');
                api_json(200, ['tokens' => api_token_list()]);
            }
        }

        // Pflichtfelder bei der Selbstanmeldung (Spieler/Staff), siehe admin/registration-fields.php
        if ($path === 'manage/registration-fields' && in_array($method, ['GET', 'PUT'], true)) {
            if (!$apiCan('members.registrations')) {
                $denied('members.registrations');
            }
            require_once __DIR__ . '/../includes/registration_fields.php';
            if ($method === 'PUT' && is_array($manageBody)) {
                $rfType = ($manageBody['type'] ?? '') === 'staff' ? 'staff' : 'player';
                $rfKeys = is_array($manageBody['required'] ?? null) ? array_values(array_filter(array_map('strval', $manageBody['required']))) : [];
                registration_required_keys_set($rfType, $rfKeys);
            }
            $rfBuild = static fn (string $type): array => ['registry' => registration_field_labels($type), 'required' => registration_required_keys($type)];
            api_json(200, ['player' => $rfBuild('player'), 'staff' => $rfBuild('staff')]);
        }

        // Protokoll
        if ($path === 'manage/logs' && $method === 'GET') {
            if (!$apiCan('logs.view')) {
                $denied('logs.view');
            }
            api_json(200, mg_logs($_GET));
        }
        if ($path === 'manage/logs.csv' && $method === 'GET') {
            if (!$apiCan('logs.view')) {
                $denied('logs.view');
            }
            mg_logs_csv($_GET);
        }
        if ($path === 'manage/logs/purge' && $method === 'POST' && is_array($manageBody)) {
            if (!$apiCan('logs.purge')) {
                $denied('logs.purge');
            }
            $days = (int) ($manageBody['days'] ?? 0);
            $deleted = log_purge($days);
            app_log('log.purge', $days > 0 ? "Protokoll bereinigt (älter als {$days} Tage, Desktop-App)" : 'Protokoll komplett geleert (Desktop-App)', ['deleted' => $deleted, 'days' => $days], 'warning');
            api_json(200, ['deleted' => $deleted]);
        }
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    api_error(404, 'Unbekannter Endpunkt.');
}

if ($path === '' || $path === 'ping') {
    api_json(200, ['ok' => true, 'token' => $auth['name'], 'write' => $canWrite]
        + ($apiUser !== null ? ['user' => api_user_info($apiUser['id'], $apiUser['username'], $apiUser['role'])] : []));
}

if ($path === 'members.csv' && $method === 'GET') {
    $status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mitglieder-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    member_export_csv($out, member_all($status, $kaderFilter), $GLOBALS['api_hidden_keys'] ?? []);
    fclose($out);
    exit;
}

$requireWrite = static function () use ($canWrite): void {
    if (!$canWrite) {
        api_error(403, 'Dieser Token hat nur Leserechte.');
    }
};

if ($path === 'template.csv' && $method === 'GET') {
    $templateStaff = ($_GET['entity'] ?? '') === 'staff';
    if ($templateStaff) {
        require_once __DIR__ . '/../includes/staff.php';
        io_entity('staff');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($templateStaff ? 'staff' : 'mitglieder') . '-vorlage.csv"');
    $out = fopen('php://output', 'w');
    member_export_csv($out, []);
    fclose($out);
    exit;
}

// Staff als CSV (wie der Staff-Export im Admin-Bereich)
if ($path === 'staff.csv' && $method === 'GET') {
    if (!$apiCan('staff.view') || !$apiCan('members.export')) {
        api_error(403, 'Keine Berechtigung: Staff ansehen und Export');
    }
    require_once __DIR__ . '/../includes/staff.php';
    staff_ensure_table(db());
    io_entity('staff');
    $staffStatus = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    member_export_csv($out, staff_all($staffStatus));
    fclose($out);
    exit;
}

// Lizenzprüfung der Desktop-Anwendung: POST /license/validate {"key","machine_id","machine_name","app_version"}
// Antwort immer mit HTTP 200: {"valid": true/false, "reason", "message", ...}. Auch mit Lese-Token erlaubt.
if ($path === 'license/validate' && $method === 'POST') {
    require_once __DIR__ . '/../includes/licenses.php';
    $licBody = json_decode((string) api_body(), true);
    if (!is_array($licBody)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit key, machine_id, machine_name und app_version.');
    }
    try {
        $licResult = license_check(
            (string) ($licBody['key'] ?? ''),
            (string) ($licBody['machine_id'] ?? ''),
            (string) ($licBody['machine_name'] ?? ''),
            (string) ($licBody['app_version'] ?? ''),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
    } catch (RuntimeException $e) {
        api_error(500, $e->getMessage());
    }
    if (empty($licResult['valid'])) {
        app_log('license.denied', 'Lizenzprüfung abgelehnt: ' . ($licResult['reason'] ?? ''), ['machine' => (string) ($licBody['machine_name'] ?? '')], 'warning');
    }
    api_json(200, $licResult);
}

// Roster als Datei: GET /roster.pdf|xlsx (alphabetisch) und /roster-ifaf.pdf|xlsx?competition=&game=&team=
if (preg_match('#^roster(-ifaf|-bekleidung|-vereine|-fehlend|-abgelaufen|-staff)?\.(pdf|xlsx)$#', $path, $rm) === 1 && $method === 'GET') {
    require_once __DIR__ . '/../includes/roster.php';
    try {
        if ($rm[1] === '-ifaf') {
            $opt = [
                'competition' => mb_substr(trim((string) ($_GET['competition'] ?? 'IFAF European Championship 2026/27')), 0, 120),
                'game' => mb_substr(trim((string) ($_GET['game'] ?? '')), 0, 120),
                'team' => mb_substr(trim((string) ($_GET['team'] ?? 'Austria')), 0, 120),
            ];
            [$contentType, $filename, $binary] = roster_generate_ifaf($rm[2], $opt);
        } else {
            $kader = in_array($_GET['kader'] ?? 'kader', ['kader', 'nicht_im_kader'], true) ? (string) ($_GET['kader'] ?? 'kader') : null;
            $status = ($_GET['status'] ?? 'aktiv') === 'alle' ? null : 'aktiv';
            $generate = ['-bekleidung' => 'roster_generate_clothing', '-vereine' => 'roster_generate_clubs', '-fehlend' => 'roster_generate_missing', '-abgelaufen' => 'roster_generate_expired', '-staff' => 'roster_generate_staff'][$rm[1]] ?? 'roster_generate_alphabetical';
            [$contentType, $filename, $binary] = $generate($rm[2], $kader, $status, $GLOBALS['api_hidden_keys'] ?? []);
        }
    } catch (Throwable $e) {
        app_log('export.roster_failed', 'Roster konnte nicht erstellt werden: ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()], 'error');
        api_error(500, 'Die Liste konnte nicht erstellt werden: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
    app_log('export.roster', 'Roster per API erstellt (' . $rm[0] . ')', []);
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store');
    echo $binary;
    exit;
}

// Import einer CSV/XLSX-Datei (multipart: file, update_existing, commit=1 zum Speichern, sonst nur Vorschau)
if ($path === 'import' && $method === 'POST') {
    $requireWrite();
    // Spieler (Standard) oder Staff importieren
    if (($_POST['entity'] ?? '') === 'staff') {
        if (!$apiCan('staff.edit')) {
            api_error(403, 'Keine Berechtigung: ' . (permissions_registry()['staff.edit'][0] ?? 'staff.edit'));
        }
        require_once __DIR__ . '/../includes/staff.php';
        staff_ensure_table(db());
        io_entity('staff');
    }
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

// "Fehlt"-Markierung der Pflichtdokumente: POST/PUT /members/{id}/document-flags  {"nada": true, "ecard": false, ...}
if (preg_match('#^members/(\d+)/document-flags$#', $path, $fm) === 1 && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $requireWrite();
    $flagId = (int) $fm[1];
    if (member_find_by_id($flagId) === false) {
        api_error(404, 'Mitglied nicht gefunden.');
    }
    $flagBody = json_decode((string) api_body(), true);
    if (!is_array($flagBody)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt, z. B. {"nada": true}.');
    }
    $flagData = [];
    foreach (array_keys(MEMBER_DOCUMENT_REQUIRED) as $reqType) {
        if (array_key_exists($reqType, $flagBody)) {
            $flagData['fehlt_' . $reqType] = $flagBody[$reqType] === true ? 1 : 0;
        }
    }
    if ($flagData === []) {
        api_error(422, 'Keine bekannten Dokumenttypen (nada, pass, ecard, rechte).');
    }
    upsert_child_row('member_documents', DOCUMENT_COLUMNS, $flagId, $flagData, false);
    app_log('document.flag', '"Fehlt"-Markierung per API geändert', ['target_type' => 'member', 'target_id' => $flagId, 'flags' => $flagData]);
    api_json(200, api_member(member_find_by_id($flagId)));
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
    $body = json_decode((string) api_body(), true);
    if (!is_array($body)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit "ids" oder "all" und "confirm".');
    }

    try {
        if (($body['all'] ?? false) === true) {
            if (!$apiCan('members.delete_all')) {
                api_error(403, 'Keine Berechtigung: ' . (permissions_registry()['members.delete_all'][0] ?? 'members.delete_all'));
            }
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

// Staff-Dokumente (freiwillig): GET/POST/DELETE /staff/{id}/documents/rechte|pass|ecard
if (preg_match('#^staff/(\d+)/documents/(rechte|pass|ecard)$#', $path, $sdm) === 1) {
    require_once __DIR__ . '/../includes/staff.php';
    $sid = (int) $sdm[1];
    $sType = $sdm[2];
    try {
        staff_ensure_table(db());
        $sRow = staff_find_by_id($sid);
        if ($sRow === false) {
            api_error(404, 'Person nicht gefunden.');
        }
        if ($method === 'GET') {
            if (staff_document_path($sRow, $sType) === null) {
                api_error(404, 'Dokument nicht vorhanden.');
            }
            staff_document_send($sRow, $sType, false);
        }
        if ($method === 'POST') {
            $requireWrite();
            if (!isset($_FILES['file'])) {
                api_error(400, 'Keine Datei übermittelt (Feld "file").');
            }
            staff_set_document($sid, $sType, 'file');
            app_log('document.upload', 'Staff-Dokument per API hochgeladen', ['target_type' => 'staff', 'target_id' => $sid, 'type' => $sType]);
            api_json(200, ['dokumente' => staff_documents_present(staff_find_by_id($sid))]);
        }
        if ($method === 'DELETE') {
            $requireWrite();
            staff_set_document($sid, $sType, null);
            app_log('document.delete', 'Staff-Dokument per API entfernt', ['target_type' => 'staff', 'target_id' => $sid, 'type' => $sType]);
            api_json(200, ['dokumente' => staff_documents_present(staff_find_by_id($sid))]);
        }
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    header('Allow: GET, POST, DELETE');
    api_error(405, 'Methode nicht erlaubt.');
}

// Staff (Coaches/Betreuer): GET /staff, GET /staff/{id}, POST /staff, PUT /staff/{id}, DELETE /staff/{id}
if (preg_match('#^staff(?:/(\d+))?$#', $path, $sm) === 1) {
    require_once __DIR__ . '/../includes/staff.php';
    require_once __DIR__ . '/../includes/verification.php';
    $staffId = isset($sm[1]) ? (int) $sm[1] : null;

    $apiStaff = static function (array $row): array {
        $out = ['id' => (int) $row['id']];
        foreach (STAFF_IO_COLUMNS as $key => [$label, $type]) {
            $value = $row[$key] ?? null;
            $out[$key] = $type === 'bool' ? (int) $value === 1 : ($value === '' ? null : $value);
        }
        $out['name_vorname'] = trim((string) ($row['nachname'] ?? '') . ' ' . (string) ($row['vorname'] ?? ''));
        $out['dokumente'] = staff_documents_present($row);
        static $verifiedMap = null;
        $verifiedMap ??= staff_verified_map();
        $out['bestaetigt_am'] = $verifiedMap[(int) $row['id']] ?? null;
        return $out;
    };
    $readStaffBody = static function (): array {
        $body = json_decode((string) api_body(), true);
        if (!is_array($body) || $body === [] || array_is_list($body)) {
            api_error(400, 'Erwartet wird ein JSON-Objekt mit Staff-Feldern im Request-Body.');
        }
        return $body;
    };

    try {
        staff_ensure_table(db());
        io_entity('staff');

        if ($method === 'GET' && $staffId === null) {
            $q = trim((string) ($_GET['q'] ?? ''));
            $status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;
            $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            api_json(200, [
                'total' => staff_count($q, $status),
                'limit' => $limit,
                'offset' => $offset,
                'data' => array_map($apiStaff, staff_search($q, $limit, $offset, $status)),
            ]);
        }
        if ($method === 'GET') {
            $row = staff_find_by_id($staffId);
            $row === false ? api_error(404, 'Person nicht gefunden.') : api_json(200, $apiStaff($row));
        }
        if ($method === 'POST' && $staffId === null) {
            $requireWrite();
            ['data' => $data, 'errors' => $errors] = io_convert_row($readStaffBody());
            foreach (io_required_keys() as $required) {
                if (!isset($data[$required])) {
                    $errors[] = member_io_columns()[$required][0] . ' ist ein Pflichtfeld';
                }
            }
            if ($errors !== []) {
                api_error(422, 'Ungültige Daten.', $errors);
            }
            $newId = staff_upsert($data, null);
            app_log('staff.create', 'Staff per API angelegt', ['target_type' => 'staff', 'target_id' => $newId]);
            api_json(201, $apiStaff(staff_find_by_id($newId)));
        }
        if (($method === 'PUT' || $method === 'PATCH') && $staffId !== null) {
            $requireWrite();
            if (staff_find_by_id($staffId) === false) {
                api_error(404, 'Person nicht gefunden.');
            }
            ['data' => $data, 'errors' => $errors] = io_convert_row($readStaffBody(), true);
            if ($errors !== []) {
                api_error(422, 'Ungültige Daten.', $errors);
            }
            staff_upsert($data, $staffId);
            app_log('staff.update', 'Staff per API geändert', ['target_type' => 'staff', 'target_id' => $staffId, 'fields' => array_keys($data)]);
            api_json(200, $apiStaff(staff_find_by_id($staffId)));
        }
        if ($method === 'DELETE' && $staffId !== null) {
            $requireWrite();
            if (staff_find_by_id($staffId) === false) {
                api_error(404, 'Person nicht gefunden.');
            }
            staff_delete_many([$staffId]);
            app_log('staff.delete', 'Staff per API gelöscht', ['target_type' => 'staff', 'target_id' => $staffId], 'warning');
            api_json(200, ['deleted' => $staffId]);
        }
        header('Allow: GET, POST, PUT, PATCH, DELETE');
        api_error(405, 'Methode nicht erlaubt.');
    } catch (RuntimeException $e) {
        api_error(409, $e->getMessage());
    } catch (Throwable $e) {
        api_error(500, APP_DEBUG ? $e->getMessage() : 'Interner Serverfehler.');
    }
}

// ── Neue Mitglieder: ausstehende Anmeldungen, Registrierungslinks, Benachrichtigungs-Adresse ──
if ($path === 'registrations' && $method === 'GET') {
    require_once __DIR__ . '/../includes/registration.php';
    api_json(200, ['data' => array_map('api_member', member_registrations_pending())]);
}

// Anmeldung übernehmen (Kader / nicht im Kader) oder ablehnen (löschen)
if (preg_match('#^registrations/(\d+)/(approve|reject)$#', $path, $rgm) === 1 && $method === 'POST') {
    $requireWrite();
    require_once __DIR__ . '/../includes/registration.php';
    $rgId = (int) $rgm[1];
    if ($rgm[2] === 'approve') {
        $rgBody = json_decode((string) api_body(), true);
        // "target": kader (Standard) | nicht_im_kader | staff (legt eine neue Staff-Person an, siehe unten)
        $rgTarget = is_array($rgBody) ? (string) ($rgBody['target'] ?? $rgBody['kader'] ?? 'kader') : 'kader';
        if (!in_array($rgTarget, ['kader', 'nicht_im_kader', 'staff'], true)) {
            $rgTarget = 'kader';
        }
        if ($rgTarget === 'staff') {
            $rgResult = member_registration_approve_as_staff($rgId);
            if (!$rgResult['ok']) {
                api_error(409, $rgResult['message']);
            }
            api_json(200, ['target' => 'staff', 'staff_id' => $rgResult['staff_id']]);
        }
        if (!member_registration_approve($rgId, $rgTarget)) {
            api_error(404, 'Anmeldung nicht gefunden oder bereits bearbeitet.');
        }
        api_json(200, ['target' => $rgTarget] + api_member(member_find_by_id($rgId)));
    }
    if (!member_registration_reject($rgId)) {
        api_error(404, 'Anmeldung nicht gefunden oder bereits bearbeitet.');
    }
    api_json(200, ['deleted' => $rgId]);
}

// Ausstehende Staff-Anmeldungen (eigener Staff-Einladungslink): GET Liste, approve/reject
if ($path === 'registrations/staff' && $method === 'GET' || preg_match('#^registrations/staff/(\d+)/(approve|reject)$#', $path, $rsgm) === 1) {
    require_once __DIR__ . '/../includes/staff.php';
    require_once __DIR__ . '/../includes/registration.php';
    $apiRegStaff = static function (array $row): array {
        $out = ['id' => (int) $row['id']];
        foreach (STAFF_IO_COLUMNS as $key => [$label, $type]) {
            $value = $row[$key] ?? null;
            $out[$key] = $type === 'bool' ? (int) $value === 1 : ($value === '' ? null : $value);
        }
        $out['name_vorname'] = trim((string) ($row['nachname'] ?? '') . ' ' . (string) ($row['vorname'] ?? ''));
        $out['created_at'] = $row['created_at'] ?? null;
        return $out;
    };

    if ($path === 'registrations/staff') {
        api_json(200, ['data' => array_map($apiRegStaff, staff_registration_pending())]);
    }

    $requireWrite();
    $rsgId = (int) $rsgm[1];
    if ($rsgm[2] === 'approve') {
        if (!staff_registration_approve($rsgId)) {
            api_error(404, 'Anmeldung nicht gefunden oder bereits bearbeitet.');
        }
        $rsgRow = staff_find_by_id($rsgId);
        api_json(200, $rsgRow !== false ? $apiRegStaff($rsgRow) : ['id' => $rsgId]);
    }
    if (!staff_registration_reject($rsgId)) {
        api_error(404, 'Anmeldung nicht gefunden oder bereits bearbeitet.');
    }
    api_json(200, ['deleted' => $rsgId]);
}

// Registrierungslinks: GET Liste, POST anlegen, PUT/PATCH aktivieren/deaktivieren, DELETE löschen
if (preg_match('#^registration-links(?:/(\d+))?$#', $path, $rlm) === 1) {
    require_once __DIR__ . '/../includes/registration.php';
    $rlId = isset($rlm[1]) ? (int) $rlm[1] : null;
    $apiLink = static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'token' => (string) $row['token'],
            'url' => registration_build_url((string) $row['token']),
            'label' => $row['label'],
            'link_type' => ($row['link_type'] ?? 'player') === 'staff' ? 'staff' : 'player',
            'active' => (int) $row['active'] === 1,
            'created_by' => $row['created_by'],
            'created_at' => $row['created_at'],
            'expires_at' => $row['expires_at'],
            'use_count' => (int) $row['use_count'],
            'last_used_at' => $row['last_used_at'],
        ];
    };

    if ($method === 'GET' && $rlId === null) {
        api_json(200, ['data' => array_map($apiLink, registration_links_all())]);
    }
    if ($method === 'POST' && $rlId === null) {
        $requireWrite();
        $rlBody = json_decode((string) api_body(), true);
        $rlLabel = is_array($rlBody) ? trim((string) ($rlBody['label'] ?? '')) : '';
        $rlExpires = is_array($rlBody) && !empty($rlBody['expires_at']) ? (string) $rlBody['expires_at'] . ' 23:59:59' : null;
        $rlType = is_array($rlBody) && ($rlBody['link_type'] ?? '') === 'staff' ? 'staff' : 'player';
        $created = registration_link_create($rlLabel, $apiUser['username'] ?? $auth['name'], $rlExpires, $rlType);
        $newLinks = array_values(array_filter(registration_links_all(), static fn (array $r) => (int) $r['id'] === $created['id']));
        api_json(201, $newLinks !== [] ? $apiLink($newLinks[0]) : ['id' => $created['id'], 'token' => $created['token']]);
    }
    if (($method === 'PUT' || $method === 'PATCH') && $rlId !== null) {
        $requireWrite();
        $rlBody = json_decode((string) api_body(), true);
        registration_link_set_active($rlId, !(is_array($rlBody) && ($rlBody['active'] ?? true) === false));
        $updated = array_values(array_filter(registration_links_all(), static fn (array $r) => (int) $r['id'] === $rlId));
        $updated === [] ? api_error(404, 'Link nicht gefunden.') : api_json(200, $apiLink($updated[0]));
    }
    if ($method === 'DELETE' && $rlId !== null) {
        $requireWrite();
        registration_link_delete($rlId);
        api_json(200, ['deleted' => $rlId]);
    }
    header('Allow: GET, POST, PUT, PATCH, DELETE');
    api_error(405, 'Methode nicht erlaubt.');
}

// Benachrichtigungs-Adresse: an sie geht eine E-Mail bei neuen bzw. doppelten Anmeldungen
if ($path === 'registration-settings') {
    require_once __DIR__ . '/../includes/registration.php';
    if ($method === 'GET') {
        api_json(200, ['notify_email' => registration_notify_email()]);
    }
    if (in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
        $requireWrite();
        $rsBody = json_decode((string) api_body(), true);
        $rsEmail = is_array($rsBody) ? trim((string) ($rsBody['notify_email'] ?? '')) : '';
        if ($rsEmail !== '' && !is_valid_email($rsEmail)) {
            api_error(422, 'Bitte eine gültige E-Mail-Adresse angeben (oder leer lassen, um keine Benachrichtigungen zu erhalten).');
        }
        registration_notify_email_set($rsEmail);
        api_json(200, ['notify_email' => registration_notify_email()]);
    }
    header('Allow: GET, PUT, PATCH, POST');
    api_error(405, 'Methode nicht erlaubt.');
}

if (!preg_match('#^members(?:/(\d+))?$#', $path, $m)) {
    api_error(404, 'Unbekannter Endpunkt.');
}
$id = isset($m[1]) ? (int) $m[1] : null;

/** @return array<string, mixed> */
$readBody = static function (): array {
    $body = json_decode((string) api_body(), true);
    if (!is_array($body) || $body === [] || array_is_list($body)) {
        api_error(400, 'Erwartet wird ein JSON-Objekt mit Mitgliedsfeldern im Request-Body.');
    }
    // Feld-Rechte: für Bearbeiter ausgeblendete Felder lassen sich nicht ändern
    return array_diff_key($body, array_flip($GLOBALS['api_hidden_keys'] ?? []));
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
