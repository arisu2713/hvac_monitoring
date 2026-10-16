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
     * These are STATUS-ONLY cards on the AHU page: only the run status is
     * surfaced for the createCard() loop. status_only is kept true so the main
     * AHU page renders AHU 96-97 exactly as before — name + colour, no value
     * line. RoomTemp1 and SensorHumidityROOM are still shown on the ROOM TEMP &
     * RH page (see api_room.php).
     *
     * The AHU / EF G8 page needs more than that, so the same units also carry
     * room_temp / room_rh / ec_fans as ADDITIVE fields. The plain AHU page
     * ignores them; only the G8 renderer reads them. Keeping them here rather
     * than in a second endpoint means AHU 96-97 has one source of truth.
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
            value_double,
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

                'room_temp'   => null,
                'room_rh'     => null,

                /*
                 * EC Fan 1-5 structure, seeded from the point master below.
                 * Every value starts null because no poller writes them yet —
                 * see the EC fan block for why that is reported as unavailable
                 * rather than as zero.
                 */
                'ec_fans'     => [
                    1 => ['trip' => null, 'frequency' => null, 'temp_motor' => null, 'temp_electronic' => null, 'temp_module' => null, 'unit' => null],
                    2 => ['trip' => null, 'frequency' => null, 'temp_motor' => null, 'temp_electronic' => null, 'temp_module' => null, 'unit' => null],
                    3 => ['trip' => null, 'frequency' => null, 'temp_motor' => null, 'temp_electronic' => null, 'temp_module' => null, 'unit' => null],
                    4 => ['trip' => null, 'frequency' => null, 'temp_motor' => null, 'temp_electronic' => null, 'temp_module' => null, 'unit' => null],
                    5 => ['trip' => null, 'frequency' => null, 'temp_motor' => null, 'temp_electronic' => null, 'temp_module' => null, 'unit' => null],
                ],

                'points' => [
                    'run'   => null,
                    'alarm' => null,
                    'temp'  => null
                ]
            ];
        }

        $pointName = trim($row['point_name'] ?? '');

        /*
         * Only the points this card actually shows may touch it — including its
         * timestamp. Every other Daikin point (Feed_MV1, SetPoint, FEED_*,
         * Diff, Pre, Med, SpDP01/02) stays out.
         */
        if ($pointName === 'BFM1-RUN-ST' || $pointName === 'BFM2-RUN-ST') {

            $units[$unitName]['run'] =
                $row['value_bool'] !== null ? (int)$row['value_bool'] : null;

        } elseif ($pointName === 'RoomTemp1') {

            $units[$unitName]['room_temp'] = $row['value_double'] !== null
                ? (float)$row['value_double']
                : null;

        } elseif ($pointName === 'SensorHumidityROOM') {

            $units[$unitName]['room_rh'] = $row['value_double'] !== null
                ? (float)$row['value_double']
                : null;

        } else {
            continue;
        }

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
     * AHU 96-97 EC Fan 1-5 (Daikin / Niagara).
     *
     * The EC fan mapping lives in the point master daikin_points (50 rows:
     * EC_FAN{1..5}_TRIP / _FREQ / _TEMP_MOTOR / _TEMP_ELECTRONIC /
     * _TEMP_MODULE per AHU).
     *
     * This query is written against the MASTER (a LEFT JOIN), not against
     * daikin_current alone: the fan structure is emitted from the mapping that
     * genuinely exists, and each value is whatever the current table has. The
     * LEFT JOIN is what makes the card degrade honestly — a point with no
     * current row arrives as null and renders as "—", NOT as zero. Inventing a
     * value, or defaulting these to 0, would report a fan as stopped when the
     * truth is that nobody measured it.
     *
     * STATUS AS OF THE LAST CHECK (2026-10-12): daikin_current now DOES carry
     * all 50 EC_FAN rows with live values (FREQ ~37-38 Hz, TRIP 0), so these
     * cards show real readings. An earlier revision of this comment said no
     * EC_FAN* value was ever written; that was true when it was written and is
     * no longer. The code needed no change to pick the values up — the join
     * was already on point_id rather than a hardcoded null, which is exactly
     * why it lights up on its own.
     *
     * TRIP is a fault flag, not a run flag, so it is surfaced as its own field
     * and never folded into `run`.
     */
    $ecFanSql = "
        SELECT
            p.equipment_no,
            p.point_name,
            p.unit,
            c.value_bool,
            c.value_double
        FROM daikin_points p
        LEFT JOIN daikin_current c
            ON c.point_id = p.id
        WHERE p.equipment_type = 'AHU'
          AND p.equipment_no IN ('96', '97')
          AND p.point_name LIKE 'EC\\_FAN%'
        ORDER BY CAST(p.equipment_no AS UNSIGNED), p.point_name
    ";

    $ecFanMetrics = [
        'TRIP'            => 'trip',
        'FREQ'            => 'frequency',
        'TEMP_MOTOR'      => 'temp_motor',
        'TEMP_ELECTRONIC' => 'temp_electronic',
        'TEMP_MODULE'     => 'temp_module',
    ];

    foreach ($pdo->query($ecFanSql)->fetchAll() as $row) {

        $unitName = 'AHU ' . (int)$row['equipment_no'];

        // Only units the block above already created; a stray master row must
        // not conjure an AHU into existence.
        if (!isset($units[$unitName]['ec_fans'])) {
            continue;
        }

        if (!preg_match('/^EC_FAN(\d+)_(.+)$/', trim($row['point_name']), $m)) {
            continue;
        }

        $fanNo = (int)$m[1];
        $metric = $ecFanMetrics[$m[2]] ?? null;

        if ($metric === null || !isset($units[$unitName]['ec_fans'][$fanNo])) {
            continue;
        }

        $units[$unitName]['ec_fans'][$fanNo][$metric] =
            $metric === 'trip'
                ? ($row['value_bool'] !== null ? (int)$row['value_bool'] : null)
                : ($row['value_double'] !== null ? (float)$row['value_double'] : null);

        $units[$unitName]['ec_fans'][$fanNo]['unit'] = $row['unit'];
    }

    /*
     * AHU 94-95 (G8 building, HNX Modbus TCP).
     *
     * These units are NOT in hvac_current.points, and they are NOT in
     * daikin_current either. They are collected by the separate HNX G8 Modbus
     * poller into hvac_current.hnx_g8_points (the point master) joined by
     * point_id to hvac_current.hnx_g8_current (the latest values). Do not read
     * daikin_current or daikin_points for these two units.
     *
     * The join is an INNER JOIN on hnx_g8_current.point_id =
     * hnx_g8_points.id, which is a real foreign key
     * (fk_hnx_g8_current_point), so point identity comes from the master and
     * the value from the current table.
     *
     * Point names (verified against hnx_g8_points, 8 points per AHU):
     *   EC_FAN1_STATUS / EC_FAN2_STATUS / EC_FAN3_STATUS  boolean  -> value_bool
     *   EC_FAN1_SPEED  / EC_FAN2_SPEED  / EC_FAN3_SPEED   numeric  -> fan frequency
     *   DUCT_TEMP                                         numeric  -> duct temp
     *   DUCT_RH                                           numeric  -> not used
     *
     * The three *_SPEED points are the fans' frequency in Hz (unit "Hz" in the
     * point master) and are surfaced as fan1_frequency / fan2_frequency /
     * fan3_frequency. They are read rather than assumed: the points exist and
     * are mapped, but hnx_g8_current currently holds NULL for all six of them,
     * so each one arrives null and the card renders "—". That is the honest
     * reading of an uncollected point, and it lights up on its own the moment
     * the poller starts writing values — no further code change.
     *
     * NOTE ON THE THREE FAN STATUSES. There is no unit-level run/stop point
     * for AHU 94/95 and no aggregation rule for one is documented anywhere
     * (checked in this repo and in the poller project). The rule used below
     * was an explicit operator decision, not an inference from the data:
     *
     *     run = 1 if ANY fan status is 1
     *     run = 0 only if all three fan statuses are present and 0
     *     run = null if no fan status has a value
     *
     * A NULL fan status is "not known", so it never counts as "off" — one
     * unknown fan cannot drag an otherwise-running AHU to off, and a
     * three-NULL AHU stays unknown (grey) rather than off (red).
     *
     * The individual fan values are surfaced alongside as fan1_status /
     * fan2_status / fan3_status so the decision is auditable. The existing
     * frontend ignores these extra fields (it reads only run / alarm / temp /
     * temp_max / last_update), exactly as it ignores the `points` object the
     * points loop above already emits.
     *
     * These are NOT status_only cards: unlike Daikin AHU 96-97, these units
     * do have a duct temperature, so the card keeps its value line.
     *
     * Additive only: the points query, the AHU 1-93 loop, the Daikin AHU 96-97
     * block and their fields are all untouched.
     */
    $hnxG8Sql = "
        SELECT
            p.id AS point_id,
            p.equipment_no,
            p.point_name,
            c.value_double,
            c.value_bool,
            c.last_update
        FROM hnx_g8_points p
        INNER JOIN hnx_g8_current c
            ON c.point_id = p.id
        WHERE p.equipment_type = 'AHU'
          AND p.equipment_no IN ('94', '95')
    ";

    $hnxG8StatusNames = [
        'EC_FAN1_STATUS' => 'fan1_status',
        'EC_FAN2_STATUS' => 'fan2_status',
        'EC_FAN3_STATUS' => 'fan3_status',
    ];

    /*
     * Frequency points, keyed the same way as the statuses so the card can
     * render status and frequency side by side for the same fan. Unit is Hz
     * in the point master; the value is passed through as a float or null,
     * never coerced to 0.
     */
    $hnxG8SpeedNames = [
        'EC_FAN1_SPEED' => 'fan1_frequency',
        'EC_FAN2_SPEED' => 'fan2_frequency',
        'EC_FAN3_SPEED' => 'fan3_frequency',
    ];

    /*
     * AHU 94-95 source availability.
     *
     * hnx_g8_points / hnx_g8_current were created 2026-10-06, AFTER the
     * one-time schema import the hosting database was built from, so on a
     * database that predates them this query fails with "table doesn't exist".
     * Letting that escape would 500 the WHOLE endpoint and blank all 97 AHU
     * cards in order to report a problem with two of them — the failure of a
     * two-unit source must not be able to take down the other 95.
     *
     * So the failure is caught here and turned into an explicit availability
     * flag, which the units below carry through to the page.
     */
    $hnxG8Available = true;
    $hnxG8Rows = [];

    try {
        $hnxG8Rows = $pdo->query($hnxG8Sql)->fetchAll();
    } catch (Throwable $e) {
        $hnxG8Available = false;

        error_log(
            '[' . basename(__FILE__) . '] AHU 94-95 source (hnx_g8) '
            . 'unavailable: ' . $e->getMessage()
        );
    }

    foreach ($hnxG8Rows as $row) {

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

                'fan1_status' => null,
                'fan2_status' => null,
                'fan3_status' => null,

                /*
                 * EC fan frequency (Hz). Null until the poller writes the
                 * EC_FAN*_SPEED points; the card shows "—", not 0.
                 */
                'fan1_frequency' => null,
                'fan2_frequency' => null,
                'fan3_frequency' => null,

                /*
                 * Duct RH. The unit's `temp` field carries Duct Temperature
                 * (the card's main value line on the main AHU page), so the
                 * humidity needs its own field rather than overwriting it.
                 * Label on the G8 page is "Duct RH", never "Room RH".
                 */
                'duct_rh'     => null,

                'points' => [
                    'run'   => [],
                    'alarm' => null,
                    'temp'  => null
                ]
            ];
        }

        $pointName = trim($row['point_name'] ?? '');

        /*
         * Latest timestamp for this AHU — every point of the unit counts,
         * the same way the points loop above does it.
         */
        if ($row['last_update'] !== null) {

            if (
                $units[$unitName]['last_update'] === null ||
                $row['last_update'] > $units[$unitName]['last_update']
            ) {
                $units[$unitName]['last_update'] = $row['last_update'];
            }
        }

        /*
         * Duct Temperature
         */
        if ($pointName === 'DUCT_TEMP') {

            $units[$unitName]['temp'] = $row['value_double'] !== null
                ? (float)$row['value_double']
                : null;

            $units[$unitName]['points']['temp'] =
                $row['point_id'];
        }

        /*
         * Duct RH
         */
        elseif ($pointName === 'DUCT_RH') {

            $units[$unitName]['duct_rh'] = $row['value_double'] !== null
                ? (float)$row['value_double']
                : null;
        }

        /*
         * EC Fan statuses
         */
        elseif (isset($hnxG8StatusNames[$pointName])) {

            $units[$unitName][$hnxG8StatusNames[$pointName]] =
                $row['value_bool'] !== null
                    ? (int)$row['value_bool']
                    : null;

            $units[$unitName]['points']['run'][] =
                $row['point_id'];
        }

        /*
         * EC Fan frequency (Hz). Same null-preserving treatment as the
         * statuses: a point the poller has not written stays null rather than
         * becoming a measured zero.
         */
        elseif (isset($hnxG8SpeedNames[$pointName])) {

            $units[$unitName][$hnxG8SpeedNames[$pointName]] =
                $row['value_double'] !== null
                    ? (float)$row['value_double']
                    : null;
        }
    }

    /*
     * AHU 94-95 are always emitted, even when their source is unreachable.
     *
     * The G8 page lays its four AHU cards out by name and skips any name the
     * API did not send, so a missing unit silently collapses the row and
     * shifts the page — and the main AHU page, which no longer lists 94/95 as
     * placeholders, simply drops them. Emitting the unit with run=null (and
     * `source_available`=false) instead keeps both pages honest: the card is
     * drawn, greyed as unknown, and says why, rather than disappearing.
     *
     * run stays NULL, never 0. "We cannot see this unit" and "this unit is
     * switched off" are different facts and must not look alike.
     */
    foreach (['94', '95'] as $hnxNo) {

        $unitName = 'AHU ' . $hnxNo;

        if (!isset($units[$unitName])) {
            $units[$unitName] = [
                'name'        => $unitName,
                'run'         => null,
                'alarm'       => null,
                'temp'        => null,
                'temp_max'    => $tempMax,
                'frequency'   => null,
                'last_update' => null,

                'fan1_status' => null,
                'fan2_status' => null,
                'fan3_status' => null,

                'fan1_frequency' => null,
                'fan2_frequency' => null,
                'fan3_frequency' => null,

                'duct_rh'     => null,

                'points' => [
                    'run'   => [],
                    'alarm' => null,
                    'temp'  => null
                ]
            ];
        }

        /*
         * Present in the response either way; false only when the hnx_g8
         * source could not be read at all, which is what the page reports as
         * "source unavailable" rather than as an offline unit.
         */
        $units[$unitName]['source_available'] = $hnxG8Available;
    }

    /*
     * Fold the three fan statuses into the single `run` field the frontend
     * reads. Done after the loop so the result does not depend on row order.
     */
    foreach ($units as $unitName => $unit) {

        if (
            !array_key_exists('fan1_status', $unit) ||
            !array_key_exists('fan2_status', $unit) ||
            !array_key_exists('fan3_status', $unit)
        ) {
            // Not an AHU 94/95 unit.
            continue;
        }

        $fanStatuses = [
            $unit['fan1_status'],
            $unit['fan2_status'],
            $unit['fan3_status'],
        ];

        $known = array_filter($fanStatuses, fn($s) => $s !== null);

        if ($known === []) {
            // No fan status has a value: the unit state is unknown, not off.
            $run = null;
        } elseif (in_array(1, $known, true)) {
            // Any fan running -> the unit is running.
            $run = 1;
        } else {
            // Every fan that reported is off -> the unit is off.
            $run = 0;
        }

        $units[$unitName]['run'] = $run;
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
