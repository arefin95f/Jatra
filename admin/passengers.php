<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

/**
 * The trip a desk officer should see for this passenger.
 * A search match wins. Otherwise the next departure, then the latest ticket.
 */
function passenger_desk_trip(array $trips, string $find, string $today): ?array
{
    if (!$trips) {
        return null;
    }
    $pool = $trips;
    $matched = false;
    if ($find !== '') {
        $needle = mb_strtolower($find);
        $hits = [];
        foreach ($trips as $trip) {
            $hay = mb_strtolower(implode(' ', [
                (string) $trip['booking_code'],
                (string) $trip['passenger_name'],
                (string) $trip['seat_label'],
                (string) $trip['origin'],
                (string) $trip['destination'],
                (string) $trip['carrier_name'],
                (string) $trip['service_code'],
                (string) $trip['cabin'],
                (string) $trip['mode'],
            ]));
            if (mb_strpos($hay, $needle) !== false) {
                $hits[] = $trip;
            }
        }
        if ($hits) {
            $pool = $hits;
            $matched = true;
        }
    }
    $upcoming = array_values(array_filter(
        $pool,
        static fn (array $trip): bool => $trip['travel_date'] >= $today && $trip['booking_status'] !== 'cancelled'
    ));
    if ($upcoming) {
        usort($upcoming, static fn (array $a, array $b): int => [$a['travel_date'], $a['depart_time'], (int) $a['booking_id']] <=> [$b['travel_date'], $b['depart_time'], (int) $b['booking_id']]);
        $upcoming[0]['ops_kind'] = $matched ? 'Matched' : 'Next';
        return $upcoming[0];
    }
    $open = array_values(array_filter(
        $pool,
        static fn (array $trip): bool => $trip['booking_status'] !== 'cancelled'
    ));
    if ($open && !$matched) {
        $pool = $open;
    }
    usort($pool, static fn (array $a, array $b): int => [$b['travel_date'], $b['depart_time'], (int) $b['booking_id']] <=> [$a['travel_date'], $a['depart_time'], (int) $a['booking_id']]);
    $pool[0]['ops_kind'] = $matched ? 'Matched' : 'Last';
    return $pool[0];
}

$find = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$where = "u.role = 'passenger'
    AND EXISTS (
        SELECT 1
        FROM bookings assigned_booking
        JOIN schedules assigned_schedule ON assigned_schedule.id = assigned_booking.schedule_id
        WHERE assigned_booking.user_id = u.id
          AND COALESCE(assigned_schedule.bus_id, assigned_schedule.train_id, assigned_schedule.plane_id) IS NOT NULL
    )";
$types = '';
$params = [];
if ($find !== '') {
    $where .= " AND (
        u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.nid_no LIKE ?
        OR EXISTS (
            SELECT 1
            FROM bookings bx
            JOIN schedules sx ON sx.id = bx.schedule_id
            JOIN routes rx ON rx.id = sx.route_id
            LEFT JOIN buses busx ON busx.id = sx.bus_id
            LEFT JOIN trains tnx ON tnx.id = sx.train_id
            LEFT JOIN planes plx ON plx.id = sx.plane_id
            WHERE bx.user_id = u.id
              AND COALESCE(sx.bus_id, sx.train_id, sx.plane_id) IS NOT NULL
              AND (
                bx.booking_code LIKE ? OR bx.passenger_name LIKE ? OR bx.passenger_phone LIKE ? OR bx.seat_label LIKE ?
                OR rx.origin LIKE ? OR rx.destination LIKE ?
                OR busx.name LIKE ? OR busx.bus_number LIKE ?
                OR tnx.name LIKE ? OR tnx.train_number LIKE ?
                OR plx.airline LIKE ? OR plx.flight_number LIKE ?
              )
        )
    )";
    $like = '%' . $find . '%';
    $types = str_repeat('s', 16);
    $params = array_fill(0, 16, $like);
}

$totalRows = (int) qone("SELECT COUNT(*) c FROM users u WHERE $where", $types, $params)['c'];
$pages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;
$people = qall(
    "SELECT u.id, u.full_name, u.phone, u.status, COUNT(s.id) AS tickets
     FROM users u
     LEFT JOIN bookings b ON b.user_id = u.id
     LEFT JOIN schedules s ON s.id = b.schedule_id
       AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL
     WHERE $where
     GROUP BY u.id
     ORDER BY MAX(s.travel_date) DESC, u.full_name, u.id
     LIMIT $perPage OFFSET $offset",
    $types,
    $params
);

