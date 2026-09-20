<?php

declare(strict_types=1);

/** Auskunft (Art. 15/20) zu einer Person als Datei (HTML oder JSON). */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dsgvo.php';

require_permission('dsgvo.manage');

$entity = dsgvo_entity((string) ($_GET['entity'] ?? 'members'));
$id = (int) ($_GET['id'] ?? 0);

try {
    $export = dsgvo_export($entity, $id);
} catch (RuntimeException $e) {
    flash_set('error', $e->getMessage());
    redirect('dsgvo.php?tab=anfragen');
}

app_log('privacy.export', 'Auskunft erstellt (' . $entity . ')', ['target_type' => $entity === 'staff' ? 'staff' : 'member', 'target_id' => $id]);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (($_GET['format'] ?? 'html') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="auskunft-' . $entity . '-' . $id . '.json"');
    echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} else {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="auskunft-' . $entity . '-' . $id . '.html"');
    echo dsgvo_export_html($export);
}
