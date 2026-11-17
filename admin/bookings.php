<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $booking = qone(
        'SELECT b.*, s.travel_date, s.depart_time FROM bookings b JOIN schedules s ON s.id = b.schedule_id WHERE b.id = ?',
        'i',
        [$id]
    );
    if (!$booking) {
        flash('err', 'Booking not found.');
    } elseif ($action === 'cancel') {
        $purchase = purchase_bookings($booking);
        $result = count($purchase) > 1 ? cancel_purchase($purchase) : cancel_ticket($booking);
        flash($result['ok'] ? 'ok' : 'err', $result['message']);
    } else {
        flash('err', 'That booking cannot be changed.');
    }
    redirect('bookings.php');
}

$q = trim($_GET['q'] ?? '');
$when = ($_GET['when'] ?? '') === 'today' ? 'today' : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$from = 'FROM bookings b
         JOIN schedules s ON s.id = b.schedule_id
         JOIN routes r ON r.id = s.route_id
         JOIN users u ON u.id = b.user_id
         WHERE 1 = 1';
$types = '';
$params = [];
if ($q !== '') {
    $from .= " AND (b.booking_code LIKE ? OR b.passenger_name LIKE ? OR b.passenger_email LIKE ? OR b.payment_ref LIKE ? OR r.origin LIKE ? OR r.destination LIKE ?)";
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($when === 'today') {
    $from .= ' AND b.created_at >= CURDATE() AND b.created_at < CURDATE() + INTERVAL 1 DAY';
}
if (($_GET['live'] ?? '') === '1') {
    $latest = qone("SELECT COALESCE(MAX(b.created_at), '') stamp $from", $types, $params);
    header('Content-Type: application/json');
    echo json_encode(['stamp' => (string) ($latest['stamp'] ?? '')]);
    exit;
}
$purchaseKey = "CASE WHEN NULLIF(b.payment_ref, '') IS NULL
                     THEN CONCAT('booking:', b.id)
                     ELSE CONCAT('payment:', b.schedule_id, ':', b.payment_ref)
                END";
$totalRows = (int) qone(
    "SELECT COUNT(*) c FROM (
        SELECT b.schedule_id, $purchaseKey AS purchase_key
        $from
        GROUP BY b.schedule_id, purchase_key
     ) tickets",
    $types,
    $params
)['c'];
$pages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;
$pageGroups = qall(
    "SELECT b.schedule_id, $purchaseKey AS purchase_key, MAX(b.id) AS newest_id, MIN(b.created_at) AS purchased_at
     $from
     GROUP BY b.schedule_id, purchase_key
     ORDER BY purchased_at DESC, newest_id DESC
     LIMIT $perPage OFFSET $offset",
    $types,
    $params
);
$rows = [];
if ($pageGroups) {
    $conditions = [];
    $seatTypes = '';
    $seatParams = [];
    foreach ($pageGroups as $group) {
        if (str_starts_with((string) $group['purchase_key'], 'booking:')) {
            $conditions[] = 'b.id = ?';
            $seatTypes .= 'i';
            $seatParams[] = (int) substr((string) $group['purchase_key'], 8);
        } else {
            $conditions[] = '(b.schedule_id = ? AND b.payment_ref = ?)';
            $seatTypes .= 'is';
            $seatParams[] = (int) $group['schedule_id'];
            $seatParams[] = substr((string) $group['purchase_key'], strlen('payment:' . $group['schedule_id'] . ':'));
        }
    }
    $rows = qall(
        "SELECT b.*, r.origin, r.destination, s.travel_date, s.depart_time, u.full_name,
                TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMP(s.travel_date, s.depart_time)) AS minutes_left
         FROM bookings b
         JOIN schedules s ON s.id = b.schedule_id
         JOIN routes r ON r.id = s.route_id
         JOIN users u ON u.id = b.user_id
         WHERE " . implode(' OR ', $conditions) . '
         ORDER BY b.id DESC',
        $seatTypes,
        $seatParams
    );
}
$byPurchase = [];
foreach ($rows as $row) {
    $byPurchase[booking_purchase_key($row)][] = $row;
}
$tickets = [];
foreach ($pageGroups as $group) {
    $stored = (string) $group['purchase_key'];
    if (str_starts_with($stored, 'booking:')) {
        $key = 'one:' . substr($stored, 8);
    } else {
        $ref = substr($stored, strlen('payment:' . $group['schedule_id'] . ':'));
        $key = (int) $group['schedule_id'] . ':' . $ref;
    }
    if (isset($byPurchase[$key])) {
        $tickets[$key] = $byPurchase[$key];
    }
}
$latestStamp = (string) (qone("SELECT COALESCE(MAX(b.created_at), '') stamp $from", $types, $params)['stamp'] ?? '');
$pageQuery = 'q=' . urlencode($q) . ($when !== '' ? '&when=today' : '');

