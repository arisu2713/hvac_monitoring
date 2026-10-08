<?php
// Hourly snapshot of the ROOM TEMP & RH points into temp_rh_hourly.
// Run once per hour via hosting cron job (Hostinger has no Event Scheduler
// privilege on shared hosting, so this must be a scheduled PHP script,
// not a MariaDB EVENT).
//
// What it does, once per run:
//   1. Reads every ROOM TEMP & RH point (points JOIN ai_current).
//   2. Parses point_name into room_type / room_name / metric using the same
//      rules as api_room.php - the parsing lives in PHP, not in SQL, so
//      labels like "SE 41" do not need fragile SUBSTRING_INDEX logic.
//   3. Inserts one long-format row per point for the current hour.
//      INSERT IGNORE + PRIMARY KEY (recorded_at, point_id) makes a
//      double run in the same hour a no-op instead of a duplicate.
//
// Same file runs against both databases - local (hvac_current) and hosting
// (u468140406_hvac). Which one it talks to is decided entirely by
// config.php, so there is no environment switch here.
//
// CLI only - not reachable over HTTP.
//
// NOTE: ai_current is overwritten in place (it is a "current value" table,
// not history), so this script can only ever capture one instantaneous
// sample per hour. Per-hour min/max/avg cannot be reconstructed later
// without the poller keeping its own history - do not pretend otherwise.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// recorded_at must be the WIB wall-clock hour, matching how read_at is
// stored. On hosting the server itself is UTC, so the timezone has to be
// pinned explicitly rather than inherited.
date_default_timezone_set('Asia/Jakarta');

$config = require __DIR__ . '/config.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Same reason as date_default_timezone_set() above: read_at is naive WIB
// datetime, so the session that writes it must not run in the server's
// own (UTC) zone.
$pdo->exec("SET time_zone = '+07:00'");

// Truncate to the hour, not the raw execution time, so the row lands on a
// clean :00:00 boundary no matter when in the hour cron actually fires.
$recordedAt = date('Y-m-d H:00:00');

$stmt = $pdo->query("
    SELECT
        p.point_id,
        p.point_name,
        p.device_id,
        a.value,
        a.read_at
    FROM points p
    JOIN ai_current a ON a.point_id = p.point_id
    WHERE p.equip_type = 'ROOM TEMP & RH'
      AND p.obj_type = 'AI'
");
$rows = $stmt->fetchAll();

$insert = $pdo->prepare("
    INSERT IGNORE INTO temp_rh_hourly
        (recorded_at, point_id, device_id, point_name, room_type, room_name, metric, unit, value, read_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$inserted = 0;
$skipped  = 0;

foreach ($rows as $row) {
    $pointName = trim($row['point_name'] ?? '');

    // Identical patterns to api_room.php, so a point that renders on the
    // page is a point that gets stored here - and vice versa.
    if (preg_match('/^ROOM\s+(TEMP|RH)\s+(.+)$/i', $pointName, $m)) {
        $roomType = 'ROOM';
        $metric   = strtoupper($m[1]) === 'TEMP' ? 'temp' : 'rh';
        $roomName = strtoupper(trim($m[2]));
    } elseif (preg_match('/^OUTDOOR\s+(TEMPERATURE|HUMIDITY)\s+(.+)$/i', $pointName, $m)) {
        $roomType = 'OUTDOOR';
        $metric   = strtoupper($m[1]) === 'TEMPERATURE' ? 'temp' : 'rh';
        $roomName = strtoupper(trim($m[2]));
    } else {
        // Unrecognised label - skip rather than guess a room/metric.
        // Still reported below so a new naming convention does not go
        // unnoticed.
        echo "[skip] unrecognised point_name: {$row['point_id']} '{$pointName}'" . PHP_EOL;
        continue;
    }

    $unit = $metric === 'temp' ? 'C' : '%RH';

    // read_at is NOT NULL in ai_current, but a LEFT-vs-INNER JOIN change
    // upstream should surface as a visible error, not a silent bad row.
    if ($row['read_at'] === null) {
        echo "[skip] missing read_at: {$row['point_id']}" . PHP_EOL;
        continue;
    }

    $insert->execute([
        $recordedAt,
        $row['point_id'],
        (int)$row['device_id'],
        $pointName,
        $roomType,
        $roomName,
        $metric,
        $unit,
        $row['value'] !== null ? (float)$row['value'] : null,
        $row['read_at'],
    ]);

    if ($insert->rowCount() > 0) {
        $inserted++;
    } else {
        $skipped++;
    }
}

echo "[ROOM TEMP & RH] $recordedAt: $inserted inserted, $skipped already present, "
    . count($rows) . ' points read' . PHP_EOL;
