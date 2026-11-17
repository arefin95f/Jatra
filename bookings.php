<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/bookings.php');
}

$noticeId = (int) ($_GET['notice'] ?? 0);
if ($noticeId > 0) {
    require_once __DIR__ . '/includes/fleet_replace.php';
    ensure_replacement_tables();
    qexec(
        'UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
        'ii',
        [$noticeId, (int) $user['id']]
    );
    redirect('bookings.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$tripType = ($_GET['trip'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
$tripCondition = $tripType === 'upcoming'
    ? 'TIMESTAMP(s.travel_date, s.depart_time) >= NOW()'
    : 'TIMESTAMP(s.travel_date, s.depart_time) < NOW()';
$pageSize = 20;
$offset = ($page - 1) * $pageSize;
$pageGroups = qall(
    "SELECT b.schedule_id,
            CASE WHEN NULLIF(b.payment_ref, '') IS NULL
                 THEN CONCAT('booking:', b.id)
                 ELSE CONCAT('payment:', b.payment_ref)
            END AS purchase_key,
            MAX(b.id) AS newest_id
     FROM bookings b
     JOIN schedules s ON s.id = b.schedule_id
     WHERE b.user_id = ? AND " . $tripCondition . "
     GROUP BY b.schedule_id, purchase_key
     ORDER BY newest_id DESC
     LIMIT ? OFFSET ?",
    'iii',
    [(int) $user['id'], $pageSize + 1, $offset]
);
$hasNextPage = count($pageGroups) > $pageSize;
$pageGroups = array_slice($pageGroups, 0, $pageSize);
$rows = [];
if ($pageGroups) {
    $conditions = [];
    $params = [(int) $user['id']];
    $types = 'i';
    foreach ($pageGroups as $group) {
        if (str_starts_with($group['purchase_key'], 'booking:')) {
            $conditions[] = 'b.id = ?';
            $types .= 'i';
            $params[] = (int) substr($group['purchase_key'], 8);
        } else {
            $conditions[] = '(b.schedule_id = ? AND b.payment_ref = ?)';
            $types .= 'is';
            $params[] = (int) $group['schedule_id'];
            $params[] = substr($group['purchase_key'], 8);
        }
    }
    $rows = qall(
        "SELECT b.*, s.travel_date, s.depart_time,
            TIMESTAMP(s.travel_date, s.depart_time) >= NOW() AS is_upcoming,
            r.origin, r.destination, r.mode,
            COALESCE(bus.coach_type, tn.seat_class, pl.cabin) AS cabin
     FROM bookings b
     JOIN schedules s ON s.id = b.schedule_id
     JOIN routes r ON r.id = s.route_id
     LEFT JOIN buses bus ON bus.id = s.bus_id
     LEFT JOIN trains tn ON tn.id = s.train_id
     LEFT JOIN planes pl ON pl.id = s.plane_id
     WHERE b.user_id = ? AND (" . implode(' OR ', $conditions) . ')
     ORDER BY b.id DESC',
        $types,
        $params
    );
}
$tripGroups = ['upcoming' => [], 'past' => []];
foreach ($rows as $row) {
    $ref = trim((string) ($row['payment_ref'] ?? ''));
    $key = $ref !== '' ? $ref . ':' . $row['schedule_id'] : 'one:' . $row['id'];
    $tripType = $row['is_upcoming'] ? 'upcoming' : 'past';
    $tripGroups[$tripType][$key][] = $row;
}

$pageTitle = 'My tickets';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <h1>My tickets</h1>
  <nav class="ticket-tabs" role="tablist" aria-label="Ticket trips">
    <a role="tab" aria-selected="<?= $tripType === 'upcoming' ? 'true' : 'false' ?>" class="<?= $tripType === 'upcoming' ? 'active' : '' ?>" href="?trip=upcoming">Upcoming Trips</a>
    <a role="tab" aria-selected="<?= $tripType === 'past' ? 'true' : 'false' ?>" class="<?= $tripType === 'past' ? 'active' : '' ?>" href="?trip=past">Past Trips</a>
  </nav>
  <?php if (!$tripGroups[$tripType]): ?>
    <div class="empty">
      <?php if ($tripType === 'upcoming'): ?>No upcoming trips. <a href="index.php">Find a trip</a>.
      <?php else: ?>No past trips yet.<?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap tickets-table">
      <table>
        <thead>
          <tr><th>Code</th><th>Route</th><th>When</th><th>Seat</th><th>Fare</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($tripGroups[$tripType] as $group): ?>
            <?php
              usort($group, function (array $a, array $b): int {
                  return strnatcmp($a['seat_label'], $b['seat_label']);
              });
              $first = $group[0];
              $count = count($group);
              $labels = array_map(fn (array $seat): string => $seat['seat_label'], $group);
              $fare = array_sum(array_map(fn (array $seat): float => (float) $seat['amount'], $group));
              $refunded = array_sum(array_map(fn (array $seat): float => (float) ($seat['refund_amount'] ?? 0), $group));
              $statuses = array_unique(array_column($group, 'booking_status'));
              $codeLabel = $count > 1 ? (string) $first['payment_ref'] : $first['booking_code'];
            ?>
            <tr>
              <td>
                <a href="ticket.php?code=<?= urlencode($first['booking_code']) ?>"><?= e($codeLabel) ?></a>
                <?php if ($count > 1): ?><br><span class="muted"><?= (int) $count ?> seats</span><?php endif; ?>
              </td>
              <td><?= mode_badge($first['mode']) ?><br><?= e($first['origin']) ?> → <?= e($first['destination']) ?></td>
              <td><?= e(date_label($first['travel_date'])) ?><br><?= e(clock_label($first['depart_time'])) ?></td>
              <td>
                <?= e(implode(', ', $labels)) ?>
                <br><span class="muted"><?= e(($first['mode'] === 'train') ? train_cabin_label((string) $first['cabin'], (string) $first['depart_time']) : (string) $first['cabin']) ?></span>
              </td>
              <td><?= e(money($fare)) ?><?php if ($refunded > 0): ?><br><span class="muted">Refund <?= e(money($refunded)) ?></span><?php endif; ?></td>
              <td><?php if (count($statuses) === 1): ?><?= badge($statuses[0]) ?><?php else: ?><span class="muted">Mixed</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?php if ($page > 1 || $hasNextPage): ?>
    <nav class="pagination" aria-label="Ticket pages">
      <?php if ($page > 1): ?><a href="?trip=<?= e($tripType) ?>&amp;page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
      <span>Page <?= $page ?></span>
      <?php if ($hasNextPage): ?><a href="?trip=<?= e($tripType) ?>&amp;page=<?= $page + 1 ?>">Next</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
