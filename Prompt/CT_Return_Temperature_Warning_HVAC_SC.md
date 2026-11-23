# Task: CT Return Temperature Warning Threshold — HVAC_SC

## Objective
Implement a Warning threshold for `Ret Tmp` (Return Temperature) on **all CT cards** on the `HVAC_SC` page of the HVAC Monitoring web app.

First inspect the existing Warning implementation for AHU or Room Temperature and reuse its logic, CSS classes, and presentation pattern. Do not invent a separate mechanism if an existing one can be reused.

## Threshold rule
- `Ret Tmp > 32.0 °C` → **Warning**
- `Ret Tmp <= 32.0 °C` → **Normal**
- Missing, null, empty, or invalid data → follow existing unavailable-data behavior; do not falsely classify it as Normal or Warning.

Boundary checks:
- `32.0 °C` → Normal
- `32.1 °C` → Warning
- `33.9 °C` → Warning
- `34.7 °C` → Warning
- `30.5 °C` → Normal

Determine whether `0.0 °C` is a valid reading or an existing unavailable-data sentinel before evaluating it. Preserve the application's existing data semantics.

## Mandatory inspection before editing
Inspect the repository and identify:
1. The AHU temperature threshold implementation.
2. The Room Temperature warning implementation, if present.
3. The CT card rendering code on `HVAC_SC`.
4. The actual data field used for CT `Ret Tmp`.
5. Existing warning CSS classes, indicators, and conditional rendering.
6. Whether the warning logic lives in PHP, JavaScript, the API, or a combination.

Discover actual filenames and function names from the repository; do not guess.

## Implementation requirements
- Reuse the closest existing AHU/Room Temperature warning pattern.
- Apply the rule dynamically to every CT card, not just the cards visible in the screenshot. Include CT 11A/11B, CT 12A/12B, CT 13A/13B, and any other CT cards present.
- When `Ret Tmp > 32.0 °C`, show the existing Warning treatment on the `Ret Tmp` field and/or its established warning indicator.
- When `Ret Tmp <= 32.0 °C`, restore the normal visual treatment.
- Ensure the warning updates during the page's existing automatic refresh.
- Keep the temperature warning separate from equipment status. Do not change Running/Stopped logic, the motor indicator, frequency/current/drive-temperature thresholds, or infer equipment status from zero frequency/current.
- Preserve existing handling for missing or invalid measurements; do not silently convert them to `0.0 °C`.
- Keep the change minimal. Do not redesign the page, add dependencies, or refactor unrelated code.

## Scope and safety constraints
Only change the CT Return Temperature Warning behavior on `HVAC_SC`.
- Do not change AHU or Room Temperature behavior.
- Do not modify CCP, CHILLER, CHWP, or other HVAC sections.
- Do not change database schemas, tables, or records.
- Do not change pollers, PLC logic, Modbus mappings, unrelated APIs, production configuration, or credentials.
- Do not deploy or perform live equipment tests without explicit approval.

## Testing and verification
Use the project's existing test/build tools. Verify at least:
- `30.5 °C` → Normal
- `32.0 °C` → Normal
- `32.1 °C` → Warning
- `33.9 °C` → Warning
- `34.7 °C` → Warning
- Missing/null/invalid data → unavailable state, without a false Warning or Normal
- `0.0 °C` → preserve existing semantics after checking whether zero means unavailable
- All CT cards use the same rule.
- Warning appears/disappears correctly as values cross the threshold.
- Automatic refresh updates the warning.
- Equipment status and AHU/Room warning behavior remain unchanged.

Add focused tests only if practical with the existing test framework. Do not add a new dependency just for this task.

## Git and change safety
- Inspect `git status` before editing.
- Preserve user modifications and uncommitted work.
- Do not reset, revert, overwrite, or clean unrelated files.
- Review `git diff`; every change must be relevant to this task.
- Run PHP syntax checks for modified PHP files and relevant JavaScript/CSS checks available in the project.
- Do not change the database schema.

## Final report
Report:
1. Root cause of the missing CT Warning.
2. Existing AHU/Room implementation reused.
3. Files changed and each change's purpose.
4. Exact threshold condition.
5. Test results for `32.0`, `32.1`, `33.9`, `34.7 °C`, and unavailable data.
6. Confirmation that all CT cards are covered.
7. Confirmation that unrelated sections, database schema, pollers, and equipment status logic were untouched.
8. Remaining limitations or browser checks needed.

If the existing pattern cannot be identified confidently, stop before speculative edits and explain what needs clarification.

## Definition of done
Every CT card on `HVAC_SC` shows the existing Warning treatment when `Ret Tmp > 32.0 °C`, returns to Normal at `Ret Tmp <= 32.0 °C`, handles unavailable data correctly, and passes relevant checks without unrelated behavior changes.
