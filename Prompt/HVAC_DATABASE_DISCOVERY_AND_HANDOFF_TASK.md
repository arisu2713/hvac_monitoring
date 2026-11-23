# HVAC Monitoring — Database Discovery & Handoff Task

**For:** Coding Agent  
**Mode:** Read-only audit and documentation  
**Project:** `C:\xampp\htdocs\hvac_monitoring`  
**Repository:** `git@github.com:arisu2713/hvac_monitoring.git` (`master`)

## Objective

Map every database used or referenced by HVAC Monitoring, describe all tables and columns, compare local and remote databases, document data flows and permissions, and update the project handoff with verified findings.

## Known Starting Points — Verify Before Trusting

- Local MariaDB: historically `127.0.0.1:3306`
- Primary local database: `hvac_current` (verify current configuration)
- Remote MariaDB: historically `srv1763.hstgr.io:3306`
- Remote database: `hvac_current` (verify current configuration)
- `hvac_monitoring`: the user reports that the legacy object referred to by this name has been dropped. The poller was updated to avoid errors afterward, and the user reports that the Web App is unaffected. Treat this as user-reported current context; verify the actual object/database status from read-only metadata and source code. Do not recreate it or treat it as an active dependency. If necessary, distinguish whether the dropped object was a database or a table based on evidence; do not guess.
- Latest known handoff: `HVAC_MONITORING_PROJECT_HANDOFF_COPILOT_2026-10-11_REV2.txt`

These are leads, not proof that every database is accessible or has the same schema. The discovery report must record the `hvac_monitoring` removal as user-reported and verify whether any stale code/documentation references remain.

## Safety Rules

