<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';

require_admin();

$member = member_find_by_id((int) ($_GET['id'] ?? 0));
if ($member === false) {
    http_response_code(404);
    exit('Mitglied nicht gefunden.');
}

member_document_send($member, (string) ($_GET['type'] ?? ''));
