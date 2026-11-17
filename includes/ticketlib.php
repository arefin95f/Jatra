<?php

function refund_for(int $minutesLeft, string $paymentStatus, float $amount): array
{
    $percent = 0;
    if ($paymentStatus === 'paid' && $minutesLeft >= 48 * 60) {
        $percent = 100;
    } elseif ($paymentStatus === 'paid' && $minutesLeft >= 24 * 60) {
        $percent = 50;
    } elseif ($paymentStatus === 'paid' && $minutesLeft > 20 * 60) {
        $percent = 25;
    } elseif ($paymentStatus === 'paid' && $minutesLeft > 16 * 60) {
        $percent = 15;
    }
    $refund = round($amount * $percent / 100, 2);
    return [
        'percent' => $percent,
        'amount' => $refund,
        'payment' => $percent === 100 ? 'refunded' : $paymentStatus,
    ];
}

function time_left_label(int $minutes): string
{
    $hours = intdiv(max(0, $minutes), 60);
    $mins = max(0, $minutes) % 60;
    $parts = [];
    if ($hours === 1) {
        $parts[] = '1 hour';
    } elseif ($hours > 1) {
        $parts[] = $hours . ' hours';
    }
    if ($mins === 1) {
        $parts[] = '1 minute';
    } elseif ($mins > 1) {
        $parts[] = $mins . ' minutes';
    }
    return $parts ? implode(' ', $parts) : 'less than a minute';
}

function refund_reason(int $minutesLeft, string $paymentStatus): string
{
    $when = 'Leaves in ' . time_left_label($minutesLeft) . '. ';
    if ($paymentStatus !== 'paid') {
        return $when . 'Nothing was paid yet.';
    }
    if ($minutesLeft >= 48 * 60) {
        return $when . '48 hours or more before departure, so this is a full refund.';
    }
    if ($minutesLeft >= 24 * 60) {
        return $when . 'Under 48 hours and at least 24, so this is a 50% refund.';
    }
    if ($minutesLeft > 20 * 60) {
        return $when . 'Under 24 hours and more than 20, so this is a 25% refund.';
    }
    if ($minutesLeft > 16 * 60) {
        return $when . '20 hours or less and more than 16, so this is a 15% refund.';
    }
    return $when . '16 hours or less before departure, so there is no refund.';
}

function refund_note(int $minutesLeft, string $paymentStatus, float $amount): string
{
    $offer = refund_for($minutesLeft, $paymentStatus, $amount);
    $reason = refund_reason($minutesLeft, $paymentStatus);
    if ($paymentStatus !== 'paid' || (int) $offer['percent'] === 0) {
        return $reason;
    }
    return $reason . ' ' . money($offer['amount']) . '.';
}

function refund_notification(array $booking, array $codes, array $seats, float $amount): void
{
    $codes = array_values(array_filter(array_map(static fn ($code): string => trim((string) $code), $codes)));
    $seats = array_values(array_filter(array_map(static fn ($seat): string => trim((string) $seat), $seats)));
    if ($amount <= 0 || !$codes || (int) ($booking['user_id'] ?? 0) <= 0) {
        return;
    }
    require_once __DIR__ . '/fleet_replace.php';
    $many = count($codes) > 1;
    $route = '';
    if (!empty($booking['origin']) && !empty($booking['destination'])) {
        $route = $booking['origin'] . ' to ' . $booking['destination'];
    }
    $seatText = $seats === [] ? '' : ((count($seats) === 1 ? 'seat ' : 'seats ') . list_and($seats));
    $where = $route;
    if ($seatText !== '') {
        $where = $where === '' ? $seatText : $where . ', ' . $seatText;
    }
    $body = ($many ? 'Tickets ' . list_and($codes) . ' were cancelled.' : 'Ticket ' . $codes[0] . ' was cancelled.')
        . ($where !== '' ? ' ' . $where . '.' : '')
        . ' ' . money($amount) . ' has been refunded.';
    create_user_notification(
        (int) $booking['user_id'],
        $many ? 'Refund for your ticket' : 'Refund for ' . $codes[0],
        $body,
        (int) $booking['id']
    );
}

