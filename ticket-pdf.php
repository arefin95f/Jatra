<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/qr.php';
require __DIR__ . '/includes/ticketpdf.php';

$code = trim($_GET['code'] ?? '');
$user = current_user();
$booking = ticket_by_code($code);

if (!$booking || !$user || ((int) $booking['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin')) {
    flash('err', 'Ticket not found.');
    redirect($user && $user['role'] === 'admin' ? 'admin/bookings.php' : 'bookings.php');
}

$purchase = purchase_bookings($booking);
$fileCode = count($purchase) > 1 && trim((string) $booking['payment_ref']) !== ''
    ? (string) $booking['payment_ref']
    : $booking['booking_code'];
$fileCode = preg_replace('/[^A-Za-z0-9_-]/', '', $fileCode);
$token = ensure_verify_token($booking);
$pdf = ticket_pdf($booking, qr_matrix(verify_url($token)));
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Jatra-' . $fileCode . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
