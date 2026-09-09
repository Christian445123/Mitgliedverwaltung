<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

require_administrator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('users.php');
}

verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

if ($id === current_admin_id()) {
    flash_set('error', 'Du kannst dich nicht selbst löschen.');
    redirect('users.php');
}

$stmt = db()->prepare('SELECT role FROM admins WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

if ($target === false) {
    flash_set('error', 'Benutzer nicht gefunden.');
    redirect('users.php');
}

if ($target['role'] === 'administrator') {
    $countStmt = db()->prepare("SELECT COUNT(*) FROM admins WHERE role = 'administrator' AND id != ?");
    $countStmt->execute([$id]);
    if ((int) $countStmt->fetchColumn() === 0) {
        flash_set('error', 'Mindestens ein Administrator muss bestehen bleiben.');
        redirect('users.php');
    }
}

$delete = db()->prepare('DELETE FROM admins WHERE id = ?');
$delete->execute([$id]);

flash_set('info', 'Benutzer wurde gelöscht.');
redirect('users.php');
