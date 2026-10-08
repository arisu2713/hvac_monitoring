<?php

/*
 * Global alarm endpoint.
 *
 * Read-only view over hvac_current.alarm_events, the table both pollers write
 * (BACNET_POLLER and EF_POLLER). This file never writes: muting an alarm is a
 * browser-side concern and must not touch alarm state.
 */

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

/*
 * Cleared alarms are history and would grow without bound, so only a recent
 * window is returned. ACTIVE rows are always included whatever their age.
 * Both values are clamped integers, never raw request strings.
 */
$hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 24;
$hours = max(1, min(168, $hours));

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
$limit = max(1, min(1000, $limit));

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

    /*
     * Counts are taken over the whole table, not over the windowed and limited
     * row set below, so the summary stays correct when history is truncated.
     * NOW(6) comes from the database so the window and the reported time are
     * measured on the same clock the pollers stamp raised_at with.
     */
    $counts = $pdo->query("
        SELECT
            NOW(6)                                    AS server_time,
            COALESCE(SUM(event_class = 'ALARM'), 0)   AS active_alarm,
            COALESCE(SUM(event_class = 'WARNING'), 0) AS active_warning,
            COUNT(*)                                  AS active_total
        FROM alarm_events
        WHERE status = 'ACTIVE'
    ")->fetch();

    $stmt = $pdo->prepare("
        SELECT
            id, event_class, source, equip_type, equipment,
            point_id, ef_point_id, panel_no, ip_address, metric,
            description, status, value, limit_min, limit_max,
            raised_at, cleared_at
        FROM alarm_events
        WHERE status = 'ACTIVE'
           OR raised_at >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)
        ORDER BY (status = 'ACTIVE') DESC, raised_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    /*
     * active_key is deliberately not returned: it is the pollers' internal
     * de-duplication key, not something the page needs. The stable identifier
     * used for new-alarm detection is the primary key, id.
     */
    $alarms = [];

    foreach ($stmt as $row) {
        $alarms[] = [
            'id'          => (int) $row['id'],
            'event_class' => $row['event_class'],
            'source'      => $row['source'],
            'equip_type'  => $row['equip_type'],
            'equipment'   => $row['equipment'],
            'point_id'    => $row['point_id'],
            'ef_point_id' => $row['ef_point_id'] === null ? null : (int) $row['ef_point_id'],
            'panel_no'    => $row['panel_no'] === null ? null : (int) $row['panel_no'],
            'ip_address'  => $row['ip_address'],
            'metric'      => $row['metric'],
            'description' => $row['description'],
            'status'      => $row['status'],
            'value'       => $row['value'] === null ? null : (float) $row['value'],
            'limit_min'   => $row['limit_min'] === null ? null : (float) $row['limit_min'],
            'limit_max'   => $row['limit_max'] === null ? null : (float) $row['limit_max'],
            'raised_at'   => $row['raised_at'],
            'cleared_at'  => $row['cleared_at'],
        ];
    }

    echo json_encode([
        'success' => true,
        'counts' => [
            'active_alarm'   => (int) $counts['active_alarm'],
            'active_warning' => (int) $counts['active_warning'],
            'active_total'   => (int) $counts['active_total'],
        ],
        'window_hours' => $hours,
        'server_time'  => $counts['server_time'],
        'alarms'       => $alarms,
    ]);
} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
