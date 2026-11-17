<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/fleet.php';
require_once __DIR__ . '/includes/timetable.php';

ini_set('memory_limit', '512M');
set_time_limit(0);

clear_expired_holds();
$db->query(
    "UPDATE schedules
     SET status = 'completed'
     WHERE status = 'scheduled'
       AND (travel_date < CURDATE()
         OR (travel_date = CURDATE() AND depart_time < CURTIME()))"
);
$db->query(
    "UPDATE bookings b
     JOIN schedules s ON s.id = b.schedule_id
     SET b.booking_status = 'completed'
     WHERE b.booking_status = 'confirmed' AND s.status = 'completed'"
);

$slots = ensure_weekly_slots();
$rolled = roll_dated_schedules(7);
qexec(
    "INSERT INTO settings (skey, svalue) VALUES ('schedule_roll', ?)
     ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)",
    's',
    [qone('SELECT CURDATE() d')['d']]
);
echo "Weekly slots checked (+{$slots}). Dated trips rolled (+{$rolled}).\n";

if (!fleet_lock(2)) {
    throw new RuntimeException('Could not obtain the fleet maintenance lock.');
}
try {
    ensure_vehicle_day_offs(false);
    ensure_real_fleet();
    assign_open_fleet();
} finally {
    fleet_unlock();
}

echo "Project-X maintenance completed.\n";
echo "Day off is per vehicle (staggered). Modes run every day.\n";
foreach (['buses' => 'bus', 'trains' => 'train', 'planes' => 'plane'] as $table => $label) {
    $distinct = (int) qone("SELECT COUNT(DISTINCT day_off) c FROM {$table} WHERE status = 'active'")['c'];
    echo "  {$label}: {$distinct} distinct rest days\n";
}
