<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pairs = [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'support_phone' => trim($_POST['support_phone'] ?? ''),
        'support_email' => trim($_POST['support_email'] ?? ''),
    ];
    if ($pairs['company_name'] === '' || !filter_var($pairs['support_email'], FILTER_VALIDATE_EMAIL)) {
        flash('err', 'Company name and a valid support email are required.');
        redirect('settings.php');
    }
    foreach ($pairs as $key => $value) {
        qexec(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            'ss',
            [$key, $value]
        );
    }
    flash('ok', 'Settings saved.');
    redirect('settings.php');
}

$pageTitle = 'Settings';
$active = 'settings';
require dirname(__DIR__) . '/includes/admin-header.php';
?>
<form class="card form-grid narrow" method="post">
  <?= csrf_field() ?>
  <h2>Company</h2>
  <label>Company name
    <input name="company_name" required maxlength="120" value="<?= e(setting('company_name')) ?>">
  </label>
  <label>Support phone
    <input name="support_phone" maxlength="40" value="<?= e(setting('support_phone')) ?>">
  </label>
  <label>Support email
    <input type="email" name="support_email" required maxlength="160" value="<?= e(setting('support_email')) ?>">
  </label>
  <button class="btn" type="submit">Save settings</button>
</form>
<?php require dirname(__DIR__) . '/includes/admin-footer.php'; ?>
