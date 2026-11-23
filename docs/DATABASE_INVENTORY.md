# Database Inventory — HVAC Monitoring

**Audit date:** 2026-10-11
**Mode:** read-only metadata inspection (no writes, no DDL, no DML)
**Local account used:** `hvac_web` (the same account the web app uses) — see `DATABASE_SECURITY_REVIEW.md`
**Source of truth:** live `information_schema` + `SHOW CREATE TABLE` on the local MariaDB, plus source-code evidence for writers/readers.

> Status labels used throughout: `VERIFIED` (observed directly), `INFERRED — NEEDS VERIFICATION`, `NOT VERIFIED`.
> No business meaning is asserted from a table name alone.

---

## 1. Environments discovered

| Env | Host | Port | Database | DBMS | Status | How verified |
|---|---|---|---|---|---|---|
| LOCAL | `127.0.0.1` | 3306 | `hvac_current` | MariaDB 11.4.12 | **VERIFIED — connected** | Live connection as `hvac_web@localhost`, 2026-10-11 |
| LOCAL | `127.0.0.1` | 3306 | `information_schema` | — | VERIFIED — system schema | Visible to `hvac_web` |
| LOCAL | `127.0.0.1` | 3306 | `test` | — | VERIFIED — present, unrelated | Visible; `PUBLIC` has full grants (MariaDB default) |
| LOCAL | `127.0.0.1` | 3306 | `hvac_monitoring` | — | **VERIFIED ABSENT (dropped)** | See §3 |
| REMOTE | `srv1763.hstgr.io` | 3306 | `u468140406_hvac` | NOT VERIFIED (reported MySQL/MariaDB) | **NOT VERIFIED — not accessible from this audit** | See §2 |

### Databases visible to the `hvac_web` account

`SHOW DATABASES` as `hvac_web` returns exactly three:

```
hvac_current
information_schema
test
```

No other database is visible to this account. `hvac_web` has `SELECT` on `hvac_current.*` plus a small set of
`INSERT` grants (see `DATABASE_SECURITY_REVIEW.md`); it has no privilege on any other schema.

---

## 2. REMOTE database — `NOT VERIFIED`

The remote database could not be inventoried. This is a **blocker, not a negative finding**.

| Item | Value |
|---|---|
| Reported host | `srv1763.hstgr.io:3306` |
| Reported database / user | `u468140406_hvac` (database and user share the name — Hostinger convention) |
| Credential location | Password is **not** in any repo file. It is read at runtime from env vars: `AZBIL_REMOTE_DB_PASSWORD` (Azbil poller), `EF_REMOTE_DB_PASSWORD` (EF poller), `DAIKIN_REMOTE_DB_PASSWORD` (Daikin poller) |
| Why not verified | Those environment variables are **not set in the audit shell**, and the audit is not permitted to retrieve them. Hostinger also restricts remote MySQL to whitelisted IPs. |

**Evidence that the remote database exists and is actively written** (indirect, from source + local data):

- All three active pollers contain a working remote write path to `srv1763.hstgr.io` / `u468140406_hvac`.
- The Azbil poller's remote live-value copy and daily-kWh copy are implemented and enabled when the env var is set.
- `PROJECT.md` §6.2 documents the remote schema as matching local for the tables the pollers write.

**What is NOT verified:** the remote table list, column definitions, indexes, constraints, row counts,
and freshness. **Do not assume the remote schema matches local** — this audit did not compare them.
The parity document records this as `NOT VERIFIED` rather than `MATCH`.

---

## 3. The dropped `hvac_monitoring` object

### 3.1 It was a DATABASE, not a table — VERIFIED

Evidence chain:

1. **The poller's own error is MariaDB error 1049**, which MariaDB raises *only* for an unknown **schema**:
   `pymysql.err.OperationalError: (1049, "Unknown database 'hvac_monitoring'")`
   — observed 292 times in `C:\Projects\ahu_ef_daikin\_audit\daikin_poller_stderr.log`, first at line 85356.
2. **`information_schema.SCHEMATA` contains no schema named `hvac_monitoring`** — but note the caveat in 3.2:
   this view only shows schemas the account can see, so on its own it is not proof. The error code in (1) is
   what settles it, because 1049 is raised before any privilege check on a schema.
