<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/fleet_replace.php';
require_once dirname(__DIR__) . '/includes/schedule_pattern.php';
require_admin();

$mode = clean_mode($_POST['mode'] ?? $_GET['mode'] ?? 'bus');
$routes = qall("SELECT id, origin, destination, base_fare FROM routes WHERE status = 'active' AND mode = ? ORDER BY origin, destination", 's', [$mode]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'cancel') {
        qexec("UPDATE schedules SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'", 'i', [$id]);
        flash('ok', 'Schedule cancelled.');
        redirect('schedules.php');
    }
    $mode = clean_mode($_POST['mode'] ?? 'bus');
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $vehicleKey = trim($_POST['vehicle_code'] ?? '');
    $vehicleCode = strtoupper($vehicleKey);
    $date = trim($_POST['travel_date'] ?? '');
    $time = trim($_POST['depart_time'] ?? '');
    $fare = (float) ($_POST['fare'] ?? 0);
    $weekday = (int) ($_POST['weekday'] ?? 0);
    $route = qone(
        'SELECT id, base_fare, duration_minutes FROM routes
         WHERE status = ? AND mode = ? AND origin = ? AND destination = ?',
        'ssss',
        ['active', $mode, $origin, $destination]
    );
    $vehicle = null;
    if ($vehicleKey !== '') {
        $vehicle = match ($mode) {
            'train' => qone(
                'SELECT id, seat_class AS cabin, day_off FROM trains
                 WHERE status = ? AND (train_number = ? OR name = ? OR name LIKE ?)
                 ORDER BY (train_number = ?) DESC, (name = ?) DESC, id ASC LIMIT 1',
                'ssssss',
                ['active', $vehicleCode, $vehicleKey, $vehicleKey . '%', $vehicleCode, $vehicleKey]
            ),
            'plane' => qone(
                'SELECT id, cabin, day_off FROM planes
                 WHERE status = ? AND (flight_number = ? OR airline = ? OR airline LIKE ?)
                 ORDER BY (flight_number = ?) DESC, (airline = ?) DESC, id ASC LIMIT 1',
                'ssssss',
                ['active', $vehicleCode, $vehicleKey, $vehicleKey . '%', $vehicleCode, $vehicleKey]
            ),
            default => qone(
                'SELECT id, coach_type AS cabin, day_off FROM buses
                 WHERE status = ? AND (bus_number = ? OR name = ? OR name LIKE ?)
                 ORDER BY (bus_number = ?) DESC, (name = ?) DESC, id ASC LIMIT 1',
                'ssssss',
                ['active', $vehicleCode, $vehicleKey, $vehicleKey . '%', $vehicleCode, $vehicleKey]
            ),
        };
    }
    if ($weekday < 1 || $weekday > 7) {
        $weekday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? (int) date('w', strtotime($date)) + 1
            : 0;
    }
    if (!$route || !$vehicle || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time) || $fare <= 0 || $weekday < 1) {
        flash('err', 'Enter From and To, vehicle, weekday, effective date, time, and fare.');
        redirect('schedules.php?mode=' . $mode . ($id ? '&id=' . $id : ''));
    }
    $routeId = (int) $route['id'];
    $vehicleId = (int) $vehicle['id'];
    $column = schedule_vehicle_column($mode);
    $cabin = (string) ($vehicle['cabin'] ?? '');
    $duration = (int) $route['duration_minutes'];

    if ($id > 0) {
        $current = qone("SELECT * FROM schedules WHERE id = ? AND status = 'scheduled'", 'i', [$id]);
        if (!$current) {
            flash('err', 'That schedule was not found.');
            redirect('schedules.php?mode=' . $mode);
        }
        $hasBookings = schedule_active_booking_count($id) > 0;
        if ($hasBookings) {
            if ($routeId !== (int) $current['route_id']) {
                flash('err', 'This trip already has bookings. From and To cannot be changed.');
                redirect('schedules.php?mode=' . $mode . '&id=' . $id . '&date=' . urlencode((string) $current['travel_date']) . '#schedule-form');
            }
            $result = apply_booked_trip_time_update($mode, $id, $vehicleId, $time, $fare);
            flash($result['ok'] ? 'ok' : 'err', $result['message']);
            redirect('schedules.php?mode=' . $mode . '&id=' . $id . '&date=' . urlencode((string) $current['travel_date']) . '#schedule-form');
        }
        $originalWeekday = (int) date('w', strtotime((string) $current['travel_date'])) + 1;
        $busyWeekdays = vehicle_busy_weekdays($mode, $vehicleId);
        // Other weekdays that already have trips are blocked; this trip's weekday is allowed.
        if (isset($busyWeekdays[$weekday]) && $weekday !== $originalWeekday) {
            flash('err', 'That vehicle already has a schedule on ' . weekday_name($weekday) . '. Keep ' . weekday_name($originalWeekday) . ' to edit this trip.');
            redirect('schedules.php?mode=' . $mode . '&id=' . $id);
        }
        // Unbooked: editing replaces that weekday pattern from the effective date.
        $result = apply_vehicle_weekday_pattern_update(
            $mode,
            $id,
            $vehicleId,
            $routeId,
            $date,
            $weekday,
            $time,
            $fare,
            $cabin
        );
        flash($result['ok'] ? 'ok' : 'err', $result['message']);
        if (!$result['ok']) {
            redirect('schedules.php?mode=' . $mode . '&id=' . $id . '&date=' . urlencode((string) $current['travel_date']) . '#schedule-form');
        }
        redirect('schedules.php?mode=' . $mode . '&date=' . urlencode($date) . '#trips');
    }

    // Add new dated trip ? no passenger notify.
    if (!vehicle_is_spare($mode, $vehicleId, 0)) {
        flash('err', 'That vehicle is already assigned. Pick a spare, or unassign it first.');
        redirect('schedules.php?mode=' . $mode);
    }
    if (vehicle_on_day_off($mode, $vehicleId, $date)) {
        flash('err', 'That vehicle is on day off for this date. Pick another spare (day off stays saved in fleet).');
        redirect('schedules.php?mode=' . $mode);
    }
    $busy = qone(
        "SELECT s.id FROM schedules s
         JOIN routes r ON r.id = s.route_id
         WHERE s.$column = ? AND s.status = 'scheduled'
           AND TIMESTAMP(s.travel_date, s.depart_time) < DATE_ADD(TIMESTAMP(?, ?), INTERVAL ? MINUTE)
           AND DATE_ADD(TIMESTAMP(s.travel_date, s.depart_time), INTERVAL r.duration_minutes MINUTE) > TIMESTAMP(?, ?)",
        'ississ',
        [$vehicleId, $date, $time . ':00', $duration, $date, $time . ':00']
    );
    if ($busy) {
        flash('err', 'That vehicle is already on another trip at this time.');
        redirect('schedules.php?mode=' . $mode);
    }
    try {
        qexec(
            "INSERT INTO schedules (route_id, {$column}, travel_date, depart_time, fare, status, cabin_name)
             VALUES (?, ?, ?, ?, ?, 'scheduled', ?)",
            'iissds',
            [$routeId, $vehicleId, $date, $time . ':00', $fare, $cabin]
        );
        sync_weekly_slot_pattern($routeId, $weekday, $time . ':00', $routeId, $time . ':00');
        flash('ok', 'Schedule added. Passengers will see it when they search.');
    } catch (mysqli_sql_exception $ex) {
        flash('err', (int) $ex->getCode() === 1062 ? 'That class already departs at this time.' : 'Could not save the schedule.');
    }
    redirect('schedules.php?mode=' . $mode . '&date=' . urlencode($date));
}

