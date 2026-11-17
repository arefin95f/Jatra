<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/timetable.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish_week') {
    csrf_check();
    $before = (string) (qone("SELECT svalue FROM settings WHERE skey = 'schedule_fill'")['svalue'] ?? '');
    publish_trips_for_page();
    $after = timetable_publish_status();
    $after['moved'] = $after['done'] || $after['cursor'] !== $before;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($after);
    exit;
}

$publish = timetable_publish_status();

$bookings = (int) qone("SELECT COUNT(*) c FROM bookings WHERE booking_status <> 'cancelled'")['c'];
$revenue = (float) qone("SELECT COALESCE(SUM(amount),0) c FROM bookings WHERE payment_status = 'paid'")['c'];
$passengers = (int) qone("SELECT COUNT(*) c FROM users WHERE role = 'passenger'")['c'];
$today = (int) qone("SELECT COUNT(*) c FROM bookings WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY")['c'];
$collected = $revenue;
$refunds = (float) qone("SELECT COALESCE(SUM(refund_amount),0) c FROM bookings")['c'];

$monthKeys = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKeys[] = date('Y-m', strtotime("first day of -$i month"));
}
$monthRows = qall(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) c
     FROM bookings WHERE created_at >= ? GROUP BY ym",
    's',
    [$monthKeys[0] . '-01']
);
$monthMap = [];
foreach ($monthRows as $row) {
    $monthMap[$row['ym']] = (int) $row['c'];
}
$maxMonth = max(1, ...array_map(fn ($key) => $monthMap[$key] ?? 0, $monthKeys));

$statusRows = qall('SELECT booking_status status, COUNT(*) c FROM bookings GROUP BY booking_status');
$statusMap = ['confirmed' => 0, 'cancelled' => 0, 'completed' => 0];
foreach ($statusRows as $row) {
    if (!array_key_exists($row['status'], $statusMap)) {
        continue;
    }
    $statusMap[$row['status']] = (int) $row['c'];
}
$statusTotal = max(1, array_sum($statusMap));

$recent = qall(
    "SELECT b.booking_code, b.booking_status, b.created_at, r.origin, r.destination, r.mode, s.depart_time
     FROM bookings b
     JOIN schedules s ON s.id = b.schedule_id
     JOIN routes r ON r.id = s.route_id
     ORDER BY b.id DESC LIMIT 6"
);
$topRoutes = qall(
    "SELECT r.origin, r.destination, COUNT(*) c
     FROM bookings b
     JOIN schedules s ON s.id = b.schedule_id
     JOIN routes r ON r.id = s.route_id
     WHERE b.booking_status <> 'cancelled'
     GROUP BY r.id
     ORDER BY c DESC LIMIT 4"
);
$topMax = max(1, ...array_map(fn ($row) => (int) $row['c'], $topRoutes ?: [['c' => 1]]));

$colors = ['confirmed' => '#0f9f6e', 'cancelled' => '#dc3b3b', 'completed' => '#64748b'];
$cursor = 0;
$stops = [];
foreach ($statusMap as $name => $count) {
    $next = $cursor + ($count / $statusTotal) * 100;
    $stops[] = $colors[$name] . ' ' . $cursor . '% ' . $next . '%';
    $cursor = $next;
}

$modeCounts = ['bus' => 0, 'train' => 0, 'plane' => 0];
foreach (qall("SELECT r.mode, COUNT(*) c FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.status = 'scheduled' AND s.travel_date >= CURDATE() GROUP BY r.mode") as $row) {
    $modeCounts[$row['mode']] = (int) $row['c'];
}

