<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    member_delete($id);
    app_log('member.delete', 'Mitglied gelöscht', ['target_type' => 'member', 'target_id' => $id], 'warning');
    flash_set('info', 'Mitglied wurde gelöscht.');
}

redirect('index.php');
