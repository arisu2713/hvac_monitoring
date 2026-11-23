# Activity Log — ON/OFF History Proposal

**Status: PROPOSAL ONLY. No code, schema, or poller change has been made.**
Prepared 2026-10-14. Awaiting explicit approval before any implementation.

---

## 1. Why this document exists

The Activity Log page was specified to show three event types: **ON/OFF**,
**WARNING**, **ALARM**.

The mandatory audit found that **WARNING and ALARM are fully supported by an
existing table, and ON/OFF is not supported by anything.** No amount of web-app
work can produce ON/OFF history, because the data does not exist and cannot be
derived from what is stored. This document reports that gap and proposes the
poller + schema change needed to close it.

---

## 2. Audit findings (read-only, verified against the live database)

### 2.1 Tables that exist

`hvac_current` holds 24 tables. Exactly three are event/history tables:

| Table | Rows | Span | Nature |
|---|---|---|---|
| `alarm_events` | 2,810 | 2026-10-08 → live | The real event log |
| `ef_alarm_events` | 136 | 2026-10-03 → live | EF fault-contact log, 22 of 55 fans |
| `ef_communication_events` | 6 | 2026-10-12 → 10-13 | Panel comm loss, panel 9 only |

A schema-wide search for `%hist%`, `%log%`, `%event%`, `%transition%` returns
only these three. Only `hvac_current`, `information_schema` and `test` are
reachable; the legacy `hvac_monitoring` database is gone.

### 2.2 WARNING — supported

`alarm_events.event_class = 'WARNING'`, source `BACNET_POLLER`: **2,759 rows**,
live, with `raised_at` and `cleared_at`. Equipment types present: ROOM TEMP & RH
(1,631), CHILLER (863), AHU (266). Metrics: `temp`, `rh`, `evap_leaving_temp`,
`cond_entering_temp`. This is a genuine append-only history and needs no change.

### 2.3 ALARM — supported, but narrower than the label suggests

`event_class = 'ALARM'`: **43 rows total**, of which **42 are `EF_POLLER`**
(`metric = 'STATUS'`, description `"EF 55 status changed from ON to OFF"`).
Exactly **one** BACnet-native ALARM row exists, dated 2026-10-08.

So an "ALARM" filter today shows *exhaust-fan stopped* events. It is not a
general equipment-alarm history. This is a data reality, not a bug, and the page
should say so rather than imply otherwise.

### 2.4 ON/OFF — NOT AVAILABLE

- **No history table exists.** Nothing records state transitions.
- **Every ON/OFF source is current-state-only and overwritten in place.**
  `bi_current` (PK `point_id`, one row per point) holds `AHU 61 Status`,
  `CHILLER n Status`, `CT n Status` and similar — but all 318 rows carry a
  single `read_at` timestamp. The previous value is destroyed on every tick.
  `ef_current` and `hnx_g8_current` have the same shape.
- **The pollers say so themselves.** `ahu_poller_unified.py` (lines 1736–1745)
  carries a `HISTORICAL - DISABLED` block: *"the history table has no
  replacement in `hvac_current`. The history path is therefore disabled rather
  than redirected. Impact: no local historical/append-only readings are
  recorded."* Its runtime log line reads
  `DATABASE: wrote 38 current states (history disabled)`.
- **`ef_alarm_events` is not an ON/OFF log.** Its `previous_status` /
  `current_status` encode `1 → 0 = ALARM` — fan-stopped *is* the fault, per
  `GLOBAL_ALARM_PHASE2B.md` §H. It covers 22 of 55 fans and only when a fault
  occurs. A join confirms its ALARM rows **duplicate** the `alarm_events`
  EF_POLLER rows at identical timestamps: same event, not a second source.

**Conclusion: ON/OFF history requires a new writer in the pollers and a new
table. It cannot be backfilled — the data was destroyed when `hvac_monitoring`
was dropped. History would begin the day the writer is deployed.**

---

## 3. Two constraints that must be resolved before implementation

### 3.1 The standing instruction forbids exactly this change

`C:\Projects\azbil_bacnet\AZBIL_POLLER_HVAC_MONITORING_REMOVAL.md` states, as a
mandatory safety rule:

> Do NOT create history/reading tables in `hvac_current`.

The Azbil poller's `SaveHistory()` path was removed on 2026-10-10 for this
reason (`Program.cs` lines 55–57, 1220–1224). A poller writer for ON/OFF
transitions is a history table in `hvac_current` and therefore **contradicts a
standing instruction**.

Two readings are possible, and only you can say which is correct:

- **(a) A permanent architectural ban.** `hvac_current` holds current state
  only; history lives elsewhere. Then this proposal is the wrong shape and the
  correct move is a separate history database, not a new table here.
- **(b) A deferral, not a ban.** The rule existed so the history path would not
  be *silently* redirected while the database was being dropped. Under this
  reading the new requirement explicitly and knowingly supersedes it.

