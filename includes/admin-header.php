<?php
$admin = current_user();
$flash = take_flash();
$company = setting('company_name', 'Jatra');
$groups = [
    'Desk' => [
        'dashboard' => ['Dashboard', 'index.php'],
    ],
    'Trips' => [
        'routes' => ['Routes', 'routes.php'],
        'lookup' => ['Departures', 'route-lookup.php'],
        'schedules' => ['Schedules', 'schedules.php'],
        'replace' => ['Replace vehicle', 'replace-vehicle.php'],
    ],
    'Fleet' => [
        'buses' => ['Buses', 'buses.php'],
        'trains' => ['Trains', 'trains.php'],
        'planes' => ['Planes', 'planes.php'],
    ],
    'Sales' => [
        'bookings' => ['Bookings', 'bookings.php'],
        'passengers' => ['Passenger', 'passengers.php'],
        'payments' => ['Payments', 'payments.php'],
    ],
    'Office' => [
        'reports' => ['Reports', 'reports.php'],
        'settings' => ['Settings', 'settings.php'],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? 'Desk') ?> · <?= e($company) ?></title>
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="admin-body">
<div class="shell">
  <aside class="side">
    <a class="brand" href="index.php">
      <span class="brand-mark"><?= e(strtoupper(substr($company, 0, 1))) ?></span>
      <span><?= e($company) ?></span>
    </a>
    <p class="side-kicker">Bus, Train, Plane</p>
    <nav class="side-nav">
      <?php foreach ($groups as $group => $links): ?>
        <div class="nav-section">
          <p class="nav-group"><?= e($group) ?></p>
          <?php foreach ($links as $key => [$label, $href]): ?>
            <a class="<?= ($active ?? '') === $key ? 'on' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="../logout.php">Log out</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <div>
        <p class="crumb"><?= e($company) ?> desk</p>
        <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
      </div>
    </div>
    <div class="content">
      <?php if ($flash): ?>
        <div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
      <?php endif; ?>
