<?php

/**
 * Weekly reusable timetable.
 * weekday uses MySQL DAYOFWEEK(): 1 = Sunday … 7 = Saturday.
 * Day off is per vehicle (staggered), so each mode still runs every day.
 */
function staggered_day_off(int $ordinal): int
{
    return normalize_day_off((($ordinal - 1) % 7) + 1);
}

function weekday_name(int $weekday): string
{
    if ($weekday === 0) {
        return 'None';
    }
    return [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'][$weekday] ?? 'Unknown';
}

function weekday_options(bool $includeNone = false): array
{
    $days = $includeNone ? [0 => 'None'] : [];
    for ($day = 1; $day <= 7; $day++) {
        $days[$day] = weekday_name($day);
    }
    return $days;
}

function normalize_day_off(int $day): int
{
    if ($day === 0) {
        return 0; // None — used for hot-standby spare vehicles
    }
    return ($day >= 1 && $day <= 7) ? $day : 1;
}

function parse_day_off($value, int $fallback = 1): int
{
    if ($value === null || $value === '') {
        return normalize_day_off($fallback);
    }
    if (is_string($value) && strtolower(trim($value)) === 'none') {
        return 0;
    }
    if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
        return normalize_day_off((int) $value);
    }
    $names = [
        'sunday' => 1, 'monday' => 2, 'tuesday' => 3, 'wednesday' => 4,
        'thursday' => 5, 'friday' => 6, 'saturday' => 7,
    ];
    return $names[strtolower(trim((string) $value))] ?? normalize_day_off($fallback);
}

/** @deprecated Mode-wide day off is no longer used; kept for older callers. */
function mode_day_off(string $mode): int
{
    $defaults = ['bus' => 3, 'train' => 6, 'plane' => 4];
    return $defaults[$mode] ?? 3;
}

function next_staggered_day_off(string $table): int
{
    if (!in_array($table, ['buses', 'trains', 'planes'], true)) {
        return 1;
    }
    $count = (int) qone("SELECT COUNT(*) c FROM {$table}")['c'];
    return staggered_day_off($count + 1);
}

function day_off_distribution(string $table): array
{
    if (!in_array($table, ['buses', 'trains', 'planes'], true)) {
        return [];
    }
    $counts = array_fill(1, 7, 0);
    foreach (qall("SELECT day_off, COUNT(*) c FROM {$table} WHERE status = 'active' GROUP BY day_off") as $row) {
        $day = normalize_day_off((int) $row['day_off']);
        $counts[$day] = (int) $row['c'];
    }
    return $counts;
}

function ensure_vehicle_day_offs(bool $force = false): void
{
    global $db;
    $db->query("DELETE FROM settings WHERE skey IN ('day_off_bus', 'day_off_train', 'day_off_plane')");
    foreach (['buses', 'trains', 'planes'] as $table) {
        $total = (int) qone("SELECT COUNT(*) c FROM {$table}")['c'];
        if ($total === 0) {
            continue;
        }
        // 0 is a spare with no weekly rest. Only a missing or out-of-range day is filled in.
        $roster = (int) qone("SELECT COUNT(*) c FROM {$table} WHERE day_off BETWEEN 1 AND 7")['c'];
        $distinct = (int) qone("SELECT COUNT(DISTINCT day_off) c FROM {$table} WHERE day_off BETWEEN 1 AND 7")['c'];
        $invalid = (int) qone("SELECT COUNT(*) c FROM {$table} WHERE day_off < 0 OR day_off > 7")['c'];
        if ($invalid > 0) {
            $db->query("UPDATE {$table} SET day_off = ((id - 1) % 7) + 1 WHERE day_off < 0 OR day_off > 7");
        }
        if ($force || ($roster > 0 && $distinct <= 1)) {
            $db->query("UPDATE {$table} SET day_off = ((id - 1) % 7) + 1 WHERE day_off BETWEEN 1 AND 7");
        }
    }
}

/** @deprecated Use ensure_vehicle_day_offs(). */
function ensure_day_off_settings(): void
{
    ensure_vehicle_day_offs(false);
}

function mode_departures(string $mode): array
{
    return match ($mode) {
        'train' => ['07:15:00', '15:40:00', '22:10:00'],
        'plane' => ['08:00:00', '13:20:00', '19:05:00'],
        default => ['06:30:00', '08:30:00', '14:00:00', '18:00:00'],
    };
}

