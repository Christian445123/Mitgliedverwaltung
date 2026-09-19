<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_import.php';
require_once __DIR__ . '/../includes/field_access.php';

require_permission('members.export');

$template = isset($_GET['template']);
$status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;

$filename = $template ? 'mitglieder-vorlage.csv' : 'mitglieder-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
app_log('export.csv', $template ? 'Import-Vorlage heruntergeladen' : 'Mitglieder als CSV exportiert', ['status_filter' => $status]);

$out = fopen('php://output', 'w');
// Bearbeiter erhalten nur die Felder, die für sie freigegeben sind (Feld-Rechte)
$exclude = field_access_admin_audience() === 'admin' ? [] : field_access_hidden_export_keys('editor');
member_export_csv($out, $template ? [] : member_all($status), $exclude);
fclose($out);