3. Multiple independent source files configure `hvac_monitoring` as a **`database`/`DB_NAME`** value, never as a table:
   `azbil_new2/curated_poll_client.py:31`, `azbil_project/poll_client.py:32`, `azbil_project/load_catalog.py:17`,
   `ahu_ef_daikin/ahu_poller_unified.py:374`, `unified_poller/unified_poller.py:189`.
4. `C:\Projects\azbil_bacnet\AZBIL_POLLER_HVAC_MONITORING_REMOVAL.md:7` — task doc written at removal time:
   *"The `hvac_monitoring` database has been dropped."*

**Conclusion: `hvac_monitoring` was a database. It is gone.** Per instruction, it was not recreated and is not
treated as an active dependency of the web app.

### 3.2 Why a naive existence probe is inconclusive

Probing `` SELECT 1 FROM `hvac_monitoring`.`x` `` as `hvac_web` returns
`ERROR 1142 (42000): SELECT command denied ... for table 'hvac_monitoring.x'`.

**This is NOT evidence of anything**, and it is worth being precise about why, because the tempting
inference ("1142, not 1049, so the schema must exist") is exactly backwards. Control probe run against
three schemas that **provably exist** on this server:

| Probe target | Exists? | Error returned | `SCHEMATA` rows visible |
|---|---|---|---|
| `mysql.user` | **yes** | 1142 SELECT command denied | **0** |
| `performance_schema.session_variables` | **yes** | 1142 SELECT command denied | **0** |
| `sys.version` | **yes** | 1142 SELECT command denied | **0** |
| `hvac_monitoring.x` | unknown | 1142 SELECT command denied | **0** |
| `definitely_no_such_db_xyz.x` | **no** | 1142 SELECT command denied | **0** |

MariaDB does not disclose schema existence to an account holding no privilege on it: an existing-but-
invisible schema and a non-existent one are **indistinguishable** by both the 1142 error and
`information_schema.SCHEMATA`. `information_schema.TABLES` is likewise privilege-filtered (it still
shows 3 rows for `performance_schema` and 0 for `mysql`, purely by accident of what PUBLIC can read).

**Conclusion: that probe proves nothing** and is recorded here so a future audit does not repeat it.
The decisive evidence is the **1049** error from the poller (which *did* hold privileges on the schema)
— see 3.1 — because 1049 is raised before any privilege check.

### 3.3 Objects that lived in `hvac_monitoring` (historical — VERIFIED ABSENT locally)

Reported by the pollers' own source code and by the removal docs. **None of these exist in `hvac_current`:**

| Object | Written by (historically) | Evidence |
|---|---|---|
| `ai_readings` | Azbil pollers | `azbil_new2/curated_poll_client.py:299` |
| `bi_readings` | Azbil pollers | `azbil_new2/curated_poll_client.py:304` |
| `acc_readings` | Azbil pollers | `azbil_new2/curated_poll_client.py:309` |
| `daikin_readings` | Daikin poller | `ahu_ef_daikin/ahu_poller_unified.py:1205` |
| `ef_readings` | EF poller (legacy) | `Project/monitoring ef/ef_polling_engine*.py:734/759` |
| `hnx_g8_readings` | Unified poller (never deployed) | `unified_poller/hnx/store.py:34` |
| `points_legacy` | FK target for the reading tables | `azbil_new2/PROJECT.md:80-82` |

> **`ef_readings` had ~1,054,048 rows** according to `monitoring_ef/ef_migration_plan.md:28`. That history is
> **gone** and is not recoverable from the local server. A `mysqldump` intended to preserve it exists but is
> **0 bytes** (§5).

---

## 4. LOCAL `hvac_current` — object inventory

**24 base tables, 0 views, 0 triggers, 0 routines, 0 events.** Verified 2026-10-11.

Structural totals (**counted from `SHOW CREATE TABLE` DDL, which is authoritative**):

| Item | Count |
|---|---|
| Base tables | 24 |
| Columns | 196 |
| Indexes (distinct) | 53 — comprising 24 PRIMARY KEY + 6 UNIQUE + 23 plain `KEY` |
| Index parts (column-per-index rows) | 75 |
| CHECK constraints | 2 |
| FOREIGN KEY constraints | 2 |