function mode_cabins(string $mode): array
{
    return match ($mode) {
        'train' => [['Shovan', 0.7], ['Snigdha', 1.0], ['AC', 1.55]],
        'plane' => [['Economy', 1.0], ['Business', 1.85]],
        default => [['Standard', 1.0], ['VIP', 1.35], ['Executive', 1.75]],
    };
}

function ensure_weekly_table(): void
{
    global $db;
    $db->query(
        "CREATE TABLE IF NOT EXISTS weekly_slots (
          id INT AUTO_INCREMENT PRIMARY KEY,
          route_id INT NOT NULL,
          weekday TINYINT NOT NULL,
          depart_time TIME NOT NULL,
          status ENUM('active','inactive') NOT NULL DEFAULT 'active',
          UNIQUE KEY uniq_week_slot (route_id, weekday, depart_time),
          INDEX idx_week_day (weekday, status),
          FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
        )"
    );
}

function ensure_weekly_slots(): int
{
    global $db;
    ensure_weekly_table();
    ensure_vehicle_day_offs(false);

    $added = 0;
    foreach (['bus', 'train', 'plane'] as $mode) {
        $times = mode_departures($mode);
        $timeRows = implode(' UNION ALL ', array_map(
            static fn (string $t): string => "SELECT '{$t}' AS depart",
            $times
        ));
        $dayRows = [];
        for ($day = 1; $day <= 7; $day++) {
            $dayRows[] = "SELECT {$day} AS weekday";
        }
        $weekSql = implode(' UNION ALL ', $dayRows);
        $before = (int) qone('SELECT COUNT(*) c FROM weekly_slots')['c'];
        $modeSql = $db->real_escape_string($mode);
        $db->query(
            "INSERT IGNORE INTO weekly_slots (route_id, weekday, depart_time, status)
             SELECT r.id, w.weekday, t.depart, 'active'
             FROM routes r
             JOIN ({$weekSql}) w
             JOIN ({$timeRows}) t
             WHERE r.mode = '{$modeSql}' AND r.status = 'active'"
        );
        $after = (int) qone('SELECT COUNT(*) c FROM weekly_slots')['c'];
        $added += max(0, $after - $before);
    }
    return $added;
}

function rebuild_weekly_slots(): int
{
    global $db;
    ensure_weekly_table();
    ensure_vehicle_day_offs(false);
    $db->query('DELETE FROM weekly_slots');
    return ensure_weekly_slots();
}

function roll_dated_schedules(int $horizonDays = 7): int
{
    global $db;
    ensure_weekly_table();
    ensure_weekly_slots();

    $inserted = 0;
    $horizonDays = max(7, min(14, $horizonDays));

    foreach (['bus' => 'bus_id', 'train' => 'train_id', 'plane' => 'plane_id'] as $mode => $column) {
        foreach (mode_cabins($mode) as [$cabin, $factor]) {
            $cabinSql = $db->real_escape_string($cabin);
            $modeSql = $db->real_escape_string($mode);
            $before = (int) qone('SELECT COUNT(*) c FROM schedules')['c'];
            $db->query(
                "INSERT INTO schedules (route_id, {$column}, travel_date, depart_time, fare, status, cabin_name)
                 SELECT w.route_id,
                        NULL,
                        DATE_ADD(CURDATE(), INTERVAL d.n DAY),
                        w.depart_time,
                        ROUND(r.base_fare * {$factor}, 0),
                        'scheduled',
                        '{$cabinSql}'
                 FROM weekly_slots w
                 JOIN routes r ON r.id = w.route_id
                 JOIN (
                   SELECT 0 AS n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3
                   UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
                 ) d
                 WHERE r.mode = '{$modeSql}'
                   AND r.status = 'active'
                   AND w.status = 'active'
                   AND d.n < {$horizonDays}
                   AND w.weekday = DAYOFWEEK(DATE_ADD(CURDATE(), INTERVAL d.n DAY))
                   AND NOT EXISTS (
                     SELECT 1 FROM schedules s
                     WHERE s.route_id = w.route_id
                       AND s.travel_date = DATE_ADD(CURDATE(), INTERVAL d.n DAY)
                       AND s.depart_time = w.depart_time
                       AND s.cabin_name = '{$cabinSql}'
                   )"
            );
            $after = (int) qone('SELECT COUNT(*) c FROM schedules')['c'];
            $inserted += max(0, $after - $before);
        }
    }

    return $inserted;
}

