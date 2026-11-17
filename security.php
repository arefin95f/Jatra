<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/notifications.php';

$user = require_login();
if ($user['role'] !== 'passenger') {
    redirect('admin/index.php');
}

$account = qone(
    'SELECT full_name, email, password_hash FROM users WHERE id = ?',
    'i',
    [(int) $user['id']]
);
if (!$account) {
    http_response_code(404);
    exit('Account not found.');
}

$mode = ($_GET['mode'] ?? '') === 'forgot' ? 'forgot' : 'change';
$errors = [];
$resetKey = 'password_reset_otp';
$rateKey = 'password_reset_rate';
$rate = $_SESSION[$rateKey] ?? [];
$sameRate = (int) ($rate['user_id'] ?? 0) === (int) $user['id'];
$now = time();
if ($sameRate && $now - (int) ($rate['window'] ?? 0) >= 600) {
    unset($_SESSION[$rateKey]);
    $rate = [];
    $sameRate = false;
}
$resendAvailableAt = $sameRate
    ? max((int) ($rate['last_sent'] ?? 0) + 120, (int) ($rate['count'] ?? 0) >= 3 ? (int) ($rate['window'] ?? 0) + 600 : 0)
    : 0;
$resendWait = max(0, $resendAvailableAt - $now);
$challenge = $_SESSION[$resetKey] ?? null;
if ($challenge && (int) ($challenge['user_id'] ?? 0) !== (int) $user['id']) {
    unset($_SESSION[$resetKey]);
    $challenge = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';

        if (!password_verify($currentPassword, $account['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($newPassword) < 6) {
            $errors[] = 'Use a new password of at least 6 characters.';
        }
        if (!$errors) {
            qexec(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                'si',
                [password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]
            );
            flash('ok', 'Your password has been changed.');
            redirect('security.php');
        }
    } elseif ($action === 'send_reset_code') {
        $rate = $sameRate
            ? $rate
            : ['user_id' => (int) $user['id'], 'window' => $now, 'count' => 0, 'last_sent' => 0];
        $resendAvailableAt = max(
            (int) $rate['last_sent'] + 120,
            (int) $rate['count'] >= 3 ? (int) $rate['window'] + 600 : 0
        );
        if ($now < $resendAvailableAt) {
            $errors[] = 'Please wait before requesting another password reset code.';
        } else {
            $rate['count'] = (int) $rate['count'] + 1;
            $rate['last_sent'] = $now;
            $_SESSION[$rateKey] = $rate;
            try {
                $otp = (string) random_int(100000, 999999);
                send_password_reset_otp($account['email'], $account['full_name'], $otp);
                $_SESSION[$resetKey] = [
                    'user_id' => (int) $user['id'],
                    'otp_hash' => hash_hmac('sha256', $otp, session_id()),
                    'expires_at' => $now + 300,
                    'attempts' => 0,
                ];
                flash('ok', 'A password reset code was sent to your registered email address.');
                redirect('security.php?mode=forgot');
            } catch (RuntimeException $exception) {
                error_log('Password reset OTP delivery failed: ' . $exception->getMessage());
                $errors[] = 'The reset code could not be sent. Please try again later.';
            }
        }
    } elseif ($action === 'reset_password') {
        $challenge = $_SESSION[$resetKey] ?? null;
        $otp = trim($_POST['otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if (!$challenge || (int) ($challenge['user_id'] ?? 0) !== (int) $user['id'] || (int) $challenge['expires_at'] < $now) {
            unset($_SESSION[$resetKey]);
            $errors[] = 'The reset code has expired. Request a new code.';
        } elseif ((int) $challenge['attempts'] >= 5) {
            unset($_SESSION[$resetKey]);
            $errors[] = 'Too many incorrect codes. Request a new code.';
        } else {
            $submittedHash = hash_hmac('sha256', $otp, session_id());
            if (!preg_match('/^\d{6}$/', $otp) || !hash_equals($challenge['otp_hash'], $submittedHash)) {
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
                [password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]
            );
            unset($_SESSION[$resetKey]);
            flash('ok', 'Your password has been reset.');
            redirect('security.php');
        }
    }
}

$rate = $_SESSION[$rateKey] ?? [];
$sameRate = (int) ($rate['user_id'] ?? 0) === (int) $user['id'];
$resendAvailableAt = $sameRate
    ? max((int) ($rate['last_sent'] ?? 0) + 120, (int) ($rate['count'] ?? 0) >= 3 ? (int) ($rate['window'] ?? 0) + 600 : 0)
    : 0;
$resendWait = max(0, $resendAvailableAt - time());
$challenge = $_SESSION[$resetKey] ?? null;

$pageTitle = 'Security';
require __DIR__ . '/includes/header.php';
?>
<?php if ($mode === 'forgot'): ?>
  <section class="auth-card profile-card">
    <p class="eyebrow">Account recovery</p>
    <h1>Reset password</h1>
    <?php foreach ($errors as $error): ?>
      <div class="alert err"><?= e($error) ?></div>
    <?php endforeach; ?>
    <?php if ($challenge && (int) $challenge['expires_at'] >= time()): ?>
      <p class="muted">Enter the code sent to your registered email, then choose a new password.</p>
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
        <input type="hidden" name="action" value="send_reset_code">
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
        <input type="hidden" name="action" value="send_reset_code">
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
    <a class="btn btn-ghost" href="security.php">Back to security</a>
  </section>
<?php else: ?>
  <form class="auth-card profile-card" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <p class="eyebrow">Account security</p>
    <h1>Change password</h1>
    <?php foreach ($errors as $error): ?>
      <div class="alert err"><?= e($error) ?></div>
    <?php endforeach; ?>
    <label>Current password
      <span class="secret">
        <input type="password" name="current_password" required autocomplete="current-password">
        <button class="eye" type="button" aria-label="Show password" aria-pressed="false">
          <svg class="eye-open" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          <svg class="eye-shut" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 3l18 18M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </button>
      </span>
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
    <div class="security-actions">
      <button class="btn btn-gold" type="submit">Change password</button>
      <a class="btn btn-ghost" href="security.php?mode=forgot">Forgot password</a>
    </div>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