> **Measurement caveat (found during final verification).** `information_schema.TABLE_CONSTRAINTS`
> reports only **8** rows for this database (6 PKs + 1 CHECK + 1 PK on `temp_rh_hourly`) — that view is
> **privilege-filtered**, and the 7 tables it does show are exactly the 7 tables `hvac_web` holds a
> non-SELECT privilege on. It is **not** a reliable constraint count for this account. `SHOW CREATE TABLE`
> is used instead. `information_schema.CHECK_CONSTRAINTS` is likewise filtered but happens to return both
> CHECKs. A future auditor using a higher-privileged account should expect **34** constraints
> (24 PK + 6 UNIQUE + 2 CHECK + 2 FK).

Engine `InnoDB` for every table; database charset `utf8mb4` / collation `utf8mb4_unicode_ci`
(the single exception is `users`, which is `utf8mb4_general_ci`).

Row counts below are **exact** (`SELECT COUNT(*)`), not InnoDB estimates. Live tables change continuously.

### 4.1 Core / live-value tables

| Table | Rows | Columns | Key | Writer | Notes |
|---|---|---|---|---|---|
| `points` | 824 | 6 | PK `point_id` | external (master catalog) | The point master list. No `AUTO_INCREMENT`, no indexes beyond the PK. |
| `ai_current` | 443 | 3 | PK `point_id` | Azbil poller | Analog inputs. Overwritten in place. |
| `bi_current` | 318 | 3 | PK `point_id` | Azbil poller | Binary inputs. Overwritten in place. |
| `acc_current` | 29 | 3 | PK `point_id` | Azbil poller | Accumulator (energy meter) points. Overwritten in place. |

`points` column detail (`VERIFIED`):

```
point_id    varchar(32)  NOT NULL   PK
device_id   int(11)      NOT NULL
obj_type    varchar(8)   NOT NULL   -- 'AI' | 'BI' | 'ACC'
instance    int(11)      NOT NULL
point_name  varchar(128) NULL
equip_type  varchar(50)  NULL
```

`obj_type` distribution: `AI` 489, `BI` 306, `ACC` 29 (= 824).
`equip_type` distribution: `AHU` 279, `CT` 111, `CHILLER` 63, `CCP` 54, `CHWP` 54, `ROOM TEMP & RH` 20, and
`CHILLER 1 MODBUS` … `CHILLER 9 MODBUS` 27 each.

`ai_current` / `bi_current` / `acc_current` share one shape (`VERIFIED`):

```
point_id  varchar(32)  NOT NULL  PK
value     float        NULL      -- bi_current uses tinyint(1)
read_at   datetime     NOT NULL  DEFAULT current_timestamp()
```

**No foreign key** links these to `points` — the link is by convention on `point_id`. This is why orphan rows can
exist (§4.7).

### 4.2 Exhaust fan (EF) tables

| Table | Rows | Columns | Key | Writer | Notes |
|---|---|---|---|---|---|
| `ef_points` | 55 | 6 | PK `id` (AI, next 56) | external catalog | 2 UNIQUE keys |
| `ef_current` | 55 | 3 | PK `ef_point_id` | EF poller | Live on/off status only |
| `ef_alarm_events` | 52 | 6 | PK `id` (AI, next 53) | EF poller | **Has FK → `ef_points(id)`** |
| `ef_communication_events` | **0** | 5 | PK `id` (AI, next 1) | EF poller | **Never had a row written** |

`ef_points`: `id`, `panel_no` (int), `ip_address`, `ef_name` (unique), `register_address`, `slave_id` (default 1).
Unique keys: `uq_ef_points_name(ef_name)` and `uq_ef_points_panel_register(panel_no, register_address)`.

Panels present: 1(5), 2(6), 3(7), 4(6), 5(7), 6(6), 7(4), 8(7), 9(7) = 55 fans.

`ef_current`: `ef_point_id`, `status` `tinyint(1) NOT NULL` (1 = running), `last_update` `datetime(6)`.
**No EF history table exists locally** — `ef_readings` was in the dropped database and was not recreated.

`ef_alarm_events`: `id`, `ef_point_id`, `event_type` enum(`ALARM`,`RECOVERED`), `previous_status`,
`current_status`, `event_time` `datetime(6)`. 26 ALARM + 26 RECOVERED rows, all between 2026-10-03 and 2026-10-09.

### 4.3 Daikin / Niagara tables

| Table | Rows | Columns | Key | Writer | Notes |
|---|---|---|---|---|---|
| `daikin_points` | 38 | 15 | PK `id` (AI, next 53) | Daikin poller | UNIQUE `(station_ip, niagara_path)` |
| `daikin_current` | 38 | 11 | PK `point_id` | Daikin poller | Denormalised: carries `equipment_type`/`equipment_no` |

