<?php

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => $secureCookie ? 'None' : 'Lax',
]);
session_start();
require dirname(__DIR__) . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Setup</title>';
    echo '<body style="font-family:Segoe UI,sans-serif;max-width:640px;margin:48px auto;padding:0 20px">';
    echo '<h1>Database not ready</h1>';
    echo '<p>Import <code>Project-X/database.sql</code> into MySQL, then run <code>php seed.php</code> from the Project-X folder.</p>';
    exit;
}

$db->set_charset('utf8mb4');
date_default_timezone_set('Asia/Dhaka');

function app_zone(): DateTimeZone
{
    static $zone;
    return $zone ??= new DateTimeZone('Asia/Dhaka');
}

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', app_zone());
}

function app_today(): string
{
    // Calendar date in Bangladesh. It stays the same through the evening and changes at 12:00 AM.
    return app_now()->format('Y-m-d');
}

function booking_horizon(): string
{
    // Passengers can book today and the next 6 days. That is the window trips are published for.
    return app_now()->modify('+6 days')->format('Y-m-d');
}

$db->query("SET time_zone = '" . app_now()->format('P') . "'");

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if ($sent === '' || !hash_equals($real, $sent)) {
        http_response_code(400);
        exit('Invalid form token. Go back and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function qall(string $sql, string $types = '', array $params = []): array
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function qone(string $sql, string $types = '', array $params = []): ?array
{
    $rows = qall($sql, $types, $params);
    return $rows[0] ?? null;
}

function qexec(string $sql, string $types = '', array $params = []): int
{
    global $db;
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return (int) $db->insert_id;
}

function money($amount): string
{
    return '৳' . number_format((float) $amount, 0);
}

function duration_label(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    if ($hours && $mins) {
        return $hours . 'h ' . $mins . 'm';
    }
    if ($hours) {
        return $hours . 'h';
    }
    return $mins . 'm';
}

function clock_label(string $time): string
{
    $time = trim($time);
    if (preg_match('/(\d{1,2}:\d{2}(?::\d{2})?)/', $time, $match) === 1) {
        $clock = $match[1];
        $format = substr_count($clock, ':') === 2 ? 'H:i:s' : 'H:i';
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $clock, app_zone());
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed->format('g:i A');
        }
    }
    return $time;
}

function date_label(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($date), 0, 10), app_zone());
    return $parsed instanceof DateTimeImmutable ? $parsed->format('d M Y') : $date;
}

function trip_has_left(string $date, string $time): bool
{
    $clock = substr(trim($time), 0, 8);
    $format = substr_count($clock, ':') === 2 ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
    $departure = DateTimeImmutable::createFromFormat('!' . $format, substr(trim($date), 0, 10) . ' ' . $clock, app_zone());
    return $departure instanceof DateTimeImmutable && $departure <= app_now();
}

function clean_mode(?string $mode): string
{
    return in_array($mode, ['bus', 'train', 'plane'], true) ? $mode : 'bus';
}

function mode_label(string $mode): string
{
    return match (clean_mode($mode)) {
        'train' => 'Train',
        'plane' => 'Plane',
        default => 'Bus',
    };
}

function cabin_choices(string $mode): array
{
    return match (clean_mode($mode)) {
        'train' => ['Shovan', 'Snigdha', 'AC'],
        'plane' => ['Economy', 'Business'],
        default => ['Standard', 'VIP', 'Executive'],
    };
}

function clean_cabin(string $mode, string $cabin): string
{
    return in_array($cabin, cabin_choices($mode), true) ? $cabin : '';
}

function class_factor(string $mode, string $cabin): float
{
    return match (clean_mode($mode) . ':' . $cabin) {
        'bus:VIP' => 1.35,
        'bus:Executive' => 1.75,
        'train:Shovan' => 0.7,
        'train:AC' => 1.55,
        'plane:Business' => 1.85,
        default => 1.0,
    };
}