$pageTitle = 'Bookings';
$active = 'bookings';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="filters" method="get">
  <?php if ($when !== ''): ?><input type="hidden" name="when" value="today"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Code, passenger, email, or city">
  <button class="btn" type="submit">Search</button>
</form>
<div class="table-wrap card desk-fit">
  <table id="booking-desk" class="ops-table" data-stamp="<?= e($latestStamp) ?>" data-live="bookings.php?live=1&<?= e($pageQuery) ?>">
    <thead><tr><th>Code</th><th>Purchased</th><th>Passenger</th><th>Route</th><th>When</th><th>Seat</th><th>Pay</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($tickets as $seats): ?>
        <?php
          usort($seats, static fn (array $a, array $b): int => strnatcmp((string) $a['seat_label'], (string) $b['seat_label']));
          $row = $seats[0];
          $count = count($seats);
          $fare = array_sum(array_map(static fn (array $seat): float => (float) $seat['amount'], $seats));
          $methods = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['payment_method'], $seats)));
          $payStates = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['payment_status'], $seats)));
          $states = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['booking_status'], $seats)));
          $codeLabel = $count > 1 && trim((string) $row['payment_ref']) !== '' ? (string) $row['payment_ref'] : (string) $row['booking_code'];
          $open = array_values(array_filter(
              $seats,
              static fn (array $seat): bool => $seat['booking_status'] === 'confirmed' && (int) $seat['minutes_left'] > 0
          ));
          $bought = '';
          foreach ($seats as $seat) {
              $at = (string) ($seat['created_at'] ?? '');
              if ($bought === '' || ($at !== '' && $at < $bought)) {
                  $bought = $at;
              }
          }
        ?>
        <tr>
          <td>
            <a href="../ticket.php?code=<?= urlencode($row['booking_code']) ?>"><?= e($codeLabel) ?></a>
            <?php if ($count > 1): ?><br><span class="muted"><?= $count ?> seats</span><?php endif; ?>
          </td>
          <td><?= e(purchased_label($bought)) ?></td>
          <td>
            <a href="passenger.php?id=<?= (int) $row['user_id'] ?>&profile=1"><?= e($row['passenger_name']) ?></a>
            <br><span class="muted"><?= e($row['passenger_phone']) ?></span>
          </td>
          <td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td>
          <td><?= e(date_label($row['travel_date'])) ?><br><?= e(clock_label($row['depart_time'])) ?></td>
          <td class="ops-seats"><?php seat_chips($seats); ?></td>
          <td><?= e(money($fare)) ?><br><span class="muted"><?= e(count($methods) === 1 ? method_label($methods[0]) : 'Mixed') ?> · <?= e(count($payStates) === 1 ? $payStates[0] : 'mixed') ?></span></td>
          <td><?= count($states) === 1 ? badge($states[0]) : '<span class="muted">Mixed</span>' ?></td>
          <td class="actions">
            <?php if ($open): ?>
              <?php $note = refund_note((int) $open[0]['minutes_left'], (string) $open[0]['payment_status'], $fare); ?>
              <form method="post" data-confirm="Cancel this ticket? <?= e($note) ?> The QR code will stop working.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $open[0]['id'] ?>">
                <button type="submit">Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$tickets): ?><p class="muted pad">No bookings match this view.</p><?php endif; ?>
</div>
<?php if ($pages > 1): ?>
  <p class="filters pager">
    <?php if ($page > 1): ?><a href="bookings.php?<?= $pageQuery ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($totalRows) ?> tickets</span>
    <?php if ($page < $pages): ?><a href="bookings.php?<?= $pageQuery ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
  </p>
<?php endif; ?>
<script>
(function () {
  var desk = document.getElementById("booking-desk");
  if (!desk) return;
  var stamp = desk.getAttribute("data-stamp") || "";
  var url = desk.getAttribute("data-live");
  window.setInterval(function () {
    var active = document.activeElement;
    if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.tagName === "SELECT")) return;
    fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && typeof data.stamp === "string" && data.stamp !== stamp) {
          window.location.reload();
        }
      })
      .catch(function () {});
  }, 4000);
})();
</script>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
