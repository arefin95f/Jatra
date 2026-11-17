<?php
require __DIR__ . '/includes/bootstrap.php';

$token = trim($_GET['t'] ?? '');
$booking = ticket_by_token($token);
$purchase = $booking ? purchase_bookings($booking) : [];
$verified = $purchase && purchase_verified($purchase);
$awaitingPayment = false;
if ($purchase && !$verified) {
    foreach ($purchase as $seat) {
        if ($seat['booking_status'] !== 'cancelled' && ($seat['payment_status'] ?? '') !== 'paid') {
            $awaitingPayment = true;
            break;
        }
    }
}
$pageTitle = $verified ? 'Verified online ticket' : 'Ticket check';
require __DIR__ . '/includes/header.php';
?>
<?php if (!$booking): ?>
  <div class="verify-banner bad">This QR code is not a Jatra ticket.</div>
<?php elseif ($verified): ?>
  <div class="verify-banner ok">Verified online ticket</div>
<?php elseif ($awaitingPayment): ?>
  <div class="verify-banner bad">Payment is not confirmed. This ticket is not valid for travel.</div>
<?php else: ?>
  <div class="verify-banner bad">This ticket was cancelled. It is not valid for travel.</div>
<?php endif; ?>
<?php if ($booking): ?>
  <?php ticket_document($booking); ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