`daikin_points` is the Niagara point catalog (incl. `niagara_path`, `object_type`, `data_type`, `writable`,
`is_confirmed_live`, `classification`, `priority`, `notes`).
`daikin_current` carries the value (`value_double` / `value_bool`), `unit`, `status`, `priority`, `last_update`.

**The legacy table groups named in the task brief do NOT exist in `hvac_current`:**
`daikin_ahu_current`, `daikin_ahu_points`, `daikin_ahu_readings`, `daikin_ef_current`, `daikin_ef_points`,
`daikin_ef_readings` — a schema-wide search found **none of them** in any accessible schema.
The `daikin_ahu_*` trio is referenced only by a **superseded** poller (`ahu_poller_playwright.py`, not the
running service). The `daikin_ef_*` trio has **zero references anywhere** in code or docs — those names appear to
be hypothetical. The current structures are `daikin_points` + `daikin_current` (catalog + current value), with
history formerly in the dropped `hvac_monitoring.daikin_readings`.

### 4.4 HNX G8 tables (not in the original brief — discovered)

| Table | Rows | Columns | Key | Writer | Notes |
|---|---|---|---|---|---|
| `hnx_g8_points` | 16 | 16 | PK `id` (AI, next 17) | external catalog | Modbus TCP catalog |
| `hnx_g8_current` | 16 | 10 | PK `point_id` | HNX G8 poller | **FK → `hnx_g8_points(id)`** |

`hnx_g8_points`: `equipment_type`, `equipment_no`, `station_ip`, `modbus_port` (502), `unit_id`,
`register_address` (varchar), `function_code`, `point_name`, `display_name`, `data_type` enum, `unit`,
`scale_factor` (default 1), `writable`, `is_confirmed_live`, `notes`.
Unique key `uq_hnx_g8_point(station_ip, register_address, function_code)`.

These back the **AHU G8** workstream (AHU 94/95). Created 2026-10-06; currently being written live.

### 4.5 Energy (kWh) tables

Six tables, one **cumulative total** and one **daily usage** per category. All `InnoDB`, PK `reading_date` (date),
all value columns `float NULL`, **no secondary indexes**.

| Table | Rows | Unit columns |
|---|---|---|
| `kwh_total_ct` | 10 | `ct_1`–`ct_6`, `ct_9`–`ct_13` (11) |
| `kwh_usage_ct` | 10 | same 11 |
| `kwh_total_ccp` | 10 | `ccp_1`–`ccp_9` (9) |
| `kwh_usage_ccp` | 10 | same 9 |
| `kwh_total_chwp` | 10 | `chwp_1`–`chwp_9` (9) |
| `kwh_usage_chwp` | 10 | same 9 |

**Cumulative vs daily — supported by code, not assumed.** The writer (`azbil_bacnet/Program.cs`,
`SaveDailyKwh`) reads the ACC meter value and stores it into `kwh_total_*` as the day's cumulative snapshot; it
then computes `kwh_usage_*` = today's total − yesterday's total, storing **`NULL`** when yesterday is missing or
the difference is negative. This is documented in `azbil_bacnet/PROJECT.md` §6.3–6.4 and matches the local data
(all three categories hold exactly the dates 2026-10-02 … 2026-10-11, 10 consecutive days).

**`ct_7` and `ct_8` are deliberately absent** — those cooling-tower units have no points at all
(corroborated by `points`: CT has no `CT 7`/`CT 8` entries). The web app discovers unit columns dynamically
from `information_schema.columns` rather than hardcoding 1–13, precisely so the gap does not render as
"missing data".

### 4.6 Room TEMP/RH history and threshold reference tables

| Table | Rows | Columns | Key | Writer | Notes |
|---|---|---|---|---|---|
| `temp_rh_hourly` | 1,680 | 10 | PK `(recorded_at, point_id)` | Azbil poller | Long format, 1 row per point per hour |
| `threshold_direct` | 5 | 4 | PK `(equip_type, metric)` | external (seed) | read-only to the app |
| `threshold_by_kw` | 4 | 3 | PK `motor_kw` | external (seed) | read-only to the app |
| `unit_motor_kw` | 31 | 3 | PK `(equip_type, unit_name)` | external (seed) | read-only to the app |

