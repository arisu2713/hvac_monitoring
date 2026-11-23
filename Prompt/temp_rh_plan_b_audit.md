# TEMP & RH PLAN B AUDIT REPORT

**Project**: HVAC Monitoring System  
**Audit Target**: Migration of Hourly Temp/RH Snapshot from standalone PHP script (`temp_rh_snapshot.php`) to C# BACnet Poller (`C:\Projects\azbil_bacnet\Program.cs`).  
**Audit Scope**: Read-only inspection of source code, live database schema, and existing snapshot data.

---

## 1. Audit of `temp_rh_snapshot.php`

### Execution & Trigger Context
- **Script Type**: CLI PHP script (`PHP_SAPI === 'cli'`). Rejects HTTP requests with HTTP 404.
- **Current Execution**: Intended to run via system/cron scheduler at minute `0` of every hour (`0 * * * *`).
- **Timezone & Hour Normalization**:
  - Explicitly sets PHP timezone: `date_default_timezone_set('Asia/Jakarta')` (WIB, UTC+7).
  - Explicitly sets MySQL session timezone: `SET time_zone = '+07:00'`.
  - Normalizes snapshot timestamp to top of the hour: `$recordedAt = date('Y-m-d H:00:00')`.

### Data Source & Selection
- Queries `hvac_current.points` joined with `hvac_current.ai_current`:
  ```sql
  SELECT p.point_id, p.point_name, p.device_id, a.value, a.read_at
  FROM points p
  JOIN ai_current a ON a.point_id = p.point_id
  WHERE p.equip_type = 'ROOM TEMP & RH'
    AND p.obj_type = 'AI'
  ```

### Parsing & Classification Rules
1. **Room Points** (Regex: `/^ROOM\s+(TEMP|RH)\s+(.+)$/i`):
   - `room_type` = `'ROOM'`
   - `metric` = `'temp'` (if `TEMP`) or `'rh'` (if `RH`)
   - `room_name` = `strtoupper(trim($m[2]))` (e.g. `1056`, `OVC`, `SE 41`, `8302`, `1422`, `20014`, `20110`, `9652`)
2. **Outdoor Points** (Regex: `/^OUTDOOR\s+(TEMPERATURE|HUMIDITY)\s+(.+)$/i`):
   - `room_type` = `'OUTDOOR'`
   - `metric` = `'temp'` (if `TEMPERATURE`) or `'rh'` (if `HUMIDITY`)
   - `room_name` = `strtoupper(trim($m[2]))` (e.g. `G1`, `G3`)
3. **Unrecognized Points**:
   - Logged to standard output and skipped (`[skip] unrecognised point_name`).
4. **Units & Null Handling**:
   - `unit` = `'C'` for `temp`, `'%RH'` for `rh`.
   - Skips points where `read_at` is `NULL`.
   - Preserves `value` as `float` (or `null` if value is `NULL`).

### Database Insertion
- Target table: `temp_rh_hourly`
- Query:
  ```sql
  INSERT IGNORE INTO temp_rh_hourly
    (recorded_at, point_id, device_id, point_name, room_type, room_name, metric, unit, value, read_at)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ```
- **Deduplication**: Relies on `INSERT IGNORE` with Primary Key `(recorded_at, point_id)`. Multiple executions in the same hour result in `0` inserted rows without throwing an exception.

---

## 2. Audit of Existing `temp_rh_hourly` Schema & Live Data

