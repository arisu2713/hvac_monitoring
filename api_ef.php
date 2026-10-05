<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required'
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $sql = "
        SELECT
            p.id AS ef_point_id,
            p.panel_no,
            p.ef_name AS name,
            c.status,
            c.last_update
        FROM ef_points p
        INNER JOIN ef_current c
            ON c.ef_point_id = p.id
        ORDER BY p.panel_no, p.id
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $exhaustFan = [];

    foreach ($rows as $row) {
        $exhaustFan[] = [
            'id' => (int)$row['ef_point_id'],
            'panel_no' => (int)$row['panel_no'],
            'name' => $row['name'],
            'status' => $row['status'] !== null
                ? (int)$row['status']
                : null,
            'last_update' => $row['last_update'],
        ];
    }

    /*
     * Exhaust Fan G8 — EF 58-61.
     *
     * These four fans belong to the G8 building and are collected by the
     * separate Daikin / Niagara poller into hvac_current.daikin_current, NOT
     * into ef_points / ef_current. They therefore cannot join the query above.
     *
     * Deliberately returned under their OWN key, EXHAUST_FAN_G8, never mixed
     * into EXHAUST_FAN: the frontend groups EXHAUST_FAN by panel_no, so a
     * separate key is what structurally guarantees EF 58-61 can never be
     * absorbed into Panel 1-9.
     *
     * The EF number comes from daikin_current.equipment_no (58/59/60/61), which
     * is the identity the poller maintains. daikin_point_id is carried along
     * for traceability against the point master.
     *
     * value_bool is the ON/OFF status: 1 = running, 0 = off, NULL = unknown.
     */
    $exhaustFanG8 = [];

    $g8Sql = "
        SELECT
            point_id,
            equipment_no,
            value_bool,
            last_update
        FROM daikin_current
        WHERE equipment_type = 'EF'
          AND equipment_no IN ('58', '59', '60', '61')
        ORDER BY CAST(equipment_no AS UNSIGNED)
    ";

    foreach ($pdo->query($g8Sql)->fetchAll() as $row) {

        $exhaustFanG8[] = [
            'id' => (int)$row['point_id'],
            'daikin_point_id' => (int)$row['point_id'],
            // Not part of the existing Panel 1-9 grouping; the G8 groupbox
            // renders these as a single flat group.
            'panel_no' => null,
            'name' => 'EF ' . (int)$row['equipment_no'],
            'status' => $row['value_bool'] !== null
                ? (int)$row['value_bool']
                : null,
            'last_update' => $row['last_update'],
        ];
    }

    echo json_encode([
        'success' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment' => [
            'EXHAUST_FAN' => $exhaustFan,
            'EXHAUST_FAN_G8' => $exhaustFanG8
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