function cancel_ticket(array $booking, bool $notify = true): array
{
    if (!in_array($booking['booking_status'], ['confirmed', 'pending'], true)) {
        return ['ok' => false, 'message' => 'That ticket cannot be cancelled.'];
    }
    $left = qone(
        'SELECT TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMP(?, ?)) AS minutes_left',
        'ss',
        [$booking['travel_date'], $booking['depart_time']]
    );
    $minutes = (int) ($left['minutes_left'] ?? 0);
    if ($minutes <= 0) {
        return ['ok' => false, 'message' => 'This trip has already departed.'];
    }
    $rule = refund_for($minutes, $booking['payment_status'], (float) $booking['amount']);
    qexec(
        "UPDATE bookings SET booking_status = 'cancelled', payment_status = ?, refund_amount = ? WHERE id = ?",
        'sdi',
        [$rule['payment'], $rule['amount'], (int) $booking['id']]
    );
    if ($notify) {
        refund_notification(
            $booking,
            [(string) $booking['booking_code']],
            [(string) ($booking['seat_label'] ?? '')],
            (float) $rule['amount']
        );
    }
    $detail = refund_note($minutes, $booking['payment_status'], (float) $booking['amount']);
    return [
        'ok' => true,
        'message' => 'Ticket ' . $booking['booking_code'] . ' cancelled. ' . $detail . ' The QR code is no longer valid.',
        'rule' => $rule,
    ];
}

function purchase_bookings(array $booking): array
{
    $ref = trim((string) ($booking['payment_ref'] ?? ''));
    if ($ref === '') {
        return [$booking];
    }
    $rows = qall(
        ticket_sql() . 'WHERE b.user_id = ? AND b.payment_ref = ? AND b.schedule_id = ?',
        'isi',
        [(int) $booking['user_id'], $ref, (int) $booking['schedule_id']]
    );
    if (count($rows) < 2) {
        return [$booking];
    }
    usort($rows, function (array $a, array $b): int {
        return strnatcmp($a['seat_label'], $b['seat_label']);
    });
    return $rows;
}

function booking_purchase_key(array $booking): string
{
    $schedule = (int) ($booking['schedule_id'] ?? 0);
    $ref = trim((string) ($booking['payment_ref'] ?? ''));
    if ($ref === '') {
        return 'one:' . (int) ($booking['booking_id'] ?? $booking['id'] ?? 0);
    }
    return $schedule . ':' . $ref;
}

/** Seats bought in one payment, in seat order. */
function purchase_mates(array $trips, array $lead): array
{
    $key = booking_purchase_key($lead);
    $seats = array_values(array_filter(
        $trips,
        static fn (array $trip): bool => booking_purchase_key($trip) === $key
    ));
    if (!$seats) {
        $seats = [$lead];
    }
    usort($seats, static fn (array $a, array $b): int => strnatcmp((string) $a['seat_label'], (string) $b['seat_label']));
    return $seats;
}

function seat_chips(array $seats): void
{
    $states = array_values(array_unique(array_map(
        static fn (array $seat): string => (string) $seat['booking_status'],
        $seats
    )));
    echo '<div class="seat-set">';
    foreach ($seats as $seat) {
        echo '<span class="seat-chip">' . e((string) $seat['seat_label']);
        if (count($states) > 1) {
            echo badge((string) $seat['booking_status']);
        }
        echo '</span>';
    }
    echo '</div>';
    if (count($seats) > 1) {
        echo '<span class="muted">' . count($seats) . ' seats together</span>';
    }
}

