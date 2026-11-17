<?php

require_once __DIR__ . '/timetable.php';

function bus_name_pool(): array
{
    return [
        'Padma Express', 'Meghna Star', 'Jamuna Rider', 'Surma Express', 'Shitalakshya',
        'Karnaphuli', 'Tista Rider', 'Buriganga', 'Madhumati', 'Gomati Link',
        'Sangu Express', 'Atrai Star', 'Kushiara', 'Dhaleshwari', 'Bangali Line',
        'Rupsha Rider', 'Feni Express', 'Halda Star', 'Karatoa', 'Brahmaputra',
    ];
}

function train_name_pool(): array
{
    return [
        'Subarna Express', 'Padma Express', 'Tista Express', 'Silkcity Express', 'Mohunagari Express',
        'Turna Nishitha', 'Sundarban Express', 'Chitra Express', 'Ekota Express', 'Drutajan Express',
        'Upaban Express', 'Parabat Express', 'Kalni Express', 'Haor Express', 'Lalmoni Express',
        'Rangpur Express', 'Titumir Express', 'Bijoy Express', 'Dhumketu Express', 'Sonar Bangla',
    ];
}

function plane_name_pool(): array
{
    return [
        'Sky Padma', 'Bay Horizon', 'Delta Cloud', 'Monsoon Jet', 'Sundarban Air',
        'Meghna Wing', 'Cox Pearl', 'Sylhet Breeze', 'Rajshahi Star', 'Chattogram Link',
        'Barishal Air', 'Rangpur Jet', 'Khulna Skyliner', 'Jamuna Flyer', 'Tejgaon Express',
    ];
}

/** @return array{0:string,1:string} [number, name] */
function bus_fleet_identity(int $seq, string $class): array
{
    $seq = max(1, $seq);
    $metro = ['DM', 'CM', 'SM', 'RM', 'KM', 'BM', 'GM', 'JM'];
    $series = ['BA', 'TA', 'KA', 'GA', 'HA', 'LA', 'MA', 'NA'];
    $names = bus_name_pool();
    $number = sprintf(
        '%s-%s-%02d-%05d',
        $metro[($seq - 1) % count($metro)],
        $series[($seq - 1) % count($series)],
        11 + (($seq - 1) % 40),
        $seq
    );
    $base = $names[($seq - 1) % count($names)];
    $round = intdiv($seq - 1, count($names));
    $name = $round === 0 ? $base : $base . ' ' . ($round + 1);
    if ($class === 'VIP' && !str_contains($name, 'VIP')) {
        // keep poetic names; class already shown in search
    }
    return [$number, $name];
}

/** @return array{0:string,1:string} [number, name] */
function train_fleet_identity(int $seq, string $class): array
{
    $seq = max(1, $seq);
    $names = train_name_pool();
    $prefix = match ($class) {
        'AC' => 'A',
        'Snigdha' => 'N',
        default => 'S',
    };
    // Unique across classes: A1125, N1125, S1125
    $number = $prefix . (1000 + $seq);
    $base = $names[($seq - 1) % count($names)];
    $round = intdiv($seq - 1, count($names));
    $name = $round === 0 ? $base : $base . ' ' . ($round + 1);
    return [$number, $name];
}

/** @return array{0:string,1:string} [flight_number, airline/aircraft name] */
function plane_fleet_identity(int $seq, string $cabin): array
{
    $seq = max(1, $seq);
    $names = plane_name_pool();
    $number = 'JT-' . str_pad((string) (1000 + $seq), 4, '0', STR_PAD_LEFT);
    $base = $names[($seq - 1) % count($names)];
    $round = intdiv($seq - 1, count($names));
    $name = $round === 0 ? $base : $base . ' ' . ($round + 1);
    return [$number, $name];
}

