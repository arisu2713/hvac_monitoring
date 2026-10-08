# HVAC Monitoring — Project Documentation

Wall-display dashboard for building HVAC equipment status. PHP + vanilla JS,
no framework, no build step. Runs under XAMPP (`C:\xampp\htdocs\hvac_monitoring`),
reads from MariaDB `hvac_current`. Auto-refreshes every 10 seconds.

Read this file before making changes. Update it whenever a decision changes.

---

## 1. Architecture

- **Local (source of truth):** XAMPP on a Windows PC on-site. Poller writes
  live BACnet/S-Inet data into `hvac_current`. `index.php` here is served on
  `localhost:8080` and reads directly from the local DB.
- **Hosting (public/remote view):** Hostinger shared hosting, MySQL DB
  `u468140406_hvac` (host `srv1763.hstgr.io`, port 3306). Same schema as
  local (imported via `mysqldump --no-data`). Remote MySQL is allowed
  (coordinator confirmed) — this is **Option A**: the poller writes to
  *both* the local DB and the hosting DB (dual-write). Local DB keeps
  running regardless of internet status; hosting DB is a live mirror.
- **Deployment:** PHP files will be deployed to hosting via **GitHub**
  (coordinator's decision), once poller dual-write is working. `config.php`
  must NOT be committed — see Security section.
- phpMyAdmin for the hosting DB: `https://auth-db1763.hstgr.io` (different
  host than the DB connection host — normal for Hostinger, just a portal).

## 2. Files

| File | Purpose |
|---|---|
| `index.php` | Entire frontend — auth gate, HTML, all JS inline. Tabs: AHU, HVAC SC, ROOM TEMP & RH, EXHAUST FAN, AHU G8 (placeholder, not built yet), ALARM, ENERGY. Polls the relevant `api_*.php` every 10s, plus `api_alarm.php` on its own 10s timer (Section 12). ENERGY, and ROOM TEMP & RH while its Hourly History view is on screen, are history views — loaded once per filter change, never polled (Section 13). |
| `login.php` / `logout.php` / `auth.php` | Session-based auth. `auth.php` has `db()` singleton and `require_login()`. |
| `config.php` | DB credentials. Gitignored. **Not** committed, never was. |
| `api_ahu.php` | AHU units — parses `points.point_name` like `AHU 12 Status` / `Alarm` / `Duct Temperature`. |
| `api_hvac_sc.php` | CHILLER / CCP / CHWP / CT. Parses `point_name`. Handles G3 chillers (6-9) specially — see Section 4. |
| `api_room.php` | ROOM TEMP & RH + OUTDOOR. Parses `point_name`, merges TEMP+RH pairs into one card per room. |
| `api_ef.php` | Exhaust fans — separate `ef_points` / `ef_current` tables, keyed by `panel_no`. |
| `api_alarm.php` | **Global alarm feed** — read-only SELECT over `alarm_events`, returns active counts + rows. See Section 12. |
| `api_energy.php` | **Daily kWh history** for the ENERGY tab — reads `kwh_total_{ct,ccp,chwp}` + `kwh_usage_{ct,ccp,chwp}`, discovers unit columns from `information_schema`. See Section 13. |
| `api_temp_rh_hourly.php` | **Hourly temp/RH history** for the Hourly History view inside ROOM TEMP & RH — reads `temp_rh_hourly` (long format) and pivots to one column per room in PHP. See Section 13. |
| `api.php` | **Does not exist.** The file is absent from disk and was never tracked in git (HEAD included). It was legacy/dead code (old `equipment_type`/`role` schema) and is already gone; only the stale `API_URL` reference in `index.php` remains — see Section 8's "Known cosmetic quirk". |
| `style.css` / `ef.css` | Styling. Room cards use a teal theme; outdoor cards use indigo (`room-outdoor` class). Also holds the alarm-value colours (Section 8), the AHU card typography (Section 9), the Global Alarm page (Section 12), and the history tables + filter bar (Section 13). |

## 3. Database schema (current, as deployed)

`points` table (current schema — **not** the old `equipment_type`/`role` schema
that `api.php` still expects):

```
point_id, device_id, obj_type, instance, point_name, equip_type
```

`equip_type` values: `AHU`, `CCP`, `CHILLER`, `CHILLER 1 MODBUS` …
`CHILLER 9 MODBUS`, `CHWP`, `CT`, `ROOM TEMP & RH`.
Latest values live in `ai_current` (float) / `bi_current` (tinyint), keyed by
`point_id`, with `read_at`. Exhaust fans use `ef_points` / `ef_current`
instead (separate `id`/`ef_point_id`, `panel_no`, `ef_name`).

### Point naming conventions (point_name)

- **AHU:** `AHU <n> Status|Alarm|Duct Temperature`
- **CCP / CHWP:** `<TYPE> <n> Status|Alarm|AL|Current|Frequency|Drive Temp`
  (`AL` and `Alarm` both mean alarm — CHWP uses both inconsistently)
- **CT (cooling tower):** `CT <n> Current|Frequency|Drive Temp` = tower-level
  (shared VSD), `CT <n><A|B> Status|Alarm|Return Temp` = cell-level.
  `api_hvac_sc.php` makes cells inherit Current/Frequency/Run/Alarm/**Drive
  Temp** from the tower entry, then drops the tower-level entry (only cells
  are returned to the frontend). Exception: **CT 5A/5B/6A/6B have their own
  cell-level Drive Temp**, so those keep their own value instead of
  inheriting. CT 7 and CT 8 have no points at all. Drive Temp is a VSD
  temperature and is **not** the cell `Return Temp` — the two are separate
  points and must never be substituted for one another.
- **CHILLER G1-G2 (chillers 1-5):** `Chiller <n> Evap Supply Temp` / `Evap
  Return Temp` / `Cond Supply Temp` / `Cond Return Temp`, plus
  `CHILLER <n> Status|Alarm`.
- **CHILLER G3 (chillers 6-9):** different sensor layout, no per-chiller
  condenser/evap-entering sensors:
  - Evap leaving (EL): `Temp Out Chiller <n>` — per chiller
  - Evap entering (EE): `Temp Return Header Chil G3` — ONE shared sensor for
    chillers 6-9
  - Cond entering (CE): `Temp Return Head Conden G3` — ONE shared sensor
  - Cond leaving (CL): `Temp Supp Head Condenso G3` — ONE shared sensor
  - Other G3 points (`Flow Zone #`, `Temp Supply/Return Zone #`) are zone
    metering, not chiller metrics — intentionally skipped.
- **CHILLER Modbus (SP / RLA, all 9 chillers):** stored under their own
  `equip_type` values — `CHILLER <n> MODBUS` (n = 1..9), **not** under
  `CHILLER`. Per unit, 27 points:
  - Setpoint (SP): `SETPOINT CHILLER-<n>`
  - RLA: `RLA-<n>`
  - `Active Setpoint RLA-<n>` also exists (constant `100`) but is
    **deliberately not used** — it is a scale/limit, not the setpoint.
  `api_hvac_sc.php` reads these in a **separate query** (the main
  `CHILLER/CCP/CHWP/CT` query is unchanged) and merges the results into
  `setpoint` / `rla` by chiller number. Chillers **1 and 3 have no Modbus
  connection yet** — their rows exist in `points` but `ai_current` stays
  NULL, so their SP/RLA are legitimately `null`. Never substitute dummy
  values or scale/correct the numbers (e.g. Chiller 4 setpoint `50` is
  sent as-is).
- **ROOM TEMP & RH:** `ROOM TEMP <label>` / `ROOM RH <label>` (label = room
  number, `OVC`, or `SE <n>`) — merged into one card per label.
  `Outdoor Temperature G<n>` / `Outdoor Humidity G<n>` — separate OUTDOOR
  cards, indigo theme.

### Known field-mapping decision (IMPORTANT — do not "fix" without asking)

For **G1-G2 chillers**, `cond_entering_temp`/`cond_leaving_temp` mapping is
**currently swapped** (Cond Supply Temp → cond_entering, should be
cond_leaving, based on the Azbil graphic reference which shows Supply as the
hotter/leaving side). **User asked to postpone this patch** — do not change
without explicit confirmation. G3 chillers already use the corrected mapping
(Supply = leaving/hot, Return = entering/cool), confirmed against the Azbil
SCADA graphic screenshot.

SP (setpoint) and RLA come from the separate Modbus query described above
(added 2026-09-30). They were previously always `null` because no such point
existed under `equip_type='CHILLER'`; the points were later added under
`CHILLER <n> MODBUS`. Chillers 1 and 3 still have no Modbus link, so their
SP/RLA remain `null` by design — that is expected, not a bug.

## 4. Security setup (done)

- DB user for the web app: `hvac_web` — **SELECT only** on `hvac_current.*`
  (verified: `CREATE TABLE` is denied). `config.php` uses this, not `root`.
- `root` password was **not yet rotated** — still pending confirmation of
  everything else using `root` (poller service, phpMyAdmin config) before
  changing it.
- A `hvac_cron` user exists locally (SELECT on `ai_current`/`points`, INSERT
  on `ai_hourly`) for the hourly-history cron script.
- PHP timezone was left as XAMPP default (likely `Europe/Berlin`) —
  **not yet fixed**. Causes `server_time` in API JSON responses to be ~5h
  off from `read_at` (which is correct, written in WIB). Low impact today
  (frontend doesn't compare them), but must fix before building any
  "data is stale" indicator. Fix: set `date.timezone = Asia/Jakarta` in
  `php.ini` and restart Apache.

## 5. Known issues / audit findings (from a Claude Code + Deepseek audit)

Priority order agreed with user. **Items 1–5 below are now FIXED** (verified
2026-10-02) — kept for history, with the resolution noted. Items 6–7 still
stand.

1. ~~**`api_ahu.php` hardcodes `dbname=hvac_current`**~~ — **FIXED.** It now
   uses `$config['database']` like the other API files (commit `34c904d`).
2. ~~**`.gitignore` has `*.backup*`, which does not match `*.bak`.**~~ —
   **FIXED.** `*.bak` is now in `.gitignore` (commit `3131c7b`).
3. ~~**Run `git add -A` before the next commit**~~ — resolved; the staged
   state was cleared. Verify with `git status` before committing anyway.
4. ~~**`display_errors`/`expose_php` On, and every API's catch block does
   `echo $e->getMessage()`.**~~ — **FIXED.** All four current-schema APIs now
   `error_log()` the real message server-side and return a generic error to
   the client (commit `7e61380`).
5. ~~`session_write_close()` is missing in `api_ef.php`~~ — **FIXED** (same
   commit `7e61380`); present in all four APIs.
6. `api.php` is **already gone** — absent from disk, never tracked in git.
   The only residue is the stale `API_URL = "api.php"` in `index.php`, which
   makes the AHU G8 tab log a 404 (see Section 8). Nothing to delete.
7. Session cookie hardening (`httponly`, `samesite`) and login rate-limiting
   — matter most once the hosting version is reachable from the public
   internet. Not urgent for the local LAN-only instance.

## 6. Deployment plan (GitHub → hosting)

- Poller needs dual-write support added (writes to local DB as today, PLUS
  hosting DB `srv1763.hstgr.io`). Poller code not yet reviewed/modified —
  pending user sharing it.
- Dual-write must not block/slow local writes if the hosting connection is
  slow or down (short timeout, isolated try/catch, no retry queue built
  yet — open decision).
- Static reference tables (`points`, `ef_points`, `daikin_points`) need a
  one-time data-only import to hosting (`mysqldump --no-create-info`) — not
  yet done. Live tables (`ai_current`, `bi_current`, `ef_current`, etc.)
  should only ever be filled by the poller, never copied manually.
- `users` table on hosting should be populated with fresh accounts, not
  copied from local (avoid spreading local password hashes).
- `config.php` must differ between local and hosting (different DB host)
  and must not be overwritten by GitHub deploys. Plan: `.gitignore` on
  `config.php`, keep a `config.example.php` template in the repo, and
  create the real `config.php` manually on the hosting server (outside of
  the deploy step).
- Optional: hourly ROOM TEMP & RH history. **The `ai_hourly` table described
  in earlier revisions of this file was never created** — the implemented
  version uses `temp_rh_hourly` (long format, one row per point per hour),
  defined in `temp_rh_hourly.sql` and written by `temp_rh_snapshot.php`.
  Both scripts are **CLI-only** (`PHP_SAPI !== 'cli'` → 404) and must be run
  by a cron job — on hosting there is no MariaDB Event Scheduler / `EVENT`
  privilege. See Section 11.

```sql
-- Superseded by temp_rh_hourly.sql; kept only to show the abandoned shape.
CREATE TABLE IF NOT EXISTS ai_hourly (
    recorded_at DATETIME    NOT NULL,
    point_id    VARCHAR(32) NOT NULL,
    value       FLOAT       NULL,
    read_at     DATETIME    NOT NULL,
    PRIMARY KEY (recorded_at, point_id),
    KEY idx_point_time (point_id, recorded_at)
) ENGINE=InnoDB;
```

## 7. Open questions / not yet decided

- How exactly does the poller currently write to the local DB (language,
  libraries, write pattern)? Needed before adding dual-write.
- Is the building's internet connection static or dynamic IP? Matters for
  whether Hostinger's Remote MySQL IP whitelist stays valid long-term.
- Should the condenser temp mapping for G1-G2 chillers be corrected now or
  later? (Currently postponed at user's request — see Section 3.)
- AHU G8 data source is not ready — tab stays a placeholder until then.

## 8. Threshold / alarm indicators (added 2026-10-02)

Cards whose reading crosses a configured limit are flagged visually
(`.value-alarm` in `style.css`, `@keyframes blink-alarm`). The frontend only
decides *how* to flag; all limits come from the API as extra JSON fields.

### Reference tables (read-only from this app)

Maintained outside the app (seeded by `threshold_rules_seed.sql`, imported
from `threshold_rules_ed3.xlsx`) — the app **only reads** them, never writes,
and never alters their schema:

- `threshold_direct(equip_type, metric, min_value, max_value)`
- `threshold_by_kw(motor_kw, min_value, max_value)`
- `unit_motor_kw(equip_type, unit_name, motor_kw)` — `equip_type` in
  `CCP` / `CHWP` / `CT`

### Fields emitted per endpoint

| Endpoint | Field | Source |
|---|---|---|
| `api_ahu.php` | `temp_max` | `threshold_direct` AHU/temp |
| `api_room.php` | `temp_max`, `rh_max` | `threshold_direct` ROOM/temp, ROOM/rh — **ROOM entries only**; OUTDOOR deliberately gets no limits |
| `api_hvac_sc.php` | `evap_leaving_temp_max`, `cond_entering_temp_max` | `threshold_direct` CHILLER |
| `api_hvac_sc.php` | `current_min`, `current_max` | `unit_motor_kw` → `threshold_by_kw` (CCP / CHWP / CT) |
| `api_hvac_sc.php` | `drive_temperature` | `points` `"<TYPE> <n> Drive Temp"` ← `ai_current` (CCP / CHWP / CT only — **no threshold, never blinks**) |

All are floats or `null`. **`null` means "no limit configured" — never an
alarm**, and the unit is always still returned (a missing threshold must
never hide a unit from the dashboard).

### CCP / CHWP / CT current-limit resolution

1. Exact `(equip_type, unit_name)` match in `unit_motor_kw`.
2. If not found **and** the name ends in a letter (`5A`), retry without the
   trailing letter (`5`) — cooling tower cells share one motor/VSD per tower.
3. Look the resulting `motor_kw` up in `threshold_by_kw`.
4. Unresolved at any step → `current_min` / `current_max` stay `null`.

All three tables are loaded into PHP arrays **once per request**, not queried
per unit.

### Frontend rule (`index.php`)

`isOutOfRange(value, min, max)` flags a value only when it is present,
numeric, and outside a non-null bound. `null`/empty/non-numeric values are
never flagged, so a missing reading is not an alarm. Applied to: AHU duct
temp, ROOM temp + RH (not OUTDOOR), chiller EL + CE, and CCP/CHWP/CT current.
SP / RLA / EE / CL, the CT frequency, and `drive_temperature` have no
thresholds and never blink.

### Alarm value colours

Default alarm value is **red** (`#ef4444`) and blinks.

**Exception — OFF cards.** An OFF card is itself red (`#b51d1d`, the
`.status-off` background), so a red value on it is unreadable. On any card
carrying `.status-off`, `.value-alarm` renders **black** (`#000000`) while
keeping the *same* `blink-alarm` animation and duration. This is the only
difference; the card's own background, border and status styling are never
touched, and no status/colour logic was changed to achieve it.

```css
.value-alarm            { color: #ef4444 !important; }  /* red  */
.status-off .value-alarm { color: #000000 !important; }  /* black */
```

`.status-off` is the DOM's own status marker (`index.php` sets
`status-<status>` from `getStatus()`, which returns `off` when `run === 0`),
so the rule keys off existing status information rather than inferred pixel
colour. Status backgrounds for reference: `.status-off` red `#b51d1d`,
`.status-alarm` orange `#d87500`, `.status-run` green `#0b9f50`,
`.status-unknown` grey `#555`. Room cards carry **no** status class (dark
theme, never red), so they always keep the red alarm value; EF cards do go red
when off but render no numeric values, so no `.value-alarm` can exist there.

The blink is suppressed under `prefers-reduced-motion: reduce` (the colour is
kept, the animation is dropped).

### Known cosmetic quirk (pre-existing, unrelated)

The **AHU G8** tab logs two console errors (a 404 and the resulting
`API returned success=false`). `index.php` still points that tab at the
legacy `API_URL = "api.php"`, but `api.php` **does not exist on disk** — it
was already absent and already referenced in HEAD before this change, so it
is not caused by the alarm work. Also, `renderEquipment()` returns early for
AHU G8 *before* `safeLoadData()` runs, so the placeholder renders and the
fetch still fires. Left untouched here; worth fixing when AHU G8 is built
(see Section 5 item 6 — `api.php` is already absent, only this stale
reference remains).

## 9. AHU card typography (added 2026-10-02)

Internal text styling for AHU cards only — the duct temperature was too small
to read from the wall display, and the `AHU xx` name sits slightly higher.
**CSS only** (`style.css`); no PHP, JS or API was touched, and no card box
property (size / padding / border-radius / grid / gap / columns) changed.

Scope selector: `.unit-card:not(.room-card)` is exactly the AHU cards —
room cards always carry `.room-card`, while HVAC SC and EF cards use their own
classes (`.hvac-card` / `.m-val`, `.ef-card` / `.ef-name`), so they are
unaffected. No marker class was added to the DOM.

```css
.unit-card:not(.room-card) .unit-name  { transform: translateY(-2px); }
.unit-card:not(.room-card) .unit-value { font-size: 15px; margin-top: 2px; }
/* + per-breakpoint sizes, each ~+25-33% of that breakpoint's original */
```

Value font per breakpoint (original → new):

| Breakpoint | original | new | change |
|---|---|---|---|
| base | 12px | 15px | +25% |
| ≥1600px (1920 wall display) | 10px | 13px | +30% |
| ≥2500px (4K) | 16px | 21px | +31% |
| ≤900px (tablet) | 9px | 12px | +33% |
| ≤600px (phone) | 9px | 12px | +33% |

Two things worth knowing before editing this again:

1. **The name lift uses `transform`, not margin/padding.** `transform` is
   visual-only and cannot affect layout, which *guarantees* card height and
   grid stay identical. The freed space is reclaimed by the value's
   `margin-top` (4px → 2px) so the larger number still fits.
2. **The base rule needs its explicit `@media` blocks.** It is more specific
   than the original `.unit-value` rules, so without them it would force 15px
   at *every* width and override the 4K and phone sizes.

**Base size is 15px, not larger, on purpose.** The AHU 94-97 placeholder cards
carry three lines of text and are the tightest fit. 15px fits; **16px grows
those cards from 78px to 78.7px** and shifts the grid. If a bigger number is
wanted later, the placeholder cards must be dealt with first.

## 10. VSD Drive Temperature — CCP / CHWP / CT (added 2026-10-02)

The VSD ("drive") temperature for the pumps and cooling towers is now read
and shown on the compact CCP / CHWP / CT cards as **`Drv Tmp:`** (label
abbreviated to fit the narrow card; the JSON field is the unabbreviated
`drive_temperature`).

### Point mapping (verified against the DB, not guessed)

All 31 points are named exactly `<TYPE> <n> Drive Temp`, `obj_type = 'AI'`,
`equip_type` in `CCP` / `CHWP` / `CT`:

| Equipment | Points | Notes |
|---|---|---|
| CCP | 1–9 (9) | tower-level |
| CHWP | 1–9 (9) | tower-level |
| CT | 1, 2, 3, 4, 9, 10, 11, 12, 13 (9) | tower-level |
| CT | 5A, 5B, 6A, 6B (4) | **cell-level — these have their own Drive Temp** |

CT 7 and CT 8 have no points at all. The remaining CT cells (1A–4A/B, 9A–13A/B)
have no Drive Temp of their own and **inherit** the tower's value through the
same inheritance loop that already carries `run` / `alarm` / `frequency` /
`current`.

**`Drive Temp` is not `Return Temp`.** They are two distinct points on a CT
cell — `Return Temp` is the water temperature, `Drive Temp` is the VSD. The
implementation never substitutes one for the other.

### Implementation

No new query was needed: the points already flow through the existing
`points` JOIN `ai_current` main query, because the main loop parses
`point_name` generically. Only the field mapping was added:

```php
'drive temp' => 'drive_temperature',   // HVAC_SC_FIELDS
```

plus `'drive_temperature' => null` in the CT and CCP/CHWP blank cards, and
`drive_temperature` added to the CT cell-inheritance field list.

The field is emitted for **CCP / CHWP / CT only** — chiller cards do not
carry it. It has **no threshold**, so it never receives `.value-alarm`.

### Operational note

Adding the mapping is not enough on its own: the **poller** must also write
these points into `ai_current`. While the mapping was being added, all 31
points existed in `points` but had **zero rows in `ai_current`**, so the API
correctly returned `drive_temperature: null` (rendered `-- °C`). The poller
has since picked them up (all 31 now have values). If Drive Temp ever shows
`-- °C` again, check `ai_current` for those `point_id`s before touching this
code — the mapping is not the likely cause.

## 11. Snapshot / history scripts (added 2026-10-02)

Two standalone CLI-only scripts write history tables. **Neither is called by
the web app** — no `include`, no HTTP route, no JS reference. They are
invoked from outside the repo (cron on hosting, or run manually), and they
open their own PDO connection from `config.php`.

| Script | Writes | Reads | Cadence |
|---|---|---|---|
| `temp_rh_snapshot.php` | `temp_rh_hourly` | `points` JOIN `ai_current` (ROOM TEMP & RH, `obj_type='AI'`) | hourly |
| `kwh_daily_snapshot.php` | `kwh_total_{ct,ccp,chwp}`, `kwh_usage_{ct,ccp,chwp}` | `points` JOIN `acc_current` (`obj_type='ACC'`) | daily |

Both guard with `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`,
pin `date_default_timezone_set('Asia/Jakarta')` and `SET time_zone = '+07:00'`
(the hosting server itself is UTC, while `read_at` is naive WIB), and use
`INSERT IGNORE` so a double run is a no-op rather than a duplicate.

`temp_rh_snapshot.php` parses `point_name` with the **same patterns as
`api_room.php`** (ROOM/OUTDOOR), so a point that renders on the page is a
point that gets stored. `kwh_daily_snapshot.php` also computes daily usage as
`today − yesterday`, storing `NULL` (never a wrong number) when yesterday's
snapshot is missing or the diff is negative (meter reset/replaced).

**Schema note:** `temp_rh_hourly.sql` is in the repo; the `kwh_total_*` /
`kwh_usage_*` tables have **no schema file in the repo** — they exist in the
DB only. Worth adding before deploying.

**Current state:** no scheduler was found on the local machine (no Windows
Task Scheduler entry, no MariaDB `EVENT`, no runner script in the repo). Both
scripts appear to be run manually today. `ai_current` is overwritten in place,
so `temp_rh_snapshot.php` can only capture one instantaneous sample per hour —
per-hour min/max/avg cannot be reconstructed afterwards.

---
*Keep this file up to date as decisions change — it exists so no session
(human or AI) has to rediscover this context from scratch.*

## 12. Global Alarm page (added 2026-10-06)

The web app now has an **ALARM** tab — a global, read-only view over
`hvac_current.alarm_events`, the table the two pollers write
(`BACNET_POLLER` and `EF_POLLER`). No new table was created and no schema was
changed.

### ALARM vs WARNING

`alarm_events.event_class` is an enum with exactly two values, and they are
kept conceptually separate everywhere (badge, row accent, summary counters):

- **ALARM** — a native equipment alarm/status, raised by the poller from a
  device's own alarm point (`TryBuildNativeAlarm`).
- **WARNING** — a threshold / value-limit violation, raised when a reading
  falls outside its configured bounds (`TryBuildWarning`).

### ACTIVE vs CLEARED

`alarm_events.status` is `ACTIVE` or `CLEARED`. The page renders the two as
separate groups: ACTIVE first, with a red/amber left accent and a red-tinted
row background; CLEARED below in a receded grey group limited to the recent
window. Active rows are the visually prominent ones.

The poller clears a row in place (`status='CLEARED'`, `cleared_at` set,
`active_key=NULL`); a recurrence inserts a **new** row, so a cleared row is
immutable history and never reactivates.

> **Not the same thing as the per-card alarm colours.** The red/amber
> blinking on the AHU/EF/room cards comes from each `api_*.php` returning an
> instantaneous `alarm` flag and `*_min`/`*_max` bounds (Section 8). That is a
> live snapshot with no identity or history. This page is the event log with
> stable ids and a lifecycle. They can disagree — a card can be red with no
> event row yet (the poller has not run), and a CLEARED event row can exist
> while a card is back to normal.

### Alarm API — `api_alarm.php`

Same auth model and conventions as the other endpoints: `require_once
auth.php`, 401 JSON when `$_SESSION['user_id']` is empty, `session_write_close()`,
`Content-Type: application/json`, PDO with `ERRMODE_EXCEPTION` and
`FETCH_ASSOC`, and a `try/catch` that logs server-side and returns a generic
`{"success":false,"error":"Internal server error"}`.

It is **read-only** — a single `SELECT`, no writes of any kind.

```json
{
  "success": true,
  "counts": { "active_alarm": 0, "active_warning": 0, "active_total": 0 },
  "window_hours": 24,
  "server_time": "2026-10-06 05:55:00.000000",
  "alarms": [ { "id": 1, "event_class": "WARNING", "status": "ACTIVE", ... } ]
}
```

- `id` is `alarm_events.id` (bigint unsigned PK) — the **stable event
  identifier**, and what the browser keys new-alarm detection on.
- `active_key` is deliberately **not** returned: it is the pollers' internal
  de-duplication key, not something the page needs.
- The three counts are computed over the whole table (`WHERE status='ACTIVE'`),
  not over the returned rows, so they stay correct when history is truncated.
- Cleared rows are windowed (`?hours=`, default 24, clamped 1–168) because
  history grows without bound; ACTIVE rows are always returned whatever their
  age. `?limit=` (default 500, clamped 1–1000) bounds the payload.
- Ordering is `(status='ACTIVE') DESC, raised_at DESC`, so active rows lead.
- `server_time` uses the database clock (`NOW(6)`), the same clock the pollers
  stamp `raised_at` with.

### Refresh behaviour

Two independent 10s timers in `index.php`:

- `safeLoadData()` — the visible equipment page.
- `safeLoadAlarmData()` — always `api_alarm.php`, whatever tab is on screen,
  so a new alarm still sounds while another page is being watched. The alarm
  response is kept in `allData.ALARM`.

To avoid fetching `api_alarm.php` twice per cycle, `loadData()` only calls
`loadAlarmData()` on a first visit to the ALARM tab (when `allData.ALARM` is
empty) and otherwise just re-renders the data the alarm poll already
refreshed. `renderAlarm()` clears `#equipmentGrid` itself, because the
background poll re-renders this page directly rather than going through
`renderEquipment()`.

### New alarm detection

Keyed on the stable `id`, never on row position or array order:

1. The **first** response only records the set of active ids
   (`knownAlarmIds`) and is silent — alarms already active on page load never
   sound.
2. Each later response diffs against that set. A newly appearing **ACTIVE**
   id sounds once; `knownAlarmIds` is then replaced with the current set.
3. A CLEARED row is not an active alarm and never sounds.
4. Ids are tracked even while muted, so unmuting does not replay alarms that
   arrived during the mute.

### Sound

A Web Audio API tone — no audio file, no CDN, no third-party service. Two
880 Hz square-wave beeps with short gain ramps (a raw square wave clicks).
The `AudioContext` is created lazily and reused.

Browsers suspend audio until the user interacts. This is handled by **not**
fighting it: if the context is suspended when an alarm arrives, `resume()` is
attempted, and on failure a notice appears in the alarm toolbar telling the
user to press MUTE then UNMUTE. That click is the user gesture that unlocks
the context. Nothing bypasses the browser's autoplay policy.

### Mute / UNMUTE

The MUTE button sits in the alarm toolbar. Mute:

- silences the sound only;
- does **not** hide, filter, or reorder the list;
- does **not** clear, acknowledge, or touch an alarm;
- does **not** write to the database — `api_alarm.php` is read-only and the
  mute state never leaves the browser.

State persists in `localStorage` under `hvac_alarm_muted` (`"1"` / `"0"`) and
is read back before the first poll, so a refresh during an active alarm never
produces a burst of sound. Both `localStorage` accesses are wrapped in
`try/catch` — private mode or blocked storage degrades to "unmuted for this
session" rather than breaking the page.

### Layout

A compact table-like grid: summary counters (Active Alarm / Active Warning /
Total Active) plus a MUTE button, then the ACTIVE group and the CLEARED group.
Columns are Category, Status, Equipment, Source/point, Message, Limits, Raised
at, Cleared at, Event ID.

At ≤900px the header row is hidden and each row becomes a stacked card whose
cells carry their own column label via `::before { content: attr(data-label) }`.
Verified with no horizontal overflow at 1920×1080, 1366×768 and 390×844.

---

## 13. ENERGY page and the ROOM TEMP & RH hourly view (added 2026-10-06)

Two **history** views reading the tables written by the Section 11 snapshot
scripts. Both are read-only: no schema change, no poller change, no write of
any kind. Neither table had a frontend before this.

**ENERGY is a nav tab of its own. The hourly temp/RH table is NOT** — it is a
second view *inside* the existing ROOM TEMP & RH page, reached with a
Current / Hourly History switch. There is no `TEMP & RH HOURLY` tab.

| View | Reached by | API | Table(s) | Shape in DB | Shape on screen |
|---|---|---|---|---|---|
| ENERGY | its own nav tab | `api_energy.php` | `kwh_total_{ct,ccp,chwp}`, `kwh_usage_{ct,ccp,chwp}` | wide, one row per day | wide, one row per day |
| ROOM TEMP & RH → Hourly History | the in-page switch | `api_temp_rh_hourly.php` | `temp_rh_hourly` | **long**, one row per point per hour | wide, one row per hour |

### ENERGY

One row per day, newest first, **one column per unit showing daily usage
only**. Category selector: **CT / CCP / CHWP** — three independent table
pairs, never mixed in one view. Column headers are prefixed with the
equipment category (`CT 1`, `CCP 1`, `CHWP 1`, …) rather than a generic
`Unit 1`, so a screenshot of the table says which equipment it is about.
The last column is the day's `Usage Sum`.

**The cumulative TOTAL kWh is backend-only and is deliberately NOT
displayed.** It exists so `kwh_daily_snapshot.php` can diff one day against
the previous one to produce `kwh_usage_*`; it is calculation data, not
something an operator reads off the wall display. `api_energy.php` still
returns `values[unit].total` and `sum_total` (they are needed to derive and
cross-check usage, and other consumers may want them) — the UI simply never
reads those fields. Do not "restore" a TOTAL column without asking: showing
it was explicitly rejected.

**Unit columns are discovered, not hardcoded.** `api_energy.php` reads the
column list from `information_schema.columns`, keeps only names matching
`/^[a-z]+_(\d+)$/` with the category's own prefix, and sorts by unit number.
This is deliberate: **CT has units 1–6 and 9–13 — there is no unit 7 or 8**
(consistent with Section 10, where CT 7/8 have no points at all). A hardcoded
`1..13` list would render two permanently empty columns and imply data is
missing when it is not. Add a unit to the kWh table and the column appears on
its own.

The unit number is re-validated against the pattern *before* it is used in SQL
text, and the category picks the table/prefix from a fixed PHP map — no column
or table name is ever built from request data.

**`NULL` usage is not `0`.** `kwh_daily_snapshot.php` stores `NULL` when there
is no previous day to diff against (or the diff went negative). The earliest
day in the table is therefore all-`NULL` usage; the page renders those cells as
a dimmed `--`, and a genuine `0.0` stays visibly different from it. Never
coerce `NULL` to `0` — "no consumption recorded" and "zero consumption" are
different facts.

### ROOM TEMP & RH — the Current / Hourly History switch

ROOM TEMP & RH hosts **two views under one nav tab**, switched in place by a
`Current` / `Hourly History` control (`#pageSwitch`, built by
`buildPageSwitch()`). Switching is a re-render, **not a navigation**: the nav
tab stays highlighted, the section title stays `ROOM TEMP & RH`, and the URL
does not change. `roomView` (`ROOM_VIEW_CURRENT` / `ROOM_VIEW_HOURLY`) holds
the mode.

- **Current** — the existing live room cards, unchanged, from `api_room.php`.
  It has no filter bar; the switch is its only control.
- **Hourly History** — the hourly table below, from `api_temp_rh_hourly.php`.
  It builds the metric + date filter bar.

`loadData()` picks the API from the mode, so the two views are one page with
two data sources rather than two pages. `isHistoryPage()` also follows the
mode: **Current is live** (10s refresh + alarm poll), **Hourly History is
history** (neither). The 10s interval is guarded by `isHistoryPage()` so a
history table is never re-fetched or re-rendered under the reader — which
would also throw away their scroll position.

> **Why this is one page and not a tab.** The hourly table and the room cards
> are the same rooms at two time scales; an operator reading a hot room wants
> the history without losing their place. A separate tab also meant the two
> were unreachable from each other. Do not split them back into two tabs.

### The hourly table

`temp_rh_hourly` is long format (one row per point per hour), so the pivot to
one column per room happens **in PHP**, not in dynamic SQL — no column name is
built from data.

One row per hour, newest first, with a **metric selector (TEMPERATURE / HUMIDITY)**.
Temperature and humidity are deliberately **not** shown side by side: that
would be ~20 room columns and unreadable. Switching metric changes only the
values — the room list query is *not* filtered by metric, so the column set
stays identical and the eye doesn't have to re-find a room after switching.

Room labels are built with the **same rule as `api_room.php`**
(`room_type + ' ' + room_name`), so a room reads the same here as on the live
Current view.

Each row also carries a **`READING AT`** column showing the actual `read_at`
range for that hour. `ai_current` is overwritten in place (Section 11), so a
snapshot is one instantaneous sample; when the sample times within an hour
differ, the column shows `min – max` instead of hiding the spread behind a
single time.

### Filtering

The filter bar (`#pageFilters`, built by `buildFilterBar()`) is shared by the
ENERGY page and the ROOM TEMP & RH hourly view: a selector (category / metric)
plus FROM and TO date inputs, APPLY and RESET.
Dates are validated as real calendar dates server-side (`checkdate`), and a
reversed range is swapped rather than rejected. Range defaults: ENERGY 30 days,
hourly 7 days; each API also reports `available{min,max}` and the view prints
it, so an empty result is distinguishable from "you filtered it away".

The bar lives **outside** `#equipmentGrid` on purpose — a re-render would
otherwise destroy the inputs mid-typing. `renderEquipment()` clears it up
front for every page, so it appears only where a view actually builds one
(ENERGY, and ROOM TEMP & RH in its Hourly History mode).

### Refresh behaviour

**No history view is auto-refreshed, and none polls `api_alarm.php`**
(`isHistoryPage()` guards both the 10s interval and `safeLoadAlarmData()`).
Past data does not change, so a 10s poll would re-fetch identical rows forever
— and re-rendering the table would throw away the reader's scroll position.
The alarm poll is skipped too: a siren triggered from a view that shows
neither alarms nor live equipment, with no way to see what caused it, is
confusing rather than useful.

Leaving a history view re-baselines `knownAlarmIds` silently, so alarms that
arrived while history was on screen are recorded without firing the moment the
user switches back.

### Layout

The tables are div-based like the rest of the app (the app has no `<table>`
markup anywhere). Column count is passed to CSS as `--history-cols`; the first
column is sticky-left and the header sticky-top, and the whole table scrolls
horizontally inside `.history-table-wrap` rather than overflowing the page.
Every DB-derived string is written with `textContent`, never `innerHTML`.

`.equipment-grid:has(.history-page) { display: block }` is **required** — the
base rule is a `repeat(auto-fill, minmax(120px,1fr))` grid, and grid items do
not shrink below their content, so without it a 2091px table overflows the
whole page on a phone instead of scrolling inside its wrapper.

**Both bars are cleared at the top of `renderEquipment()`, not at the end.**
Several of its branches (EF, ALARM, ENERGY) return early, so a clear placed
after them never ran and a switch or filter bar from the previous page stayed
on screen. Each page that wants them builds its own.

Verified with no page overflow at 1920×1080, 1366×768 and 390×844. ENERGY is
13 columns for CT and 11 for CCP/CHWP (down from 24 before the TOTAL columns
were removed) and fits at 1920/1366, scrolling only on a phone; the hourly
table is 12 columns, fitting at 1920 and scrolling below.

---
*Keep this file up to date as decisions change — it exists so no session
(human or AI) has to rediscover this context from scratch.*
