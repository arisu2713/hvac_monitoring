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

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error'   => $e->getMessage()
        ],
        JSON_UNESCAPED_UNICODE
    );
}
