<?php

function notification_env(string $name, ?string $default = null): ?string
{
    if (defined($name)) {
        $defined = constant($name);
        if (is_string($defined) && trim($defined) !== '') {
            return trim($defined);
        }
    }
    $value = getenv($name);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function configured_mailer(): PHPMailer\PHPMailer\PHPMailer
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Email delivery is not installed. Run Composer install in Project-X.');
    }
    require_once $autoload;

    $username = notification_env('GMAIL_SMTP_USERNAME');
    $password = notification_env('GMAIL_SMTP_PASSWORD');
    if (!$username || !$password) {
        throw new RuntimeException('Gmail SMTP is not configured. Set the Gmail username and app password.');
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = notification_env('GMAIL_SMTP_HOST', 'smtp.gmail.com');
    $mailer->SMTPAuth = true;
    $mailer->Username = $username;
    $mailer->Password = $password;
    $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) notification_env('GMAIL_SMTP_PORT', '587');
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom($username, notification_env('GMAIL_FROM_NAME', 'Jatra'));
    return $mailer;
}

function send_email_otp(string $email, string $name, string $otp): void
{
    try {
        $mailer = configured_mailer();
        $mailer->addAddress($email, $name);
        $bangladeshTime = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('h:i:s A');
        $mailer->Subject = 'Your Jatra booking confirmation code - ' . $bangladeshTime;
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mailer->isHTML(true);
        $mailer->Body = '<!doctype html><html><body>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your Jatra booking confirmation code is <strong>' . $otp . '.</strong><br>'
            . 'It expires in 5 minutes. If you did not request this code, you can ignore this email.</p>'
            . '<p>Jatra</p>'
            . '</body></html>';
        $mailer->AltBody = "Hello {$name},\r\n\r\n"
            . "Your Jatra booking confirmation code is {$otp}.\r\n"
            . "It expires in 5 minutes. If you did not request this code, you can ignore this email.\r\n\r\n"
            . "Jatra";
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        throw new RuntimeException('Gmail could not send the confirmation code.', 0, $exception);
    }
}

function send_password_reset_otp(string $email, string $name, string $otp): void
{
    try {
        $mailer = configured_mailer();
        $mailer->addAddress($email, $name);
        $mailer->Subject = 'Your Jatra password reset code';
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mailer->isHTML(true);
        $mailer->Body = '<!doctype html><html><body>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Your Jatra password reset code is <strong>' . $otp . '.</strong><br>'
            . 'It expires in 5 minutes. If you did not request a password reset, you can ignore this email.</p>'
            . '<p>Jatra</p>'
            . '</body></html>';
        $mailer->AltBody = "Hello {$name},\r\n\r\n"
            . "Your Jatra password reset code is {$otp}.\r\n"
            . "It expires in 5 minutes. If you did not request a password reset, you can ignore this email.\r\n\r\n"
            . "Jatra";
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        throw new RuntimeException('Gmail could not send the password reset code.', 0, $exception);
    }
}

function enqueue_ticket_email(int $bookingId): int
{
    qexec(
        "INSERT IGNORE INTO notification_outbox (booking_id, notification_type) VALUES (?, 'ticket_email')",
        'i',
        [$bookingId]
    );
    $job = qone(
        "SELECT id FROM notification_outbox WHERE booking_id = ? AND notification_type = 'ticket_email'",
        'i',
        [$bookingId]
    );
    if (!$job) {
        throw new RuntimeException('The ticket email could not be added to the delivery queue.');
    }
    return (int) $job['id'];
}