function rename_fleet_identities(): void
{
    // Temp codes avoid unique-key clashes mid-update.
    $dbBuses = qall('SELECT id, coach_type FROM buses ORDER BY id');
    foreach ($dbBuses as $i => $row) {
        qexec('UPDATE buses SET bus_number = ? WHERE id = ?', 'si', ['TMPB-' . $row['id'], (int) $row['id']]);
    }
    foreach ($dbBuses as $i => $row) {
        [$number, $name] = bus_fleet_identity($i + 1, (string) $row['coach_type']);
        qexec('UPDATE buses SET bus_number = ?, name = ? WHERE id = ?', 'ssi', [$number, $name, (int) $row['id']]);
    }

    $dbTrains = qall('SELECT id, seat_class FROM trains ORDER BY id');
    foreach ($dbTrains as $row) {
        qexec('UPDATE trains SET train_number = ? WHERE id = ?', 'si', ['TMPT-' . $row['id'], (int) $row['id']]);
    }
    foreach ($dbTrains as $i => $row) {
        [$number, $name] = train_fleet_identity($i + 1, (string) $row['seat_class']);
        qexec('UPDATE trains SET train_number = ?, name = ? WHERE id = ?', 'ssi', [$number, $name, (int) $row['id']]);
    }

    $dbPlanes = qall('SELECT id, cabin FROM planes ORDER BY id');
    foreach ($dbPlanes as $row) {
        qexec('UPDATE planes SET flight_number = ? WHERE id = ?', 'si', ['TMPP-' . $row['id'], (int) $row['id']]);
    }
    foreach ($dbPlanes as $i => $row) {
        [$number, $name] = plane_fleet_identity($i + 1, (string) $row['cabin']);
        qexec('UPDATE planes SET flight_number = ?, airline = ? WHERE id = ?', 'ssi', [$number, $name, (int) $row['id']]);
    }
}

/** Peak same-day same-origin trips for a mode (optionally one cabin/class). */
function schedule_peak_need(string $mode, string $cabin = ''): int
{
    if ($cabin !== '') {
        return (int) qone(
            "SELECT COALESCE(MAX(cnt), 0) c FROM (
                SELECT s.travel_date, r.origin, COUNT(*) cnt
                FROM schedules s
                JOIN routes r ON r.id = s.route_id
                WHERE r.mode = ? AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
                  AND s.cabin_name = ?
                GROUP BY s.travel_date, r.origin
             ) t",
            'ss',
            [$mode, $cabin]
        )['c'];
    }
    return (int) qone(
        "SELECT COALESCE(MAX(cnt), 0) c FROM (
            SELECT s.travel_date, s.cabin_name, r.origin, COUNT(*) cnt
            FROM schedules s
            JOIN routes r ON r.id = s.route_id
            WHERE r.mode = ? AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
              AND s.cabin_name <> ''
            GROUP BY s.travel_date, s.cabin_name, r.origin
         ) t",
        's',
        [$mode]
    )['c'];
}

/** Day-off buffer (~one day in seven) plus a small operating reserve. */
function fleet_stock_target(int $peakNeed): int
{
    return (int) ceil(max(1, $peakNeed) * (7 / 6) * 1.15);
}

function ensure_real_fleet(): void
{
    foreach (['Standard' => 40, 'VIP' => 48, 'Executive' => 36] as $class => $seats) {
        stock_buses($class, schedule_peak_need('bus', $class), $seats);
    }
    foreach (['Shovan' => 48, 'Snigdha' => 40, 'AC' => 48] as $class => $seats) {
        stock_trains($class, schedule_peak_need('train', $class), $seats);
    }
    foreach (['Economy' => 30, 'Business' => 12] as $cabin => $seats) {
        stock_planes($cabin, schedule_peak_need('plane', $cabin), $seats);
    }
}

/** @deprecated Kept for older callers; prefer schedule_peak_need(). */
function bus_pool_need(): int
{
    return max(1, schedule_peak_need('bus'));
}

/** @deprecated Kept for older callers; prefer schedule_peak_need(). */
function train_pool_need(): int
{
    return max(1, schedule_peak_need('train'));
}