1. **Read-only inspection only.** No database or production-data changes.
2. Do not run `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `INSERT`, `UPDATE`, `DELETE`, migrations, seeds, or scripts that write data.
3. Do not change users, privileges, passwords, firewall settings, or configuration.
4. Do not deploy, rebuild, restart, or modify pollers or production services during this task. The user reports that the poller was already updated after the `hvac_monitoring` removal; verify source/status only if accessible, and do not redeploy it.
5. Never expose credentials, tokens, private keys, or connection strings containing secrets. Redact them in all output.
6. Do not assume local and remote schemas match. Do not invent schemas, table meanings, or data flows.
7. If access requires a change or elevated privilege, stop and report the blocker.
8. Do not commit or push. Do not use `git add -A` blindly.
9. Keep the existing acquisition and polling workflows untouched. Do not recreate the removed `hvac_monitoring` object.

## Work Plan

### 1. Inspect the repository and configuration

Check `git status`, branch, HEAD, file tree, `.gitignore`, `PROJECT.md`, PHP APIs, database configuration, SQL files, and available poller/integration source. Search for database connections, SQL queries, table names, schema definitions, and references in documentation.

Classify each reference as:
- current dependency;
- poller/acquisition dependency;
- legacy;
- suspected unused;
- historical documentation only;
- stale reference to the removed `hvac_monitoring` object;
- unknown/unverified.

Specifically search for stale `hvac_monitoring` references in PHP, poller source available to the agent, SQL, config, Git-tracked documentation, and deployment notes. Report them; do not modify poller/application behavior as part of this audit. The user reports that the Web App is unaffected, but verify its actual configured database and current source references.

Do not print secrets when inspecting configuration.

### 2. Discover accessible databases

For each authorized, accessible database, record environment (`LOCAL`/`REMOTE`), host and port (no credentials), database name, DBMS/version if available, connection status, inspection permissions, and inspection timestamp.

Discover other relevant databases visible to the authorized account. Do not scan unrelated systems. Mark inaccessible environments `NOT VERIFIED` and explain why.

### 3. Inventory every table and view

For every database, collect where available:
- object name and type (table/view);
- engine, charset, collation;
- row count and whether exact or estimated;
- table/index size where available;
- every column in order, with type, length, nullability, default, and key/index membership;
- primary keys, unique constraints, indexes and column order;
- foreign keys, CHECK constraints, generated columns and AUTO_INCREMENT;
- triggers, views, and routines if relevant and accessible;
- subsystem usage and evidence for that classification.

Use read-only metadata inspection or `SHOW CREATE TABLE` where permitted. Include legacy objects, but label their status. Do not infer business meanings from names alone; use `UNKNOWN` or `INFERRED — NEEDS VERIFICATION` when evidence is insufficient.

### 4. Pay special attention to these data domains

Verify actual names and schemas; do not assume they exist:

- **Core/live data:** `points`, `ai_current`, `bi_current`, `acc_current`, and related history tables.
- **EF:** inventory, current-value, and historical tables; panel/device metadata and API dependencies.
- **Energy:** `kwh_total_ccp`, `kwh_total_chwp`, `kwh_total_ct`, `kwh_usage_ccp`, `kwh_usage_chwp`, `kwh_usage_ct`. Explain cumulative versus daily values only when code/data supports it. A script's existence does not prove it runs automatically.
- **Room TEMP/RH history:** `temp_rh_hourly`, related points/current values, writers, triggers, timestamps (`recorded_at` and `read_at`), coverage and gaps. Do not run snapshot jobs or insert test data.
- **Alarms:** `alarm_events`, threshold/rule tables and supporting objects. Document event semantics and writers; distinguish code present from production behavior verified.
- **Daikin:** inspect legacy groups if present: `daikin_ahu_current`, `daikin_ahu_points`, `daikin_ahu_readings`, `daikin_ef_current`, `daikin_ef_points`, `daikin_ef_readings`; identify newer structures separately if found.

### 5. Compare local and remote `hvac_current`

If both are accessible, compare:

- database/table/view inventory;
- DDL, columns, types, nullability and defaults;
- primary/unique keys and indexes;
- foreign keys, CHECK constraints, engine, charset and collation;
- triggers, views and relevant routines;
- row counts where safely obtainable;
- important configuration and data freshness.

Include all tables, with special attention to `points`, `ai_current`, `bi_current`, `acc_current`, EF tables, threshold tables, energy tables, `temp_rh_hourly`, and `alarm_events`.

Use deterministic schema fingerprints/hashes when practical and explain their coverage. Live values and row counts may change while pollers run; do not mislabel expected live changes as schema mismatches. Classify results as `MATCH`, `MISMATCH`, `NOT VERIFIED`, `NOT APPLICABLE`, or `EXPECTED DIFFERENCE — EXPLAINED`, with evidence.

**Do not change either database to make them match.**

### 6. Map architecture and data flow

Create an evidence-based diagram covering:

Field devices/controllers → acquisition/pollers → local and/or remote databases → history/event storage → PHP APIs → dashboard/browser.

Show Azbil/BACnet, EF, and Daikin Niagara separately where their paths differ. Identify actual writers/readers, authoritative source for each domain, whether data is copied or independently written, and whether synchronization exists. Use solid lines for verified links and dashed lines for inferred/unverified links. Do not draw a synchronization link solely because database names match.

### 7. Review database permissions and security

Where permitted, inspect the account actually used by the Web App and its grants. Verify whether `hvac_web` exists/is used and whether it is SELECT-only for required objects. Report unnecessary write privileges if observable, but do not change any grant. Check tracked source for embedded secrets and whether `.gitignore` protects local configuration. Report findings without reproducing secrets.

### 8. Documentation deliverables

Inspect the repository's existing documentation structure first. Create or update appropriate files, preferably:

1. `docs/DATABASE_INVENTORY.md` — all discovered databases, tables, views, columns, constraints and verified meanings.
2. `docs/DATABASE_ARCHITECTURE.md` — diagram, writers/readers, data flow and system boundaries.
3. `docs/DATABASE_LOCAL_REMOTE_PARITY.md` — fingerprints, comparison results, differences and unknowns.
4. `docs/DATABASE_SECURITY_REVIEW.md` — permission/configuration findings, secrets redacted.
5. Update `PROJECT.md` only with verified architecture and links to the new documents.
6. Create updated handoff: `HVAC_MONITORING_PROJECT_HANDOFF_COPILOT_2026-10-11_REV3.txt`.

The handoff should preserve the existing scope and constraints, then summarize database roles, actual architecture, parity status, unresolved issues and next actions. Put detailed schema in the dedicated documents rather than duplicating thousands of lines in the handoff. If the Rev. 2 file is outside the repo, do not overwrite it; create a new Rev. 3 output.

### 9. Preserve existing project scope

Keep the handoff focused on the current HVAC Monitoring work:

- AHU
- HVAC_SC
- EF
- Room Temperature & RH History
- Global Alarm
- Daily Energy
- Web App verification/release
- Local/remote database parity

Do not expand into unrelated features. Clearly distinguish implemented and verified features from planned or unverified behavior.

### 10. Final verification and report

Before finishing:
- check all documented facts against source evidence;
- confirm no secrets are present;
- confirm no database or production data was modified;
- confirm no poller was changed, deployed, or restarted;
- review `git status` and `git diff`;
- do not commit or push.

Report:
1. databases discovered and accessibility;
2. table/view counts per database;
3. columns/indexes documented, where practical;
4. local/remote parity summary;
5. important differences;
6. permission/security findings;
7. documentation created/updated;
8. `NOT VERIFIED` items and blockers;
9. confirmation that work was read-only and no production changes occurred.

## Execution Rule

Start with inspection. Continue only while operations remain read-only. If a step requires a write, deployment, restart, privilege change, or other potentially disruptive action, stop and request approval. Do not commit, push, deploy, or restart anything.
