<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

/*
 * Point naming convention (points.point_name, case-insensitive):
 *
 *   CHILLER 1 Status | CHILLER 1 Alarm
 *   Chiller 1 Evap Supply Temp | Evap Return Temp | Cond Supply Temp | Cond Return Temp
 *   CCP 1 Status | Alarm | Current | Frequency
 *   CHWP 1 Status | Alarm (or AL) | Current | Frequency
 *   CT 1 Current | CT 1 Frequency            (tower level, shared VSD)
 *   CT 1A Status | Alarm | Return Temp       (cell level)
 *
 * Other CHILLER-type points (Flow Zone..., Temp Supply Zone..., etc.)
 * do not match the pattern and are skipped here.
 */

const HVAC_SC_TYPES = ['CHILLER', 'CCP', 'CHWP', 'CT'];

// function text (lowercase) => JSON field
const HVAC_SC_FIELDS = [
    'status'           => 'run',
    'alarm'            => 'alarm',
    'al'               => 'alarm',
    'current'          => 'current',
    'frequency'        => 'frequency',
    'return temp'      => 'return_temp',
    // VSD/Drive temperature — CCP, CHWP and CT only. The point is named
    // "Drive Temp" in the DB; it is NOT the CT cell "Return Temp".
    'drive temp'       => 'drive_temperature',
    // Chiller water temperatures
    // Evap: Supply = leaving (cold), Return = entering.
    // Cond: naming is from the tower's view, so Supply = leaving chiller (hot), Return = entering chiller (cool).
    'evap supply temp' => 'evap_leaving_temp',
    'evap return temp' => 'evap_entering_temp',
    'cond supply temp' => 'cond_leaving_temp',
    'cond return temp' => 'cond_entering_temp',
];

/*
 * Blank card for a unit. $limits carries the alarm bounds resolved from the
 * reference tables (threshold_direct / threshold_by_kw) and is merged over
 * the null defaults, so an unresolved limit simply stays null.
 */
function hvac_sc_blank(string $type, string $name, array $limits = []): array
{
    if ($type === 'CHILLER') {
        return array_merge([
            'name'               => $name,
            'run'                => null,
            'alarm'              => null,
            'setpoint'           => null,   // filled from CHILLER <n> MODBUS below
            'rla'                => null,   // filled from CHILLER <n> MODBUS below
            'evap_leaving_temp'  => null,
            'evap_entering_temp' => null,
            'cond_entering_temp' => null,
            'cond_leaving_temp'  => null,
            'evap_leaving_temp_max'  => null,
            'cond_entering_temp_max' => null,
            'last_update'        => null,
        ], $limits);
    }

    if ($type === 'CT') {
        return array_merge([
            'name'        => $name,
            'run'         => null,
            'alarm'       => null,
            'frequency'   => null,
            'current'     => null,
            'current_min' => null,
            'current_max' => null,
            'return_temp' => null,
            'drive_temperature' => null,
            'last_update' => null,
        ], $limits);
    }

    // CCP, CHWP
    return array_merge([
        'name'        => $name,
        'run'         => null,
        'alarm'       => null,
        'frequency'   => null,
        'current'     => null,
        'current_min' => null,
        'current_max' => null,
        'drive_temperature' => null,
        'last_update' => null,
    ], $limits);
}

/*
 * Current alarm bounds for a CCP / CHWP / CT unit.
 *
 *   1. exact (equip_type, unit_name) match in unit_motor_kw
 *   2. if missing and the name ends in a letter ("5A"), retry without it
 *      ("5") — cooling tower cells share one motor/VSD per tower
 *   3. look the motor_kw up in threshold_by_kw
 *   4. anything unresolved yields null bounds — never an error, and the
 *      unit is still returned to the frontend.
 */
