<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/timetable.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/buses.php');
}

$mode = $_GET['mode'] ?? 'bus';
if (!in_array($mode, ['bus', 'train', 'plane'], true)) {
    $mode = 'bus';
}
$q = trim($_GET['q'] ?? '');
if (strlen($q) > 80) {
    $q = substr($q, 0, 80);
}

$spec = match ($mode) {
    'train' => ['table' => 'trains', 'code' => 'train_number', 'name' => 'name', 'class' => 'seat_class', 'order' => 'train_number'],
    'plane' => ['table' => 'planes', 'code' => 'flight_number', 'name' => 'airline', 'class' => 'cabin', 'order' => 'flight_number'],
    default => ['table' => 'buses', 'code' => 'bus_number', 'name' => 'name', 'class' => 'coach_type', 'order' => 'bus_number'],
};
$placeholder = match ($mode) {
    'train' => 'Number, name, class, or day',
    'plane' => 'Flight, airline, cabin, or day',
    default => 'Number, name, coach, or day',
};
$noun = ['bus' => 'bus', 'train' => 'train', 'plane' => 'plane'][$mode];

$where = "status = 'active'";
$types = '';
$params = [];
if ($q !== '') {
    $dayIds = [];
    foreach (weekday_options(true) as $dayNum => $dayLabel) {
        if (stripos($dayLabel, $q) !== false) {
            $dayIds[] = (int) $dayNum;
        }
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= " AND ({$spec['code']} LIKE ? OR {$spec['name']} LIKE ? OR {$spec['class']} LIKE ?";
    $types = 'sss';
    $params = [$like, $like, $like];
    if ($dayIds) {
        $where .= ' OR day_off IN (' . implode(',', array_fill(0, count($dayIds), '?')) . ')';
        $types .= str_repeat('i', count($dayIds));
        array_push($params, ...$dayIds);
    }
    $where .= ')';
}
$total = (int) (qone(
    "SELECT COUNT(*) AS c FROM {$spec['table']} WHERE {$where}",
    $types,
    $params
)['c'] ?? 0);
$perPage = 40;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$rows = qall(
    "SELECT id, {$spec['code']} AS code, {$spec['name']} AS name, {$spec['class']} AS class_name, day_off
     FROM {$spec['table']}
     WHERE {$where}
     ORDER BY day_off, {$spec['order']}
     LIMIT {$perPage} OFFSET {$offset}",
    $types,
    $params
);

$pageTitle = 'Check Dayoff';
require __DIR__ . '/includes/header.php';
$tabQuery = $q !== '' ? '&q=' . urlencode($q) : '';
?>
<section class="panel">
  <h1>Check Dayoff</h1>
  <nav class="ticket-tabs" role="tablist" aria-label="Fleet modes">
    <a role="tab" aria-selected="<?= $mode === 'bus' ? 'true' : 'false' ?>" class="<?= $mode === 'bus' ? 'active' : '' ?>" href="?mode=bus<?= $tabQuery ?>">Buses</a>
    <a role="tab" aria-selected="<?= $mode === 'train' ? 'true' : 'false' ?>" class="<?= $mode === 'train' ? 'active' : '' ?>" href="?mode=train<?= $tabQuery ?>">Trains</a>
    <a role="tab" aria-selected="<?= $mode === 'plane' ? 'true' : 'false' ?>" class="<?= $mode === 'plane' ? 'active' : '' ?>" href="?mode=plane<?= $tabQuery ?>">Planes</a>
  </nav>
  <form class="dayoff-search" method="get" role="search">
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <label class="dayoff-field">
      <svg class="field-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M10.5 3a7.5 7.5 0 0 1 5.96 12.05l3.74 3.75-1.4 1.4-3.75-3.74A7.5 7.5 0 1 1 10.5 3zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11z"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($placeholder) ?>" aria-label="Search day off" maxlength="80">
      <button class="btn" type="submit">Search</button>
    </label>
  </form>
  <?php if ($q !== '' && !$rows): ?>
    <p class="dayoff-note">No <?= e($noun) ?> matches “<?= e($q) ?>”.</p>
  <?php else: ?>
    <p class="dayoff-note"><?= $q !== '' ? number_format($total) . ' ' . ($total === 1 ? 'match' : 'matches') : number_format($total) . ' ' . ($mode === 'bus' ? 'buses' : ($mode === 'train' ? 'trains' : 'planes')) ?></p>
    <div class="table-wrap tickets-table">
      <table>
        <thead>
          <tr>
            <th><?= $mode === 'plane' ? 'Flight' : 'Number' ?></th>
            <th><?= $mode === 'plane' ? 'Airline' : 'Name' ?></th>
            <th><?= $mode === 'bus' ? 'Coach' : ($mode === 'train' ? 'Class' : 'Cabin') ?></th>
            <th>Day off</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['code']) ?></td>
              <td><a href="vehicle.php?mode=<?= urlencode($mode) ?>&amp;id=<?= (int) $row['id'] ?>"><?= e($row['name']) ?></a></td>
              <td><?= e($row['class_name']) ?></td>
              <td><span class="day-off"><?= e(weekday_name((int) $row['day_off'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <?php $pageBase = 'rest-days.php?mode=' . urlencode($mode) . ($q !== '' ? '&q=' . urlencode($q) : ''); ?>
      <p class="filters pager">
        <?php if ($page > 1): ?><a href="<?= e($pageBase) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= number_format($pages) ?></span>
        <?php if ($page < $pages): ?><a href="<?= e($pageBase) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
