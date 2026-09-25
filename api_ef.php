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

    echo json_encode([
        'success' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment' => [
            'EXHAUST_FAN' => $exhaustFan
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
