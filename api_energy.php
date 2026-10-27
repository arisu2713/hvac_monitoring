<?php

/*
 * Energy endpoint — daily kWh history.
 *
 * Reads the six snapshot tables written by kwh_daily_snapshot.php:
 *
 *   kwh_total_<cat>   cumulative ACC meter reading at the end of the day
 *   kwh_usage_<cat>   that day's consumption (today - yesterday), computed
 *                     by the snapshot script; NULL means "not derivable"
 *                     (missing previous day, or a meter reset)
 *
 * Wide format: one column per unit, reading_date is the primary key. The
 * unit columns are read from the database rather than hardcoded, because
 * CT has units 1-6 and 9-13 (there is no 7 or 8).
 *
 * Read-only: SELECT only.
 */

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
 * Category whitelist. Table names are taken from HERE and never from the
 * request, so no user input can ever reach a table position in the SQL.
 * An unknown ?category= falls back to the default rather than erroring.
 */
$categories = [
    'CT'   => ['label' => 'CT',   'total' => 'kwh_total_ct',   'usage' => 'kwh_usage_ct',   'prefix' => 'ct_'],
    'CCP'  => ['label' => 'CCP',  'total' => 'kwh_total_ccp',  'usage' => 'kwh_usage_ccp',  'prefix' => 'ccp_'],
    'CHWP' => ['label' => 'CHWP', 'total' => 'kwh_total_chwp', 'usage' => 'kwh_usage_chwp', 'prefix' => 'chwp_'],
];

$catKey = strtoupper(trim($_GET['category'] ?? 'CT'));

if (!isset($categories[$catKey])) {
    $catKey = 'CT';
}

$cat = $categories[$catKey];

/* A date is accepted only in strict YYYY-MM-DD form; anything else is dropped. */
function valid_date(?string $value): ?string
{
    if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
        return null;
    }

    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
}

