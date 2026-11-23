# GLOBAL ALARM IMPLEMENTATION — PHASE 1

Implement the database foundation for the Global Alarm system.

## Objective
Create and verify exactly ONE global table:
`hvac_current.alarm_events`

Do NOT implement poller/API/frontend changes in this phase.

## Mandatory rules
1. Do not modify existing tables/schema.
2. Do not modify any poller, curated_poll_client.py, azbil-poller.service, or EF poller.
3. Do not modify PHP API/frontend.
4. Do not create PHP CLI evaluator, cron, scheduler, or daemon.
5. Do not create separate alarm tables.
6. Use one global table: alarm_events.
7. WARNING and ALARM are distinct:
   - WARNING = threshold/value-limit violation.
   - ALARM = native equipment alarm/status point.
8. Alarm detection will eventually happen inside the respective pollers.
9. PHP/web will only read alarm_events.
10. Do not commit or push.
11. Do not update PROJECT.md in this phase.
12. Do not invent names, columns, mappings, or source information.

## Existing sources
AHU:
- native BI alarm/status points
- temperature threshold warnings

HVAC_SC:
- native BI alarm/status points
- threshold/value warnings

EF:
- native status/event information
- communication events

ROOM TEMP & RH:
- threshold/value warnings

Current tables such as ai_current and bi_current contain latest values, not event history.

## Event behavior
One alarm condition = one event row.

Alarm appears:
`INSERT -> ACTIVE`

Alarm remains active:
`NO new INSERT`

Alarm clears:
`UPDATE existing event -> CLEARED`

Same alarm appears again after clear:
`INSERT new event`

Do not implement this behavior in PHP.

## WARNING vs ALARM
WARNING example:
Equipment AHU 65, Supply Temperature, value 28.4 C, limit >25 C.

ALARM example:
Equipment AHU 65, native Alarm point, value ON.

Do not classify threshold violations as native ALARM.
Do not convert native ALARM into WARNING.

Both categories use the same alarm_events table.

## Discovery BEFORE CREATE
Read-only discovery first:
1. Inspect hvac_current.
2. Inspect:
   - points
   - ai_current
   - bi_current
   - acc_current
   - threshold-related tables
   - existing EF alarm/event tables
3. Inspect actual columns, types, indexes and constraints.
4. Use actual project/database names.
5. Do not guess.

## Table requirements
The future web page must be able to show at least:
TIME, EQUIPMENT, POINT, TYPE, DESCRIPTION, VALUE, LIMIT, STATUS, CLEARED TIME, DURATION, SOURCE.

Preserve enough identity to distinguish:
- AHU/HVAC_SC/ROOM point_id
- EF point identity
- communication events where panel/IP may be relevant

Do not add fields without explaining why they are needed.

## Duplicate prevention
Explain how an active alarm condition gets a unique identity.

The design must support:
active -> unique active identity
clear -> active identity becomes available for a future occurrence

Never insert every 30 seconds while unchanged.

## Time
Preserve raise time, clear time, and enough data for duration.
Inspect existing timezone conventions; do not blindly assume timezone.

## Value/limit
WARNING must preserve value and applicable limit at alarm time.
Native ALARM may have NULL value/limit when not applicable.
Do not rely on current tables later to reconstruct historical alarm values.

## Severity
Do not invent severity policy.
If a severity field is proposed, explain it and leave mapping for future poller contract work.

## Acknowledgement
Do not implement acknowledgement now.
If nullable future fields are proposed, explain them.

## Hosting
The same table will eventually exist locally and on hosting.
Do not assume PHP CLI/cron is available.
Do not implement hosting automation now.

## Performance
Current system has about 2 pollers at 30-second intervals.
Required write pattern:
alarm appears -> INSERT
alarm remains -> no INSERT
alarm clears -> UPDATE

Do not create a dedicated DB connection per alarm.
Do not change poller connection behavior.

## Before CREATE
First report:
1. Current schema findings.
2. Proposed alarm_events columns.
3. Primary key.
4. Unique/index strategy.
5. Active alarm identity strategy.
6. WARNING vs ALARM representation.
7. Source representation.
8. Clear/recovery strategy.
9. Timezone/time strategy.
10. Future-ready fields and why.

Only after reviewing the design, create the table.

## After CREATE
Verify:
1. CREATE result.
2. SHOW CREATE TABLE alarm_events.
3. DESCRIBE alarm_events.
4. SHOW INDEX FROM alarm_events.
5. Confirm it is in hvac_current.
6. Confirm no existing tables were modified.

Controlled test data may be used only if reversible:
- one WARNING ACTIVE
- one native ALARM ACTIVE
- clear/update behavior
- duplicate prevention

Do not leave fake/test data in production without approval.

## Final report
Return:
1. Schema findings.
2. Final alarm_events DDL.
3. Important-column explanations.
4. Local CREATE/verification results.
5. Whether existing tables changed.
6. Duplicate prevention strategy.
7. WARNING vs ALARM handling.
8. Next step for poller integration.

STOP after Phase 1.

Do NOT modify pollers, API, frontend, PROJECT.md, commit, or push.
Wait for the next instruction.
