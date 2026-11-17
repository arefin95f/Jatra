<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/notifications.php';

if (current_user()) {
    redirect('security.php');
}

$errors = [];
$resetKey = 'forgot_password_otp';
$rateKey = 'forgot_password_rate';
$now = time();
$rate = $_SESSION[$rateKey] ?? ['window' => $now, 'count' => 0, 'last_sent' => 0];
if ($now - (int) $rate['window'] >= 600) {
    $rate = ['window' => $now, 'count' => 0, 'last_sent' => 0];
}
$resendAvailableAt = max(
    (int) $rate['last_sent'] + 120,
    (int) $rate['count'] >= 3 ? (int) $rate['window'] + 600 : 0
);
$resendWait = max(0, $resendAvailableAt - $now);
$challenge = $_SESSION[$resetKey] ?? null;
$email = trim($_SESSION['forgot_password_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'send_code') {
        $email = trim($_POST['email'] ?? $email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!$errors && $now < $resendAvailableAt) {
            $errors[] = 'Please wait before requesting another reset code.';
        }
        if (!$errors) {
            $rate['count'] = (int) $rate['count'] + 1;
            $rate['last_sent'] = $now;
            $_SESSION[$rateKey] = $rate;
            $_SESSION['forgot_password_email'] = $email;

            $account = qone('SELECT id, full_name, email FROM users WHERE email = ? AND role = ?', 'ss', [$email, 'passenger']);
            $otp = (string) random_int(100000, 999999);
            $_SESSION[$resetKey] = [
                'user_id' => (int) ($account['id'] ?? 0),
                'otp_hash' => hash_hmac('sha256', $otp, session_id()),
                'expires_at' => $now + 300,
                'attempts' => 0,
            ];
            if ($account) {
                try {
                    send_password_reset_otp($account['email'], $account['full_name'], $otp);
                } catch (RuntimeException $exception) {
                    unset($_SESSION[$resetKey]);
                    error_log('Password reset OTP delivery failed: ' . $exception->getMessage());
                }
            }
            flash('ok', 'If an account matches that email address, a password reset code has been sent.');
            redirect('forgot_password.php?code=1');
        }
    } elseif ($action === 'reset_password') {
        $challenge = $_SESSION[$resetKey] ?? null;
        $otp = trim($_POST['otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if (!$challenge || (int) $challenge['expires_at'] < $now) {
            unset($_SESSION[$resetKey]);
            $errors[] = 'The reset code has expired. Request a new code.';
        } elseif ((int) $challenge['attempts'] >= 5) {
            unset($_SESSION[$resetKey]);
            $errors[] = 'Too many incorrect codes. Request a new code.';
        } else {
            $submittedHash = hash_hmac('sha256', $otp, session_id());
            if (!preg_match('/^\d{6}$/', $otp) || !hash_equals($challenge['otp_hash'], $submittedHash) || (int) $challenge['user_id'] < 1) {
                $_SESSION[$resetKey]['attempts']++;
                if ($_SESSION[$resetKey]['attempts'] >= 5) {
                    unset($_SESSION[$resetKey]);
                    $errors[] = 'Too many incorrect codes. Request a new code.';
                } else {
                    $errors[] = 'That reset code is incorrect. Check your email and try again.';
                }
            }
        }
        if (!$errors && strlen($newPassword) < 6) {
            $errors[] = 'Use a new password of at least 6 characters.';
        }
        if (!$errors) {
            qexec(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                'si',
                [password_hash($newPassword, PASSWORD_DEFAULT), (int) $challenge['user_id']]
            );
            unset($_SESSION[$resetKey], $_SESSION['forgot_password_email']);
            flash('ok', 'Your password has been reset. You can now log in.');
            redirect('login.php');
        }
    }
}

$rate = $_SESSION[$rateKey] ?? $rate;
if ($now - (int) ($rate['window'] ?? $now) >= 600) {
    $rate = ['window' => $now, 'count' => 0, 'last_sent' => 0];
    unset($_SESSION[$resetKey]);
}
$resendAvailableAt = max(
    (int) ($rate['last_sent'] ?? 0) + 120,
    (int) ($rate['count'] ?? 0) >= 3 ? (int) ($rate['window'] ?? 0) + 600 : 0
);
$resendWait = max(0, $resendAvailableAt - time());
$challenge = $_SESSION[$resetKey] ?? null;
$email = trim($_SESSION['forgot_password_email'] ?? $email);

$pageTitle = 'Forgot password';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-card profile-card">
  <p class="eyebrow">Account recovery</p>
  <h1>Forgot password</h1>
  <?php foreach ($errors as $error): ?>
    <div class="alert err"><?= e($error) ?></div>
  <?php endforeach; ?>
  <?php if (isset($_GET['code']) && $_GET['code'] === '1'): ?>
    <p class="muted">If an account matches that email address, a reset code has been sent.</p>
  <?php endif; ?>
  <?php if ($challenge && (int) $challenge['expires_at'] >= time()): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <label>Email reset code
        <input name="otp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
      </label>
      <label>New password
        <span class="secret">
          <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
          <button class="eye" type="button" aria-label="Show password" aria-pressed="false">
            <svg class="eye-open" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            <svg class="eye-shut" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 3l18 18M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          </button>
        </span>
      </label>
      <button class="btn btn-gold" type="submit">Reset password</button>
    </form>
    <form class="security-actions" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_code">
      <input type="hidden" name="email" value="<?= e($email) ?>">
      <?php if ($resendWait > 0): ?>
        <button class="btn btn-ghost" type="submit" data-otp-resend disabled>Resend code</button>
        <p class="muted otp-countdown" role="status" aria-live="polite">
          Request another code in <span data-otp-countdown data-resend-at="<?= $resendAvailableAt ?>"><?= sprintf('%02d:%02d', intdiv($resendWait, 60), $resendWait % 60) ?></span>.
        </p>
      <?php else: ?>
        <button class="btn btn-ghost" type="submit">Resend code</button>
      <?php endif; ?>
    </form>
  <?php else: ?>
    <?php if ($challenge): ?><p class="muted">Your reset code has expired. Request a new code to continue.</p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_code">
      <label>Email
        <input type="email" name="email" required maxlength="160" value="<?= e($email) ?>" autocomplete="email">
      </label>
      <?php if ($resendWait > 0): ?>
        <button class="btn btn-gold" type="submit" data-otp-resend disabled>Send reset code</button>
        <p class="muted otp-countdown" role="status" aria-live="polite">
          You can request a new code in <span data-otp-countdown data-resend-at="<?= $resendAvailableAt ?>"><?= sprintf('%02d:%02d', intdiv($resendWait, 60), $resendWait % 60) ?></span>.
        </p>
      <?php else: ?>
        <button class="btn btn-gold" type="submit">Send reset code</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  <a class="btn btn-ghost" href="login.php">Back to login</a>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
