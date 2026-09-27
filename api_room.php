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
