-- temp_rh_hourly - hourly ROOM TEMP & RH history, long/normal format.
--
-- Long format (one row per point per hour) instead of wide (one column per
-- room) on purpose: adding a room later just means new rows appearing from
-- `points`, with zero DDL and no migration of existing history.
--
-- Written to once per hour by temp_rh_snapshot.php (INSERT IGNORE), never
-- updated or deleted.
--
-- Run this against BOTH databases:
--   local  : hvac_current      (XAMPP, 127.0.0.1)
--   remote : u468140406_hvac   (Hostinger, srv1763.hstgr.io)
-- On hosting, run it via phpMyAdmin -> SQL tab (same as the schema import
-- done earlier). The file is blocked from HTTP by .htaccess, but keep it
-- out of the webroot anyway if that is easy.

CREATE TABLE IF NOT EXISTS `temp_rh_hourly` (
  `recorded_at` datetime     NOT NULL COMMENT 'Snapshot hour, WIB, truncated to :00:00',
  `point_id`    varchar(32)  NOT NULL COMMENT 'points.point_id - the real identity',
  `device_id`   int(11)      NOT NULL COMMENT 'points.device_id - disambiguates same label on different devices',
  `point_name`  varchar(128) NOT NULL COMMENT 'Raw points.point_name at snapshot time (audit trail)',
  `room_type`   varchar(8)   NOT NULL COMMENT 'ROOM | OUTDOOR',
  `room_name`   varchar(64)  NOT NULL COMMENT 'Normalised label only: 1056, OVC, SE 41, G1. Display name = room_type + " " + room_name',
  `metric`      varchar(4)   NOT NULL COMMENT 'temp | rh',
  `unit`        varchar(8)   NOT NULL COMMENT 'C | %RH',
  `value`       float        DEFAULT NULL COMMENT 'Value as-is; NULL means no reading, never 0',
  `read_at`     datetime     NOT NULL COMMENT 'Actual ai_current.read_at - shows stale/frozen poller',
  PRIMARY KEY (`recorded_at`, `point_id`),
  KEY `idx_room_metric_time` (`room_name`, `metric`, `recorded_at`),
  KEY `idx_point_time` (`point_id`, `recorded_at`),
  CONSTRAINT `chk_metric` CHECK (`metric` IN ('temp', 'rh'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