### Database Schema Definition
```sql
CREATE TABLE `temp_rh_hourly` (
  `recorded_at` datetime NOT NULL COMMENT 'Snapshot hour, WIB, truncated to :00:00',
  `point_id` varchar(32) NOT NULL COMMENT 'points.point_id - the real identity',
  `device_id` int(11) NOT NULL COMMENT 'points.device_id - disambiguates same label on different devices',
  `point_name` varchar(128) NOT NULL COMMENT 'Raw points.point_name at snapshot time (audit trail)',
  `room_type` varchar(8) NOT NULL COMMENT 'ROOM | OUTDOOR',
  `room_name` varchar(64) NOT NULL COMMENT 'Normalised label only: 1056, OVC, SE 41, G1. Display name = room_type + " " + room_name',
  `metric` varchar(4) NOT NULL COMMENT 'temp | rh',
  `unit` varchar(8) NOT NULL COMMENT 'C | %RH',
  `value` float DEFAULT NULL COMMENT 'Value as-is; NULL means no reading, never 0',
  `read_at` datetime NOT NULL COMMENT 'Actual ai_current.read_at - shows stale/frozen poller',
  PRIMARY KEY (`recorded_at`,`point_id`),
  KEY `idx_room_metric_time` (`room_name`,`metric`,`recorded_at`),
  KEY `idx_point_time` (`point_id`,`recorded_at`),
  CONSTRAINT `chk_metric` CHECK (`metric` in ('temp','rh'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### Empirical Database Findings
- **Total Rows**: 40 rows currently in `hvac_current.temp_rh_hourly`.
- **Recorded Timestamps**:
  - `2026-10-01 09:00:00` (20 points)
  - `2026-10-01 12:00:00` (20 points)
- **Point Inventory**:
  - Exactly 20 `ROOM TEMP & RH` AI points exist in `points` table (10 `temp`, 10 `rh`).
  - Devices: `10202` (8 points), `10203` (12 points).
  - All 20 points currently pass the parsing rules without any skipped records.

---

## 3. Audit of `C:\Projects\azbil_bacnet\Program.cs`

### Current Loop & Timing Architecture
- **Tick Rate**: 5 seconds (`TICK_SECONDS = 5`).
- **Main Loop Functions**:
  1. `LoadPoints()`: Reads `AI`, `BI`, `ACC` points from `hvac_current.points`.
  2. `ReadAll(points)`: Polls BACnet controllers over UDP.
  3. `SaveCurrent(points, now)`: Writes live readings to `ai_current`, `bi_current`, `acc_current`.
  4. `SaveAlarmEvents(points, now)`: Phase 2B Global Alarm evaluation & writing.
  5. `RunDailyKwh(...)`: Scheduled daily kWh snapshot (runs inside minute `00:00`).
  6. `SaveHistory(points, now)`: High-frequency history write every 60s (`tick % 12 == 0`) to `ai_readings`.
  7. `SaveCurrentRemote(points, now)`: Syncs live values to Hostinger remote MySQL database every 30s.

### System Isolation & Error Safety
- Each subsystem (`SaveCurrent`, `SaveAlarmEvents`, `RunDailyKwh`, `SaveHistory`, `SaveCurrentRemote`) runs inside its own `try / catch` block with an independent database connection/transaction.
- A failure in any one subsystem logs an error line to stdout and never prevents the poller from executing subsequent subsystems or continuing BACnet polling.

---

## 4. Plan B Technical Design: Moving Snapshot to BACnet Poller

### Architectural Fit in `Program.cs`
By moving the hourly snapshot into `Program.cs`, the requirement for external PHP cron tasks is eliminated. The C# poller directly captures the hourly snapshot in memory immediately after polling BACnet and updating `ai_current`.

### Proposed Implementation Details (for future execution)
1. **Trigger Condition**:
   - Track `string tempRhHourLocal = ""` and `string tempRhHourRemote = ""`.
   - On each tick inside minute 0 (`now.Minute == 0`), check if `$"{now:yyyy-MM-dd HH}"` differs from `tempRhHourLocal`.
2. **In-Memory Parsing in C#**:
   - Reuse/extend compiled `Regex` matchers in `Program.cs`:
     - `RxRoomTemp`: `^ROOM\s+TEMP\s+(.+)$`
     - `RxRoomRh`: `^ROOM\s+RH\s+(.+)$`
     - `RxOutdoorTemp`: `^OUTDOOR\s+TEMPERATURE\s+(.+)$`
     - `RxOutdoorRh`: `^OUTDOOR\s+HUMIDITY\s+(.+)$`
3. **Database Write**:
   - Construct parameterized `INSERT IGNORE INTO temp_rh_hourly (...)` statements for both local MariaDB and (optionally) remote Hostinger MySQL (`u468140406_hvac.temp_rh_hourly`).
   - Standardized `recorded_at`: `$"{now:yyyy-MM-dd HH:00:00}"`.
4. **Safety Isolation**:
   - Isolated inside `RunHourlyTempRh(points, now)` wrapped in a `try/catch` block.

---

## 5. Audit Compliance Checklist

| Constraint Rule | Status | Confirmation |
|---|---|---|
| Do NOT modify any source file | **PASSED** | Codebase untouched. |
| Do NOT modify DB/schema/data | **PASSED** | Only read-only `SELECT` / `DESCRIBE` queries issued. |
| Do NOT modify Program.cs | **PASSED** | `Program.cs` untouched. |
| Do NOT modify Phase 2B | **PASSED** | Phase 2B global alarm code untouched. |
| Do NOT modify KWH/ACC | **PASSED** | KWH/ACC code untouched. |
| Do NOT deploy | **PASSED** | No deployment actions performed. |
| Do NOT restart services | **PASSED** | No services restarted. |
| Do NOT commit / push | **PASSED** | No git operations performed. |
| Do NOT execute temp_rh_snapshot.php | **PASSED** | Script was NOT executed. |
| Do NOT invent mappings | **PASSED** | All rules derived directly from source code & schema. |

`AUDIT ONLY — REPORT CREATED`
