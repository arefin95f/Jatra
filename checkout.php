<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/notifications.php';

$user = current_user();
$pending = $_SESSION['pending_booking'] ?? null;
$draft = $_SESSION['checkout_draft'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $scheduleId = (int) ($_POST['schedule'] ?? 0);
    $seats = normalize_seats($_POST['seats'] ?? ($_POST['seat'] ?? []));
} elseif ($user && is_array($draft)) {
    $scheduleId = (int) ($draft['schedule'] ?? 0);
    $seats = normalize_seats($draft['seats'] ?? []);
} elseif ($user && is_array($pending)) {
    $scheduleId = (int) ($pending['schedule'] ?? 0);
    $seats = normalize_seats($pending['seats'] ?? ($pending['seat'] ?? []));
} else {
    redirect('index.php');
}

$errors = [];

$trip = qone(trip_sql() . " WHERE s.id = ? AND s.status = 'scheduled'", 'i', [$scheduleId]);

if (
    !$trip
    || strtotime($trip['travel_date'] . ' ' . $trip['depart_time']) <= time()
    || trip_on_day_off($trip)
    || ($trip['carrier_status'] ?? '') !== 'active'
) {
    unset($_SESSION['pending_booking']);
    flash('err', 'That trip is no longer open.');
    redirect('index.php');
}
if (count($seats) < 1 || count($seats) > ticket_cap()) {
    unset($_SESSION['pending_booking']);
    flash('err', 'Choose 1 to 6 seats.');
    redirect('seats.php?schedule=' . $scheduleId);
}
foreach ($seats as $seat) {
    if (!seat_exists_for_trip($trip, $seat)) {
        unset($_SESSION['pending_booking']);
        flash('err', 'Choose a seat on this coach.');
        redirect('seats.php?schedule=' . $scheduleId);
    }
}
$allowance = $user ? tickets_remaining($user) : ticket_cap();
if (count($seats) > $allowance) {
    unset($_SESSION['pending_booking']);
    flash('err', $allowance === 0
        ? 'You have booked 6 tickets in the last 24 hours.'
        : 'You can book ' . $allowance . ' more ' . ($allowance === 1 ? 'ticket' : 'tickets') . ' in the next 24 hours.');
    redirect('seats.php?schedule=' . $scheduleId);
}
$holdConflict = hold_conflict_message($scheduleId);
if ($holdConflict !== null) {
    unset($_SESSION['pending_booking']);
    flash('err', $holdConflict);
    redirect('seats.php?schedule=' . $scheduleId);
}
foreach ($seats as $seat) {
    if (!claim_seat($scheduleId, $seat, $allowance)) {
        unset($_SESSION['pending_booking']);
        flash('err', 'That seat is already selected by another passenger.');
        redirect('seats.php?schedule=' . $scheduleId);
    }
}
if (!$user) {
    $_SESSION['pending_booking'] = ['schedule' => $scheduleId, 'seats' => $seats];
    flash('err', 'Log in to book these seats.');
    redirect('login.php');
}

$name = trim($_POST['passenger_name'] ?? ($draft['name'] ?? ($user['full_name'] ?? '')));
$phone = trim($_POST['passenger_phone'] ?? ($draft['phone'] ?? ($user['phone'] ?? '')));
$email = trim($_POST['passenger_email'] ?? ($draft['email'] ?? ($user['email'] ?? '')));
$method = $_POST['payment_method'] ?? ($draft['method'] ?? '');
$action = $_POST['action'] ?? '';
$otpKey = 'booking_otp';
$checkoutDraft = [
    'schedule' => $scheduleId,
    'seats' => $seats,
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'method' => $method,
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['checkout_draft'] = $checkoutDraft;
}
$binding = hash('sha256', json_encode([
    $scheduleId,
    $seats,
    $name,
    $phone,
    $email,
    $method,
], JSON_THROW_ON_ERROR));

if (isset($_SESSION[$otpKey]) && !hash_equals($_SESSION[$otpKey]['binding'], $binding)) {
    unset($_SESSION[$otpKey]);
}
$otpRate = $_SESSION['otp_send_rate'] ?? [];
$sameOtpRequest = isset($otpRate['binding']) && hash_equals($otpRate['binding'], $binding);
$resendAvailableAt = 0;
if ($sameOtpRequest) {
    $resendAvailableAt = (int) $otpRate['last_sent'] + 120;
    if ((int) $otpRate['count'] >= 3) {
        $resendAvailableAt = max($resendAvailableAt, (int) $otpRate['window'] + 600);
    }
}