function class_field(string $mode, string $selected): void
{
    $mode = clean_mode($mode);
    $options = [['value' => '', 'label' => 'All']];
    foreach (cabin_choices($mode) as $choice) {
        $options[] = ['value' => $choice, 'label' => $choice];
    }
    $label = $selected !== '' ? $selected : 'All';
    ?>
    <div class="suggest class-field" data-class-menu>
      <button class="field-box class-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
        <svg class="field-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 8.5 12 4l8 4.5-8 4.5-8-4.5zm0 4.2 8 4.5 8-4.5V17l-8 4.5L4 17v-4.3z"/></svg>
        <span class="field-copy">
          <span class="field-kicker">Class</span>
          <span class="class-value"><?= e($label) ?></span>
        </span>
        <svg class="class-chevron" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 10h10l-5 5-5-5z"/></svg>
      </button>
      <input type="hidden" name="class" value="<?= e($selected) ?>">
      <ul class="suggest-list" hidden role="listbox"></ul>
      <script type="application/json" class="class-source"><?= json_encode($options, JSON_UNESCAPED_UNICODE) ?></script>
    </div>
    <?php
}

function mode_badge(string $mode): string
{
    $mode = clean_mode($mode);
    return '<span class="mode-pill ' . e($mode) . '">' . e(mode_label($mode)) . '</span>';
}

function trip_sql(): string
{
    return "SELECT s.id, s.route_id, s.travel_date, s.depart_time, s.fare, s.status,
            s.bus_id, s.train_id, s.plane_id,
            r.origin, r.destination, r.distance_km, r.duration_minutes, r.mode,
            COALESCE(b.name, tn.name, pl.airline) AS carrier_name,
            COALESCE(b.bus_number, tn.train_number, pl.flight_number) AS service_code,
            COALESCE(b.coach_type, tn.seat_class, pl.cabin) AS cabin,
            COALESCE(b.total_seats, tn.total_seats, pl.total_seats) AS total_seats,
            COALESCE(b.status, tn.status, pl.status) AS carrier_status,
            COALESCE(b.day_off, tn.day_off, pl.day_off) AS day_off,
            (SELECT COUNT(*) FROM bookings bk WHERE bk.schedule_id = s.id AND bk.booking_status <> 'cancelled') AS taken
        FROM schedules s
        JOIN routes r ON r.id = s.route_id
        LEFT JOIN buses b ON b.id = s.bus_id
        LEFT JOIN trains tn ON tn.id = s.train_id
        LEFT JOIN planes pl ON pl.id = s.plane_id";
}

/** SQL fragment: assigned vehicle is active and not resting on the travel weekday. */
function trip_bookable_sql(): string
{
    return "s.status = 'scheduled'
      AND r.status = 'active'
      AND COALESCE(b.status, tn.status, pl.status) = 'active'
      AND COALESCE(b.day_off, tn.day_off, pl.day_off) <> DAYOFWEEK(s.travel_date)";
}

/**
 * Remaining departures on a city pair. Same rules as passenger search:
 * active vehicle, not a rest day, and the departure is still ahead.
 *
 * @return list<array{mode: string, minutes: int, fare: ?string, times: int}>
 */
function open_route_offers(string $origin, string $destination, string $date, string $skipMode = '', string $onlyMode = ''): array
{
    $sql = "SELECT r.mode, r.duration_minutes, s.travel_date, s.depart_time, s.fare
            FROM schedules s
            JOIN routes r ON r.id = s.route_id
            LEFT JOIN buses b ON b.id = s.bus_id
            LEFT JOIN trains tn ON tn.id = s.train_id
            LEFT JOIN planes pl ON pl.id = s.plane_id
            WHERE r.origin = ? AND r.destination = ? AND s.travel_date = ?
              AND " . trip_bookable_sql();
    $types = 'sss';
    $params = [$origin, $destination, $date];
    if ($onlyMode !== '') {
        $sql .= ' AND r.mode = ?';
        $types .= 's';
        $params[] = clean_mode($onlyMode);
    } elseif ($skipMode !== '') {
        $sql .= ' AND r.mode <> ?';
        $types .= 's';
        $params[] = clean_mode($skipMode);
    }
    $byMode = [];
    foreach (qall($sql, $types, $params) as $row) {
        if (trip_has_left((string) $row['travel_date'], (string) $row['depart_time'])) {
            continue;
        }
        $key = (string) $row['mode'];
        if (!isset($byMode[$key])) {
            $byMode[$key] = [
                'mode' => $key,
                'minutes' => (int) $row['duration_minutes'],
                'fare' => $row['fare'],
                'times' => [],
            ];
        }
        $offer = &$byMode[$key];
        $minutes = (int) $row['duration_minutes'];
        if ($minutes < $offer['minutes']) {
            $offer['minutes'] = $minutes;
        }
        if ($row['fare'] !== null && ($offer['fare'] === null || (float) $row['fare'] < (float) $offer['fare'])) {
            $offer['fare'] = $row['fare'];
        }
        $offer['times'][(string) $row['depart_time']] = true;
        unset($offer);
    }
    $order = ['bus' => 0, 'train' => 1, 'plane' => 2];
    $offers = [];
    foreach ($byMode as $offer) {
        $offer['times'] = count($offer['times']);
        $offers[] = $offer;
    }
    usort($offers, static fn (array $a, array $b): int => ($order[$a['mode']] ?? 9) <=> ($order[$b['mode']] ?? 9));
    return $offers;
}