`temp_rh_hourly` — every column carries a COMMENT in the DDL; the important semantics:

- `recorded_at` — snapshot hour, WIB, truncated to `:00:00` (verified: every distinct value ends in `00:00`)
- `point_id` — `points.point_id`, the real identity; `device_id` disambiguates duplicate labels
- `room_type` — `ROOM` | `OUTDOOR`; `room_name` — normalised label only
- `metric` — `temp` | `rh`, with `CHECK (metric in ('temp','rh'))`
- `value` — `NULL` means *no reading*, never `0`
- `read_at` — the actual `ai_current.read_at` at snapshot time (reveals a frozen poller)

Content: 84 distinct hours, 1,680 rows, 0 NULL values; `temp` 840 rows / 10 rooms, `rh` 840 rows / 10 rooms;
8 `ROOM` labels + 2 `OUTDOOR` labels. Every hour holds exactly 20 rows / 20 distinct points — a clean,
consistent snapshot per hour.

**Coverage and gaps — important.** The span 2026-10-01 09:00 → 2026-10-11 19:00 is 251 hours, but only
**84 hours have rows: 167 hours are missing**, in **two** runs:

| Missing run | Length |
|---|---|
| 2026-10-01 10:00 → 2026-10-01 11:00 | 2 hours |
| **2026-10-01 13:00 → 2026-10-08 09:00** | **165 hours (≈7 days)** |

From 2026-10-08 10:00 onward the series is **complete and continuous** through 2026-10-11 19:00
(2026-10-08 has 14 distinct hours, 10-09 and 10-10 have 24 each, 10-11 has 20 to 19:00).
Interpretation (`INFERRED — NEEDS VERIFICATION`): the hourly writer was not running during that week and
was started on 2026-10-08. The gap is a real hole in history, not a query artifact.

Threshold tables — the app **only reads** them; values are seeded from `threshold_rules_seed.sql`
(generated from `threshold_rules_ed3.xlsx`, which is the actual master):

| `threshold_direct` | min | max |
|---|---|---|
| AHU / temp | NULL | 25 |
| CHILLER / cond_entering_temp | NULL | 32 |
| CHILLER / evap_leaving_temp | NULL | 12 |
| ROOM / temp | NULL | 30 |
| ROOM / rh | NULL | 65 |

`threshold_by_kw`: 7.5 kW → 12, 15 kW → 30, 30 kW → 56, 37 kW → 73 (all `max`, `min` NULL).
`unit_motor_kw`: 9 CCP + 9 CHWP + 13 CT = 31 rows.

### 4.7 Alarm events

| Table | Rows | Columns | Key | Writer |
|---|---|---|---|---|
| `alarm_events` | ~1,540 (growing) | 18 | PK `id` (AI, next ~1547) | Azbil poller (**and** EF poller, uncommitted) |

Full column list (`VERIFIED`):

```
id            bigint(20) unsigned NOT NULL AUTO_INCREMENT  PK
event_class   enum('ALARM','WARNING') NOT NULL
source        varchar(32) NOT NULL
equip_type    varchar(50) NULL
equipment     varchar(64) NOT NULL
point_id      varchar(32) NULL
ef_point_id   int(10) unsigned NULL
panel_no      int(11) NULL
ip_address    varchar(45) NULL
metric        varchar(32) NULL
description   varchar(255) NOT NULL
status        enum('ACTIVE','CLEARED') NOT NULL DEFAULT 'ACTIVE'
active_key    varchar(200) NULL      UNIQUE (uq_alarm_active)
value         float NULL
limit_min     float NULL
limit_max     float NULL
raised_at     datetime(6) NOT NULL
cleared_at    datetime(6) NULL
```

Indexes: `idx_alarm_raised(raised_at)`, `idx_alarm_status(status, raised_at)`,
`idx_alarm_equip(equip_type, raised_at)`, `idx_alarm_point(point_id, raised_at)`,
`idx_alarm_ef_point(ef_point_id, raised_at)`, `idx_alarm_class(event_class, raised_at)`.

**CHECK constraint** `chk_alarm_active_state` enforces the lifecycle invariant:

```sql
CHECK (status = 'ACTIVE' AND active_key IS NOT NULL
    OR status = 'CLEARED' AND active_key IS NULL)
```

