<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $used = (int) qone('SELECT COUNT(*) c FROM schedules WHERE train_id = ?', 'i', [$id])['c'];
        if ($used) {
            flash('err', 'This train has schedules. Mark it under maintenance instead.');
        } else {
            qexec('DELETE FROM trains WHERE id = ?', 'i', [$id]);
            flash('ok', 'Train deleted.');
        }
        redirect('trains.php');
    }
    require_once dirname(__DIR__) . '/includes/timetable.php';
    require_once dirname(__DIR__) . '/includes/fleet_replace.php';
    $number = strtoupper(trim($_POST['train_number'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $class = $_POST['seat_class'] ?? 'Snigdha';
    $seats = (int) ($_POST['total_seats'] ?? 0);
    $dayOff = parse_day_off($_POST['day_off'] ?? next_staggered_day_off('trains'));
    $statusIn = $_POST['status'] ?? 'active';
    if ($statusIn === 'maintenance') {
        $status = 'maintenance';
    } elseif ($statusIn === 'spare' || $dayOff === 0) {
        $status = 'active';
        $dayOff = 0;
    } else {
        $status = 'active';
        if ($dayOff === 0) {
            $dayOff = next_staggered_day_off('trains');
        }
    }
    if (!in_array($class, ['Shovan', 'Snigdha', 'AC'], true)) {
        $class = 'Snigdha';
    }
    if ($number === '' || $name === '' || $seats < 8 || $seats > 600) {
        flash('err', 'Enter a train number, name, and between 8 and 600 seats.');
        redirect('trains.php' . ($id ? '?id=' . $id : ''));
    }
    try {
        if ($id) {
            $was = qone('SELECT status FROM trains WHERE id = ?', 'i', [$id]);
            qexec('UPDATE trains SET train_number=?, name=?, seat_class=?, total_seats=?, day_off=?, status=? WHERE id=?', 'sssissi', [$number, $name, $class, $seats, $dayOff, $status, $id]);
            if ($statusIn === 'spare' || $dayOff === 0) {
                release_vehicle_to_spare('train', $id);
            } elseif ($status === 'active' && $dayOff !== 0 && ($was['status'] ?? '') === 'maintenance') {
                reactivate_fleet_vehicle('train', $id);
            }
            flash('ok', 'Train updated.');
        } else {
            $newId = qexec('INSERT INTO trains (train_number, name, seat_class, total_seats, day_off, status) VALUES (?, ?, ?, ?, ?, ?)', 'sssiss', [$number, $name, $class, $seats, $dayOff, $status]);
            if ($statusIn === 'spare' || $dayOff === 0) {
                release_vehicle_to_spare('train', $newId);
            }
            flash('ok', 'Train added.');
        }
    } catch (mysqli_sql_exception $ex) {
        flash('err', (int) $ex->getCode() === 1062 ? 'That train number already exists.' : 'Could not save the train.');
    }
    redirect('trains.php');
}

require_once dirname(__DIR__) . '/includes/timetable.php';
$edit = isset($_GET['id']) ? qone('SELECT * FROM trains WHERE id = ?', 'i', [(int) $_GET['id']]) : null;
$find = trim($_GET['q'] ?? '');
$fleetView = trim($_GET['fleet'] ?? 'active');
if (!in_array($fleetView, ['active', 'spare', 'maintenance', 'all'], true)) {
    $fleetView = 'active';
}
$trainWhere = [];
$trainTypes = '';
$trainParams = [];
if ($fleetView === 'active') {
    $trainWhere[] = "status = 'active' AND day_off BETWEEN 1 AND 7";
} elseif ($fleetView === 'spare') {
    $trainWhere[] = "status = 'active' AND day_off = 0";
} elseif ($fleetView === 'maintenance') {
    $trainWhere[] = "status = 'maintenance'";
}
if ($find !== '') {
    $trainWhere[] = '(train_number LIKE ? OR name LIKE ? OR seat_class LIKE ?)';
    $like = '%' . $find . '%';
    $trainTypes .= 'sss';
    $trainParams = [$like, $like, $like];
}
$whereSql = $trainWhere ? ('WHERE ' . implode(' AND ', $trainWhere)) : '';
$rows = qall('SELECT * FROM trains ' . $whereSql . ' ORDER BY train_number', $trainTypes, $trainParams);
$countActive = (int) qone("SELECT COUNT(*) c FROM trains WHERE status = 'active' AND day_off BETWEEN 1 AND 7")['c'];
$countSpare = (int) qone("SELECT COUNT(*) c FROM trains WHERE status = 'active' AND day_off = 0")['c'];
$countMaint = (int) qone("SELECT COUNT(*) c FROM trains WHERE status = 'maintenance'")['c'];
$editStatus = $edit ? fleet_list_status($edit) : 'active';
$pageTitle = 'Trains';
$active = 'trains';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="card form-grid" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= $edit ? 'Edit train' : 'Add train' ?></h2>
  <label>Number<input name="train_number" required maxlength="20" value="<?= e($edit['train_number'] ?? '') ?>"></label>
  <label>Name<input name="name" required maxlength="80" value="<?= e($edit['name'] ?? '') ?>"></label>
  <label>Class
    <select name="seat_class">
      <?php foreach (['Shovan', 'Snigdha', 'AC'] as $class): ?>
        <option <?= ($edit['seat_class'] ?? '') === $class ? 'selected' : '' ?>><?= e($class) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Seats<input type="number" min="8" max="600" name="total_seats" required value="<?= e((string) ($edit['total_seats'] ?? (string) train_capacity('Shovan'))) ?>"></label>
  <label>Day off
    <select name="day_off">
      <?php
        $selectedDay = (int) ($edit['day_off'] ?? ($editStatus === 'spare' ? 0 : next_staggered_day_off('trains')));
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
  <button class="btn" type="submit"><?= $edit ? 'Save train' : 'Add train' ?></button>
</form>
<form class="filters" method="get">
  <input type="hidden" name="fleet" value="<?= e($fleetView) ?>">
  <input type="search" name="q" value="<?= e($find) ?>" placeholder="Number, name, or class">
  <button class="btn" type="submit">Search</button>
</form>
<p class="filters">
  <?php foreach (['active' => 'Active (' . number_format($countActive) . ')', 'spare' => 'Spare (' . number_format($countSpare) . ')', 'maintenance' => 'Maintenance (' . number_format($countMaint) . ')', 'all' => 'All'] as $key => $label): ?>
    <a class="<?= $fleetView === $key ? 'on' : '' ?>" href="trains.php?fleet=<?= e($key) ?><?= $find !== '' ? '&q=' . urlencode($find) : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</p>
<div class="table-wrap card">
  <table>
    <thead><tr><th>Number</th><th>Name</th><th>Class</th><th>Seats</th><th>Day off</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e($row['train_number']) ?></td>
          <td><?= e($row['name']) ?></td>
          <td><?= e($row['seat_class']) ?></td>
          <td><?= (int) $row['total_seats'] ?></td>
          <td><span class="day-off"><?= e(weekday_name((int) $row['day_off'])) ?></span></td>
          <td><?= badge(fleet_list_status($row)) ?></td>
          <td class="actions">
            <a href="trains.php?id=<?= (int) $row['id'] ?>&fleet=<?= e($fleetView) ?>">Edit</a>
            <form method="post" data-confirm="Delete this train?">
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
