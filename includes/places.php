<?php

function bd_cities(): array
{
    return [
        'Bagerhat' => [22.6516, 89.7859],
        'Bandarban' => [22.1953, 92.2184],
        'Barguna' => [22.1592, 90.1264],
        'Barishal' => [22.7010, 90.3535],
        'Bhola' => [22.6859, 90.6482],
        'Bogura' => [24.8465, 89.3773],
        'Brahmanbaria' => [23.9571, 91.1119],
        'Chandpur' => [23.2333, 90.6711],
        'Chapainawabganj' => [24.5965, 88.2775],
        'Chattogram' => [22.3569, 91.7832],
        'Chuadanga' => [23.6402, 88.8415],
        "Cox's Bazar" => [21.4272, 92.0058],
        'Cumilla' => [23.4607, 91.1809],
        'Dhaka' => [23.8103, 90.4125],
        'Dinajpur' => [25.6279, 88.6332],
        'Faridpur' => [23.6070, 89.8429],
        'Feni' => [23.0159, 91.3976],
        'Gaibandha' => [25.3290, 89.5430],
        'Gazipur' => [23.9999, 90.4203],
        'Gopalganj' => [23.0051, 89.8266],
        'Habiganj' => [24.3749, 91.4155],
        'Jamalpur' => [24.9375, 89.9378],
        'Jashore' => [23.1664, 89.2081],
        'Jhalokathi' => [22.6406, 90.1987],
        'Jhenaidah' => [23.5449, 89.1539],
        'Joypurhat' => [25.0968, 89.0227],
        'Khagrachhari' => [23.1193, 91.9847],
        'Khulna' => [22.8456, 89.5403],
        'Kishoreganj' => [24.4449, 90.7766],
        'Kurigram' => [25.8054, 89.6362],
        'Kushtia' => [23.9013, 89.1200],
        'Lakshmipur' => [22.9440, 90.8300],
        'Lalmonirhat' => [25.9923, 89.2847],
        'Madaripur' => [23.1641, 90.1897],
        'Magura' => [23.4873, 89.4198],
        'Manikganj' => [23.8644, 90.0047],
        'Meherpur' => [23.7622, 88.6318],
        'Moulvibazar' => [24.4829, 91.7774],
        'Munshiganj' => [23.5422, 90.5305],
        'Mymensingh' => [24.7471, 90.4203],
        'Naogaon' => [24.8138, 88.9406],
        'Narail' => [23.1725, 89.5125],
        'Narayanganj' => [23.6238, 90.5000],
        'Narsingdi' => [23.9322, 90.7151],
        'Natore' => [24.4206, 89.0000],
        'Netrokona' => [24.8706, 90.7279],
        'Nilphamari' => [25.9310, 88.8560],
        'Noakhali' => [22.8696, 91.0995],
        'Pabna' => [24.0064, 89.2372],
        'Panchagarh' => [26.3411, 88.5542],
        'Patuakhali' => [22.3596, 90.3298],
        'Pirojpur' => [22.5841, 89.9720],
        'Rajbari' => [23.7574, 89.6444],
        'Rajshahi' => [24.3745, 88.6042],
        'Rangamati' => [22.7324, 92.2985],
        'Rangpur' => [25.7439, 89.2752],
        'Satkhira' => [22.7185, 89.0705],
        'Shariatpur' => [23.2423, 90.4348],
        'Sherpur' => [25.0205, 90.0153],
        'Sirajganj' => [24.4534, 89.7007],
        'Sunamganj' => [25.0658, 91.3950],
        'Sylhet' => [24.8949, 91.8687],
        'Tangail' => [24.2513, 89.9167],
        'Thakurgaon' => [26.0337, 88.4617],
    ];
}

function bd_road_km(array $from, array $to): int
{
    $earth = 6371;
    $lat1 = deg2rad($from[0]);
    $lat2 = deg2rad($to[0]);
    $dLat = $lat2 - $lat1;
    $dLon = deg2rad($to[1] - $from[1]);
    $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
    $straight = 2 * $earth * asin(min(1, sqrt($h)));
    return max(15, (int) round($straight * 1.3));
}

