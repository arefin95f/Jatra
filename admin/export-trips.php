<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

function write_export_csv(string $path, array $header, string $sql): void
{
    $file = fopen($path, 'wb');
    if ($file === false) {
        throw new RuntimeException('Could not create an export CSV file.');
    }

    fwrite($file, "\xEF\xBB\xBF");
    fputcsv($file, $header);
    foreach (qall($sql) as $row) {
        fputcsv($file, array_values($row));
    }
    fclose($file);
}

$files = [];
$zipPath = tempnam(sys_get_temp_dir(), 'project-x-export-');
if ($zipPath === false) {
    throw new RuntimeException('Could not create the export archive.');
}

try {
    $csvDefinitions = [
        'routes.csv' => [
            ['route_id', 'mode', 'origin', 'destination', 'distance_km', 'duration_minutes', 'base_fare', 'route_status'],
            'SELECT id AS route_id, mode, origin, destination, distance_km, duration_minutes, base_fare, status AS route_status
             FROM routes ORDER BY mode, origin, destination',
        ],
        'vehicles.csv' => [
            ['mode', 'vehicle_id', 'vehicle_code', 'name', 'provider', 'class', 'total_seats', 'status', 'driver_name', 'driver_phone', 'driver_license'],
            "SELECT 'bus' AS mode, b.id AS vehicle_id, b.bus_number AS vehicle_code, b.name, '' AS provider,
                    b.coach_type AS class, b.total_seats, b.status,
                    '' AS driver_name, '' AS driver_phone, '' AS driver_license
             FROM buses b
             UNION ALL
             SELECT 'train', id, train_number, name, '', seat_class, total_seats, status, '', '', ''
             FROM trains
             UNION ALL
             SELECT 'plane', id, flight_number, airline, airline, cabin, total_seats, status, '', '', ''
             FROM planes
             ORDER BY mode, vehicle_code",
        ],
        'schedules.csv' => [
            ['schedule_id', 'route_id', 'mode', 'origin', 'destination', 'distance_km', 'duration_minutes', 'route_base_fare', 'travel_date', 'depart_time', 'fare', 'class', 'schedule_status', 'vehicle_id', 'vehicle_code', 'vehicle_name', 'provider', 'vehicle_class', 'vehicle_seats', 'vehicle_status'],
            "SELECT s.id AS schedule_id, r.id AS route_id, r.mode, r.origin, r.destination,
                    r.distance_km, r.duration_minutes, r.base_fare AS route_base_fare,
                    s.travel_date, s.depart_time, s.fare, s.cabin_name AS class, s.status AS schedule_status,
                    COALESCE(b.id, t.id, p.id) AS vehicle_id,
                    COALESCE(b.bus_number, t.train_number, p.flight_number) AS vehicle_code,
                    COALESCE(b.name, t.name, p.airline) AS vehicle_name,
                    COALESCE(p.airline, '') AS provider,
                    COALESCE(b.coach_type, t.seat_class, p.cabin) AS vehicle_class,
                    COALESCE(b.total_seats, t.total_seats, p.total_seats) AS vehicle_seats,
                    COALESCE(b.status, t.status, p.status) AS vehicle_status
             FROM schedules s
             JOIN routes r ON r.id = s.route_id
             LEFT JOIN buses b ON b.id = s.bus_id
             LEFT JOIN trains t ON t.id = s.train_id
             LEFT JOIN planes p ON p.id = s.plane_id
             ORDER BY s.travel_date, s.depart_time, s.id",
        ],
    ];

    $csvPaths = [];
    foreach ($csvDefinitions as $name => [$header, $sql]) {
        $path = tempnam(sys_get_temp_dir(), 'project-x-csv-');
        if ($path === false) {
            throw new RuntimeException('Could not create an export CSV file.');
        }
        $files[] = $path;
        write_export_csv($path, $header, $sql);
        $csvPaths[$name] = $path;
    }

    $archive = new ZipArchive();
    if ($archive->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the export archive.');
    }
    foreach ($csvPaths as $name => $path) {
        if (!$archive->addFile($path, $name)) {
            $archive->close();
            throw new RuntimeException('Could not add a CSV file to the export archive.');
        }
    }
    if (!$archive->close()) {
        throw new RuntimeException('Could not finish the export archive.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="project-x-trip-data.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
} finally {
    foreach ($files as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_file($zipPath)) {
        unlink($zipPath);
    }
}
