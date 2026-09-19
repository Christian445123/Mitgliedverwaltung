<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_permission('members.delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || $ids === []) {
    flash_set('error', 'Es wurde kein Mitglied ausgewählt.');
    redirect('index.php');
}

$count = member_delete_many($ids);
app_log('member.delete_bulk', $count . ' Mitglieder gelöscht', ['ids' => array_values($ids), 'deleted' => $count], 'warning');
flash_set('info', $count === 1 ? '1 Mitglied wurde gelöscht.' : $count . ' Mitglieder wurden gelöscht.');
redirect('index.php');
