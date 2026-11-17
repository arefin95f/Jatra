<?php
require __DIR__ . '/includes/bootstrap.php';

$scheduleId = (int) ($_POST['schedule'] ?? $_GET['schedule'] ?? 0);
header('Content-Type: application/json');
if ($scheduleId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose a trip.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $seat = strtoupper(trim($_POST['seat'] ?? ''));
    $keep = ($_POST['keep'] ?? '1') === '1';
    $trip = qone(trip_sql() . " WHERE s.id = ? AND s.status = 'scheduled'", 'i', [$scheduleId]);
    if (
        !$trip
        || strtotime($trip['travel_date'] . ' ' . $trip['depart_time']) <= time()
        || trip_on_day_off($trip)
        || ($trip['carrier_status'] ?? '') !== 'active'
        || !seat_exists_for_trip($trip, $seat)
    ) {
        echo json_encode(array_merge(hold_payload($scheduleId), ['ok' => false, 'message' => 'Choose a seat on this coach.']));
        exit;
    }
    $max = tickets_remaining(current_user());
    if (!$keep) {
        release_seat($scheduleId, $seat);
        echo json_encode(array_merge(hold_payload($scheduleId), ['ok' => true, 'message' => '']));
        exit;
    }
    $mine = my_holds($scheduleId);
    if (!in_array($seat, $mine, true) && count($mine) >= $max) {
        $message = $max === 0
            ? 'You have booked 6 tickets in the last 24 hours.'
            : 'You can book ' . $max . ' more ' . ($max === 1 ? 'ticket' : 'tickets') . ' in the next 24 hours.';
        echo json_encode(array_merge(hold_payload($scheduleId), ['ok' => false, 'message' => $message]));
        exit;
    }
    $holdConflict = hold_conflict_message($scheduleId);
    if ($holdConflict !== null) {
        echo json_encode(array_merge(hold_payload($scheduleId), ['ok' => false, 'message' => $holdConflict]));
        exit;
    }
    $ok = claim_seat($scheduleId, $seat, $max);
    echo json_encode(array_merge(hold_payload($scheduleId), [
        'ok' => $ok,
        'message' => $ok ? '' : (hold_conflict_message($scheduleId) ?? 'That seat is already selected by another passenger.'),
    ]));
    exit;
}

echo json_encode(hold_payload($scheduleId));