function stock_buses(string $class, int $need, int $seats): void
{
    $have = (int) qone('SELECT COUNT(*) c FROM buses WHERE coach_type = ? AND status = ?', 'ss', [$class, 'active'])['c'];
    $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM buses')['n'];
    $target = fleet_stock_target($need);
    bulk_insert(
        'INSERT INTO buses (bus_number, name, coach_type, total_seats, day_off, status) VALUES ',
        $target - $have,
        $next,
        function (int $n) use ($class, $seats): array {
            [$number, $name] = bus_fleet_identity($n, $class);
            return ['sssiss', [$number, $name, $class, $seats, staggered_day_off($n), 'active']];
        }
    );
}

function stock_trains(string $class, int $need, int $seats): void
{
    $have = (int) qone('SELECT COUNT(*) c FROM trains WHERE seat_class = ? AND status = ?', 'ss', [$class, 'active'])['c'];
    $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM trains')['n'];
    $target = fleet_stock_target($need);
    bulk_insert(
        'INSERT INTO trains (train_number, name, seat_class, total_seats, day_off, status) VALUES ',
        $target - $have,
        $next,
        function (int $n) use ($class, $seats): array {
            [$number, $name] = train_fleet_identity($n, $class);
            return ['sssiss', [$number, $name, $class, $seats, staggered_day_off($n), 'active']];
        }
    );
}

function stock_planes(string $cabin, int $need, int $seats): void
{
    $have = (int) qone('SELECT COUNT(*) c FROM planes WHERE cabin = ? AND status = ?', 'ss', [$cabin, 'active'])['c'];
    $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM planes')['n'];
    $target = fleet_stock_target($need);
    bulk_insert(
        'INSERT INTO planes (flight_number, airline, cabin, total_seats, day_off, status) VALUES ',
        $target - $have,
        $next,
        function (int $n) use ($cabin, $seats): array {
            [$number, $name] = plane_fleet_identity($n, $cabin);
            return ['sssiss', [$number, $name, $cabin, $seats, staggered_day_off($n), 'active']];
        }
    );
}

function bulk_insert(string $prefixSql, int $count, int $start, callable $row): void
{
    if ($count <= 0) {
        return;
    }
    $values = [];
    $types = '';
    $params = [];
    $flush = function () use ($prefixSql, &$values, &$types, &$params): void {
        if (!$values) {
            return;
        }
        qexec($prefixSql . implode(',', $values), $types, $params);
        $values = [];
        $types = '';
        $params = [];
    };
    for ($i = 1; $i <= $count; $i++) {
        [$rowTypes, $rowParams] = $row($start + $i);
        $values[] = '(' . implode(',', array_fill(0, count($rowParams), '?')) . ')';
        $types .= $rowTypes;
        array_push($params, ...$rowParams);
        if (count($values) === 400) {
            $flush();
        }
    }
    $flush();
}

function clear_day_off_conflicts(): void
{
    global $db;
    $db->query(
        "UPDATE schedules s JOIN buses b ON b.id = s.bus_id
         SET s.bus_id = NULL
         WHERE s.status = 'scheduled' AND s.travel_date >= CURDATE()
           AND DAYOFWEEK(s.travel_date) = b.day_off"
    );
    $db->query(
        "UPDATE schedules s JOIN trains t ON t.id = s.train_id
         SET s.train_id = NULL
         WHERE s.status = 'scheduled' AND s.travel_date >= CURDATE()
           AND DAYOFWEEK(s.travel_date) = t.day_off"
    );
    $db->query(
        "UPDATE schedules s JOIN planes p ON p.id = s.plane_id
         SET s.plane_id = NULL
         WHERE s.status = 'scheduled' AND s.travel_date >= CURDATE()
           AND DAYOFWEEK(s.travel_date) = p.day_off"
    );
}

/** Minutes from midnight for a TIME string. */
function fleet_time_minutes(string $time): int
{
    $parts = array_map('intval', explode(':', $time));
    return (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0);
}

