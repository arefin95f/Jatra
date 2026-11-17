<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $used = (int) qone('SELECT COUNT(*) c FROM schedules WHERE route_id = ?', 'i', [$id])['c'];
        if ($used) {
            flash('err', 'This route has schedules, so it was not deleted.');
        } else {
            qexec('DELETE FROM routes WHERE id = ?', 'i', [$id]);
            flash('ok', 'Route deleted.');
        }
        redirect('routes.php?mode=' . urlencode(clean_mode($_POST['mode'] ?? 'bus')));
    }
    $routeMode = clean_mode($_POST['mode'] ?? 'bus');
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $distance = (int) ($_POST['distance_km'] ?? 0);
    $minutes = (int) ($_POST['duration_minutes'] ?? 0);
    $fare = (float) ($_POST['base_fare'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
    if ($origin === '' || $destination === '' || $origin === $destination || $distance < 1 || $minutes < 1 || $fare <= 0) {
        flash('err', 'Enter two different cities, distance, duration, and a fare above zero.');
        redirect('routes.php?mode=' . urlencode($routeMode) . ($id ? '&id=' . $id : ''));
    }
    $previousStatus = $id
        ? (string) (qone('SELECT status FROM routes WHERE id = ?', 'i', [$id])['status'] ?? '')
        : '';
    if ($id) {
        qexec('UPDATE routes SET origin=?, destination=?, mode=?, distance_km=?, duration_minutes=?, base_fare=?, status=? WHERE id=?', 'sssiidsi', [$origin, $destination, $routeMode, $distance, $minutes, $fare, $status, $id]);
    } else {
        $id = qexec("INSERT INTO routes (origin, destination, mode, distance_km, duration_minutes, base_fare, status) VALUES (?, ?, ?, ?, ?, ?, ?)", 'sssiids', [$origin, $destination, $routeMode, $distance, $minutes, $fare, $status]);
    }
    if ($previousStatus === 'inactive' && $status === 'active' && $id > 0) {
        require_once dirname(__DIR__) . '/includes/timetable.php';
        restore_route_service($id);
    }
    if ($previousStatus === 'inactive' && $status === 'active') {
        flash('ok', 'Route is active again. Its vehicles are back on their trips and in search.');
    } elseif ($previousStatus !== '') {
        flash('ok', 'Route updated.');
    } else {
        flash('ok', 'Route added.');
    }
    redirect('routes.php?mode=' . urlencode($routeMode));
}

require_once dirname(__DIR__) . '/includes/places.php';
$edit = isset($_GET['id']) ? qone('SELECT * FROM routes WHERE id = ?', 'i', [(int) $_GET['id']]) : null;
$mode = clean_mode($_GET['mode'] ?? ($edit['mode'] ?? 'bus'));
$citySuggest = array_keys(bd_cities());
sort($citySuggest, SORT_STRING);
$fromFind = trim($_GET['from'] ?? '');
$toFind = trim($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$filterTypes = 's';
$filterParams = [$mode];
$clauses = ['r.mode = ?'];
if ($fromFind !== '') {
    $clauses[] = 'r.origin LIKE ?';
    $filterTypes .= 's';
    $filterParams[] = '%' . $fromFind . '%';
}
if ($toFind !== '') {
    $clauses[] = 'r.destination LIKE ?';
    $filterTypes .= 's';
    $filterParams[] = '%' . $toFind . '%';
}
if ($clauses) {
    $where = 'WHERE ' . implode(' AND ', $clauses);
}
$routeQuery = 'mode=' . urlencode($mode) . '&from=' . urlencode($fromFind) . '&to=' . urlencode($toFind);
$totalRoutes = (int) qone("SELECT COUNT(*) c FROM routes r $where", $filterTypes, $filterParams)['c'];
$pages = max(1, (int) ceil($totalRoutes / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;
$rows = qall(
    "SELECT r.*, (SELECT COUNT(*) FROM schedules s WHERE s.route_id = r.id) trips
     FROM routes r $where
     ORDER BY r.origin, r.destination
     LIMIT $perPage OFFSET $offset",
    $filterTypes,
    $filterParams
);
$pageTitle = 'Routes';
$active = 'routes';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="card form-grid" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= $edit ? 'Edit route' : 'Add route' ?></h2>
  <label>Mode
    <select name="mode">
      <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= ($edit['mode'] ?? $mode) === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php city_filter_field('origin', 'From', (string) ($edit['origin'] ?? ''), 'route-from', true); ?>
  <?php city_filter_field('destination', 'To', (string) ($edit['destination'] ?? ''), 'route-to', true); ?>
  <script type="application/json" class="city-source"><?= json_encode($citySuggest, JSON_UNESCAPED_UNICODE) ?></script>
  <label>Distance km<input type="number" min="1" name="distance_km" required value="<?= e((string) ($edit['distance_km'] ?? '')) ?>"></label>
  <label>Duration minutes<input type="number" min="1" name="duration_minutes" required value="<?= e((string) ($edit['duration_minutes'] ?? '')) ?>"></label>
  <label>Base fare<input type="number" min="1" step="0.01" name="base_fare" required value="<?= e((string) ($edit['base_fare'] ?? '')) ?>"></label>
  <label>Status
    <select name="status">
      <option value="active" <?= ($edit['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </label>
  <button class="btn" type="submit"><?= $edit ? 'Save route' : 'Add route' ?></button>
</form>
<form class="filters route-find" method="get">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <?php city_filter_field('from', 'From', $fromFind); ?>
  <span class="filter-arrow" aria-hidden="true">&rarr;</span>
  <?php city_filter_field('to', 'To', $toFind); ?>
  <button class="btn" type="submit">Search</button>
  <span class="route-modes">
    <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
      <a class="<?= $mode === $key ? 'on' : '' ?>" href="routes.php?mode=<?= e($key) ?>&from=<?= urlencode($fromFind) ?>&to=<?= urlencode($toFind) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </span>
  <span class="muted"><?= number_format($totalRoutes) ?> routes</span>
  <script type="application/json" class="city-source"><?= json_encode(cities(), JSON_UNESCAPED_UNICODE) ?></script>
</form>
<div class="table-wrap card">
  <table>
    <thead><tr><th>Route</th><th>Distance</th><th>Duration</th><th>Fare</th><th>Trips</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= mode_badge($row['mode'] ?? 'bus') ?> <?= e($row['origin']) ?> &rarr; <?= e($row['destination']) ?></td>
          <td><?= (int) $row['distance_km'] ?> km</td>
          <td><?= e(duration_label((int) $row['duration_minutes'])) ?></td>
          <td><?= e(money($row['base_fare'])) ?></td>
          <td><?= (int) $row['trips'] ?></td>
          <td><?= badge($row['status']) ?></td>
          <td class="actions">
            <a href="routes.php?mode=<?= e($mode) ?>&id=<?= (int) $row['id'] ?>">Edit</a>
            <form method="post" data-confirm="Delete this route?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="mode" value="<?= e($mode) ?>">
              <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($pages > 1): ?>
  <p class="filters pager">
    <?php if ($page > 1): ?>
      <a href="routes.php?<?= $routeQuery ?>&page=<?= $page - 1 ?>">Previous</a>
    <?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
      <a href="routes.php?<?= $routeQuery ?>&page=<?= $page + 1 ?>">Next</a>
    <?php endif; ?>
  </p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
