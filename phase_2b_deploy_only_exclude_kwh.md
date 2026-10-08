# PHASE 2B — DEPLOY ONLY / EXCLUDE DAILY KWH

The FINAL DIFF AUDIT passed.

Deploy ONLY the Phase 2B global-alarm changes.

## CRITICAL SCOPE

`C:\Projects\azbil_bacnet\Program.cs` contains TWO workstreams:

1. Phase 2B global alarm
2. Daily kWh Stage 2 / ACC changes

The Daily kWh / ACC changes MUST NOT be deployed in this Phase 2B deployment.

## BEFORE DEPLOYMENT

Inspect the current deployment mechanism and service configuration.

Inspect the exact Phase 2B code boundaries identified by the audit.

DO NOT:
- modify `Program.cs` to remove or rewrite the kWh code
- modify source code just to make deployment easier
- commit
- push

## DEPLOYMENT SCOPE

### AZBIL BACNET

Deploy ONLY the Phase 2B `BACNET_POLLER` global-alarm implementation.

Do NOT deploy:
- Daily kWh changes
- ACC Stage 2 functionality
- unrelated changes
- documentation changes

### EF

Deploy the already-audited Phase 2B EF alarm implementation from:

`C:\Projects\monitoring_ef\ef_polling_engine_claude_v2.py`

## DO NOT DEPLOY

- Daily kWh / ACC changes
- unrelated documentation
- database schema changes
- `alarm_events` schema changes
- changes to existing alarm semantics
- Daikin changes
- EF communication alarms

## CRITICAL DEPLOYMENT SAFETY

Because `Program.cs` contains both Phase 2B and kWh changes, first determine whether the existing build/deployment process would ship the kWh code together with Phase 2B.

### IF NORMAL BUILD WOULD SHIP BOTH WORKSTREAMS

STOP.

Do NOT deploy.

Report exactly why Phase 2B cannot safely be deployed independently from the current `Program.cs` state.

Do NOT:
- make an ad-hoc source modification
- temporarily remove the kWh code
- rewrite/revert the kWh block
- create a temporary deployment source

unless explicitly approved.

### IF THE EXISTING DEPLOYMENT MECHANISM CAN SAFELY DEPLOY PHASE 2B WITHOUT SHIPPING KWH

Proceed with the approved Phase 2B deployment.

## AFTER DEPLOYMENT

1. Verify service status.
2. Restart ONLY the required services after deployment.
3. Verify the services are running the newly deployed binaries/code.
4. Observe `alarm_events`.
5. Verify no unexpected kWh deployment occurred.
6. Verify BACNET_POLLER alarm writes.
7. Verify EF_POLLER alarm writes.
8. Verify local `alarm_events` data.
9. Do NOT modify the remote database yet unless the deployment procedure explicitly requires it.

If a remote DB action is required, STOP and report before doing it.

## REPORTING

For every action, report:
- what was done
- exact service affected
- exact deployment artifact/path
- verification result

## STOP CONDITIONS

STOP immediately if:
- Phase 2B cannot be isolated from the kWh changes.
- The deployment mechanism is unclear.
- Deployment would require modifying source code to remove kWh.
- A destructive or irreversible action is required.
- Database schema modification is required.
- Remote DB modification is required without explicit approval.

## FINAL RULES

- SAFE Phase 2B-only deployment is the goal.
- A successful build is NOT sufficient if it also ships Daily kWh.
- Do NOT commit.
- Do NOT push.
- Do NOT deploy Daily kWh.
- Do NOT restart unrelated services.

If Phase 2B-only deployment is safe, proceed.
Otherwise STOP and report the blocker.
