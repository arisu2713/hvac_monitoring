# Deploying the Web App to Hostinger

Companion to `PROJECT.md`. This file covers only the **web app** deployment
(PHP files). The poller/dual-write app that fills the hosting database is a
separate task, handled separately — not covered here.

Both the local (XAMPP) and Hostinger versions run the **same PHP codebase**
from the same GitHub repo. The only thing that differs per environment is
`config.php` (never committed) and a couple of hosting-only hardening files.

---

## 1. One-time setup on Hostinger

### 1.1 Deploy the code

Preferred: hPanel's built-in Git deployment (Advanced → Git), pointing at
`arisu2713/hvac_monitoring`, branch `master`, deployed into `public_html`
(or a subfolder if this site shares the domain with something else).
Every `git push` to `master` can then be redeployed with one click (or
auto-deploy, if the plan supports webhooks).

If Git deployment isn't available on the plan, fall back to downloading a
zip of the repo from GitHub and uploading via hPanel File Manager, or SFTP.

### 1.2 Create `config.php` directly on the server (never via git)

`config.php` is gitignored and will NOT come through with the deploy. Create
it manually, once, using **File Manager → New File** (or SFTP), in the same
folder as `index.php`:

```php
<?php

return [
    'host' => 'srv1763.hstgr.io',
    'port' => 3306,
    'database' => 'u468140406_hvac',
    'username' => 'u468140406_hvac',
    'password' => 'PASTE_THE_REAL_PASSWORD_HERE',
];
```

Use the actual password for the `u468140406_hvac` MySQL user (the one
tested earlier via phpMyAdmin / remote MySQL). Do not paste the real
password into chat, GitHub, or any other file that gets committed.

> Note: on this hosting plan, `u468140406_hvac` has full privileges on its
> own database (Hostinger's shared-hosting model doesn't offer a way to
> create an additional read-only MySQL user the way `hvac_web` was created
> locally). This is a known trade-off of shared hosting — acceptable here
> since the account only has access to its own database, not the whole
> server.

### 1.3 Upload `.htaccess`

Copy the `.htaccess` file (provided alongside this guide) into the same
folder as `index.php` on the server. It blocks direct HTTP access to
`config.php`, `config.example.php`, `.bak`/`.sql`/`.md`/`.log` files, and
adds a few basic security headers. This is defense-in-depth — the main
protection is that these files aren't in the repo or are gitignored, this
just covers the case where something ends up there by accident.

### 1.4 Create hosting login accounts

Do not copy the `users` table from local. Create fresh accounts for the
hosting site via phpMyAdmin (`https://auth-db1763.hstgr.io`), tab **SQL**:

```sql
INSERT INTO users (username, password_hash, is_admin, is_active)
VALUES ('someone', '$2y$...bcrypt-hash...', 0, 1);
```

Generate the bcrypt hash locally first:

```powershell
C:\xampp\php\php.exe -r "echo password_hash('the-password', PASSWORD_DEFAULT), PHP_EOL;"
```

### 1.5 Populate reference tables (one-time, not live data)

`points`, `ef_points`, `daikin_points` need their data copied once (these
rarely change — they're the point catalog, not live readings):

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p --no-create-info --result-file="$env:USERPROFILE\Desktop\hvac_points_data.sql" hvac_current points ef_points daikin_points
```

Import that file via phpMyAdmin on the hosting side (Import tab), same as
the schema import done earlier. Do **not** copy `ai_current`, `bi_current`,
`ef_current`, `daikin_current`, `acc_current` this way — those are live
tables the poller (separate task) is responsible for keeping current on
the hosting side.

### 1.6 Check PHP settings on the hosting account

hPanel → Advanced → PHP Configuration:
- `display_errors` should be `Off` (Hostinger's production default usually
  already is — verify).
- `date.timezone` should be `Asia/Jakarta` (matches how `read_at` is stored
  in the database, avoids the same 5-hour offset issue seen locally).

---

## 2. Differences from the local version (by design)

| | Local (XAMPP) | Hosting |
|---|---|---|
| `config.php` host | `127.0.0.1` | `srv1763.hstgr.io` |
| `config.php` DB user | `hvac_web` (read-only) | `u468140406_hvac` (full access — hosting limitation, see 1.2) |
| `users` table | Existing local accounts | Separate accounts, created fresh (1.4) |
| Live data source | Poller writes directly | Poller dual-write (separate task) must be working before hosting shows current data |
| `.htaccess` | Not present (XAMPP doesn't need it for this) | Present (1.3) |

Everything else — `index.php`, `api_*.php`, `style.css`, `ef.css`, the point
naming/parsing logic — is identical between the two. Any bug fix or feature
added to one is fixed for both, since it's the same files from the same
repo.

## 3. Testing after deploy

1. Open the hosting URL, confirm the login page renders (styling loads —
   if not, check the `.htaccess` isn't accidentally blocking `.css`).
2. Log in with a hosting-specific account (1.4).
3. Check each tab (AHU, HVAC SC, ROOM TEMP & RH, EXHAUST FAN). Expect
   `ONLINE` but **no live data yet** until the poller's dual-write is
   working — cards will show `--` or the last one-time-imported point
   catalog with no current values, which is expected at this stage.
4. Confirm `config.php` and `.bak`/`.sql`/`.md` files are NOT downloadable
   by visiting their URLs directly (should get a 403/404, not the file
   contents).

## 4. Known follow-ups (see PROJECT.md Section 5 for the full list)

- Session cookie hardening (`httponly`, `samesite`, `secure`) — matters more
  once this is reachable from the public internet. Should be done before
  the hosting URL is shared outside the coordinator's team.
- Login rate limiting — same reasoning as above.
- `ai_hourly` (hourly ROOM TEMP & RH history), if wanted on hosting, needs a
  **cron job** here (not a MariaDB Event — shared hosting typically doesn't
  allow the Event Scheduler / `EVENT` privilege).