Verified against data: 0 violations. `active_key` is the poller's de-duplication key — unique while ACTIVE,
set to NULL on clear, so a recurrence inserts a **new** row and a cleared row is immutable history.

**Event semantics (VERIFIED against data + code):**

- `event_class = 'ALARM'` — native equipment alarm from the device's own alarm point.
- `event_class = 'WARNING'` — threshold violation raised by the poller comparing a reading to its limit.
- Current content: **~1,540 rows = 1 ALARM (CLEARED) + 8 WARNING (ACTIVE) + ~1,531 WARNING (CLEARED)**.
  Counts are a moving target — the poller inserts continuously (1,529 at 19:08, 1,538 at 19:28,
  1,544 at 19:37 on 2026-10-11). Treat the split as "1 ALARM ever; 8 threshold warnings currently
  open; the rest cleared history".
- All rows have `source = 'BACNET_POLLER'`. `raised_at` spans 2026-10-08 09:22 → now.
- Equipment distribution by `equip_type`: `ROOM TEMP & RH` ~825, `CHILLER` ~538, `AHU` 181.
  (`equipment` is the finer-grained label — `ROOM 20014` 376, `CHILLER 3` 278, `ROOM 20110` 249, …)
- The 8 ACTIVE rows are all WARNING/threshold, e.g. `CHILLER 4 cond_entering_temp 32.1 > 32`,
  `ROOM 20014 temp 30.2 > 30`, `CHILLER 3 evap_leaving_temp 12.1 > 12`.

> **Semantics caution.** The table holds both classes, and `api_alarm.php` reports them separately. Note that
> `ef_alarm_events` also exists and is a **separate, older** EF-specific log — the two are not the same feed.

### 4.8 Auth table

| Table | Rows | Columns | Notes |
|---|---|---|---|
| `users` | **1** | 7 | `admin` (is_admin=1, is_active=1), created 2026-09-03 |

`users`: `id` (AI, next 2), `username` (unique), `password_hash`, `is_admin`, `is_active`, `created_at`,
`updated_at`. Collation `utf8mb4_general_ci` (differs from every other table — cosmetic, but a real difference).
**The `password_hash` column was deliberately not read** during this audit.

---

## 5. Object-count summary

| Database | Tables | Views | Triggers | Routines | Events |
|---|---|---|---|---|---|
| `hvac_current` (LOCAL) | **24** | 0 | 0 | 0 | 0 |
| `u468140406_hvac` (REMOTE) | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED | NOT VERIFIED |
| `hvac_monitoring` | **dropped** | — | — | — | — |

**Columns documented:** 196 (local, exact). **Indexes:** 53 distinct (24 PK + 6 UNIQUE + 23 KEY),
= 75 index parts. **Constraints:** 34 (24 PK + 6 UNIQUE + 2 CHECK + 2 FK), counted from `SHOW CREATE TABLE`.

> An earlier draft of this section reported "8 constraints" — that number came from
> `information_schema.TABLE_CONSTRAINTS`, which is **privilege-filtered** for `hvac_web` and hides 26 of the
> 34 (including both foreign keys). Corrected during final verification; see §4.

No `EVENT` scheduler objects exist, and `@@global.event_scheduler` is **OFF** — consistent with the documented
decision that history is written by pollers, not by MariaDB events. (The web app's own snapshot scripts are
CLI-only and run manually; see §6.)

---

## 6. Writers and readers per table (evidence-based)

