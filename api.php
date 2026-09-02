<?php

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
            p.point_id,
            p.equipment_type,
            p.equipment_name,
            p.role,
            p.obj_type,
            CASE
                WHEN p.obj_type = 'AI' THEN ar.value
                WHEN p.obj_type = 'BI' THEN br.value
                ELSE NULL
            END AS value,
            CASE
                WHEN p.obj_type = 'AI' THEN ar.read_at
                WHEN p.obj_type = 'BI' THEN br.read_at
                ELSE NULL
            END AS read_at
        FROM points p

        LEFT JOIN (
            SELECT point_id, value, read_at
            FROM (
                SELECT
                    point_id,
                    value,
                    read_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY point_id
                        ORDER BY read_at DESC, id DESC
                    ) AS rn
                FROM ai_readings
            ) x
            WHERE rn = 1
        ) ar ON ar.point_id = p.point_id
           AND p.obj_type = 'AI'

        LEFT JOIN (
            SELECT point_id, value, read_at
            FROM (
                SELECT
                    point_id,
                    value,
                    read_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY point_id
                        ORDER BY read_at DESC, id DESC
                    ) AS rn
                FROM bi_readings
            ) x
            WHERE rn = 1
        ) br ON br.point_id = p.point_id
           AND p.obj_type = 'BI'

        WHERE p.equipment_type IN ('CHILLER', 'CCP', 'CHWP', 'CT', 'AHU')
        ORDER BY
            p.equipment_type,
            CAST(p.equipment_name AS UNSIGNED),
            p.equipment_name,
            p.role
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $equipment = [];

    foreach ($rows as $row) {
        $type = $row['equipment_type'];
        $name = $row['equipment_name'];

        if (!isset($equipment[$type])) {
            $equipment[$type] = [];
        }

        if (!isset($equipment[$type][$name])) {
            $equipment[$type][$name] = [
                'name' => $name,
                'run' => null,
                'alarm' => null,
                'temp' => null,
                'frequency' => null,
                'last_update' => null,
            ];
        }

        $role = strtolower(trim($row['role'] ?? ''));
        $value = $row['value'];

        if ($role === 'run') {
            $equipment[$type][$name]['run'] = $value !== null
                ? (int)$value
                : null;
        }

        elseif ($role === 'alarm') {
            $equipment[$type][$name]['alarm'] = $value !== null
                ? (int)$value
                : null;
        }

        elseif ($role === 'run,alarm') {
            $equipment[$type][$name]['run'] = $value !== null
                ? (int)$value
                : null;
        }

        elseif ($role === 'temp') {
            $equipment[$type][$name]['temp'] = $value !== null
                ? (float)$value
                : null;
        }

        elseif (strpos($role, 'freq') !== false) {
            $equipment[$type][$name]['frequency'] = $value !== null
                ? (float)$value
                : null;
        }

        if ($row['read_at'] !== null) {
            if (
                $equipment[$type][$name]['last_update'] === null ||
                $row['read_at'] > $equipment[$type][$name]['last_update']
            ) {
                $equipment[$type][$name]['last_update'] = $row['read_at'];
            }
        }
    }

    foreach ($equipment as $type => &$units) {
        $units = array_values($units);
    }
    unset($units);

    echo json_encode([
        'success' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment' => $equipment,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