$edit = isset($_GET['id']) ? qone("SELECT * FROM schedules WHERE id = ? AND status = 'scheduled'", 'i', [(int) $_GET['id']]) : null;
$vehicleCode = '';
$editOrigin = '';
$editDestination = '';
$editWeekday = (int) date('w', strtotime($appToday)) + 1;
$editVehicleId = 0;
$editHasBookings = false;
$busyWeekdays = [];
$vehicleSuggestCtx = null;
if ($edit) {
    $editHasBookings = schedule_active_booking_count((int) $edit['id']) > 0;
    $editWeekday = (int) date('w', strtotime((string) $edit['travel_date'])) + 1;
    $codeRow = null;
    if ((int) $edit['train_id'] > 0) {
        $codeRow = qone('SELECT train_number AS code FROM trains WHERE id = ?', 'i', [(int) $edit['train_id']]);
    } elseif ((int) $edit['plane_id'] > 0) {
        $codeRow = qone('SELECT flight_number AS code FROM planes WHERE id = ?', 'i', [(int) $edit['plane_id']]);
    } elseif ((int) $edit['bus_id'] > 0) {
        $codeRow = qone('SELECT bus_number AS code FROM buses WHERE id = ?', 'i', [(int) $edit['bus_id']]);
    }
    $vehicleCode = $codeRow['code'] ?? '';
    $editRoute = qone('SELECT origin, destination, duration_minutes FROM routes WHERE id = ?', 'i', [(int) $edit['route_id']]);
    $editOrigin = (string) ($editRoute['origin'] ?? '');
    $editDestination = (string) ($editRoute['destination'] ?? '');
    $editVehicleId = (int) ($edit['bus_id'] ?: $edit['train_id'] ?: $edit['plane_id'] ?: 0);
    $busyWeekdays = vehicle_busy_weekdays($mode, $editVehicleId);
    $vehicleSuggestCtx = [
        'id' => (int) $edit['id'],
        'mode' => $mode,
        'travel_date' => (string) $edit['travel_date'],
        'depart_time' => (string) $edit['depart_time'],
        'duration_minutes' => (int) ($editRoute['duration_minutes'] ?? 0),
        'origin' => $editOrigin,
        'cabin' => (string) ($edit['cabin_name'] ?? ''),
        'vehicle_id' => $editVehicleId,
    ];
}
$vehicleSuggest = schedule_vehicle_suggest_list($mode, $vehicleSuggestCtx);