function trip_on_day_off(array $trip): bool
{
    $dayOff = (int) ($trip['day_off'] ?? 0);
    if ($dayOff < 1 || $dayOff > 7 || empty($trip['travel_date'])) {
        return false;
    }
    // PHP date('w'): 0=Sun .. 6=Sat → MySQL DAYOFWEEK: 1=Sun .. 7=Sat
    $weekday = (int) date('w', strtotime((string) $trip['travel_date'])) + 1;
    return $weekday === $dayOff;
}

function is_night_train(?string $departTime): bool
{
    if ($departTime === null || $departTime === '') {
        return false;
    }
    $hour = (int) substr($departTime, 0, 2);
    return $hour >= 20 || $hour < 5;
}

function train_cabin_label(string $class, ?string $departTime = null): string
{
    $class = trim($class);
    if ($class === 'AC') {
        return is_night_train($departTime) ? 'AC Berth' : 'AC Seat';
    }
    return $class !== '' ? $class : '—';
}

function train_has_extra_coaches(string $trainNumber = '', int $trainId = 0): bool
{
    $key = $trainNumber !== '' ? $trainNumber : (string) $trainId;
    if ($key === '' || $key === '0') {
        return false;
    }
    return (crc32($key) % 3) === 0;
}

function train_coaches(string $class, bool $extras = false): array
{
    return match (trim($class)) {
        'AC' => ['ক', 'খ'],
        'Snigdha' => ['গ', 'ঘ', 'ঙ'],
        default => $extras
            ? ['চ', 'ছ', 'জ', 'ঝ', 'ঞ', 'ট', 'ঠ']
            : ['চ', 'ছ', 'জ', 'ঝ', 'ঞ'],
    };
}

function train_coach_bn(string $code): string
{
    $code = trim($code);
    $legacy = [
        'KA' => 'ক', 'KHA' => 'খ', 'GA' => 'গ', 'GHA' => 'ঘ', 'NGA' => 'ঙ',
        'CHA' => 'চ', 'SCHA' => 'ছ', 'JA' => 'জ', 'JHA' => 'ঝ', 'NEO' => 'ঞ',
        'TA' => 'ট', 'THA' => 'ঠ', 'DA' => 'ড', 'DANT' => 'ণ', 'DHA' => 'ঢ', 'TO' => 'ত',
        'SA' => 'গ', 'SB' => 'ঘ', 'SC' => 'ঙ', 'SD' => 'চ', 'SE' => 'ছ',
        'ACA' => 'ক', 'ACB' => 'খ', 'ACC' => 'গ', 'ACD' => 'ঘ',
    ];
    $upper = strtoupper($code);
    if (isset($legacy[$upper])) {
        return $legacy[$upper];
    }
    return $code;
}

function seats_per_train_coach(string $class, ?string $departTime = null): int
{
    $class = trim($class);
    if ($class === 'AC') {
        // AC Seat is smaller than Snigdha; AC Berth at night is half of AC Seat.
        $acSeat = 28;
        return is_night_train($departTime) ? intdiv($acSeat, 2) : $acSeat;
    }
    if ($class === 'Snigdha') {
        return 40;
    }
    return 48;
}

function train_seat_letters(string $class, ?string $departTime = null): array
{
    if (trim($class) === 'AC' && is_night_train($departTime)) {
        return ['A', 'B'];
    }
    return ['A', 'B', 'C', 'D'];
}

