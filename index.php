<?php
require __DIR__ . '/includes/bootstrap.php';

$mode = clean_mode($_GET['mode'] ?? 'bus');
$pageTitle = setting('company_name', 'Jatra');
$cityNames = cities($mode);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$chosenDate = trim($_GET['date'] ?? '');
if (!in_array($from, $cityNames, true)) {
    $from = '';
}
if (!in_array($to, $cityNames, true) || strcasecmp($from, $to) === 0) {
    $to = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $chosenDate) || $chosenDate < $appToday || $chosenDate > $bookingHorizon) {
    $chosenDate = $appToday;
}
$placeHint = ['bus' => 'City or terminal', 'train' => 'City or station', 'plane' => 'City or airport'][$mode];
$class = clean_cabin($mode, trim($_GET['class'] ?? ''));
$copy = [
    'bus' => ['Bus travel across Bangladesh', 'Find a bus'],
    'train' => ['Explore Bangladesh by rail', 'Find a train'],
    'plane' => ['Domestic flights across Bangladesh', 'Find a flight'],
];

require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div class="hero-title">
    <p class="eyebrow"><?= e($copy[$mode][0]) ?></p>
    <h1>Plan your next journey</h1>
  </div>
  <p class="hero-lead">Compare routes, choose your seat, and book your trip online.</p>
  <form class="search-card" method="get" action="search.php">
    <div class="mode-switch">
      <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
        <a class="<?= $mode === $key ? 'on' : '' ?>" href="index.php?mode=<?= e($key) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <div class="route-fields">
      <?php suggest_box('from', 'From', $from, $placeHint); ?>
      <button class="swap-btn" type="button" aria-label="Swap cities">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M9 3 5 7h3v7h2V7h3L9 3zm6 18 4-4h-3v-7h-2v7h-3l4 4z"/></svg>
      </button>
      <?php suggest_box('to', 'To', $to, $placeHint); ?>
    </div>
    <?php class_field($mode, $class); ?>
    <script type="application/json" class="city-source"><?= json_encode(city_places($mode), JSON_UNESCAPED_UNICODE) ?></script>
    <label class="date-field">
      <span class="field-box">
        <svg class="field-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2zm11 8H6v8h12v-8z"/></svg>
        <span class="field-copy">
          <span class="field-kicker">Date</span>
          <input type="date" name="date" required min="<?= e($appToday) ?>" max="<?= e($bookingHorizon) ?>" value="<?= e($chosenDate) ?>">
        </span>
      </span>
    </label>
    <button class="btn btn-gold" type="submit"><?= e($copy[$mode][1]) ?></button>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