/**
 * Once a day, copy the weekday plan onto the next 7 calendar days
 * and put each trip back on the vehicle that ran that same slot.
 * A page on the live host only writes one small piece, then the next page continues.
 */
function ensure_upcoming_trips(): void
{
    $rolled = qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'");
    $markedToday = (string) ($rolled['svalue'] ?? '') >= app_today();
    if ($markedToday && PHP_SAPI === 'cli') {
        return;
    }
    if ($markedToday) {
        $fill = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_fill'")['svalue'] ?? '');
        if ($fill === 'done') {
            return;
        }
        $assigned = qone(
            "SELECT 1 AS ok FROM schedules
             WHERE status = 'scheduled' AND travel_date >= CURDATE()
               AND (bus_id IS NOT NULL OR train_id IS NOT NULL OR plane_id IS NOT NULL)
             LIMIT 1"
        );
        if ($assigned && $fill === '') {
            save_setting('schedule_fill', 'done');
            return;
        }
    }
    if (PHP_SAPI === 'cli') {
        publish_trips_fully();
        return;
    }
    publish_trips_for_page();
}

function publish_trips_fully(): void
{
    $lock = qone("SELECT GET_LOCK('project_x_schedule_roll', 0) AS locked");
    if ((int) ($lock['locked'] ?? 0) !== 1) {
        return;
    }
    try {
        $rolled = qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'");
        if ((string) ($rolled['svalue'] ?? '') >= app_today()) {
            return;
        }
        set_time_limit(0);
        roll_dated_schedules(7);
        copy_slot_vehicles('bus', 'bus_id', 'buses', 'coach_type');
        copy_slot_vehicles('train', 'train_id', 'trains', 'seat_class');
        copy_slot_vehicles('plane', 'plane_id', 'planes', 'cabin');
        save_setting('schedule_roll', app_today());
        save_setting('schedule_fill', 'done');
    } finally {
        qone("SELECT RELEASE_LOCK('project_x_schedule_roll')");
    }
}

function timetable_publish_status(): array
{
    $today = app_today();
    $horizon = app_now()->modify('+6 days')->format('Y-m-d');
    $rolled = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'")['svalue'] ?? '');
    $fill = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_fill'")['svalue'] ?? '');
    $done = $rolled >= $today && $fill === 'done';
    $place = '';
    $cursorDate = '';
    $cursorMode = '';
    $cursorCabin = '';
    $afterId = 0;
    if (!$done && preg_match('/^(\d{4}-\d{2}-\d{2})\|(bus|train|plane)\|([^|]+)\|(\d+)$/', $fill, $match) === 1) {
        $cursorDate = $match[1];
        $cursorMode = $match[2];
        $cursorCabin = $match[3];
        $afterId = (int) $match[4];
        $place = date_label($cursorDate) . ' · ' . mode_label($cursorMode) . ' · ' . $cursorCabin;
    }
    $percent = $done ? 100 : timetable_publish_percent($cursorDate, $cursorMode, $cursorCabin, $afterId, $today);
    $updatedFrom = '';
    $updatedThrough = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rolled) === 1) {
        $updatedFrom = date_label($rolled);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $rolled, app_zone());
        $updatedThrough = $end instanceof DateTimeImmutable
            ? date_label($end->modify('+6 days')->format('Y-m-d'))
            : $updatedFrom;
    }
    return [
        'done' => $done,
        'percent' => $percent,
        'from' => date_label($today),
        'through' => date_label($horizon),
        'updated_from' => $updatedFrom,
        'updated_through' => $updatedThrough,
        'place' => $place,
        'cursor' => $fill,
    ];
}