function list_and(array $items): string
{
    $items = array_values($items);
    $count = count($items);
    if ($count <= 1) {
        return $items[0] ?? '';
    }
    if ($count === 2) {
        return $items[0] . ' and ' . $items[1];
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' and ' . $last;
}

function cancel_purchase(array $bookings): array
{
    $seats = [];
    $codes = [];
    $refund = 0.0;
    $failed = null;
    $sample = null;
    foreach ($bookings as $booking) {
        if (!in_array($booking['booking_status'], ['confirmed', 'pending'], true)) {
            continue;
        }
        $result = cancel_ticket($booking, false);
        if (!$result['ok']) {
            $failed = $result;
            continue;
        }
        $sample = $booking;
        $codes[] = (string) $booking['booking_code'];
        $seats[] = $booking['seat_label'];
        $refund += (float) $result['rule']['amount'];
    }
    if (!$seats) {
        return $failed ?? ['ok' => false, 'message' => 'That ticket cannot be cancelled.'];
    }
    if ($sample) {
        array_multisort($seats, SORT_NATURAL, $codes);
        refund_notification($sample, $codes, $seats, $refund);
    }
    sort($seats, SORT_NATURAL);
    $money = $refund > 0 ? 'Refund ' . money($refund) . '.' : 'No refund.';
    $codes = count($seats) === 1 ? 'The QR code is no longer valid.' : 'The QR codes are no longer valid.';
    return [
        'ok' => true,
        'message' => 'Seats ' . list_and($seats) . ' cancelled. ' . $money . ' ' . $codes,
    ];
}

function ticket_sql(): string
{
    return "SELECT b.*, s.travel_date, s.depart_time, r.origin, r.destination, r.mode,
                COALESCE(bus.name, tn.name, pl.airline) AS carrier_name,
                COALESCE(bus.bus_number, tn.train_number, pl.flight_number) AS service_code,
                COALESCE(bus.coach_type, tn.seat_class, pl.cabin) AS cabin,
                u.nid_no
         FROM bookings b
         JOIN schedules s ON s.id = b.schedule_id
         JOIN routes r ON r.id = s.route_id
         LEFT JOIN users u ON u.id = b.user_id
         LEFT JOIN buses bus ON bus.id = s.bus_id
         LEFT JOIN trains tn ON tn.id = s.train_id
         LEFT JOIN planes pl ON pl.id = s.plane_id ";
}

function ticket_by_code(string $code): ?array
{
    return qone(ticket_sql() . 'WHERE b.booking_code = ?', 's', [$code]);
}

function ticket_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $row = qone('SELECT booking_code FROM bookings WHERE verify_token = ?', 's', [$token]);
    return $row ? ticket_by_code($row['booking_code']) : null;
}

function ensure_verify_token(array $booking): string
{
    if (!empty($booking['verify_token'])) {
        return $booking['verify_token'];
    }
    do {
        $token = bin2hex(random_bytes(16));
    } while (qone('SELECT id FROM bookings WHERE verify_token = ?', 's', [$token]));
    qexec('UPDATE bookings SET verify_token = ? WHERE id = ? AND verify_token IS NULL', 'si', [$token, (int) $booking['id']]);
    $saved = qone('SELECT verify_token FROM bookings WHERE id = ?', 'i', [(int) $booking['id']]);
    return $saved['verify_token'];
}

function app_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8088';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function verify_url(string $token): string
{
    return app_base() . '/verify.php?t=' . $token;
}

function ticket_verified(array $booking): bool
{
    return ($booking['payment_status'] ?? '') === 'paid'
        && ($booking['booking_status'] ?? '') !== 'cancelled';
}

function purchase_verified(array $bookings): bool
{
    foreach ($bookings as $booking) {
        if (ticket_verified($booking)) {
            return true;
        }
    }
    return false;
}

function nid_label(?string $nid): string
{
    $digits = preg_replace('/\D+/', '', (string) $nid);
    if ($digits === '') {
        return '';
    }
    return trim(chunk_split($digits, 4, ' '));
}

