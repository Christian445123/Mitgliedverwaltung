<?php

declare(strict_types=1);

/** Massenzuweisung: ein Camp für mehrere ausgewählte Mitglieder auf einmal zuweisen oder entfernen. */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/member_repository.php';
require_once __DIR__ . '/../includes/camps.php';

require_permission('members.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

verify_csrf();

$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i) => $i > 0)));
$camp = (string) ($_POST['camp'] ?? '');
$add = ($_POST['camp_action'] ?? 'add') !== 'remove';
$campOptions = camps_options();

if ($ids === [] || !array_key_exists($camp, $campOptions)) {
    flash_set('error', 'Bitte Mitglieder und ein Camp auswählen.');
    redirect('index.php');
}

$count = camp_bulk_assign('members', $ids, $camp, $add);
$campName = $campOptions[$camp];
app_log(
    'member.camp_bulk',
    ($add ? 'Camp zugewiesen: ' : 'Camp entfernt: ') . $campName . ' (' . $count . ' Mitglieder)',
    ['ids' => $ids, 'camp' => $camp, 'add' => $add]
);
flash_set('info', $count . ($count === 1 ? ' Mitglied: „' : ' Mitglieder: „') . $campName . '" ' . ($add ? 'zugewiesen.' : 'entfernt.'));
redirect('index.php');
