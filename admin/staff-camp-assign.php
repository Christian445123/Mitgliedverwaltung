<?php

declare(strict_types=1);

/** Massenzuweisung: ein Camp für mehrere ausgewählte Staff-Personen auf einmal zuweisen oder entfernen. */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff.php';
require_once __DIR__ . '/../includes/camps.php';

require_permission('staff.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('staff.php');
}

verify_csrf();

$ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i) => $i > 0)));
$camp = (string) ($_POST['camp'] ?? '');
$add = ($_POST['camp_action'] ?? 'add') !== 'remove';
$campOptions = camps_options();

if ($ids === [] || !array_key_exists($camp, $campOptions)) {
    flash_set('error', 'Bitte Personen und ein Camp auswählen.');
    redirect('staff.php');
}

$count = camp_bulk_assign('staff', $ids, $camp, $add);
$campName = $campOptions[$camp];
app_log(
    'staff.camp_bulk',
    ($add ? 'Camp zugewiesen: ' : 'Camp entfernt: ') . $campName . ' (' . $count . ' Personen)',
    ['ids' => $ids, 'camp' => $camp, 'add' => $add]
);
flash_set('info', $count . ($count === 1 ? ' Person: „' : ' Personen: „') . $campName . '" ' . ($add ? 'zugewiesen.' : 'entfernt.'));
redirect('staff.php');