| Table | Writer(s) | Reader(s) |
|---|---|---|
| `points` | external catalog import | Azbil poller (`Program.cs:343`); `api_ahu.php`, `api_hvac_sc.php`, `api_room.php`; `temp_rh_snapshot.php`, `kwh_daily_snapshot.php` |
| `ai_current` | Azbil poller (`SaveCurrent`) | `api_ahu.php`, `api_hvac_sc.php`, `api_room.php`; `temp_rh_snapshot.php` |
| `bi_current` | Azbil poller (`SaveCurrent`) | `api_ahu.php`, `api_hvac_sc.php` |
| `acc_current` | Azbil poller (`SaveCurrent`) | `kwh_daily_snapshot.php` |
| `ef_points` | external catalog | `api_ef.php` |
| `ef_current` | EF poller | `api_ef.php` |
| `ef_alarm_events` | EF poller | **no reader found in the web app** (`suspected unused` by the app) |
| `ef_communication_events` | EF poller | no reader found; **0 rows ever** |
| `daikin_points` | Daikin poller | **no reader in the web app** (the app reads `daikin_current` only) |
| `daikin_current` | Daikin poller | `api_ahu.php`, `api_room.php`, `api_ef.php` |
| `hnx_g8_points` | external catalog | **no reader found in the web app** (AHU G8 tab is still a placeholder) |
| `hnx_g8_current` | HNX G8 poller | **no reader found in the web app** |
| `kwh_total_*` / `kwh_usage_*` | Azbil poller (`SaveDailyKwh`); also `kwh_daily_snapshot.php` if run | `api_energy.php` |
| `temp_rh_hourly` | Azbil poller (`SaveHourlyTempRh`); also `temp_rh_snapshot.php` if run | `api_temp_rh_hourly.php` |
| `alarm_events` | Azbil poller (`SaveAlarmEvents`); EF poller (uncommitted) | `api_alarm.php` |
| `threshold_*` / `unit_motor_kw` | external seed only | `api_ahu.php`, `api_hvac_sc.php`, `api_room.php`, and the Azbil poller |
| `users` | manual/administrative | `login.php` |

**Two writers exist for the history tables.** `azbil_bacnet/Program.cs` writes `temp_rh_hourly` and the kWh
tables directly, *and* the repo contains `temp_rh_snapshot.php` / `kwh_daily_snapshot.php` that write the same
tables. `PROJECT.md` §11 states no scheduler was found and both scripts "appear to be run manually".
`INFERRED — NEEDS VERIFICATION`: the poller is the effective writer; the PHP scripts are a fallback/legacy path.
**Both use `INSERT IGNORE`, so a double run is a no-op rather than a duplicate** — which is consistent with the
data showing exactly one row per hour per point and one row per day.

---

## 7. Orphan and gap findings

These are data-integrity observations, not schema faults.

### 7.1 Orphan rows in `*_current` (no matching `points` row)

| Table | Orphans | Detail |
|---|---|---|
| `ai_current` | **2** | `0010202AI0000499`, `0010202AI0000500` |
| `bi_current` | **12** | 6 on device 10202, 6 on device 10203 |
| `acc_current` | 0 | — |

