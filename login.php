<?php
require __DIR__ . '/includes/bootstrap.php';

$afterBooking = !empty($_SESSION['pending_booking']) ? 'checkout.php' : '';
if (current_user()) {
    if ($afterBooking !== '') {
        redirect($afterBooking);
    }
    redirect((current_user()['role'] === 'admin') ? 'admin/index.php' : 'index.php');
}

$error = '';
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = $_POST['password'] ?? '';
    $account = qone('SELECT * FROM users WHERE email = ?', 's', [$email]);
    if (!$account || !password_verify($password, $account['password_hash'])) {
        $error = 'Email or password is incorrect.';
    } elseif ($account['status'] !== 'active') {
        $error = 'This account is blocked. Contact the terminal office.';
    } else {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $account['id'];
        flash('ok', 'Welcome back, ' . $account['full_name'] . '.');
        if ($afterBooking !== '') {
            redirect($afterBooking);
        }
        redirect($account['role'] === 'admin' ? 'admin/index.php' : 'index.php');
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<form class="auth-card login-card" method="post">
  <?= csrf_field() ?>
  <h1>Log in</h1>
  <?php if ($afterBooking !== ''): ?><p class="muted">Log in to finish this booking. Your seats stay held.</p><?php endif; ?>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <label>Email
    <input name="email" required maxlength="160" value="<?= e($email) ?>" autocomplete="username">
  </label>
  <label>Password
    <span class="secret">
      <input type="password" name="password" required autocomplete="current-password">
      <button class="eye" type="button" aria-label="Show password" aria-pressed="false">
        <svg class="eye-open" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        <svg class="eye-shut" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 3l18 18M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      </button>
    </span>
  </label>
  <p class="login-forgot-row"><a href="forgot_password.php">Forgot password?</a></p>
  <button class="btn btn-gold" type="submit">Log in</button>
  <p class="muted">New here? <a href="register.php">Create a passenger account</a>.</p>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
