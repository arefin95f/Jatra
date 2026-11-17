<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if ($user['role'] !== 'passenger') {
    redirect('admin/index.php');
}

$profile = qone(
    'SELECT full_name, email, phone, nid_no FROM users WHERE id = ?',
    'i',
    [(int) $user['id']]
);
if (!$profile) {
    http_response_code(404);
    exit('Profile not found.');
}

$errors = [];
$name = $profile['full_name'];
$email = $profile['email'];
$phone = preg_replace('/\D+/', '', $profile['phone']) ?? '';
if (str_starts_with($phone, '880') && strlen($phone) > 10) {
    $phone = substr($phone, 3);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim(preg_replace('/\s+/u', ' ', $_POST['full_name'] ?? '') ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = preg_replace('/\D+/', '', trim($_POST['phone'] ?? '')) ?? '';
    if (str_starts_with($phone, '880') && strlen($phone) > 10) {
        $phone = substr($phone, 3);
    } elseif (str_starts_with($phone, '0') && strlen($phone) === 11) {
        $phone = substr($phone, 1);
    }

    if (!preg_match('/^\p{L}+(?: \p{L}+)*$/u', $name) || strlen($name) > 120) {
        $errors[] = 'Enter a name using letters only.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^\d{10}$/', $phone)) {
        $errors[] = 'Enter the 10 digits after +880.';
    }
    if (!$errors) {
        $duplicate = qone(
            'SELECT id FROM users WHERE (email = ? OR phone = ?) AND id <> ?',
            'ssi',
            [$email, '+880' . $phone, (int) $user['id']]
        );
        if ($duplicate) {
            $errors[] = 'That email address or phone number is already used by another account.';
        }
    }
    if (!$errors) {
        try {
            qexec(
                'UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?',
                'sssi',
                [$name, $email, '+880' . $phone, (int) $user['id']]
            );
            flash('ok', 'Your profile has been updated.');
            redirect('profile.php');
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() !== 1062) {
                throw $exception;
            }
            $errors[] = 'That email address or phone number is already used by another account.';
        }
    }
}

$pageTitle = 'Profile';
require __DIR__ . '/includes/header.php';
?>
<form class="auth-card profile-card" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_profile">
  <p class="eyebrow">Account</p>
  <h1>Your profile</h1>
  <?php foreach ($errors as $error): ?>
    <div class="alert err"><?= e($error) ?></div>
  <?php endforeach; ?>
  <label>Full name
    <input name="full_name" required maxlength="120" value="<?= e($name) ?>" autocomplete="name">
  </label>
  <label>NID number
    <input value="<?= e($profile['nid_no'] ?? 'Not provided') ?>" readonly>
  </label>
  <label>Email
    <input type="email" name="email" required maxlength="160" value="<?= e($email) ?>" autocomplete="email">
  </label>
  <label>Phone
    <span class="phone-field">
      <span class="phone-code">+880</span>
      <input name="phone" required inputmode="numeric" maxlength="10" pattern="\d{10}" value="<?= e($phone) ?>" autocomplete="tel-national">
    </span>
  </label>
  <button class="btn btn-gold" type="submit">Save profile</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
