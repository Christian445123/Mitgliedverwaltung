<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_import.php';

require_admin();

$template = isset($_GET['template']);
$status = in_array($_GET['status'] ?? '', ['aktiv', 'inaktiv'], true) ? $_GET['status'] : null;

$filename = $template ? 'mitglieder-vorlage.csv' : 'mitglieder-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
app_log('export.csv', $template ? 'Import-Vorlage heruntergeladen' : 'Mitglieder als CSV exportiert', ['status_filter' => $status]);

$out = fopen('php://output', 'w');
member_export_csv($out, $template ? [] : member_all($status));
fclose($out);
