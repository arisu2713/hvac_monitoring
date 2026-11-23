# HVAC Monitoring — Commit Changes Only

## Purpose

Commit the current completed HVAC Monitoring web-app changes locally.

**Important:** This procedure creates a Git commit only. It does **NOT** push to GitHub.

## Project

```powershell
cd C:\xampp\htdocs\hvac_monitoring
```

## Coding Agent Command

Give the coding agent the following instruction:

```text
Commit the current HVAC Monitoring web-app changes.

Working directory:
C:\xampp\htdocs\hvac_monitoring

The previous read-only Git audit confirmed:
- HEAD = origin/master at d2d1bc3
- No staged changes
- 3 modified files:
  - PROJECT.md
  - index.php
  - style.css
- 26 untracked files consisting of:
  - implementation PHP files
  - SQL files
  - project documentation/reports
- No temporary/test artifacts were found
- config.php is protected by .gitignore

TASK:
1. Re-run `git status --short`.
2. Review the complete diff for:
   - PROJECT.md
   - index.php
   - style.css
3. Review the untracked files and confirm they belong to the completed HVAC Monitoring workstreams:
   - Energy / Daily kWh
   - TEMP & RH Hourly
   - Global Alarm
   - AHU typography / EF G8 UI fixes
4. Do NOT modify application code, CSS, SQL, or documentation.
5. Do NOT delete, reset, restore, clean, stash, or checkout anything.
6. Do NOT touch config.php.
7. Stage only the intended completed work.
8. Show the staged file list with `git diff --cached --stat` and `git status --short`.
9. If everything is consistent, create ONE commit containing these current completed changes.
10. Use this commit message:
    `Complete energy, alarm, temp-RH history, and dashboard updates`
11. After committing, run:
    - `git status --short`
    - `git log -2 --oneline --decorate`
    - `git branch -vv`
12. DO NOT push to origin yet.

Important:
- This is a commit-only task.
- No source changes.
- No database changes.
- No poller changes.
- No deployment.
- No push.
- If you find anything that does not clearly belong to the completed workstreams, STOP before staging/committing and report it.
```

## Expected Result

After the agent finishes:

```text
HEAD = new local commit
origin/master = d2d1bc3
```

The new commit should exist locally, while GitHub remains unchanged.

## Verification

Run:

```powershell
git status --short
git log -2 --oneline --decorate
git branch -vv
```

Do not run `git push` until the commit has been reviewed and explicitly approved.

## Safety Rules

- Commit only.
- No push.
- No reset.
- No clean.
- No stash.
- No checkout.
- No source modification.
- No database modification.
- No poller modification.
- No deployment.
