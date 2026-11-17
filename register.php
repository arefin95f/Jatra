<?php
require __DIR__ . '/includes/bootstrap.php';

$afterBooking = !empty($_SESSION['pending_booking']) ? 'checkout.php' : '';
if (current_user()) {
    redirect($afterBooking !== '' ? $afterBooking : 'bookings.php');
}

$errors = [];
$name = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? '') ?? '');
$email = trim($_POST['email'] ?? '');
$phone = preg_replace('/\D+/', '', trim($_POST['phone'] ?? ''));
if (str_starts_with($phone, '880') && strlen($phone) > 10) {
    $phone = substr($phone, 3);
} elseif (str_starts_with($phone, '0') && strlen($phone) === 11) {
    $phone = substr($phone, 1);
}
$nid = preg_replace('/\D+/', '', trim($_POST['nid_no'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = $_POST['password'] ?? '';
    if (!preg_match('/^\p{L}+(?: \p{L}+)*$/u', $name) || strlen($name) > 120) {
        $errors[] = 'Enter a name using letters only.';
    }
    if (!preg_match('/^\d{10}$/', $nid)) {
        $errors[] = 'Enter a 10-digit NID number.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        $errors[] = 'Enter the 10 digits after +880.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Use a password of at least 6 characters.';
    }
    if (!$errors) {
        $taken = qall(
            'SELECT email, phone, nid_no FROM users WHERE email = ? OR nid_no = ? OR phone = ?',
            'sss',
            [$email, $nid, '+880' . $phone]
        );
        foreach ($taken as $row) {
            if (strcasecmp($row['email'], $email) === 0) {
                $errors[] = 'That email is already registered.';
            }
            if ($row['nid_no'] === $nid) {
                $errors[] = 'That NID number is already registered.';
            }
            if ($row['phone'] === '+880' . $phone) {
                $errors[] = 'That phone number is already registered.';
            }
        }
        $errors = array_values(array_unique($errors));
    }
    if (!$errors) {
        try {
            $id = qexec(
                "INSERT INTO users (full_name, email, phone, nid_no, password_hash, role) VALUES (?, ?, ?, ?, ?, 'passenger')",
                'sssss',
                [$name, $email, '+880' . $phone, $nid, password_hash($password, PASSWORD_DEFAULT)]
            );
            session_regenerate_id(true);
            $_SESSION['uid'] = $id;
            flash('ok', $afterBooking !== '' ? 'Account created. Finish the booking.' : 'Account created. You can book a seat now.');
            redirect($afterBooking !== '' ? $afterBooking : 'index.php');
        } catch (mysqli_sql_exception $ex) {
            if ((int) $ex->getCode() === 1062) {
                $errors[] = 'An account already uses that email, phone, or NID.';
            } else {
                throw $ex;
            }
        }
    }
}

$pageTitle = 'Create account';
require __DIR__ . '/includes/header.php';
?>
<form class="auth-card" method="post">
  <?= csrf_field() ?>
  <h1>Create account</h1>
  <?php if ($afterBooking !== ''): ?><p class="muted">Create an account to finish this booking. Your seats stay held.</p><?php endif; ?>
  <?php foreach ($errors as $error): ?>
    <div class="alert err"><?= e($error) ?></div>
  <?php endforeach; ?>
  <label>Full name
    <input name="full_name" required maxlength="120" value="<?= e($name) ?>" autocomplete="name">
  </label>
  <label>NID number
    <input name="nid_no" required inputmode="numeric" maxlength="10" pattern="\d{10}" value="<?= e($nid) ?>" placeholder="10-digit NID">
  </label>
  <label>Email
    <input type="email" name="email" required maxlength="160" value="<?= e($email) ?>">
  </label>
  <label>Phone
    <span class="phone-field">
      <span class="phone-code">+880</span>
      <input name="phone" required inputmode="numeric" maxlength="10" pattern="\d{10}" value="<?= e($phone) ?>" placeholder="1XXXXXXXXX" autocomplete="tel-national">
    </span>
  </label>
  <label>Password
    <span class="secret">
      <input type="password" name="password" required minlength="6" autocomplete="new-password">
      <button class="eye" type="button" aria-label="Show password" aria-pressed="false">
        <svg class="eye-open" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        <svg class="eye-shut" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" hidden><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M3 3l18 18M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      </button>
    </span>
  </label>
  <button class="btn btn-gold" type="submit">Register</button>
  <p class="muted">Already registered? <a href="login.php">Log in</a>.</p>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
