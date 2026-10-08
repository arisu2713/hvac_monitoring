# HVAC MONITORING WEB APP — COMMIT & PUSH

## SCOPE

Focus ONLY on the Web App repository:

`C:\xampp\htdocs\hvac_monitoring`

Do NOT touch:
- `C:\Projects\azbil_bacnet`
- `C:\Projects\monitoring_ef`
- any poller repository
- poller deployment
- poller services
- database schema
- remote database

The immediate goal is to safely commit and push the Web App changes only.

## IMPORTANT SAFETY RULES

- Inspect first.
- Do NOT use `git add -A`.
- Do NOT use `git add .`.
- Do NOT commit unrelated files.
- Do NOT commit credentials/passwords.
- Treat `config.php` carefully.
- Do NOT modify files just to make the commit easier.
- Do NOT revert existing work.
- Do NOT discard uncommitted changes.
- Keep existing backups/scratch files unless they are explicitly selected for commit.
- The recent EF G8 visual fix in `ef.css` is part of this Web App work.

## STEP 1 — INSPECT REPOSITORY

Change to:

`C:\xampp\htdocs\hvac_monitoring`

Run:

```powershell
git status --short
git branch --show-current
git remote -v
git log -5 --oneline
```

Then inspect:

```powershell
git diff --stat
git diff --check
```

## STEP 2 — IDENTIFY WEB APP CHANGES

Show the complete list of:

- modified tracked files
- untracked files

For each modified file, inspect its diff.

For each untracked file, inspect its contents/role before deciding whether it belongs in the Web App release.

Do NOT assume every modified/untracked file belongs in this release.

## STEP 3 — RELEASE SCOPE

The commit should contain ONLY verified Web App changes that are ready for release.

In particular, review the recent:

`ef.css`

change for the EF G8 groupbox width:

```css
@media (min-width: 1101px) {
    .equipment-grid:has(.ef-panel) .ef-panel-g8 {
        justify-self: start;
    }
}
```

Do NOT recreate or modify this change unless inspection shows it is missing or incorrect.

Also review other current Web App changes against the existing project state and handoff.

## STEP 4 — SECURITY CHECK

Before staging anything, inspect the proposed release diff for:

- passwords
- DB credentials
- API keys
- tokens
- private keys
- connection strings containing secrets
- local-only secrets

Do NOT stage `config.php` unless its diff is explicitly verified safe and it is genuinely required for the release.

## STEP 5 — STAGE EXPLICIT FILES ONLY

After inspection, stage ONLY the verified Web App release files using explicit paths.

Never use:

```text
git add -A
git add .
git commit -am
```

Then run:

```powershell
git status --short
git diff --cached --check
git diff --cached --stat
```

## STEP 6 — REVIEW FULL STAGED DIFF

Run:

```powershell
git diff --cached
```

Review the FULL staged diff.

Confirm:

- only Web App changes
- EF G8 visual fix is included
- no poller changes
- no database/schema changes
- no credentials
- no unrelated scratch/temp files
- no accidental deletion
- no accidental rollback

If anything unexpected is staged:

STOP.

Do NOT commit.

Report the issue.

## STEP 7 — COMMIT

Only after the staged diff is clean, create one clear Web App commit.

Suggested commit message:

```text
Update HVAC monitoring web app UI
```

If the actual changes justify a more precise message, use an accurate message instead.

## STEP 8 — VERIFY COMMIT

Run:

```powershell
git status --short
git show --stat --oneline HEAD
git show --check --oneline HEAD
```

Confirm the commit contains ONLY the intended Web App changes.

If unrelated changes remain uncommitted, leave them untouched and report them.

## STEP 9 — PUSH

Only after the commit has been verified:

```powershell
git push origin master
```

Before pushing, confirm:

- current branch is `master`
- remote is the expected GitHub repository
- the commit being pushed is the verified Web App commit

After push, run:

```powershell
git status --short
git log -2 --oneline
git ls-remote origin refs/heads/master
```

## FINAL REPORT

Report:

1. Web App repository path
2. branch
3. files included in commit
4. files intentionally left uncommitted
5. commit hash
6. push result
7. final `git status --short`
8. confirmation that no poller repository was touched
9. confirmation that no credentials were committed

## STOP CONDITIONS

STOP before commit if:
- release scope is unclear
- unexpected files are mixed into the diff
- credentials are present
- a file needs modification merely to separate unrelated work
- a destructive action is required

STOP before push if:
- commit contents are not exactly what was reviewed
- remote/branch is not confirmed

The goal is a clean Web App release only.
