<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/notifications.php';

$limit = max(1, min(100, (int) ($argv[1] ?? 10)));
$processed = 0;

while ($processed < $limit) {
    $job = qone(
        "SELECT id FROM notification_outbox
         WHERE (status = 'pending' AND available_at <= NOW())
            OR (status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
         ORDER BY id
         LIMIT 1"
    );
    if (!$job) {
        break;
    }
    process_ticket_email_job((int) $job['id']);
    $processed++;
}

echo 'Notification jobs processed: ' . $processed . PHP_EOL;
