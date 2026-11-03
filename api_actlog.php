<?php

/*
 * Activity Log API endpoint.
 *
 * Read-only view over hvac_current.equipment_status_events.
 * Returns paginated, filtered event rows. Never writes.
 *
 * Accepted GET parameters (all optional, all validated/clamped):
 *   page         int  >= 1                        default 1
 *   per_page     int  10|25|50|100|200            default 50
 *   date_from    str  YYYY-MM-DD                  default "" (no lower bound)
 *   date_to      str  YYYY-MM-DD                  default "" (no upper bound)
 *   equip_type   str  one of known types or ""    default "" (all)
 *   equip_id     str  free text, LIKE search      default "" (all)
 *   search       str  free text, equipment_id OR point_id LIKE search
 *   new_status   str  ON|OFF|""                   default "" (all)
 */

require_once __DIR__ . '/auth.php';

/* ---- Authentication ---- */
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

session_write_close();

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

/* ---- Constants ---- */
if (!defined('ACTLOG_ALLOWED_PER_PAGE')) {
    define('ACTLOG_ALLOWED_PER_PAGE', [10, 25, 50, 100, 200]);
    define('ACTLOG_DEFAULT_PER_PAGE', 50);
    define('ACTLOG_ALLOWED_TYPES', ['AHU', 'CHILLER', 'CCP', 'CHWP', 'CT', 'EF']);
    define('ACTLOG_ALLOWED_STATUSES', ['ON', 'OFF']);
}

/* ---- Input validation helpers ---- */

if (!function_exists('actlog_clamp_int')) {
    function actlog_clamp_int(mixed $raw, int $min, int $max, int $default): int
    {
        $n = filter_var($raw, FILTER_VALIDATE_INT);
        if ($n === false || $n === null) {
            return $default;
        }
        return max($min, min($max, (int) $n));
    }
}

if (!function_exists('actlog_safe_date')) {
    function actlog_safe_date(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        // Accept YYYY-MM-DD only
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            // Verify it is a real calendar date
            [$y, $m, $d] = explode('-', $raw);
            if (checkdate((int)$m, (int)$d, (int)$y)) {
                return $raw;
            }
        }
        return '';
    }
}

if (!function_exists('actlog_safe_choice')) {
    function actlog_safe_choice(string $raw, array $allowed): string
    {
        return in_array($raw, $allowed, true) ? $raw : '';
    }
}

/* ---- Parse parameters ---- */

$rawPage    = $_GET['page']       ?? 1;
$rawPer     = $_GET['per_page']   ?? ACTLOG_DEFAULT_PER_PAGE;
$rawFrom    = $_GET['date_from']  ?? '';
$rawTo      = $_GET['date_to']    ?? '';
$rawType    = strtoupper(trim($_GET['equip_type'] ?? ''));
$rawId      = trim($_GET['equip_id']   ?? '');
$rawSearch  = trim($_GET['search']     ?? '');
$rawStatus  = strtoupper(trim($_GET['new_status'] ?? ''));
$rawPeriod  = strtolower(trim($_GET['period'] ?? ''));
$period     = in_array($rawPeriod, ['today', 'week', 'month', 'all'], true) ? $rawPeriod : '';

$page    = actlog_clamp_int($rawPage, 1, 100000, 1);
$perPage = in_array((int)$rawPer, ACTLOG_ALLOWED_PER_PAGE, true)
    ? (int)$rawPer
    : ACTLOG_DEFAULT_PER_PAGE;

$dateFrom  = actlog_safe_date($rawFrom);
$dateTo    = actlog_safe_date($rawTo);
$equipType = actlog_safe_choice($rawType, ACTLOG_ALLOWED_TYPES);
$equipId   = $rawId;    // LIKE search — parameterised
$search    = $rawSearch; // LIKE search — parameterised
$newStatus = actlog_safe_choice($rawStatus, ACTLOG_ALLOWED_STATUSES);

$offset = ($page - 1) * $perPage;

/* ---- Build WHERE clause ---- */

$conditions = [];
$params     = [];

