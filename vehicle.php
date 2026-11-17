<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/timetable.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/buses.php');
}

$mode = $_GET['mode'] ?? 'bus';
$id = (int) ($_GET['id'] ?? 0);
if (!in_array($mode, ['bus', 'train', 'plane'], true) || $id <= 0) {
    flash('err', 'Vehicle not found.');
    redirect('rest-days.php');
}

$vehicle = null;
$column = '';
$classLabel = '';
$codeLabel = '';
$nameLabel = '';

if ($mode === 'bus') {
    $vehicle = qone(
        'SELECT id, bus_number AS code, name, coach_type AS class_name, total_seats, day_off, status
         FROM buses WHERE id = ? AND status = ?',
        'is',
        [$id, 'active']
    );
    $column = 'bus_id';
    $classLabel = 'Coach';
    $codeLabel = 'Number';
    $nameLabel = 'Name';
} elseif ($mode === 'train') {
    $vehicle = qone(
        'SELECT id, train_number AS code, name, seat_class AS class_name, total_seats, day_off, status
         FROM trains WHERE id = ? AND status = ?',
        'is',
        [$id, 'active']
    );
    $column = 'train_id';
    $classLabel = 'Class';
    $codeLabel = 'Number';
    $nameLabel = 'Name';
} else {
    $vehicle = qone(
        'SELECT id, flight_number AS code, airline AS name, cabin AS class_name, total_seats, day_off, status
         FROM planes WHERE id = ? AND status = ?',
        'is',
        [$id, 'active']
    );
    $column = 'plane_id';
    $classLabel = 'Cabin';
    $codeLabel = 'Flight';
    $nameLabel = 'Airline';
}

if (!$vehicle) {
    flash('err', 'Vehicle not found.');
    redirect('rest-days.php?mode=' . urlencode($mode));
}

$dayOff = (int) $vehicle['day_off'];

$trips = qall(
    "SELECT s.travel_date, s.depart_time, s.fare, s.cabin_name,
            r.origin, r.destination, r.duration_minutes
     FROM schedules s
     JOIN routes r ON r.id = s.route_id
     WHERE s.{$column} = ? AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
       AND DAYOFWEEK(s.travel_date) <> ?
     ORDER BY s.travel_date, s.depart_time
     LIMIT 60",
    'ii',
    [$id, $dayOff]
);

$tripsByDate = [];
$corridors = [];
foreach ($trips as $trip) {
    $tripsByDate[$trip['travel_date']][] = $trip;
    $key = $trip['origin'] . ' → ' . $trip['destination'];
    $corridors[$key] = ($corridors[$key] ?? 0) + 1;
}
arsort($corridors);
$corridors = array_slice($corridors, 0, 8, true);

$runDays = [];
for ($day = 1; $day <= 7; $day++) {
    if ($day !== $dayOff) {
        $runDays[] = weekday_name($day);
    }
}

$arrive_at = static function (string $date, string $depart, int $minutes): array {
    $start = strtotime($date . ' ' . $depart);
    if ($start === false) {
        return ['time' => clock_label($depart), 'date' => ''];
    }
    $end = $start + max(0, $minutes) * 60;
    $sameDay = date('Y-m-d', $start) === date('Y-m-d', $end);
    return [
        'time' => date('g:i A', $end),
        'date' => $sameDay ? '' : date('d M Y', $end),
    ];
};

$pageTitle = $vehicle['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <h1><?= e($vehicle['name']) ?></h1>
  <dl class="vehicle-facts">
    <div><dt><?= e($codeLabel) ?></dt><dd><?= e($vehicle['code']) ?></dd></div>
    <div><dt><?= e($nameLabel) ?></dt><dd><?= e($vehicle['name']) ?></dd></div>
    <div><dt><?= e($classLabel) ?></dt><dd><?= e($vehicle['class_name']) ?></dd></div>
    <div><dt>Seats</dt><dd><?= (int) $vehicle['total_seats'] ?></dd></div>
    <div><dt>Day off</dt><dd class="day-off"><?= e(weekday_name($dayOff)) ?></dd></div>
    <div><dt>Runs on</dt><dd><?= e(implode(', ', $runDays)) ?></dd></div>
    <div><dt>Status</dt><dd><?= badge($vehicle['status']) ?></dd></div>
  </dl>

  <?php if ($corridors): ?>
    <h2 style="margin-top:22px">Upcoming plan</h2>
    <ul class="vehicle-corridors">
      <?php foreach ($corridors as $label => $count): ?>
        <li><strong><?= e($label) ?></strong> <span class="muted">· <?= (int) $count ?> upcoming</span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2 style="margin-top:22px">Duty plan</h2>
  <?php if (!$tripsByDate): ?>
    <div class="empty">No upcoming trips assigned right now.</div>
  <?php else: ?>
    <?php foreach ($tripsByDate as $date => $dayTrips): ?>
      <?php $weekday = (int) date('w', strtotime($date)) + 1; ?>
      <h3 style="margin:18px 0 8px"><?= e(date_label($date)) ?> · <?= e(weekday_name($weekday)) ?></h3>
      <div class="table-wrap tickets-table">
        <table>
          <thead>
            <tr><th>Route</th><th>Depart</th><th>Arrive</th><th>Duration</th><th>Fare</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dayTrips as $trip): ?>
              <?php $arrive = $arrive_at($trip['travel_date'], $trip['depart_time'], (int) $trip['duration_minutes']); ?>
              <tr>
                <td><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></td>
                <td><?= e(clock_label($trip['depart_time'])) ?></td>
                <td>
                  <?= e($arrive['time']) ?>
                  <?php if ($arrive['date'] !== ''): ?><br><span class="muted"><?= e($arrive['date']) ?></span><?php endif; ?>
                </td>
                <td><?= e(duration_label((int) $trip['duration_minutes'])) ?></td>
                <td><?= e(money((float) $trip['fare'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top:18px">
    <a class="btn btn-ghost" href="rest-days.php?mode=<?= urlencode($mode) ?>">Back to Check Dayoff</a>
  </p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
