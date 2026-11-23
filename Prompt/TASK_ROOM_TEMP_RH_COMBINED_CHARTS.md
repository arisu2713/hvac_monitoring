# Task: Consolidate ROOM TEMP & RH Charts

## Objective

Modify only the chart presentation on the existing ROOM TEMP & RH page.

Replace the current per-room/multiple-chart presentation with exactly two combined time-series charts:

1. **Temperature** — all room temperature points as separate colored series.
2. **Humidity** — all room humidity points as separate colored series.

## Requirements

### 1. Temperature chart

- Combine all available room temperature points into one chart.
- Use °C as the unit.
- Add a horizontal reference line at exactly **30 °C**.
- Give every room a distinct, visually distinguishable line color.
- Show a legend identifying each room.

### 2. Humidity chart

- Combine all available room humidity points into one chart.
- Use % RH as the unit.
- Add a horizontal reference line at exactly **65% RH**.
- Give every room a distinct, visually distinguishable line color.
- Show a legend identifying each room.

### 3. Data and interaction

- Use actual API/history data; never fabricate readings.
- Respect the existing date range and metric/filter behavior where applicable.
- Keep timestamps aligned correctly across room series.
- Preserve missing/NULL readings as gaps; never convert them to zero.
- Keep each room's line color consistent within its chart.
- Make the reference line clearly distinguishable from room series.
- Support narrow/mobile screens without breaking the layout.
- Display exactly two charts: one Temperature and one Humidity.

## Scope and safety

- Preserve the existing dark dashboard theme, typography, cards, spacing, navigation, history table, scrolling, and export behavior.
- Do not change AHU, HVAC SC, EXHAUST FAN, AHU / EF G8, ENERGY, or ALARM pages.
- Do not modify database schemas, pollers, acquisition logic, services, or production data.
- Inspect Git status and existing diffs before editing. Preserve all pre-existing local changes.
- Do not use `git add -A`.
- Do not commit or push unless explicitly instructed.

## Verification checklist

- [ ] Exactly two charts render on ROOM TEMP & RH.
- [ ] All available room temperature series appear in the Temperature chart.
- [ ] All available room humidity series appear in the Humidity chart.
- [ ] Each room has a distinct line color and a correct legend label.
- [ ] The Temperature reference line is exactly 30 °C.
- [ ] The Humidity reference line is exactly 65% RH.
- [ ] Date/filter changes update the correct chart data.
- [ ] NULL readings remain gaps, not zero values.
- [ ] No JavaScript console errors.
- [ ] History table, export, and scrolling still work.
- [ ] Other dashboard pages show no regressions.

## Final report

Report:
- Files changed.
- Tests performed and results.
- Any limitations or data points that are unavailable.

Stop after implementation and verification. Do not commit or push.