function train_trip_extras(array $trip): bool
{
    if (($trip['mode'] ?? '') !== 'train' || ($trip['cabin'] ?? '') !== 'Shovan') {
        return false;
    }
    return train_has_extra_coaches((string) ($trip['service_code'] ?? ''), (int) ($trip['train_id'] ?? 0));
}

function train_capacity(string $class, bool $extras = false): int
{
    return count(train_coaches($class, $extras)) * seats_per_train_coach($class, null);
}

function train_trip_capacity(array $trip): int
{
    if (($trip['mode'] ?? '') !== 'train') {
        return (int) ($trip['total_seats'] ?? 0);
    }
    $cabin = (string) ($trip['cabin'] ?? 'Shovan');
    $time = (string) ($trip['depart_time'] ?? '');
    return count(train_coaches($cabin, train_trip_extras($trip))) * seats_per_train_coach($cabin, $time);
}

function bookable_seats(array $trip): int
{
    if (($trip['mode'] ?? '') === 'train') {
        return train_trip_capacity($trip);
    }
    return (int) ($trip['total_seats'] ?? 0);
}

function plane_capacity(string $cabin): int
{
    return trim($cabin) === 'Business' ? 16 : 162;
}

function seat_aisle_after(string $mode, int $total, string $cabin = '', ?string $departTime = null): int
{
    if ($mode === 'plane' && $total > 24) {
        return 3;
    }
    if ($mode === 'train' && trim($cabin) === 'AC' && is_night_train($departTime)) {
        return 1;
    }
    return 2;
}

function seat_rows_for_trip(array $trip, ?string $coach = null): array
{
    $mode = (string) ($trip['mode'] ?? 'bus');
    $cabin = (string) ($trip['cabin'] ?? '');
    $total = (int) ($trip['total_seats'] ?? 0);
    $time = (string) ($trip['depart_time'] ?? '');
    if ($mode !== 'train') {
        return seat_rows($total, $mode, $cabin, $coach, $time, false);
    }
    return seat_rows($total, 'train', $cabin, $coach, $time, train_trip_extras($trip));
}

function seat_rows(
    int $total,
    string $mode = 'bus',
    string $cabin = '',
    ?string $coach = null,
    ?string $departTime = null,
    bool $extras = false
): array {
    if ($mode === 'train') {
        $all = train_coaches($cabin, $extras);
        $coaches = $coach !== null && $coach !== ''
            ? (in_array($coach, $all, true) ? [$coach] : $all)
            : $all;
        $perCoach = seats_per_train_coach($cabin, $departTime);
        $letters = train_seat_letters($cabin, $departTime);
        $rows = [];
        foreach ($coaches as $code) {
            $n = 0;
            $row = 1;
            while ($n < $perCoach) {
                $line = [];
                foreach ($letters as $letter) {
                    if ($n >= $perCoach) {
                        break;
                    }
                    $line[] = $code . '-' . $row . $letter;
                    $n++;
                }
                $rows[] = $line;
                $row++;
            }
        }
        return $rows;
    }

    $letters = $mode === 'plane'
        ? ($total <= 24 ? ['A', 'B', 'C', 'D'] : ['A', 'B', 'C', 'D', 'E', 'F'])
        : ['A', 'B', 'C', 'D'];
    $rows = [];
    $n = 0;
    $row = 1;
    while ($n < $total) {
        $line = [];
        foreach ($letters as $letter) {
            if ($n >= $total) {
                break;
            }
            $line[] = $row . $letter;
            $n++;
        }
        $rows[] = $line;
        $row++;
    }
    return $rows;
}

function coach_is_full_for_trip(array $trip, string $coach, array $unavailable): bool
{
    foreach (seat_rows_for_trip($trip, $coach) as $row) {
        foreach ($row as $seat) {
            if (!in_array($seat, $unavailable, true)) {
                return false;
            }
        }
    }
    return true;
}

function seat_exists(int $total, string $seat, string $mode = 'bus', string $cabin = '', ?string $departTime = null, bool $extras = false): bool
{
    foreach (seat_rows($total, $mode, $cabin, null, $departTime, $extras) as $row) {
        if (in_array($seat, $row, true)) {
            return true;
        }
    }
    return false;
}

function seat_exists_for_trip(array $trip, string $seat): bool
{
    foreach (seat_rows_for_trip($trip) as $row) {
        if (in_array($seat, $row, true)) {
            return true;
        }
    }
    return false;
}