$fromParam = valid_date($_GET['from'] ?? null);
$toParam   = valid_date($_GET['to'] ?? null);

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

    /*
     * Fast summary mode for Main Dashboard:
     * Returns today's and yesterday's energy totals across CT, CCP, and CHWP
     * without querying or transferring 31 days of historical readings.
     */
    if (isset($_GET['summary'])) {
        $summary = [
            'available'      => false,
            'today_date'     => null,
            'yesterday_date' => null,
            'today_kwh'      => null,
            'yesterday_kwh'  => null,
            'categories'     => []
        ];

        try {
            $todaySum = 0.0;
            $yesterdaySum = 0.0;
            $hasData = false;

            foreach ($categories as $catName => $catMeta) {
                $tableName = $catMeta['usage'];
                $stmt = $pdo->query("SELECT * FROM `{$tableName}` ORDER BY reading_date DESC LIMIT 2");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $cToday = null;
                $cYest = null;

                if (!empty($rows[0])) {
                    $hasData = true;
                    if ($summary['today_date'] === null) {
                        $summary['today_date'] = $rows[0]['reading_date'];
                    }
                    $vals = array_filter($rows[0], fn($k) => $k !== 'reading_date', ARRAY_FILTER_USE_KEY);
                    $numericVals = array_filter($vals, 'is_numeric');
                    if (!empty($numericVals)) {
                        $cToday = round((float)array_sum($numericVals), 1);
                        $todaySum += $cToday;
                    }
                }

                if (!empty($rows[1])) {
                    if ($summary['yesterday_date'] === null) {
                        $summary['yesterday_date'] = $rows[1]['reading_date'];
                    }
                    $vals = array_filter($rows[1], fn($k) => $k !== 'reading_date', ARRAY_FILTER_USE_KEY);
                    $numericVals = array_filter($vals, 'is_numeric');
                    if (!empty($numericVals)) {
                        $cYest = round((float)array_sum($numericVals), 1);
                        $yesterdaySum += $cYest;
                    }
                }

                $summary['categories'][$catName] = [
                    'label'     => $catMeta['label'],
                    'today'     => $cToday,
                    'yesterday' => $cYest,
                ];
            }

            if ($hasData) {
                $summary['available']     = true;
                $summary['today_kwh']     = round($todaySum, 1);
                $summary['yesterday_kwh'] = round($yesterdaySum, 1);
            }

            echo json_encode([
                'success'     => true,
                'server_time' => date('Y-m-d H:i:s'),
                'summary'     => $summary
            ], JSON_UNESCAPED_UNICODE);
            exit;

        } catch (Throwable $e) {
            error_log('[' . basename(__FILE__) . '] summary error: ' . $e->getMessage());
            echo json_encode([
                'success'     => true,
                'server_time' => date('Y-m-d H:i:s'),
                'summary'     => $summary
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /*
     * Unit columns come from information_schema for the whitelisted table.
     * Each name is re-validated against a strict pattern before it is used
     * in the SELECT list below — a column name is still SQL text, so it is
     * checked even though its source is the database itself.
     */
    $colStmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :t
          AND column_name <> 'reading_date'
    ");
    $colStmt->execute([':t' => $cat['total']]);

    $units = [];

    foreach ($colStmt as $row) {
        $col = $row['column_name'];

        if (!preg_match('/^[a-z]+_(\d+)$/', $col, $m)) {
            continue;
        }

        if (strpos($col, $cat['prefix']) !== 0) {
            continue;
        }

        $units[] = ['column' => $col, 'unit' => (int) $m[1]];
    }

    usort($units, fn($a, $b) => $a['unit'] <=> $b['unit']);

    /*
     * Device id per unit, so the page can offer an area filter.
     *
     * The snapshot tables store one column per unit and carry no device
     * column, but the ACC point master does: every unit's kWh total is a
     * "CT 1 KWh Total"-style point with a device_id. Reading it here keeps
     * the device -> unit relationship in the database instead of duplicating
     * it in the page.
     *
     * Read only, and only for the selected category. A unit with no matching
     * ACC point gets null rather than a guessed device.
     */
    $deviceByUnit = [];

    $pointStmt = $pdo->prepare("
        SELECT point_name, device_id
        FROM points
        WHERE obj_type = 'ACC'
          AND equip_type = :t
    ");
    $pointStmt->execute([':t' => $catKey]);

    foreach ($pointStmt as $point) {
        if (!preg_match('/^' . preg_quote($catKey, '/') . '\s+(\d+)\s+KWh Total$/i', trim($point['point_name']), $m)) {
            continue;
        }

        $deviceByUnit[(int) $m[1]] = $point['device_id'] !== null
            ? (int) $point['device_id']
            : null;
    }

    /* Available range drives the default window and the UI date bounds. */
    $range = $pdo->query(
        "SELECT MIN(reading_date) AS min_date, MAX(reading_date) AS max_date
         FROM {$cat['total']}"
    )->fetch();

    $minDate = $range['min_date'];
    $maxDate = $range['max_date'];

    if ($maxDate === null) {
        /* No snapshots yet: a valid, empty answer rather than an error. */
        echo json_encode([
            'success'     => true,
            'category'    => $catKey,
            'categories'  => array_keys($categories),
            'available'   => ['min' => null, 'max' => null],
            'from'        => null,
            'to'          => null,
            'units'       => [],
            'days'        => [],
        ]);
        exit;
    }

    /*
     * Default window: the last 31 days of data. If a requested bound is
     * absent it is filled from the data, and the two are ordered so a
     * reversed range cannot silently return nothing.
     */
    $to   = $toParam   ?? $maxDate;
    $from = $fromParam ?? date('Y-m-d', strtotime($to . ' -30 days'));

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    if (empty($units)) {
        echo json_encode([
            'success'    => true,
            'category'   => $catKey,
            'categories' => array_keys($categories),
            'available'  => ['min' => $minDate, 'max' => $maxDate],
            'from'       => $from,
            'to'         => $to,
            'units'      => [],
            'days'       => [],
        ]);
        exit;
    }

    $select = [];
    $bind   = [];

    foreach ($units as $u) {
        $col = $u['column'];
        /* total and usage live in two tables with identical column names. */
        $select[] = "t.`{$col}` AS `t_{$col}`";
        $select[] = "u.`{$col}` AS `u_{$col}`";
    }

    $sql = "
        SELECT t.reading_date, " . implode(",\n               ", $select) . "
        FROM {$cat['total']} t
        LEFT JOIN {$cat['usage']} u ON u.reading_date = t.reading_date
        WHERE t.reading_date BETWEEN :from AND :to
        ORDER BY t.reading_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':from' => $from, ':to' => $to]);

    $days = [];

    foreach ($stmt as $row) {
        $values = [];
        $totalSum = null;
        $usageSum = null;

        foreach ($units as $u) {
            $col = $u['column'];

            $total = $row['t_' . $col];
            $usage = $row['u_' . $col];

            $total = $total === null ? null : (float) $total;
            $usage = $usage === null ? null : (float) $usage;

            $values[(string) $u['unit']] = ['total' => $total, 'usage' => $usage];

            if ($total !== null) {
                $totalSum = ($totalSum ?? 0.0) + $total;
            }

            if ($usage !== null) {
                $usageSum = ($usageSum ?? 0.0) + $usage;
            }
        }

        $days[] = [
            'date'      => $row['reading_date'],
            'values'    => $values,
            'sum_total' => $totalSum,
            'sum_usage' => $usageSum,
        ];
    }

    echo json_encode([
        'success'    => true,
        'category'   => $catKey,
        'categories' => array_keys($categories),
        'available'  => ['min' => $minDate, 'max' => $maxDate],
        'from'       => $from,
        'to'         => $to,
        'units'      => array_map(
            fn($u) => [
                'unit'   => $u['unit'],
                'device' => $deviceByUnit[$u['unit']] ?? null,
            ],
            $units
        ),
        'days'       => $days,
    ]);
} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error',
    ]);
}