/** Turnaround buffer after arrival before the same vehicle can start again. */
function fleet_turnaround_minutes(string $mode): int
{
    return match ($mode) {
        'plane' => 90,
        'train' => 60,
        default => 45,
    };
}

function vehicle_interval_free(array $busy, int $start, int $end): bool
{
    foreach ($busy as [$busyStart, $busyEnd]) {
        if ($start < $busyEnd && $end > $busyStart) {
            return false;
        }
    }
    return true;
}

function fleet_null_assignments(string $column, array $ids): void
{
    if (!$ids || !in_array($column, ['bus_id', 'train_id', 'plane_id'], true)) {
        return;
    }
    foreach (array_chunk($ids, 200) as $chunk) {
        qexec(
            "UPDATE schedules SET {$column} = NULL WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ')'
        );
    }
}

/**
 * Drop duties that overlap in time OR teleport (next origin ≠ last destination).
 * Location carries across days for each vehicle.
 */
function clear_vehicle_duty_conflicts(string $mode, string $column): void
{
    if (!in_array($column, ['bus_id', 'train_id', 'plane_id'], true)) {
        return;
    }
    $buffer = fleet_turnaround_minutes($mode);
    $rows = qall(
        "SELECT s.id, s.{$column} AS vehicle_id, s.travel_date, s.depart_time,
                r.origin, r.destination, r.duration_minutes
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE r.mode = ? AND s.{$column} IS NOT NULL AND s.status = 'scheduled'
           AND s.travel_date >= CURDATE()
         ORDER BY s.{$column}, s.travel_date, s.depart_time, s.id",
        's',
        [$mode]
    );
    $clear = [];
    $state = []; // vid => ['date'=>, 'end'=>minutes, 'city'=>]
    foreach ($rows as $row) {
        $vid = (int) $row['vehicle_id'];
        $date = (string) $row['travel_date'];
        $start = fleet_time_minutes((string) $row['depart_time']);
        $end = $start + max(1, (int) $row['duration_minutes']) + $buffer;
        $origin = (string) $row['origin'];
        $destination = (string) $row['destination'];
        if (isset($state[$vid])) {
            $prev = $state[$vid];
            $sameDay = $prev['date'] === $date;
            $timeClash = $sameDay && $start < $prev['end'];
            $wrongCity = $prev['city'] !== '' && $prev['city'] !== $origin;
            if ($timeClash || $wrongCity) {
                $clear[] = (int) $row['id'];
                continue;
            }
        }
        $state[$vid] = ['date' => $date, 'end' => $end, 'city' => $destination];
    }
    fleet_null_assignments($column, $clear);
}

/**
 * Pick a free vehicle already at $origin, or a depot-fresh one.
 * Prefers currently unused / lightly used vehicles, then route stickiness.
 *
 * @param array<int,int> $workload
 */
function pick_free_vehicle(
    array $pool,
    array &$busy,
    array &$location,
    array &$workload,
    int $routeId,
    string $origin,
    string $destination,
    int $start,
    int $end
): int {
    $n = count($pool);
    if ($n === 0) {
        return 0;
    }
    $preferred = ((int) abs($routeId) * 31) % max(1, $n);
    $candidates = [];
    foreach (['here', 'fresh'] as $passRank => $pass) {
        for ($i = 0; $i < $n; $i++) {
            $vid = (int) $pool[($preferred + $i) % $n];
            if ($vid <= 0) {
                continue;
            }
            $city = $location[$vid] ?? null;
            if ($pass === 'here' && $city !== $origin) {
                continue;
            }
            if ($pass === 'fresh' && $city !== null) {
                continue;
            }
            if (!vehicle_interval_free($busy[$vid] ?? [], $start, $end)) {
                continue;
            }
            $candidates[] = [
                'id' => $vid,
                'load' => (int) ($workload[$vid] ?? 0),
                'pass' => $passRank,
                'sticky' => $i,
            ];
        }
        if ($candidates) {
            break; // prefer "already here" over fresh depot when any exist
        }
    }
    if (!$candidates) {
        return 0;
    }
    usort($candidates, static function (array $a, array $b): int {
        return [$a['load'], $a['pass'], $a['sticky']] <=> [$b['load'], $b['pass'], $b['sticky']];
    });
    $vid = (int) $candidates[0]['id'];
    $busy[$vid][] = [$start, $end];
    $location[$vid] = $destination;
    $workload[$vid] = ((int) ($workload[$vid] ?? 0)) + 1;
    return $vid;
}