$pageTitle = 'Dashboard';
$active = 'dashboard';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<?php if (!$publish['done']): ?>
<section class="card publish-card" id="db-update"
  data-state="<?= $publish['percent'] > 0 ? 'partial' : 'idle' ?>"
  data-percent="<?= (int) $publish['percent'] ?>"
  data-done="0"
  data-place="<?= e($publish['place']) ?>"
  data-from="<?= e($publish['from']) ?>"
  data-through="<?= e($publish['through']) ?>"
  data-csrf="<?= e(csrf_token()) ?>">
    <div class="publish-head">
      <h2>Database update</h2>
      <span class="publish-pill" data-pill data-state="<?= $publish['percent'] > 0 ? 'partial' : 'idle' ?>"><?= $publish['percent'] > 0 ? 'Incomplete' : 'Not updated' ?></span>
    </div>
    <div class="publish-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $publish['percent'] ?>" data-track>
      <span class="publish-bar" data-bar style="width: <?= (int) $publish['percent'] ?>%"></span>
    </div>
    <div class="publish-meta">
      <p data-label><?= $publish['place'] !== '' ? 'Stopped at ' . e($publish['place']) . '.' : 'Timetable ' . e($publish['from']) . ' – ' . e($publish['through']) . ' is not on sale yet.' ?></p>
      <strong data-pct><?= (int) $publish['percent'] ?>%</strong>
    </div>
    <p class="publish-note" data-note><?php if ($publish['updated_from'] !== ''): ?>Last update <?= e($publish['updated_from']) ?> – <?= e($publish['updated_through']) ?>.<?php else: ?>No timetable update on file.<?php endif; ?></p>
    <button class="btn" type="button" data-go><?= $publish['percent'] > 0 ? 'Resume update' : 'Update database' ?></button>
</section>
<script>
(function () {
  var root = document.getElementById('db-update');
  if (!root) return;
  var bar = root.querySelector('[data-bar]');
  var track = root.querySelector('[data-track]');
  var pill = root.querySelector('[data-pill]');
  var label = root.querySelector('[data-label]');
  var note = root.querySelector('[data-note]');
  var pct = root.querySelector('[data-pct]');
  var button = root.querySelector('[data-go]');

  function settle(fromDate, throughDate) {
    root.className = 'publish-done';
    root.setAttribute('role', 'status');
    root.replaceChildren();
    var mark = document.createElement('span');
    mark.className = 'publish-mark';
    mark.setAttribute('aria-hidden', 'true');
    mark.textContent = '✓';
    var line = document.createElement('p');
    var title = document.createElement('strong');
    title.textContent = 'Update complete.';
    line.append(title, document.createTextNode(' Timetable on sale ' + fromDate + ' – ' + throughDate + '.'));
    root.append(mark, line);
    window.setTimeout(function () {
      root.remove();
    }, 6000);
  }

  function weekLine() {
    return (root.dataset.from || '') + ' – ' + (root.dataset.through || '');
  }

  function paint(percent, state, title) {
    if (!bar || !track || !pct || !pill || !label || !note) return;
    var width = Math.max(0, Math.min(100, percent));
    bar.style.width = width + '%';
    track.setAttribute('aria-valuenow', String(width));
    pct.textContent = width + '%';
    pill.textContent = state === 'running' ? 'Updating' : (state === 'stopped' ? 'Stopped' : (width > 0 ? 'Incomplete' : 'Not updated'));
    pill.dataset.state = state;
    root.dataset.state = state;
    label.textContent = title;
  }

  if (root.dataset.done === '1') return;

  if (!button) return;
  button.addEventListener('click', async function () {
    button.disabled = true;
    button.textContent = 'Updating…';
    var lastCursor = '';
    while (true) {
      paint(Number(track.getAttribute('aria-valuenow') || 0), 'running', root.dataset.place ? 'Updating ' + root.dataset.place + '.' : 'Updating ' + weekLine() + '.');
      var body = new FormData();
      body.append('csrf', root.dataset.csrf || '');
      body.append('action', 'publish_week');
      var response;
      try {
        response = await fetch('index.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json' } });
      } catch (error) {
        paint(Number(track.getAttribute('aria-valuenow') || 0), 'stopped', 'Update stopped. Resume ' + weekLine() + '.');
        button.disabled = false;
        button.textContent = 'Resume update';
        return;
      }
      if (!response.ok) {
        paint(Number(track.getAttribute('aria-valuenow') || 0), 'stopped', 'Update stopped. Resume ' + weekLine() + '.');
        button.disabled = false;
        button.textContent = 'Resume update';
        return;
      }
      var data;
      try {
        data = await response.json();
      } catch (error) {
        paint(Number(track.getAttribute('aria-valuenow') || 0), 'stopped', 'Update stopped. Resume ' + weekLine() + '.');
        button.disabled = false;
        button.textContent = 'Resume update';
        return;
      }
      root.dataset.place = data.place || '';
      if (data.done) {
        settle(data.from || root.dataset.from || '', data.through || root.dataset.through || '');
        return;
      }
      paint(Number(data.percent || 0), 'running', data.place ? 'Updating ' + data.place + '.' : 'Updating ' + weekLine() + '.');
      if (!data.moved || data.cursor === lastCursor) {
        paint(Number(data.percent || 0), 'stopped', 'Timetable did not move. Resume ' + weekLine() + '.');
        button.disabled = false;
        button.textContent = 'Resume update';
        return;
      }
      lastCursor = data.cursor || '';
    }
  });
})();
</script>
<?php endif; ?>