function timetable_publish_percent(string $date, string $mode, string $cabin, int $afterId, string $today): int
{
    $modes = ['bus', 'train', 'plane'];
    $routeCounts = [];
    $modeUnits = [];
    $dayUnits = 0;
    foreach ($modes as $item) {
        $routeCounts[$item] = (int) (qone(
            "SELECT COUNT(*) c FROM routes WHERE mode = ? AND status = 'active'",
            's',
            [$item]
        )['c'] ?? 0);
        $modeUnits[$item] = $routeCounts[$item] * count(mode_cabins($item));
        $dayUnits += $modeUnits[$item];
    }
    $total = $dayUnits * 7;
    if ($total < 1 || $date === '' || !in_array($mode, $modes, true)) {
        return 0;
    }
    $dayIndex = 0;
    $cursor = new DateTimeImmutable($today, app_zone());
    for ($i = 0; $i < 7; $i++) {
        if ($cursor->format('Y-m-d') === $date) {
            $dayIndex = $i;
            break;
        }
        $cursor = $cursor->modify('+1 day');
    }
    $units = $dayIndex * $dayUnits;
    foreach ($modes as $item) {
        if ($item === $mode) {
            break;
        }
        $units += $modeUnits[$item];
    }
    $cabins = array_column(mode_cabins($mode), 0);
    $cabinIndex = array_search($cabin, $cabins, true);
    if ($cabinIndex === false) {
        $cabinIndex = 0;
    }
    $units += (int) $cabinIndex * $routeCounts[$mode];
    $passed = (int) (qone(
        "SELECT COUNT(*) c FROM routes WHERE mode = ? AND status = 'active' AND id <= ?",
        'si',
        [$mode, $afterId]
    )['c'] ?? 0);
    $units += min($routeCounts[$mode], $passed);
    return min(99, (int) floor(($units / $total) * 100));
}

function publish_trips_for_page(): void
{
    $rolled = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_roll'")['svalue'] ?? '');
    $fill = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_fill'")['svalue'] ?? '');
    if ($rolled >= app_today() && $fill === 'done') {
        return;
    }
    $lock = qone("SELECT GET_LOCK('project_x_schedule_roll', 0) AS locked");
    if ((int) ($lock['locked'] ?? 0) !== 1) {
        return;
    }
    try {
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            if (!publish_next_trip_batch()) {
                break;
            }
        }
    } finally {
        qone("SELECT RELEASE_LOCK('project_x_schedule_roll')");
    }
}

/** @return array{bus: array{0: string, 1: string, 2: string}, train: array{0: string, 1: string, 2: string}, plane: array{0: string, 1: string, 2: string}} */
function trip_mode_columns(): array
{
    return [
        'bus' => ['bus_id', 'buses', 'coach_type'],
        'train' => ['train_id', 'trains', 'seat_class'],
        'plane' => ['plane_id', 'planes', 'cabin'],
    ];
}

function save_setting(string $key, string $value): void
{
    qexec(
        "INSERT INTO settings (skey, svalue) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)",
        'ss',
        [$key, $value]
    );
}

/**
 * Write the next group of routes for one date and class.
 * Returns false when today through the next 6 days are finished.
 */
function publish_next_trip_batch(): bool
{
    global $db;
    $today = app_today();
    $horizon = app_now()->modify('+6 days')->format('Y-m-d');
    $cursor = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_fill'")['svalue'] ?? '');
    [$date, $mode, $cabin, $afterId] = fill_cursor_position($cursor, $today, $horizon);
    $modes = trip_mode_columns();
    [$column, $table, $classColumn] = $modes[$mode];
    $factor = 1.0;
    foreach (mode_cabins($mode) as [$name, $mult]) {
        if ($name === $cabin) {
            $factor = (float) $mult;
        }
    }
    $limit = 300;
    $ids = qall(
        "SELECT id FROM routes WHERE mode = ? AND status = 'active' AND id > ? ORDER BY id LIMIT {$limit}",
        'si',
        [$mode, $afterId]
    );
    if (!$ids) {
        return advance_fill_cursor($date, $mode, $cabin, $horizon);
    }
    $idList = implode(',', array_map(static fn (array $row): int => (int) $row['id'], $ids));
    $lastId = (int) $ids[count($ids) - 1]['id'];
    $firstId = (int) $ids[0]['id'];
    $slot = qone(
        "SELECT id FROM weekly_slots WHERE route_id = ? AND weekday = DAYOFWEEK(?) AND status = 'active' LIMIT 1",
        'is',
        [$firstId, $date]
    );
    if (!$slot) {
        publish_batch_slots($mode, $idList);
    }
    $cabinSql = $db->real_escape_string($cabin);
    $modeSql = $db->real_escape_string($mode);
    $dateSql = $db->real_escape_string($date);
    $db->query(
        "INSERT INTO schedules (route_id, {$column}, travel_date, depart_time, fare, status, cabin_name)
         SELECT w.route_id, NULL, '{$dateSql}', w.depart_time,
                ROUND(r.base_fare * {$factor}, 0), 'scheduled', '{$cabinSql}'
         FROM weekly_slots w
         JOIN routes r ON r.id = w.route_id
         WHERE r.mode = '{$modeSql}' AND r.status = 'active' AND w.status = 'active'
           AND w.weekday = DAYOFWEEK('{$dateSql}')
           AND r.id IN ({$idList})
           AND NOT EXISTS (
             SELECT 1 FROM schedules s
             WHERE s.route_id = w.route_id AND s.travel_date = '{$dateSql}'
               AND s.depart_time = w.depart_time AND s.cabin_name = '{$cabinSql}'
           )"
    );
    publish_batch_vehicles($mode, $column, $table, $classColumn, $idList, $dateSql, $cabinSql);
    if (count($ids) < $limit) {
        return advance_fill_cursor($date, $mode, $cabin, $horizon);
    }
    save_setting('schedule_fill', $date . '|' . $mode . '|' . $cabin . '|' . $lastId);
    return true;
}

