<?php
require __DIR__ . '/includes/bootstrap.php';

$mode = clean_mode($_GET['mode'] ?? 'bus');
$askedFrom = trim($_GET['from'] ?? '');
$askedTo = trim($_GET['to'] ?? '');
$date = trim($_GET['date'] ?? '');
$dateShown = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $appToday && $date <= $bookingHorizon) ? $date : $appToday;
$cityList = cities($mode);
$error = '';
$trips = [];
$noun = ['bus' => 'bus', 'train' => 'train', 'plane' => 'flight'][$mode];
$placeHint = ['bus' => 'City or terminal', 'train' => 'City or station', 'plane' => 'City or airport'][$mode];
$class = clean_cabin($mode, trim($_GET['class'] ?? ''));
$from = in_array($askedFrom, $cityList, true) ? $askedFrom : '';
$to = in_array($askedTo, $cityList, true) ? $askedTo : '';
$missed = [];
if ($askedFrom !== '' && $from === '') {
    $missed[] = $askedFrom;
}
if ($askedTo !== '' && $to === '') {
    $missed[] = $askedTo;
}
$gap = null;
if ($missed) {
    $pair = $askedFrom !== '' && $askedTo !== '' && strcasecmp($askedFrom, $askedTo) !== 0;
    $plural = ['bus' => 'buses', 'train' => 'trains', 'plane' => 'flights'][$mode];
    $options = [];
    if ($pair) {
        $choices = open_route_offers($askedFrom, $askedTo, $dateShown, $mode);
        foreach ($choices as $choice) {
            $bits = [duration_label((int) $choice['minutes'])];
            if ($choice['fare'] !== null) {
                $bits[] = 'from ' . money($choice['fare']);
            }
            $times = (int) $choice['times'];
            $bits[] = $times === 1 ? '1 departure' : $times . ' departures';
            $options[] = [
                'mode' => $choice['mode'],
                'label' => $askedFrom . ' → ' . $askedTo,
                'meta' => implode(' · ', $bits),
                'href' => 'search.php?mode=' . urlencode($choice['mode'])
                    . '&from=' . urlencode($askedFrom)
                    . '&to=' . urlencode($askedTo)
                    . '&date=' . urlencode($dateShown),
            ];
        }
        $fromHub = nearest_airport($askedFrom);
        $toHub = nearest_airport($askedTo);
        $sameFlight = $fromHub && $toHub && $fromHub['city'] === $askedFrom && $toHub['city'] === $askedTo;
        if ($fromHub && $toHub && $fromHub['city'] !== $toHub['city'] && !$sameFlight) {
            $flights = open_route_offers($fromHub['city'], $toHub['city'], $dateShown, '', 'plane');
            $flight = $flights[0] ?? null;
            if ($flight && (int) $flight['times'] > 0) {
                $bits = ['Nearest airports, ' . $fromHub['code'] . ' to ' . $toHub['code'], duration_label((int) $flight['minutes'])];
                if ($flight['fare'] !== null) {
                    $bits[] = 'from ' . money($flight['fare']);
                }
                $options[] = [
                    'mode' => 'plane',
                    'label' => $fromHub['city'] . ' → ' . $toHub['city'],
                    'meta' => implode(' · ', $bits),
                    'href' => 'search.php?mode=plane'
                        . '&from=' . urlencode($fromHub['city'])
                        . '&to=' . urlencode($toHub['city'])
                        . '&date=' . urlencode($dateShown),
                ];
            }
        }
    }
    if ($pair && count($missed) === 2 && $mode === 'plane') {
        $lead = $askedFrom . ' and ' . $askedTo . ' have no airport.';
    } elseif ($pair && count($missed) === 1 && $mode === 'plane') {
        $lead = $missed[0] . ' has no airport.';
    } elseif ($pair && $mode === 'train') {
        $lead = count($missed) === 1
            ? $missed[0] . ' has no railway station.'
            : $askedFrom . ' and ' . $askedTo . ' have no railway station.';
    } elseif ($pair) {
        $lead = count($missed) === 1
            ? $missed[0] . ' is not on a bus route.'
            : 'This pair is not on a bus route.';
    } else {
        $city = $missed[0];
        $lead = match ($mode) {
            'plane' => $city . ' has no airport.',
            'train' => $city . ' has no railway station.',
            default => $city . ' is not on a bus route.',
        };
    }
    $gap = [
        'title' => $pair ? 'No ' . $plural . ' on this route' : $lead,
        'lead' => $pair ? $lead : 'Choose a city on this network.',
        'date' => date_label($dateShown),
        'pair' => $pair,
        'options' => $options,
    ];
} elseif ($from !== '' && strcasecmp($from, $to) === 0) {
    $to = '';
    $error = 'Departure and destination must be different.';
}

if ($error === '' && $from !== '' && $to !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $appToday || $date > $bookingHorizon) {
        $error = 'Choose a date within the next 7 days.';
    } else {
        $sql = trip_sql() . " WHERE r.origin = ? AND r.destination = ? AND r.mode = ? AND s.travel_date = ?
               AND " . trip_bookable_sql();
        $types = 'ssss';
        $params = [$from, $to, $mode, $date];
        if ($class !== '') {
            $sql .= " AND COALESCE(b.coach_type, tn.seat_class, pl.cabin) = ?";
            $types .= 's';
            $params[] = $class;
        }
        $trips = qall($sql . ' ORDER BY s.depart_time, cabin', $types, $params);
        $trips = array_values(array_filter(
            $trips,
            static fn (array $trip): bool => !trip_has_left((string) $trip['travel_date'], (string) $trip['depart_time'])
        ));
    }
}