/** Hot-standby spare reserve (~8% of vehicles that already have duties). */
function fleet_spare_reserve(int $usedCount): int
{
    return max(3, (int) ceil(max(1, $usedCount) * 0.08));
}

/** In-shop maintenance bay (~5% of working fleet) — real fleets do not park half the stock forever. */
function fleet_maintenance_reserve(int $usedCount): int
{
    return max(2, (int) ceil(max(1, $usedCount) * 0.05));
}

function fleet_delete_vehicles(string $table, string $column, array $ids): void
{
    if (!$ids || !in_array($table, ['buses', 'trains', 'planes'], true)
        || !in_array($column, ['bus_id', 'train_id', 'plane_id'], true)) {
        return;
    }
    foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
        if (!$chunk) {
            continue;
        }
        $in = implode(',', $chunk);
        // Drop history links so unused stock can be removed safely.
        qexec("UPDATE schedules SET {$column} = NULL WHERE {$column} IN ({$in}) AND travel_date < CURDATE()");
        qexec(
            "DELETE FROM {$table}
             WHERE id IN ({$in})
               AND NOT EXISTS (SELECT 1 FROM schedules s WHERE s.{$column} = {$table}.id)"
        );
    }
}

/**
 * Keep a realistic active/spare/maintenance mix:
 * - ~8% hot-standby spare (day_off = None)
 * - ~5% in maintenance
 * - delete leftover idle / parked stock instead of growing a huge maintenance yard
 */
function trim_excess_fleet(): void
{
    $specs = [
        ['buses', 'bus_id', 'coach_type'],
        ['trains', 'train_id', 'seat_class'],
        ['planes', 'plane_id', 'cabin'],
    ];
    foreach ($specs as [$table, $column, $classColumn]) {
        $classes = qall("SELECT DISTINCT {$classColumn} AS cls FROM {$table}");
        foreach ($classes as $classRow) {
            $class = (string) $classRow['cls'];
            if ($class === '') {
                continue;
            }
            $used = qall(
                "SELECT DISTINCT v.id
                 FROM {$table} v
                 JOIN schedules s ON s.{$column} = v.id
                 WHERE v.status = 'active' AND v.{$classColumn} = ?
                   AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
                 ORDER BY v.id",
                's',
                [$class]
            );
            $usedIds = array_map(static fn (array $r): int => (int) $r['id'], $used);
            $usedSet = array_fill_keys($usedIds, true);
            $usedCount = count($usedIds);
            $spareKeep = fleet_spare_reserve($usedCount);
            $maintKeep = fleet_maintenance_reserve($usedCount);

            $idle = qall(
                "SELECT id FROM {$table}
                 WHERE status = 'active' AND {$classColumn} = ?
                 ORDER BY id DESC",
                's',
                [$class]
            );
            $spareIds = [];
            foreach ($idle as $row) {
                $id = (int) $row['id'];
                if (!isset($usedSet[$id])) {
                    $spareIds[] = $id;
                }
            }
            // Top up hot-standby spare to ~8% of used when the idle pool is short.
            while (count($spareIds) < $spareKeep) {
                $newId = stock_one($table, $classColumn, $class);
                if ($newId <= 0) {
                    break;
                }
                qexec("UPDATE {$table} SET day_off = 0, status = 'active' WHERE id = ?", 'i', [$newId]);
                $spareIds[] = $newId;
            }
            $keepIds = array_slice($spareIds, 0, min($spareKeep, count($spareIds)));
            foreach (array_chunk($keepIds, 200) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                qexec(
                    "UPDATE {$table} SET day_off = 0, status = 'active'
                     WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ')'
                );
            }

            $excessIdle = array_slice($spareIds, count($keepIds));
            $maintRows = qall(
                "SELECT id FROM {$table}
                 WHERE status = 'maintenance' AND {$classColumn} = ?
                 ORDER BY id DESC",
                's',
                [$class]
            );
            $maintIds = array_map(static fn (array $r): int => (int) $r['id'], $maintRows);
            $maintSlots = max(0, $maintKeep - count($maintIds));
            $toMaint = array_slice($excessIdle, 0, $maintSlots);
            $toDeleteIdle = array_slice($excessIdle, count($toMaint));

            foreach (array_chunk($toMaint, 200) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                qexec(
                    "UPDATE {$table} SET status = 'maintenance'
                     WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ')'
                );
                foreach ($chunk as $id) {
                    $maintIds[] = (int) $id;
                }
            }

            // Keep only ~5% of working fleet in the shop; remove the rest.
            if (count($maintIds) > $maintKeep) {
                $maintDelete = array_slice($maintIds, $maintKeep);
                fleet_delete_vehicles($table, $column, $maintDelete);
            }
            if ($toDeleteIdle) {
                fleet_delete_vehicles($table, $column, $toDeleteIdle);
            }
        }
    }
}

