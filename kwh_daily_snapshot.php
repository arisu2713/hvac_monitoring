<?php
// Daily kWh snapshot + usage calculation.
// Run once daily via hosting cron job (Hostinger has no Event Scheduler
// privilege on shared hosting, so this must be a scheduled PHP script,
// not a MariaDB EVENT).
//
// What it does, once per run:
//   1. Reads today's ACC (kWh accumulator) values from acc_current.
//   2. Inserts one row into kwh_total_<category> for today (insert-only,
//      never updates an existing day's row).
//   3. Looks up yesterday's row in the same table, computes
//      today - yesterday per column, inserts that into
//      kwh_usage_<category> for today.
//   4. If yesterday's snapshot is missing, or a column's diff comes out
//      negative (meter reset/replaced), that column's usage is stored as
//      NULL rather than a wrong number - never guessed.
//
// CLI only - not reachable over HTTP.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Jakarta');

$config = require __DIR__ . '/config.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// acc_current.read_at is naive DATETIME (no timezone conversion happens
// on it), but NOW()/CURDATE() used by THIS session must reflect WIB even
// though the hosting server's own timezone is UTC - otherwise "today"
// would be computed wrong.
$pdo->exec("SET time_zone = '+07:00'");

// One category = one point-name prefix, one snapshot table, one usage
// table, and the column-name mapping (unit number -> column name).
$categories = [
    'CT' => [
        'table' => 'kwh_total_ct',
        'usage_table' => 'kwh_usage_ct',
        'units' => ['1', '2', '3', '4', '5', '6', '9', '10', '11', '12', '13'],
        'column_prefix' => 'ct_',
    ],
    'CCP' => [
        'table' => 'kwh_total_ccp',
        'usage_table' => 'kwh_usage_ccp',
        'units' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
        'column_prefix' => 'ccp_',
    ],
    'CHWP' => [
        'table' => 'kwh_total_chwp',
        'usage_table' => 'kwh_usage_chwp',
        'units' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
        'column_prefix' => 'chwp_',
    ],
];

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Pull every ACC point's current value + its point_name once.
$stmt = $pdo->query("
    SELECT p.point_name, p.equip_type, a.value
    FROM points p
    JOIN acc_current a ON a.point_id = p.point_id
    WHERE p.obj_type = 'ACC'
");
$accRows = $stmt->fetchAll();

foreach ($categories as $equipType => $cat) {
    // Build column => value for today from acc_current, matching
    // "<EQUIP> <n> KWh Total" point names for this category.
    $values = array_fill_keys(
        array_map(fn($u) => $cat['column_prefix'] . $u, $cat['units']),
        null
    );

    foreach ($accRows as $row) {
        if ($row['equip_type'] !== $equipType) {
            continue;
        }
        if (!preg_match('/^' . preg_quote($equipType, '/') . '\s+(\d+)\s+KWh Total$/i', trim($row['point_name']), $m)) {
            continue;
        }
        $col = $cat['column_prefix'] . $m[1];
        if (array_key_exists($col, $values)) {
            $values[$col] = $row['value'] !== null ? (float)$row['value'] : null;
        }
    }

    // 1) Insert today's snapshot (insert-only; if today's row already
    // exists - e.g. cron ran twice - skip rather than overwrite).
    $cols = array_keys($values);
    $colList = implode(', ', $cols);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));

    $insertSnapshot = $pdo->prepare(
        "INSERT IGNORE INTO {$cat['table']} (reading_date, $colList) VALUES (?, $placeholders)"
    );
    $insertSnapshot->execute(array_merge([$today], array_values($values)));

    // 2) Load yesterday's snapshot for the diff. If missing, usage for
    // every column today is NULL (nothing to compare against).
    $prevStmt = $pdo->prepare("SELECT * FROM {$cat['table']} WHERE reading_date = ?");
    $prevStmt->execute([$yesterday]);
    $prev = $prevStmt->fetch();

    $usage = [];
    foreach ($cols as $col) {
        $todayVal = $values[$col];
        $prevVal = $prev[$col] ?? null;

        if ($todayVal === null || $prevVal === null) {
            $usage[$col] = null;
            continue;
        }

        $diff = $todayVal - $prevVal;
        // Negative means the meter was reset/replaced between readings -
        // store NULL rather than a misleading negative "usage".
        $usage[$col] = $diff >= 0 ? $diff : null;
    }

    $insertUsage = $pdo->prepare(
        "INSERT IGNORE INTO {$cat['usage_table']} (reading_date, $colList) VALUES (?, $placeholders)"
    );
    $insertUsage->execute(array_merge([$today], array_values($usage)));

    echo "[$equipType] snapshot + usage written for $today" . PHP_EOL;
}
