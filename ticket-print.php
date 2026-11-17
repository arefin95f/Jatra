<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ticketpdf.php';

$code = trim($_GET['code'] ?? '');
$user = current_user();
$booking = ticket_by_code($code);

if (!$booking || !$user || ((int) $booking['user_id'] !== (int) $user['id'] && $user['role'] !== 'admin')) {
    flash('err', 'Ticket not found.');
    redirect($user && $user['role'] === 'admin' ? 'admin/bookings.php' : 'bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('ticket.php?code=' . urlencode($booking['booking_code']));
}

header('Content-Type: application/json');
echo json_encode(['ok' => ticket_system_print($booking)]);
