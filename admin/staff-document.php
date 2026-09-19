<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff.php';

require_permission('staff.view');
require_permission('documents.view');

$row = staff_find_by_id((int) ($_GET['id'] ?? 0));
if ($row === false) {
    http_response_code(404);
    exit('Person nicht gefunden.');
}

app_log('document.view', 'Staff-Dokument angesehen', ['target_type' => 'staff', 'target_id' => $row['id'], 'type' => (string) ($_GET['type'] ?? '')]);
staff_document_send($row, (string) ($_GET['type'] ?? ''));
