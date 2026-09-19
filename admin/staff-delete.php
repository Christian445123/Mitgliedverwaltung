<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff.php';

require_permission('staff.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('staff.php');
}

verify_csrf();

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || $ids === []) {
    flash_set('error', 'Es wurde niemand ausgewählt.');
    redirect('staff.php');
}

$count = staff_delete_many($ids);
app_log('staff.delete', $count . ' Staff-Person(en) gelöscht', ['ids' => array_values($ids), 'deleted' => $count], 'warning');
flash_set('info', $count === 1 ? '1 Person wurde gelöscht.' : $count . ' Personen wurden gelöscht.');
redirect('staff.php');
