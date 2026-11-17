<?php
require __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['schedule'] ?? 0);
if (($_GET['hold'] ?? '') === 'expired') {
    unset($_SESSION['booking_otp']);
    $buyer = current_user();
    $justBooked = null;
    if ($buyer && $id > 0) {
        $justBooked = qone(
            "SELECT booking_code FROM bookings
             WHERE schedule_id = ? AND user_id = ? AND booking_status = 'confirmed'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
             ORDER BY id DESC
             LIMIT 1",
            'ii',
            [$id, (int) $buyer['id']]
        );
    }
    if ($justBooked) {
        if (empty($_SESSION['flash'])) {
            flash('ok', 'Ticket booked and paid.');
        }
        redirect('ticket.php?code=' . urlencode($justBooked['booking_code']));
    }
    flash('err', 'Your 7-minute seat hold expired. Select available seats again to restart checkout.');
}
$trip = qone(trip_sql() . ' WHERE s.id = ?', 'i', [$id]);

$pageTitle = 'Choose a seat';
require __DIR__ . '/includes/header.php';

if (
    !$trip
    || $trip['status'] !== 'scheduled'
    || strtotime($trip['travel_date'] . ' ' . $trip['depart_time']) <= time()
    || trip_on_day_off($trip)
    || ($trip['carrier_status'] ?? '') !== 'active'
) {
    echo '<div class="empty">That trip is not open for booking. <a href="index.php">Search again</a>.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$buyer = current_user();
$ticketMax = tickets_remaining($buyer);
$taken = taken_seats($id);
$held = held_by_others($id);
$mine = my_holds($id);
$holdConflict = hold_conflict_message($id);
$isTrain = $trip['mode'] === 'train';
$cabinLabel = $isTrain
    ? train_cabin_label((string) $trip['cabin'], (string) $trip['depart_time'])
    : (string) $trip['cabin'];
$coaches = $isTrain ? train_coaches((string) $trip['cabin'], train_trip_extras($trip)) : [];
$unavailable = array_values(array_unique(array_merge($taken, $held)));
$coachFull = [];
foreach ($coaches as $code) {
    $coachFull[$code] = coach_is_full_for_trip($trip, $code, $unavailable);
}
$activeCoach = '';
if ($isTrain && $mine) {
    foreach ($coaches as $code) {
        foreach ($mine as $heldSeat) {
            if (str_starts_with($heldSeat, $code . '-')) {
                $activeCoach = $code;
                break 2;
            }
        }
    }
}
if ($activeCoach === '') {
    foreach ($coaches as $code) {
        if (empty($coachFull[$code])) {
            $activeCoach = $code;
            break;
        }
    }
    if ($activeCoach === '' && $coaches) {
        $activeCoach = $coaches[0];
    }
}
$aisleAfter = seat_aisle_after(
    $trip['mode'],
    (int) $trip['total_seats'],
    (string) $trip['cabin'],
    (string) $trip['depart_time']
);
$front = ['bus' => 'Driver', 'train' => 'Engine', 'plane' => 'Cockpit'][$trip['mode']] ?? 'Front';
$mapTotal = bookable_seats($trip);
$left = $mapTotal - count(array_filter(
    $unavailable,
    static function (string $seat) use ($trip): bool {
        return seat_exists_for_trip($trip, $seat);
    }
));
?>
<section class="split">
  <div>
    <p class="eyebrow"><?= e($trip['carrier_name']) ?> · <?= e($trip['service_code']) ?> · <?= e($cabinLabel) ?></p>
    <h1><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></h1>
    <p class="muted"><?= e(date_label($trip['travel_date'])) ?> · <?= e(clock_label($trip['depart_time'])) ?> · <?= e(duration_label((int) $trip['duration_minutes'])) ?></p>
    <form class="seat-form" method="post" action="checkout.php" data-hold="hold.php" data-max="<?= (int) $ticketMax ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="schedule" value="<?= (int) $trip['id'] ?>">

      <?php if ($isTrain && $coaches): ?>
        <div class="coach-select" data-coach-select>
          <div class="coach-select-head">
            <strong>Select Coach</strong>
            <span class="coach-select-status"><span data-coach-label><?= e($activeCoach) ?></span> Selected</span>
          </div>
          <div class="coach-chips" role="tablist" aria-label="Train coaches">
            <?php foreach ($coaches as $code): ?>
              <?php
                $full = !empty($coachFull[$code]);
                $active = $code === $activeCoach;
                $chipClass = 'coach-chip' . ($active ? ' is-active' : '') . ($full ? ' is-full' : '');
              ?>
              <button
                type="button"
                class="<?= e($chipClass) ?>"
                role="tab"
                aria-selected="<?= $active ? 'true' : 'false' ?>"
                aria-label="<?= e($code . ($full ? ' full' : '')) ?>"
                data-coach="<?= e($code) ?>"
                <?= $full ? 'disabled' : '' ?>
              >
                <span class="coach-bn"><?= e($code) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($isTrain && $coaches): ?>
        <?php foreach ($coaches as $code): ?>
          <?php $rows = seat_rows_for_trip($trip, $code); ?>
          <div class="coach train" data-coach-panel="<?= e($code) ?>"<?= $code === $activeCoach ? '' : ' hidden' ?>>
            <div class="driver"><?= e($front) ?> · Coach <?= e($code) ?></div>
            <?php foreach ($rows as $row): ?>
              <div class="seat-row">
                <?php foreach ($row as $index => $seat): ?>
                  <?php if ($index === $aisleAfter): ?><span class="aisle"></span><?php endif; ?>
                  <?php
                    $isTaken = in_array($seat, $taken, true);
                    $isHeld = in_array($seat, $held, true);
                    $isMine = in_array($seat, $mine, true);
                    $parts = split_seat_label($seat);
                    $short = $parts['seat'] !== '' ? $parts['seat'] : $seat;
                  ?>
                  <label class="seat <?= $isTaken ? 'taken' : ($isHeld ? 'held' : '') ?>">
                    <input type="checkbox" name="seats[]" value="<?= e($seat) ?>" <?= $isMine ? 'checked' : '' ?> <?= $isTaken || $isHeld || $holdConflict ? 'disabled' : '' ?>>
                    <span><?= e($short) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <?php $rows = seat_rows_for_trip($trip); ?>
        <div class="coach <?= e($trip['mode']) ?>">
          <div class="driver"><?= e($front) ?></div>
          <?php foreach ($rows as $row): ?>
            <div class="seat-row">
              <?php foreach ($row as $index => $seat): ?>
                <?php if ($index === $aisleAfter): ?><span class="aisle"></span><?php endif; ?>
                <?php
                  $isTaken = in_array($seat, $taken, true);
                  $isHeld = in_array($seat, $held, true);
                  $isMine = in_array($seat, $mine, true);
                ?>
                <label class="seat <?= $isTaken ? 'taken' : ($isHeld ? 'held' : '') ?>">
                  <input type="checkbox" name="seats[]" value="<?= e($seat) ?>" <?= $isMine ? 'checked' : '' ?> <?= $isTaken || $isHeld || $holdConflict ? 'disabled' : '' ?>>
                  <span><?= e($seat) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="legend">
        <span><i class="swatch free"></i> Open</span>
        <span><i class="swatch mine"></i> Selected</span>
        <?php if ($mine): ?><span><i class="swatch mine"></i> Held by you</span><?php endif; ?>
        <span><i class="swatch held"></i> Held</span>
        <span><i class="swatch booked"></i> Booked</span>
        <?php if ($isTrain): ?><span><i class="swatch coach-full"></i> Coach full</span><?php endif; ?>
        <span><?= max(0, $left) ?> open</span>
      </div>
      <?php if ($holdConflict): ?>
        <div class="alert err" role="status"><?= e($holdConflict) ?></div>
      <?php elseif ($ticketMax === 0): ?>
        <div class="alert err">You have booked 6 tickets in the last 24 hours.</div>
      <?php elseif ($left > 0): ?>
        <?php $seatAllowance = $ticketMax === 1 ? '1 seat' : 'up to ' . (int) $ticketMax . ' seats'; ?>
        <div class="seat-guide" aria-label="Booking rules">
          <div class="seat-guide-row">
            <span class="seat-guide-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M4 7.2A1.2 1.2 0 0 1 5.2 6h13.6A1.2 1.2 0 0 1 20 7.2v2a2 2 0 0 0 0 4v3.6a1.2 1.2 0 0 1-1.2 1.2H5.2A1.2 1.2 0 0 1 4 16.8V13.2a2 2 0 0 0 0-4V7.2zM8.2 9.2h7.6v1.6H8.2V9.2zm0 3.4h7.6V14H8.2v-1.4z"/></svg>
            </span>
            <p>You can book <strong><?= e($seatAllowance) ?></strong> now. The limit is <?= (int) ticket_cap() ?> tickets every 24 hours.</p>
          </div>
          <div class="seat-guide-row" id="seat-lock">
            <span class="seat-guide-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 5v4.7l3.3 2-1 1.6L11 12.7V7h2z"/></svg>
            </span>
            <p id="seat-lock-text" aria-live="polite">Choosing a seat starts a 7 minute hold. The same time keeps running while you pay.</p>
            <?php hold_clock($id); ?>
          </div>
        </div>
        <button class="btn btn-gold" type="submit">Continue to payment</button>
      <?php else: ?>
        <div class="alert err">Every seat on this coach is taken.</div>
      <?php endif; ?>
    </form>
  </div>
  <aside class="summary">
    <h2>Trip</h2>
    <p><span><?= e(mode_label($trip['mode'])) ?></span><strong><?= e($trip['carrier_name']) ?></strong></p>
    <p><span>Class</span><strong><?= e($cabinLabel) ?></strong></p>
    <p><span>Fare</span><strong><?= e(money($trip['fare'])) ?></strong></p>
    <p><span>Seat</span><strong id="seat-preview">None yet</strong></p>
  </aside>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
