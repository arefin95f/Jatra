<?php

require_once __DIR__ . '/timetable.php';
require_once __DIR__ . '/fleet_replace.php';

/** Active passenger bookings on a schedule (confirmed / pending). */
function schedule_active_booking_count(int $scheduleId): int
{
    if ($scheduleId <= 0) {
        return 0;
    }
    $row = qone(
        "SELECT COUNT(*) AS c FROM bookings
         WHERE schedule_id = ? AND booking_status IN ('confirmed', 'pending')",
        'i',
        [$scheduleId]
    );
    return (int) ($row['c'] ?? 0);
}

/**
 * Booked trip: From/To stay fixed. Departure time can change on today or a future day.
 * On the travel day the new time cannot be in the past.
 *
 * @return array{ok:bool,message:string,updated?:int,notified?:int}
 */
function apply_booked_trip_time_update(
    string $mode,
    int $scheduleId,
    int $vehicleId,
    string $newDepartHm,
    float $fare
): array {
    $mode = clean_mode($mode);
    $column = schedule_vehicle_column($mode);
    if ($scheduleId <= 0 || $vehicleId <= 0) {
        return ['ok' => false, 'message' => 'Missing schedule or vehicle.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $newDepartHm)) {
        return ['ok' => false, 'message' => 'Enter a valid departure time.'];
    }
    $current = qone(
        "SELECT s.*, r.origin, r.destination, r.duration_minutes
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.id = ? AND s.status = 'scheduled'",
        'i',
        [$scheduleId]
    );
    if (!$current) {
        return ['ok' => false, 'message' => 'That schedule was not found.'];
    }
    if ((int) ($current[$column] ?? 0) !== $vehicleId) {
        return ['ok' => false, 'message' => 'To change the vehicle on one trip, use Replace vehicle.'];
    }
    if (schedule_active_booking_count($scheduleId) <= 0) {
        return ['ok' => false, 'message' => 'That trip has no bookings — use the normal schedule edit.'];
    }

    // Fare stays as sold; only departure time may move.
    $fare = (float) $current['fare'];

    $today = (string) (qone('SELECT CURDATE() AS today')['today'] ?? date('Y-m-d'));
    $travelDate = (string) $current['travel_date'];
    if ($travelDate < $today) {
        return ['ok' => false, 'message' => 'Past trips cannot be changed.'];
    }

    $newDepart = $newDepartHm . ':00';
    $oldDepartHm = substr((string) $current['depart_time'], 0, 5);
    if ($travelDate === $today) {
        $nowHm = (string) (qone('SELECT DATE_FORMAT(NOW(), \'%H:%i\') AS t')['t'] ?? date('H:i'));
        if ($newDepartHm < $nowHm) {
            return ['ok' => false, 'message' => 'Departure time cannot be in the past.'];
        }
    }

    $routeId = (int) $current['route_id'];
    $useCabin = (string) ($current['cabin_name'] ?? '');
    $duration = (int) ($current['duration_minutes'] ?? 0);

    $busy = qone(
        "SELECT s.id FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.{$column} = ? AND s.status = 'scheduled' AND s.id <> ?
           AND TIMESTAMP(s.travel_date, s.depart_time) < DATE_ADD(TIMESTAMP(?, ?), INTERVAL ? MINUTE)
           AND DATE_ADD(TIMESTAMP(s.travel_date, s.depart_time), INTERVAL r.duration_minutes MINUTE) > TIMESTAMP(?, ?)",
        'iississ',
        [$vehicleId, $scheduleId, $travelDate, $newDepart, $duration, $travelDate, $newDepart]
    );
    if ($busy) {
        return ['ok' => false, 'message' => 'That vehicle is already on another trip at this time.'];
    }

    $clash = qone(
        "SELECT id FROM schedules
         WHERE route_id = ? AND travel_date = ? AND depart_time = ? AND cabin_name = ?
           AND id <> ? AND status IN ('scheduled', 'cancelled')",
        'isssi',
        [$routeId, $travelDate, $newDepart, $useCabin, $scheduleId]
    );
    if ($clash) {
        return ['ok' => false, 'message' => 'That class already departs at this time on this route.'];
    }

    $timeChanged = $oldDepartHm !== $newDepartHm;
    if (!$timeChanged) {
        return ['ok' => true, 'message' => 'No changes to save.', 'updated' => 0, 'notified' => 0];
    }

    // Keep route, date, and fare fixed; only departure time may move.
    qexec(
        'UPDATE schedules SET depart_time = ? WHERE id = ? AND status = ?',
        'sis',
        [$newDepart, $scheduleId, 'scheduled']
    );

    $vehicleLabel = format_vehicle_notice_label($mode, $vehicleId);
    $title = 'Your ' . mode_label($mode) . ' departure was updated';
    $body = 'Your ' . mode_label($mode) . ' ' . $current['origin'] . ' → ' . $current['destination']
        . ' trip on ' . date_label($travelDate)
        . ' departure changed from ' . clock_label($oldDepartHm)
        . ' to ' . clock_label($newDepart)
        . '.'
        . ($vehicleLabel !== '' ? ' Vehicle: ' . $vehicleLabel . '.' : '');
    $notice = notify_schedule_pattern_bookings([$scheduleId], $title, $body);

    $msg = 'Departure time updated for this booked trip.';
    $msg .= schedule_passenger_notice_sentence($notice['notified'], $notice['emailed']);

    return [
        'ok' => true,
        'message' => $msg,
        'updated' => 1,
        'notified' => $notice['notified'],
    ];
}

function schedule_passenger_notice_sentence(int $notified, int $emailed): string
{
    if ($notified <= 0) {
        return '';
    }
    $line = ' Notified ' . $notified . ' passenger(s) in the app';
    if ($emailed === $notified) {
        return $line . ' and by email.';
    }
    if ($emailed > 0) {
        return $line . '. Emailed ' . $emailed . ' of them.';
    }
    return $line . '. Email could not be sent.';
}

/**
 * In-app notice once per account, and one email per booking, for the given schedules.
 *
 * @return array{notified:int,emailed:int}
 */
function notify_schedule_pattern_bookings(array $scheduleIds, string $title, string $body): array
{
    ensure_replacement_tables();
    $notified = 0;
    $emailed = 0;
    $seenUsers = [];
    $seenBookings = [];
    foreach ($scheduleIds as $scheduleId) {
        $scheduleId = (int) $scheduleId;
        if ($scheduleId <= 0) {
            continue;
        }
        $bookings = qall(
            "SELECT id, user_id, booking_code, passenger_name, passenger_email, seat_label
             FROM bookings
             WHERE schedule_id = ? AND booking_status IN ('confirmed', 'pending')
             ORDER BY id",
            'i',
            [$scheduleId]
        );
        foreach ($bookings as $booking) {
            $bookingId = (int) ($booking['id'] ?? 0);
            if ($bookingId <= 0 || isset($seenBookings[$bookingId])) {
                continue;
            }
            $seenBookings[$bookingId] = true;
            $reached = false;
            $userId = (int) ($booking['user_id'] ?? 0);
            if ($userId > 0 && !isset($seenUsers[$userId])) {
                create_user_notification($userId, $title, $body, $bookingId);
                $seenUsers[$userId] = true;
                $reached = true;
            }
            if (send_schedule_change_email($booking, $title, $body)) {
                $emailed++;
                $reached = true;
            }
            if ($reached) {
                $notified++;
            }
        }
    }
    return ['notified' => $notified, 'emailed' => $emailed];
}

function send_schedule_change_email(array $booking, string $title, string $body): bool
{
    $email = trim((string) ($booking['passenger_email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $name = trim((string) ($booking['passenger_name'] ?? ''));
    $code = trim((string) ($booking['booking_code'] ?? ''));
    $seat = trim((string) ($booking['seat_label'] ?? ''));
    try {
        $mailer = configured_mailer();
        $mailer->addAddress($email, $name !== '' ? $name : $email);
        $mailer->Subject = $title;
        $mailer->Body = 'Hello ' . ($name !== '' ? $name : 'passenger') . ",\r\n\r\n"
            . $body . "\r\n\r\n"
            . ($code !== '' ? 'Booking code: ' . $code . "\r\n" : '')
            . ($seat !== '' ? 'Seat: ' . $seat . "\r\n" : '')
            . "\r\nPlease use these updated details for your trip.\r\n\r\n"
            . "Jatra";
        $mailer->send();
        return true;
    } catch (Throwable $exception) {
        error_log('Schedule change email failed for booking ' . ($code !== '' ? $code : $email) . ': ' . $exception->getMessage());
        return false;
    }
}

function sync_weekly_slot_pattern(
    int $oldRouteId,
    int $weekday,
    string $oldDepart,
    int $newRouteId,
    string $newDepart
): void {
    ensure_weekly_table();
    $weekday = max(1, min(7, $weekday));
    $oldDepart = strlen($oldDepart) === 5 ? $oldDepart . ':00' : $oldDepart;
    $newDepart = strlen($newDepart) === 5 ? $newDepart . ':00' : $newDepart;

    $old = qone(
        'SELECT id FROM weekly_slots WHERE route_id = ? AND weekday = ? AND depart_time = ?',
        'iis',
        [$oldRouteId, $weekday, $oldDepart]
    );
    $clash = qone(
        'SELECT id FROM weekly_slots WHERE route_id = ? AND weekday = ? AND depart_time = ?',
        'iis',
        [$newRouteId, $weekday, $newDepart]
    );

    if ($old && $clash && (int) $old['id'] === (int) $clash['id']) {
        return;
    }
    if ($old && !$clash) {
        qexec(
            'UPDATE weekly_slots SET route_id = ?, depart_time = ? WHERE id = ?',
            'isi',
            [$newRouteId, $newDepart, (int) $old['id']]
        );
        return;
    }
    if ($old && $clash) {
        qexec('DELETE FROM weekly_slots WHERE id = ?', 'i', [(int) $old['id']]);
        return;
    }
    qexec(
        "INSERT IGNORE INTO weekly_slots (route_id, weekday, depart_time, status) VALUES (?, ?, ?, 'active')",
        'iis',
        [$newRouteId, $weekday, $newDepart]
    );
}

/**
 * Weekdays (1–7) that already have upcoming scheduled trips for this vehicle.
 *
 * @return array<int,true>
 */
function vehicle_busy_weekdays(string $mode, int $vehicleId): array
{
    $mode = clean_mode($mode);
    $column = schedule_vehicle_column($mode);
    if ($vehicleId <= 0) {
        return [];
    }
    $rows = qall(
        "SELECT DISTINCT DAYOFWEEK(s.travel_date) AS weekday
         FROM schedules s
         WHERE s.{$column} = ?
           AND s.status = 'scheduled'
           AND s.travel_date >= CURDATE()",
        'i',
        [$vehicleId]
    );
    $busy = [];
    foreach ($rows as $row) {
        $day = (int) ($row['weekday'] ?? 0);
        if ($day >= 1 && $day <= 7) {
            $busy[$day] = true;
        }
    }
    return $busy;
}

/**
 * Update one schedule row (the trip opened via Edit).
 * Vehicle stays as-is; swaps use Replace vehicle.
 *
 * @return array{ok:bool,message:string,updated?:int,notified?:int}
 */
function apply_single_schedule_update(
    string $mode,
    int $scheduleId,
    int $vehicleId,
    int $newRouteId,
    string $travelDate,
    string $newDepartHm,
    float $fare,
    string $cabin
): array {
    $mode = clean_mode($mode);
    $column = schedule_vehicle_column($mode);
    if ($scheduleId <= 0 || $vehicleId <= 0 || $newRouteId <= 0) {
        return ['ok' => false, 'message' => 'Missing schedule or vehicle.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
        return ['ok' => false, 'message' => 'Enter a valid date.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $newDepartHm)) {
        return ['ok' => false, 'message' => 'Enter a valid departure time.'];
    }
    if ($fare <= 0) {
        return ['ok' => false, 'message' => 'Enter a fare above zero.'];
    }

    $current = qone(
        "SELECT s.*, r.origin, r.destination, r.duration_minutes
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.id = ? AND s.status = 'scheduled'",
        'i',
        [$scheduleId]
    );
    if (!$current) {
        return ['ok' => false, 'message' => 'That schedule was not found.'];
    }
    if ((int) ($current[$column] ?? 0) !== $vehicleId) {
        return ['ok' => false, 'message' => 'To change the vehicle on one trip, use Replace vehicle.'];
    }
    if (schedule_active_booking_count($scheduleId) > 0) {
        return apply_booked_trip_time_update($mode, $scheduleId, $vehicleId, $newDepartHm, $fare);
    }

    $route = qone(
        'SELECT id, origin, destination, duration_minutes FROM routes WHERE id = ? AND status = ?',
        'is',
        [$newRouteId, 'active']
    );
    if (!$route) {
        return ['ok' => false, 'message' => 'That route is not active.'];
    }

    $newDepart = $newDepartHm . ':00';
    $useCabin = $cabin !== '' ? $cabin : (string) ($current['cabin_name'] ?? '');
    $duration = (int) ($route['duration_minutes'] ?? $current['duration_minutes'] ?? 0);

    $busy = qone(
        "SELECT s.id FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.{$column} = ? AND s.status = 'scheduled' AND s.id <> ?
           AND TIMESTAMP(s.travel_date, s.depart_time) < DATE_ADD(TIMESTAMP(?, ?), INTERVAL ? MINUTE)
           AND DATE_ADD(TIMESTAMP(s.travel_date, s.depart_time), INTERVAL r.duration_minutes MINUTE) > TIMESTAMP(?, ?)",
        'iississ',
        [$vehicleId, $scheduleId, $travelDate, $newDepart, $duration, $travelDate, $newDepart]
    );
    if ($busy) {
        return ['ok' => false, 'message' => 'That vehicle is already on another trip at this time.'];
    }

    $clash = qone(
        "SELECT id FROM schedules
         WHERE route_id = ? AND travel_date = ? AND depart_time = ? AND cabin_name = ?
           AND id <> ? AND status IN ('scheduled', 'cancelled')",
        'isssi',
        [$newRouteId, $travelDate, $newDepart, $useCabin, $scheduleId]
    );
    if ($clash) {
        return ['ok' => false, 'message' => 'That class already departs at this time on this route.'];
    }

    $changed = (int) $current['route_id'] !== $newRouteId
        || (string) $current['travel_date'] !== $travelDate
        || substr((string) $current['depart_time'], 0, 5) !== $newDepartHm
        || abs((float) $current['fare'] - $fare) > 0.009;

    qexec(
        "UPDATE schedules
         SET route_id = ?, bus_id = NULL, train_id = NULL, plane_id = NULL,
             {$column} = ?, travel_date = ?, depart_time = ?, fare = ?, cabin_name = ?
         WHERE id = ? AND status = 'scheduled'",
        'iissdsi',
        [$newRouteId, $vehicleId, $travelDate, $newDepart, $fare, $useCabin, $scheduleId]
    );

    $notified = 0;
    $emailed = 0;
    if ($changed) {
        $vehicleLabel = format_vehicle_notice_label($mode, $vehicleId);
        $title = 'Your ' . mode_label($mode) . ' trip was updated';
        $body = 'Your ' . mode_label($mode) . ' ' . $route['origin'] . ' → ' . $route['destination']
            . ' trip on ' . date_label($travelDate)
            . ' now departs at ' . clock_label($newDepart)
            . '. Fare: ' . money($fare) . '.'
            . ($vehicleLabel !== '' ? ' Vehicle: ' . $vehicleLabel . '.' : '');
        $notice = notify_schedule_pattern_bookings([$scheduleId], $title, $body);
        $notified = $notice['notified'];
        $emailed = $notice['emailed'];
    }

    $msg = 'Schedule updated.';
    $msg .= schedule_passenger_notice_sentence($notified, $emailed);

    return [
        'ok' => true,
        'message' => $msg,
        'updated' => 1,
        'notified' => $notified,
    ];
}

/**
 * Update this vehicle's matching weekday slot from an effective date onward.
 * Slot key = same vehicle + same original depart_time + weekday.
 * Vehicle swaps for a single trip stay on Replace vehicle.
 *
 * @return array{ok:bool,message:string,updated?:int,notified?:int}
 */
function apply_vehicle_weekday_pattern_update(
    string $mode,
    int $editScheduleId,
    int $vehicleId,
    int $newRouteId,
    string $effectiveDate,
    int $weekday,
    string $newDepartHm,
    float $fare,
    string $cabin
): array {
    $mode = clean_mode($mode);
    $column = schedule_vehicle_column($mode);
    $weekday = max(1, min(7, $weekday));
    if ($editScheduleId <= 0 || $vehicleId <= 0 || $newRouteId <= 0) {
        return ['ok' => false, 'message' => 'Missing schedule or vehicle.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
        return ['ok' => false, 'message' => 'Enter a valid effective date.'];
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $newDepartHm)) {
        return ['ok' => false, 'message' => 'Enter a valid departure time.'];
    }
    if ($fare <= 0) {
        return ['ok' => false, 'message' => 'Enter a fare above zero.'];
    }

    $current = qone(
        "SELECT s.*, r.origin, r.destination
         FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.id = ? AND s.status = 'scheduled'",
        'i',
        [$editScheduleId]
    );
    if (!$current) {
        return ['ok' => false, 'message' => 'That schedule was not found.'];
    }
    if ((int) ($current[$column] ?? 0) !== $vehicleId) {
        return ['ok' => false, 'message' => 'To change the vehicle on one trip, use Replace vehicle.'];
    }
    if (schedule_active_booking_count($editScheduleId) > 0) {
        return apply_booked_trip_time_update($mode, $editScheduleId, $vehicleId, $newDepartHm, $fare);
    }

    $oldRouteId = (int) $current['route_id'];
    $oldDepart = (string) $current['depart_time'];
    $newDepart = $newDepartHm . ':00';
    $route = qone(
        'SELECT id, origin, destination FROM routes WHERE id = ? AND status = ?',
        'is',
        [$newRouteId, 'active']
    );
    if (!$route) {
        return ['ok' => false, 'message' => 'That route is not active.'];
    }

    // Match this vehicle's same weekday + depart slot from the effective date.
    // Always include the row that was opened via Edit.
    $targets = qall(
        "SELECT s.id, s.travel_date, s.depart_time, s.route_id, s.cabin_name
         FROM schedules s
         WHERE s.status = 'scheduled'
           AND (
                s.id = ?
                OR (
                    s.{$column} = ?
                    AND s.travel_date >= ?
                    AND DAYOFWEEK(s.travel_date) = ?
                    AND s.depart_time = ?
                )
           )
         ORDER BY (s.id = ?) DESC, s.travel_date, s.id",
        'iisisi',
        [$editScheduleId, $vehicleId, $effectiveDate, $weekday, $oldDepart, $editScheduleId]
    );
    if (!$targets) {
        return ['ok' => false, 'message' => 'No matching trips found for that weekday from the effective date.'];
    }

    $updatedIds = [];
    $skipped = 0;
    $skippedBooked = 0;
    $seen = [];
    foreach ($targets as $row) {
        $sid = (int) $row['id'];
        if (isset($seen[$sid])) {
            continue;
        }
        $seen[$sid] = true;
        // Booked trips keep From/To. Change their time on the booked-trip form.
        if (schedule_active_booking_count($sid) > 0) {
            $skippedBooked++;
            continue;
        }
        $clash = qone(
            "SELECT id FROM schedules
             WHERE route_id = ? AND travel_date = ? AND depart_time = ? AND cabin_name = ?
               AND id <> ? AND status IN ('scheduled', 'cancelled')",
            'isssi',
            [$newRouteId, $row['travel_date'], $newDepart, $cabin !== '' ? $cabin : (string) $row['cabin_name'], $sid]
        );
        if ($clash) {
            if ($sid === $editScheduleId) {
                $other = qone(
                    "SELECT s.depart_time, s.cabin_name, r.origin, r.destination,
                            COALESCE(b.name, tn.name, pl.airline) AS vehicle_name,
                            COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS vehicle_code
                     FROM schedules s
                     JOIN routes r ON r.id = s.route_id
                     LEFT JOIN buses b ON b.id = s.bus_id
                     LEFT JOIN trains tn ON tn.id = s.train_id
                     LEFT JOIN planes pl ON pl.id = s.plane_id
                     WHERE s.id = ?",
                    'i',
                    [(int) $clash['id']]
                );
                $className = $cabin !== '' ? $cabin : (string) ($row['cabin_name'] ?? 'That class');
                $who = trim((string) (($other['vehicle_name'] ?? '') . ' ' . ($other['vehicle_code'] ?? '')));
                $where = trim((string) (($other['origin'] ?? '') . ' → ' . ($other['destination'] ?? '')));
                $when = $other ? clock_label((string) $other['depart_time']) : clock_label($newDepart);
                $message = $className . ' already departs ' . ($where !== '→' ? $where : 'that route') . ' at ' . $when;
                if ($who !== '') {
                    $message .= ' (' . $who . ')';
                }
                $message .= '. Choose another time or route.';
                return ['ok' => false, 'message' => $message];
            }
            $skipped++;
            continue;
        }
        $useCabin = $cabin !== '' ? $cabin : (string) $row['cabin_name'];
        qexec(
            "UPDATE schedules
             SET route_id = ?, bus_id = NULL, train_id = NULL, plane_id = NULL,
                 {$column} = ?, travel_date = travel_date, depart_time = ?, fare = ?, cabin_name = ?
             WHERE id = ? AND status = 'scheduled'",
            'iisdsi',
            [$newRouteId, $vehicleId, $newDepart, $fare, $useCabin, $sid]
        );
        $updatedIds[] = $sid;
    }

    if (!$updatedIds) {
        if ($skippedBooked > 0) {
            return [
                'ok' => false,
                'message' => 'Matching trips already have bookings. From/To stay fixed. Open each booked trip and change its departure time there.',
            ];
        }
        return ['ok' => false, 'message' => 'Could not update trips — time/route clashes with existing schedules.'];
    }

    sync_weekly_slot_pattern($oldRouteId, $weekday, $oldDepart, $newRouteId, $newDepart);

    // Pattern updates only touch unbooked trips — no passenger notify here.
    $notified = 0;

    $msg = 'Updated ' . count($updatedIds) . ' ' . weekday_name($weekday)
        . ' trip(s) from ' . date_label($effectiveDate) . '.';
    if ($skippedBooked > 0) {
        $msg .= ' Left ' . $skippedBooked . ' booked trip(s) unchanged.';
    }
    if ($skipped > 0) {
        $msg .= ' Skipped ' . $skipped . ' clash(es).';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'updated' => count($updatedIds),
        'notified' => $notified,
    ];
}
