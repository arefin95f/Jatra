<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $used = (int) qone('SELECT COUNT(*) c FROM schedules WHERE plane_id = ?', 'i', [$id])['c'];
        if ($used) {
            flash('err', 'This flight has schedules. Mark it under maintenance instead.');
        } else {
            qexec('DELETE FROM planes WHERE id = ?', 'i', [$id]);
            flash('ok', 'Flight deleted.');
        }
        redirect('planes.php');
    }
    require_once dirname(__DIR__) . '/includes/timetable.php';
    require_once dirname(__DIR__) . '/includes/fleet_replace.php';
    $number = strtoupper(trim($_POST['flight_number'] ?? ''));
    $airline = trim($_POST['airline'] ?? '');
    $cabin = $_POST['cabin'] ?? 'Economy';
    $seats = (int) ($_POST['total_seats'] ?? 0);
    $dayOff = parse_day_off($_POST['day_off'] ?? next_staggered_day_off('planes'));
    $statusIn = $_POST['status'] ?? 'active';
    if ($statusIn === 'maintenance') {
        $status = 'maintenance';
    } elseif ($statusIn === 'spare' || $dayOff === 0) {
        $status = 'active';
        $dayOff = 0;
    } else {
        $status = 'active';
        if ($dayOff === 0) {
            $dayOff = next_staggered_day_off('planes');
        }
    }
    if (!in_array($cabin, ['Economy', 'Business'], true)) {
        $cabin = 'Economy';
    }
    if ($number === '' || $airline === '' || $seats < 8 || $seats > 200) {
        flash('err', 'Enter a flight number, airline, and between 8 and 200 seats.');
        redirect('planes.php' . ($id ? '?id=' . $id : ''));
    }
    try {
        if ($id) {
            $was = qone('SELECT status FROM planes WHERE id = ?', 'i', [$id]);
            qexec('UPDATE planes SET flight_number=?, airline=?, cabin=?, total_seats=?, day_off=?, status=? WHERE id=?', 'sssissi', [$number, $airline, $cabin, $seats, $dayOff, $status, $id]);
            if ($statusIn === 'spare' || $dayOff === 0) {
                release_vehicle_to_spare('plane', $id);
            } elseif ($status === 'active' && $dayOff !== 0 && ($was['status'] ?? '') === 'maintenance') {
                reactivate_fleet_vehicle('plane', $id);
            }
            flash('ok', 'Flight updated.');
        } else {
            $newId = qexec('INSERT INTO planes (flight_number, airline, cabin, total_seats, day_off, status) VALUES (?, ?, ?, ?, ?, ?)', 'sssiss', [$number, $airline, $cabin, $seats, $dayOff, $status]);
            if ($statusIn === 'spare' || $dayOff === 0) {
                release_vehicle_to_spare('plane', $newId);
            }
            flash('ok', 'Flight added.');
        }
    } catch (mysqli_sql_exception $ex) {
        flash('err', (int) $ex->getCode() === 1062 ? 'That flight number already exists.' : 'Could not save the flight.');
    }
    redirect('planes.php');
}

