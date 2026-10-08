# EF G8 GROUPBOX WIDTH — CODING AGENT COMMAND

## TASK

Fix the EF G8 groupbox width only.

### Context

- Current EF page displays Panel 1–9 correctly.
- At the bottom there is `EXHAUST FAN G8` containing only 4 cards:
  - EF 58
  - EF 59
  - EF 60
  - EF 61
- The G8 groupbox currently stretches almost across the entire screen, leaving a large empty area on the right.
- The provided screenshot shows the exact visual problem.

## REQUIRED RESULT

- Make the `EXHAUST FAN G8` groupbox width fit its actual card content.
- It should be approximately the width required by 4 EF cards plus their gaps and groupbox padding.
- Do NOT stretch G8 to the full available row width.
- Keep EF 58–61 card sizes and spacing consistent with the existing EF cards.
- Keep the existing 3-column layout/appearance of Panel 1–9 unchanged.
- Do NOT change EF data, API, polling, database, card status logic, or naming.
- Do NOT change responsive behavior except where required to prevent the G8 box from becoming unnecessarily wide.

## WORKFLOW

1. Inspect the current EF HTML/PHP structure and EF CSS first.
2. Identify why the G8 groupbox is stretching.
3. Make the smallest CSS/layout change necessary.
4. Do not invent a new layout system if the existing layout can be corrected.
5. Verify that Panel 1–9 remain visually unchanged.
6. Verify G8 contains exactly EF 58–61 and its groupbox width follows those 4 cards.
7. Show the exact file(s) changed and the relevant diff.
8. Do not commit, push, modify database, or modify any poller.

## IMPORTANT PROJECT RULES

- Follow the existing project conventions.
- Inspect before modifying.
- No editor; use terminal/PowerShell commands.
- Do not touch unrelated files.
- Do not invent filenames, routes, APIs, or schemas.
- Work incrementally and verify the result.
- One command at a time where practical.

## ACCEPTANCE CRITERIA

- [ ] EF Panel 1–9 remain unchanged.
- [ ] EF G8 still contains EF 58, EF 59, EF 60, EF 61.
- [ ] G8 groupbox no longer spans the full screen width.
- [ ] G8 width follows the actual 4-card content.
- [ ] No changes to backend/API/polling/database.
- [ ] Only the necessary CSS/layout file(s) are changed.
- [ ] Diff is clean and limited to this visual fix.
- [ ] No commit or push.
