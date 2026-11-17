<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

function project_x_column_exists(string $table, string $column): bool
{
    return qone(
        'SELECT 1 AS present FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        'ss',
        [$table, $column]
    ) !== null;
}

function project_x_index_exists(string $table, string $index): bool
{
    return qone(
        'SELECT 1 AS present FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        'ss',
        [$table, $index]
    ) !== null;
}

if (!project_x_column_exists('bookings', 'verify_token')) {
    $db->query('ALTER TABLE bookings ADD verify_token CHAR(32) NULL');
}
if (!qone(
    "SELECT 1 AS present FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
       AND COLUMN_NAME = 'verify_token' AND NON_UNIQUE = 0"
)) {
    $db->query('CREATE UNIQUE INDEX uniq_verify_token ON bookings (verify_token)');
}
if (!project_x_column_exists('bookings', 'refund_amount')) {
    $db->query('ALTER TABLE bookings ADD refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER amount');
}
if (!project_x_index_exists('bookings', 'idx_booking_user_recent')) {
    $db->query('CREATE INDEX idx_booking_user_recent ON bookings (user_id, id)');
}
if (!project_x_index_exists('bookings', 'idx_booking_user_created')) {
    $db->query('CREATE INDEX idx_booking_user_created ON bookings (user_id, created_at)');
}
if (!project_x_index_exists('bookings', 'idx_booking_created')) {
    $db->query('CREATE INDEX idx_booking_created ON bookings (created_at)');
}
if (!project_x_index_exists('bookings', 'idx_booking_payment')) {
    $db->query('CREATE INDEX idx_booking_payment ON bookings (payment_ref)');
}
if (!project_x_index_exists('routes', 'idx_route_search')) {
    $db->query('CREATE INDEX idx_route_search ON routes (mode, status, origin, destination)');
}

$db->query(
    'CREATE TABLE IF NOT EXISTS seat_holds (
      id INT AUTO_INCREMENT PRIMARY KEY,
      schedule_id INT NOT NULL,
      seat_label VARCHAR(16) NOT NULL,
      hold_token CHAR(32) NOT NULL,
      expires_at DATETIME NOT NULL,
      UNIQUE KEY uniq_seat_hold (schedule_id, seat_label),
      INDEX idx_hold_token (hold_token),
      INDEX idx_hold_expiry (expires_at),
      FOREIGN KEY (schedule_id) REFERENCES schedules(id)
    )'
);
if (!project_x_index_exists('seat_holds', 'idx_hold_expiry')) {
    $db->query('CREATE INDEX idx_hold_expiry ON seat_holds (expires_at)');
}

$db->query('ALTER TABLE bookings MODIFY seat_label VARCHAR(16) NOT NULL');
$db->query('ALTER TABLE seat_holds MODIFY seat_label VARCHAR(16) NOT NULL');

$db->query("UPDATE planes SET total_seats = 162 WHERE cabin = 'Economy' AND total_seats < 162");
$db->query("UPDATE planes SET total_seats = 16 WHERE cabin = 'Business' AND total_seats < 16");
$db->query("UPDATE trains SET total_seats = " . (int) train_capacity('Snigdha') . " WHERE seat_class = 'Snigdha'");
$db->query("UPDATE trains SET total_seats = " . (int) train_capacity('AC') . " WHERE seat_class = 'AC'");
foreach (qall('SELECT id, train_number FROM trains WHERE seat_class = ?', 's', ['Shovan']) as $train) {
    $extras = train_has_extra_coaches((string) $train['train_number'], (int) $train['id']);
    qexec(
        'UPDATE trains SET total_seats = ? WHERE id = ?',
        'ii',
        [train_capacity('Shovan', $extras), (int) $train['id']]
    );
}

$db->query(
    "CREATE TABLE IF NOT EXISTS notification_outbox (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      booking_id INT NOT NULL,
      notification_type ENUM('ticket_email') NOT NULL,
      status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
      attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
      available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      sent_at DATETIME NULL,
      last_error VARCHAR(1000) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_notification_booking (booking_id, notification_type),
      INDEX idx_notification_ready (status, available_at, id),
      FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
    )"
);

foreach (['buses', 'trains', 'planes'] as $fleetTable) {
    if (!project_x_column_exists($fleetTable, 'day_off')) {
        $db->query(
            "ALTER TABLE {$fleetTable}
             ADD day_off TINYINT NOT NULL DEFAULT 1
             COMMENT 'MySQL DAYOFWEEK: 1=Sun .. 7=Sat; staggered per vehicle'
             AFTER total_seats"
        );
    }
}

require __DIR__ . '/includes/timetable.php';
require_once __DIR__ . '/includes/fleet.php';
require_once __DIR__ . '/includes/fleet_replace.php';
ensure_replacement_tables();
ensure_vehicle_day_offs(true);
ensure_weekly_table();
$slots = ensure_weekly_slots();
$rolled = roll_dated_schedules(7);
if (fleet_lock(30)) {
    try {
        ensure_real_fleet();
        assign_open_fleet();
    } finally {
        fleet_unlock();
    }
}

echo "Project-X database migrations are up to date.\n";
echo "Weekly slots ready (+{$slots}). Dated trips rolled (+{$rolled}).\n";
echo "Day off is per vehicle (staggered across the week).\n";
echo "Hot standby spare ~8%; in-shop maintenance ~5% of working fleet.\n";
foreach (['buses' => 'bus', 'trains' => 'train', 'planes' => 'plane'] as $table => $label) {
    $distinct = (int) qone("SELECT COUNT(DISTINCT day_off) c FROM {$table} WHERE status = 'active'")['c'];
    echo "  {$label}: {$distinct} distinct rest days\n";
}