require_once dirname(__DIR__) . '/includes/timetable.php';
$edit = isset($_GET['id']) ? qone('SELECT * FROM planes WHERE id = ?', 'i', [(int) $_GET['id']]) : null;
$find = trim($_GET['q'] ?? '');
$fleetView = trim($_GET['fleet'] ?? 'active');
if (!in_array($fleetView, ['active', 'spare', 'maintenance', 'all'], true)) {
    $fleetView = 'active';
}
$planeWhere = [];
$planeTypes = '';
$planeParams = [];
if ($fleetView === 'active') {
    $planeWhere[] = "status = 'active' AND day_off BETWEEN 1 AND 7";
} elseif ($fleetView === 'spare') {
    $planeWhere[] = "status = 'active' AND day_off = 0";
} elseif ($fleetView === 'maintenance') {
    $planeWhere[] = "status = 'maintenance'";
}
if ($find !== '') {
    $planeWhere[] = '(flight_number LIKE ? OR airline LIKE ? OR cabin LIKE ?)';
    $like = '%' . $find . '%';
    $planeTypes .= 'sss';
    $planeParams = [$like, $like, $like];
}
$whereSql = $planeWhere ? ('WHERE ' . implode(' AND ', $planeWhere)) : '';
$rows = qall('SELECT * FROM planes ' . $whereSql . ' ORDER BY flight_number', $planeTypes, $planeParams);
$countActive = (int) qone("SELECT COUNT(*) c FROM planes WHERE status = 'active' AND day_off BETWEEN 1 AND 7")['c'];
$countSpare = (int) qone("SELECT COUNT(*) c FROM planes WHERE status = 'active' AND day_off = 0")['c'];
$countMaint = (int) qone("SELECT COUNT(*) c FROM planes WHERE status = 'maintenance'")['c'];
$editStatus = $edit ? fleet_list_status($edit) : 'active';
$pageTitle = 'Planes';
$active = 'planes';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="card form-grid" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= $edit ? 'Edit flight' : 'Add flight' ?></h2>
  <label>Flight<input name="flight_number" required maxlength="20" value="<?= e($edit['flight_number'] ?? '') ?>"></label>
  <label>Airline<input name="airline" required maxlength="80" value="<?= e($edit['airline'] ?? '') ?>"></label>
  <label>Cabin
    <select name="cabin">
      <?php foreach (['Economy', 'Business'] as $cabin): ?>
        <option <?= ($edit['cabin'] ?? '') === $cabin ? 'selected' : '' ?>><?= e($cabin) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Seats<input type="number" min="8" max="200" name="total_seats" required value="<?= e((string) ($edit['total_seats'] ?? '162')) ?>"></label>
  <label>Day off
    <select name="day_off">
      <?php
        $selectedDay = (int) ($edit['day_off'] ?? ($editStatus === 'spare' ? 0 : next_staggered_day_off('planes')));
        foreach (weekday_options(true) as $dayNum => $dayLabel):
      ?>
        <option value="<?= (int) $dayNum ?>" <?= $selectedDay === (int) $dayNum ? 'selected' : '' ?>><?= e($dayLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="active" <?= $editStatus === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="spare" <?= $editStatus === 'spare' ? 'selected' : '' ?>>Spare</option>
      <option value="maintenance" <?= $editStatus === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
    </select>
  </label>
  <button class="btn" type="submit"><?= $edit ? 'Save flight' : 'Add flight' ?></button>
</form>
<form class="filters" method="get">
  <input type="hidden" name="fleet" value="<?= e($fleetView) ?>">
  <input type="search" name="q" value="<?= e($find) ?>" placeholder="Flight, airline, or cabin">
  <button class="btn" type="submit">Search</button>
</form>
<p class="filters">
  <?php foreach (['active' => 'Active (' . number_format($countActive) . ')', 'spare' => 'Spare (' . number_format($countSpare) . ')', 'maintenance' => 'Maintenance (' . number_format($countMaint) . ')', 'all' => 'All'] as $key => $label): ?>
    <a class="<?= $fleetView === $key ? 'on' : '' ?>" href="planes.php?fleet=<?= e($key) ?><?= $find !== '' ? '&q=' . urlencode($find) : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>
<div class="table-wrap card">
  <table>
    <thead><tr><th>Flight</th><th>Airline</th><th>Cabin</th><th>Seats</th><th>Day off</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e($row['flight_number']) ?></td>
          <td><?= e($row['airline']) ?></td>
          <td><?= e($row['cabin']) ?></td>
          <td><?= (int) $row['total_seats'] ?></td>
          <td><span class="day-off"><?= e(weekday_name((int) $row['day_off'])) ?></span></td>
          <td><?= badge(fleet_list_status($row)) ?></td>
          <td class="actions">
            <a href="planes.php?id=<?= (int) $row['id'] ?>&fleet=<?= e($fleetView) ?>">Edit</a>
            <form method="post" data-confirm="Delete this flight?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
