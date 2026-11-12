-- chiller_setpoint_hourly - hourly Chiller Setpoint (°C) and Active Setpoint RLA (%) telemetry.
--
-- Populated once per hour by the azbil_bacnet poller service.
-- Read by api_chiller_setpoint.php to display current snapshot and 90-hour history.
--
-- Target databases:
--   local  : hvac_current      (XAMPP, 127.0.0.1)
--   remote : u468140406_hvac   (Hostinger)

CREATE TABLE IF NOT EXISTS `chiller_setpoint_hourly` (
  `timestamp` datetime NOT NULL,
  `point_id` varchar(32) NOT NULL,
  `equip_type` varchar(50) NOT NULL,
  `point_name` varchar(100) NOT NULL,
  `value` float DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`timestamp`,`point_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
