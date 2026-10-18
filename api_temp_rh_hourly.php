<?php

/*
 * Temp & RH hourly endpoint.
 *
 * Reads temp_rh_hourly, written once per hour by temp_rh_snapshot.php.
 *
 * Long format: one row per (recorded_at, point_id) — that pair is the
 * primary key. The table is pivoted here into hour-per-row, room-per-column
 * because that is the shape a wall display can actually read; the pivot is
 * done in PHP rather than as dynamic SQL so no column name is ever built
 * from data.
 *
 * metric selects which half of the pair is shown (temp or rh); showing both
 * at once would double the column count to 20 and is not readable.
 *
 * Read-only: SELECT only.
 */

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

session_write_close();

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

/* Whitelist: the request picks a key, never a column or table name. */
$metrics = [
    'temp' => ['label' => 'TEMPERATURE', 'unit' => 'C'],
    'rh'   => ['label' => 'HUMIDITY',    'unit' => '%RH'],
];

$metricKey = strtolower(trim($_GET['metric'] ?? 'temp'));

if (!isset($metrics[$metricKey])) {
    $metricKey = 'temp';
}

$metric = $metrics[$metricKey];

function valid_date(?string $value): ?string
{
    if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
        return null;
    }

    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
}

$fromParam = valid_date($_GET['from'] ?? null);
$toParam   = valid_date($_GET['to'] ?? null);

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $range = $pdo->query(
        "SELECT MIN(recorded_at) AS min_at, MAX(recorded_at) AS max_at
         FROM temp_rh_hourly"
    )->fetch();

    if ($range['max_at'] === null) {
        echo json_encode([
            'success'   => true,
            'metric'    => $metricKey,
            'metrics'   => array_keys($metrics),
            'unit'      => $metric['unit'],
            'available' => ['min' => null, 'max' => null],
            'from'      => null,
            'to'        => null,
            'rooms'     => [],
            'hours'     => [],
        ]);
        exit;
    }

    /* Default window: the last 7 days of snapshots. */
    $to   = $toParam   ?? substr($range['max_at'], 0, 10);
    $from = $fromParam ?? date('Y-m-d', strtotime($to . ' -6 days'));

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    /*
     * recorded_at is a datetime, so the date bounds are widened to whole
     * days. The end is the last second of $to, so a snapshot at 23:00 is
     * included rather than cut off at midnight.
     */
    $fromAt = $from . ' 00:00:00';
    $toAt   = $to . ' 23:59:59';

    /*
     * Room list comes from the range but NOT from the metric, so the column
     * set is identical for temp and rh over the same dates — switching the
     * metric must not move the columns.
     *
     * The device id travels with each room so the page can group by area.
     * temp_rh_hourly already stores it per row, so no join is needed; MIN()
     * picks one when a room's rows ever disagree.
     */
    $roomStmt = $pdo->prepare("
        SELECT room_type, room_name, MIN(device_id) AS device_id
        FROM temp_rh_hourly
        WHERE recorded_at BETWEEN :from AND :to
        GROUP BY room_type, room_name
        ORDER BY room_type, room_name
    ");
    $roomStmt->execute([':from' => $fromAt, ':to' => $toAt]);

    $rooms = [];

    foreach ($roomStmt as $row) {
        $device = $row['device_id'] !== null ? (int) $row['device_id'] : null;

        $rooms[] = [
            'key'       => $row['room_type'] . '|' . $row['room_name'],
            'room_type' => $row['room_type'],
            'room_name' => $row['room_name'],
            /* Same display rule as api_room.php: "<room_type> <room_name>". */
            'label'     => $row['room_type'] . ' ' . $row['room_name'],
            /*
             * Device identity. The device -> area mapping lives in the page,
             * the same way the ENERGY page maps its units, so the API only
             * reports the fact it can read.
             */
            'device'    => $device,
            'source'    => 'azbil',
        ];
    }

    $stmt = $pdo->prepare("
        SELECT recorded_at, room_type, room_name, value, read_at
        FROM temp_rh_hourly
        WHERE metric = :metric
          AND recorded_at BETWEEN :from AND :to
        ORDER BY recorded_at DESC, room_type, room_name
    ");
    $stmt->execute([':metric' => $metricKey, ':from' => $fromAt, ':to' => $toAt]);

    /*
     * Pivot into hour -> room. read_at is tracked as a min/max pair per hour
     * rather than assumed identical across points: the snapshot script
     * writes them together so they normally match, but a frozen point would
     * show up as a spread and the UI can say so instead of hiding it.
     */
    $hours = [];

    foreach ($stmt as $row) {
        $at = $row['recorded_at'];

        if (!isset($hours[$at])) {
            $hours[$at] = [
                'recorded_at'  => $at,
                'values'       => [],
                'read_at'      => [],
                'read_at_min'  => null,
                'read_at_max'  => null,
            ];
        }

        $key = $row['room_type'] . '|' . $row['room_name'];
        $hours[$at]['values'][$key] = $row['value'] === null ? null : (float) $row['value'];

        $readAt = $row['read_at'];

        /*
         * Per-room read time as well as the hour's spread. The page filters
         * rooms client-side, and a spread taken over rooms that are no longer
         * on screen would state something about the wrong set.
         */
        if ($readAt !== null) {
            $hours[$at]['read_at'][$key] = $readAt;
        }

        if ($readAt !== null) {
            if ($hours[$at]['read_at_min'] === null || $readAt < $hours[$at]['read_at_min']) {
                $hours[$at]['read_at_min'] = $readAt;
            }

            if ($hours[$at]['read_at_max'] === null || $readAt > $hours[$at]['read_at_max']) {
                $hours[$at]['read_at_max'] = $readAt;
            }
        }
    }

    /*
     * Daikin AHU 96-97 (G8 building) as additional ROOM sources.
     *
     * Their room temperature and RH are RoomTemp1 / SensorHumidityROOM, which
     * live in daikin_current — NOT in points/ai_current, and therefore never in
     * temp_rh_hourly. The Azbil poller that fills temp_rh_hourly only snapshots
     * points/ai_current, so these two rooms have no hourly rows there.
     *
     * The Daikin poller's own history write (hvac_monitoring.daikin_readings)
     * was disabled when that database was deleted, so there is no Daikin
     * history table to read. The only surviving historical fact is the current
     * row's value together with the timestamp it was taken at. That single
     * sample is what is reported here, at its real timestamp — it is NOT
     * presented as an hourly series, and the page labels it as last-known.
     *
     * Read-only: SELECT only.
     */
    $daikinMetricByPoint = [
        'RoomTemp1'            => 'temp',
        'SensorHumidityROOM'   => 'rh',
    ];

    $daikinStmt = $pdo->prepare("
        SELECT equipment_no, point_name, value_double, last_update
        FROM daikin_current
        WHERE equipment_type = 'AHU'
          AND equipment_no IN ('96', '97')
          AND point_name IN ('RoomTemp1', 'SensorHumidityROOM')
    ");
    $daikinStmt->execute();

    $daikinRooms = [];

    foreach ($daikinStmt as $row) {
        $name = 'AHU ' . (int) $row['equipment_no'];
        $key  = 'ROOM|' . $name;

        /*
         * Snap the sample to its hour so it lands on the same grid as the
         * hourly rows, and keep the exact time as the reading time.
         */
        $hourAt = $row['last_update'] !== null
            ? substr($row['last_update'], 0, 13) . ':00:00'
            : null;

        /*
         * A room is listed only when its sample falls inside the window. The
         * test is on the timestamp, never on the metric, so the temp and rh
         * column sets stay identical for the same dates.
         */
        if ($hourAt === null || $hourAt < $fromAt || $hourAt > $toAt) {
            continue;
        }

        if (!isset($daikinRooms[$key])) {
            $daikinRooms[$key] = [
                'key'       => $key,
                'room_type' => 'ROOM',
                'room_name' => $name,
                'label'     => $name,
                'device'    => null,
                'source'    => 'daikin',
                'last_update' => null,
            ];
        }

        if ($daikinRooms[$key]['last_update'] === null
            || $row['last_update'] > $daikinRooms[$key]['last_update']) {
            $daikinRooms[$key]['last_update'] = $row['last_update'];
        }

        $rowMetric = $daikinMetricByPoint[$row['point_name']] ?? null;

        if ($rowMetric === null || $rowMetric !== $metricKey) {
            continue;
        }

        if (!isset($hours[$hourAt])) {
            $hours[$hourAt] = [
                'recorded_at'  => $hourAt,
                'values'       => [],
                'read_at'      => [],
                'read_at_min'  => null,
                'read_at_max'  => null,
            ];
        }

        $hours[$hourAt]['values'][$key] = $row['value_double'] === null
            ? null
            : (float) $row['value_double'];

        $readAt = substr($row['last_update'], 0, 19);
        $hours[$hourAt]['read_at'][$key] = $readAt;

        if ($hours[$hourAt]['read_at_min'] === null || $readAt < $hours[$hourAt]['read_at_min']) {
            $hours[$hourAt]['read_at_min'] = $readAt;
        }

        if ($hours[$hourAt]['read_at_max'] === null || $readAt > $hours[$hourAt]['read_at_max']) {
            $hours[$hourAt]['read_at_max'] = $readAt;
        }
    }

    ksort($daikinRooms);

    foreach ($daikinRooms as $room) {
        $rooms[] = $room;
    }

    /*
     * Newest hour first, matching the ORDER BY the Azbil query used.
     */
    krsort($hours);

    echo json_encode([
        'success'   => true,
        'metric'    => $metricKey,
        'metrics'   => array_keys($metrics),
        'unit'      => $metric['unit'],
        'available' => ['min' => $range['min_at'], 'max' => $range['max_at']],
        'from'      => $from,
        'to'        => $to,
        'rooms'     => $rooms,
        'hours'     => array_values($hours),
    ]);
} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error',
    ]);
}
