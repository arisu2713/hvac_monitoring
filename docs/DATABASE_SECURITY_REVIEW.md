# Database Security Review — HVAC Monitoring

**Audit date:** 2026-10-11 · **Mode:** read-only · **No credential values appear in this document.**

> Every secret found is described by **location and kind** only. No password, hash, token, or key is reproduced,
> in whole or in part. Where a secret must be identified, it is referred to as `<redacted>`.

---

## 1. Accounts and privileges

### 1.1 Local web app account — `hvac_web` (VERIFIED)

This is the account the web app actually uses. Verified by reading `config.php` (the credential itself was not
printed) and by connecting as it.

`SHOW GRANTS` for `hvac_web@localhost`:

```sql
GRANT USAGE ON *.* TO `hvac_web`@`localhost` IDENTIFIED BY PASSWORD '<redacted-hash>'
GRANT SELECT ON `hvac_current`.* TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_total_ccp`   TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_total_chwp`  TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_total_ct`    TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_usage_ccp`   TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_usage_chwp`  TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`kwh_usage_ct`    TO `hvac_web`@`localhost`
GRANT INSERT ON `hvac_current`.`temp_rh_hourly`  TO `hvac_web`@`localhost`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ... ON `test`.* TO PUBLIC
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ... ON `test\_%`.* TO PUBLIC
```

### 1.2 Assessment of `hvac_web`

| Finding | Assessment |
|---|---|
| `SELECT` on `hvac_current.*` | **Correct and sufficient** for the web app — every `api_*.php` issues only `SELECT`. |
| **No `UPDATE`/`DELETE` on `hvac_current.*`** | **Correct.** The app cannot corrupt operational data even if a query were injected. |
| **`INSERT` on 7 specific tables** | **Intentional, and correctly scoped.** These are exactly the tables the CLI snapshot scripts write (`temp_rh_hourly`, `kwh_total_{ct,ccp,chwp}`, `kwh_usage_{ct,ccp,chwp}`). Not a blanket grant. |
| No `CREATE`/`ALTER`/`DROP` on `hvac_current` | **Correct.** Confirmed: `CREATE TABLE` is denied (also recorded in `PROJECT.md` §4). |
| No access to any other schema | **Correct** — `SHOW DATABASES` returns only `hvac_current`, `information_schema`, `test`. |

**Is `hvac_web` SELECT-only as the brief asked?** **Effectively yes for all operational tables.** It is
SELECT-only on the whole schema, plus narrowly-scoped `INSERT` on the seven history tables the snapshot scripts
own. It holds **no** write privilege on any live table (`ai_current`, `bi_current`, `acc_current`, `ef_*`,
`daikin_*`, `alarm_events`, `points`).

> **Caveat worth flagging:** the seven `INSERT` grants exist because `kwh_daily_snapshot.php` and
> `temp_rh_snapshot.php` run as `hvac_web`. Those scripts are **CLI-only and appear to be run manually**; the
> Azbil poller also writes the same tables with its own credentials. So the web app's account carries write
> privilege it does not need *for serving pages*. If the manual-script path is ever retired, these grants should
> be revoked. This is the only unnecessary-privilege observation in the local database, and it is minor.

### 1.3 `test` schema — PUBLIC full access (VERIFIED, but not project-created)

`PUBLIC` holds `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES,
LOCK TABLES, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, EVENT, TRIGGER` on `test.*` and `test\_%`. This is
**MariaDB's shipped default**, not something this project created. Nothing in the HVAC project uses `test`.

**Recommendation:** `DROP DATABASE test;` (or revoke the PUBLIC grants) on a production host. **Not performed —
out of scope and a write operation.**

### 1.4 Other accounts referenced in documentation (not observable via `hvac_web`)

| Account | Purpose | Status |
|---|---|---|
| `root` (local) | XAMPP admin; the poller connects as `root` | **Referenced in poller source** (`Program.cs` `UserID = "root"`). `PROJECT.md` §4 records that the `root` password was never rotated. **Could not be verified** — `hvac_web` cannot see `mysql.user`. |
| `hvac_cron` (local) | Documented for the hourly-history cron | **NOT VERIFIED** — cannot enumerate users as `hvac_web`. |
| `daikin_poller` (local) | Daikin poller | **NOT VERIFIED** as an account; referenced in `ahu_poller_unified.py:372`. A prior audit noted it lacks grants on `hvac_current.daikin_points`. |
| `u468140406_hvac` (remote) | Hosted web app + pollers | Documented in `DEPLOY_HOSTING.md` §1.2 as having **full privileges on its own database** — a shared-hosting limitation, explicitly accepted. **NOT VERIFIED.** |

### 1.5 Least privilege is *better* than the grants alone suggest — but it also blinds introspection

Two findings from final verification, both consequences of `hvac_web`'s narrow grants:

1. **`information_schema` is privilege-filtered for this account.** `TABLE_CONSTRAINTS` returns only
   **8** of the database's **34** constraints — precisely the 7 tables the account holds `INSERT` on,
   plus `temp_rh_hourly`. Hidden from it: the `alarm_events` CHECK constraint and **both foreign keys**.
   `SCHEMATA` returns 0 rows for schemas the account cannot read (verified against `mysql`,
   `performance_schema` and `sys`, all of which exist).
   **Consequence for security work:** a drift or compliance check run as this account would
   **silently under-report** constraints and could not detect a dropped FK. Use `SHOW CREATE TABLE`
   (not privilege-filtered) or a dedicated read-only monitoring account with
   `SELECT` on `information_schema` for any such check. Recorded as R11 in
   `docs/DATABASE_ARCHITECTURE.md`.
   **Consequence for audits:** this filtering is also why the naive `hvac_monitoring` existence probe is
   worthless — see `docs/DATABASE_INVENTORY.md` §3.2.

2. **`information_schema.COLUMNS` and `.TABLES` are NOT filtered** for this account — both return the
   full 196 columns / 24 tables and agree exactly with the DDL. So the blindness is specific to
   constraint and schema-level metadata, not a general limitation.

This is a *positive* finding about privilege scope (the account can see only what it should) with a
*negative* operational side effect (metadata-based monitoring built on it is unreliable).

---

## 2. Embedded-secret findings

| # | Location | Kind | Tracked in git? | Risk |
|---|---|---|---|---|
| **S1** | `config.php:8` (repo root) | Plaintext DB password for `hvac_web` | **No** — gitignored, never committed | Low *if* the file stays local; **High if the tree is copied or archived** |
| **S2** | `C:\Projects\ahu_ef_daikin\.env.poller:1-2` | Plaintext `NIAGARA_PASSWORD`, `MARIADB_PASSWORD` | No (outside repo) | Medium — plaintext on disk |
| **S3** | `C:\Projects\ahu_ef_daikin\_wsl_lab\.env.poller:1-2` | Duplicate of S2 | No | Medium — a **second copy** of the same secrets |
| **S4** | `C:\xampp\htdocs\test_vps_db.php:8` | **Plaintext remote DB password** hardcoded in PHP, for host `139.190.99.168` / db `hvac_test` / user `hvac_remote` | No (outside repo) | **High** — see §2.1 |
| **S5** | `C:\xampp\htdocs\_archive\users_backup.sql:47` | bcrypt `password_hash` for user `admin` (hash, not plaintext) | No (outside repo) | Medium — credential artifact, crackable offline |
| **S6** | `C:\xampp\htdocs\_archive\*.php`, `api.php.backup_*` | Files matched a password grep (legacy copies of config/API files) | No | Low–Medium — stale copies may contain old credentials |
| **S7** | `C:\Users\ADMIN\Documents\mqtt8.html:190`, `mqtt7.html:190` | Hardcoded JS client-side gate password | No | Low (client-side gate, not a DB credential) |
| **S8** | `C:\Users\ADMIN\Documents\mqtt9.html:175` | Placeholder `"yourPassword"` | No | None — not a real secret |
| **S9** | XAMPP bundled docs (`htdocs/dashboard/docs/*.html`) | Example/placeholder passwords shipped by XAMPP | No | None |

**No secrets were found in any git-tracked file in the web app repo.** Specifically checked: no password literal
appears in any tracked `.php`, `.md`, `.txt`, `.sql`, or `.js`. `config.example.php` (which *is* tracked)
contains only empty strings and `127.0.0.1` — verified.

### 2.1 S4 is the most serious secret finding

`C:\xampp\htdocs\test_vps_db.php` is a **web-accessible PHP file** (it sits in the Apache web root, one level
above the project) that contains a **plaintext database password** for a remote VPS
(`139.190.99.168`, database `hvac_test`, user `hvac_remote`). It is not gitignored by any repo (it is outside
every repo) and, unlike the project's own `config.php`, it is **not covered by the project's `.htaccess`**,
which only applies inside the project folder.

If Apache serves `C:\xampp\htdocs`, this file is reachable at `http://<host>/test_vps_db.php`. It would fail to
*display* the password (it only echoes OK/FAILED), but the **source is readable** only if PHP is misconfigured —
the real risk is that the credential sits in a web-served directory at all, and that the file is a leftover
connection test.

**Recommendation (NOT performed):** delete the file, or move it out of the web root, and rotate the
`hvac_remote` password. This is a write/delete operation outside the audit's read-only mandate.

> **Related note:** the `hvac_test` database on `139.190.99.168` was **not** part of this audit's scope and was
> not contacted. It is a **different host** from both the local MariaDB and the Hostinger remote. Whether it is
> still in use is **NOT VERIFIED**.

---

## 3. Configuration and exposure review

### 3.1 `.gitignore` (VERIFIED — adequate)

```
config.php
*.backup*
_ui_test/
*.bak
```

- `config.php` **is** ignored — verified with `git check-ignore` and `git ls-files` (only `config.example.php`
  is tracked).
- `*.backup*` and `*.bak` are ignored, which covers the backup-file naming patterns used in `_archive/`.

**Gap:** the ignore patterns do **not** cover `.env`, `.env.*`, `*.env`, or `*.sql`. The project itself has no
`.env` files, so this is not currently a leak — but the poller projects **do** use `.env.poller` files (S2/S3),
and if such a file were ever copied into this repo it would be committed. Adding `.env*` and `*.sql` to
`.gitignore` would be a cheap hardening step. **Not performed** (this audit does not modify tracked files
beyond the documentation it was asked to create).

### 3.2 `.htaccess` (VERIFIED — good, with one gap)

The committed `.htaccess` denies HTTP access to `config.php`, `config.example.php`, and `*.bak|sql|md|ini|log|gitignore`,
blocks `.git`, disables directory listing, and sets `nosniff` / `SAMEORIGIN` / `same-origin` headers.

**Gaps:**

1. It protects **only the project folder**. `test_vps_db.php` (S4) sits **outside** it and is unprotected.
2. `.htaccess` is Apache-only. On Hostinger it is effective; under XAMPP locally it is not loaded unless
   `AllowOverride` permits it. The **local** instance therefore relies on `config.php` simply not being
   requested. `PROJECT.md` §4 notes the local instance is LAN-only, so this is acceptable today.
3. No `Content-Security-Policy` header. `index.php` builds all DOM with `textContent` (per `PROJECT.md` §13),
   so XSS exposure is limited, but a CSP would be defence-in-depth.

### 3.3 Error-handling posture (VERIFIED — fixed)

`PROJECT.md` §5 records that all APIs previously echoed `$e->getMessage()` to the client. That is now fixed:
each API logs server-side and returns a generic `{"success":false,"error":"Internal server error"}`. Verified by
reading the `catch` blocks in `api_alarm.php`, `api_energy.php`, and the other endpoints.

**Consequence for this audit:** database error text (including schema names) is **not** leaked to the browser.
Good.

### 3.4 Session / auth posture

| Item | Status |
|---|---|
| `require_login()` gate on every API | VERIFIED present |
| `session_write_close()` before DB work | VERIFIED present (per `PROJECT.md` §5 item 5) |
| Cookie hardening (`httponly`, `samesite`, `secure`) | **NOT DONE** — `PROJECT.md` §5 item 7 records this as open |
| Login rate limiting | **NOT DONE** — same |
| `users` table stores bcrypt hashes | VERIFIED (schema only; hashes not read) |

These matter most once the hosted site is publicly reachable. They are documented in `PROJECT.md` and were not
changed by this audit.

### 3.5 Timezone inconsistency (VERIFIED — security-adjacent, low risk)

`@@global.time_zone = SYSTEM` and `@@session.time_zone = SYSTEM`. `PROJECT.md` §4 records that PHP's
`date.timezone` is still the XAMPP default (likely `Europe/Berlin`) while `read_at` is written in WIB. This
produces a ~5 h offset between `server_time` and `read_at` in API responses. It is **not** a security issue, but
it is the prerequisite for any future "data is stale" indicator — and a staleness indicator is precisely what
would have made the Daikin freeze (architecture §6) visible.

---

## 4. Findings summary, ranked

| # | Finding | Severity | Action owner |
|---|---|---|---|
| 1 | **Plaintext remote DB password in web-served `test_vps_db.php`** (S4) | **High** | Rotate password; remove file from web root |
| 2 | Duplicate plaintext poller credentials in two `.env.poller` files (S2/S3) | Medium | Consolidate to one; restrict ACL |
| 3 | `users` bcrypt hash in a stray SQL dump outside the repo (S5) | Medium | Delete the dump |
| 4 | Local `root` password never rotated; poller connects as `root` | Medium | `PROJECT.md` §4 — still open |
| 5 | Remote app account `u468140406_hvac` has **full privileges** on its DB | Medium | Shared-hosting limitation; documented/accepted |
| 6 | `hvac_web` holds 7 `INSERT` grants it does not need to serve pages | Low | Revoke if snapshot scripts are retired |
| 7 | `test` schema is world-writable via PUBLIC | Low | MariaDB default; drop the schema |
| 8 | `.gitignore` does not cover `.env*` / `*.sql` | Low | Add patterns |
| 9 | No session cookie hardening / login rate limiting | Medium (pre-public-launch) | `PROJECT.md` §5 item 7 |
| 10 | `config.php` plaintext password (S1) | Low *while gitignored* | Keep it out of archives/backups |

---

## 5. Read-only attestation

- **No grant, user, password, or privilege was created, altered, or revoked.**
- **No `GRANT`/`REVOKE`/`CREATE USER`/`SET PASSWORD` statement was issued.**
- The only credential used was the web app's own `hvac_web` account, read from `config.php` — it was never
  printed to output, echoed in a command line, or written to any file.
- Privilege information was obtained with `SHOW GRANTS` (self-inspection) and `information_schema` reads.
- **No secret value appears anywhere in this document or in any other document created by this audit.**
