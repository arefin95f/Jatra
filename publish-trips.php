<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/timetable.php';

ini_set('memory_limit', '512M');

$before = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'")['svalue'] ?? '');
ensure_upcoming_trips();
$after = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'")['svalue'] ?? '');
$openDays = (int) (qone(
    "SELECT COUNT(DISTINCT travel_date) c
     FROM schedules
     WHERE status = 'scheduled'
       AND travel_date >= CURDATE()
       AND travel_date <= DATE_ADD(CURDATE(), INTERVAL 6 DAY)"
)['c'] ?? 0);

echo date('Y-m-d H:i:s') . " schedule_roll {$before} -> {$after}, open_days {$openDays}\n";
