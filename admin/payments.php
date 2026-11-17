<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'paid', 'refunded'], true)) {
    $status = 'all';
}
$find = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$sql = "SELECT b.*, r.origin, r.destination FROM bookings b
        JOIN schedules s ON s.id = b.schedule_id
        JOIN routes r ON r.id = s.route_id
        WHERE 1 = 1";
$types = '';
$params = [];
if ($status !== 'all') {
    $sql .= ' AND b.payment_status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($find !== '') {
    $sql .= ' AND (b.booking_code LIKE ? OR b.payment_ref LIKE ? OR b.passenger_name LIKE ? OR r.origin LIKE ? OR r.destination LIKE ?)';
    $like = '%' . $find . '%';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}
$countSql = "SELECT COUNT(*) c FROM bookings b
             JOIN schedules s ON s.id = b.schedule_id
             JOIN routes r ON r.id = s.route_id
             WHERE 1 = 1";
if ($status !== 'all') {
    $countSql .= ' AND b.payment_status = ?';
}
if ($find !== '') {
    $countSql .= ' AND (b.booking_code LIKE ? OR b.payment_ref LIKE ? OR b.passenger_name LIKE ? OR r.origin LIKE ? OR r.destination LIKE ?)';
}
$totalRows = (int) qone($countSql, $types, $params)['c'];
$pages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;
$sql .= " ORDER BY b.id DESC LIMIT $perPage OFFSET $offset";
$rows = qall($sql, $types, $params);
$pageQuery = 'status=' . urlencode($status) . '&q=' . urlencode($find);
$totals = qone(
    "SELECT
        COALESCE(SUM(CASE WHEN payment_status='paid' THEN amount END),0) paid,
        COALESCE(SUM(refund_amount),0) refunded
     FROM bookings"
);
$pageTitle = 'Payments';
$active = 'payments';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<section class="stats slim">
  <article><p>Collected</p><strong><?= e(money($totals['paid'])) ?></strong></article>
  <article><p>Refunds</p><strong><?= e(money($totals['refunded'])) ?></strong></article>
</section>
<p class="filters">
  <?php foreach (['all', 'paid', 'refunded'] as $item): ?>
    <a class="<?= $status === $item ? 'on' : '' ?>" href="payments.php?status=<?= e($item) ?>&q=<?= urlencode($find) ?>"><?= e(ucfirst($item)) ?></a>
  <?php endforeach; ?>
</p>
<form class="filters" method="get">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q" value="<?= e($find) ?>" placeholder="Code, reference, passenger, or city">
  <button class="btn" type="submit">Search</button>
</form>
<div class="table-wrap card">
  <table>
    <thead><tr><th>Reference</th><th>Ticket</th><th>Route</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e($row['payment_ref'] ?? '—') ?></td>
          <td><?= e($row['booking_code']) ?></td>
          <td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td>
          <td><?= e(method_label($row['payment_method'])) ?></td>
          <td><?= e(money($row['amount'])) ?><?php if ((float) ($row['refund_amount'] ?? 0) > 0): ?><br><span class="muted">Refund <?= e(money($row['refund_amount'])) ?></span><?php endif; ?></td>
          <td><?= badge($row['payment_status']) ?></td>
          <td><?= e(date_label(substr($row['created_at'], 0, 10))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <p class="filters pager">
      <?php if ($page > 1): ?><a href="payments.php?<?= $pageQuery ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
      <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($totalRows) ?> payments</span>
      <?php if ($page < $pages): ?><a href="payments.php?<?= $pageQuery ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
    </p>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