if (in_array($action, ['send_otp', 'verify_otp', 'confirm'], true)) {
    if ($name === '' || strlen($name) > 120) {
        $errors[] = 'Enter the passenger name.';
    }
    if (!preg_match('/^[0-9+\s-]{10,20}$/', $phone)) {
        $errors[] = 'Enter a phone number of at least 10 digits.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!in_array($method, ['mobile_money', 'card'], true)) {
        $errors[] = 'Choose a payment method.';
    }

    if (!$errors && $action === 'send_otp') {
        $now = time();
        $rate = $_SESSION['otp_send_rate'] ?? ['window' => $now, 'count' => 0, 'last_sent' => 0];
        $sameRequest = isset($rate['binding']) && hash_equals($rate['binding'], $binding);
        if ($now - (int) $rate['window'] >= 600) {
            $rate = ['window' => $now, 'count' => 0, 'last_sent' => 0];
            $sameRequest = false;
        }
        if ((int) $rate['count'] >= 3) {
            $wait = max(0, (int) $rate['window'] + 600 - $now);
            $errors[] = 'You have requested the maximum number of codes. You can request another in '
                . intdiv($wait, 60) . ':' . str_pad((string) ($wait % 60), 2, '0', STR_PAD_LEFT) . '.';
        } elseif ($sameRequest && $now < (int) $rate['last_sent'] + 120) {
            $wait = (int) $rate['last_sent'] + 120 - $now;
            $errors[] = 'You can request another code in ' . intdiv($wait, 60) . ':' . str_pad((string) ($wait % 60), 2, '0', STR_PAD_LEFT) . '.';
        } else {
            $otp = (string) random_int(100000, 999999);
            unset($_SESSION[$otpKey]);
            try {
                send_email_otp($email, $name, $otp);
                $_SESSION[$otpKey] = [
                    'binding' => $binding,
                    'otp_hash' => hash_hmac('sha256', $otp, session_id()),
                    'expires_at' => $now + 300,
                    'attempts' => 0,
                    'verified' => false,
                ];
                $rate['count'] = (int) $rate['count'] + 1;
                $rate['last_sent'] = $now;
                $rate['binding'] = $binding;
                $_SESSION['otp_send_rate'] = $rate;
                flash('ok', 'A confirmation code was sent to your email address.');
                redirect('checkout.php');
            } catch (RuntimeException $exception) {
                error_log('Email OTP delivery failed: ' . $exception->getMessage());
                $errors[] = $exception->getMessage();
            }
        }
    } elseif (!$errors && $action === 'verify_otp') {
        $challenge = $_SESSION[$otpKey] ?? null;
        $submittedOtp = trim($_POST['otp'] ?? '');
        if (!$challenge || (int) $challenge['expires_at'] < time()) {
            $errors[] = 'The code expired. Request a new email code.';
        } elseif ((int) $challenge['attempts'] >= 5) {
            unset($_SESSION[$otpKey]);
            $errors[] = 'Too many incorrect codes. Request a new email code.';
        } else {
            $_SESSION[$otpKey]['attempts']++;
            $submittedHash = hash_hmac('sha256', $submittedOtp, session_id());
            if (!preg_match('/^\d{6}$/', $submittedOtp) || !hash_equals($challenge['otp_hash'], $submittedHash)) {
                if ($_SESSION[$otpKey]['attempts'] >= 5) {
                    unset($_SESSION[$otpKey]);
                    $errors[] = 'Too many incorrect codes. Request a new email code.';
                } else {
                    $errors[] = 'That code is incorrect. Check your email and try again.';
                }
            } else {
                $_SESSION[$otpKey]['verified'] = true;
                flash('ok', 'Email address verified. Complete your booking to confirm payment.');
                redirect('checkout.php');
            }
        }
    } elseif (!$errors && $action === 'confirm') {
        $challenge = $_SESSION[$otpKey] ?? null;
        if (!$challenge
            || empty($challenge['verified'])
            || (int) $challenge['expires_at'] < time()
            || !hash_equals($challenge['binding'], $binding)) {
            unset($_SESSION[$otpKey]);
            $errors[] = 'Verify your email address before completing payment.';
        }
    }

    if (!$errors && $action === 'confirm') {
        $tranId = '';
        $seatFare = round((float) $trip['fare'], 2);
        $total = round($seatFare * count($seats), 2);
        if ($total <= 0) {
            $errors[] = 'This trip has no fare to charge.';
        }
    }

    if (!$errors && $action === 'confirm') {
        $db->begin_transaction();
        try {
            qone('SELECT id FROM users WHERE id = ? FOR UPDATE', 'i', [(int) $user['id']]);
            $left = ticket_cap() - tickets_bought_recently((int) $user['id']);
            if (count($seats) > $left) {
                $db->rollback();
                flash('err', $left <= 0
                    ? 'You have booked 6 tickets in the last 24 hours.'
                    : 'You can book ' . $left . ' more ' . ($left === 1 ? 'ticket' : 'tickets') . ' in the next 24 hours.');
                redirect('seats.php?schedule=' . $scheduleId);
            }
            foreach ($seats as $seat) {
                $taken = qone(
                    "SELECT id FROM bookings WHERE schedule_id = ? AND seat_label = ? AND booking_status <> 'cancelled' FOR UPDATE",
                    'is',
                    [$scheduleId, $seat]
                );
                $hold = qone(
                    'SELECT id FROM seat_holds WHERE schedule_id = ? AND seat_label = ? AND hold_token = ? AND expires_at > NOW() FOR UPDATE',
                    'iss',
                    [$scheduleId, $seat, hold_token()]
                );
                if ($taken) {
                    $ownPayment = qone(
                        "SELECT id FROM bookings
                         WHERE id = ? AND user_id = ? AND payment_status = 'pending'
                           AND booking_status = 'pending' AND payment_ref LIKE 'JTRA%'",
                        'ii',
                        [(int) $taken['id'], (int) $user['id']]
                    );
                    $db->rollback();
                    if ($ownPayment) {
                        flash('err', 'These seats already have a payment in progress. Finish it from My tickets.');
                        redirect('bookings.php');
                    }
                    flash('err', 'A seat was just booked. Pick again.');
                    redirect('seats.php?schedule=' . $scheduleId);
                }
                if (!$hold) {
                    $db->rollback();
                    flash('err', 'A seat was just booked. Pick again.');
                    redirect('seats.php?schedule=' . $scheduleId);
                }
                if ($tranId === '') {
                    do {
                        $tranId = 'JTRA' . strtoupper(bin2hex(random_bytes(8)));
                    } while (qone('SELECT id FROM bookings WHERE payment_ref = ?', 's', [$tranId]));
                }
                do {
                    $code = 'BK' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                } while (qone('SELECT id FROM bookings WHERE booking_code = ?', 's', [$code]));
                do {
                    $verifyToken = bin2hex(random_bytes(16));
                } while (qone('SELECT id FROM bookings WHERE verify_token = ?', 's', [$verifyToken]));
                qexec(
                    'INSERT INTO bookings
                      (booking_code, verify_token, user_id, schedule_id, passenger_name, passenger_phone, passenger_email,
                       seat_label, amount, payment_method, payment_status, booking_status, payment_ref)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'ssiissssdssss',
                    [
                        $code,
                        $verifyToken,
                        (int) $user['id'],
                        $scheduleId,
                        $name,
                        $phone,
                        $email,
                        $seat,
                        $seatFare,
                        $method,
                        'pending',
                        'pending',
                        $tranId,
                    ]
                );
            }
            unset($_SESSION['pending_booking']);
            $db->commit();
        } catch (mysqli_sql_exception $ex) {
            $db->rollback();
            $code = (int) $ex->getCode();
            if ($code === 1062 || $code === 1213) {
                $errors[] = 'That seat was just booked. Pick another one.';
            } else {
                throw $ex;
            }
        }
        if (!$errors) {
            unset($_SESSION[$otpKey], $_SESSION['checkout_draft']);
            $_SESSION['sim_pay'] = [
                'tran_id' => $tranId,
                'amount' => $total,
                'method' => $method === 'card' ? 'card' : 'mobile_money',
            ];
            redirect('pay-practice.php');
        }
    }
}