if ($dateFrom !== '' || $dateTo !== '') {
    if ($dateFrom !== '') {
        $conditions[] = 'event_time >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $conditions[] = 'event_time <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }
} elseif ($period === 'today') {
    $conditions[] = 'DATE(event_time) = CURDATE()';
} elseif ($period === 'week') {
    $conditions[] = "event_time >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND event_time <= CONCAT(CURDATE(), ' 23:59:59')";
} elseif ($period === 'month') {
    $conditions[] = "event_time >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00') AND event_time <= CONCAT(LAST_DAY(CURDATE()), ' 23:59:59')";
}

if ($equipType !== '') {
    $conditions[] = 'equipment_type = :equip_type';
    $params[':equip_type'] = $equipType;
}

if ($equipId !== '') {
    $conditions[] = 'equipment_id LIKE :equip_id';
    $params[':equip_id'] = '%' . $equipId . '%';
}

if ($search !== '') {
    $conditions[] = '(equipment_id LIKE :search OR point_id LIKE :search2)';
    $params[':search']  = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}

if ($newStatus !== '') {
    $conditions[] = 'new_status = :new_status';
    $params[':new_status'] = $newStatus;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

/* ---- Execute ---- */

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

    /* Total matching rows and ON/OFF breakdown (respects all active filters). */
    $countSql  = "
        SELECT
            COUNT(*) as total,
            COALESCE(SUM(new_status = 'ON'), 0) as total_on,
            COALESCE(SUM(new_status = 'OFF'), 0) as total_off
        FROM equipment_status_events
        $where
    ";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $countsRow = $countStmt->fetch();
    $total    = (int) $countsRow['total'];
    $totalOn  = (int) $countsRow['total_on'];
    $totalOff = (int) $countsRow['total_off'];

    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    // Clamp page to valid range now that we know totalPages
    if ($page > $totalPages) {
        $page   = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    /* Distinct equipment types present (for the filter dropdown). */
    $typeStmt = $pdo->query(
        'SELECT DISTINCT equipment_type FROM equipment_status_events ORDER BY equipment_type'
    );
    $availableTypes = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

    /* Event rows, newest first. */
    $rowSql  = "
        SELECT
            id,
            event_time,
            equipment_type,
            equipment_id,
            point_id,
            previous_status,
            new_status,
            source_poller,
            created_at
        FROM equipment_status_events
        $where
        ORDER BY event_time DESC, id DESC
        LIMIT :limit OFFSET :offset
    ";

    $rowStmt = $pdo->prepare($rowSql);
    foreach ($params as $k => $v) {
        $rowStmt->bindValue($k, $v);
    }
    $rowStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $rowStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $rowStmt->execute();

    $events = [];
    foreach ($rowStmt as $row) {
        $events[] = [
            'id'              => (int) $row['id'],
            'event_time'      => $row['event_time'],
            'equipment_type'  => $row['equipment_type'],
            'equipment_id'    => $row['equipment_id'],
            'point_id'        => $row['point_id'],
            'previous_status' => $row['previous_status'],
            'new_status'      => $row['new_status'],
            'source_poller'   => $row['source_poller'],
            'created_at'      => $row['created_at'],
        ];
    }

    echo json_encode([
        'success'         => true,
        'total'           => $total,
        'total_on'        => $totalOn,
        'total_off'       => $totalOff,
        'page'            => $page,
        'per_page'        => $perPage,
        'total_pages'     => $totalPages,
        'offset'          => $offset,
        'events'          => $events,
        'available_types' => $availableTypes,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    $sqlState   = $e instanceof PDOException ? $e->getCode()             : '';
    $driverCode = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    $tableMissing = $sqlState === '42S02' || $driverCode === 1146;

    error_log('[api_actlog.php] ' . ($tableMissing
        ? 'equipment_status_events table missing'
        : $e->getMessage()));

    if ($tableMissing) {
        http_response_code(200);
        echo json_encode([
            'success'         => true,
            'source_available'=> false,
            'total'           => 0,
            'total_on'        => 0,
            'total_off'       => 0,
            'page'            => 1,
            'per_page'        => $perPage,
            'total_pages'     => 1,
            'offset'          => 0,
            'events'          => [],
            'available_types' => [],
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
