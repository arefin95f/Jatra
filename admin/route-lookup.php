<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$mode = $_GET['mode'] ?? '';
if (!in_array($mode, ['', 'bus', 'train', 'plane'], true)) {
    $mode = '';
}
$fromFind = trim($_GET['from'] ?? '');
$toFind = trim($_GET['to'] ?? '');
$vehicleFind = trim($_GET['vehicle'] ?? '');
$dateFind = trim($_GET['date'] ?? $appToday);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFind)) {
    $dateFind = $appToday;
}
$searched = $fromFind !== '' || $toFind !== '' || $vehicleFind !== '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$rows = [];
$totalRows = 0;
$pages = 1;

if ($searched) {
    $where = "FROM schedules s
              JOIN routes r ON r.id = s.route_id
              LEFT JOIN buses b ON b.id = s.bus_id
              LEFT JOIN trains tn ON tn.id = s.train_id
              LEFT JOIN planes pl ON pl.id = s.plane_id
              WHERE s.travel_date = ?";
    $types = 's';
    $params = [$dateFind];
    if ($mode !== '') {
        $where .= ' AND r.mode = ?';
        $types .= 's';
        $params[] = $mode;
    }
    if ($fromFind !== '') {
        $where .= ' AND r.origin LIKE ?';
        $types .= 's';
        $params[] = '%' . $fromFind . '%';
    }
    if ($toFind !== '') {
        $where .= ' AND r.destination LIKE ?';
        $types .= 's';
        $params[] = '%' . $toFind . '%';
    }
    if ($vehicleFind !== '') {
        $where .= " AND (
            b.name LIKE ? OR b.bus_number LIKE ?
            OR tn.name LIKE ? OR tn.train_number LIKE ?
            OR pl.airline LIKE ? OR pl.flight_number LIKE ?
            OR s.cabin_name LIKE ?
        )";
        $like = '%' . $vehicleFind . '%';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $totalRows = (int) qone('SELECT COUNT(*) c ' . $where, $types, $params)['c'];
    $pages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;
    $rows = qall(
        "SELECT s.id, s.train_id, s.travel_date, s.depart_time, s.status, s.fare,
                r.origin, r.destination, r.mode,
                COALESCE(b.name, tn.name, pl.airline) AS carrier_name,
                COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS service_code,
                COALESCE(b.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin,
                COALESCE(b.total_seats, tn.total_seats, pl.total_seats) AS total_seats,
                (SELECT COUNT(*) FROM bookings bk WHERE bk.schedule_id = s.id AND bk.booking_status <> 'cancelled') AS taken
         $where
         ORDER BY s.depart_time, r.origin, r.destination, service_code
         LIMIT $perPage OFFSET $offset",
        $types,
        $params
    );
}

$query = http_build_query([
    'mode' => $mode,
    'from' => $fromFind,
    'to' => $toFind,
    'vehicle' => $vehicleFind,
    'date' => $dateFind,
]);

$pageTitle = 'Departures';
$active = 'lookup';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<p class="filters">
  <?php foreach (['' => 'All', 'bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
    <?php
    $tab = http_build_query([
        'mode' => $key,
        'from' => $fromFind,
        'to' => $toFind,
        'vehicle' => $vehicleFind,
        'date' => $dateFind,
    ]);
    ?>
    <a class="<?= $mode === $key ? 'on' : '' ?>" href="route-lookup.php?<?= e($tab) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>
<form class="filters route-find" method="get">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <?php city_filter_field('from', 'From', $fromFind); ?>
  <span class="filter-arrow" aria-hidden="true">→</span>
  <?php city_filter_field('to', 'To', $toFind); ?>
  <label>Date
    <input type="date" name="date" required value="<?= e($dateFind) ?>">
  </label>
  <label>Transport
    <input type="search" name="vehicle" value="<?= e($vehicleFind) ?>" placeholder="Name or code" maxlength="80">
  </label>
  <button class="btn" type="submit">Search</button>
  <?php if ($searched): ?><span class="muted"><?= number_format($totalRows) ?> trips</span><?php endif; ?>
  <script type="application/json" class="city-source"><?= json_encode(cities(), JSON_UNESCAPED_UNICODE) ?></script>
</form>
<div class="table-wrap card" id="trips">
  <h2><?= e(date_label($dateFind)) ?></h2>
  <table>
    <thead>
      <tr>
        <th>Route</th>
        <th>Transport</th>
        <th>Name</th>
        <th>Date</th>
        <th>Time</th>
        <th>Class</th>
        <th>Seats left</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$searched): ?>
        <tr><td colspan="8">Enter a city or a transport name, then search.</td></tr>
      <?php elseif (!$rows): ?>
        <tr><td colspan="8">No trips match this route.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <?php
        $carrier = trim((string) ($row['carrier_name'] ?? ''));
        $code = trim((string) ($row['service_code'] ?? ''));
        $capacity = bookable_seats($row);
        $left = max(0, $capacity - (int) $row['taken']);
        ?>
        <tr>
          <td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td>
          <td><?= mode_badge($row['mode']) ?></td>
          <td><?= $carrier !== '' ? e($carrier) : '<span class="muted">Not assigned</span>' ?><?php if ($code !== ''): ?><br><span class="muted"><?= e($code) ?></span><?php endif; ?></td>
          <td><?= e(date_label($row['travel_date'])) ?></td>
          <td><?= e(clock_label($row['depart_time'])) ?></td>
          <td><?= e((string) $row['cabin']) ?></td>
          <td class="seats-left">
            <?php if ($capacity < 1): ?>
              <span class="muted">—</span>
            <?php elseif ($left < 1): ?>
              <strong class="full">Full</strong>
            <?php else: ?>
              <strong class="<?= $left < 6 ? 'low' : '' ?>"><?= $left ?></strong>
              <span class="muted">of <?= $capacity ?></span>
            <?php endif; ?>
          </td>
          <td><?= badge($row['status']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($searched && $pages > 1): ?>
  <p class="filters pager">
    <?php if ($page > 1): ?><a href="route-lookup.php?<?= e($query) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= number_format($pages) ?></span>
    <?php if ($page < $pages): ?><a href="route-lookup.php?<?= e($query) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
  </p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
