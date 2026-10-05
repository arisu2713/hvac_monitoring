<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Authentication required'
    ]);
    exit;
}

session_write_close();

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

try {

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    /*
     * AHU points
     *
     * Database schema:
     *   point_id
     *   device_id
     *   obj_type
     *   instance
     *   point_name
     *   equip_type
     *
     * Point naming convention:
     *   AHU N Status
     *   AHU N Alarm
     *   AHU N Duct Temperature
     */

    $sql = "
        SELECT
            p.point_id,
            p.point_name,
            p.obj_type,

            CASE
                WHEN p.obj_type = 'AI' THEN ai.value
                WHEN p.obj_type = 'BI' THEN bi.value
                ELSE NULL
            END AS value,

            CASE
                WHEN p.obj_type = 'AI' THEN ai.read_at
                WHEN p.obj_type = 'BI' THEN bi.read_at
                ELSE NULL
            END AS read_at

        FROM points p

        LEFT JOIN ai_current ai
            ON ai.point_id = p.point_id
           AND p.obj_type = 'AI'

        LEFT JOIN bi_current bi
            ON bi.point_id = p.point_id
           AND p.obj_type = 'BI'

        WHERE p.equip_type = 'AHU'
        ORDER BY p.point_name
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    /*
     * Alarm threshold for AHU duct temperature.
     *
     * Read once here (not per unit) — the same limit applies to every AHU.
     * threshold_direct(equip_type, metric, min_value, max_value) is a
     * reference table maintained outside this app; it is only read here.
     */
    $tempMax = null;

    $thresholdSql = "
        SELECT min_value, max_value
        FROM threshold_direct
        WHERE equip_type = 'AHU'
          AND metric = 'temp'
    ";

    foreach ($pdo->query($thresholdSql)->fetchAll() as $threshold) {
        $tempMax = $threshold['max_value'] !== null
            ? (float)$threshold['max_value']
            : null;
    }

    $units = [];

    foreach ($rows as $row) {

        $pointName = trim($row['point_name'] ?? '');

        /*
         * Extract:
         * AHU 1
         * AHU 10
         * AHU 93
         */
        if (!preg_match('/^(AHU\s+\d+)\s+(.+)$/i', $pointName, $m)) {
            continue;
        }

        $unitName = trim($m[1]);
        $function = strtolower(trim($m[2]));

        if (!isset($units[$unitName])) {
            $units[$unitName] = [
                'name'        => $unitName,
                'run'         => null,
                'alarm'       => null,
                'temp'        => null,
                'temp_max'    => $tempMax,
                'frequency'   => null,
                'last_update' => null,

                'points' => [
                    'run'   => null,
                    'alarm' => null,
                    'temp'  => null
                ]
            ];
        }

        $value = $row['value'];
        $readAt = $row['read_at'];

        /*
         * Status
         */
        if ($function === 'status') {

            $units[$unitName]['run'] =
                $value !== null ? (int)$value : null;

            $units[$unitName]['points']['run'] =
                $row['point_id'];

        }

        /*
         * Alarm
         */
        elseif ($function === 'alarm') {

            $units[$unitName]['alarm'] =
                $value !== null ? (int)$value : null;

            $units[$unitName]['points']['alarm'] =
                $row['point_id'];

        }

        /*
         * Duct Temperature
         */
        elseif ($function === 'duct temperature') {

            $units[$unitName]['temp'] =
                $value !== null ? (float)$value : null;

            $units[$unitName]['points']['temp'] =
                $row['point_id'];
        }

        /*
         * Latest timestamp for this AHU
         */
        if ($readAt !== null) {

            if (
                $units[$unitName]['last_update'] === null ||
                $readAt > $units[$unitName]['last_update']
            ) {
                $units[$unitName]['last_update'] = $readAt;
            }
        }
    }

    /*
     * Daikin AHU 96-97 (G8 building).
     *
     * These units are NOT in hvac_current.points. They are collected by the
     * separate Daikin / Niagara poller into hvac_current.daikin_current, which
     * is already denormalised (equipment_type + equipment_no + point_name +
     * value), so no join to a point master is needed.
     *
     * Additive only: the points query above, the AHU 1-93 loop and their
     * fields are untouched. These rows are merged into the same $units array,
     * so they sort and render exactly like any other AHU.
     *
     * These are STATUS-ONLY cards: only the run status is surfaced here.
     * RoomTemp1 and SensorHumidityROOM deliberately are NOT — they are shown
     * on the ROOM TEMP & RH page instead (see api_room.php). status_only tells
     * the frontend to render the card without a value line.
     *
     * There is no alarm point for these units. BFM*-RUN-ST is a RUN status,
     * so it feeds `run` (not `alarm`): the card shows green / red / gray via
     * the existing getStatus() rules.
     */
    $daikinSql = "
        SELECT
            equipment_no,
            point_name,
            value_bool,
            last_update
        FROM daikin_current
        WHERE equipment_type = 'AHU'
          AND equipment_no IN ('96', '97')
    ";

    foreach ($pdo->query($daikinSql)->fetchAll() as $row) {

        $unitName = 'AHU ' . (int)$row['equipment_no'];

        if (!isset($units[$unitName])) {
            $units[$unitName] = [
                'name'        => $unitName,
                'run'         => null,
                'alarm'       => null,
                'temp'        => null,
                'temp_max'    => $tempMax,
                'frequency'   => null,
                'last_update' => null,
                'status_only' => true,

                'points' => [
                    'run'   => null,
                    'alarm' => null,
                    'temp'  => null
                ]
            ];
        }

        $pointName = trim($row['point_name'] ?? '');

        if ($pointName !== 'BFM1-RUN-ST' && $pointName !== 'BFM2-RUN-ST') {

            // Every other Daikin point (RoomTemp1, SensorHumidityROOM,
            // Feed_MV1, SetPoint, FEED_*, Diff, Pre, Med, SpDP01/02) is not
            // part of this card, and must not influence its timestamp.
            continue;
        }

        $units[$unitName]['run'] =
            $row['value_bool'] !== null ? (int)$row['value_bool'] : null;

        if ($row['last_update'] !== null) {

            if (
                $units[$unitName]['last_update'] === null ||
                $row['last_update'] > $units[$unitName]['last_update']
            ) {
                $units[$unitName]['last_update'] = $row['last_update'];
            }
        }
    }

    /*
     * Natural numeric ordering:
     *
     * AHU 1
     * AHU 2
     * ...
     * AHU 10
     * AHU 11
     */
    uksort(
        $units,
        function ($a, $b) {

            preg_match('/(\d+)/', $a, $ma);
            preg_match('/(\d+)/', $b, $mb);

            return ((int)$ma[1]) <=> ((int)$mb[1]);
        }
    );

    $ahuList = array_values($units);

    $payload = json_encode(
        [
            'success'     => true,
            'server_time' => date('Y-m-d H:i:s'),
            'equipment'   => [
                'AHU' => $ahuList
            ]
        ],
        JSON_UNESCAPED_UNICODE
    );

    header('Content-Length: ' . strlen($payload));

    echo $payload;

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error' => 'Internal server error'
        ],
        JSON_UNESCAPED_UNICODE
    );
}
