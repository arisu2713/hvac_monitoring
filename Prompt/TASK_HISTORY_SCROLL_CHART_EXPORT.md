# Task: Improve History Tables — Scrolling, Chart, Excel Export

## Goal
Improve the existing ENERGY and ROOM TEMP & RH history pages with vertical scrolling, optional charts, and Excel export. The current dashboard appearance is approved and must remain the baseline.

## 1. Audit First
- Inspect `git status`, `git diff`, `index.php`, `style.css`, relevant APIs, and the existing history-table implementation.
- Preserve all existing local changes, including the approved table-header alignment and column separators.
- Do not overwrite or discard existing work.

## 2. Preserve the Current Design — Mandatory
The current dashboard design is approved. Do not redesign it.

Preserve the dark theme, colors, typography, text sizing, card sizes, spacing, page layout, navigation, existing controls, header alignment, column separators, and existing table styling except where a requested fix requires a change.

The only intended visible additions/changes are:
- Corrected table scrolling behavior
- Compact `CHART` and `EXPORT TO EXCEL` buttons matching existing button styles
- An optional chart panel when the user opens it

When the chart is closed, the page should look as close as possible to its current appearance. Do not significantly move or restructure existing elements. If a substantial layout redesign seems necessary, stop and report before implementing it.

Compare before/after screenshots at the same viewport size.

## 3. Fix Desktop Table Scrolling
- On a fullscreen desktop browser, the history table must scroll vertically when data exceeds the available viewport height.
- Preserve horizontal scrolling for wide tables.
- Keep the table header visible while scrolling where feasible.
- Scrolling must continue to work in windowed desktop and mobile layouts.
- Do not lock the whole page or make navigation/controls inaccessible.
- Keep the fix scoped to the relevant history pages.

## 4. Add Charts — Only Two Pages
Charts must be available **only** on ENERGY and ROOM TEMP & RH. Do not add charts to AHU, EF, ALARM, or any other page.

### ENERGY
- Add a `CHART` button to show/hide the chart.
- Plot actual historical energy data for the relevant selected CT/channel and active date range.
- Use the same filtered data represented by the table.
- Use the correct source unit, such as kWh where applicable.
- Update the chart when the selected channel/category or date range changes.
- Never use dummy or fabricated values.

### ROOM TEMP & RH
- Add a `CHART` button to show/hide the chart.
- Plot actual historical data for the selected metric: temperature or relative humidity.
- Use °C for temperature and % RH for humidity where those are the source units.
- Use the same filtered data represented by the table.
- Update the chart when the selected metric or date range changes.
- Never use dummy or fabricated values.

### Chart implementation rules
- Keep the table available when the chart is open.
- Inspect existing chart libraries before adding dependencies; avoid large new dependencies unless necessary.
- Handle NULL/missing values honestly; do not convert them to zero.
- Do not change API, queries, database, or poller unless genuinely required. If an API change is necessary, keep it minimal and explain why.
- Ensure charts are readable on desktop and mobile.

## 5. Add Excel Export
Add one `EXPORT TO EXCEL` button to the ENERGY table and one to the ROOM TEMP & RH table.

- Export all rows matching active filters, not only visible rows or the first page.
- Include column headers, timestamps, and values represented by the filtered table.
- Preserve numeric values as numbers and handle timestamps consistently.
- Use a real `.xlsx` file if using the `.xlsx` extension. If exporting Excel-compatible CSV, use `.csv` and clearly report that format; never label CSV as `.xlsx`.
- Export must reflect the active channel/category/metric and date range.
- Verify exported rows and values match the active filtered result.

## 6. Safety and Scope
- Do not modify database schemas or contents.
- Do not modify pollers, acquisition services, or acquisition configuration.
- Do not fabricate or change telemetry values.
- Do not affect unrelated pages.
- Preserve all pre-existing changes, including `PROJECT.md`, `api_ahu.php`, `api_ef.php`, `index.php`, `style.css`, and `docs/`.
- Do not use `git add -A`.
- Do not commit or push yet.

## 7. Verification
1. Test vertical scrolling on fullscreen desktop with more rows than fit in the viewport.
2. Test horizontal scrolling and sticky headers.
3. Test the CHART toggle on ENERGY and ROOM TEMP & RH only.
4. Change dates and metrics/channels; confirm charts follow active filters.
5. Verify chart values originate from the same actual data as the table.
6. Export both tables and verify headers, row counts, timestamps, and values against filtered results.
7. Confirm exported files open correctly in the intended spreadsheet application.
8. Run PHP syntax checks on all changed PHP files.
9. Check browser console for JavaScript errors.
10. Regression-test AHU, EF, ALARM, navigation, and other existing pages.
11. Compare before/after screenshots at the same viewport size. Existing styling must remain consistent except for requested buttons, optional chart panel, and necessary scrolling behavior.
12. Review `git diff` and `git status`; report remaining issues.

## 8. Final Report
Report:
- Files changed and purpose of each change
- How desktop scrolling was fixed
- Chart behavior and data source on each supported page
- Export format and verification results
- PHP/API/browser test results
- Screenshot comparison results
- Any unresolved issues

After implementation and verification, stop for review. Do not commit or push until explicitly instructed.
