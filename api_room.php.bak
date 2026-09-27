<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

session_write_close(); // Release session lock early — rest of script is read-only

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=hvac_current;charset=utf8mb4',
        $config['host'],
        $config['port']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sql = "
        SELECT
            p.point_id,
            p.equipment_type,
            p.equipment_name,
            p.role,
            ar.value,
            ar.read_at
        FROM hvac_current.points p
        LEFT JOIN hvac_current.ai_current ar
            ON ar.point_id = p.point_id
        WHERE p.equipment_type IN ('ROOM', 'OUTDOOR')
          AND p.obj_type = 'AI'
        ORDER BY
            p.equipment_type,
            CAST(p.equipment_name AS UNSIGNED),
            p.equipment_name,
            p.role
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $units = [];

    foreach ($rows as $row) {
        $type = $row['equipment_type'];
        $name = $row['equipment_name'];
        $key  = $type . '_' . $name;

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
        }

        $role  = strtolower(trim($row['role'] ?? ''));
        $value = $row['value'];

        if ($role === 'temp' || $role === 'temperature') {
            $units[$key]['temp'] = $value !== null ? (float) $value : null;
        } elseif ($role === 'humidity' || $role === 'rh') {
            $units[$key]['rh'] = $value !== null ? (float) $value : null;
        }

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

    $payload = json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment'   => [
            'ROOM_TEMP_RH' => $roomList,
        ],
        'data'        => $roomList,
    ], JSON_UNESCAPED_UNICODE);

    header('Content-Length: ' . strlen($payload));
    echo $payload;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
