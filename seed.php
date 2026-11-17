<?php

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/places.php';

if (PHP_SAPI === 'cli') {
    require __DIR__ . '/includes/timetable.php';
    $network = sync_bangladesh_network();
    echo "Cities: {$network['cities']}. Routes added: {$network['routes_added']}.\n";
    $air = sync_rail_and_air();
    echo "Train and plane routes added: {$air['routes_added']}.\n";
    echo "Day off is per vehicle (staggered across the week).\n";
}

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line: php seed.php');
}

$existing = (int) qone('SELECT COUNT(*) c FROM bookings')['c'];
if ($existing > 0) {
    echo "Bookings already exist ($existing). Booking seed skipped.\n";
    exit(0);
}

$people = qall("SELECT id, full_name, email, phone FROM users WHERE role = 'passenger' ORDER BY id");
$routes = qall('SELECT id, base_fare FROM routes ORDER BY id');
$buses = qall('SELECT id, total_seats FROM buses ORDER BY id');
if (!$routes || !$buses) {
    echo "Import database.sql first.\n";
    exit(1);
}
if (!$people) {
    echo "No passenger accounts yet. Booking seed skipped.\n";
    exit(0);
}

$code = 10020;
$methods = ['mobile_money', 'card'];
$letters = ['A', 'B', 'C', 'D'];

for ($month = 1; $month <= 10; $month++) {
    $count = $month === 10 ? 16 : 11;
    for ($n = 0; $n < $count; $n++) {
        $route = $routes[($month + $n) % count($routes)];
        $bus = $buses[($n) % count($buses)];
        $person = $people[$n % count($people)];
        $day = ($n % ($month === 10 ? 16 : 26)) + 1;
        $hour = [6, 8, 14, 18][$n % 4];
        $created = sprintf('2026-%02d-%02d %02d:%02d:00', $month, $day, $hour, ($n * 5) % 60);
        $travel = sprintf('2026-%02d-%02d', $month, $day);
        $roll = $n % 10;
        $scheduleStatus = 'completed';
        $bookStatus = 'completed';
        $payStatus = 'paid';
        $method = $methods[$n % 2];
        if ($roll === 0) {
            $bookStatus = 'cancelled';
            $payStatus = 'refunded';
        }
        $scheduleId = qexec(
            'INSERT INTO schedules (route_id, bus_id, travel_date, depart_time, fare, status) VALUES (?, ?, ?, ?, ?, ?)',
            'iissds',
            [(int) $route['id'], (int) $bus['id'], $travel, sprintf('%02d:30:00', $hour), (float) $route['base_fare'], $scheduleStatus]
        );
        $seat = (1 + ($n % 10)) . $letters[$n % 4];
        $ref = ($method === 'card' ? 'CARD-' : 'MM-') . (240000 + $code);
        qexec(
            'INSERT INTO bookings
              (booking_code, user_id, schedule_id, passenger_name, passenger_phone, passenger_email, seat_label, amount, payment_method, payment_status, booking_status, payment_ref, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'siissssdsssss',
            [
                'BK' . $code,
                (int) $person['id'],
                $scheduleId,
                $person['full_name'],
                $person['phone'],
                $person['email'],
                $seat,
                (float) $route['base_fare'],
                $method,
                $payStatus,
                $bookStatus,
                $ref,
                $created,
            ]
        );
        $code++;
    }
}

echo "Seeded demo bookings.\n";
