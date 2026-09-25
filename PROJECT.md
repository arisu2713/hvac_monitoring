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
| `api.php` | **Legacy/dead code.** Old schema (`equipment_type`/`role` columns that no longer exist on `points`). Not reachable from the UI (AHU_G8 tab short-circuits to "Coming Soon" before calling it). Candidate for deletion. |
| `style.css` / `ef.css` | Styling. Room cards use a teal theme; outdoor cards use indigo (`room-outdoor` class). |

## 3. Database schema (current, as deployed)

`points` table (current schema — **not** the old `equipment_type`/`role` schema
that `api.php` still expects):

```
point_id, device_id, obj_type, instance, point_name, equip_type
```

`equip_type` values: `AHU`, `CCP`, `CHILLER`, `CHWP`, `CT`, `ROOM TEMP & RH`.
Latest values live in `ai_current` (float) / `bi_current` (tinyint), keyed by
`point_id`, with `read_at`. Exhaust fans use `ef_points` / `ef_current`
instead (separate `id`/`ef_point_id`, `panel_no`, `ef_name`).

### Point naming conventions (point_name)

- **AHU:** `AHU <n> Status|Alarm|Duct Temperature`
- **CCP / CHWP:** `<TYPE> <n> Status|Alarm|AL|Current|Frequency`
  (`AL` and `Alarm` both mean alarm — CHWP uses both inconsistently)
- **CT (cooling tower):** `CT <n> Current|Frequency` = tower-level (shared
  VSD), `CT <n><A|B> Status|Alarm|Return Temp` = cell-level. `api_hvac_sc.php`
  makes cells inherit Current/Frequency/Run/Alarm from the tower entry, then
  drops the tower-level entry (only cells are returned to the frontend).
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

SP (setpoint) and RLA fields for all chillers are always `null` — no
corresponding point exists in the DB yet.

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

Priority order agreed with user:

1. **`api_ahu.php` hardcodes `dbname=hvac_current`** instead of using
   `$config['database']` like the other four API files. Will silently break
   only the AHU tab once deployed to hosting (different DB name there).
   **Fix before deploying.**
2. **`.gitignore` has `*.backup*`, which does not match `*.bak`.** The four
   `.bak` files in the project folder are untracked but NOT ignored —
   servable over HTTP and committable to git by accident. Fix pattern to
   `*.bak`; move existing `.bak` files out of `htdocs` entirely.
3. **Run `git add -A` before the next commit** — `git status` showed a
   partially-staged state where older versions of `api_ahu.php`,
   `api_hvac_sc.php`, `api_room.php` were staged instead of current ones.
4. **`display_errors`/`expose_php` On, and every API's catch block does
   `echo $e->getMessage()`.** Leaks DB connection details on failure.
   Matters most once hosting is public. Fix: turn off `display_errors` in
   production, log the real error server-side, return a generic message
   to the client.
5. `session_write_close()` is missing in `api_ef.php` (present in the other
   three current-schema APIs) — minor concurrency issue, cheap to add.
6. `api.php` is dead code with a real bug (`role === 'run,alarm'` branch
   never sets `alarm`) but zero live impact since it's unreachable.
   Candidate for deletion once AHU G8 is actually built (or permanently, if
   AHU G8 ends up using a different schema anyway).
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
- Optional: `ai_hourly` table (hourly snapshot of ROOM TEMP & RH, schema
  below) — built and tested locally via a scheduled PHP script + MariaDB
  Event; **for hosting, must use a cron job instead** (shared hosting
  generally has no Event Scheduler / no `EVENT` privilege).

```sql
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

---
*Keep this file up to date as decisions change — it exists so no session
(human or AI) has to rediscover this context from scratch.*