$pageTitle = 'Find a ' . $noun;
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
  <div class="mode-switch">
    <?php foreach (['bus' => 'Bus', 'train' => 'Train', 'plane' => 'Plane'] as $key => $label): ?>
      <a class="<?= $mode === $key ? 'on' : '' ?>" href="search.php?mode=<?= e($key) ?>&from=<?= urlencode($askedFrom) ?>&to=<?= urlencode($askedTo) ?>&date=<?= urlencode($date) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <h1>Find a <?= e($noun) ?></h1>
  <form class="search-card flat" method="get">
    <input type="hidden" name="mode" value="<?= e($mode) ?>">
    <div class="route-fields">
      <?php suggest_box('from', 'From', $askedFrom, $placeHint); ?>
      <button class="swap-btn" type="button" aria-label="Swap cities">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M9 3 5 7h3v7h2V7h3L9 3zm6 18 4-4h-3v-7h-2v7h-3l4 4z"/></svg>
      </button>
      <?php suggest_box('to', 'To', strcasecmp($askedFrom, $askedTo) === 0 ? '' : $askedTo, $placeHint); ?>
    </div>
    <?php class_field($mode, $class); ?>
    <script type="application/json" class="city-source"><?= json_encode(city_places($mode), JSON_UNESCAPED_UNICODE) ?></script>
    <label class="date-field">
      <span class="field-box">
        <svg class="field-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2zm11 8H6v8h12v-8z"/></svg>
        <span class="field-copy">
          <span class="field-kicker">Date</span>
          <input type="date" name="date" required min="<?= e($appToday) ?>" max="<?= e($bookingHorizon) ?>" value="<?= e($dateShown) ?>">
        </span>
      </span>
    </label>
    <button class="btn btn-gold" type="submit">Search</button>
  </form>
  <?php if ($error): ?>
    <div class="alert err"><?= e($error) ?></div>
  <?php elseif ($gap): ?>
    <div class="route-gap" role="status">
      <div>
        <h2><?= e($gap['title']) ?></h2>
        <p><?= e($gap['lead']) ?></p>
      </div>
      <?php if ($gap['options']): ?>
        <p class="route-gap-kicker">Other ways on <?= e($gap['date']) ?></p>
        <div class="route-options">
          <?php foreach ($gap['options'] as $option): ?>
            <a class="route-option" href="<?= e($option['href']) ?>">
              <?= mode_badge($option['mode']) ?>
              <span class="route-option-main">
                <strong><?= e($option['label']) ?></strong>
                <small><?= e($option['meta']) ?></small>
              </span>
              <span class="route-option-go">View</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php elseif ($gap['pair']): ?>
        <p>Try another date or another pair of cities.</p>
      <?php endif; ?>
    </div>
  <?php elseif ($from !== ''): ?>
    <?php $tripWord = count($trips) === 1 ? $noun : ['bus' => 'buses', 'train' => 'trains', 'plane' => 'flights'][$mode]; ?>
    <div class="result-bar">
      <span class="result-count"><strong><?= count($trips) ?></strong> <?= e($tripWord) ?></span>
      <span class="result-route"><b><?= e($from) ?></b> <span aria-hidden="true">→</span> <b><?= e($to) ?></b></span>
      <?php if ($class !== ''): ?><span class="result-chip"><?= e($class) ?></span><?php endif; ?>
      <span class="result-chip"><?= e(date_label($date)) ?></span>
    </div>
  <?php endif; ?>
</section>

<div class="trip-list">
  <?php foreach ($trips as $trip): ?>
    <?php
      $cabinShown = $mode === 'train'
          ? train_cabin_label((string) $trip['cabin'], (string) $trip['depart_time'])
          : (string) $trip['cabin'];
      $left = bookable_seats($trip) - (int) $trip['taken'];
    ?>
    <article class="trip <?= e($mode) ?>">
      <div>
        <p class="time"><?= e(clock_label($trip['depart_time'])) ?></p>
        <p class="muted"><?= e(duration_label((int) $trip['duration_minutes'])) ?> · <?= (int) $trip['distance_km'] ?> km</p>
      </div>
      <div>
        <?= mode_badge($mode) ?>
        <strong><?= e($trip['origin']) ?> → <?= e($trip['destination']) ?></strong>
        <p><strong><?= e($trip['carrier_name']) ?></strong> · <?= e($trip['service_code']) ?> · <?= e($cabinShown) ?></p>
      </div>
      <div>
        <strong><?= e(money($trip['fare'])) ?></strong>
        <p class="<?= $left < 6 ? 'warn-text' : 'muted' ?>"><?= $left ?> seats left</p>
      </div>
      <?php if ($left > 0): ?>
        <a class="btn" href="seats.php?schedule=<?= (int) $trip['id'] ?>">Select seat</a>
      <?php else: ?>
        <span class="btn disabled">Full</span>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php if ($from !== '' && !$error && !$trips): ?>
    <div class="empty">No trips are available for this date<?= $class !== '' ? ' in ' . e($class) : '' ?>. Try another date or travel class.</div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