function process_ticket_email_job(int $jobId): bool
{
    global $db;

    $db->begin_transaction();
    try {
        $job = qone(
            "SELECT id, booking_id, attempts
             FROM notification_outbox
             WHERE id = ?
               AND ((status = 'pending' AND available_at <= NOW())
                 OR (status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)))
             FOR UPDATE",
            'i',
            [$jobId]
        );
        if (!$job) {
            $db->commit();
            return false;
        }
        $attempts = (int) $job['attempts'] + 1;
        qexec(
            "UPDATE notification_outbox
             SET status = 'processing', attempts = ?, updated_at = NOW()
             WHERE id = ?",
            'ii',
            [$attempts, $jobId]
        );
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollback();
        throw $exception;
    }

    try {
        require_once __DIR__ . '/qr.php';
        require_once __DIR__ . '/ticketpdf.php';
        $row = qone('SELECT booking_code FROM bookings WHERE id = ?', 'i', [(int) $job['booking_id']]);
        if (!$row) {
            throw new RuntimeException('The booking for this ticket email no longer exists.');
        }
        $booking = ticket_by_code($row['booking_code']);
        if (!$booking) {
            throw new RuntimeException('Ticket details could not be loaded for the queued email.');
        }
        $token = ensure_verify_token($booking);
        $pdf = ticket_pdf($booking, qr_matrix(verify_url($token)));
        send_ticket_email($booking, $pdf);
        qexec(
            "UPDATE notification_outbox
             SET status = 'sent', sent_at = NOW(), updated_at = NOW(), last_error = NULL
             WHERE id = ?",
            'i',
            [$jobId]
        );
        return true;
    } catch (Throwable $exception) {
        $delay = min(3600, 30 * (2 ** min($attempts - 1, 7)));
        $status = $attempts >= 8 ? 'failed' : 'pending';
        $error = substr($exception->getMessage(), 0, 1000);
        qexec(
            'UPDATE notification_outbox
             SET status = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 updated_at = NOW(), last_error = ?
             WHERE id = ?',
            'sisi',
            [$status, $delay, $error, $jobId]
        );
        error_log('Ticket email job ' . $jobId . ' failed (attempt ' . $attempts . '): ' . $error);
        return false;
    }
}

function send_ticket_email(array $booking, string $pdf): void
{
    $username = notification_env('GMAIL_SMTP_USERNAME');
    if (!$username) {
        throw new RuntimeException('Gmail SMTP is not configured. Set the Gmail username and app password.');
    }

    $purchase = purchase_bookings($booking);
    $seats = array_map(static fn (array $seat): string => $seat['seat_label'], $purchase);
    $codes = array_map(static fn (array $seat): string => $seat['booking_code'], $purchase);
    $total = array_sum(array_map(static fn (array $seat): float => (float) $seat['amount'], $purchase));
    $reference = trim((string) ($booking['payment_ref'] ?? ''));
    $fileCode = $reference !== '' ? $reference : $booking['booking_code'];
    $fileCode = preg_replace('/[^A-Za-z0-9_-]/', '', $fileCode);

    try {
        $mailer = configured_mailer();
        $mailer->addAddress($booking['passenger_email'], $booking['passenger_name']);
        $mailer->Subject = 'Your Jatra ticket — ' . $booking['origin'] . ' to ' . $booking['destination'];
        $mailer->Body = "Hello {$booking['passenger_name']},\r\n\r\n"
            . "Thank you for booking with Jatra. Your payment is confirmed.\r\n\r\n"
            . 'Journey: ' . $booking['origin'] . ' to ' . $booking['destination'] . "\r\n"
            . 'Date: ' . date_label($booking['travel_date']) . "\r\n"
            . 'Departure: ' . clock_label($booking['depart_time']) . "\r\n"
            . 'Class: ' . ticket_class_label($booking) . "\r\n"
            . 'Coach / seat(s): ' . implode(', ', $seats) . "\r\n"
            . 'Booking code(s): ' . implode(', ', $codes) . "\r\n"
            . 'Total paid: ' . money($total) . "\r\n\r\n"
            . "Your ticket PDF is attached. Please keep it available during your journey and present its QR code when requested.\r\n\r\n"
            . "Have a safe trip,\nJatra";
        $mailer->addStringAttachment($pdf, 'Jatra-' . $fileCode . '.pdf', 'base64', 'application/pdf');
        $mailer->send();
    } catch (PHPMailer\PHPMailer\Exception $exception) {
        throw new RuntimeException('Gmail could not send the ticket email.', 0, $exception);
    }
}
