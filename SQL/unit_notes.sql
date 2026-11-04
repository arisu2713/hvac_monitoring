-- ============================================================================
-- HVAC MONITORING — OPERATOR STICKY NOTES
-- Target database : hvac_current
-- Target table    : unit_notes (NEW table only; no existing tables altered)
--
-- PREPARED FOR MANUAL EXECUTION BY ADMINISTRATOR.
-- Run this against BOTH databases when ready:
--   local  : hvac_current      (XAMPP, 127.0.0.1)
--   remote : u468140406_hvac   (Hostinger)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `unit_notes` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `equip_type`      VARCHAR(32)     NOT NULL,
    `unit_id`         VARCHAR(64)     NOT NULL,
    `note`            VARCHAR(500)    NOT NULL,
    `author`          VARCHAR(100)    NOT NULL,
    `created_at`      DATETIME        NOT NULL,
    `updated_at`      DATETIME        NOT NULL,
    `resolved_at`     DATETIME        NULL DEFAULT NULL,

    -- Deterministic uniqueness for active note per unit:
    -- Evaluates to 'equip_type:unit_id' while open; NULL when resolved.
    -- MariaDB/MySQL allows multiple NULLs in a UNIQUE index.
    `active_unit_key` VARCHAR(100) GENERATED ALWAYS AS (
        IF(`resolved_at` IS NULL, CONCAT(`equip_type`, ':', `unit_id`), NULL)
    ) VIRTUAL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_unit_notes_active` (`active_unit_key`),
    KEY `idx_unit_notes_lookup` (`equip_type`, `unit_id`, `resolved_at`),
    KEY `idx_unit_notes_resolved` (`resolved_at`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- OPTIONAL LOCAL PERMISSIONS:
-- On local XAMPP with restricted hvac_web account, execute if needed:
--
-- GRANT SELECT, INSERT, UPDATE ON `hvac_current`.`unit_notes` TO 'hvac_web'@'localhost';
-- FLUSH PRIVILEGES;
-- ============================================================================
