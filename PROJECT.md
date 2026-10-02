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
| `index.php` | Entire frontend — auth gate, HTML, all JS inline. Tabs: AHU, HVAC SC, ROOM TEMP & RH, EXHAUST FAN, AHU G8 (placeholder, not built yet). Polls the relevant `api_*.php` every 10s. |
| `login.php` / `logout.php` / `auth.php` | Session-based auth. `auth.php` has `db()` singleton and `require_login()`. |
| `config.php` | DB credentials. Gitignored. **Not** committed, never was. |
| `api_ahu.php` | AHU units — parses `points.point_name` like `AHU 12 Status` / `Alarm` / `Duct Temperature`. |
| `api_hvac_sc.php` | CHILLER / CCP / CHWP / CT. Parses `point_name`. Handles G3 chillers (6-9) specially — see Section 4. |
| `api_room.php` | ROOM TEMP & RH + OUTDOOR. Parses `point_name`, merges TEMP+RH pairs into one card per room. |
| `api_ef.php` | Exhaust fans — separate `ef_points` / `ef_current` tables, keyed by `panel_no`. |
| `api.php` | **Does not exist.** The file is absent from disk and was never tracked in git (HEAD included). It was legacy/dead code (old `equipment_type`/`role` schema) and is already gone; only the stale `API_URL` reference in `index.php` remains — see Section 8's "Known cosmetic quirk". |
| `style.css` / `ef.css` | Styling. Room cards use a teal theme; outdoor cards use indigo (`room-outdoor` class). Also holds the alarm-value colours (Section 8) and the AHU card typography (Section 9). |

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
