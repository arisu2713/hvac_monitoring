# PHASE 2B — FINAL DIFF AUDIT ONLY

We are continuing the HVAC Monitoring project from the handoff.

IMPORTANT:
This is an AUDIT ONLY step.

DO NOT:
- deploy anything
- restart any service
- run simulation/temp scripts
- modify files
- commit
- push
- change database/schema
- change any poller behavior

## Files to audit

Audit ONLY these two files:

1. C:\Projects\azbil_bacnet\Program.cs
2. C:\Projects\monitoring_ef\ef_polling_engine_claude_v2.py

## Goal

Verify that the Phase 2B global alarm implementation is clean and contains only the intended changes.

## Checks

### 1. Git status

Run:

git status --short

### 2. Diff check

Run:

git diff --check

### 3. Diff statistics

Run:

git diff --stat

### 4. Full BACnet diff

Show the FULL diff for:

C:\Projects\azbil_bacnet\Program.cs

### 5. Full EF diff

Show the FULL diff for:

C:\Projects\monitoring_ef\ef_polling_engine_claude_v2.py

## Audit the diff specifically for

- Phase 2B global alarm changes only
- BACNET_POLLER source
- EF_POLLER source
- WARNING threshold handling
- native ALARM handling
- active_key handling
- duplicate protection
- CLEAR handling
- no EF communication alarm
- no unrelated poller logic changes

## Security check

Check the complete diff for accidental:

- credentials
- passwords
- connection strings containing secrets
- API keys
- unrelated configuration changes

## Unrelated changes

Confirm whether either file contains modifications outside Phase 2B.

## Final result

Report exactly one final audit status:

PASS

or

FAIL

### If PASS

- State clearly that both files are clean for Phase 2B.
- Do NOT deploy.
- Do NOT restart.
- Do NOT commit.
- Do NOT push.
- STOP and wait for approval.

### If FAIL

- Do NOT fix anything.
- Identify the exact file and problematic diff.
- STOP and wait for instruction.

## IMPORTANT

Do not infer that previous compile success means the diff is approved.

The purpose of this step is specifically to inspect the actual Git diff before deployment.

Inspect first. Report findings. Then STOP.