function split_seat_label(string $seat): array
{
    if (preg_match('/^(.+)-([0-9]+[A-Z])$/u', $seat, $m)) {
        return ['coach' => $m[1], 'seat' => $m[2]];
    }
    return ['coach' => '', 'seat' => $seat];
}

function ticket_class_label(array $booking): string
{
    $cabin = (string) ($booking['cabin'] ?? '');
    if (($booking['mode'] ?? '') === 'train') {
        return train_cabin_label($cabin, (string) ($booking['depart_time'] ?? ''));
    }
    return $cabin !== '' ? $cabin : '—';
}

function setting(string $key, string $default = ''): string
{
    global $SETTINGS;
    return $SETTINGS[$key] ?? $default;
}

function asset(string $path): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $prefix = str_contains($script, '/admin/') ? '../' : '';
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file) ? filemtime($file) : 1;
    return $prefix . $path . '?v=' . $version;
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $user = qone(
        'SELECT id, full_name, email, phone, role, status FROM users WHERE id = ?',
        'i',
        [(int) $_SESSION['uid']]
    );
    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        $user = null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('err', 'Please log in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = current_user();
    if (!$user) {
        flash('err', 'Please log in as an administrator.');
        redirect('../login.php');
    }
    if ($user['role'] !== 'admin') {
        flash('err', 'That page is for administrators.');
        redirect('../index.php');
    }
    return $user;
}

function cities(?string $mode = null): array
{
    if ($mode) {
        $mode = clean_mode($mode);
        return array_column(
            qall(
                'SELECT origin AS city FROM routes WHERE mode = ?
                 UNION SELECT destination FROM routes WHERE mode = ?
                 ORDER BY city',
                'ss',
                [$mode, $mode]
            ),
            'city'
        );
    }
    return array_column(
        qall('SELECT origin AS city FROM routes UNION SELECT destination FROM routes ORDER BY city'),
        'city'
    );
}

function bd_divisions(): array
{
    return [
        'Barguna' => 'Barishal', 'Barishal' => 'Barishal', 'Bhola' => 'Barishal', 'Jhalokathi' => 'Barishal', 'Patuakhali' => 'Barishal', 'Pirojpur' => 'Barishal',
        'Bandarban' => 'Chattogram', 'Brahmanbaria' => 'Chattogram', 'Chandpur' => 'Chattogram', 'Chattogram' => 'Chattogram', 'Cumilla' => 'Chattogram', "Cox's Bazar" => 'Chattogram', 'Feni' => 'Chattogram', 'Khagrachhari' => 'Chattogram', 'Lakshmipur' => 'Chattogram', 'Noakhali' => 'Chattogram', 'Rangamati' => 'Chattogram',
        'Dhaka' => 'Dhaka', 'Faridpur' => 'Dhaka', 'Gazipur' => 'Dhaka', 'Gopalganj' => 'Dhaka', 'Kishoreganj' => 'Dhaka', 'Madaripur' => 'Dhaka', 'Manikganj' => 'Dhaka', 'Munshiganj' => 'Dhaka', 'Narayanganj' => 'Dhaka', 'Narsingdi' => 'Dhaka', 'Rajbari' => 'Dhaka', 'Shariatpur' => 'Dhaka', 'Tangail' => 'Dhaka',
        'Bagerhat' => 'Khulna', 'Chuadanga' => 'Khulna', 'Jashore' => 'Khulna', 'Jhenaidah' => 'Khulna', 'Khulna' => 'Khulna', 'Kushtia' => 'Khulna', 'Magura' => 'Khulna', 'Meherpur' => 'Khulna', 'Narail' => 'Khulna', 'Satkhira' => 'Khulna',
        'Jamalpur' => 'Mymensingh', 'Mymensingh' => 'Mymensingh', 'Netrokona' => 'Mymensingh', 'Sherpur' => 'Mymensingh',
        'Bogura' => 'Rajshahi', 'Chapainawabganj' => 'Rajshahi', 'Joypurhat' => 'Rajshahi', 'Naogaon' => 'Rajshahi', 'Natore' => 'Rajshahi', 'Pabna' => 'Rajshahi', 'Rajshahi' => 'Rajshahi', 'Sirajganj' => 'Rajshahi',
        'Dinajpur' => 'Rangpur', 'Gaibandha' => 'Rangpur', 'Kurigram' => 'Rangpur', 'Lalmonirhat' => 'Rangpur', 'Nilphamari' => 'Rangpur', 'Panchagarh' => 'Rangpur', 'Rangpur' => 'Rangpur', 'Thakurgaon' => 'Rangpur',
        'Habiganj' => 'Sylhet', 'Moulvibazar' => 'Sylhet', 'Sunamganj' => 'Sylhet', 'Sylhet' => 'Sylhet',
    ];
}