function ticket_stub(array $booking, bool $payment = true): void
{
    $nid = nid_label($booking['nid_no'] ?? '');
    ?>
    <div class="ticket-facts">
      <div class="ticket-who">
        <span class="ticket-kicker">Passenger</span>
        <b><?= e($booking['passenger_name']) ?></b>
        <span class="ticket-kicker">NID</span>
        <span class="ticket-nid"><?= e($nid !== '' ? $nid : '—') ?></span>
      </div>
      <div class="ticket-rule"></div>
      <p class="ticket-code"><?= e($booking['booking_code']) ?></p>
      <strong><?= e(money($booking['amount'])) ?></strong>
      <span><?= e($booking['carrier_name'] ?? '—') ?></span>
      <span class="ticket-svc"><?= e($booking['service_code'] ?? '—') ?></span>
      <div class="ticket-pay">
        <?= badge($booking['booking_status']) ?>
        <?php if ($payment): ?>
          <small><?= e(method_label($booking['payment_method'])) ?> · <?= e($booking['payment_status']) ?></small>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

function purchased_label(?string $createdAt): string
{
    $createdAt = trim((string) $createdAt);
    if ($createdAt === '') {
        return '—';
    }
    $stamp = strtotime($createdAt);
    if ($stamp === false) {
        return '—';
    }
    return date('d M Y, g:i A', $stamp);
}

function ticket_card(array $booking): void
{
    require_once __DIR__ . '/qr.php';
    $token = ensure_verify_token($booking);
    $qr = qr_svg(qr_matrix(verify_url($token)), 120);
    ?>
    <article class="ticket <?= e($booking['mode']) ?>">
      <div class="ticket-main">
        <p class="kicker"><?= e(mode_label($booking['mode'])) ?> ticket</p>
        <div class="ticket-route">
          <div><small>From</small><strong><?= e(strtoupper($booking['origin'])) ?></strong></div>
          <div><small>To</small><strong><?= e(strtoupper($booking['destination'])) ?></strong></div>
        </div>
        <dl class="ticket-meta">
          <div><dt>Date</dt><dd><?= e(date_label($booking['travel_date'])) ?></dd></div>
          <div><dt>Time</dt><dd><?= e(clock_label($booking['depart_time'])) ?></dd></div>
          <?php $seatParts = split_seat_label((string) $booking['seat_label']); ?>
          <?php if ($seatParts['coach'] !== ''): ?>
            <div><dt>Coach</dt><dd><?= e($seatParts['coach']) ?></dd></div>
            <div><dt>Seat</dt><dd><?= e($seatParts['seat']) ?></dd></div>
          <?php else: ?>
            <div><dt>Seat</dt><dd><?= e($booking['seat_label']) ?></dd></div>
          <?php endif; ?>
          <div><dt>Class</dt><dd><?= e(ticket_class_label($booking)) ?></dd></div>
        </dl>
        <dl class="ticket-bought">
          <dt>Purchased</dt>
          <dd><?= e(purchased_label($booking['created_at'] ?? '')) ?></dd>
        </dl>
        <p class="enjoy">Enjoy your journey</p>
      </div>
      <div class="ticket-side">
        <?php ticket_stub($booking); ?>
        <div class="ticket-qr">
          <?= $qr ?>
          <small>Scan to verify</small>
        </div>
      </div>
    </article>
    <?php
}

function ticket_document(array $booking): void
{
    $purchase = purchase_bookings($booking);
    if (count($purchase) < 2) {
        ticket_card($booking);
        return;
    }
    ticket_group_card($purchase);
}

function ticket_group_card(array $bookings): void
{
    require_once __DIR__ . '/qr.php';
    $first = $bookings[0];
    $labels = array_map(fn (array $seat): string => $seat['seat_label'], $bookings);
    $total = array_sum(array_map(fn (array $seat): float => (float) $seat['amount'], $bookings));
    $statuses = array_unique(array_column($bookings, 'booking_status'));
    $status = count($statuses) === 1 ? $statuses[0] : '';
    ?>
    <article class="ticket is-group <?= e($first['mode']) ?>">
      <div class="ticket-main">
        <p class="kicker"><?= e(mode_label($first['mode'])) ?> ticket</p>
        <div class="ticket-route">
          <div><small>From</small><strong><?= e(strtoupper($first['origin'])) ?></strong></div>
          <div><small>To</small><strong><?= e(strtoupper($first['destination'])) ?></strong></div>
        </div>
        <dl class="ticket-meta">
          <div><dt>Date</dt><dd><?= e(date_label($first['travel_date'])) ?></dd></div>
          <div><dt>Time</dt><dd><?= e(clock_label($first['depart_time'])) ?></dd></div>
          <div><dt>Seats</dt><dd><?= e(implode(', ', $labels)) ?></dd></div>
          <div><dt>Class</dt><dd><?= e(ticket_class_label($first)) ?></dd></div>
        </dl>
        <dl class="ticket-bought">
          <dt>Purchased</dt>
          <dd><?= e(purchased_label($first['created_at'] ?? '')) ?></dd>
        </dl>
        <p class="enjoy">Enjoy your journey</p>
      </div>
      <div class="ticket-side">
        <div class="ticket-facts">
          <div class="ticket-who">
            <span class="ticket-kicker">Passenger</span>
            <b><?= e($first['passenger_name']) ?></b>
            <span class="ticket-kicker">NID</span>
            <span class="ticket-nid"><?= e(nid_label($first['nid_no'] ?? '') ?: '—') ?></span>
          </div>
          <div class="ticket-rule"></div>
          <p class="ticket-code"><?= e($first['payment_ref']) ?></p>
          <strong><?= e(money($total)) ?></strong>
          <span><?= e($first['carrier_name'] ?? '—') ?></span>
          <span class="ticket-svc"><?= e($first['service_code'] ?? '—') ?></span>
          <div class="ticket-pay">
            <?php if ($status !== ''): ?><?= badge($status) ?><?php endif; ?>
            <small><?= e(method_label($first['payment_method'])) ?> · <?= e(count($labels)) ?> seats</small>
          </div>
        </div>
        <div class="ticket-qr">
          <?= qr_svg(qr_matrix(verify_url(ensure_verify_token($first))), 120) ?>
          <small>Scan to verify</small>
        </div>
      </div>
    </article>
    <?php
}

function cancel_dialog(): void
{
    ?>
    <div class="cancel-modal" id="cancel-modal" hidden>
      <div class="cancel-modal-card" role="dialog" aria-modal="true" aria-labelledby="cancel-modal-title">
        <h2 id="cancel-modal-title">Cancel this ticket?</h2>
        <p class="cancel-sum" id="cancel-modal-sum"><small>৳</small><strong id="cancel-modal-amount">0</strong></p>
        <p class="cancel-modal-offer" id="cancel-modal-offer"></p>
        <p class="muted">A cancelled QR code stops working, and that seat can be sold again.</p>
        <ol class="policy-steps">
          <li data-band="100"><strong>100%</strong><span>48 hours or more</span></li>
          <li data-band="50"><strong>50%</strong><span>24 hours, under 48</span></li>
          <li data-band="25"><strong>25%</strong><span>More than 20, under 24</span></li>
          <li data-band="15"><strong>15%</strong><span>More than 16, up to 20</span></li>
          <li class="none" data-band="0"><strong>No refund</strong><span>16 hours or less</span></li>
        </ol>
        <div class="cancel-modal-actions">
          <button class="btn btn-ghost" type="button" data-cancel-close>Keep ticket</button>
          <button class="btn btn-danger" type="button" data-cancel-go>Cancel ticket</button>
        </div>
      </div>
    </div>
    <?php
}
