-- ============================================================================
-- HVAC MONITORING — UNIT PREVENTIVE MAINTENANCE (PM)
-- Target database : hvac_current (local) / u468140406_hvac (remote)
-- Target table    : unit_maintenance (NEW table only; no existing tables altered)
--
-- PREPARED FOR MANUAL EXECUTION BY ADMINISTRATOR.
-- Run this against BOTH databases when ready:
--   local  : hvac_current      (XAMPP, 127.0.0.1)
--   remote : u468140406_hvac   (Hostinger)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `unit_maintenance` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `equip_type`      VARCHAR(32)     NOT NULL,
    `unit_name`       VARCHAR(64)     NOT NULL,
    `active`          TINYINT(1)      NOT NULL DEFAULT 1,
    `started_by`      VARCHAR(100)    NOT NULL,
    `started_at`      DATETIME        NOT NULL,
    `expires_at`      DATETIME        NOT NULL,
    `ended_at`        DATETIME        NULL DEFAULT NULL,
    `ended_by`        VARCHAR(100)    NULL DEFAULT NULL,
    `reason`          VARCHAR(255)    NULL DEFAULT NULL,

    `active_unit_key` VARCHAR(100) GENERATED ALWAYS AS (
        IF(`active` = 1, CONCAT(`equip_type`, ':', `unit_name`), NULL)
    ) VIRTUAL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_unit_maintenance_active` (`active_unit_key`),
    KEY `idx_unit_maintenance_active_query` (`equip_type`, `active`, `expires_at`),
    KEY `idx_unit_maintenance_lookup` (`equip_type`, `unit_name`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- OPTIONAL LOCAL PERMISSIONS:
-- On local XAMPP with restricted hvac_web account, execute if needed:
--
-- GRANT SELECT, INSERT, UPDATE ON `hvac_current`.`unit_maintenance` TO 'hvac_web'@'localhost';
-- FLUSH PRIVILEGES;
-- ============================================================================