function assign_open_fleet(): void
{
    clear_day_off_conflicts();
    clear_vehicle_duty_conflicts('bus', 'bus_id');
    clear_vehicle_duty_conflicts('train', 'train_id');
    clear_vehicle_duty_conflicts('plane', 'plane_id');
    assign_mode_php('bus', 'bus_id', 'buses', 'coach_type');
    assign_mode_php('train', 'train_id', 'trains', 'seat_class');
    assign_mode_php('plane', 'plane_id', 'planes', 'cabin');
    trim_excess_fleet();
}

function assign_mode_php(string $mode, string $column, string $table, string $classColumn): void
{
    if (!in_array($column, ['bus_id', 'train_id', 'plane_id'], true)
        || !in_array($table, ['buses', 'trains', 'planes'], true)
        || !in_array($classColumn, ['coach_type', 'seat_class', 'cabin'], true)) {
        return;
    }

    $open = (int) qone(
        "SELECT COUNT(*) c FROM schedules s JOIN routes r ON r.id = s.route_id
         WHERE r.mode = ? AND s.{$column} IS NULL AND s.status = 'scheduled' AND s.travel_date >= CURDATE()",
        's',
        [$mode]
    )['c'];
    if ($open === 0) {
        return;
    }

    $classes = qall(
        "SELECT DISTINCT cabin_name cabin FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE r.mode = ? AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
           AND s.cabin_name <> ''",
        's',
        [$mode]
    );
    foreach ($classes as $classRow) {
        $class = $classRow['cabin'];
        $need = schedule_peak_need($mode, $class);
        $have = (int) qone(
            "SELECT COUNT(*) c FROM {$table} WHERE status = 'active' AND {$classColumn} = ?",
            's',
            [$class]
        )['c'];
        $target = fleet_stock_target($need);
        while ($have < $target) {
            stock_one($table, $classColumn, $class);
            $have++;
        }
    }

    $vehiclesByClass = [];
    foreach (qall(
        "SELECT id, day_off, {$classColumn} AS class_name FROM {$table} WHERE status = 'active' ORDER BY {$classColumn}, id"
    ) as $vehicle) {
        $id = (int) $vehicle['id'];
        if ($id > 0) {
            $vehiclesByClass[$vehicle['class_name']][] = [
                'id' => $id,
                'day_off' => normalize_day_off((int) $vehicle['day_off']),
            ];
        }
    }

    $buffer = fleet_turnaround_minutes($mode);
    // Location + duty count persist across days per cabin class.
    $locationByClass = [];
    $workloadByClass = [];

    $slices = qall(
        "SELECT DISTINCT s.travel_date d, s.cabin_name cabin, DAYOFWEEK(s.travel_date) wd
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE r.mode = ? AND s.status = 'scheduled'
           AND s.travel_date >= CURDATE() AND s.cabin_name <> ''
         ORDER BY s.travel_date, s.cabin_name",
        's',
        [$mode]
    );

    foreach ($slices as $slice) {
        $day = $slice['d'];
        $cabin = $slice['cabin'];
        $weekday = (int) $slice['wd'];
        if (!isset($locationByClass[$cabin])) {
            $locationByClass[$cabin] = [];
        }
        if (!isset($workloadByClass[$cabin])) {
            $workloadByClass[$cabin] = [];
        }
        $location =& $locationByClass[$cabin];
        $workload =& $workloadByClass[$cabin];

        $pool = [];
        foreach ($vehiclesByClass[$cabin] ?? [] as $vehicle) {
            // day_off 0 = hot-standby spare; reserved for Assign / disruption, not regular duty.
            if ($vehicle['day_off'] !== 0 && $vehicle['day_off'] !== $weekday) {
                $pool[] = $vehicle['id'];
            }
        }
        if (!$pool) {
            continue;
        }

        $busy = [];
        $rows = qall(
            "SELECT s.id, s.route_id, s.{$column} AS vehicle_id, s.depart_time,
                    r.origin, r.destination, r.duration_minutes
             FROM schedules s
             JOIN routes r ON r.id = s.route_id
             WHERE r.mode = ? AND s.status = 'scheduled'
               AND s.travel_date = ? AND s.cabin_name = ?
             ORDER BY s.depart_time, s.route_id, s.id",
            'sss',
            [$mode, $day, $cabin]
        );

        $pairs = [];
        $unassigned = [];
        foreach ($rows as $row) {
            $start = fleet_time_minutes((string) $row['depart_time']);
            $end = $start + max(1, (int) $row['duration_minutes']) + $buffer;
            $origin = (string) $row['origin'];
            $destination = (string) $row['destination'];
            $existing = (int) ($row['vehicle_id'] ?? 0);

            if ($existing > 0) {
                $city = $location[$existing] ?? null;
                $okCity = ($city === null || $city === $origin);
                $okTime = vehicle_interval_free($busy[$existing] ?? [], $start, $end);
                if ($okCity && $okTime) {
                    $busy[$existing][] = [$start, $end];
                    $location[$existing] = $destination;
                    $workload[$existing] = ((int) ($workload[$existing] ?? 0)) + 1;
                    continue;
                }
                $unassigned[] = $row + ['_start' => $start, '_end' => $end];
                continue;
            }
            $unassigned[] = $row + ['_start' => $start, '_end' => $end];
        }

        // Free invalid existing assignments so they can be re-placed correctly.
        $invalidIds = [];
        foreach ($unassigned as $row) {
            $existing = (int) ($row['vehicle_id'] ?? 0);
            if ($existing > 0) {
                $invalidIds[] = (int) $row['id'];
            }
        }
        if ($invalidIds) {
            fleet_null_assignments($column, $invalidIds);
        }

        foreach ($unassigned as $row) {
            $start = (int) $row['_start'];
            $end = (int) $row['_end'];
            $origin = (string) $row['origin'];
            $destination = (string) $row['destination'];
            $vehicleId = pick_free_vehicle(
                $pool,
                $busy,
                $location,
                $workload,
                (int) $row['route_id'],
                $origin,
                $destination,
                $start,
                $end
            );
            $growTries = 0;
            while ($vehicleId <= 0 && $growTries < 2) {
                stock_one($table, $classColumn, $cabin);
                $fresh = qone(
                    "SELECT id, day_off FROM {$table} WHERE status = 'active' AND {$classColumn} = ? ORDER BY id DESC LIMIT 1",
                    's',
                    [$cabin]
                );
                $freshDay = $fresh ? normalize_day_off((int) $fresh['day_off']) : 0;
                if ($fresh && $freshDay !== 0 && $freshDay !== $weekday) {
                    $pool[] = (int) $fresh['id'];
                    $vehiclesByClass[$cabin][] = [
                        'id' => (int) $fresh['id'],
                        'day_off' => $freshDay,
                    ];
                }
                $vehicleId = pick_free_vehicle(
                    $pool,
                    $busy,
                    $location,
                    $workload,
                    (int) $row['route_id'],
                    $origin,
                    $destination,
                    $start,
                    $end
                );
                $growTries++;
            }
            if ($vehicleId <= 0) {
                continue;
            }
            $pairs[] = [(int) $row['id'], $vehicleId];
            if (count($pairs) >= 80) {
                write_assignments($column, $pairs);
                $pairs = [];
            }
        }
        if ($pairs) {
            write_assignments($column, $pairs);
        }
        unset($location, $workload);
    }
}

