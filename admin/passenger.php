<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$profileOnly = ($_GET['profile'] ?? '') === '1';
$person = qone(
    'SELECT id, full_name, email, phone, nid_no, role, status, created_at FROM users WHERE id = ?',
    'i',
    [$id]
);

$trips = [];
$spent = 0.0;
$upcoming = 0;
if ($person && !$profileOnly) {
    $trips = qall(
        "SELECT b.id AS booking_id, b.schedule_id, b.payment_ref, b.booking_code, b.seat_label, b.amount, b.payment_status, b.payment_method, b.booking_status,
                r.origin, r.destination, r.mode,
                s.travel_date, s.depart_time,
                COALESCE(bus.name, tn.name, pl.airline) AS carrier_name,
                COALESCE(bus.bus_number, tn.train_number, pl.flight_number) AS service_code,
                COALESCE(bus.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin
         FROM bookings b
         JOIN schedules s ON s.id = b.schedule_id
         JOIN routes r ON r.id = s.route_id
         LEFT JOIN buses bus ON bus.id = s.bus_id
         LEFT JOIN trains tn ON tn.id = s.train_id
         LEFT JOIN planes pl ON pl.id = s.plane_id
         WHERE b.user_id = ?
           AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL
         ORDER BY s.travel_date DESC, s.depart_time DESC, b.id DESC",
        'i',
        [$id]
    );
    foreach ($trips as $trip) {
        if ($trip['booking_status'] !== 'cancelled' && $trip['travel_date'] >= $appToday) {
            $upcoming++;
        }
        if ($trip['payment_status'] === 'paid' && $trip['booking_status'] !== 'cancelled') {
            $spent += (float) $trip['amount'];
        }
    }
}

$pageTitle = $person ? $person['full_name'] : 'Passenger';
$active = $profileOnly ? 'bookings' : 'passengers';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<a class="desk-back" href="<?= $profileOnly ? 'bookings.php' : 'passengers.php' ?>"><?= $profileOnly ? 'Bookings' : 'Passenger' ?></a>
<?php if (!$person): ?>
  <div class="card panel"><p>That passenger is not on file.</p></div>
<?php else: ?>
  <section class="card panel pax-file">
    <div class="pax-file-head">
      <div>
        <p class="ops-kicker"><?= $profileOnly ? 'Profile' : 'Passenger file' ?></p>
        <h2><?= e($person['full_name']) ?></h2>
      </div>
      <?= badge($person['status']) ?>
    </div>
    <dl class="pax-meta">
      <div><dt>Phone</dt><dd><?= e($person['phone']) ?></dd></div>
      <div><dt>Email</dt><dd><?= e($person['email']) ?></dd></div>
      <div><dt>NID</dt><dd><?= e(nid_label($person['nid_no']) ?: 'Not on file') ?></dd></div>
      <div><dt>On file since</dt><dd><?= e(date_label(substr((string) $person['created_at'], 0, 10))) ?></dd></div>
    </dl>
    <?php if (!$profileOnly): ?>
    <div class="pax-stats">
      <article><span>Tickets</span><strong><?= count($trips) ?></strong></article>
      <article><span>Upcoming</span><strong><?= $upcoming ?></strong></article>
      <article><span>Paid</span><strong><?= e(money($spent)) ?></strong></article>
    </div>
    <?php endif; ?>
  </section>
  <?php if (!$profileOnly): ?>
  <div class="table-wrap card">
    <h2>Travel record</h2>
    <table class="ops-table">
      <thead>
        <tr>
          <th>Ticket</th>
          <th>Transport</th>
          <th>Route</th>
          <th>Departure</th>
          <th>Seat</th>
          <th>Payment</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$trips): ?>
          <tr><td colspan="7">No ticket on file.</td></tr>
        <?php endif; ?>
        <?php
        $purchases = [];
        foreach ($trips as $trip) {
            $purchases[booking_purchase_key($trip)][] = $trip;
        }
        ?>
        <?php foreach ($purchases as $seats): ?>
          <?php
          usort($seats, static fn (array $a, array $b): int => strnatcmp((string) $a['seat_label'], (string) $b['seat_label']));
          $trip = $seats[0];
          $carrier = trim((string) ($trip['carrier_name'] ?? ''));
          $service = trim((string) ($trip['service_code'] ?? ''));
          $detail = trim($service . (($trip['cabin'] ?? '') !== '' ? ' · ' . $trip['cabin'] : ''));
          $paid = 0.0;
          foreach ($seats as $seat) {
              $paid += (float) $seat['amount'];
          }
          $methods = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['payment_method'], $seats)));
          $payStates = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['payment_status'], $seats)));
          $states = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['booking_status'], $seats)));
          ?>
          <tr>
            <td><a href="../ticket.php?code=<?= urlencode($trip['booking_code']) ?>"><?= e($trip['booking_code']) ?></a></td>
            <td class="ops-run"><?= mode_badge($trip['mode']) ?> <?= e($carrier !== '' ? $carrier : 'Not assigned') ?><?php if ($detail !== ''): ?><br><span class="muted"><?= e($detail) ?></span><?php endif; ?></td>
            <td><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></td>
            <td><?= e(date_label($trip['travel_date'])) ?><br><span class="muted"><?= e(clock_label($trip['depart_time'])) ?></span></td>
            <td class="ops-seats"><?php seat_chips($seats); ?></td>
            <td><?= e(money($paid)) ?><br><span class="muted"><?= e(count($methods) === 1 ? method_label($methods[0]) : 'Mixed') ?> · <?= e(count($payStates) === 1 ? $payStates[0] : 'mixed') ?></span></td>
            <td><?= count($states) === 1 ? badge($states[0]) : '<span class="muted">Mixed</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
