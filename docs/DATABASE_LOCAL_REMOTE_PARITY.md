# Local / Remote Database Parity — HVAC Monitoring

**Audit date:** 2026-10-11 · **Mode:** read-only
**Verdict: `NOT VERIFIED`.** The remote database could not be reached from this audit, so **no parity claim is
made**. This document records the local fingerprints (so a future run can diff against them), states precisely
what is missing, and lists the exact commands a future auditor can use.

---

## 1. Why parity is NOT VERIFIED

| Requirement | Status |
|---|---|
| Remote host reachable | NOT ATTEMPTED — see below |
| Remote credential available | **NO** — password is only in runtime env vars (`AZBIL_REMOTE_DB_PASSWORD`, `EF_REMOTE_DB_PASSWORD`, `DAIKIN_REMOTE_DB_PASSWORD`), which are **not set** in the audit environment |
| Remote inventory obtainable | **NO** — depends on the above |
| Remote DDL obtainable | **NO** |

Hostinger also restricts remote MySQL to whitelisted client IPs, and the poller PC uses a 4G modem with a
changing public IP, so reachability from a different machine is not guaranteed even with the password.

**This is a blocker, not a mismatch.** Nothing in this document should be read as evidence that the remote
database differs from local — only that the comparison was not performed.

> **Do not assume local and remote schemas match.** The claim in `PROJECT.md` §1 ("Same schema as local,
> imported via `mysqldump --no-data`") is a *historical statement about how the remote DB was created*. It does
> not prove the remote DB is in that state **today** — in particular, tables added after that import (notably
> `hnx_g8_points` / `hnx_g8_current`, created 2026-10-06) would not be present unless separately created.

---

## 2. LOCAL fingerprints (VERIFIED — use these as the comparison baseline)

Taken 2026-10-11 from `information_schema` and `SHOW CREATE TABLE` on `hvac_current`, as `hvac_web`.

### 2.1 Database-level

| Fingerprint | Value |
|---|---|
| DDL fingerprint (sha256, all 24 objects) | `17e7ed4aa594621f594a708d20de3266ec5405effa05a3c27576bd5d9b8c82d9` |
| Column-level (sha256 over `information_schema.COLUMNS`) | `1f3dec16ea92aea9c9f7310437607a2c802bbbca15f55c6273c9c69d7b575050` |
| Index-level (sha256 over `information_schema.STATISTICS`) | `4235000f9cd77f044c2ee6ab98ff9564ff636c94303f19e8f06742db90cb5c1b` |
| **Constraint-level (sha256 over constraint lines in `SHOW CREATE TABLE`)** | **`8543cc9fcbffb28575faac1e1596e878a9f8fbfd0574a4faa431c70f80d9e241`** |
| ~~Constraint-level over `information_schema.TABLE_CONSTRAINTS`~~ | ~~`15ed136bc5cf6ba715e77217ae8af9233fd690bf9b0fc9a0baca11e4213a9431`~~ **DO NOT USE — see below** |

> **Correction to an earlier draft of this document.** The constraint fingerprint was first computed over
> `information_schema.TABLE_CONSTRAINTS`, which is **privilege-filtered**: for `hvac_web` it returns only
> **8** rows (the 7 tables that account may write to, plus `temp_rh_hourly`), silently hiding 26 of the
> database's 34 constraints — including the `alarm_events` CHECK and **both foreign keys**. A comparison
> built on it would have reported "no difference" against a remote that genuinely lacked those constraints.
> The fingerprint above is derived from `SHOW CREATE TABLE` instead, which is **not** privilege-filtered
> and yields the true count of 34. If a future auditor compares constraints, use the DDL-derived value.

**Fingerprint coverage and limits (important for interpreting a future diff):**

- The **DDL fingerprint** hashes each object's `SHOW CREATE TABLE`, normalised by stripping `AUTO_INCREMENT=<n>`
  (volatile — it changes whenever rows are inserted) and collapsing whitespace. Objects are hashed in name order.
- The **column fingerprint** is computed independently from `information_schema.COLUMNS`
  (`table|ordinal|name|type|nullability|default|key|extra|charset|collation|comment`). It is the more robust of
  the two: it does not depend on DDL text formatting or on server version quirks in `SHOW CREATE`.
- **Verified not filtered:** `information_schema.COLUMNS` (196 rows) and `information_schema.TABLES`
  (24 rows) return complete results for `hvac_web` and agree exactly with the DDL.
- **Verified filtered:** `information_schema.TABLE_CONSTRAINTS` (8 vs 34). Treat any other
  `information_schema` view as suspect until cross-checked against `SHOW CREATE TABLE`.
- **NOT covered by any fingerprint:** row counts, live values, the `AUTO_INCREMENT` counter, table/index sizes,
  and `CREATE_TIME`/`UPDATE_TIME`. A parity verdict must not be drawn from fingerprints alone.

### 2.2 Per-object DDL fingerprints (sha256, first 16 hex)

| Object | Fingerprint | Object | Fingerprint |
|---|---|---|---|
| `acc_current` | `bad0d8386205b968` | `kwh_total_ccp` | `2e34fe1ddf94cf09` |
| `ai_current` | `72ea504ec425130b` | `kwh_total_chwp` | `40ec62fddfda6b82` |
| `alarm_events` | `cd2bd28c2d693eb6` | `kwh_total_ct` | `68c441c01d299b22` |
| `bi_current` | `fba026832caaaf93` | `kwh_usage_ccp` | `16522a80e339acc5` |
| `daikin_current` | `f0a0887c052b638e` | `kwh_usage_chwp` | `5d60d398b96cf9d4` |
| `daikin_points` | `3a10572fac4463a4` | `kwh_usage_ct` | `fac8ea90181af6d4` |
| `ef_alarm_events` | `4e9f5ca03a642554` | `points` | `c5650459544c4cf6` |
| `ef_communication_events` | `94bda6641d0a4d88` | `temp_rh_hourly` | `2580ef6500d877e7` |
| `ef_current` | `44bf5ba58f3e8b48` | `threshold_by_kw` | `0bfa53820e9cc947` |
| `ef_points` | `187fe424d79c8284` | `threshold_direct` | `588dec234cf44b0e` |
| `hnx_g8_current` | `77b0ff918164beac` | `unit_motor_kw` | `193df711c02415f5` |
| `hnx_g8_points` | `8ffb0419bb31a6a2` | `users` | `5447ca7b7ccc711e` |

### 2.3 Local structural summary

- 24 base tables, 0 views, 0 triggers, 0 routines, 0 events
- 196 columns; 53 distinct indexes (24 PRIMARY KEY + 6 UNIQUE + 23 plain `KEY`) = 75 index parts
- 34 constraints: 24 PRIMARY KEY + 6 UNIQUE + 2 CHECK + 2 FOREIGN KEY
  (counted from `SHOW CREATE TABLE`; **not** from `information_schema.TABLE_CONSTRAINTS`, which is
  privilege-filtered and reports only 8 rows for this account — see inventory §4)
- Engine `InnoDB` throughout; `utf8mb4` / `utf8mb4_unicode_ci` throughout **except `users`**
  (`utf8mb4_general_ci`)
- The only foreign keys in the whole database:
  - `ef_alarm_events.fk_ef_alarm_point` → `ef_points(id)`
  - `hnx_g8_current.fk_hnx_g8_current_point` → `hnx_g8_points(id)`
- CHECK constraints: `alarm_events.chk_alarm_active_state`, `temp_rh_hourly.chk_metric`

### 2.4 Local row counts at audit time (exact, 2026-10-11 ~19:28)

| Table | Rows | | Table | Rows |
|---|---|---|---|---|
| `acc_current` | 29 | | `kwh_total_ccp` | 10 |
| `ai_current` | 443 | | `kwh_total_chwp` | 10 |
| `alarm_events` | ~1,540 (growing) | | `kwh_total_ct` | 10 |
| `bi_current` | 318 | | `kwh_usage_ccp` | 10 |
| `daikin_current` | 38 | | `kwh_usage_chwp` | 10 |
| `daikin_points` | 38 | | `kwh_usage_ct` | 10 |
| `ef_alarm_events` | 52 | | `points` | 824 |
| `ef_communication_events` | 0 | | `temp_rh_hourly` | 1,680 |
| `ef_current` | 55 | | `threshold_by_kw` | 4 |
| `ef_points` | 55 | | `threshold_direct` | 5 |
| `hnx_g8_current` | 16 | | `unit_motor_kw` | 31 |
| `hnx_g8_points` | 16 | | `users` | 1 |

> **Row counts are the weakest parity signal.** Live tables (`ai_current`, `bi_current`, `acc_current`,
> `ef_current`, `daikin_current`, `hnx_g8_current`) change every few seconds. A count difference there is
> **expected** and must never be reported as a schema mismatch. `alarm_events` and `temp_rh_hourly` grow over
> time. Only the reference tables (`points`, `ef_points`, `daikin_points`, `threshold_*`, `unit_motor_kw`,
> `hnx_g8_points`) should be expected to hold near-identical counts.

---

## 3. Expected-difference list (by design, NOT defects)

If a future comparison runs, these differences are **expected and already explained**. They must not be
reported as mismatches.

| # | Table / area | Expected relationship | Why |
|---|---|---|---|
| E1 | `users` | **Different content** | `DEPLOY_HOSTING.md` §1.4: hosting accounts are created fresh; local hashes are deliberately not copied |
| E2 | `ai_current`, `bi_current`, `acc_current` | Same schema; **values differ in time** | dual-write is ~30 s behind local (`REMOTE_EVERY_N_TICKS = 6`) |
| E3 | `ef_current` | Same schema; values lag | remote `ef_current` is throttled to 30 s |
| E4 | `daikin_current` | Remote is **frozen** at 2026-10-10 22:57 | the Daikin poller is broken — see architecture §6 |
| E5 | `alarm_events` | **May be absent remotely** | no remote writer was found in any poller |
| E6 | `hnx_g8_points`, `hnx_g8_current` | **May be absent remotely** | created 2026-10-06, after the reported one-time schema import; no remote path found |
| E7 | `ef_alarm_events`, `ef_communication_events` | **May be absent remotely** | EF poller mirrors `ef_current` only |
| E8 | `ef_readings` | **Absent both sides** | lived in the dropped `hvac_monitoring`; deliberately not recreated |
| E9 | kWh / `temp_rh_hourly` rows | Remote may hold **fewer days** | remote writers started later than local ones |
| E10 | `AUTO_INCREMENT` counters | Always differ | volatile |

**E5, E6 and E7 are the highest-value things to check** — they are the cases where the remote schema is
genuinely likely to be *missing* tables rather than merely holding older data.

---

## 4. What a future auditor needs (to close this gap)

1. **A remote credential.** Either set `AZBIL_REMOTE_DB_PASSWORD` (etc.) in the environment, or run the
   comparison from the poller PC which is already whitelisted by Hostinger.
2. **Read-only access** — the comparison only needs `SELECT` and `SHOW VIEW`/`information_schema` reads. It must
   **not** use the app's full-privilege account if a read-only one can be arranged.
3. Then run the same four fingerprint queries against the remote schema and diff the per-object table in §2.2.

**Suggested comparison method (read-only, safe to run):**

```sql
-- inventory
SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = 'u468140406_hvac' ORDER BY TABLE_NAME;

-- column fingerprint input (hash these rows, same field order as §2.1)
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
       COLUMN_DEFAULT, COLUMN_KEY, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'u468140406_hvac'
 ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- index fingerprint input
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'u468140406_hvac'
 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- constraints
SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE
  FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = 'u468140406_hvac'
 ORDER BY TABLE_NAME, CONSTRAINT_NAME;
```

For DDL, `SHOW CREATE TABLE` per object, then normalise by stripping `AUTO_INCREMENT=<n>` and collapsing
whitespace — exactly as done for the local fingerprints above.

---

## 5. Comparison result table

| Comparison area | Result | Evidence |
|---|---|---|
| Database/table inventory | **NOT VERIFIED** | remote not reached |
| DDL, columns, types, nullability, defaults | **NOT VERIFIED** | remote not reached |
| Primary/unique keys, indexes | **NOT VERIFIED** | remote not reached |
| Foreign keys, CHECK constraints | **NOT VERIFIED** | remote not reached |
| Engine, charset, collation | **NOT VERIFIED** | remote not reached |
| Triggers, views, routines | **NOT VERIFIED** | remote not reached |
| Row counts | **NOT VERIFIED** | remote not reached |
| Data freshness | **NOT VERIFIED** | remote not reached |
| `points`, `ai_current`, `bi_current`, `acc_current` | **NOT VERIFIED** | the tables the brief calls out specifically — still not comparable |
| EF tables | **NOT VERIFIED** | — |
| Threshold tables | **NOT VERIFIED** | — |
| Energy tables | **NOT VERIFIED** | — |
| `temp_rh_hourly` | **NOT VERIFIED** | — |
| `alarm_events` | **NOT VERIFIED** | also expected absent (E5) |
| `hvac_monitoring` (either side) | **NOT APPLICABLE** | dropped; must not exist on either side |

**Zero rows in this table are `MATCH`, and that is the honest result.** No `MISMATCH` is claimed either — a
mismatch cannot be asserted without observing both sides.

---

## 6. Local-only integrity observations

These are internal-consistency checks on the local database. They are not parity findings, but a future remote
comparison should re-run them on the remote side.

| Check | Result |
|---|---|
| `alarm_events` CHECK-constraint violations | 0 (ACTIVE with NULL key = 0, CLEARED with key = 0) |
| `temp_rh_hourly` `metric` values | only `temp` / `rh` (CHECK satisfied) |
| `temp_rh_hourly` `recorded_at` truncation | all distinct values are `:00:00` — consistent |
| `temp_rh_hourly` rows per hour | exactly 20 rows / 20 points in every hour present |
| `temp_rh_hourly` coverage | **167 hours missing** (2026-10-01 13:00 → 2026-10-08 09:00) |
| `ef_points` without `ef_current` row | 0 |
| `ef_current` / `points` FK integrity | intact (only 2 FKs exist, both respected) |
| `ai_current` / `bi_current` orphans | 2 AI + 12 BI, all frozen since 2026-09-23 |
| `acc_current` orphans | 0 |
| kWh date coverage | `ct`/`ccp`/`chwp` all hold the same 10 dates, 2026-10-02 … 2026-10-11 |

---

## 7. Summary

- **Local database: fully inventoried and fingerprinted** (24 tables, 196 columns, 4 fingerprints recorded).
- **Remote database: not inventoried, not fingerprinted, not compared.** `NOT VERIFIED` throughout.
- **No parity claim is made in either direction.** Not `MATCH`, not `MISMATCH`.
- **Neither database was modified** in any way to influence parity.
- The highest-probability real difference is **missing tables remotely** (E5–E7: `alarm_events`,
  `hnx_g8_*`, `ef_alarm_events`), not altered columns.
