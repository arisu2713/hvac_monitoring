<?php

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

/*
 * equip_type = 'ROOM TEMP & RH'
 *
 * Point naming convention:
 *   ROOM TEMP 1056 / ROOM RH 1056
 *   ROOM TEMP OVC  / ROOM RH OVC
 *   ROOM TEMP SE 41 / ROOM RH SE 41
 *   Outdoor Temperature G1 / Outdoor Humidity G1
 *
 * Temp and RH points sharing the same label are merged into one card.
 */

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

    $sql = "
        SELECT
            p.point_id,
            p.point_name,
            ai.value,
            ai.read_at
        FROM points p
        LEFT JOIN ai_current ai
            ON ai.point_id = p.point_id
        WHERE p.equip_type = 'ROOM TEMP & RH'
          AND p.obj_type = 'AI'
    ";

    $rows = $pdo->query($sql)->fetchAll();

    /*
     * Alarm thresholds for ROOM temp / RH.
     *
     * Read once here (not per unit) — the same limits apply to every room.
     * OUTDOOR cards deliberately get no limits, so these fields are only
     * added to ROOM entries below.
     * threshold_direct is a reference table maintained outside this app;
     * it is only read here.
     */
    $roomThresholds = [];

    $thresholdSql = "
        SELECT metric, min_value, max_value
        FROM threshold_direct
        WHERE equip_type = 'ROOM'
          AND metric IN ('temp', 'rh')
    ";

    foreach ($pdo->query($thresholdSql)->fetchAll() as $threshold) {
        $roomThresholds[$threshold['metric']] = $threshold['max_value'] !== null
            ? (float)$threshold['max_value']
            : null;
    }

    $units = [];

    foreach ($rows as $row) {
        $pointName = trim($row['point_name'] ?? '');

        if (preg_match('/^ROOM\s+(TEMP|RH)\s+(.+)$/i', $pointName, $m)) {
            $type   = 'ROOM';
            $metric = strtoupper($m[1]) === 'TEMP' ? 'temp' : 'rh';
            $name   = 'ROOM ' . strtoupper(trim($m[2]));
        } elseif (preg_match('/^OUTDOOR\s+(TEMPERATURE|HUMIDITY)\s+(.+)$/i', $pointName, $m)) {
            $type   = 'OUTDOOR';
            $metric = strtoupper($m[1]) === 'TEMPERATURE' ? 'temp' : 'rh';
            $name   = 'OUTDOOR ' . strtoupper(trim($m[2]));
        } else {
            continue;
        }

        $key = $type . '|' . $name;

        if (!isset($units[$key])) {
            $units[$key] = [
                'type'        => $type,
                'name'        => $name,
                'temp'        => null,
                'rh'          => null,
                'temp_unit'   => '°C',
                'rh_unit'     => '% RH',
                'last_update' => null,
            ];

            // OUTDOOR entries deliberately carry no alarm limits.
            if ($type === 'ROOM') {
                $units[$key]['temp_max'] = $roomThresholds['temp'] ?? null;
                $units[$key]['rh_max']   = $roomThresholds['rh'] ?? null;
            }
        }

        $units[$key][$metric] = $row['value'] !== null ? (float)$row['value'] : null;

        if ($row['read_at'] !== null) {
            if (
                $units[$key]['last_update'] === null ||
                $row['read_at'] > $units[$key]['last_update']
            ) {
                $units[$key]['last_update'] = $row['read_at'];
            }
        }
    }

    $roomList = array_values($units);

    /*
     * Daikin AHU 96-97 as additional ROOM sources (G8 building).
     *
     * RoomTemp1 / SensorHumidityROOM for these two units live in
     * hvac_current.daikin_current, NOT in points/ai_current, so they cannot be
     * reached by the query above. daikin_current is already denormalised
     * (equipment_type + equipment_no + point_name + value), so no join to a
     * point master is needed — deliberately NOT joining
     * hvac_monitoring.daikin_points, which this account cannot read.
     *
     * Identity is explicit and comes straight from the row:
     * equipment_type = 'AHU', equipment_no = '96' / '97'. No point_id is
     * invented.
     *
     * Additive only: the 10 existing ROOM / OUTDOOR entries above are
     * untouched and keep their own ordering. These two are appended after
     * them, so the existing cards never move.
     *
     * They are plain ROOM entries, so they reuse the ROOM alarm limits that
     * were read once above (temp 30 / rh 65) — no threshold is changed here.
     */
    $daikinRoomSql = "
        SELECT
            equipment_no,
            point_name,
            value_double,
            last_update
        FROM daikin_current
        WHERE equipment_type = 'AHU'
          AND equipment_no IN ('96', '97')
          AND point_name IN ('RoomTemp1', 'SensorHumidityROOM')
    ";

    $daikinRooms = [];

    foreach ($pdo->query($daikinRoomSql)->fetchAll() as $row) {

        $name = 'AHU ' . (int)$row['equipment_no'];

        if (!isset($daikinRooms[$name])) {
            $daikinRooms[$name] = [
                'type'        => 'ROOM',
                'name'        => $name,
                'temp'        => null,
                'rh'          => null,
                'temp_unit'   => '°C',
                'rh_unit'     => '% RH',
                'temp_max'    => $roomThresholds['temp'] ?? null,
                'rh_max'      => $roomThresholds['rh'] ?? null,
                'last_update' => null,
            ];
        }

        $pointName = trim($row['point_name'] ?? '');

        $metric = $pointName === 'RoomTemp1'
            ? 'temp'
            : ($pointName === 'SensorHumidityROOM' ? 'rh' : null);

        if ($metric === null) {
            continue;
        }

        $daikinRooms[$name][$metric] =
            $row['value_double'] !== null ? (float)$row['value_double'] : null;

        if ($row['last_update'] !== null) {
            if (
                $daikinRooms[$name]['last_update'] === null ||
                $row['last_update'] > $daikinRooms[$name]['last_update']
            ) {
                $daikinRooms[$name]['last_update'] = $row['last_update'];
            }
        }
    }

    // Stable order regardless of the order the rows came back in.
    ksort($daikinRooms);

    foreach ($daikinRooms as $entry) {
        $roomList[] = $entry;
    }

    // Rooms first, outdoor last; natural order inside each group
    usort($roomList, function ($a, $b) {
        $rank = ['ROOM' => 0, 'OUTDOOR' => 1];
        if ($a['type'] !== $b['type']) {
            return $rank[$a['type']] <=> $rank[$b['type']];
        }
        return strnatcasecmp($a['name'], $b['name']);
    });

    $payload = json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment'   => [
            'ROOM_TEMP_RH' => $roomList,
        ],
    ], JSON_UNESCAPED_UNICODE);

    header('Content-Length: ' . strlen($payload));
    echo $payload;

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
