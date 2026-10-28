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
     * Fast summary mode for Main Dashboard.
     *
     * Reports the last COMPLETED day, not today. The snapshot script writes a
     * row for today as soon as it runs, and that row is frozen from then on:
     * it does not grow through the day. Presenting it as "today" compares a
     * finished day against one still in progress, which reads as a sudden
     * drop every morning and says nothing about how the plant is doing now.
     *
     * So the reporting day is the newest row dated before today, the
     * comparison day is the one before that, and the 7-day window ends at
     * the reporting day.
     */
    if (isset($_GET['summary'])) {
        $summary = [
            'available'     => false,
            'report_date'   => null,
            'prev_date'     => null,
            'report_kwh'    => null,
            'prev_kwh'      => null,
            'is_yesterday'  => false,
            'categories'    => [],
            'daily_history' => [],
            'peak_day'      => null,
            'avg_daily_kwh' => null,
            'total_7d_kwh'  => null,
        ];

        try {
            /*
             * "Today" in plant time. The snapshot script pins itself to
             * Asia/Jakarta; this must agree with it, or a server running on
             * UTC would disagree for seven hours a day about which row is
             * today's and report the unfinished one again.
             */
            $plantNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            $today = $plantNow->format('Y-m-d');
            $yesterday = $plantNow->modify('-1 day')->format('Y-m-d');

            /*
             * Sum of every numeric unit column in a row. NULL when the row
             * carries no reading at all - distinct from a row of zeroes,
             * which is a real "nothing consumed".
             */
            $rowSum = static function (array $row): ?float {
                $vals = array_filter($row, fn($k) => $k !== 'reading_date', ARRAY_FILTER_USE_KEY);
                $numeric = array_filter($vals, 'is_numeric');

                return empty($numeric) ? null : round((float) array_sum($numeric), 1);
            };

            /*
             * Fourteen rows, not eight. Eight would exactly cover a 7-day
             * window plus its baseline only when every date is present; a
             * category with gaps would then reach back too few distinct dates
             * and the union window would silently shrink. The extra rows cost
             * nothing and the window is trimmed to seven dates below.
             */
            $catRows = [];
            $allDates = [];

            foreach ($categories as $catName => $catMeta) {
                $rows = $pdo->query(
                    "SELECT * FROM `{$catMeta['usage']}` ORDER BY reading_date DESC LIMIT 14"
                )->fetchAll(PDO::FETCH_ASSOC);

                $catRows[$catName] = $rows;

                foreach ($rows as $r) {
                    $allDates[$r['reading_date']] = true;
                }
            }

            $dates = array_keys($allDates);
            rsort($dates);

            $reportDate = null;
            $prevDate = null;

            foreach ($dates as $d) {
                if ($d >= $today) {
                    continue;
                }

                if ($reportDate === null) {
                    $reportDate = $d;
                } else {
                    $prevDate = $d;
                    break;
                }
            }

            /*
             * Only today's row exists yet (fresh install, or the snapshot has
             * just run for the first time): report it rather than an empty
             * card. The client labels it as the last day, not yesterday.
             */
            if ($reportDate === null && !empty($dates)) {
                $reportDate = $dates[0];
            }

            if ($reportDate !== null) {
                $reportSum = 0.0;
                $prevSum = 0.0;
                $daysMap = [];

                foreach ($categories as $catName => $catMeta) {
                    $reportRow = null;
                    $prevRow = null;

                    foreach ($catRows[$catName] as $r) {
                        if ($r['reading_date'] === $reportDate) {
                            $reportRow = $r;
                        } elseif ($r['reading_date'] === $prevDate) {
                            $prevRow = $r;
                        }
                    }

                    $cReport = $reportRow !== null ? $rowSum($reportRow) : null;
                    $cPrev = $prevRow !== null ? $rowSum($prevRow) : null;

                    if ($cReport !== null) {
                        $reportSum += $cReport;
                    }
                    if ($cPrev !== null) {
                        $prevSum += $cPrev;
                    }

                    $summary['categories'][$catName] = [
                        'label'  => $catMeta['label'],
                        'report' => $cReport,
                        'prev'   => $cPrev,
                    ];

                    /* Today's unfinished row never reaches the chart. */
                    foreach ($catRows[$catName] as $r) {
                        $d = $r['reading_date'];

                        if ($d > $reportDate) {
                            continue;
                        }

                        if (!isset($daysMap[$d])) {
                            $daysMap[$d] = ['date' => $d, 'kwh' => 0.0, 'by_cat' => []];
                        }

                        $valSum = $rowSum($r) ?? 0.0;
                        $daysMap[$d]['by_cat'][$catName] = $valSum;
                        $daysMap[$d]['kwh'] = round($daysMap[$d]['kwh'] + $valSum, 1);
                    }
                }

                /*
                 * Trim to the newest seven dates, then put them back in
                 * chronological order for the chart. The date window is taken
                 * before summing per day, so a category that lags behind the
                 * others cannot stretch the window past seven days.
                 */
                $windowDates = array_keys($daysMap);
                rsort($windowDates);
                $windowDates = array_slice($windowDates, 0, 7);
                sort($windowDates);

                $dailyHistory = [];
                $peak = null;
                $total7d = 0.0;

                foreach ($windowDates as $d) {
                    $item = $daysMap[$d];
                    $dt = DateTime::createFromFormat('Y-m-d', $d);

                    $item['label'] = $dt ? $dt->format('d M') : $d;
                    $item['short_day'] = $dt ? $dt->format('D') : '';
                    $item['is_latest'] = ($d === $reportDate);

                    $total7d += $item['kwh'];

                    if ($peak === null || $item['kwh'] > $peak['kwh']) {
                        $peak = [
                            'date'  => $d,
                            'label' => $item['label'],
                            'kwh'   => $item['kwh'],
                        ];
                    }

                    $dailyHistory[] = $item;
                }

                $summary['available']     = true;
                $summary['report_date']   = $reportDate;
                $summary['prev_date']     = $prevDate;
                $summary['report_kwh']    = round($reportSum, 1);
                $summary['prev_kwh']      = $prevDate !== null ? round($prevSum, 1) : null;
                $summary['is_yesterday']  = ($reportDate === $yesterday);
                $summary['daily_history'] = $dailyHistory;
                $summary['peak_day']      = $peak;
                $summary['total_7d_kwh']  = round($total7d, 1);
                $summary['avg_daily_kwh'] = count($dailyHistory) > 0
                    ? round($total7d / count($dailyHistory), 1)
                    : 0.0;
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