<section class="stats">
  <article><p>Total bookings</p><strong><?= number_format($bookings) ?></strong></article>
  <article><p>Collected revenue</p><strong><?= e(money($revenue)) ?></strong></article>
  <article><p>Passengers</p><strong><?= number_format($passengers) ?></strong></article>
  <article><p>Today's bookings</p><strong><?= number_format($today) ?></strong><a href="bookings.php?when=today">View</a></article>
</section>
<section class="mode-board">
  <?php foreach (['bus' => 'Upcoming buses', 'train' => 'Upcoming trains', 'plane' => 'Upcoming flights'] as $key => $label): ?>
    <a class="<?= e($key) ?>" href="schedules.php?mode=<?= e($key) ?>#trips">
      <span><?= e($label) ?></span>
      <strong><?= number_format($modeCounts[$key]) ?></strong>
    </a>
  <?php endforeach; ?>
</section>

<section class="dash-grid">
  <article class="card">
    <h2>Booking overview</h2>
    <div class="bars">
      <?php foreach ($monthKeys as $key): ?>
        <?php $count = $monthMap[$key] ?? 0; ?>
        <div class="bar">
          <span><?= $count ?></span>
          <i style="height: <?= (int) round(($count / $maxMonth) * 100) ?>%"></i>
          <small><?= e(date('M', strtotime($key . '-01'))) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </article>
  <article class="card">
    <h2>Recent bookings</h2>
    <?php foreach ($recent as $row): ?>
      <div class="recent">
        <div>
          <strong><?= e($row['booking_code']) ?></strong>
          <p><?= mode_badge($row['mode']) ?> <?= e($row['origin']) ?> → <?= e($row['destination']) ?> · <?= e(clock_label($row['depart_time'])) ?></p>
        </div>
        <?= badge($row['booking_status']) ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$recent): ?><p class="muted">No bookings yet.</p><?php endif; ?>
  </article>
</section>

<section class="dash-grid three">
  <article class="card">
    <h2>Top routes</h2>
    <?php foreach ($topRoutes as $route): ?>
      <div class="meter">
        <span><?= e($route['origin']) ?> → <?= e($route['destination']) ?></span>
        <i><b style="width: <?= (int) round(((int) $route['c'] / $topMax) * 100) ?>%"></b></i>
        <em><?= (int) $route['c'] ?></em>
      </div>
    <?php endforeach; ?>
    <?php if (!$topRoutes): ?><p class="muted">Routes will appear after the first bookings.</p><?php endif; ?>
  </article>
  <article class="card">
    <h2>Bookings by status</h2>
    <div class="donut-wrap">
      <div class="donut" style="background: conic-gradient(<?= e(implode(', ', $stops)) ?>)"><span><?= $statusTotal ?></span></div>
      <ul class="legend-list">
        <?php foreach ($statusMap as $name => $count): ?>
          <li><i style="background: <?= e($colors[$name]) ?>"></i><?= e(ucfirst($name)) ?> · <?= $count ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </article>
  <article class="card">
    <h2>Payment summary</h2>
    <p class="pay-line"><span>Collected</span><strong><?= e(money($collected)) ?></strong></p>
    <p class="pay-line"><span>Refunds</span><strong><?= e(money($refunds)) ?></strong></p>
  </article>
</section>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