/** @return array<string, array{0: string, 1: string}> */
function bd_airports(): array
{
    return [
        'Dhaka' => ['DAC', 'Hazrat Shahjalal International'],
        'Chattogram' => ['CGP', 'Shah Amanat International'],
        'Sylhet' => ['ZYL', 'Osmani International'],
        "Cox's Bazar" => ['CXB', "Cox's Bazar Airport"],
        'Rajshahi' => ['RJH', 'Shah Makhdum Airport'],
        'Jashore' => ['JSR', 'Jashore Airport'],
        'Barishal' => ['BZL', 'Barishal Airport'],
        'Rangpur' => ['SPD', 'Saidpur Airport'],
    ];
}

/** Nearest city that actually has an airport, or null when this district has none. */
function nearest_airport(string $city): ?array
{
    $airports = bd_airports();
    $hub = $city;
    if (!isset($airports[$hub])) {
        $hub = bd_divisions()[$city] ?? '';
    }
    if ($hub === '' || !isset($airports[$hub])) {
        return null;
    }
    return ['city' => $hub, 'code' => $airports[$hub][0], 'name' => $airports[$hub][1]];
}

function city_places(string $mode): array
{
    $mode = clean_mode($mode);
    $division = bd_divisions();
    $airports = bd_airports();
    $stations = [
        'Dhaka' => 'Kamalapur Railway Station',
        'Chattogram' => 'Chattogram Railway Station',
        'Sylhet' => 'Sylhet Railway Station',
    ];
    $terminals = [
        'Dhaka' => 'Gabtoli and Sayedabad',
        'Chattogram' => 'Dampara bus terminal',
        'Sylhet' => 'Kadamtoli bus terminal',
        'Khulna' => 'Sonadanga bus terminal',
    ];
    $rows = [];
    foreach (cities($mode) as $city) {
        if ($mode === 'plane' && isset($airports[$city])) {
            $rows[] = ['name' => $city, 'code' => $airports[$city][0], 'line' => $airports[$city][1]];
        } elseif ($mode === 'train') {
            $rows[] = ['name' => $city, 'code' => '', 'line' => $stations[$city] ?? ($city . ' Railway Station')];
        } else {
            $place = $terminals[$city] ?? ($city . ' bus terminal');
            $rows[] = ['name' => $city, 'code' => '', 'line' => $place . ' · ' . ($division[$city] ?? 'Bangladesh') . ' Division'];
        }
    }
    return $rows;
}

function suggest_box(string $name, string $label, string $value, string $placeholder): void
{
    ?>
    <div class="suggest" data-suggest>
      <label>
        <span class="field-box">
          <svg class="field-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
          <span class="field-copy">
            <span class="field-kicker"><?= e($label) ?></span>
            <input class="suggest-text" type="text" placeholder="<?= e($placeholder) ?>" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list" value="<?= e($value) ?>">
          </span>
          <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        </span>
      </label>
      <ul class="suggest-list" hidden role="listbox"></ul>
    </div>
    <?php
}

function city_filter_field(string $name, string $label, string $value, string $id = '', bool $required = false): void
{
    ?>
    <div class="suggest city-filter" data-city-suggest>
      <label>
        <?= e($label) ?>
        <input class="suggest-text" name="<?= e($name) ?>"<?= $id !== '' ? ' id="' . e($id) . '"' : '' ?> value="<?= e($value) ?>" placeholder="City" maxlength="80"<?= $required ? ' required' : '' ?> autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list">
      </label>
      <ul class="suggest-list" hidden role="listbox"></ul>
    </div>
    <?php
}