function sync_bangladesh_network(): array
{
    global $db;
    $cities = bd_cities();
    $names = array_keys($cities);
    $known = [];
    foreach (qall('SELECT origin, destination, distance_km, duration_minutes, base_fare FROM routes') as $row) {
        $known[$row['origin'] . "\n" . $row['destination']] = $row;
    }

    $added = 0;
    $chunk = [];
    $types = '';
    $params = [];
    $flush = function () use (&$chunk, &$types, &$params, &$added) {
        if (!$chunk) {
            return;
        }
        qexec(
            'INSERT INTO routes (origin, destination, distance_km, duration_minutes, base_fare, status) VALUES '
            . implode(',', $chunk),
            $types,
            $params
        );
        $added += count($chunk);
        $chunk = [];
        $types = '';
        $params = [];
    };

    foreach ($names as $origin) {
        foreach ($names as $destination) {
            if ($origin === $destination) {
                continue;
            }
            $key = $origin . "\n" . $destination;
            if (isset($known[$key])) {
                continue;
            }
            $reverse = $known[$destination . "\n" . $origin] ?? null;
            if ($reverse) {
                $km = (int) $reverse['distance_km'];
                $minutes = (int) $reverse['duration_minutes'];
                $fare = (float) $reverse['base_fare'];
            } else {
                $km = bd_road_km($cities[$origin], $cities[$destination]);
                $minutes = max(40, (int) (round($km / 42 * 60 / 10) * 10));
                $fare = max(150, (int) (round($km * 4.8 / 10) * 10));
            }
            $chunk[] = '(?,?,?,?,?,\'active\')';
            $types .= 'ssiid';
            array_push($params, $origin, $destination, $km, $minutes, (float) $fare);
            $known[$key] = [
                'distance_km' => $km,
                'duration_minutes' => $minutes,
                'base_fare' => $fare,
            ];
            if (count($chunk) === 200) {
                $flush();
            }
        }
    }
    $flush();

    require_once __DIR__ . '/timetable.php';
    ensure_weekly_slots();
    roll_dated_schedules(7);

    return ['routes_added' => $added, 'cities' => count($names)];
}

function sync_rail_and_air(): array
{
    global $db;
    $cities = bd_cities();
    $trains = [
        ['701', 'Suborno Express', 'AC', train_capacity('AC')],
        ['721', 'Turna Express', 'Snigdha', train_capacity('Snigdha')],
        ['709', 'Parabat Express', 'AC', train_capacity('AC')],
        ['753', 'Silkcity Express', 'Snigdha', train_capacity('Snigdha')],
        ['725', 'Sundarban Express', 'Shovan', train_capacity('Shovan', train_has_extra_coaches('725'))],
        ['705', 'Ekota Express', 'AC', train_capacity('AC')],
    ];
    $planes = [
        ['BG101', 'Biman Bangladesh', 'Economy', plane_capacity('Economy')],
        ['BG205', 'Biman Bangladesh', 'Business', plane_capacity('Business')],
        ['BS301', 'US-Bangla', 'Economy', plane_capacity('Economy')],
        ['BS411', 'US-Bangla', 'Economy', plane_capacity('Economy')],
        ['VQ501', 'Novoair', 'Economy', plane_capacity('Economy')],
        ['BG117', 'Biman Bangladesh', 'Economy', plane_capacity('Economy')],
    ];
    foreach ($trains as $row) {
        qexec(
            'INSERT IGNORE INTO trains (train_number, name, seat_class, total_seats) VALUES (?, ?, ?, ?)',
            'sssi',
            $row
        );
    }
    foreach ($planes as $row) {
        qexec(
            'INSERT IGNORE INTO planes (flight_number, airline, cabin, total_seats) VALUES (?, ?, ?, ?)',
            'sssi',
            $row
        );
    }

    $corridors = [
        'train' => [
            ['Dhaka', 'Chattogram'], ['Dhaka', 'Sylhet'], ['Dhaka', 'Rajshahi'], ['Dhaka', 'Khulna'],
            ['Dhaka', 'Rangpur'], ['Dhaka', 'Dinajpur'], ['Dhaka', 'Mymensingh'], ['Dhaka', 'Bogura'],
            ['Dhaka', "Cox's Bazar"], ['Dhaka', 'Jashore'], ['Dhaka', 'Cumilla'], ['Chattogram', 'Sylhet'],
        ],
        'plane' => [
            ['Dhaka', 'Chattogram'], ['Dhaka', 'Sylhet'], ['Dhaka', "Cox's Bazar"], ['Dhaka', 'Rajshahi'],
            ['Dhaka', 'Jashore'], ['Dhaka', 'Barishal'], ['Dhaka', 'Rangpur'],
        ],
    ];
    $added = 0;
    foreach ($corridors as $mode => $pairs) {
        foreach ($pairs as [$origin, $destination]) {
            foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
                if (!isset($cities[$from], $cities[$to])) {
                    continue;
                }
                $exists = qone(
                    'SELECT id FROM routes WHERE origin = ? AND destination = ? AND mode = ?',
                    'sss',
                    [$from, $to, $mode]
                );
                if ($exists) {
                    continue;
                }
                $km = bd_road_km($cities[$from], $cities[$to]);
                if ($mode === 'plane') {
                    $minutes = max(55, (int) round(($km / 550) * 60 + 35));
                    $fare = max(3500, (int) (round($km * 14 / 100) * 100));
                } else {
                    $minutes = max(90, (int) (round($km / 48 * 60 / 10) * 10));
                    $fare = max(180, (int) (round($km * 2.8 / 10) * 10));
                }
                qexec(
                    "INSERT INTO routes (origin, destination, mode, distance_km, duration_minutes, base_fare, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')",
                    'sssiid',
                    [$from, $to, $mode, $km, $minutes, (float) $fare]
                );
                $added++;
            }
        }
    }

    require_once __DIR__ . '/timetable.php';
    ensure_weekly_slots();
    roll_dated_schedules(7);

    return ['routes_added' => $added];
}