function hvac_sc_current_limits(
    string $type,
    string $name,
    array $unitMotorKw,
    array $thresholdByKw
): array {
    $motorKw = $unitMotorKw[$type . '|' . $name] ?? null;

    if ($motorKw === null && preg_match('/^(.+?)[A-Z]$/', $name, $m)) {
        $motorKw = $unitMotorKw[$type . '|' . $m[1]] ?? null;
    }

    $threshold = $motorKw !== null
        ? ($thresholdByKw[(string)$motorKw] ?? null)
        : null;

    return [
        'current_min' => $threshold['min'] ?? null,
        'current_max' => $threshold['max'] ?? null,
    ];
}

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

    /*
     * Alarm reference tables, loaded once here instead of querying per unit.
     *
     * threshold_direct / threshold_by_kw / unit_motor_kw are maintained
     * outside this app; they are only read here.
     *
     *   thresholdByKw : "30" => ['min' => null, 'max' => 56]
     *   unitMotorKw   : "CT|5A" => 7.5
     */
    $thresholdByKw = [];

    foreach (
        $pdo->query("SELECT motor_kw, min_value, max_value FROM threshold_by_kw")->fetchAll()
        as $threshold
    ) {
        $thresholdByKw[(string)(float)$threshold['motor_kw']] = [
            'min' => $threshold['min_value'] !== null ? (float)$threshold['min_value'] : null,
            'max' => $threshold['max_value'] !== null ? (float)$threshold['max_value'] : null,
        ];
    }

    $unitMotorKw = [];

    foreach (
        $pdo->query("SELECT equip_type, unit_name, motor_kw FROM unit_motor_kw")->fetchAll()
        as $motor
    ) {
        $unitMotorKw[
            strtoupper(trim($motor['equip_type'])) . '|' . strtoupper(trim($motor['unit_name']))
        ] = (float)$motor['motor_kw'];
    }

    $chillerThresholds = [];

    foreach (
        $pdo->query("SELECT metric, max_value FROM threshold_direct WHERE equip_type = 'CHILLER'")->fetchAll()
        as $threshold
    ) {
        $metric = $threshold['metric'];

        if ($metric !== 'evap_leaving_temp' && $metric !== 'cond_entering_temp') {
            continue;
        }

        $chillerThresholds[$metric . '_max'] = $threshold['max_value'] !== null
            ? (float)$threshold['max_value']
            : null;
    }

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
            ON ai.point_id = p.point_id AND p.obj_type = 'AI'
        LEFT JOIN bi_current bi
            ON bi.point_id = p.point_id AND p.obj_type = 'BI'
        WHERE p.equip_type IN ('CHILLER', 'CCP', 'CHWP', 'CT')
    ";

    $rows = $pdo->query($sql)->fetchAll();

    /*
     * Separate Modbus query for chiller Setpoint (SP) and RLA.
     *
     * These points deliberately live under their own equip_type values
     * ("CHILLER 1 MODBUS" .. "CHILLER 9 MODBUS"), NOT under 'CHILLER', so
     * they are intentionally excluded from the main HVAC_SC query above.
     * Do not merge them into that query — the two sources stay separate
     * and are only combined by unit number further down.
     *
     * "Active Setpoint RLA-<n>" is deliberately NOT read here.
     */
    $modbusSql = "
        SELECT
            p.point_name,
            c.value
        FROM points p
        LEFT JOIN ai_current c
            ON c.point_id = p.point_id
        WHERE p.equip_type LIKE 'CHILLER % MODBUS'
          AND (
                p.point_name REGEXP '^SETPOINT[[:space:]]+CHILLER-[0-9]+$'
             OR p.point_name REGEXP '^RLA-[0-9]+$'
          )
    ";

    $modbusSpRla = [];

    foreach ($pdo->query($modbusSql)->fetchAll() as $mrow) {
        $mName = trim($mrow['point_name'] ?? '');
        $mVal  = $mrow['value'] !== null ? (float)$mrow['value'] : null;

        if (preg_match('/^SETPOINT\s+CHILLER-(\d+)$/i', $mName, $mm)) {
            $modbusSpRla[(string)(int)$mm[1]]['setpoint'] = $mVal;
        } elseif (preg_match('/^RLA-(\d+)$/i', $mName, $mm)) {
            $modbusSpRla[(string)(int)$mm[1]]['rla'] = $mVal;
        }
    }

    $equipment = [
        'CHILLER' => [],
        'CCP'     => [],
        'CHWP'    => [],
        'CT'      => [],
    ];

    $g3Shared = [];

    foreach ($rows as $row) {
        $pointName = trim($row['point_name'] ?? '');

        // G3 chillers (6-9): outlet temp is per chiller, other temps are shared header sensors
        $g3Value = $row['value'] !== null ? (float)$row['value'] : null;

        if (preg_match('/^Temp Out Chiller\s+(\d+)$/i', $pointName, $g3m)) {
            $cn = (string)(int)$g3m[1];
            if (!isset($equipment['CHILLER'][$cn])) {
                $equipment['CHILLER'][$cn] = hvac_sc_blank('CHILLER', $cn, $chillerThresholds);
            }
            $equipment['CHILLER'][$cn]['evap_leaving_temp'] = $g3Value;
            if (
                $row['read_at'] !== null &&
                ($equipment['CHILLER'][$cn]['last_update'] === null ||
                 $row['read_at'] > $equipment['CHILLER'][$cn]['last_update'])
            ) {
                $equipment['CHILLER'][$cn]['last_update'] = $row['read_at'];
            }
            continue;
        }

        $g3Header = [
            'temp return header chil g3' => 'evap_entering_temp',
            'temp return head conden g3' => 'cond_entering_temp',
            'temp supp head condenso g3' => 'cond_leaving_temp',
        ];
        $g3Key = strtolower(preg_replace('/\s+/', ' ', $pointName));

        if (isset($g3Header[$g3Key])) {
            $g3Shared[$g3Header[$g3Key]] = ['value' => $g3Value, 'read_at' => $row['read_at']];
            continue;
        }

        if (!preg_match('/^(CHILLER|CCP|CHWP|CT)\s+(\d+[A-Z]?)\s+(.+)$/i', $pointName, $m)) {
            continue;
        }

        $type     = strtoupper($m[1]);
        $name     = strtoupper($m[2]);
        $function = strtolower(preg_replace('/\s+/', ' ', trim($m[3])));

        if (!isset(HVAC_SC_FIELDS[$function])) {
            continue;
        }

        $field = HVAC_SC_FIELDS[$function];

        if (!isset($equipment[$type][$name])) {
            $limits = $type === 'CHILLER'
                ? $chillerThresholds
                : hvac_sc_current_limits($type, $name, $unitMotorKw, $thresholdByKw);

            $equipment[$type][$name] = hvac_sc_blank($type, $name, $limits);
        }

        if (!array_key_exists($field, $equipment[$type][$name])) {
            continue;
        }

        $value = null;
        if ($row['value'] !== null) {
            $value = strtoupper($row['obj_type']) === 'BI'
                ? (int)$row['value']
                : (float)$row['value'];
        }

        $equipment[$type][$name][$field] = $value;

        if ($row['read_at'] !== null) {
            $current = $equipment[$type][$name]['last_update'];
            if ($current === null || $row['read_at'] > $current) {
                $equipment[$type][$name]['last_update'] = $row['read_at'];
            }
        }
    }

    /*
     * Cooling tower: Current / Frequency are tower-level ("CT 9"),
     * Status / Return Temp are cell-level ("CT 9A", "CT 9B").
     * Cells inherit missing values from their tower, then the
     * tower-level entry is dropped so only cells are returned.
     */
    foreach (array_keys($equipment['CT']) as $key) {
        $key = (string)$key;

        if (!ctype_digit($key)) {
            continue;
        }

        $cells = array_filter([$key . 'A', $key . 'B'], fn($c) => isset($equipment['CT'][$c]));

        if (!$cells) {
            continue;
        }

        $parent = $equipment['CT'][$key];

        foreach ($cells as $cellKey) {
            foreach (['run', 'alarm', 'frequency', 'current', 'drive_temperature'] as $f) {
                if ($equipment['CT'][$cellKey][$f] === null && $parent[$f] !== null) {
                    $equipment['CT'][$cellKey][$f] = $parent[$f];
                }
            }

            if (
                $parent['last_update'] !== null &&
                ($equipment['CT'][$cellKey]['last_update'] === null ||
                 $parent['last_update'] > $equipment['CT'][$cellKey]['last_update'])
            ) {
                $equipment['CT'][$cellKey]['last_update'] = $parent['last_update'];
            }
        }

        unset($equipment['CT'][$key]);
    }

    // Apply shared G3 header sensors to chillers 6-9
    foreach (['6', '7', '8', '9'] as $cn) {
        if (!isset($equipment['CHILLER'][$cn])) {
            continue;
        }
        foreach ($g3Shared as $field => $info) {
            $equipment['CHILLER'][$cn][$field] = $info['value'];
            if (
                $info['read_at'] !== null &&
                ($equipment['CHILLER'][$cn]['last_update'] === null ||
                 $info['read_at'] > $equipment['CHILLER'][$cn]['last_update'])
            ) {
                $equipment['CHILLER'][$cn]['last_update'] = $info['read_at'];
            }
        }
    }

    /*
     * Merge the Modbus SP/RLA results into the chiller entries by unit
     * number. A NULL in ai_current stays null in the JSON — no dummy
     * values, no scaling. Chillers with no Modbus connection (1 and 3)
     * simply keep the null defaults from hvac_sc_blank().
     */
    foreach ($modbusSpRla as $chillerNo => $spRla) {
        if (!isset($equipment['CHILLER'][$chillerNo])) {
            continue;
        }

        $equipment['CHILLER'][$chillerNo]['setpoint'] = $spRla['setpoint'] ?? null;
        $equipment['CHILLER'][$chillerNo]['rla']      = $spRla['rla'] ?? null;
    }

    foreach ($equipment as $type => $units) {
        uksort($units, fn($a, $b) => strnatcasecmp((string)$a, (string)$b));
        $equipment[$type] = array_values($units);
    }

    $payload = json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment'   => $equipment,
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
