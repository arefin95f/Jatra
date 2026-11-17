<?php

require_once __DIR__ . '/timetable.php';
require_once __DIR__ . '/fleet.php';
require_once __DIR__ . '/notifications.php';

function replacement_reasons(): array
{
    return [
        'traffic_delay' => 'Traffic delay',
        'damage' => 'Transport damage',
        'accident' => 'Accident',
        'other' => 'Other operational issue',
    ];
}

function replacement_reason_label(string $reason): string
{
    return replacement_reasons()[$reason] ?? 'Operational update';
}

/** Damage / accident / other → ground the old unit in maintenance. Traffic delay does not. */
function replacement_grounds_vehicle(string $reason): bool
{
    return in_array($reason, ['damage', 'accident', 'other'], true);
}

/**
 * When admin returns a grounded vehicle to active:
 * hand all upcoming duties back from the covering spare, then that spare becomes spare again.
 */
function reactivate_fleet_vehicle(string $mode, int $vehicleId): void
{
    if ($vehicleId <= 0) {
        return;
    }
    ensure_replacement_tables();
    $mode = clean_mode($mode);
    $table = schedule_vehicle_table($mode);
    $column = schedule_vehicle_column($mode);

    $takeover = qone(
        "SELECT id, new_vehicle_id FROM vehicle_replacements
         WHERE mode = ? AND old_vehicle_id = ? AND full_takeover = 1 AND reversed_at IS NULL
         ORDER BY id DESC LIMIT 1",
        'si',
        [$mode, $vehicleId]
    );
    if ($takeover) {
        $spareId = (int) $takeover['new_vehicle_id'];
        qexec(
            "UPDATE schedules SET {$column} = ?
             WHERE {$column} = ? AND status = 'scheduled' AND travel_date >= CURDATE()",
            'ii',
            [$vehicleId, $spareId]
        );
        qexec(
            "UPDATE {$table} SET status = 'active', day_off = 0 WHERE id = ?",
            'i',
            [$spareId]
        );
        qexec(
            'UPDATE vehicle_replacements SET reversed_at = CURRENT_TIMESTAMP WHERE id = ?',
            'i',
            [(int) $takeover['id']]
        );
    }

    qexec("UPDATE {$table} SET status = 'active' WHERE id = ?", 'i', [$vehicleId]);
    $upcoming = (int) qone(
        "SELECT COUNT(*) c FROM schedules
         WHERE {$column} = ? AND status = 'scheduled' AND travel_date >= CURDATE()",
        'i',
        [$vehicleId]
    )['c'];
    if ($upcoming === 0) {
        qexec("UPDATE {$table} SET day_off = 0 WHERE id = ?", 'i', [$vehicleId]);
    }
}