function vehicle_suggest_field(string $name, string $label, string $value, bool $required = false, bool $spareOnly = false): void
{
    ?>
    <div class="suggest city-filter vehicle-suggest" data-vehicle-suggest<?= $spareOnly ? ' data-spare-only' : '' ?>>
      <label>
        <?= e($label) ?>
        <input class="suggest-text" name="<?= e($name) ?>" value="<?= e($value) ?>" placeholder="<?= $spareOnly ? 'Spare code or name' : 'Code or Name' ?>" maxlength="80"<?= $required ? ' required' : '' ?> autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list">
      </label>
      <ul class="suggest-list" hidden role="listbox"></ul>
    </div>
    <?php
}

function ticket_cap(): int
{
    return 6;
}

function normalize_seats(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $seats = [];
    foreach ($raw as $seat) {
        $seat = strtoupper(trim((string) $seat));
        if ($seat !== '' && !in_array($seat, $seats, true)) {
            $seats[] = $seat;
        }
    }
    return $seats;
}

function tickets_bought_recently(int $userId): int
{
    $row = qone(
        "SELECT COUNT(*) c FROM bookings
         WHERE user_id = ? AND booking_status <> 'cancelled'
           AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        'i',
        [$userId]
    );
    return (int) ($row['c'] ?? 0);
}

function tickets_remaining(?array $user): int
{
    if (!$user) {
        return ticket_cap();
    }
    return max(0, ticket_cap() - tickets_bought_recently((int) $user['id']));
}

function taken_seats(int $scheduleId): array
{
    return array_column(
        qall(
            "SELECT seat_label FROM bookings WHERE schedule_id = ? AND booking_status <> 'cancelled'",
            'i',
            [$scheduleId]
        ),
        'seat_label'
    );
}