$tripsByUser = [];
$ids = array_map(static fn (array $person): int => (int) $person['id'], $people);
if ($ids) {
    $idList = implode(',', $ids);
    $tripRows = qall(
        "SELECT b.id AS booking_id, b.user_id, b.schedule_id, b.payment_ref, b.booking_code, b.seat_label, b.passenger_name, b.booking_status,
                r.origin, r.destination, r.mode, s.travel_date, s.depart_time,
                COALESCE(bus.name, tn.name, pl.airline) AS carrier_name,
                COALESCE(bus.bus_number, tn.train_number, pl.flight_number) AS service_code,
                COALESCE(bus.coach_type, tn.seat_class, pl.cabin, s.cabin_name) AS cabin
         FROM bookings b
         JOIN schedules s ON s.id = b.schedule_id
         JOIN routes r ON r.id = s.route_id
         LEFT JOIN buses bus ON bus.id = s.bus_id
         LEFT JOIN trains tn ON tn.id = s.train_id
         LEFT JOIN planes pl ON pl.id = s.plane_id
         WHERE b.user_id IN ($idList)
           AND COALESCE(s.bus_id, s.train_id, s.plane_id) IS NOT NULL"
    );
    foreach ($tripRows as $trip) {
        $tripsByUser[(int) $trip['user_id']][] = $trip;
    }
}

$pageTitle = 'Passenger';
$active = 'passengers';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="filters ops-find" method="get">
  <label>Search
    <input class="lookup-q" type="search" name="q" value="<?= e($find) ?>" placeholder="Name, phone, NID, seat, city, or transport">
  </label>
  <button class="btn" type="submit">Search</button>
  <span class="muted"><?= number_format($totalRows) ?> <?= $totalRows === 1 ? 'passenger' : 'passengers' ?></span>
</form>
<div class="table-wrap card">
  <table class="ops-table">
    <thead>
      <tr>
        <th>Passenger</th>
        <th>Transport</th>
        <th>Route</th>
        <th>Departure</th>
        <th>Seat</th>
        <th>Ticket</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$people): ?>
        <tr><td colspan="6">No passenger matches this search.</td></tr>
      <?php endif; ?>
      <?php foreach ($people as $person): ?>
        <?php
        $own = $tripsByUser[(int) $person['id']] ?? [];
        $trip = passenger_desk_trip($own, $find, $appToday);
        $seats = $trip ? purchase_mates($own, $trip) : [];
        $states = array_values(array_unique(array_map(static fn (array $seat): string => (string) $seat['booking_status'], $seats)));
        ?>
        <tr>
          <td class="ops-who">
            <a href="passenger.php?id=<?= (int) $person['id'] ?>"><?= e($person['full_name']) ?></a>
            <br><span class="muted"><?= e($person['phone']) ?></span>
          </td>
          <?php if (!$trip): ?>
            <td class="muted" colspan="5">No ticket on file</td>
          <?php else: ?>
            <?php
            $carrier = trim((string) ($trip['carrier_name'] ?? ''));
            $service = trim((string) ($trip['service_code'] ?? ''));
            $detail = trim($service . (($trip['cabin'] ?? '') !== '' ? ' · ' . $trip['cabin'] : ''));
            $others = max(0, (int) $person['tickets'] - count($seats));
            ?>
            <td class="ops-run">
              <span class="ops-kicker"><?= e($trip['ops_kind']) ?></span>
              <?= mode_badge($trip['mode']) ?> <?= e($carrier !== '' ? $carrier : 'Not assigned') ?>
              <?php if ($detail !== ''): ?><br><span class="muted"><?= e($detail) ?></span><?php endif; ?>
            </td>
            <td><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></td>
            <td><?= e(date_label($trip['travel_date'])) ?><br><span class="muted"><?= e(clock_label($trip['depart_time'])) ?></span></td>
            <td class="ops-seats"><?php seat_chips($seats); ?></td>
            <td>
              <a href="../ticket.php?code=<?= urlencode($seats[0]['booking_code']) ?>"><?= e($seats[0]['booking_code']) ?></a>
              <?php if (count($states) === 1): ?><br><?= badge($states[0]) ?><?php endif; ?>
              <?php if ($others > 0): ?><br><span class="muted"><?= $others ?> other <?= $others === 1 ? 'ticket' : 'tickets' ?></span><?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($pages > 1): ?>
  <p class="filters pager">
    <?php if ($page > 1): ?><a href="passengers.php?q=<?= urlencode($find) ?>&page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="passengers.php?q=<?= urlencode($find) ?>&page=<?= $page + 1 ?>">Next</a><?php endif; ?>
  </p>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
