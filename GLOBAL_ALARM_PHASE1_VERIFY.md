# GLOBAL ALARM IMPLEMENTATION — PHASE 1 VERIFICATION

## Context

The `hvac_current.alarm_events` table has now been CREATEd successfully.

Continue ONLY with Phase 1 verification.

Do NOT proceed to poller integration.

---

## Required verification

Run all of the following:

1. Verify the table exists in `hvac_current`.
2. Run `SHOW CREATE TABLE alarm_events;`
3. Run `DESCRIBE alarm_events;`
4. Run `SHOW INDEX FROM alarm_events;`
5. Verify the CHECK constraint:
   - ACTIVE -> active_key IS NOT NULL
   - CLEARED -> active_key IS NULL
6. Verify `UNIQUE(active_key)`.
7. Verify ENGINE=InnoDB, utf8mb4, utf8mb4_unicode_ci.
8. Verify the approved 18-column structure.

## Existing-schema protection

Compare the post-CREATE database against the pre-CREATE baseline. Existing schema must remain unchanged.

Expected:
- Existing tables before alarm_events: 21
- alarm_events: 1 new table
- Existing foreign keys before: 1
- Existing CHECK constraints before: 1
- Existing FKs/CHECKs on existing tables unchanged.
- Existing table columns, indexes, and row counts unchanged.

Previously reported baseline hashes (exclude alarm_events):

columns_md5: 0404ee27ce38657baaccff745220c8f0
indexes_md5: 617c93073325efdc9568ab5b9dd8afc6
rowcounts_md5: 5b94187967b42fab65b029c572b6765a

Expected existing-schema counts:
- existing tables = 21
- existing FKs = 1
- existing CHECKs = 1

Report the new alarm_events CHECK separately.

## Duplicate-prevention test

Only after schema verification, perform a reversible test.

### Test 1 — WARNING ACTIVE
Create one temporary WARNING ACTIVE event with a clearly identifiable test value.
Expected: status=ACTIVE and active_key NOT NULL.

### Test 2 — duplicate ACTIVE
Attempt another ACTIVE event with the exact same active_key.
Expected: rejected by UNIQUE(active_key). Report the actual error.

### Test 3 — CLEAR
Update the temporary event:
- status=CLEARED
- cleared_at=explicit test timestamp
- active_key=NULL

Expected: historical CLEARED row remains.

### Test 4 — recurrence
Insert the same alarm identity again after clearing.
Expected: new event row is allowed.

### Cleanup
Remove ALL test rows afterward. Production alarm_events must contain ZERO test rows after verification. Use transaction where practical or explicitly DELETE test rows. Do not leave test data.

## Important constraints

Do NOT:
- modify any existing table
- modify any poller
- modify curated_poll_client.py
- modify azbil-poller.service
- modify EF poller
- modify PHP API
- modify frontend
- modify PROJECT.md
- commit
- push
- create another alarm table
- create PHP CLI evaluator
- create cron/scheduler logic

Do not change the schema as part of verification.

If a verification result differs from the approved design, STOP and report it instead of silently altering the table.

## Final report

Return:
1. Table existence.
2. SHOW CREATE TABLE result.
3. DESCRIBE result.
4. Index result.
5. CHECK constraint result.
6. UNIQUE(active_key) result.
7. Engine/charset/collation result.
8. Existing-schema baseline comparison.
9. Duplicate-prevention test results.
10. Confirmation all test rows were removed.
11. Confirmation no poller/API/frontend/PROJECT.md changes were made.

Then STOP. Do not start Phase 2.