All 14 orphans have `read_at = 2026-09-23 00:42–00:43` — i.e. they have been frozen since 2026-09-23 and are
never updated. `azbil_new2/PROJECT.md:214` independently describes exactly this ("2 AI and 12 BI … leftovers
from earlier tests … never updated. Deleting them is undecided"). **Corroborated by two independent sources.**
Because there is no FK, they are harmless to the app (the APIs join from `points`, so orphans never render).

### 7.2 Points with no current row

**48 `AI` points have never had a value; every `BI` and `ACC` point has one.** The arithmetic
(re-verified during final verification, correcting an earlier draft that said 46):

```
points:      AI 489  + BI 306 + ACC  29 = 824
*_current:   AI 443  + BI 318 + ACC  29 = 790
of which 14 rows are ORPHANS matching no point (§7.1):  AI 2 + BI 12
=> rows that actually match a point:                  AI 441 + BI 306 + ACC 29 = 776
=> points with no value: 824 - 776 = 48   (all of them AI; 489 - 441 = 48)
```

Note that the naive comparison "489 AI points vs 443 `ai_current` rows ⇒ 46 missing" is **wrong**,
because it silently mixes the two independent defects: 2 of the 443 rows are orphans that belong to no
point, so the true shortfall is 48, not 46. `BI` looks *over*-covered (306 points, 318 rows) for the
same reason — its 12 orphans.

The documented cause is the **48 inactive Chiller Modbus points** — chillers 1 and 3 have no Modbus
link yet, so their points are polled and fail every tick. `PROJECT.md` §3 records chillers 1 and 3 as
having no Modbus connection. That the documented count (48) and the measured shortfall (48) now agree
exactly is a good sign, but the **per-point attribution remains `INFERRED — NEEDS VERIFICATION`**.
The shortfall itself is `VERIFIED`.

### 7.3 `temp_rh_hourly` gap

167 missing hours between 2026-10-01 and 2026-10-11 — see §4.6. This is the single largest data-quality issue in
the local database and it is **visible to the ENERGY / Hourly History UI**.

---

## 8. Freshness snapshot (taken 2026-10-11 ~19:09 WIB)

| Source | Newest reading | Status |
|---|---|---|
| `ai_current` / `bi_current` | 2026-10-11 19:09:05 | **LIVE** (Azbil poller, 5 s tick) |
| `acc_current` | 2026-10-11 19:09:05 | **LIVE** |
| `ef_current` | 2026-10-11 19:09:07 | **LIVE** (EF poller) |
| `hnx_g8_current` | 2026-10-11 19:09:08 | **LIVE** (HNX G8 poller) |
| `daikin_current` | **2026-10-10 22:57:28** | **STALE ~20 h** — Daikin poller is PAUSED and failing |
| `alarm_events` | 2026-10-11 19:08:44 | **LIVE** |
| `temp_rh_hourly` | 2026-10-11 19:00:00 | **LIVE** (hourly) |
| kWh tables | 2026-10-11 (row present) | current day written at midnight |

The Daikin staleness is explained by §3.1 and is reported in full in `DATABASE_ARCHITECTURE.md` §6.

> **Re-confirmed at 19:43 on the same day:** every live table had advanced to within seconds of "now"
> (`ai/bi/acc_current` 19:43:04, `ef_current` 19:43:04, `hnx_g8_current` 19:43:03, `alarm_events`
> 19:40:47), `temp_rh_hourly` held its 19:00 hour, and `daikin_current` remained frozen at
> 2026-10-10 22:57:28 — i.e. **20.8 hours stale and drifting further behind**. The diagnosis is stable,
> not a transient.

---

## 9. Items marked NOT VERIFIED

1. **Entire remote database** (`u468140406_hvac`) — no accessible credential; see §2.
2. **Remote parity** — cannot be compared; see `DATABASE_LOCAL_REMOTE_PARITY.md`.
3. **Remote freshness** — the Azbil poller's remote path is enabled only when its env var is set, which this
   audit could not confirm. The Daikin remote copy is known-frozen only because its local copy is.
4. **Whether `hvac_web` is the account the *remote* web app uses** — no; on Hostinger the app uses
   `u468140406_hvac` (full privileges) per `DEPLOY_HOSTING.md` §1.2. Local uses `hvac_web`.
5. **Whether the `azbil_new2` / `azbil_project` legacy pollers ever ran against the dropped DB in production** —
   both services are Stopped, so they are not currently writing.
6. **Business meaning of `daikin_points.classification`** (tinyint, no lookup table) — `UNKNOWN`.
7. **Whether the 167-hour `temp_rh_hourly` gap is a known/accepted outage** — not stated in any doc found.
8. **Exact contents of the empty `hvac_monitoring_points_backup.sql` files (0 bytes)** — unrecoverable.
9. **Whether `ef_alarm_events` stopping at 2026-10-09 15:15 is normal** (no alarms since) **or a stalled writer** —
   the EF poller is running and `ef_current` is current, so the table is not being fed. Ambiguous.
10. **Whether `ef_communication_events`' 0 rows means no comm faults ever occurred, or a broken writer** — ambiguous.
    (Item 6 above also notes that only `classification` values 1 and 3 are present, with no lookup table.)

---

## 10. Final verification pass (2026-10-11, post-write)

After the documents were written, every headline number was **re-derived from the live database** and
compared against the text. Five claims were found **wrong in the first draft** and corrected in place:

| Claim | First draft (WRONG) | Verified value | Cause of the error |
|---|---|---|---|
| Table constraints | 8 | **34** (24 PK + 6 UNIQUE + 2 CHECK + 2 FK) | `information_schema.TABLE_CONSTRAINTS` is privilege-filtered for `hvac_web` |
| Indexes | 75 index parts | **53 distinct** indexes = 75 parts | "parts" was quoted without the distinct count |
| AI points with no value | 46 | **48** | naive `489 − 443`, ignoring 2 orphan rows that inflate the count |
| `temp_rh_hourly` gap | one block, 2026-10-01 13:00 → 10-08 09:00 | **two runs** — 2 h on 10-01, then 165 h | only the longest run was reported |
| FK name | `fk_hnx_g8_point` | **`fk_hnx_g8_current_point`** | transcribed from memory, not from DDL |

`alarm_events` row counts are inherently volatile (the poller inserts every few seconds: 1,529 → 1,544
during this audit) and are now documented as ranges with timestamps rather than as fixed values.

All values in §4, §5 and §7 above are from the corrected, re-verified pass.
