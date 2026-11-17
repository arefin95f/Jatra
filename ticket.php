<?php
require __DIR__ . '/includes/bootstrap.php';

$code = trim($_GET['code'] ?? '');
$user = current_user();
$booking = ticket_by_code($code);

if (!$booking || !$user || ((int) $booking['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin')) {
    flash('err', 'Ticket not found.');
    redirect($user && $user['role'] === 'admin' ? 'admin/bookings.php' : 'bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] !== 'admin') {
    csrf_check();
    $purchase = purchase_bookings($booking);
    $result = count($purchase) > 1 ? cancel_purchase($purchase) : cancel_ticket($booking);
    flash($result['ok'] ? 'ok' : 'err', $result['message']);
    redirect('ticket.php?code=' . urlencode($booking['booking_code']));
}

$purchase = purchase_bookings($booking);
$openSeats = array_values(array_filter(
    $purchase,
    fn (array $seat): bool => in_array($seat['booking_status'], ['confirmed', 'pending'], true)
));
$labels = array_map(fn (array $seat): string => $seat['seat_label'], $purchase);
$many = count($purchase) > 1;
$left = qone(
    'SELECT TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMP(?, ?)) AS minutes_left',
    'ss',
    [$booking['travel_date'], $booking['depart_time']]
);
$minutesLeft = (int) ($left['minutes_left'] ?? 0);
$canCancel = $user['role'] !== 'admin' && $openSeats && $minutesLeft > 0;
$openFare = array_sum(array_map(fn (array $seat): float => (float) $seat['amount'], $openSeats));
$refundNote = $canCancel
    ? refund_reason($minutesLeft, $openSeats[0]['payment_status'])
    : '';
$refundAmount = $canCancel
    ? number_format((float) refund_for($minutesLeft, $openSeats[0]['payment_status'], $openFare)['amount'], 0)
    : '0';
$allCancelled = $openSeats === [] && array_values(array_unique(array_column($purchase, 'booking_status'))) === ['cancelled'];
if ($allCancelled) {
    $refund = array_sum(array_map(fn (array $seat): float => (float) ($seat['refund_amount'] ?? 0), $purchase));
    $closedNote = $many
        ? ($refund > 0
            ? 'These seats are cancelled. Refund ' . money($refund) . '. The QR codes are not valid for travel.'
            : 'These seats are cancelled. There was no refund. The QR codes are not valid for travel.')
        : ($refund > 0
            ? 'This ticket is already cancelled. Refund ' . money($refund) . '. The QR code is not valid for travel.'
            : 'This ticket is already cancelled. There was no refund. The QR code is not valid for travel.');
} elseif ($minutesLeft <= 0 || in_array('completed', array_column($purchase, 'booking_status'), true)) {
    $closedNote = 'This trip is completed. A ticket cannot be cancelled after departure.';
} else {
    $closedNote = 'This ticket cannot be cancelled.';
}
$pageTitle = $many ? (string) $booking['payment_ref'] : ('Ticket ' . $booking['booking_code']);
require __DIR__ . '/includes/header.php';
ticket_document($booking);
?>
<div class="ticket-desk">
<div class="ticket-actions">
  <a class="btn" href="ticket-pdf.php?code=<?= urlencode($booking['booking_code']) ?>">Download PDF</a>
  <a class="btn btn-ghost" href="<?= $user['role'] === 'admin' ? 'admin/bookings.php' : 'bookings.php' ?>">Back to bookings</a>
  <?php if ($user['role'] !== 'admin'): ?>
    <?php if ($canCancel): ?>
      <button class="btn btn-danger" type="submit" form="cancel-ticket"><?= $many ? 'Cancel seats' : 'Cancel ticket' ?></button>
    <?php else: ?>
      <button class="btn btn-danger" type="button" disabled><?= $many ? 'Cancel seats' : 'Cancel ticket' ?></button>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($user['role'] !== 'admin' && $canCancel): ?>
  <form id="cancel-ticket" class="cancel-card" method="post" data-cancel-offer="<?= e($refundNote) ?>" data-cancel-amount="<?= e($refundAmount) ?>" data-cancel-band="<?= (int) refund_for($minutesLeft, $openSeats[0]['payment_status'], $openFare)['percent'] ?>">
    <?= csrf_field() ?>
    <p><?= e($refundNote) ?> The QR <?= $many ? 'codes' : 'code' ?> will stop working.</p>
  </form>
<?php elseif ($user['role'] !== 'admin'): ?>
  <p class="cancel-card is-closed"><?= e($closedNote) ?></p>
<?php endif; ?>
</div>
<?php if ($user['role'] !== 'admin'): ?><?php cancel_dialog(); ?><?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