/** @return array{0: string, 1: string, 2: string, 3: int} */
function fill_cursor_position(string $cursor, string $today, string $horizon): array
{
    $parts = explode('|', $cursor);
    $date = $parts[0] ?? '';
    $mode = $parts[1] ?? 'bus';
    $cabin = $parts[2] ?? '';
    $afterId = (int) ($parts[3] ?? 0);
    $modes = trip_mode_columns();
    $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date >= $today && $date <= $horizon;
    if (!$validDate || !isset($modes[$mode])) {
        return [$today, 'bus', (string) mode_cabins('bus')[0][0], 0];
    }
    $names = array_column(mode_cabins($mode), 0);
    if (!in_array($cabin, $names, true)) {
        return [$date, $mode, (string) $names[0], 0];
    }
    return [$date, $mode, $cabin, max(0, $afterId)];
}

function advance_fill_cursor(string $date, string $mode, string $cabin, string $horizon): bool
{
    $next = next_fill_position($date, $mode, $cabin, $horizon);
    if ($next === null) {
        save_setting('schedule_roll', app_today());
        save_setting('schedule_fill', 'done');
        return false;
    }
    [$nextDate, $nextMode, $nextCabin, $afterId] = $next;
    save_setting('schedule_fill', $nextDate . '|' . $nextMode . '|' . $nextCabin . '|' . $afterId);
    return true;
}

/** @return array{0: string, 1: string, 2: string, 3: int}|null */
function next_fill_position(string $date, string $mode, string $cabin, string $horizon): ?array
{
    $modes = array_keys(trip_mode_columns());
    $cabins = array_column(mode_cabins($mode), 0);
    $cabinIndex = array_search($cabin, $cabins, true);
    if ($cabinIndex !== false && isset($cabins[$cabinIndex + 1])) {
        return [$date, $mode, (string) $cabins[$cabinIndex + 1], 0];
    }
    $modeIndex = array_search($mode, $modes, true);
    if ($modeIndex !== false && isset($modes[$modeIndex + 1])) {
        $nextMode = (string) $modes[$modeIndex + 1];
        return [$date, $nextMode, (string) mode_cabins($nextMode)[0][0], 0];
    }
    $nextDate = (new DateTimeImmutable($date, app_zone()))->modify('+1 day')->format('Y-m-d');
    if ($nextDate > $horizon) {
        return null;
    }
    return [$nextDate, 'bus', (string) mode_cabins('bus')[0][0], 0];
}

function publish_batch_slots(string $mode, string $idList): void
{
    global $db;
    $times = mode_departures($mode);
    $timeRows = implode(' UNION ALL ', array_map(
        static fn (string $time): string => "SELECT '" . $db->real_escape_string($time) . "' AS depart",
        $times
    ));
    $dayRows = [];
    for ($day = 1; $day <= 7; $day++) {
        $dayRows[] = "SELECT {$day} AS weekday";
    }
    $db->query(
        "INSERT IGNORE INTO weekly_slots (route_id, weekday, depart_time, status)
         SELECT r.id, d.weekday, t.depart, 'active'
         FROM routes r
         JOIN (" . implode(' UNION ALL ', $dayRows) . ") d
         JOIN ({$timeRows}) t
         WHERE r.id IN ({$idList})"
    );
}

