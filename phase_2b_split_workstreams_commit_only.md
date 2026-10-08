# PHASE 2B — SPLIT WORKSTREAMS / COMMIT ONLY

Create a clean Phase 2B-only commit while keeping Daily kWh / ACC Stage 2 uncommitted.

IMPORTANT:
- Do NOT lose, revert, or discard Daily kWh / ACC changes.
- Do NOT use `git add -A`.
- Do NOT use `git commit -am`.
- Do NOT push.
- Do NOT deploy.
- Do NOT restart services.

## 1. INSPECT

Run:

```bash
git status --short
git diff --stat
git diff -- C:\Projects\azbil_bacnet\Program.cs
git diff -- C:\Projects\monitoring_ef\ef_polling_engine_claude_v2.py
```

Confirm the exact Phase 2B versus Daily kWh/ACC boundaries before staging.

## 2. STAGE PHASE 2B ONLY

Stage ONLY:
- BACnet Phase 2B global alarm implementation
- EF Phase 2B global alarm implementation

Do NOT stage:
- Daily kWh / ACC Stage 2
- unrelated PROJECT.md changes
- unrelated README.md changes
- KWH documentation
- unrelated files

Because Program.cs contains both workstreams, use a safe method that preserves all kWh/ACC changes in the working tree.

Do NOT blindly stage the whole Program.cs.

## 3. VERIFY STAGED DIFF

Run:

```bash
git status --short
git diff --cached --check
git diff --cached --stat
git diff --cached
```

The staged diff MUST contain only Phase 2B.

Verify:
- BACNET_POLLER
- SaveAlarmEvents
- WARNING threshold handling
- native ALARM handling
- active_key lifecycle
- duplicate protection
- CLEAR handling
- EF_POLLER
- EF global alarm handling
- no EF communication alarm

The staged diff MUST NOT contain:
- Daily kWh
- ACC
- kwh_total_
- kwh_usage_
- acc_current
- ACC point plumbing
- Daily KWH documentation
- unrelated changes

If anything outside Phase 2B is staged: STOP. Do not commit. Report the problem.

## 4. COMMIT PHASE 2B ONLY

Only after the staged diff is verified clean, create:

```text
Phase 2B global alarm implementation
```

Do NOT push.

## 5. VERIFY COMMIT

Run:

```bash
git status --short
git show --stat --oneline HEAD
git show --check --oneline HEAD
git show --format=fuller --stat HEAD
```

Verify:
- HEAD contains Phase 2B only.
- Daily kWh / ACC remains uncommitted.
- No unrelated files entered the commit.
- Working-tree diff still contains the Daily kWh / ACC changes.

## 6. STOP

Do NOT deploy.
Do NOT restart.
Do NOT push.

Report:
1. Phase 2B commit hash
2. exact files in the commit
3. exact Daily kWh / ACC changes still uncommitted
4. `git status --short`
5. confirmation that no kWh/ACC code entered the Phase 2B commit

Then STOP.

FINAL RULE:
The objective is:
Phase 2B committed cleanly → Daily kWh/ACC remains uncommitted → STOP.