$pageTitle = 'Checkout';
require __DIR__ . '/includes/header.php';
?>
<section class="split checkout">
  <form class="panel checkout-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="schedule" value="<?= (int) $scheduleId ?>">
    <?php foreach ($seats as $seat): ?>
      <input type="hidden" name="seats[]" value="<?= e($seat) ?>">
    <?php endforeach; ?>
    <h1>Passenger and payment</h1>
    <?php hold_clock($scheduleId); ?>
    <?php foreach ($errors as $error): ?>
      <div class="alert err"><?= e($error) ?></div>
    <?php endforeach; ?>
    <label>Passenger name
      <input name="passenger_name" required maxlength="120" autocomplete="name" value="<?= e($name) ?>">
    </label>
    <label>Phone
      <input name="passenger_phone" required maxlength="20" inputmode="tel" autocomplete="tel" value="<?= e($phone) ?>">
    </label>
    <label>Email
      <input type="email" name="passenger_email" required maxlength="160" autocomplete="email" value="<?= e($email) ?>">
    </label>
    <fieldset class="pay-methods">
      <legend>Payment</legend>
      <label class="pay-choice">
        <input type="radio" name="payment_method" value="mobile_money" <?= $method === 'mobile_money' || $method === '' ? 'checked' : '' ?>>
        <span><strong>Mobile Money</strong><small>bKash, Nagad, or Rocket.</small></span>
      </label>
      <label class="pay-choice">
        <input type="radio" name="payment_method" value="card" <?= $method === 'card' ? 'checked' : '' ?>>
        <span><strong>Card</strong><small>Visa, Mastercard, or DBBL NEXUS.</small></span>
      </label>
    </fieldset>
    <?php
      $otpChallenge = $_SESSION[$otpKey] ?? null;
      $otpReady = $otpChallenge
          && !empty($otpChallenge['verified'])
          && (int) $otpChallenge['expires_at'] >= time()
          && hash_equals($otpChallenge['binding'], $binding);
      $otpActive = $otpChallenge
          && empty($otpChallenge['verified'])
          && (int) $otpChallenge['expires_at'] >= time()
          && hash_equals($otpChallenge['binding'], $binding);
      $resendWait = max(0, $resendAvailableAt - time());
    ?>
    <?php if (!$otpReady): ?>
      <?php if ($otpActive): ?>
        <label>Email confirmation code
          <input name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
        </label>
        <div class="otp-actions">
          <button class="btn btn-gold" type="submit" name="action" value="verify_otp">Verify email code</button>
          <?php if ($sameOtpRequest && $resendWait > 0): ?>
            <button class="btn btn-ghost" type="submit" name="action" value="send_otp" data-otp-resend disabled>Resend code</button>
          <?php else: ?>
            <button class="btn btn-ghost" type="submit" name="action" value="send_otp">Resend code</button>
          <?php endif; ?>
        </div>
      <?php elseif ($otpChallenge && hash_equals($otpChallenge['binding'], $binding)): ?>
        <p class="muted">Your verification code has expired. Send a new code to restart verification.</p>
        <?php if ($sameOtpRequest && $resendWait > 0): ?>
          <button class="btn btn-ghost otp-resend-alone" type="submit" name="action" value="send_otp" data-otp-resend disabled>Send new code</button>
        <?php else: ?>
          <button class="btn btn-ghost otp-resend-alone" type="submit" name="action" value="send_otp">Send new code</button>
        <?php endif; ?>
      <?php else: ?>
        <?php if ($sameOtpRequest && $resendWait > 0): ?>
          <button class="btn btn-gold" type="submit" name="action" value="send_otp" data-otp-resend disabled>Send code</button>
        <?php else: ?>
          <button class="btn btn-gold" type="submit" name="action" value="send_otp">Send code</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($sameOtpRequest && $resendWait > 0): ?>
        <p class="muted otp-countdown" role="status" aria-live="polite">
          <?= $otpActive ? 'Request another code in' : 'You can send a code in' ?>
          <span data-otp-countdown data-resend-at="<?= $resendAvailableAt ?>"><?= sprintf('%02d:%02d', intdiv($resendWait, 60), $resendWait % 60) ?></span>.
        </p>
      <?php elseif ($sameOtpRequest && (int) ($otpRate['count'] ?? 0) >= 3): ?>
        <p class="muted otp-countdown">You’ve reached the maximum of three codes. Try again in a new 10-minute window.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Email address verified.</p>
      <button class="btn btn-gold" type="submit" name="action" value="confirm">Continue to payment</button>
    <?php endif; ?>
  </form>
  <aside class="summary">
    <p class="kicker"><?= e(mode_label($trip['mode'])) ?></p>
    <h2><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></h2>
    <p class="summary-when"><?= e(date_label($trip['travel_date'])) ?>, <?= e(clock_label($trip['depart_time'])) ?></p>
    <p><span>Vehicle</span><strong><?= e($trip['carrier_name']) ?> · <?= e($trip['service_code']) ?> · <?= e($trip['cabin']) ?></strong></p>
    <p><span>Seats</span><strong><?= e(implode(', ', $seats)) ?></strong></p>
    <p><span>Tickets</span><strong><?= count($seats) ?></strong></p>
    <p class="fare-line"><span>Fare</span><span class="fare-figure"><small>৳</small><strong><?= e(number_format((float) $trip['fare'] * count($seats), 0)) ?></strong></span></p>
    <?php if (count($seats) > 1): ?><p class="muted"><?= e(money($trip['fare'])) ?> for each seat</p><?php endif; ?>
  </aside>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