**This proposal assumes (b). If (a) is correct, stop here and tell me — the
design changes substantially.**

### 3.2 The web database account cannot create the table

`SHOW GRANTS` for the account in `config.php`:

```
GRANT SELECT ON `hvac_current`.* TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_*` / `temp_rh_hourly`   (6 tables only)
```

`hvac_web` has **no CREATE privilege**. The DDL below cannot be applied by the
web app or by any script running as that user. It must be executed manually by
whoever holds schema rights — the same manual-execution path
`alarm_events_phase1.sql` documents. I will not attempt to apply it.

---

## 4. Proposed schema

One new table. No ALTER, DROP, DELETE, or TRUNCATE. No foreign key.

```sql
CREATE TABLE IF NOT EXISTS `equipment_transitions` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Originating writer. VARCHAR, not ENUM, so a new poller needs no ALTER
    -- (matches the alarm_events convention).
    `source`      VARCHAR(32) NOT NULL,   -- BACNET_POLLER | EF_POLLER | HNX_G8_POLLER

    -- Stable point identity, unified across three namespaces. bi_current keys
    -- on varchar point_id; ef_current on int ef_point_id; hnx_g8_current on int
    -- point_id. Storing one canonical string here is what lets a single table
    -- and a single UI filter serve all three without a polymorphic join.
    `point_key`   VARCHAR(64) NOT NULL,   -- e.g. '0010202BI0000687', 'EF:53', 'G8:1'

    `equip_type`  VARCHAR(50) NULL,       -- AHU | CHILLER | CCP | CHWP | CT | EF | ...
    `equipment`   VARCHAR(64) NOT NULL,   -- 'AHU 61', 'EF 55' — TRIM()ed at write

    `prev_state`  TINYINT(1) NULL,        -- NULL only if a writer ever seeds a row
    `new_state`   TINYINT(1) NOT NULL,    -- 1 = ON/running, 0 = OFF/stopped

    `changed_at`  DATETIME(6) NOT NULL,

    PRIMARY KEY (`id`),
    -- Makes the writer idempotent: a retried or duplicated cycle cannot insert
    -- the same transition twice. This is the ON/OFF analogue of the
    -- UNIQUE(active_key) guarantee that makes alarm_events restart-safe.
    UNIQUE KEY `uq_transition` (`source`, `point_key`, `changed_at`),

    -- One index per UI filter dimension, each time-ordered, because every
    -- Activity Log query is "filter on X, ordered by time".
    KEY `idx_tr_time`     (`changed_at`),
    KEY `idx_tr_equip`    (`equip_type`, `changed_at`),
    KEY `idx_tr_equipment`(`equipment`,  `changed_at`),
    KEY `idx_tr_point`    (`point_key`,  `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Deliberately no `active_key` / `cleared_at`.** A transition is an instant, not
a state with a lifetime. `alarm_events` needs ACTIVE/CLEARED because an alarm
persists; a state change does not.

**`equip_type` uses the existing project values, including the known
`'CT '` trailing-space defect.** Per `alarm_events_phase1.sql`, the writer
contract is to `TRIM()` before writing, so the UI filter stays consistent. This
is a writer-side fix, not a source-data change.

---

## 5. Proposed writer contract

The rules below are not arbitrary — each one mirrors a bug this project has
already hit and documented.

1. **Edge-on-change.** Write a row only when the observed state differs from the
   last recorded state. No row per poll cycle.

2. **State read back from the database, never from process memory.** This
   directly follows `GLOBAL_ALARM_DISCOVERY_REPORT.md` §9.3, which records that
   the EF poller's in-memory `previous_status` means *"restart poller =
   kehilangan transisi"* — a restart loses or fabricates transitions. The
   alarm evaluator was deliberately made stateless to avoid this. The ON/OFF
   writer must be stateless in the same way: the last known state is
   `SELECT new_state FROM equipment_transitions WHERE source=? AND point_key=?
   ORDER BY changed_at DESC LIMIT 1`.

3. **First observation is silent.** On a cold start or after a restart, if no
   prior row exists for a point, record nothing — the point's current state
   becomes the baseline. This is the direct analogue of the standing rule in
   `GLOBAL_ALARM_PHASE2B.md` §H: *"Jangan membuat semua EF yang saat ini OFF
   menjadi historical alarm sekaligus saat startup."* Without this rule, the
   first run after deployment would write ~142 fabricated "OFF → ON"
   transitions and the startup/shutdown report would be worthless.

4. **Write in the same transaction as the current-state write**, under a
   savepoint so a transition-write failure cannot roll back the live-value
   write. `ef_polling_engine_claude_v2.py` already uses exactly this
   `SAVEPOINT global_alarm` pattern for its additive alarm writer; reuse it.

5. **Never write NULL/NaN/invalid as 0.** A point whose read failed is not
   "OFF". Skip it — same rule the Azbil removal task already imposes.

6. **Flapping.** A state that oscillates around a threshold would flood the
   table and bury real transitions. Options: (i) record every transition and
   let the UI filter, or (ii) require a minimum dwell time (e.g. state must hold
   ≥ N seconds before a transition is recorded). **Recommendation: (ii), with a
   configurable N**, because the page's stated purpose is a *factory
   startup/shutdown report*, which cares about real transitions, not contact
   bounce. This needs a decision from you.

---

## 6. Writers required — four separate codebases

This is the largest part of the work, and it is not confined to the web app.

| Poller | Codebase | Language | Points | Service |
|---|---|---|---|---|
| Azbil BACnet | `C:\Projects\azbil_bacnet\Program.cs` | C# | ~142 status points | `AzbilBacnetPoller` |
| EF panels | `C:\Projects\monitoring_ef\ef_polling_engine_claude_v2.py` | Python | 55 fans | `HVACEFPollingNSSM` |
| HNX G8 | `C:\Projects\ahu_hnx_g8\hnx_g8_poller` | Python | 6 fan status | `HVACHNXG8PollingNSSM` |
| Daikin | `C:\Projects\ahu_ef_daikin\ahu_poller_unified.py` | Python | EF 58–61 | `DaikinPoller` |

Each needs: the state read-back query, the edge comparison, the silent-first-
observation rule, and the savepointed insert. **Roughly 142 candidate ON/OFF
points exist** (93 AHU + 22 CT + 9 CCP + 9 CHILLER + 9 CHWP status points, plus
EF and G8 fans).

**Volume estimate.** Edge-on-change at a 5 s poll is low-volume: a few hundred
rows/day in steady state, low thousands during a full plant start/stop. Even
generously this is ~10⁵ rows/year — small. Retention is therefore a policy
choice, not a capacity emergency. `alarm_events` currently has no retention and
grows unbounded; I would recommend a documented retention window for
transitions rather than repeating that, but it is not urgent.

---

## 7. Remote database

`docs/DATABASE_ARCHITECTURE.md` records a dual-write to Hostinger
(`u468140406_hvac`) by the same pollers, and that local/remote parity was
**never established**. Decision needed: should `equipment_transitions` be
dual-written, or local-only? Dual-writing doubles the writer surface and the
schema must be applied to both. **Recommendation: local-only for the first
release**, so the feature ships without depending on an unverified remote path.
Flagging it because the answer changes the poller work.

---

## 8. Phased plan (once approved)

| Phase | Work | Touches |
|---|---|---|
| 0 | You resolve §3.1 and §3.2; decide flapping (§5.6) and remote (§7) | — |
| 1 | Hand you the DDL; you apply it manually to `hvac_current` | DB (manual) |
| 2 | Add the writer to **one** poller (EF — smallest, 55 points, already has the savepoint pattern) and observe | `monitoring_ef` |
| 3 | Add to the remaining three pollers | azbil, g8, daikin |
| 4 | Build the Activity Log page + `api_activity.php` over `alarm_events` (WARNING/ALARM) and `equipment_transitions` (ON/OFF) | web app |
| 5 | Verify per the task's checklist | — |

**Phases 1–3 are poller/DB work outside this repository.** They also require
stopping and restarting Windows services, which the standing instructions
forbid me from doing. Phase 4 is the only part I can complete end-to-end myself.

---

## 9. What I recommend

Given that **two thirds of the Activity Log page is already buildable today**,
the pragmatic path is to decouple the two halves:

- **Now:** build the Activity Log page over `alarm_events` only — WARNING
  (2,759 rows), ALARM (43 rows), date/equipment/area filters, text search,
  pagination, and Excel export. This is real, useful, and needs no schema or
  poller change. The ON/OFF filter button renders but shows an explicit
  empty-state naming the missing writer, rather than silently returning nothing.
- **Then:** run phases 0–3 for ON/OFF history as a separate, reviewed workstream,
  after which the ON/OFF filter lights up with no page rewrite — the endpoint
  already queries the right table.

This delivers the operationally useful half immediately and keeps the schema
and poller changes behind an explicit decision rather than smuggling them in.

---

## 10. Open questions

1. **§3.1** — Is "no history tables in `hvac_current`" a permanent ban or a
   deferral? *(Blocking.)*
2. **§3.2** — Who applies the DDL, and to which databases?
3. **§5.6** — Record every transition, or apply a minimum-dwell debounce?
4. **§7** — Dual-write to Hostinger, or local-only?
5. **Retention** — bounded window, or unbounded like `alarm_events`?
6. **Scope of ON/OFF** — all ~142 status points, or a named subset (e.g. only
   AHU and CHILLER, the equipment a startup report actually covers)?