function publish_batch_vehicles(
    string $mode,
    string $column,
    string $table,
    string $classColumn,
    string $idList,
    string $dateSql,
    string $cabinSql
): void {
    global $db;
    if (!isset(trip_mode_columns()[$mode])) {
        return;
    }
    $db->query(
        "UPDATE schedules s
         JOIN (
           SELECT route_id, depart_time, cabin_name, MAX(travel_date) AS last_day
           FROM schedules
           WHERE {$column} IS NOT NULL
             AND route_id IN ({$idList})
             AND cabin_name = '{$cabinSql}'
             AND travel_date >= DATE_SUB('{$dateSql}', INTERVAL 28 DAY)
             AND travel_date < '{$dateSql}'
             AND DAYOFWEEK(travel_date) = DAYOFWEEK('{$dateSql}')
           GROUP BY route_id, depart_time, cabin_name
         ) latest
           ON latest.route_id = s.route_id
          AND latest.depart_time = s.depart_time
          AND latest.cabin_name = s.cabin_name
         JOIN schedules src
           ON src.route_id = latest.route_id
          AND src.depart_time = latest.depart_time
          AND src.cabin_name = latest.cabin_name
          AND src.travel_date = latest.last_day
          AND src.{$column} IS NOT NULL
         JOIN {$table} v ON v.id = src.{$column}
         SET s.{$column} = src.{$column}
         WHERE s.{$column} IS NULL
           AND s.status = 'scheduled'
           AND s.route_id IN ({$idList})
           AND s.travel_date = '{$dateSql}'
           AND s.cabin_name = '{$cabinSql}'
           AND v.status = 'active'
           AND v.day_off <> 0
           AND v.day_off <> DAYOFWEEK(s.travel_date)
           AND v.{$classColumn} = s.cabin_name"
    );
}

function copy_slot_vehicles(string $mode, string $column, string $table, string $classColumn): void
{
    global $db;
    if (!in_array($mode, ['bus', 'train', 'plane'], true)
        || !in_array($column, ['bus_id', 'train_id', 'plane_id'], true)
        || !in_array($table, ['buses', 'trains', 'planes'], true)
        || !in_array($classColumn, ['coach_type', 'seat_class', 'cabin'], true)) {
        return;
    }
    $modeSql = $db->real_escape_string($mode);
    $db->query(
        "UPDATE schedules s
         JOIN routes r ON r.id = s.route_id AND r.mode = '{$modeSql}'
         JOIN (
           SELECT route_id, depart_time, cabin_name, DAYOFWEEK(travel_date) AS wd, MAX(travel_date) AS last_day
           FROM schedules
           WHERE {$column} IS NOT NULL
             AND travel_date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
             AND travel_date < CURDATE()
           GROUP BY route_id, depart_time, cabin_name, DAYOFWEEK(travel_date)
         ) latest
           ON latest.route_id = s.route_id
          AND latest.depart_time = s.depart_time
          AND latest.cabin_name = s.cabin_name
          AND latest.wd = DAYOFWEEK(s.travel_date)
         JOIN schedules src
           ON src.route_id = latest.route_id
          AND src.depart_time = latest.depart_time
          AND src.cabin_name = latest.cabin_name
          AND src.travel_date = latest.last_day
          AND src.{$column} IS NOT NULL
         JOIN {$table} v ON v.id = src.{$column}
         SET s.{$column} = src.{$column}
         WHERE s.{$column} IS NULL
           AND s.status = 'scheduled'
           AND s.travel_date >= CURDATE()
           AND v.status = 'active'
           AND v.day_off <> 0
           AND v.day_off <> DAYOFWEEK(s.travel_date)
           AND v.{$classColumn} = s.cabin_name"
    );
}

/**
 * Put an active route back on the next 7 days and return each trip
 * to a vehicle that already ran that same weekday, time, and class.
 */
