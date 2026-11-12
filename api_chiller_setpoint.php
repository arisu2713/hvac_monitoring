<?php

/*
 * API Endpoint: Chiller Setpoint & Max RLA
 *
 * Data source: chiller_setpoint_hourly table (hvac_current database).
 * Fetches latest Setpoint (°C) and Active Setpoint RLA (%) per chiller.
 *
 * The underlying table contains 'timestamp' and 'created_at'.
 * 'read_at' is aliased to 'timestamp' for seamless compatibility.
 */

require_once __DIR__ . '/auth.php';

// Enforce session authentication
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
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

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 1. Fetch latest snapshot (one row per chiller)
    $latestSql = "
        SELECT 
            equip_type,
            MAX(CASE WHEN point_name LIKE 'SETPOINT%' THEN value END) AS setpoint,
            MAX(CASE WHEN point_name LIKE 'Active Setpoint RLA%' THEN value END) AS active_setpoint_rla,
            MAX(timestamp) AS timestamp,
            MAX(timestamp) AS read_at,
            MAX(created_at) AS created_at
        FROM chiller_setpoint_hourly
        WHERE timestamp = (SELECT MAX(timestamp) FROM chiller_setpoint_hourly)
        GROUP BY equip_type
        ORDER BY LENGTH(equip_type), equip_type
    ";

    $stmtLatest = $pdo->query($latestSql);
    $latestRows = $stmtLatest->fetchAll();

    // Pre-populate chillers 1 to 9
    $units = [];
    $dataTimestamp = null;

    for ($i = 1; $i <= 9; $i++) {
        $units[$i] = [
            'id'                  => $i,
            'name'                => "CHILLER $i",
            'equip_type'          => "CHILLER $i MODBUS",
            'building'            => $i <= 5 ? "G1-G2" : "G3",
            'gateway'             => $i <= 5 ? "11008" : "11007",
            'setpoint'            => null,
            'active_setpoint_rla' => null,
            'timestamp'           => null,
            'read_at'             => null,
            'created_at'          => null,
        ];
    }

    foreach ($latestRows as $row) {
        if (preg_match('/^CHILLER\s+(\d+)/i', $row['equip_type'], $m)) {
            $num = (int)$m[1];
            if (isset($units[$num])) {
                $units[$num]['setpoint']            = $row['setpoint'] !== null ? round((float)$row['setpoint'], 2) : null;
                $units[$num]['active_setpoint_rla'] = $row['active_setpoint_rla'] !== null ? round((float)$row['active_setpoint_rla'], 1) : null;
                $units[$num]['timestamp']           = $row['timestamp'];
                $units[$num]['read_at']             = $row['read_at'];
                $units[$num]['created_at']          = $row['created_at'];

                if ($dataTimestamp === null && !empty($row['timestamp'])) {
                    $dataTimestamp = $row['timestamp'];
                }
            }
        }
    }

    // 2. Fetch recent hourly log history (latest 90 records)
    $historySql = "
        SELECT 
            timestamp,
            timestamp AS read_at,
            equip_type,
            MAX(CASE WHEN point_name LIKE 'SETPOINT%' THEN value END) AS setpoint,
            MAX(CASE WHEN point_name LIKE 'Active Setpoint RLA%' THEN value END) AS active_setpoint_rla,
            MAX(created_at) AS created_at
        FROM chiller_setpoint_hourly
        GROUP BY timestamp, equip_type
        ORDER BY timestamp DESC, LENGTH(equip_type), equip_type
        LIMIT 90
    ";
    $stmtHistory = $pdo->query($historySql);
    $historyRows = [];
    foreach ($stmtHistory->fetchAll() as $hr) {
        $chNum = preg_match('/^CHILLER\s+(\d+)/i', $hr['equip_type'], $m) ? (int)$m[1] : null;
        $historyRows[] = [
            'timestamp'           => $hr['timestamp'],
            'read_at'             => $hr['read_at'],
            'chiller_id'          => $chNum,
            'chiller_name'        => $chNum ? "CHILLER $chNum" : $hr['equip_type'],
            'equip_type'          => $hr['equip_type'],
            'setpoint'            => $hr['setpoint'] !== null ? round((float)$hr['setpoint'], 2) : null,
            'active_setpoint_rla' => $hr['active_setpoint_rla'] !== null ? round((float)$hr['active_setpoint_rla'], 1) : null,
            'created_at'          => $hr['created_at'],
        ];
    }

    $dbNow = $pdo->query("SELECT NOW() as now_time")->fetchColumn();

    echo json_encode([
        'success'        => true,
        'server_time'    => $dbNow,
        'data_timestamp' => $dataTimestamp,
        'units'          => array_values($units),
        'history'        => $historyRows,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
