<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/fleet_replace.php';
require_admin();
ensure_replacement_tables();

$admin = current_user();
$scheduleId = (int) ($_GET['id'] ?? $_POST['schedule_id'] ?? 0);
$modeFilter = clean_mode($_POST['mode'] ?? $_GET['mode'] ?? 'bus');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
    $spareTyped = trim((string) ($_POST['spare_code'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $dayBack = trim((string) ($_POST['day'] ?? $appToday));
    $openTrip = load_schedule_for_replace($scheduleId);
    $newVehicleId = $openTrip
        ? find_spare_vehicle_id(clean_mode((string) $openTrip['mode']), $spareTyped, (int) ($openTrip['vehicle_id'] ?? 0))
        : 0;
    if ($spareTyped !== '' && $newVehicleId <= 0) {
        flash('err', 'Only a free spare can be used. Active duty and maintenance vehicles cannot replace.');
        redirect('replace-vehicle.php?mode=' . urlencode($modeFilter) . '&id=' . $scheduleId . '&day=' . urlencode($dayBack));
    }
    $result = replace_schedule_vehicle($scheduleId, $newVehicleId, $reason, $note, (int) $admin['id']);
    flash($result['ok'] ? 'ok' : 'err', $result['message']);
    if ($result['ok']) {
        redirect('replace-vehicle.php?mode=' . urlencode($modeFilter) . '&day=' . urlencode($dayBack));
    }
    redirect('replace-vehicle.php?mode=' . urlencode($modeFilter) . '&id=' . $scheduleId . '&day=' . urlencode($dayBack));
}

$trip = $scheduleId > 0 ? load_schedule_for_replace($scheduleId) : null;
$candidates = $trip ? spare_candidates_for_schedule($trip) : [];
$spareSuggest = $trip ? spare_suggest_list($trip) : [];
$history = qall(
    "SELECT vr.*, r.origin, r.destination, s.travel_date, s.depart_time,
            COALESCE(ob.bus_number, ot.train_number, op.flight_number) AS old_code,
            COALESCE(ob.name, ot.name, op.airline) AS old_name,
            COALESCE(ob.status, ot.status, op.status) AS old_status,
            COALESCE(nb.bus_number, nt.train_number, np.flight_number) AS new_code,
            COALESCE(nb.name, nt.name, np.airline) AS new_name
     FROM vehicle_replacements vr
     JOIN schedules s ON s.id = vr.schedule_id
     JOIN routes r ON r.id = s.route_id
     LEFT JOIN buses ob ON vr.mode = 'bus' AND ob.id = vr.old_vehicle_id
     LEFT JOIN trains ot ON vr.mode = 'train' AND ot.id = vr.old_vehicle_id
     LEFT JOIN planes op ON vr.mode = 'plane' AND op.id = vr.old_vehicle_id
     LEFT JOIN buses nb ON vr.mode = 'bus' AND nb.id = vr.new_vehicle_id
     LEFT JOIN trains nt ON vr.mode = 'train' AND nt.id = vr.new_vehicle_id
     LEFT JOIN planes np ON vr.mode = 'plane' AND np.id = vr.new_vehicle_id
     ORDER BY vr.id DESC
     LIMIT 25"
);

$fromFind = trim($_GET['from'] ?? '');
$toFind = trim($_GET['to'] ?? '');
$vehicleFind = trim($_GET['vehicle'] ?? '');

$dayOptions = [];
for ($offset = 0; $offset < 7; $offset++) {
    $dayDate = date('Y-m-d', strtotime($appToday . ' +' . $offset . ' day'));
    $dayOptions[$dayDate] = weekday_name((int) date('w', strtotime($dayDate)) + 1);
}
$selectedDay = trim($_GET['day'] ?? $appToday);
if ($trip) {
    $modeFilter = clean_mode((string) $trip['mode']);
    $tripDay = (string) $trip['travel_date'];
    if (isset($dayOptions[$tripDay])) {
        $selectedDay = $tripDay;
    }
}
if (!isset($dayOptions[$selectedDay]) || $selectedDay < $appToday) {
    $selectedDay = $appToday;
}
$dayHorizon = date('Y-m-d', strtotime($appToday . ' +6 day'));
$nowStamp = app_now()->format('Y-m-d H:i:s');
$openDaySql = "SELECT MIN(s.travel_date) AS d
     FROM schedules s
     JOIN routes r ON r.id = s.route_id
     LEFT JOIN buses b ON b.id = s.bus_id
     LEFT JOIN trains tn ON tn.id = s.train_id
     LEFT JOIN planes pl ON pl.id = s.plane_id
     WHERE s.status = 'scheduled'
       AND r.mode = ?
       AND s.travel_date >= ?
       AND s.travel_date <= ?
       AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL
       AND TIMESTAMP(s.travel_date, s.depart_time) > ?";
$openTypes = 'ssss';
$openParams = [$modeFilter, $selectedDay, $dayHorizon, $nowStamp];
if ($fromFind !== '') {
    $openDaySql .= ' AND r.origin LIKE ?';
    $openTypes .= 's';
    $openParams[] = '%' . $fromFind . '%';
}
if ($toFind !== '') {
    $openDaySql .= ' AND r.destination LIKE ?';
    $openTypes .= 's';
    $openParams[] = '%' . $toFind . '%';
}
if ($vehicleFind !== '') {
    $openDaySql .= " AND (COALESCE(b.bus_number, tn.train_number, pl.flight_number) LIKE ?
        OR COALESCE(b.name, tn.name, pl.airline) LIKE ?)";
    $openLike = '%' . $vehicleFind . '%';
    $openTypes .= 'ss';
    array_push($openParams, $openLike, $openLike);
}
$openDay = qone($openDaySql, $openTypes, $openParams);
$keptToday = isset($_GET['day']) && $_GET['day'] === $appToday;
if (!$keptToday && !empty($openDay['d']) && isset($dayOptions[(string) $openDay['d']])) {
    $selectedDay = (string) $openDay['d'];
}

$filterQuery = ($fromFind !== '' ? '&from=' . urlencode($fromFind) : '')
    . ($toFind !== '' ? '&to=' . urlencode($toFind) : '')
    . ($vehicleFind !== '' ? '&vehicle=' . urlencode($vehicleFind) : '');

$filtered = $fromFind !== '' || $toFind !== '' || $vehicleFind !== '';
$filterSuggest = fleet_vehicle_filter_suggest($modeFilter);
$perPage = 15;
$listFrom = "FROM schedules s
            JOIN routes r ON r.id = s.route_id
            LEFT JOIN buses b ON b.id = s.bus_id
            LEFT JOIN trains tn ON tn.id = s.train_id
            LEFT JOIN planes pl ON pl.id = s.plane_id
            WHERE s.status = 'scheduled'
              AND r.mode = ?
              AND s.travel_date = ?
              AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL
              AND TIMESTAMP(s.travel_date, s.depart_time) > ?";
$listTypes = 'sss';
$listParams = [$modeFilter, $selectedDay, $nowStamp];
if ($fromFind !== '') {
    $listFrom .= ' AND r.origin LIKE ?';
    $listTypes .= 's';
    $listParams[] = '%' . $fromFind . '%';
}
if ($toFind !== '') {
    $listFrom .= ' AND r.destination LIKE ?';
    $listTypes .= 's';
    $listParams[] = '%' . $toFind . '%';
}
if ($vehicleFind !== '') {
    $listFrom .= " AND (COALESCE(b.bus_number, tn.train_number, pl.flight_number) LIKE ?
        OR COALESCE(b.name, tn.name, pl.airline) LIKE ?)";
    $like = '%' . $vehicleFind . '%';
    $listTypes .= 'ss';
    array_push($listParams, $like, $like);
}
$totalDayTrips = (int) qone('SELECT COUNT(*) c ' . $listFrom, $listTypes, $listParams)['c'];
$pages = max(1, (int) ceil($totalDayTrips / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$dayTrips = qall(
    "SELECT s.id, s.travel_date, s.depart_time, s.status, r.origin, r.destination, r.mode,
            COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS service_code,
            COALESCE(b.name, tn.name, pl.airline) AS carrier_name,
            COALESCE(b.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin,
            (SELECT COUNT(*) FROM bookings bk
              WHERE bk.schedule_id = s.id AND bk.booking_status IN ('confirmed','pending')) AS passengers
     {$listFrom}
     ORDER BY s.depart_time, r.origin, r.destination
     LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
    $listTypes,
    $listParams
);
$pageQuery = 'mode=' . urlencode($modeFilter) . '&day=' . urlencode($selectedDay) . $filterQuery;
$selectedWeekday = $dayOptions[$selectedDay];

$pageTitle = 'Replace vehicle';
$active = 'replace';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<p class="filters">
  <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
    <a class="<?= $modeFilter === $key ? 'on' : '' ?>" href="replace-vehicle.php?mode=<?= e($key) ?>&day=<?= e($selectedDay) ?><?= e($filterQuery) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>
<p class="filters">
  <?php foreach ($dayOptions as $dayDate => $weekday): ?>
    <a class="<?= $selectedDay === $dayDate ? 'on' : '' ?>" href="replace-vehicle.php?mode=<?= e($modeFilter) ?>&day=<?= e($dayDate) ?><?= e($filterQuery) ?>"><?= e($weekday) ?></a>
  <?php endforeach; ?>
</p>

<?php if ($trip): ?>
  <form class="card form-grid" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="schedule_id" value="<?= (int) $trip['id'] ?>">
    <input type="hidden" name="mode" value="<?= e($modeFilter) ?>">
    <input type="hidden" name="day" value="<?= e((string) $trip['travel_date']) ?>">
    <h2>Replace vehicle</h2>
    <p class="replace-trip-summary">
      <strong><?= e($trip['origin']) ?> &rarr; <?= e($trip['destination']) ?></strong>
      &middot; <strong><?= e(date_label($trip['travel_date'])) ?></strong>
      &middot; <strong><?= e(clock_label($trip['depart_time'])) ?></strong>
      &middot; <strong><?= e($trip['carrier_name']) ?> (<?= e($trip['service_code']) ?>)</strong>
      - <strong><?= e($trip['cabin']) ?></strong>
    </p>
    <label>Reason
      <select name="reason" required>
        <option value="">Select reason</option>
        <?php foreach (replacement_reasons() as $key => $label): ?>
          <option value="<?= e($key) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php vehicle_suggest_field('spare_code', 'Spare vehicle', '', true, true); ?>
    <script type="application/json" class="vehicle-source"><?= json_encode($spareSuggest, JSON_UNESCAPED_UNICODE) ?></script>
    <?php if (!$spareSuggest): ?>
      <p class="alert err">No free spare is available right now.</p>
    <?php endif; ?>
    <label>Note to passengers (optional)
      <input name="note" maxlength="255" placeholder="Short message shown in email and app">
    </label>
    <button class="btn" type="submit" <?= $spareSuggest ? '' : 'disabled' ?>>Replace and notify</button>
    <p><a href="replace-vehicle.php?mode=<?= e(clean_mode((string) $trip['mode'])) ?>&day=<?= e((string) $trip['travel_date']) ?>">← Back to trip list</a></p>
  </form>
<?php else: ?>
  <form class="filters route-find" method="get">
    <input type="hidden" name="mode" value="<?= e($modeFilter) ?>">
    <?php city_filter_field('from', 'From', $fromFind); ?>
    <span class="filter-arrow" aria-hidden="true">&rarr;</span>
    <?php city_filter_field('to', 'To', $toFind); ?>
    <label>Date
      <input type="date" name="day" required min="<?= e($appToday) ?>" max="<?= e($dayHorizon) ?>" value="<?= e($selectedDay) ?>">
    </label>
    <?php vehicle_suggest_field('vehicle', 'Vehicle', $vehicleFind, false); ?>
    <script type="application/json" class="vehicle-source"><?= json_encode($filterSuggest, JSON_UNESCAPED_UNICODE) ?></script>
    <button class="btn" type="submit">Search</button>
    <script type="application/json" class="city-source"><?= json_encode(cities($modeFilter), JSON_UNESCAPED_UNICODE) ?></script>
  </form>
  <div class="table-wrap card">
    <h2><?= e($selectedWeekday) ?> &middot; <?= e(date_label($selectedDay)) ?></h2>
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Route</th>
          <th>Vehicle</th>
          <th>Passengers</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dayTrips as $row): ?>
          <tr>
            <td><?= e(clock_label($row['depart_time'])) ?></td>
            <td><?= e($row['origin']) ?> &rarr; <?= e($row['destination']) ?></td>
            <td><?= e($row['carrier_name']) ?> (<?= e($row['service_code']) ?>) - <?= e($row['cabin']) ?></td>
            <td><?= (int) $row['passengers'] ?></td>
            <td class="actions">
              <a href="replace-vehicle.php?mode=<?= e($modeFilter) ?>&id=<?= (int) $row['id'] ?>&day=<?= e($selectedDay) ?>&page=<?= (int) $page ?><?= e($filterQuery) ?>">Replace</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$dayTrips): ?>
          <tr><td colspan="5"><?php if ($selectedDay === $appToday && !$filtered): ?>The last <?= e(strtolower(mode_label($modeFilter))) ?> for today has already left.<?php else: ?>No remaining trips<?= $filtered ? ' matching this search' : '' ?>.<?php endif; ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <p class="filters pager">
      <?php if ($page > 1): ?>
        <a href="replace-vehicle.php?<?= e($pageQuery) ?>&page=<?= $page - 1 ?>">Previous</a>
      <?php endif; ?>
      <span class="muted">Page <?= (int) $page ?> of <?= number_format($pages) ?></span>
      <?php if ($page < $pages): ?>
        <a href="replace-vehicle.php?<?= e($pageQuery) ?>&page=<?= $page + 1 ?>">Next</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
<?php endif; ?>

<div class="table-wrap card">
  <h2>Recent replacements</h2>
  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>Trip</th>
        <th>From</th>
        <th>To</th>
        <th>Reason</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($history as $row): ?>
        <?php
          $fleetPage = match ((string) $row['mode']) {
              'train' => 'trains.php',
              'plane' => 'planes.php',
              default => 'buses.php',
          };
          $grounded = replacement_grounds_vehicle((string) $row['reason']);
          $reversed = !empty($row['reversed_at']);
          $needsActive = $grounded && !$reversed && ($row['old_status'] ?? '') === 'maintenance';
        ?>
        <tr>
          <td><?= e(date_label(substr($row['created_at'], 0, 10))) ?> <?= e(clock_label(substr($row['created_at'], 11, 8))) ?></td>
          <td>
            <?= e($row['origin']) ?> &rarr; <?= e($row['destination']) ?><br>
            <span class="muted"><?= e(date_label($row['travel_date'])) ?> &middot; <?= e(clock_label($row['depart_time'])) ?></span>
          </td>
          <td>
            <?= e($row['old_name']) ?> (<?= e($row['old_code']) ?>)
            <?php if ($needsActive): ?>
              <br><span class="muted">Maintenance</span>
            <?php elseif ($grounded && $reversed): ?>
              <br><span class="muted">Active · duties returned</span>
            <?php endif; ?>
          </td>
          <td>
            <?= e($row['new_name']) ?> (<?= e($row['new_code']) ?>)
            <?php if ($grounded && $reversed): ?>
              <br><span class="muted">Spare again</span>
            <?php elseif ($grounded && !$reversed): ?>
              <br><span class="muted">Covering duties</span>
            <?php elseif ((string) $row['reason'] === 'traffic_delay'): ?>
              <br><span class="muted">One trip only</span>
            <?php endif; ?>
          </td>
          <td>
            <?= e(replacement_reason_label((string) $row['reason'])) ?>
            <?php if ($row['note']): ?><br><span class="muted"><?= e($row['note']) ?></span><?php endif; ?>
          </td>
          <td class="actions">
            <a href="<?= e($fleetPage) ?>?id=<?= (int) $row['old_vehicle_id'] ?>">
              <?= $needsActive ? 'Set active' : 'Open fleet' ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$history): ?>
        <tr><td colspan="6">No replacements yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