function restore_route_service(int $routeId): void
{
    if ($routeId <= 0) {
        return;
    }
    $route = qone('SELECT id, mode, base_fare, duration_minutes, status FROM routes WHERE id = ?', 'i', [$routeId]);
    if (!$route || ($route['status'] ?? '') !== 'active') {
        return;
    }
    $mode = clean_mode((string) $route['mode']);
    $column = match ($mode) {
        'train' => 'train_id',
        'plane' => 'plane_id',
        default => 'bus_id',
    };
    $table = match ($mode) {
        'train' => 'trains',
        'plane' => 'planes',
        default => 'buses',
    };
    $classColumn = match ($mode) {
        'train' => 'seat_class',
        'plane' => 'cabin',
        default => 'coach_type',
    };
    ensure_weekly_table();
    foreach (mode_departures($mode) as $time) {
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            qexec(
                "INSERT IGNORE INTO weekly_slots (route_id, weekday, depart_time, status) VALUES (?, ?, ?, 'active')",
                'iis',
                [$routeId, $weekday, $time]
            );
        }
    }

    $duration = max(1, (int) $route['duration_minutes']);
    $days = qall(
        "SELECT DATE_ADD(CURDATE(), INTERVAL n DAY) AS travel_date, DAYOFWEEK(DATE_ADD(CURDATE(), INTERVAL n DAY)) AS weekday
         FROM (
           SELECT 0 AS n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3
           UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
         ) d"
    );
    foreach ($days as $day) {
        $travelDate = (string) $day['travel_date'];
        $weekday = (int) $day['weekday'];
        foreach (mode_departures($mode) as $time) {
            foreach (mode_cabins($mode) as [$cabin, $factor]) {
                $fare = round((float) $route['base_fare'] * $factor, 0);
                $trip = qone(
                    "SELECT id, status, {$column} AS vehicle_id FROM schedules
                     WHERE route_id = ? AND travel_date = ? AND depart_time = ? AND cabin_name = ?
                     ORDER BY status = 'scheduled' DESC, id DESC
                     LIMIT 1",
                    'isss',
                    [$routeId, $travelDate, $time, $cabin]
                );
                if (!$trip) {
                    $tripId = qexec(
                        "INSERT INTO schedules (route_id, {$column}, travel_date, depart_time, fare, status, cabin_name)
                         VALUES (?, NULL, ?, ?, ?, 'scheduled', ?)",
                        'issds',
                        [$routeId, $travelDate, $time, $fare, $cabin]
                    );
                    $vehicleId = 0;
                } elseif (($trip['status'] ?? '') !== 'scheduled') {
                    continue;
                } else {
                    $tripId = (int) $trip['id'];
                    $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
                }
                if ($tripId <= 0 || $vehicleId > 0) {
                    continue;
                }
                $previous = qall(
                    "SELECT s.{$column} AS vehicle_id
                     FROM schedules s
                     JOIN {$table} v ON v.id = s.{$column}
                     WHERE s.route_id = ? AND s.depart_time = ? AND s.cabin_name = ?
                       AND DAYOFWEEK(s.travel_date) = ?
                       AND s.{$column} IS NOT NULL
                       AND v.status = 'active'
                       AND v.day_off <> 0
                       AND v.day_off <> ?
                       AND v.{$classColumn} = ?
                     GROUP BY s.{$column}
                     ORDER BY MAX(s.travel_date) DESC",
                    'ississ',
                    [$routeId, $time, $cabin, $weekday, $weekday, $cabin]
                );
                foreach ($previous as $candidate) {
                    $candidateId = (int) $candidate['vehicle_id'];
                    if ($candidateId <= 0 || route_vehicle_is_busy($column, $candidateId, $tripId, $travelDate, $time, $duration)) {
                        continue;
                    }
                    qexec(
                        "UPDATE schedules SET {$column} = ? WHERE id = ? AND {$column} IS NULL",
                        'ii',
                        [$candidateId, $tripId]
                    );
                    break;
                }
            }
        }
    }
}

function route_vehicle_is_busy(string $column, int $vehicleId, int $scheduleId, string $travelDate, string $departTime, int $duration): bool
{
    if (!in_array($column, ['bus_id', 'train_id', 'plane_id'], true)) {
        return true;
    }
    $clash = qone(
        "SELECT s.id
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.{$column} = ? AND s.status = 'scheduled' AND s.id <> ?
           AND TIMESTAMP(s.travel_date, s.depart_time) < DATE_ADD(TIMESTAMP(?, ?), INTERVAL ? MINUTE)
           AND DATE_ADD(TIMESTAMP(s.travel_date, s.depart_time), INTERVAL r.duration_minutes MINUTE) > TIMESTAMP(?, ?)",
        'iississ',
        [$vehicleId, $scheduleId, $travelDate, $departTime, $duration, $travelDate, $departTime]
    );
    return (bool) $clash;
}
