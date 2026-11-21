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

session_write_close();

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
$tz = $config['telemetry_source_tz'] ?? 'Asia/Jakarta';

/*
 * Phase 2 Tahap B: server_epoch dan source_tz_offset untuk waktu relatif lintas zona waktu.
 * Offset zona telemetri dihitung dari config 'telemetry_source_tz' (default Asia/Jakarta).
 */
try {
    $sourceTzOffset = (new DateTime('now', new DateTimeZone($tz)))->format('P');
} catch (Throwable $e) {
    $sourceTzOffset = '+07:00';
}
$loggedNegativeEf = false;
$loggedNegativeG8 = false;

/*
 * Koreksi Phase 2: Perhitungan age_seconds di PHP dengan zona waktu eksplisit ($tz).
 * Memecahkan masalah timestamp DB Hostinger berzona UTC vs data poller berjam Jakarta.
 */
if (!function_exists('calcTelemetryAgeSeconds')) {
    function calcTelemetryAgeSeconds(?string $lastUpdate, string $tz = 'Asia/Jakarta', ?bool &$loggedNegative = null): ?int {
        if ($lastUpdate === null || trim($lastUpdate) === '') {
            return null;
        }
        try {
            $dt = new DateTime($lastUpdate, new DateTimeZone($tz));
            $age = time() - $dt->getTimestamp();
            if ($age >= 0) {
                return (int)$age;
            }
            // Usia negatif kecil (>= -5 detik) dibulatkan 0 (toleransi clock skew minor)
            if ($age >= -5) {
                return 0;
            }
            // Usia negatif lebih besar -> null dan catat error_log sekali per request
            if ($loggedNegative === null || $loggedNegative === false) {
                $loggedNegative = true;
                error_log(sprintf('[%s] Telemetry timestamp from future detected (%s in tz %s, age %d s)', basename(__FILE__), $lastUpdate, $tz, $age));
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

/*
 * Helper validasi kualitas status titik Niagara / Daikin:
 * - 'ok': status ok
 * - 'stale': status stale (nilai metrik tetap dikirim tetapi ditandai stale)
 * - 'bad': down, fault, null, atau status lainnya (nilai dibuang menjadi null)
 */
if (!function_exists('classifyPointStatus')) {
    function classifyPointStatus(?string $status): string {
        if ($status === null) {
            return 'bad';
        }
        $s = strtolower(trim($status));
        if ($s === 'ok') {
            return 'ok';
        }
        if ($s === 'stale') {
            return 'stale';
        }
        return 'bad';
    }
}

if (!function_exists('isPointStatusOk')) {
    function isPointStatusOk(?string $status): bool {
        return $status !== null && strtolower(trim($status)) === 'ok';
    }
}

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
     * Query ef_current: TIMESTAMPDIFF dihapus dari SQL (dihitung di PHP berbasis $tz).
     */
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
        /* Koreksi: hitung age_seconds di PHP dengan zona waktu $tz */
        $ageSec = calcTelemetryAgeSeconds($row['last_update'], $tz, $loggedNegativeEf);
        $exhaustFan[] = [
            'id' => (int)$row['ef_point_id'],
            'panel_no' => (int)$row['panel_no'],
            'name' => $row['name'],
            'status' => $row['status'] !== null
                ? (int)$row['status']
                : null,
            'last_update' => $row['last_update'],
            'age_seconds' => $ageSec,
        ];
    }

    /*
     * Exhaust Fan G8 — EF 58-61.
     *
     * These four fans belong to the G8 building and are collected by the
     * separate Daikin / Niagara poller into hvac_current.daikin_current, NOT
     * into ef_points / ef_current. They therefore cannot join the query above.
     *
     * Deliberately returned under their OWN key, EXHAUST_FAN_G8, never mixed
     * into EXHAUST_FAN: the frontend groups EXHAUST_FAN by panel_no, so a
     * separate key is what structurally guarantees EF 58-61 can never be
     * absorbed into Panel 1-9.
     *
     * The EF number comes from daikin_current.equipment_no (58/59/60/61), which
     * is the identity the poller maintains. daikin_point_id is carried along
     * for traceability against the point master.
     *
     * value_bool is the ON/OFF status: 1 = running, 0 = off, NULL = unknown.
     *
     * Each G8 fan has three rows in daikin_current — Sts_EF1/Sts_EF2 (status),
     * FreqOUT (Hz) and MotorCurrent (A) — so the rows are grouped by
     * equipment_no and the values pulled with conditional aggregation. That
     * keeps ONE output row per fan, which is what the Sts_EF1/Sts_EF2 filter
     * below was originally protecting: filtering the rows instead would have
     * dropped the FreqOUT/MotorCurrent values along with the duplicate cards.
     *
     * The status point is named Sts_EF1 on EF 58/60 and Sts_EF2 on EF 59/61;
     * both names are listed so every fan contributes a status. This mirrors the
     * point_name filter api_ahu.php applies to the Daikin AHU 96-97 rows, which
     * share the same table and the same problem.
     *
     * FreqOUT and MotorCurrent are the live points the poller actually writes
     * (verified against daikin_current). They are NOT aliased into a single
     * `frequency` / `current` field: those two names already mean "the panel's
     * own frequency / current" elsewhere in this API's sibling pages, and
     * reusing them here would silently merge two different sources.
     *
     * The NULL -> 'unknown' handling below is deliberately unchanged: a status
     * point that has no value still renders as an unknown (grey) card, and a
     * fan with no frequency or current keeps those values NULL rather than 0.
     */
    $exhaustFanG8 = [];

    /*
     * Query daikin_current: TIMESTAMPDIFF dihapus dari SQL (dihitung di PHP berbasis $tz).
     */
    /*
     * Query daikin_current: SELECT kolom status ditambahkan untuk validasi kualitas titik (Phase 2 Tahap A).
     * Phase 2 Tahap 1: Tambahkan Sts_TripEF1/Sts_TripEF2 untuk alarm proteksi trip EF 58-61.
     */
    $g8Sql = "
        SELECT
            equipment_no,
            MAX(CASE WHEN point_name IN ('Sts_EF1', 'Sts_EF2') THEN point_id END) AS status_point_id,
            MAX(CASE WHEN point_name IN ('Sts_EF1', 'Sts_EF2') THEN value_bool END) AS value_bool,
            MAX(CASE WHEN point_name IN ('Sts_EF1', 'Sts_EF2') THEN status END) AS unit_status_raw,
            MAX(CASE WHEN point_name = 'FreqOUT' THEN value_double END) AS frequency,
            MAX(CASE WHEN point_name = 'FreqOUT' THEN status END) AS freq_status_raw,
            MAX(CASE WHEN point_name = 'MotorCurrent' THEN value_double END) AS motor_current,
            MAX(CASE WHEN point_name = 'MotorCurrent' THEN status END) AS current_status_raw,
            MAX(CASE WHEN point_name IN ('Sts_TripEF1', 'Sts_TripEF2') THEN point_id END) AS trip_point_id,
            MAX(CASE WHEN point_name IN ('Sts_TripEF1', 'Sts_TripEF2') THEN value_bool END) AS trip_value_bool,
            MAX(CASE WHEN point_name IN ('Sts_TripEF1', 'Sts_TripEF2') THEN status END) AS trip_status_raw,
            MAX(last_update) AS last_update
        FROM daikin_current
        WHERE equipment_type = 'EF'
          AND equipment_no IN ('58', '59', '60', '61')
          AND point_name IN ('Sts_EF1', 'Sts_EF2', 'FreqOUT', 'MotorCurrent', 'Sts_TripEF1', 'Sts_TripEF2')
        GROUP BY equipment_no
        ORDER BY CAST(equipment_no AS UNSIGNED)
    ";

    foreach ($pdo->query($g8Sql)->fetchAll() as $row) {
        /* Koreksi: hitung age_seconds di PHP dengan zona waktu $tz */
        $ageSec = calcTelemetryAgeSeconds($row['last_update'], $tz, $loggedNegativeG8);

        /*
         * Perbaikan regresi Phase 2: Kualitas titik Niagara daikin_current per kategori titik:
         * 1. Titik Status Unit (Sts_EF1/Sts_EF2):
         *    Aturan ketat tetap: selain 'ok' (termasuk 'stale', 'down', 'fault', NULL) -> tidak diketahui (null).
         * 2. Titik Metrik (FreqOUT, MotorCurrent):
         *    'ok'    -> nilai normal, point_quality 'ok'
         *    'stale' -> nilai TETAP dikirim (tidak null), point_quality 'stale'
         *    'bad'   -> nilai null, point_quality 'bad'
         * 3. Titik Trip (Sts_TripEF1/Sts_TripEF2):
         *    Aturan ketat tetap: selain 'ok' -> null (unknown).
         */
        $statusPointRaw = $row['unit_status_raw'] !== null ? trim($row['unit_status_raw']) : null;
        $statusPointQuality = classifyPointStatus($statusPointRaw);
        $statusPointOk = ($statusPointQuality === 'ok');
        $unitQuality = $statusPointOk ? 'ok' : 'bad';
        $unitStatusVal = ($statusPointOk && $row['value_bool'] !== null)
            ? (int)$row['value_bool']
            : null;

        $freqPointRaw = $row['freq_status_raw'] !== null ? trim($row['freq_status_raw']) : null;
        $freqQuality = classifyPointStatus($freqPointRaw);
        $freqVal = ($freqQuality !== 'bad' && $row['frequency'] !== null)
            ? (float)$row['frequency']
            : null;

        $currentPointRaw = $row['current_status_raw'] !== null ? trim($row['current_status_raw']) : null;
        $currentQuality = classifyPointStatus($currentPointRaw);
        $currentVal = ($currentQuality !== 'bad' && $row['motor_current'] !== null)
            ? (float)$row['motor_current']
            : null;

        $tripPointRaw = $row['trip_status_raw'] !== null ? trim($row['trip_status_raw']) : null;
        $tripQuality = classifyPointStatus($tripPointRaw);
        $tripPointOk = ($tripQuality === 'ok');
        $tripVal = ($tripPointOk && $row['trip_value_bool'] !== null)
            ? (int)$row['trip_value_bool']
            : null;

        $exhaustFanG8[] = [
            'id' => $row['status_point_id'] !== null
                ? (int)$row['status_point_id']
                : null,
            'daikin_point_id' => $row['status_point_id'] !== null
                ? (int)$row['status_point_id']
                : null,
            // Not part of the existing Panel 1-9 grouping; the G8 groupbox
            // renders these as a single flat group.
            'panel_no' => null,
            'name' => 'EF ' . (int)$row['equipment_no'],
            'status' => $unitStatusVal,
            'frequency' => $freqVal,
            'motor_current' => $currentVal,
            'trip' => $tripVal,
            'last_update' => $row['last_update'],
            'age_seconds' => $ageSec,
            /* Phase 2 Tahap A: Penanda kualitas data per unit dan per titik */
            'quality' => $unitQuality,
            'raw_status' => $statusPointRaw,
            'point_quality' => [
                'status' => $statusPointQuality,
                'frequency' => $freqQuality,
                'motor_current' => $currentQuality,
                'trip' => $tripQuality,
            ],
        ];
    }

    echo json_encode([
        'success' => true,
        'server_time' => date('Y-m-d H:i:s'),
        /* Phase 2 Tahap B: server_epoch dan source_tz_offset di akar respons */
        'server_epoch' => time(),
        'source_tz_offset' => $sourceTzOffset,
        'equipment' => [
            'EXHAUST_FAN' => $exhaustFan,
            'EXHAUST_FAN_G8' => $exhaustFanG8
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
    ], JSON_UNESCAPED_UNICODE);
}
