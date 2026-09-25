<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

session_write_close(); // Release session lock — rest of script is read-only

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
        FROM hvac_current.points p

        LEFT JOIN hvac_current.ai_current ar
            ON ar.point_id = p.point_id
           AND p.obj_type = 'AI'

        LEFT JOIN hvac_current.bi_current br
            ON br.point_id = p.point_id
           AND p.obj_type = 'BI'

        WHERE p.equipment_type = 'AHU'
        ORDER BY
            CAST(p.equipment_name AS UNSIGNED),
            p.equipment_name,
            p.role
    ";

    $stmt = $pdo->query($sql);
    $rows  = $stmt->fetchAll();

    $units = [];

    foreach ($rows as $row) {
        $name = $row['equipment_name'];

        if (!isset($units[$name])) {
            $units[$name] = [
                'name'        => $name,
                'run'         => null,
                'alarm'       => null,
                'temp'        => null,
                'frequency'   => null,
                'last_update' => null,
            ];
        }

        $role  = strtolower(trim($row['role'] ?? ''));
        $value = $row['value'];

        if ($role === 'run') {
            $units[$name]['run'] = $value !== null ? (int) $value : null;
        } elseif ($role === 'alarm') {
            $units[$name]['alarm'] = $value !== null ? (int) $value : null;
        } elseif ($role === 'run,alarm') {
            $units[$name]['run'] = $value !== null ? (int) $value : null;
        } elseif ($role === 'temp') {
            $units[$name]['temp'] = $value !== null ? (float) $value : null;
        } elseif (strpos($role, 'freq') !== false) {
            $units[$name]['frequency'] = $value !== null ? (float) $value : null;
        }

        if ($row['read_at'] !== null) {
            if (
                $units[$name]['last_update'] === null ||
                $row['read_at'] > $units[$name]['last_update']
            ) {
                $units[$name]['last_update'] = $row['read_at'];
            }
        }
    }

    $ahuList = array_values($units);

    $payload = json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment'   => ['AHU' => $ahuList],
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
