<?php
$user = current_user();
$flash = take_flash();
$company = setting('company_name', 'Jatra');
$unreadNotifications = 0;
if ($user && ($user['role'] ?? '') === 'passenger') {
    require_once __DIR__ . '/fleet_replace.php';
    ensure_replacement_tables();
    $unreadNotifications = unread_notification_count((int) $user['id']);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? $company) ?></title>
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body>
<header class="site-header">
  <a class="brand" href="index.php">
    <span class="brand-mark"><?= e(strtoupper(substr($company, 0, 1))) ?></span>
    <span><?= e($company) ?></span>
  </a>
  <nav class="site-nav">
    <?php if ($user): ?>
      <div class="account-bar">
        <?php if ($user['role'] === 'admin'): ?>
          <a class="account-main" href="admin/index.php">
            <span class="account-mark">A</span>
            Admin
          </a>
          <a class="account-out" href="logout.php">Log out</a>
        <?php else: ?>
          <details class="account-menu">
            <summary class="account-trigger">
              <span class="account-mark"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
              <span><?= e($user['full_name']) ?></span>
              <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="m5 7 5 5 5-5z"/></svg>
            </summary>
            <div class="account-dropdown">
              <a href="profile.php">Profile</a>
              <a href="bookings.php">My tickets</a>
              <a href="notifications.php">Notifications<?= $unreadNotifications > 0 ? ' (' . (int) $unreadNotifications . ')' : '' ?></a>
              <a href="rest-days.php">Check Dayoff</a>
              <a href="security.php">Security</a>
              <a class="account-dropdown-out" href="logout.php">Log out</a>
            </div>
          </details>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <a class="nav-btn" href="login.php">Sign in</a>
    <?php endif; ?>
  </nav>
</header>
<main class="wrap">
<?php if ($flash): ?>
  <div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
