<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/fleet_replace.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/index.php');
}

ensure_replacement_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_one') {
        $id = (int) ($_POST['id'] ?? 0);
        qexec('DELETE FROM user_notifications WHERE id = ? AND user_id = ?', 'ii', [$id, (int) $user['id']]);
    } elseif ($action === 'read_all') {
        qexec('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', 'i', [(int) $user['id']]);
        flash('ok', 'All notifications marked as read.');
    }
    redirect('notifications.php');
}

$rows = qall(
    'SELECT n.id, n.booking_id, n.title, n.body, n.is_read, n.seen_at, n.created_at, r.mode,
            COALESCE(bus.name, tn.name, pl.airline) AS vehicle_name,
            COALESCE(bus.bus_number, tn.train_number, pl.flight_number) AS vehicle_code
     FROM user_notifications n
     LEFT JOIN bookings b ON b.id = n.booking_id
     LEFT JOIN schedules s ON s.id = b.schedule_id
     LEFT JOIN routes r ON r.id = s.route_id
     LEFT JOIN buses bus ON bus.id = s.bus_id
     LEFT JOIN trains tn ON tn.id = s.train_id
     LEFT JOIN planes pl ON pl.id = s.plane_id
     WHERE n.user_id = ?
     ORDER BY n.id DESC
     LIMIT 50',
    'i',
    [(int) $user['id']]
);
$freshIds = [];
foreach ($rows as $row) {
    if ((int) $row['is_read'] === 0 && empty($row['seen_at'])) {
        $freshIds[] = (int) $row['id'];
    }
}

$pageTitle = 'Notifications';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <h1>Notifications</h1>
  <?php if ($rows): ?>
    <form method="post" style="margin-bottom:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="read_all">
      <button class="btn" type="submit">Mark all read</button>
    </form>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="card"><p class="muted">No notifications yet.</p></div>
  <?php else: ?>
    <?php foreach ($rows as $row): ?>
      <?php $isFresh = (int) $row['is_read'] === 0 && empty($row['seen_at']); ?>
      <article class="card notify-item<?= $isFresh ? ' notify-fresh' : '' ?>">
        <?php if (!empty($row['mode'])): ?>
          <div class="notify-mode"><?= mode_badge((string) $row['mode']) ?></div>
        <?php endif; ?>
        <h2><?= e($row['title']) ?></h2>
        <?php
          $vehicleName = trim((string) ($row['vehicle_name'] ?? ''));
          $vehicleCode = trim((string) ($row['vehicle_code'] ?? ''));
          $vehicleLine = $vehicleName !== '' && $vehicleCode !== ''
              ? $vehicleName . ' (' . $vehicleCode . ')'
              : ($vehicleName !== '' ? $vehicleName : $vehicleCode);
        ?>
        <?php if ($vehicleLine !== ''): ?>
          <p class="notify-vehicle"><span>Vehicle</span> <strong><?= e($vehicleLine) ?></strong></p>
        <?php endif; ?>
        <p><?= e($row['body']) ?></p>
        <p class="muted"><?= e(date_label(substr($row['created_at'], 0, 10))) ?> · <?= e(substr($row['created_at'], 11, 5)) ?></p>
        <div class="notify-actions">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_one">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <button class="notify-box notify-box-delete" type="submit">Delete</button>
          </form>
          <?php if ((int) $row['booking_id'] > 0): ?>
            <a class="notify-box" href="bookings.php?notice=<?= (int) $row['id'] ?>">My tickets</a>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if ($freshIds): ?>
    <?php
      qexec(
          'UPDATE user_notifications SET seen_at = CURRENT_TIMESTAMP WHERE user_id = ? AND seen_at IS NULL AND id IN (' . implode(',', $freshIds) . ')',
          'i',
          [(int) $user['id']]
      );
    ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