function hold_token(): string
{
    if (empty($_SESSION['hold_token'])) {
        $_SESSION['hold_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['hold_token'];
}

function clear_expired_holds(): void
{
    static $cleared = false;
    if ($cleared) {
        return;
    }
    $cleared = true;
    qexec('DELETE FROM seat_holds WHERE expires_at <= NOW() LIMIT 1000');
    qexec(
        "UPDATE bookings
         SET booking_status = 'cancelled'
         WHERE payment_status = 'pending'
           AND booking_status = 'pending'
           AND payment_ref LIKE 'JTRA%'
           AND CHAR_LENGTH(payment_ref) = 20
           AND created_at <= DATE_SUB(NOW(), INTERVAL 7 MINUTE)
         LIMIT 200"
    );
}

function held_by_others(int $scheduleId): array
{
    clear_expired_holds();
    return array_column(
        qall(
            'SELECT seat_label FROM seat_holds WHERE schedule_id = ? AND hold_token <> ?',
            'is',
            [$scheduleId, hold_token()]
        ),
        'seat_label'
    );
}

function my_holds(int $scheduleId): array
{
    clear_expired_holds();
    return array_column(
        qall(
            'SELECT seat_label FROM seat_holds WHERE schedule_id = ? AND hold_token = ? ORDER BY seat_label',
            'is',
            [$scheduleId, hold_token()]
        ),
        'seat_label'
    );
}

function active_hold_elsewhere(int $scheduleId): ?array
{
    clear_expired_holds();
    return qone(
        'SELECT schedule_id, TIMESTAMPDIFF(SECOND, NOW(), MIN(expires_at)) AS seconds
         FROM seat_holds
         WHERE hold_token = ? AND schedule_id <> ? AND expires_at > NOW()
         GROUP BY schedule_id
         ORDER BY MIN(expires_at)
         LIMIT 1',
        'si',
        [hold_token(), $scheduleId]
    );
}

function hold_conflict_message(int $scheduleId): ?string
{
    if (!active_hold_elsewhere($scheduleId)) {
        return null;
    }
    return 'You already have seats held for another trip. Complete that booking or wait for its 7-minute hold to expire before starting a different booking.';
}

function hold_seconds_left(int $scheduleId): int
{
    $row = qone(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(expires_at)) AS seconds
         FROM seat_holds WHERE schedule_id = ? AND hold_token = ? AND expires_at > NOW()',
        'is',
        [$scheduleId, hold_token()]
    );
    if (!$row || $row['seconds'] === null) {
        return 0;
    }
    return max(0, (int) $row['seconds']);
}

function hold_payload(int $scheduleId): array
{
    $holdConflict = hold_conflict_message($scheduleId);
    return [
        'taken' => taken_seats($scheduleId),
        'held' => held_by_others($scheduleId),
        'mine' => my_holds($scheduleId),
        'seconds' => hold_seconds_left($scheduleId),
        'blocked' => $holdConflict !== null,
        'message' => $holdConflict,
    ];
}

function hold_clock(int $scheduleId): void
{
    $seconds = hold_seconds_left($scheduleId);
    $label = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    $low = $seconds > 0 && $seconds <= 60;
    echo '<div class="hold-clock' . ($low ? ' is-low' : '') . '" id="hold-clock" data-seconds="' . $seconds . '" data-schedule="' . $scheduleId . '" data-hold="hold.php"' . ($seconds > 0 ? '' : ' hidden') . '>';
    echo '<strong id="hold-clock-time">' . $label . '</strong>';
    echo '<span>Held</span>';
    echo '</div>';
}

function release_seat(int $scheduleId, string $seat): void
{
    qexec(
        'DELETE FROM seat_holds WHERE schedule_id = ? AND seat_label = ? AND hold_token = ?',
        'iss',
        [$scheduleId, $seat, hold_token()]
    );
}

function claim_seat(int $scheduleId, string $seat, int $max = 6): bool
{
    clear_expired_holds();
    $token = hold_token();
    $max = max(0, min(ticket_cap(), $max));
    if (active_hold_elsewhere($scheduleId)) {
        return false;
    }
    if (qone(
        "SELECT id FROM bookings WHERE schedule_id = ? AND seat_label = ? AND booking_status <> 'cancelled'",
        'is',
        [$scheduleId, $seat]
    )) {
        return false;
    }
    $current = qone('SELECT id, hold_token FROM seat_holds WHERE schedule_id = ? AND seat_label = ?', 'is', [$scheduleId, $seat]);
    if ($current && !hash_equals($current['hold_token'], $token)) {
        return false;
    }
    $mine = (int) (qone(
        'SELECT COUNT(*) c FROM seat_holds WHERE schedule_id = ? AND hold_token = ?',
        'is',
        [$scheduleId, $token]
    )['c'] ?? 0);
    if (!$current && $mine >= $max) {
        return false;
    }
    if ($current) {
        return true;
    }
    $deadline = qone(
        'SELECT MIN(expires_at) AS expires_at FROM seat_holds WHERE schedule_id = ? AND hold_token = ? AND expires_at > NOW()',
        'is',
        [$scheduleId, $token]
    );
    $expiresAt = $deadline['expires_at'] ?? null;
    try {
        if ($expiresAt) {
            qexec(
                'INSERT INTO seat_holds (schedule_id, seat_label, hold_token, expires_at) VALUES (?, ?, ?, ?)',
                'isss',
                [$scheduleId, $seat, $token, $expiresAt]
            );
        } else {
            qexec(
                'INSERT INTO seat_holds (schedule_id, seat_label, hold_token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 MINUTE))',
                'iss',
                [$scheduleId, $seat, $token]
            );
        }
        return true;
    } catch (mysqli_sql_exception $ex) {
        $code = (int) $ex->getCode();
        if ($code === 1062 || $code === 1213) {
            $won = qone('SELECT id, hold_token FROM seat_holds WHERE schedule_id = ? AND seat_label = ?', 'is', [$scheduleId, $seat]);
            return $won && hash_equals($won['hold_token'], $token);
        }
        throw $ex;
    }
}

function badge(string $status): string
{
    return '<span class="badge ' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}

/** Working fleet vs hot-standby spare (day_off 0) vs maintenance. */
function fleet_list_status(array $row): string
{
    if (($row['status'] ?? '') === 'maintenance') {
        return 'maintenance';
    }
    if ((int) ($row['day_off'] ?? 1) === 0) {
        return 'spare';
    }
    return 'active';
}

function method_label(string $method): string
{
    return match ($method) {
        'mobile_money' => 'Mobile Money',
        'card' => 'Card',
        'cash' => 'Cash',
        default => ucfirst($method),
    };
}

$settingsRows = qall('SELECT skey, svalue FROM settings');
$SETTINGS = [];
foreach ($settingsRows as $row) {
    $SETTINGS[$row['skey']] = $row['svalue'];
}
$appToday = app_today();
$bookingHorizon = booking_horizon();

require_once __DIR__ . '/ticketlib.php';