function ensure_replacement_tables(): void
{
    global $db;
    $db->query(
        "CREATE TABLE IF NOT EXISTS vehicle_replacements (
          id INT AUTO_INCREMENT PRIMARY KEY,
          schedule_id INT NOT NULL,
          mode ENUM('bus','train','plane') NOT NULL,
          old_vehicle_id INT NOT NULL,
          new_vehicle_id INT NOT NULL,
          reason ENUM('traffic_delay','damage','accident','other') NOT NULL,
          note VARCHAR(255) NULL,
          admin_id INT NOT NULL,
          full_takeover TINYINT NOT NULL DEFAULT 0,
          reversed_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_replace_schedule (schedule_id, created_at),
          INDEX idx_replace_open (mode, old_vehicle_id, full_takeover, reversed_at),
          FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
        )"
    );
    $cols = [];
    $result = $db->query('SHOW COLUMNS FROM vehicle_replacements');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    if (!isset($cols['full_takeover'])) {
        $db->query('ALTER TABLE vehicle_replacements ADD COLUMN full_takeover TINYINT NOT NULL DEFAULT 0 AFTER admin_id');
    }
    if (!isset($cols['reversed_at'])) {
        $db->query('ALTER TABLE vehicle_replacements ADD COLUMN reversed_at TIMESTAMP NULL DEFAULT NULL AFTER full_takeover');
    }
    $db->query(
        "CREATE TABLE IF NOT EXISTS user_notifications (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          booking_id INT NULL,
          title VARCHAR(160) NOT NULL,
          body VARCHAR(1000) NOT NULL,
          is_read TINYINT NOT NULL DEFAULT 0,
          seen_at TIMESTAMP NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user_notifications (user_id, is_read, id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
    $noteCols = [];
    $noteResult = $db->query('SHOW COLUMNS FROM user_notifications');
    if ($noteResult) {
        while ($row = $noteResult->fetch_assoc()) {
            $noteCols[$row['Field']] = true;
        }
    }
    if (!isset($noteCols['seen_at'])) {
        $db->query('ALTER TABLE user_notifications ADD COLUMN seen_at TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    }
}

function schedule_vehicle_column(string $mode): string
{
    return match (clean_mode($mode)) {
        'train' => 'train_id',
        'plane' => 'plane_id',
        default => 'bus_id',
    };
}

function schedule_vehicle_table(string $mode): string
{
    return match (clean_mode($mode)) {
        'train' => 'trains',
        'plane' => 'planes',
        default => 'buses',
    };
}

function schedule_class_column(string $mode): string
{
    return match (clean_mode($mode)) {
        'train' => 'seat_class',
        'plane' => 'cabin',
        default => 'coach_type',
    };
}

function schedule_code_column(string $mode): string
{
    return match (clean_mode($mode)) {
        'train' => 'train_number',
        'plane' => 'flight_number',
        default => 'bus_number',
    };
}

function schedule_name_column(string $mode): string
{
    return match (clean_mode($mode)) {
        'plane' => 'airline',
        default => 'name',
    };
}

function load_schedule_for_replace(int $scheduleId): ?array
{
    $row = qone(
        "SELECT s.*, r.origin, r.destination, r.mode, r.duration_minutes,
                COALESCE(b.id, tn.id, pl.id) AS vehicle_id,
                COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS service_code,
                COALESCE(b.name, tn.name, pl.airline) AS carrier_name,
                COALESCE(b.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         LEFT JOIN buses b ON b.id = s.bus_id
         LEFT JOIN trains tn ON tn.id = s.train_id
         LEFT JOIN planes pl ON pl.id = s.plane_id
         WHERE s.id = ? AND s.status = 'scheduled'
           AND (s.travel_date > CURDATE()
             OR (s.travel_date = CURDATE() AND s.depart_time > CURTIME()))",
        'i',
        [$scheduleId]
    );
    if (!$row || !(int) ($row['vehicle_id'] ?? 0)) {
        return null;
    }
    return $row;
}

function vehicle_label_by_id(string $mode, int $vehicleId): array
{
    $table = schedule_vehicle_table($mode);
    $codeCol = schedule_code_column($mode);
    $nameCol = schedule_name_column($mode);
    $row = qone(
        "SELECT {$codeCol} AS code, {$nameCol} AS name FROM {$table} WHERE id = ?",
        'i',
        [$vehicleId]
    );
    return [
        'code' => (string) ($row['code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

/** Human label for passenger notices, e.g. "Padma Express (DM-BA-11-4521)". */
function format_vehicle_notice_label(string $mode, int $vehicleId): string
{
    $v = vehicle_label_by_id($mode, $vehicleId);
    $name = trim($v['name']);
    $code = trim($v['code']);
    if ($name !== '' && $code !== '') {
        return $name . ' (' . $code . ')';
    }
    return $name !== '' ? $name : $code;
}

/**
 * True spare pool only: active, day_off = None, no upcoming duties.
 * Returns all standby spares for the mode (same class sorted first).
 */
function spare_candidates_for_schedule(array $schedule): array
{
    $mode = clean_mode((string) $schedule['mode']);
    $column = schedule_vehicle_column($mode);
    $table = schedule_vehicle_table($mode);
    $classColumn = schedule_class_column($mode);
    $codeColumn = schedule_code_column($mode);
    $nameColumn = schedule_name_column($mode);
    $cabin = trim((string) ($schedule['cabin'] ?? ''));
    $currentId = (int) ($schedule['vehicle_id'] ?? 0);

    $sql = "SELECT v.id, v.{$codeColumn} AS code, v.{$nameColumn} AS name,
                   v.{$classColumn} AS class_name, v.day_off, 0 AS upcoming
            FROM {$table} v
            WHERE v.status = 'active'
              AND v.day_off = 0
              AND v.id <> ?
              AND NOT EXISTS (
                SELECT 1 FROM schedules s2
                WHERE s2.{$column} = v.id AND s2.status = 'scheduled' AND s2.travel_date >= CURDATE()
              )";
    $types = 'i';
    $params = [$currentId];
    if ($cabin !== '') {
        $sql .= " ORDER BY (v.{$classColumn} = ?) DESC, v.{$codeColumn} ASC";
        $types .= 's';
        $params[] = $cabin;
    } else {
        $sql .= " ORDER BY v.{$codeColumn} ASC";
    }

    return qall($sql, $types, $params);
}

/** Autosuggest rows for replace-vehicle spare picker. */
function spare_suggest_list(array $schedule): array
{
    $items = [];
    foreach (spare_candidates_for_schedule($schedule) as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $class = trim((string) ($row['class_name'] ?? ''));
        $items[] = [
            'code' => $code,
            'name' => (string) ($row['name'] ?? $code),
            'line' => $class !== '' ? ('Spare · ' . $class) : 'Spare',
            'day_off' => 0,
            'id' => (int) $row['id'],
        ];
    }
    return $items;
}

/**
 * Admin marked this unit Spare: day_off None, clear upcoming duties so it
 * appears immediately in replace / assign spare lists.
 */
function release_vehicle_to_spare(string $mode, int $vehicleId): void
{
    if ($vehicleId <= 0) {
        return;
    }
    $mode = clean_mode($mode);
    $table = schedule_vehicle_table($mode);
    $column = schedule_vehicle_column($mode);
    qexec(
        "UPDATE {$table} SET status = 'active', day_off = 0 WHERE id = ?",
        'i',
        [$vehicleId]
    );
    qexec(
        "UPDATE schedules SET {$column} = NULL
         WHERE {$column} = ? AND status = 'scheduled' AND travel_date >= CURDATE()",
        'i',
        [$vehicleId]
    );
}

function find_spare_vehicle_id(string $mode, string $typed, int $exceptId = 0): int
{
    $mode = clean_mode($mode);
    $key = trim($typed);
    if ($key === '') {
        return 0;
    }
    $code = strtoupper($key);
    $table = schedule_vehicle_table($mode);
    $column = schedule_vehicle_column($mode);
    $codeColumn = schedule_code_column($mode);
    $nameColumn = schedule_name_column($mode);
    $row = qone(
        "SELECT v.id FROM {$table} v
         WHERE v.status = 'active' AND v.day_off = 0 AND v.id <> ?
           AND (v.{$codeColumn} = ? OR v.{$nameColumn} = ? OR v.{$nameColumn} LIKE ?)
           AND NOT EXISTS (
             SELECT 1 FROM schedules s2
             WHERE s2.{$column} = v.id AND s2.status = 'scheduled' AND s2.travel_date >= CURDATE()
           )
         ORDER BY (v.{$codeColumn} = ?) DESC, (v.{$nameColumn} = ?) DESC, v.id ASC
         LIMIT 1",
        'isssss',
        [$exceptId, $code, $key, $key . '%', $code, $key]
    );
    return (int) ($row['id'] ?? 0);
}

/**
 * True spare = active, no upcoming duties, and no day off (day_off = 0).
 * Working fleet keeps a weekday day off; standby spares do not.
 */
function vehicle_is_spare(string $mode, int $vehicleId, int $exceptScheduleId = 0): bool
{
    if ($vehicleId <= 0) {
        return false;
    }
    $table = schedule_vehicle_table($mode);
    $column = schedule_vehicle_column($mode);
    $vehicle = qone("SELECT day_off FROM {$table} WHERE id = ? AND status = 'active'", 'i', [$vehicleId]);
    if (!$vehicle || normalize_day_off((int) $vehicle['day_off']) !== 0) {
        return false;
    }
    $row = qone(
        "SELECT COUNT(*) c FROM schedules
         WHERE {$column} = ? AND status = 'scheduled' AND travel_date >= CURDATE() AND id <> ?",
        'ii',
        [$vehicleId, $exceptScheduleId]
    );
    return (int) ($row['c'] ?? 0) === 0;
}

/** Whether the vehicle is resting on this travel date (day_off 0 = never). */
function vehicle_on_day_off(string $mode, int $vehicleId, string $travelDate): bool
{
    if ($vehicleId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
        return false;
    }
    $table = schedule_vehicle_table($mode);
    $row = qone("SELECT day_off FROM {$table} WHERE id = ?", 'i', [$vehicleId]);
    if (!$row) {
        return false;
    }
    $dayOff = normalize_day_off((int) $row['day_off']);
    if ($dayOff === 0) {
        return false;
    }
    $weekday = (int) date('w', strtotime($travelDate)) + 1;
    return $dayOff === $weekday;
}

/**
 * Autosuggest: standby spares only (no duties, day_off = None).
 * Fleet can still save day off on working vehicles.
 */
function schedule_vehicle_suggest_list(string $mode, ?array $schedule = null): array
{
    $mode = clean_mode($mode);
    $table = schedule_vehicle_table($mode);
    $column = schedule_vehicle_column($mode);
    $codeColumn = schedule_code_column($mode);
    $nameColumn = schedule_name_column($mode);
    $classColumn = schedule_class_column($mode);
    $items = [];
    $seen = [];

    $push = static function (array $row, string $hint = 'Spare · no day off') use (&$items, &$seen): void {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '' || isset($seen[$code])) {
            return;
        }
        $seen[$code] = true;
        $items[] = [
            'code' => $code,
            'name' => (string) ($row['name'] ?? $code),
            'line' => $hint,
            'day_off' => (int) ($row['day_off'] ?? 0),
        ];
    };

    $currentId = 0;
    $cabin = '';
    if ($schedule && (int) ($schedule['id'] ?? 0) > 0) {
        $currentId = (int) ($schedule['vehicle_id'] ?? 0);
        $cabin = trim((string) ($schedule['cabin'] ?? $schedule['cabin_name'] ?? ''));
        if ($currentId > 0) {
            $cur = vehicle_label_by_id($mode, $currentId);
            $tableRow = qone("SELECT day_off FROM {$table} WHERE id = ?", 'i', [$currentId]);
            $push([
                'code' => $cur['code'],
                'name' => $cur['name'],
                'day_off' => (int) ($tableRow['day_off'] ?? 0),
            ], 'Current vehicle');
        }
    }

    $sql = "SELECT v.{$codeColumn} AS code, v.{$nameColumn} AS name, v.day_off
            FROM {$table} v
            WHERE v.status = 'active'
              AND v.day_off = 0
              AND v.id <> ?
              AND (SELECT COUNT(*) FROM schedules s2
                    WHERE s2.{$column} = v.id AND s2.status = 'scheduled'
                      AND s2.travel_date >= CURDATE()) = 0";
    $types = 'i';
    $params = [$currentId];
    if ($cabin !== '') {
        $sql .= " AND v.{$classColumn} = ?";
        $types .= 's';
        $params[] = $cabin;
    }
    $sql .= " ORDER BY v.{$codeColumn} ASC LIMIT 400";
    foreach (qall($sql, $types, $params) as $row) {
        $push($row);
    }
    return $items;
}

/** Autosuggest for trip search filters: active fleet codes and names. */
function fleet_vehicle_filter_suggest(string $mode): array
{
    $mode = clean_mode($mode);
    $table = schedule_vehicle_table($mode);
    $codeColumn = schedule_code_column($mode);
    $nameColumn = schedule_name_column($mode);
    $rows = qall(
        "SELECT {$codeColumn} AS code, {$nameColumn} AS name, day_off
         FROM {$table}
         WHERE status = 'active'
         ORDER BY {$codeColumn} ASC
         LIMIT 800"
    );
    $items = [];
    foreach ($rows as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $dayOff = (int) ($row['day_off'] ?? 0);
        $items[] = [
            'code' => $code,
            'name' => (string) ($row['name'] ?? $code),
            'line' => $dayOff === 0 ? 'Spare' : 'Fleet',
            'day_off' => 0, // search filter: never hide by rest day
        ];
    }
    return $items;
}

function create_user_notification(int $userId, string $title, string $body, ?int $bookingId = null): void
{
    ensure_replacement_tables();
    qexec(
        'INSERT INTO user_notifications (user_id, booking_id, title, body) VALUES (?, ?, ?, ?)',
        'iiss',
        [$userId, $bookingId, substr($title, 0, 160), substr($body, 0, 1000)]
    );
}

function unread_notification_count(int $userId): int
{
    ensure_replacement_tables();
    return (int) qone(
        'SELECT COUNT(*) c FROM user_notifications WHERE user_id = ? AND is_read = 0',
        'i',
        [$userId]
    )['c'];
}

function send_vehicle_change_email(array $booking, array $payload): void
{
    try {
        $mailer = configured_mailer();
        $mailer->addAddress($booking['passenger_email'], $booking['passenger_name']);
        $mailer->Subject = 'Vehicle update — ' . $payload['origin'] . ' to ' . $payload['destination'];
        $mailer->Body = "Hello {$booking['passenger_name']},\r\n\r\n"
            . "Your Jatra trip has been updated.\r\n\r\n"
            . 'Journey: ' . $payload['origin'] . ' to ' . $payload['destination'] . "\r\n"
            . 'Date: ' . $payload['date'] . "\r\n"
            . 'Departure: ' . $payload['depart'] . "\r\n"
            . 'Reason: ' . $payload['reason'] . "\r\n"
            . 'Previous vehicle: ' . $payload['old_label'] . "\r\n"
            . 'New vehicle: ' . $payload['new_label'] . "\r\n"
            . ($payload['note'] !== '' ? 'Note: ' . $payload['note'] . "\r\n" : '')
            . "\r\nYour booking code remains {$booking['booking_code']}. "
            . "Please use the new vehicle details at boarding.\r\n\r\n"
            . "Jatra";
        $mailer->send();
    } catch (Throwable $exception) {
        error_log('Vehicle change email failed for booking ' . $booking['booking_code'] . ': ' . $exception->getMessage());
    }
}

/**
 * @return array{ok:bool,message:string,notified?:int}
 */
function replace_schedule_vehicle(int $scheduleId, int $newVehicleId, string $reason, string $note, int $adminId): array
{
    ensure_replacement_tables();
    if (!isset(replacement_reasons()[$reason])) {
        return ['ok' => false, 'message' => 'Choose a valid replacement reason.'];
    }

    $schedule = load_schedule_for_replace($scheduleId);
    if (!$schedule) {
        return ['ok' => false, 'message' => 'That trip is not open for replacement.'];
    }

    $mode = clean_mode((string) $schedule['mode']);
    $column = schedule_vehicle_column($mode);
    if (!vehicle_is_spare($mode, $newVehicleId, $scheduleId)) {
        return ['ok' => false, 'message' => 'That spare vehicle is not available for this trip.'];
    }

    $oldId = (int) $schedule['vehicle_id'];
    if ($oldId === $newVehicleId) {
        return ['ok' => false, 'message' => 'Choose a different vehicle.'];
    }

    $table = schedule_vehicle_table($mode);
    $travelDate = (string) $schedule['travel_date'];
    $departTime = (string) $schedule['depart_time'];
    $fullTakeover = replacement_grounds_vehicle($reason) ? 1 : 0;
    $affectedIds = [$scheduleId];

    if ($fullTakeover) {
        // Damage / accident / other: covering spare takes all upcoming duties from this time on.
        $more = qall(
            "SELECT id FROM schedules
             WHERE {$column} = ? AND status = 'scheduled'
               AND (
                 travel_date > ?
                 OR (travel_date = ? AND depart_time >= ?)
               )
             ORDER BY travel_date, depart_time, id",
            'isss',
            [$oldId, $travelDate, $travelDate, $departTime]
        );
        $affectedIds = array_map(static fn (array $r): int => (int) $r['id'], $more);
        if ($affectedIds) {
            qexec(
                "UPDATE schedules SET {$column} = ?
                 WHERE id IN (" . implode(',', array_map('intval', $affectedIds)) . ')',
                'i',
                [$newVehicleId]
            );
        }
        qexec("UPDATE {$table} SET status = 'maintenance' WHERE id = ?", 'i', [$oldId]);
        // Covering unit keeps no day off while it holds the duty chain.
        qexec("UPDATE {$table} SET status = 'active', day_off = 0 WHERE id = ?", 'i', [$newVehicleId]);
        $oldStatusNote = ' Old vehicle in maintenance; spare took all upcoming duties from this trip onward.';
    } else {
        // Traffic delay: only this one trip. Old keeps every other duty and stays active.
        qexec(
            "UPDATE schedules SET {$column} = ? WHERE id = ? AND status = 'scheduled'",
            'ii',
            [$newVehicleId, $scheduleId]
        );
        qexec("UPDATE {$table} SET status = 'active', day_off = 0 WHERE id = ?", 'i', [$newVehicleId]);
        $oldStatusNote = ' Old vehicle stayed active with its other duties; spare runs only this trip, then is spare again.';
    }

    qexec(
        'INSERT INTO vehicle_replacements
          (schedule_id, mode, old_vehicle_id, new_vehicle_id, reason, note, admin_id, full_takeover)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'isiissii',
        [
            $scheduleId,
            $mode,
            $oldId,
            $newVehicleId,
            $reason,
            $note !== '' ? substr($note, 0, 255) : '',
            $adminId,
            $fullTakeover,
        ]
    );

    $old = vehicle_label_by_id($mode, $oldId);
    $new = vehicle_label_by_id($mode, $newVehicleId);
    $oldLabel = trim($old['name'] . ' (' . $old['code'] . ')');
    $newLabel = trim($new['name'] . ' (' . $new['code'] . ')');
    $reasonLabel = replacement_reason_label($reason);

    $notified = 0;
    $seenUsers = [];
    $seenBookings = [];
    foreach ($affectedIds as $affectedId) {
        $trip = $affectedId === $scheduleId ? $schedule : qone(
            "SELECT s.travel_date, s.depart_time, r.origin, r.destination, r.mode
             FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = ?",
            'i',
            [$affectedId]
        );
        if (!$trip) {
            continue;
        }
        $modeName = mode_label((string) ($trip['mode'] ?? $mode ?? 'bus'));
        $title = $modeName . ' vehicle updated for your trip';
        $body = 'Your ' . $modeName . ' ' . $trip['origin'] . ' → ' . $trip['destination']
            . ' trip on ' . date_label((string) $trip['travel_date'])
            . ' at ' . clock_label((string) $trip['depart_time'])
            . ' was updated due to ' . strtolower($reasonLabel) . '. '
            . 'New vehicle: ' . $newLabel . '.'
            . ($note !== '' ? ' Note: ' . $note : '');
        $payload = [
            'origin' => (string) $trip['origin'],
            'destination' => (string) $trip['destination'],
            'date' => date_label((string) $trip['travel_date']),
            'depart' => clock_label((string) $trip['depart_time']),
            'reason' => $reasonLabel,
            'old_label' => $oldLabel,
            'new_label' => $newLabel,
            'note' => $note,
        ];
        $bookings = qall(
            "SELECT id, user_id, booking_code, passenger_name, passenger_email
             FROM bookings
             WHERE schedule_id = ? AND booking_status IN ('confirmed', 'pending')
             ORDER BY id",
            'i',
            [$affectedId]
        );
        foreach ($bookings as $booking) {
            $bookingId = (int) $booking['id'];
            if (isset($seenBookings[$bookingId])) {
                continue;
            }
            $seenBookings[$bookingId] = true;
            $userId = (int) $booking['user_id'];
            if ($userId > 0 && !isset($seenUsers[$userId])) {
                create_user_notification($userId, $title, $body, $bookingId);
                $seenUsers[$userId] = true;
            }
            send_vehicle_change_email($booking, $payload);
            $notified++;
        }
    }

    return [
        'ok' => true,
        'message' => 'Vehicle replaced. Notified ' . $notified . ' booking' . ($notified === 1 ? '' : 's') . '.' . $oldStatusNote,
        'notified' => $notified,
    ];
}
