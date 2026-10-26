-- ============================================================================
-- EQUIPMENT STATUS EVENTS — PHASE 1: equipment_status_events
--
-- Target databases : hvac_current      (local  XAMPP, 127.0.0.1, MariaDB 11.4.12)
--                    u468140406_hvac    (remote Hostinger, srv1763.hstgr.io, MariaDB 11.8.9)
-- Target table     : equipment_status_events   (exactly ONE new table per database)
--
-- Purpose: append-only history of ON/OFF status transitions per equipment.
--          alarm_events remains the separate home for WARNING/ALARM.
--          Analog temperature history and WARNING/ALARM rows must NOT be
--          written here (writer contract, later phase).
--
-- Rules encoded by the spec (enforced by the future poller writer, not by DDL):
--   * local DB is the primary event source; remote receives a copy carrying
--     the SAME event_uuid (unique key below makes the remote replay idempotent)
--   * previous_status is nullable for schema flexibility, but the poller's
--     FIRST reading of a point is a baseline and must NOT emit an event
--   * communication loss must NOT be recorded as OFF
--
-- Preflight verified 2026-10-26: table absent on both databases; no
-- conflicting %equipment% / %status_event% object exists on either side.
--
-- This statement creates one table and touches nothing else. It contains no
-- ALTER, DROP, DELETE, TRUNCATE, RENAME, or INSERT, and defines no foreign key.
-- ============================================================================

CREATE TABLE `equipment_status_events` (

    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Append-heavy event log; one row per ON/OFF transition. BIGINT sizes the
    -- log for years of edge-triggered events across ~200 status points.

    `event_uuid`    CHAR(36) NOT NULL,
    -- Identity shared between local (primary) and remote (copy). The UNIQUE
    -- key below makes a replayed/dual-written event a duplicate that is
    -- rejected instead of silently duplicated.

    `event_time`    DATETIME(3) NOT NULL,
    -- Transition timestamp, supplied explicitly by the poller (naive WIB,
    -- project convention). Deliberately NO DEFAULT: local DB SYSTEM tz is WIB
    -- but the hosting DB SYSTEM tz is UTC, so a database-generated timestamp
    -- would differ between the two hosts for the same event.

    `equipment_type` VARCHAR(32) NOT NULL,
    -- 'AHU', 'CHILLER', 'CCP', 'CHWP', 'CT', 'EF', ... (TRIM()ed by the writer
    -- to avoid the known 'CT ' trailing-space defect in source data).

    `equipment_id`  VARCHAR(64) NOT NULL,
    -- Human-readable equipment identity, e.g. 'AHU 61', 'EF 55', 'CT 5A'.

    `point_id`      VARCHAR(100) NOT NULL,
    -- Stable point identity, unified across namespaces (BACnet point_id,
    -- 'EF:<id>', 'G8:<id>'). Wide enough to hold any current project id.

    `previous_status` VARCHAR(16) NULL,
    -- NULL only for schema flexibility; the writer contract says the first
    -- reading of a point is a baseline and emits no event, so rows are
    -- expected to always carry a real previous status.

    `new_status`    VARCHAR(16) NOT NULL,
    -- 'ON' / 'OFF' (VARCHAR, not ENUM, so no ALTER is needed if a new status
    -- label is ever introduced — matches the alarm_events convention).

    `source_poller` VARCHAR(64) NOT NULL,
    -- Originating poller, e.g. BACNET_POLLER, EF_POLLER, HNX_G8_POLLER.

    `created_at`    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    -- Row-creation timestamp (technical, not the event time). Differing
    -- SYSTEM timezones between hosts are acceptable here.

    PRIMARY KEY (`id`),

    UNIQUE KEY `uq_event_uuid` (`event_uuid`),
    -- Idempotency for the local -> remote copy path: the same event_uuid can
    -- exist on both databases, but never twice on one database.

    KEY `idx_event_time` (`event_time`),
    -- Every Activity Log query is "filter, ordered by time".

    KEY `idx_equipment_time` (`equipment_type`, `equipment_id`, `event_time`)
    -- Combined index for per-equipment history lookups.

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Engine and charset match the project convention (utf8mb4_unicode_ci
-- everywhere except users), so a straight replay produces the same table on
-- both databases.

-- ============================================================================
-- No foreign key (project convention: 22 of 24 existing tables define none,
-- and point_id is deliberately polymorphic across namespaces).
-- No ALTER, DROP, DELETE, TRUNCATE, RENAME, or INSERT is performed here.
-- ============================================================================
