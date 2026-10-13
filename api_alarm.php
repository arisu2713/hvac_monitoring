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

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 1000;
$limit = max(1, min(1000, $limit));

/*
 * The window predicate, shared verbatim by the row query and the total count so
 * "returned" and "matching" can never drift apart.
 *
 * An event qualifies when it is ACTIVE, or when EITHER of its timestamps falls
 * inside the window. Keying on raised_at alone was wrong for a long-lived
 * alarm: an ACTIVE row is returned whatever its age, but the moment it cleared
 * it had to qualify on raised_at instead - so an alarm raised before the window
 * and cleared inside it disappeared exactly when it became history. That is the
 * "cleared events are not visible" symptom. cleared_at closes it.
 *
 * ACTIVE rows are still returned regardless of age: the status test is first
 * and is not windowed.
 */
$windowSql = "status = 'ACTIVE'
           OR raised_at  >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)
           OR cleared_at >= DATE_SUB(NOW(6), INTERVAL {$hours} HOUR)";

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

    /*
     * The number of rows the window matches, before LIMIT. Counted over the
     * same predicate as the row query below, so the page can tell the user
     * "showing N of M" instead of silently dropping the oldest rows when the
     * cap is reached. Without this the truncation was invisible.
     */
    $matched = $pdo->query("
        SELECT COUNT(*) AS c
        FROM alarm_events
        WHERE {$windowSql}
    ")->fetch();

    $stmt = $pdo->prepare("
        SELECT
            id, event_class, source, equip_type, equipment,
            point_id, ef_point_id, panel_no, ip_address, metric,
            description, status, value, limit_min, limit_max,
            raised_at, cleared_at
        FROM alarm_events
        WHERE {$windowSql}
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

    $returned = count($alarms);

    echo json_encode([
        'success' => true,
        'counts' => [
            'active_alarm'   => (int) $counts['active_alarm'],
            'active_warning' => (int) $counts['active_warning'],
            'active_total'   => (int) $counts['active_total'],
        ],
        'window_hours' => $hours,
        'server_time'  => $counts['server_time'],
        /*
         * returned / matched / truncated let the page state honestly how much
         * of the window it is showing. `truncated` is true only when the LIMIT
         * actually bit, so the page never claims completeness it does not have
         * nor cries truncation when there is none.
         */
        'returned'     => $returned,
        'matched'      => (int) $matched['c'],
        'limit'        => $limit,
        'truncated'    => $returned < (int) $matched['c'],
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
