# HVAC Monitoring Verification Tools

Prerequisites: Node.js, npx, and installed Playwright/Chromium.

1. Inline Script ESLint (no-undef check):
   node tools/lint_inline.js

2. Automated Alarm Regression Suite:
   node tools/tests/alarm_regression.js

Both tools run without adding local package.json or node_modules to the repository.