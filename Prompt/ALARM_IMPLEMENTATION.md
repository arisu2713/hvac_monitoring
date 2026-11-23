# ALARM IMPLEMENTATION

## Objective

Implement the Global Alarm Page for the HVAC Monitoring Web App, including:

- Global alarm list
- ALARM / WARNING separation
- ACTIVE / CLEARED status
- Real-time refresh
- Browser alarm sound
- MUTE / UNMUTE control

## Safety Rules

- Work only inside the current HVAC Monitoring Web App repository.
- Do NOT modify any poller.
- Do NOT modify the database schema.
- Do NOT modify the `alarm_events` table structure.
- Do NOT modify Apache configuration.
- Do NOT modify PHP server configuration.
- Do NOT modify system services.
- Do NOT modify unrelated projects.
- Preserve all existing dashboard functionality.
- Do NOT commit or push Git changes.
- Inspect relevant existing code/data first, then implement only the required changes.

## 1. Global Alarm Page

Add a new Alarm page/menu entry.

Read from the existing:

`hvac_current.alarm_events`

Do not create a new alarm table.

Use exactly two alarm categories:

- ALARM = native equipment alarm/status
- WARNING = threshold/value-limit violation

Keep ALARM and WARNING conceptually separate.

## 2. Alarm Information

Display, where available:

- Active / Cleared status
- Category: ALARM or WARNING
- Equipment
- Source / point
- Alarm message
- Event time
- Cleared time
- Event identifier

Inspect the actual existing `alarm_events` structure before implementation. Do not assume column names.

## 3. Active Summary

Show:

- Active Alarm count
- Active Warning count
- Total Active count

Counts must come from actual `alarm_events` data.

## 4. Active / Cleared

Clearly distinguish ACTIVE and CLEARED.

Active alarms should be visually prominent.

Do not confuse existing per-card alarm status with the global alarm event system.

## 5. Alarm API

Create a dedicated alarm API endpoint if appropriate.

Requirements:

- Use existing database configuration.
- Use prepared statements / safe SQL.
- Do not change database schema.
- Return structured data for AJAX polling.
- Follow the existing application's authentication model.
- Inspect existing APIs first and follow their conventions.

## 6. Automatic Refresh

Refresh alarm data automatically without a full page reload.

Use a reasonable polling interval.

Avoid excessive requests.

Update:

- alarm list
- active counts
- alarm status

## 7. Sound Alarm

Implement browser-side alarm sound.

Preferred approach:

- Web Audio API generated alert tone.
- No external CDN.
- No third-party audio service.
- No large audio asset.

Behavior:

Initial page load:
- Existing active alarms must NOT immediately produce sound.

New alarm:
- Newly detected ACTIVE alarm should play sound once.
- Same event must not repeatedly sound on every refresh.

Existing alarm:
- Do not retrigger continuously.

## 8. Browser Autoplay

Modern browsers may block audio until user interaction.

Handle this gracefully.

Provide a clear user interaction path to enable sound when required.

Do not bypass browser security restrictions.

## 9. Mute / Unmute

Add:

- MUTE
- UNMUTE

Mute must:

- silence alarm sound
- NOT hide alarms
- NOT clear alarms
- NOT modify `alarm_events`
- NOT modify database state

Persist mute state with:

`localStorage`

Restore mute state after page refresh.

## 10. New Alarm Detection

Track stable event IDs already seen by the browser.

Behavior:

1. First API response:
   - Populate page.
   - Record current active alarm IDs.
   - Do NOT play sound.

2. Later responses:
   - Detect newly appearing active alarm IDs.
   - Play sound once if not muted.

3. Existing active alarms:
   - Do not play again.

4. Cleared alarms:
   - Update normally.

Do not identify events only by row position or array order.

## 11. UI

Follow the existing HVAC Monitoring visual style.

Do not redesign unrelated pages.

Support desktop and mobile.

Clearly distinguish:

- ALARM
- WARNING
- ACTIVE
- CLEARED

A compact table/list layout is appropriate for alarm history.

## 12. Authentication

Follow the existing login/authentication mechanism.

Inspect:

- `auth.php`
- existing API authentication
- existing page access behavior

Do not accidentally expose alarm data to unauthenticated users.

## 13. Before Editing

First inspect:

1. Existing relevant files
2. Existing `alarm_events` usage/schema
3. Existing API conventions
4. Existing authentication
5. Existing UI/CSS conventions

Then report the minimum files that will be changed/added.

Implement only this scope.

## 14. PROJECT.md

After implementation update `PROJECT.md` with:

- ALARM vs WARNING
- ACTIVE vs CLEARED
- Alarm API
- Refresh behavior
- New alarm detection
- Sound behavior
- Mute behavior
- localStorage mute state
- Browser autoplay handling
- Files added/modified

## 15. Validation

After implementation:

- Run PHP syntax checks on every modified PHP file.
- Verify the alarm API can be reached.
- Verify the Alarm page loads.
- Verify alarm data comes from `alarm_events`.
- Verify existing alarms on initial load do not sound.
- Verify newly detected alarms can sound.
- Verify the same alarm does not retrigger every polling cycle.
- Verify MUTE disables sound.
- Verify UNMUTE restores sound.
- Verify mute survives page refresh.
- Verify mute does not hide alarms.
- Verify mute does not clear alarms.
- Verify mute does not modify the database.
- Verify existing dashboard pages remain functional.

## 16. Implementation Report

Create:

`ALARM_IMPLEMENTATION_REPORT.txt`

Include:

1. Files changed
2. Files added
3. What was implemented
4. Existing `alarm_events` structure used
5. API design
6. Alarm state handling
7. ALARM vs WARNING handling
8. Sound behavior
9. New alarm detection
10. Mute behavior
11. localStorage behavior
12. Validation performed
13. Regression checks
14. Limitations / follow-up items

## 17. Git

Do NOT:

- git add
- git commit
- git push
- reset
- checkout
- revert

Leave Git history and staging state unchanged.

## Final Scope

Implement ONLY:

**Global Alarm Page + Alarm API + Alarm Sound + Mute/Unmute**

Do NOT implement:

- Energy Table
- Temp & RH Hourly Table