$fromFind = trim($_GET['from'] ?? '');
$toFind = trim($_GET['to'] ?? '');
$vehicleFind = trim($_GET['vehicle'] ?? '');
$dateFind = trim($_GET['date'] ?? $appToday);
if ($dateFind === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFind) || $dateFind < $appToday) {
    $dateFind = $appToday;
}
$filterSuggest = fleet_vehicle_filter_suggest($mode);
$filterQuery = ($fromFind !== '' ? '&from=' . urlencode($fromFind) : '')
    . ($toFind !== '' ? '&to=' . urlencode($toFind) : '')
    . '&date=' . urlencode($dateFind)
    . ($vehicleFind !== '' ? '&vehicle=' . urlencode($vehicleFind) : '');

$listFrom = "FROM schedules s
     JOIN routes r ON r.id = s.route_id
     LEFT JOIN buses b ON b.id = s.bus_id
     LEFT JOIN trains tn ON tn.id = s.train_id
     LEFT JOIN planes pl ON pl.id = s.plane_id
     WHERE r.mode = ?
       AND s.travel_date = ?
       AND s.status = 'scheduled'
       AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL";
$scheduleTypes = 'ss';
$scheduleParams = [$mode, $dateFind];
if ($fromFind !== '') {
    $listFrom .= ' AND r.origin LIKE ?';
    $scheduleTypes .= 's';
    $scheduleParams[] = '%' . $fromFind . '%';
}
if ($toFind !== '') {
    $listFrom .= ' AND r.destination LIKE ?';
    $scheduleTypes .= 's';
    $scheduleParams[] = '%' . $toFind . '%';
}
if ($vehicleFind !== '') {
    $listFrom .= " AND (COALESCE(b.bus_number, tn.train_number, pl.flight_number) LIKE ?
        OR COALESCE(b.name, tn.name, pl.airline) LIKE ?)";
    $like = '%' . $vehicleFind . '%';
    $scheduleTypes .= 'ss';
    array_push($scheduleParams, $like, $like);
}
$perPage = 15;
$totalDayTrips = (int) qone('SELECT COUNT(*) c ' . $listFrom, $scheduleTypes, $scheduleParams)['c'];
$pages = max(1, (int) ceil($totalDayTrips / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$rows = qall(
    "SELECT s.*, r.origin, r.destination, r.mode,
            COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS service_code,
            COALESCE(b.name, tn.name, pl.airline) AS carrier_name,
            COALESCE(b.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin,
            COALESCE(s.bus_id, s.train_id, s.plane_id) AS vehicle_id,
            (SELECT COUNT(*) FROM bookings bk WHERE bk.schedule_id = s.id AND bk.booking_status <> 'cancelled') taken
     {$listFrom}
     ORDER BY s.depart_time, r.origin, r.destination
     LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
    $scheduleTypes,
    $scheduleParams
);
$pageQuery = 'mode=' . urlencode($mode) . $filterQuery;

$routeFareMap = [];
foreach ($routes as $route) {
    $routeFareMap[strtolower($route['origin']) . '|' . strtolower($route['destination'])] = (string) $route['base_fare'];
}

$pageTitle = 'Schedules';
$active = 'schedules';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<p class="filters">
  <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
    <a class="<?= $mode === $key ? 'on' : '' ?>" href="schedules.php?mode=<?= e($key) ?><?= e($filterQuery) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>
<form class="card form-grid" method="post" id="schedule-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <h2><?= $edit ? ($editHasBookings ? 'Edit booked trip' : 'Edit schedule pattern') : 'Add ' . e(strtolower(mode_label($mode))) . ' schedule' ?></h2>
  <?php if ($edit && $editHasBookings): ?>
    <label>From
      <input value="<?= e($editOrigin) ?>" readonly>
      <input type="hidden" name="origin" value="<?= e($editOrigin) ?>">
    </label>
    <label>To
      <input value="<?= e($editDestination) ?>" readonly>
      <input type="hidden" name="destination" value="<?= e($editDestination) ?>">
    </label>
  <?php else: ?>
    <?php city_filter_field('origin', 'From', $editOrigin, 'schedule-from', true); ?>
    <?php city_filter_field('destination', 'To', $editDestination, 'schedule-to', true); ?>
    <script type="application/json" class="city-source"><?= json_encode(cities($mode), JSON_UNESCAPED_UNICODE) ?></script>
  <?php endif; ?>
  <?php if ($edit): ?>
    <label><?= e(mode_label($mode)) ?> code or name
      <input name="vehicle_code" value="<?= e($vehicleCode) ?>" readonly>
    </label>
  <?php else: ?>
    <?php vehicle_suggest_field('vehicle_code', mode_label($mode) . ' code or name', $vehicleCode, true); ?>
    <script type="application/json" class="vehicle-source"><?= json_encode($vehicleSuggest, JSON_UNESCAPED_UNICODE) ?></script>
  <?php endif; ?>
  <?php if ($edit): ?>
    <label>Weekday
      <input value="<?= e(weekday_name($editWeekday)) ?>" readonly>
      <input type="hidden" name="weekday" id="schedule-weekday" value="<?= (int) $editWeekday ?>">
    </label>
  <?php else: ?>
    <label>Weekday
      <select name="weekday" id="schedule-weekday" required>
        <?php foreach (weekday_options(false) as $dayNum => $dayLabel): ?>
          <option value="<?= (int) $dayNum ?>" <?= $editWeekday === (int) $dayNum ? 'selected' : '' ?>><?= e($dayLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <?php if ($edit && $editHasBookings): ?>
    <label>Travel date
      <input type="date" name="travel_date" id="schedule-date" required value="<?= e((string) $edit['travel_date']) ?>" readonly>
    </label>
  <?php else: ?>
    <label><?= $edit ? 'Effective from' : 'Date' ?>
      <input type="date" name="travel_date" id="schedule-date" required min="<?= e($appToday) ?>" value="<?= e($edit['travel_date'] ?? $appToday) ?>">
    </label>
  <?php endif; ?>
  <label>Departs<input type="time" name="depart_time" required value="<?= e(isset($edit['depart_time']) ? substr($edit['depart_time'], 0, 5) : '08:30') ?>"<?= $edit && $editHasBookings && (string) $edit['travel_date'] < $appToday ? ' readonly' : '' ?>></label>
  <label>Fare<input type="number" min="1" step="0.01" name="fare" id="fare-input" required value="<?= e((string) ($edit['fare'] ?? '')) ?>"<?= $edit && $editHasBookings ? ' readonly' : '' ?>></label>
  <button class="btn" type="submit"<?= $edit && $editHasBookings && (string) $edit['travel_date'] < $appToday ? ' disabled' : '' ?>><?= $edit ? ($editHasBookings ? 'Save time' : 'Save pattern') : 'Add schedule' ?></button>
</form>
<script>
(function () {
  var dateInput = document.getElementById('schedule-date');
  var weekday = document.getElementById('schedule-weekday');
  if (!dateInput || !weekday || weekday.tagName !== 'SELECT') return;
  dateInput.addEventListener('change', function () {
    if (!dateInput.value) return;
    var parts = dateInput.value.split('-');
    if (parts.length !== 3) return;
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (isNaN(d.getTime())) return;
    var next = String(d.getDay() + 1);
    var option = weekday.querySelector('option[value="' + next + '"]');
    if (option && option.disabled) return;
    weekday.value = next;
  });
})();
</script>
<script type="application/json" id="schedule-route-fares"><?= json_encode($routeFareMap, JSON_UNESCAPED_UNICODE) ?></script>
<form class="filters route-find" method="get">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <?php city_filter_field('from', 'From', $fromFind); ?>
  <span class="filter-arrow" aria-hidden="true">&rarr;</span>
  <?php city_filter_field('to', 'To', $toFind); ?>
  <label>Date
    <input type="date" name="date" required min="<?= e($appToday) ?>" value="<?= e($dateFind) ?>">
  </label>
  <?php vehicle_suggest_field('vehicle', 'Vehicle', $vehicleFind, false); ?>
  <script type="application/json" class="vehicle-source"><?= json_encode($filterSuggest, JSON_UNESCAPED_UNICODE) ?></script>
  <button class="btn" type="submit">Search</button>
  <script type="application/json" class="city-source"><?= json_encode(cities($mode), JSON_UNESCAPED_UNICODE) ?></script>
</form>
<div class="table-wrap card" id="trips">
  <h2><?= e(date_label($dateFind)) ?></h2>
  <table>
    <thead><tr><th>Date</th><th>Time</th><th>Route</th><th>Vehicle</th><th>Fare</th><th>Seats taken</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $hasVehicle = (int) ($row['vehicle_id'] ?? 0) > 0 && trim((string) ($row['service_code'] ?? '')) !== ''; ?>
        <tr>
          <td><?= e(date_label($row['travel_date'])) ?></td>
          <td><?= e(clock_label($row['depart_time'])) ?></td>
          <td><?= e($row['origin']) ?> &rarr; <?= e($row['destination']) ?></td>
          <td>
            <?php if ($hasVehicle): ?>
              <?= e($row['carrier_name']) ?> (<?= e($row['service_code']) ?>) - <?= e($row['cabin']) ?>
            <?php else: ?>
              <span class="muted">Not assigned</span>
            <?php endif; ?>
          </td>
          <td><?= e(money($row['fare'])) ?></td>
          <td><?= (int) $row['taken'] ?></td>
          <td><?= badge($row['status']) ?></td>
          <td class="actions">
            <?php if ($row['status'] === 'scheduled'): ?>
              <a href="schedules.php?id=<?= (int) $row['id'] ?>&<?= e($pageQuery) ?>&page=<?= (int) $page ?>#schedule-form">Edit</a>
              <form method="post" data-confirm="Cancel this schedule?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <button type="submit">Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8">No trips with a vehicle on this day.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($pages > 1): ?>
  <p class="filters pager">
    <?php if ($page > 1): ?>
      <a href="schedules.php?<?= e($pageQuery) ?>&page=<?= $page - 1 ?>">Previous</a>
    <?php endif; ?>
    <span class="muted">Page <?= (int) $page ?> of <?= number_format($pages) ?></span>
    <?php if ($page < $pages): ?>
      <a href="schedules.php?<?= e($pageQuery) ?>&page=<?= $page + 1 ?>">Next</a>
    <?php endif; ?>
  </p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
