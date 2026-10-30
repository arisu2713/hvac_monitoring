<?php

require_once __DIR__ . '/auth.php';
require_login();

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

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 1. Ambil batas ambang (thresholds) CHILLER dari threshold_direct
    $thresholds = [
        'cond_entering_temp_max' => null,
        'evap_leaving_temp_max'  => null,
    ];

    $stmtTh = $pdo->query("SELECT metric, max_value FROM threshold_direct WHERE equip_type = 'CHILLER'");
    foreach ($stmtTh as $th) {
        $metric = trim($th['metric']);
        if ($metric === 'cond_entering_temp') {
            $thresholds['cond_entering_temp_max'] = $th['max_value'] !== null ? (float)$th['max_value'] : null;
        } elseif ($metric === 'evap_leaving_temp') {
            $thresholds['evap_leaving_temp_max'] = $th['max_value'] !== null ? (float)$th['max_value'] : null;
        }
    }

    // 2. Query data master points dan live values (ai_current & bi_current)
    $sql = "
        SELECT
            p.point_id,
            p.device_id,
            p.equip_type,
            p.instance,
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
            END AS read_at,
            CASE
                WHEN p.obj_type = 'AI' AND ai.point_id IS NOT NULL THEN 1
                ELSE 0
            END AS has_ai_record,
            CASE
                WHEN p.obj_type = 'BI' AND bi.point_id IS NOT NULL THEN 1
                ELSE 0
            END AS has_bi_record
        FROM points p
        LEFT JOIN ai_current ai 
            ON ai.point_id = p.point_id AND p.obj_type = 'AI'
        LEFT JOIN bi_current bi 
            ON bi.point_id = p.point_id AND p.obj_type = 'BI'
        WHERE p.equip_type LIKE 'CHILLER % MODBUS'
        ORDER BY p.equip_type, p.instance ASC
    ";

    $rows = $pdo->query($sql)->fetchAll();

    function mapChillerPointField(string $name): ?string {
        $n = trim($name);
        if (preg_match('/^ST_CHILLER-\d+$/i', $n)) return 'status';
        if (preg_match('/^EVAP ENTERING-\d+$/i', $n)) return 'evap_entering_temp';
        if (preg_match('/^EVAP LEAVING-\d+$/i', $n)) return 'evap_leaving_temp';
        if (preg_match('/^COND ENTERING-\d+$/i', $n)) return 'cond_entering_temp';
        if (preg_match('/^COND LEAVING-\d+$/i', $n)) return 'cond_leaving_temp';
        if (preg_match('/^RLA-\d+$/i', $n)) return 'rla';
        if (preg_match('/^EVAP REFR PRESS-\d+$/i', $n)) return 'evap_refr_press';
        if (preg_match('/^COND REFR PRESS-\d+$/i', $n)) return 'cond_refr_press';
        if (preg_match('/^EVAP SAT RFGT TEMP-\d+$/i', $n)) return 'evap_sat_rfgt_temp';
        if (preg_match('/^COND SAT RFGT TEMP-\d+$/i', $n)) return 'cond_sat_rfgt_temp';
        if (preg_match('/^OIL TANK TEMP-\d+$/i', $n)) return 'oil_tank_temp';
        if (preg_match('/^IGV(?:-1)?\s+POSITION-\d+$/i', $n)) return 'igv_position';
        if (preg_match('/^AMPERE R-\d+$/i', $n)) return 'ampere_r';
        if (preg_match('/^AMPERE S-\d+$/i', $n)) return 'ampere_s';
        if (preg_match('/^AMPERE T-\d+$/i', $n)) return 'ampere_t';
        if (preg_match('/^VOLTAGE R-\d+$/i', $n)) return 'voltage_r';
        if (preg_match('/^VOLTAGE S-\d+$/i', $n)) return 'voltage_s';
        if (preg_match('/^VOLTAGE T-\d+$/i', $n)) return 'voltage_t';
        if (preg_match('/^STARTER INPUT POWER-\d+$/i', $n)) return 'starter_input_power';
        if (preg_match('/^WINDING TEMP R-\d+$/i', $n)) return 'winding_temp_r';
        if (preg_match('/^WINDING TEMP S-\d+$/i', $n)) return 'winding_temp_s';
        if (preg_match('/^WINDING TEMP T-\d+$/i', $n)) return 'winding_temp_t';
        if (preg_match('/^RUN HOUR-\d+$/i', $n)) return 'run_hour';
        if (preg_match('/^SETPOINT CHILLER-\d+$/i', $n)) return 'setpoint';
        if (preg_match('/^COND WATER FLOW ST-\d+$/i', $n)) return 'cond_water_flow';
        if (preg_match('/^EVAP WATER FLOW ST-\d+$/i', $n)) return 'evap_water_flow';
        if (preg_match('/^Active Setpoint RLA-\d+$/i', $n)) return 'active_setpoint_rla';

        return null;
    }

    // Inisialisasi 9 unit Chiller
    $chillers = [];
    for ($i = 1; $i <= 9; $i++) {
        $chillers[$i] = [
            'id' => (string)$i,
            'name' => "CHILLER $i",
            'equip_type' => "CHILLER $i MODBUS",
            'device_id' => null,
            'status' => 'NO DATA',
            'run_state' => 'unknown', // 'on' | 'off' | 'unknown'
            'is_warning' => false,
            'warning_reasons' => [],
            'last_update' => null,
            'ai_record_count' => 0,
            'bi_record_count' => 0,
            'points' => [
                'status' => null,
                'cond_water_flow' => null,
                'evap_water_flow' => null,
                'evap_entering_temp' => null,
                'evap_leaving_temp' => null,
                'cond_entering_temp' => null,
                'cond_leaving_temp' => null,
                'rla' => null,
                'evap_refr_press' => null,
                'cond_refr_press' => null,
                'evap_sat_rfgt_temp' => null,
                'cond_sat_rfgt_temp' => null,
                'oil_tank_temp' => null,
                'igv_position' => null,
                'ampere_r' => null,
                'ampere_s' => null,
                'ampere_t' => null,
                'voltage_r' => null,
                'voltage_s' => null,
                'voltage_t' => null,
                'starter_input_power' => null,
                'winding_temp_r' => null,
                'winding_temp_s' => null,
                'winding_temp_t' => null,
                'run_hour' => null,
                'setpoint' => null,
                'active_setpoint_rla' => null,
            ],
            'read_at' => [],
            'thresholds' => [
                'cond_entering_temp_max' => $thresholds['cond_entering_temp_max'],
                'evap_leaving_temp_max' => $thresholds['evap_leaving_temp_max'],
            ],
        ];
    }

    $dbNow = $pdo->query("SELECT NOW() as now_time")->fetchColumn();
    $nowTs = strtotime($dbNow);

    foreach ($rows as $r) {
        if (!preg_match('/^CHILLER\s+(\d+)\s+MODBUS$/i', $r['equip_type'], $m)) {
            continue;
        }
        $num = (int)$m[1];
        if (!isset($chillers[$num])) continue;

        if ($chillers[$num]['device_id'] === null && $r['device_id'] !== null) {
            $chillers[$num]['device_id'] = (int)$r['device_id'];
        }

        $field = mapChillerPointField($r['point_name']);
        if (!$field) continue;

        $val = null;
        if ($r['value'] !== null) {
            $val = strtoupper($r['obj_type']) === 'BI' ? (int)$r['value'] : (float)$r['value'];
        }

        $chillers[$num]['points'][$field] = $val;
        $chillers[$num]['read_at'][$field] = $r['read_at'];

        if ($r['has_ai_record']) {
            $chillers[$num]['ai_record_count']++;
        }
        if ($r['has_bi_record']) {
            $chillers[$num]['bi_record_count']++;
        }

        // Catat last_update berdasarkan telemetry yang ada
        if ($r['read_at'] !== null) {
            if ($chillers[$num]['last_update'] === null || $r['read_at'] > $chillers[$num]['last_update']) {
                $chillers[$num]['last_update'] = $r['read_at'];
            }
        }
    }

    // Klasifikasi Status dengan Prioritas:
    // 1. NO DATA (Belum ada telemetri analog / ai_record_count === 0, misal Chiller 1 & 3)
    // 2. OFFLINE (Telemetri tersedia tapi age > 300s)
    // 3. RUNNING (ST_CHILLER = 1 & fresh)
    // 4. STOPPED (ST_CHILLER = 0 & fresh)
    $summary = [
        'total' => count($chillers),
        'running' => 0,
        'stopped' => 0,
        'warning' => 0,
        'offline' => 0,
        'no_data' => 0,
    ];

    foreach ($chillers as $num => &$ch) {
        // Prioritas 1: NO DATA
        if ($ch['ai_record_count'] === 0) {
            $ch['status'] = 'NO DATA';
            $ch['run_state'] = 'unknown';
            $ch['is_warning'] = false;
            $ch['warning_reasons'] = [];
            $ch['last_update'] = null; // Tidak menampilkan last_update BI agar konsisten dengan status NO DATA
            $summary['no_data']++;
            continue;
        }

        // Prioritas 2: OFFLINE
        $lastTs = $ch['last_update'] ? strtotime($ch['last_update']) : 0;
        $age = $nowTs - $lastTs;
        if ($age > 300) {
            $ch['status'] = 'OFFLINE';
            $ch['run_state'] = 'unknown';
            $ch['is_warning'] = false;
            $ch['warning_reasons'] = ["Telemetry stale ({$age}s > 300s)"];
            $summary['offline']++;
            continue;
        }

        // Prioritas 3 & 4: RUNNING / STOPPED
        $st = $ch['points']['status'];
        if ($st === 1) {
            $ch['run_state'] = 'on';
            $ch['status'] = 'RUNNING';
            $summary['running']++;

            // Warning threshold hanya berlaku pada Chiller RUNNING
            $wReasons = [];
            $ce = $ch['points']['cond_entering_temp'];
            $ceMax = $ch['thresholds']['cond_entering_temp_max'];
            if ($ce !== null && $ceMax !== null && $ce > $ceMax) {
                $wReasons[] = "Condenser Entering {$ce} °C > {$ceMax} °C";
            }

            $el = $ch['points']['evap_leaving_temp'];
            $elMax = $ch['thresholds']['evap_leaving_temp_max'];
            if ($el !== null && $elMax !== null && $el > $elMax) {
                $wReasons[] = "Evaporator Leaving {$el} °C > {$elMax} °C";
            }

            if (!empty($wReasons)) {
                $ch['is_warning'] = true;
                $ch['warning_reasons'] = $wReasons;
                $summary['warning']++;
            }
        } elseif ($st === 0) {
            $ch['run_state'] = 'off';
            $ch['status'] = 'STOPPED';
            $summary['stopped']++;
        } else {
            $ch['run_state'] = 'unknown';
            $ch['status'] = 'UNKNOWN';
        }
    }
    unset($ch);

    $chillerList = array_values($chillers);

    $payload = json_encode([
        'success'     => true,
        'server_time' => $dbNow,
        'thresholds'  => $thresholds,
        'summary'     => $summary,
        'equipment'   => [
            'CHILLER_MODBUS' => $chillerList,
        ],
        'chillers'    => $chillerList,
    ], JSON_UNESCAPED_UNICODE);

    header('Content-Length: ' . strlen($payload));
    echo $payload;

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
