<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$find = trim($_GET['q'] ?? '');
$routePage = max(1, (int) ($_GET['route_page'] ?? 1));
$routePerPage = 30;
$monthPage = max(1, (int) ($_GET['month_page'] ?? 1));
$monthPerPage = 12;
$reportWhere = '';
$reportTypes = '';
$reportParams = [];
if ($find !== '') {
    $reportWhere = 'WHERE r.origin LIKE ? OR r.destination LIKE ? OR r.mode LIKE ?';
    $like = '%' . $find . '%';
    $reportTypes = 'sss';
    $reportParams = [$like, $like, $like];
}
$totalRoutes = (int) qone(
    "SELECT COUNT(*) c FROM routes r $reportWhere",
    $reportTypes,
    $reportParams
)['c'];
$routePages = max(1, (int) ceil($totalRoutes / $routePerPage));
if ($routePage > $routePages) {
    $routePage = $routePages;
}
$routeOffset = ($routePage - 1) * $routePerPage;
$byRoute = qall(
    "SELECT r.origin, r.destination, COUNT(b.id) tickets,
            COALESCE(SUM(CASE WHEN b.payment_status='paid' THEN b.amount END),0) revenue
     FROM routes r
     LEFT JOIN schedules s ON s.route_id = r.id
     LEFT JOIN bookings b ON b.schedule_id = s.id AND b.booking_status <> 'cancelled'
     $reportWhere
     GROUP BY r.id
     ORDER BY revenue DESC
     LIMIT $routePerPage OFFSET $routeOffset",
    $reportTypes,
    $reportParams
);
$totalMonths = (int) qone(
    "SELECT COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m')) c FROM bookings"
)['c'];
$monthPages = max(1, (int) ceil($totalMonths / $monthPerPage));
if ($monthPage > $monthPages) {
    $monthPage = $monthPages;
}
$monthOffset = ($monthPage - 1) * $monthPerPage;
$byMonth = qall(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) tickets,
            COALESCE(SUM(CASE WHEN payment_status='paid' THEN amount END),0) revenue
     FROM bookings
     GROUP BY ym
     ORDER BY ym DESC
     LIMIT $monthPerPage OFFSET $monthOffset"
);
$pageQuery = 'q=' . urlencode($find);
$pageTitle = 'Reports';
$active = 'reports';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<section class="dash-grid">
  <article class="card table-wrap">
    <h2>Revenue by route</h2>
    <form class="filters" method="get">
      <input type="search" name="q" value="<?= e($find) ?>" placeholder="City or bus, train, plane">
      <button class="btn" type="submit">Search</button>
    </form>
    <table>
      <thead><tr><th>Route</th><th>Tickets</th><th>Collected</th></tr></thead>
      <tbody>
        <?php foreach ($byRoute as $row): ?>
          <tr>
            <td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td>
            <td><?= (int) $row['tickets'] ?></td>
            <td><?= e(money($row['revenue'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($routePages > 1): ?>
      <p class="filters pager">
        <?php if ($routePage > 1): ?><a href="reports.php?<?= $pageQuery ?>&route_page=<?= $routePage - 1 ?>&month_page=<?= $monthPage ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $routePage ?> of <?= $routePages ?></span>
        <?php if ($routePage < $routePages): ?><a href="reports.php?<?= $pageQuery ?>&route_page=<?= $routePage + 1 ?>&month_page=<?= $monthPage ?>">Next</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </article>
  <article class="card table-wrap">
    <h2>Monthly sales</h2>
    <table>
      <thead><tr><th>Month</th><th>Bookings</th><th>Collected</th></tr></thead>
      <tbody>
        <?php foreach ($byMonth as $row): ?>
          <tr>
            <td><?= e(date('F Y', strtotime($row['ym'] . '-01'))) ?></td>
            <td><?= (int) $row['tickets'] ?></td>
            <td><?= e(money($row['revenue'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$byMonth): ?><p class="muted pad">No sales recorded yet.</p><?php endif; ?>
    <?php if ($monthPages > 1): ?>
      <p class="filters pager">
        <?php if ($monthPage > 1): ?><a href="reports.php?<?= $pageQuery ?>&route_page=<?= $routePage ?>&month_page=<?= $monthPage - 1 ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $monthPage ?> of <?= $monthPages ?></span>
        <?php if ($monthPage < $monthPages): ?><a href="reports.php?<?= $pageQuery ?>&route_page=<?= $routePage ?>&month_page=<?= $monthPage + 1 ?>">Next</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </article>
</section>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