function fleet_lock(int $seconds = 5): bool
{
    global $db;
    $row = $db->query('SELECT GET_LOCK(\'jatra_fleet\', ' . (int) $seconds . ') AS locked')->fetch_assoc();
    return (int) ($row['locked'] ?? 0) === 1;
}

function fleet_unlock(): void
{
    global $db;
    $db->query("SELECT RELEASE_LOCK('jatra_fleet')");
}

function stock_one(string $table, string $classColumn, string $class): int
{
    if ($table === 'buses') {
        $seats = ['VIP' => 48, 'Executive' => 36, 'Standard' => 40][$class] ?? 40;
        $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM buses')['n'] + 1;
        [$number, $name] = bus_fleet_identity($next, $class);
        return qexec(
            'INSERT INTO buses (bus_number, name, coach_type, total_seats, day_off, status) VALUES (?, ?, ?, ?, ?, ?)',
            'sssiss',
            [$number, $name, $class, $seats, staggered_day_off($next), 'active']
        );
    }
    if ($table === 'trains') {
        $seats = ['Shovan' => 48, 'Snigdha' => 40, 'AC' => 48][$class] ?? 40;
        $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM trains')['n'] + 1;
        [$number, $name] = train_fleet_identity($next, $class);
        return qexec(
            'INSERT INTO trains (train_number, name, seat_class, total_seats, day_off, status) VALUES (?, ?, ?, ?, ?, ?)',
            'sssiss',
            [$number, $name, $class, $seats, staggered_day_off($next), 'active']
        );
    }
    $seats = ['Economy' => 30, 'Business' => 12][$class] ?? 30;
    $next = (int) qone('SELECT COALESCE(MAX(id), 0) n FROM planes')['n'] + 1;
    [$number, $name] = plane_fleet_identity($next, $class);
    return qexec(
        'INSERT INTO planes (flight_number, airline, cabin, total_seats, day_off, status) VALUES (?, ?, ?, ?, ?, ?)',
        'sssiss',
        [$number, $name, $class, $seats, staggered_day_off($next), 'active']
    );
}

function write_assignments(string $column, array $pairs): void
{
    global $db;
    if (!$pairs || !in_array($column, ['bus_id', 'train_id', 'plane_id'], true)) {
        return;
    }
    $unions = [];
    foreach ($pairs as [$scheduleId, $vehicleId]) {
        $sid = (int) $scheduleId;
        $vid = (int) $vehicleId;
        if ($sid > 0 && $vid > 0) {
            $unions[] = "SELECT {$sid} AS sid, {$vid} AS vid";
        }
    }
    if (!$unions) {
        return;
    }
    $db->query(
        "UPDATE schedules s
         JOIN (" . implode(' UNION ALL ', $unions) . ") map ON map.sid = s.id
         SET s.{$column} = map.vid"
    );
}
