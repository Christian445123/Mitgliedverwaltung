<?php

// Einstiegspunkt: leitet direkt in den Admin-Bereich weiter.
// Mitglieder erreichen ihre Daten ausschließlich über ihren persönlichen
// Link (mitglied-formular.php?token=...), den der Admin verschickt.

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

redirect(!empty($_SESSION['admin_id']) ? 'admin/index.php' : 'admin/login.php');
