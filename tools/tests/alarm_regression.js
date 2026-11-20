#!/usr/bin/env node
/**
 * tools/tests/alarm_regression.js
 *
 * Automated regression test suite for Alarm & Warning system:
 * - Phase 2 Safety Fixes (Items 0 to 5)
 * - Phase 2 PM Alarm Suppression for Exhaust Fans (Cases 8 to 17)
 *
 * Uses headless Chromium (via pre-installed Playwright) with in-memory page mocks.
 * Zero database writes for these regression assertions.
 */

const fs = require('fs');

let pw;
const candidatePaths = [
    'playwright',
    'playwright-core',
    'C:/Users/ADMIN/node_modules/playwright-core',
    'C:/Users/ADMIN/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright-core'
];
for (const p of candidatePaths) {
    try {
        pw = require(p);
        break;
    } catch (_) {}
}

if (!pw) {
    console.error('[ERROR] Playwright or playwright-core could not be resolved from pre-installed modules.');
    process.exit(1);
}

const chromePaths = [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    process.env.CHROME_BIN,
    process.env.CHROME_PATH
].filter(Boolean);

let executablePath;
for (const cp of chromePaths) {
    if (fs.existsSync(cp)) {
        executablePath = cp;
        break;
    }
}

const sessionFilePath = 'C:\\xampp\\tmp\\sess_antigravitytestsession';
const sessionData = 'user_id|i:1;username|s:5:"admin";is_admin|i:1;';
fs.writeFileSync(sessionFilePath, sessionData, 'utf8');

async function runRegressionSuite() {
    const results = [];
    const launchOptions = { headless: true };
    if (executablePath) {
        launchOptions.executablePath = executablePath;
    }

    const browser = await pw.chromium.launch(launchOptions);

    try {
        const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
        await context.addCookies([{
            name: 'PHPSESSID',
            value: 'antigravitytestsession',
            domain: 'localhost',
            path: '/'
        }]);

        // Inject mock WebView2 host before page scripts execute
        await context.addInitScript(() => {
            window.__webviewMessages = [];
            window.__consoleErrors = [];
            const listeners = [];
            window.chrome = window.chrome || {};
            window.chrome.webview = {
                postMessage: function(msg) {
                    window.__webviewMessages.push(msg);
                },
                addEventListener: function(type, cb) {
                    if (type === 'message') {
                        listeners.push(cb);
                    }
                },
                _dispatchMessage: function(data) {
                    for (const cb of listeners) {
                        try { cb({ data }); } catch (e) { console.error(e); }
                    }
                }
            };
        });

        const page = await context.newPage();
        page.on('console', msg => {
            if (msg.type() === 'error') {
                page.evaluate((text) => {
                    window.__consoleErrors = window.__consoleErrors || [];
                    window.__consoleErrors.push(text);
                }, msg.text()).catch(() => {});
            }
        });

        await page.goto('http://localhost:8080/hvac_monitoring/index.php', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        // Helper inside page to clear and reset states between tests
        const resetState = async () => {
            await page.evaluate(() => {
                window.__webviewMessages = [];
                window.__consoleErrors = [];
                activeAlarmTriggerKeys.clear();
                acknowledgedAlarmKeys.clear();
                lastDbAlarmKeys.clear();
                lastEfOffKeys.clear();
                lastDbAlarmEfNames.clear();
                efDebounceMap.clear();
                liveEfFirstSeenMap.clear();
                syntheticLiveEfAlarms = [];
                initialAlarmEvaluationDone = true;
                pendingEarlyAckUntil = 0;

                // PM State reset
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                pmHistoryList = [];
                pmHistoryServerTime = '2026-11-16 12:00:00';
                alarmPmFilterShowHidden = false;
                currentEquipment = 'ALARM';
                if (typeof maintenanceByUnit !== 'undefined') {
                    maintenanceByUnit.clear();
                }
            });
        };

        // =========================================================================
        // ORIGINAL 10 TESTS (Phase 2 Safety Fixes & Baseline Invariants)
        // =========================================================================

        // 1. ALARM DB pada AHU RUN dengan PM termuat
        {
            await resetState();
            const res = await page.evaluate(() => {
                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                const k = maintenanceKey('AHU', 'AHU 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                const alarms = [{
                    id: 101,
                    equipment: 'AHU 01',
                    equip_type: 'AHU',
                    event_class: 'ALARM',
                    status: 'ACTIVE',
                    description: 'AHU 01 Trip Alarm'
                }];
                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');
                return { unackCount, lastMsg, isBlinking };
            });

            const passed = res.unackCount === 1 && res.lastMsg === 'PLAY_ALARM' && res.isBlinking === true;
            results.push({ name: '1. ALARM DB pada AHU RUN dengan PM termuat (PM hanya mutes EF, AHU tetap bunyi)', passed, details: res });
        }

        // 2a. ALARM DB EF OFF dengan PM aktif
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                const alarms = [{
                    id: 102,
                    equipment: 'EF 01',
                    equip_type: 'EF',
                    event_class: 'ALARM',
                    status: 'ACTIVE',
                    description: 'Exhaust Fan stopped'
                }];
                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');
                return { unackCount, lastMsg, isBlinking };
            });

            const passed = res.unackCount === 0 && res.lastMsg === 'STOP_ALARM' && res.isBlinking === false;
            results.push({ name: '2a. ALARM DB EF OFF dengan PM aktif (diredam / tidak bunyi)', passed, details: res });
        }

        // 2b. ALARM DB EF OFF tanpa PM
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();

                const alarms = [{
                    id: 103,
                    equipment: 'EF 01',
                    equip_type: 'EF',
                    event_class: 'ALARM',
                    status: 'ACTIVE',
                    description: 'Exhaust Fan stopped'
                }];
                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');
                return { unackCount, lastMsg, isBlinking };
            });

            const passed = res.unackCount === 1 && res.lastMsg === 'PLAY_ALARM' && res.isBlinking === true;
            results.push({ name: '2b. ALARM DB EF OFF tanpa PM (bunyi)', passed, details: res });
        }

        // 3. Baris sengaja error (alarm lain tetap bunyi)
        {
            await resetState();
            const res = await page.evaluate(() => {
                const corruptRow = {};
                Object.defineProperty(corruptRow, 'status', {
                    get: function() { throw new Error('Simulated fault in row property access'); }
                });
                corruptRow.id = 999;
                corruptRow.equipment = 'CORRUPT_UNIT';

                allData.AHU = [{ name: 'AHU 02', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();
                const validRow = {
                    id: 104,
                    equipment: 'AHU 02',
                    equip_type: 'AHU',
                    event_class: 'ALARM',
                    status: 'ACTIVE',
                    description: 'AHU 02 Fault'
                };

                evaluateAlarmTriggers([corruptRow, validRow], [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');
                const hasValidKey = activeAlarmTriggerKeys.has('ALARM:104');
                const hasFailSafeKey = activeAlarmTriggerKeys.has('ALARM:999');
                return { unackCount, lastMsg, isBlinking, hasValidKey, hasFailSafeKey };
            });

            const passed = res.unackCount >= 2 && res.lastMsg === 'PLAY_ALARM' && res.isBlinking === true && res.hasValidKey && res.hasFailSafeKey;
            results.push({ name: '3. Baris sengaja error (try/catch fail-safe, alarm lain tetap bunyi)', passed, details: res });
        }

        // 4. EF OFF dengan last_update null (20 detik)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();

                const fanOff = { id: 1, name: 'EF 01', status: 0, last_update: null };

                // 4.1 First evaluation at t = 0
                evaluateAlarmTriggers([], [fanOff], [], true, true);
                const countAtT0 = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;

                // 4.2 Simulated poll at t = 10s (below 20s confirm limit)
                const entry = efDebounceMap.get('EF 01');
                entry.firstOffSeen = Date.now() - 10000;
                evaluateAlarmTriggers([], [fanOff], [], true, true);
                const countAtT10 = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;

                // 4.3 Simulated poll at t = 21s (exceeds 20s confirm limit)
                entry.firstOffSeen = Date.now() - 21000;
                evaluateAlarmTriggers([], [fanOff], [], true, true);
                const countAtT21 = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsgAtT21 = window.__webviewMessages[window.__webviewMessages.length - 1];

                // 4.4 Fan turns back to RUN -> resets debounce
                const fanRun = { id: 1, name: 'EF 01', status: 1, last_update: null };
                evaluateAlarmTriggers([], [fanRun], [], true, true);
                const debounceCleared = !efDebounceMap.has('EF 01');

                return { countAtT0, countAtT10, countAtT21, lastMsgAtT21, debounceCleared };
            });

            const passed = res.countAtT0 === 0 && res.countAtT10 === 0 && res.countAtT21 === 1 && res.lastMsgAtT21 === 'PLAY_ALARM' && res.debounceCleared === true;
            results.push({ name: '4. EF OFF dengan last_update null (konfirmasi 20s, reset saat run)', passed, details: res });
        }

        // 5. STOP_ALARM saat pagehide
        {
            await resetState();
            const res = await page.evaluate(() => {
                activeAlarmTriggerKeys.add('ALARM:555');
                updateGlobalAlarmWidget();
                const msgBeforePagehide = window.__webviewMessages[window.__webviewMessages.length - 1];

                window.dispatchEvent(new Event('pagehide'));
                const msgAfterPagehide = window.__webviewMessages[window.__webviewMessages.length - 1];

                return { msgBeforePagehide, msgAfterPagehide };
            });

            const passed = res.msgBeforePagehide === 'PLAY_ALARM' && res.msgAfterPagehide === 'STOP_ALARM';
            results.push({ name: '5. STOP_ALARM saat pagehide ketika alarm aktif sounding', passed, details: res });
        }

        // 6a. Pending ACK: ACK sebelum polling pertama = ter-ACK
        {
            await resetState();
            const res = await page.evaluate(() => {
                initialAlarmEvaluationDone = false;
                pendingEarlyAckUntil = 0;

                window.chrome.webview._dispatchMessage('ACK_FROM_C#');
                const pendingRecorded = pendingEarlyAckUntil > 0;

                const alarms = [{
                    id: 201,
                    equipment: 'AHU 01',
                    equip_type: 'AHU',
                    event_class: 'ALARM',
                    status: 'ACTIVE'
                }];
                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const isAcked = acknowledgedAlarmKeys.has('ALARM:201');
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');

                return { pendingRecorded, unackCount, isAcked, lastMsg, isBlinking };
            });

            const passed = res.pendingRecorded && res.unackCount === 0 && res.isAcked && res.lastMsg === 'STOP_ALARM' && !res.isBlinking;
            results.push({ name: '6a. Pending ACK: ACK sebelum polling pertama = ter-ACK pada evaluasi pertama', passed, details: res });
        }

        // 6b. Pending ACK: ACK saat tidak ada alarm setelah polling pertama lalu alarm baru 5 detik kemudian = tetap bunyi
        {
            await resetState();
            const res = await page.evaluate(() => {
                initialAlarmEvaluationDone = true;
                pendingEarlyAckUntil = 0;

                window.chrome.webview._dispatchMessage('ACK_FROM_C#');
                const pendingHeld = pendingEarlyAckUntil > 0;

                const alarms = [{
                    id: 202,
                    equipment: 'AHU 01',
                    equip_type: 'AHU',
                    event_class: 'ALARM',
                    status: 'ACTIVE'
                }];
                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const isAcked = acknowledgedAlarmKeys.has('ALARM:202');
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');

                return { pendingHeld, unackCount, isAcked, lastMsg, isBlinking };
            });

            const passed = !res.pendingHeld && res.unackCount === 1 && !res.isAcked && res.lastMsg === 'PLAY_ALARM' && res.isBlinking;
            results.push({ name: '6b. Pending ACK: ACK saat tidak ada alarm diabaikan, alarm baru 5 detik kemudian tetap bunyi', passed, details: res });
        }

        // 6c. Pending ACK: Polling pertama gagal/lebih dari 15 detik = pending kedaluwarsa dan alarm tetap bunyi
        {
            await resetState();
            const res = await page.evaluate(() => {
                initialAlarmEvaluationDone = false;
                pendingEarlyAckUntil = 0;

                window.chrome.webview._dispatchMessage('ACK_FROM_C#');

                pendingEarlyAckUntil = Date.now() - 1000;

                const alarms = [{
                    id: 203,
                    equipment: 'AHU 01',
                    equip_type: 'AHU',
                    event_class: 'ALARM',
                    status: 'ACTIVE'
                }];
                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                evaluateAlarmTriggers(alarms, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const isAcked = acknowledgedAlarmKeys.has('ALARM:203');
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');

                return { unackCount, isAcked, lastMsg, isBlinking };
            });

            const passed = res.unackCount === 1 && !res.isAcked && res.lastMsg === 'PLAY_ALARM' && res.isBlinking;
            results.push({ name: '6c. Pending ACK: Polling pertama > 15 detik = pending kedaluwarsa, alarm tetap bunyi', passed, details: res });
        }

        // 7. WARNING tidak pernah bunyi
        {
            await resetState();
            const res = await page.evaluate(() => {
                const warnings = [{
                    id: 301,
                    equipment: 'ROOM 20014',
                    equip_type: 'ROOM',
                    event_class: 'WARNING',
                    status: 'ACTIVE',
                    metric: 'TEMP',
                    description: 'High Temperature Warning'
                }, {
                    id: 302,
                    equipment: 'AHU 01',
                    equip_type: 'AHU',
                    event_class: 'WARNING',
                    status: 'ACTIVE',
                    metric: 'TEMP',
                    description: 'AHU Temp Warning'
                }];

                evaluateAlarmTriggers(warnings, [], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');

                return { unackCount, lastMsg, isBlinking };
            });

            const passed = res.unackCount === 0 && res.lastMsg === 'STOP_ALARM' && res.isBlinking === false;
            results.push({ name: '7. WARNING tidak pernah bunyi (amber warning tidak pernah memicu alarm sound)', passed, details: res });
        }

        // =========================================================================
        // NEW 10 TESTS: Phase 2 PM Alarm Suppression for Exhaust Fans (Koreksi A - E)
        // =========================================================================

        // 8. ACTIVE tersembunyi saat PM aktif (Koreksi A)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 501,
                        equipment: 'EF 01',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'ACTIVE',
                        description: 'Exhaust Fan stopped'
                    }]
                };

                renderAlarm();

                const activeRows = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');
                const suppressedCount = banner ? banner.textContent : '';

                return {
                    activeRowsCount: activeRows.length,
                    bannerText: suppressedCount,
                    hasBanner: !!banner
                };
            });

            const passed = res.activeRowsCount === 0 && res.hasBanner && res.bannerText.includes('1 alarm EF disembunyikan');
            results.push({ name: '8. ACTIVE tersembunyi saat PM aktif (Koreksi A)', passed, details: res });
        }

        // 9. ACTIVE muncul lagi setelah PM berakhir saat kipas masih OFF (Koreksi A)
        {
            await resetState();
            const res = await page.evaluate(() => {
                // Step A: Fan has active PM
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 501,
                        equipment: 'EF 01',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'ACTIVE',
                        description: 'Exhaust Fan stopped'
                    }]
                };
                renderAlarm();
                const beforeCount = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)').length;

                // Step B: PM ends while fan is still OFF (active DB alarm unchanged)
                maintenanceByUnit.clear();
                evaluateAlarmTriggers(allData.ALARM.alarms, [], [], true, true);
                renderAlarm();

                const afterRows = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)');
                const afterCount = afterRows.length;
                const renderedIdCell = afterRows[0] ? afterRows[0].querySelector('.alarm-cell-id').textContent : '';
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];

                return {
                    beforeCount,
                    afterCount,
                    renderedId: renderedIdCell,
                    lastMsg
                };
            });

            const passed = res.beforeCount === 0 && res.afterCount === 1 && String(res.renderedId) === '501' && res.lastMsg === 'PLAY_ALARM';
            results.push({ name: '9. ACTIVE muncul lagi setelah PM berakhir saat kipas masih OFF (baris DB lama, suara bunyi)', passed, details: res });
        }

        // 10. CLEARED di dalam jendela tersembunyi (Koreksi B)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                pmHistoryList = [{
                    equip_type: 'EXHAUST_FAN',
                    unit_name: 'EF 02',
                    started_at: '2026-11-16 08:00:00',
                    ended_at: '2026-11-16 12:00:00'
                }];

                // Cleared alarm strictly within 08:00 and 12:00
                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 601,
                        equipment: 'EF 02',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'CLEARED',
                        raised_at: '2026-11-16 08:30:00',
                        cleared_at: '2026-11-16 09:30:00',
                        description: 'EF 02 Cleared'
                    }]
                };

                renderAlarm();

                const clearedRows = document.querySelectorAll('.alarm-section-critical .alarm-group:not(.alarm-group-active) .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');

                return {
                    clearedCount: clearedRows.length,
                    hasBanner: !!banner
                };
            });

            const passed = res.clearedCount === 0 && res.hasBanner;
            results.push({ name: '10. CLEARED di dalam jendela tersembunyi (Koreksi B)', passed, details: res });
        }

        // 11. CLEARED di luar jendela tampil (Koreksi B)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                pmHistoryList = [{
                    equip_type: 'EXHAUST_FAN',
                    unit_name: 'EF 02',
                    started_at: '2026-11-16 08:00:00',
                    ended_at: '2026-11-16 12:00:00'
                }];

                // Cleared alarm strictly before window (06:00 to 07:00)
                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 602,
                        equipment: 'EF 02',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'CLEARED',
                        raised_at: '2026-11-16 06:00:00',
                        cleared_at: '2026-11-16 07:00:00',
                        description: 'EF 02 Morning Cleared'
                    }]
                };

                renderAlarm();

                const clearedRows = document.querySelectorAll('.alarm-section-critical .alarm-group:not(.alarm-group-active) .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');

                return {
                    clearedCount: clearedRows.length,
                    hasBanner: !!banner
                };
            });

            const passed = res.clearedCount === 1 && !res.hasBanner;
            results.push({ name: '11. CLEARED di luar jendela tampil (Koreksi B)', passed, details: res });
        }

        // 12. Alarm melingkupi jendela (spans window) tersembunyi (Koreksi B)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                pmHistoryList = [{
                    equip_type: 'EXHAUST_FAN',
                    unit_name: 'EF 02',
                    started_at: '2026-11-16 08:00:00',
                    ended_at: '2026-11-16 12:00:00'
                }];

                // Raised before window (07:00), cleared after window (13:00) -> spans entire PM window
                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 603,
                        equipment: 'EF 02',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'CLEARED',
                        raised_at: '2026-11-16 07:00:00',
                        cleared_at: '2026-11-16 13:00:00',
                        description: 'EF 02 Spanning Alarm'
                    }]
                };

                renderAlarm();

                const clearedRows = document.querySelectorAll('.alarm-section-critical .alarm-group:not(.alarm-group-active) .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');

                return {
                    clearedCount: clearedRows.length,
                    hasBanner: !!banner
                };
            });

            const passed = res.clearedCount === 0 && res.hasBanner;
            results.push({ name: '12. Alarm melingkupi seluruh jendela (spans window) tersembunyi (Koreksi B)', passed, details: res });
        }

        // 13. Fail-safe fetch gagal: tampilkan semua alarm tanpa sembunyi (Koreksi E)
        {
            await resetState();
            const res = await page.evaluate(() => {
                // Active PM entry exists, but history fetch failed (fail-safe trigger)
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = false; // simulated failure
                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 701,
                        equipment: 'EF 01',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'ACTIVE',
                        description: 'EF 01 Fail-Safe Test'
                    }]
                };

                renderAlarm();

                const activeRows = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');

                return {
                    activeCount: activeRows.length,
                    hasBanner: !!banner
                };
            });

            const passed = res.activeCount === 1 && !res.hasBanner;
            results.push({ name: '13. Fail-safe: fetch gagal -> tampilkan semua alarm tanpa sembunyi (Koreksi E)', passed, details: res });
        }

        // 14. Non-EF tidak tersaring (Koreksi C)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;
                // Configure maintenance for AHU 01
                const k = maintenanceKey('AHU', 'AHU 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 801,
                        equipment: 'AHU 01',
                        equip_type: 'AHU',
                        event_class: 'ALARM',
                        status: 'ACTIVE',
                        description: 'AHU 01 Trip'
                    }]
                };

                renderAlarm();

                const activeRows = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)');
                const banner = document.querySelector('.alarm-pm-banner');

                return {
                    activeCount: activeRows.length,
                    hasBanner: !!banner
                };
            });

            const passed = res.activeCount === 1 && !res.hasBanner;
            results.push({ name: '14. Non-EF (AHU/Chiller/Room) tidak pernah tersaring (Koreksi C)', passed, details: res });
        }

        // 15. Kunci pencocokan: nama peralatan, bukan nomor source (Koreksi C)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;

                // PM exists for "EF 53", but the alarm equipment is "EF 01" (source says "EF #53")
                pmHistoryList = [{
                    equip_type: 'EXHAUST_FAN',
                    unit_name: 'EF 53',
                    started_at: '2026-11-16 08:00:00',
                    ended_at: '2026-11-16 12:00:00'
                }];

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [{
                        id: 901,
                        equipment: 'EF 01',
                        source: 'EF #53',
                        equip_type: 'EF',
                        event_class: 'ALARM',
                        status: 'CLEARED',
                        raised_at: '2026-11-16 08:30:00',
                        cleared_at: '2026-11-16 09:30:00'
                    }]
                };

                renderAlarm();
                const clearedRowsMismatch = document.querySelectorAll('.alarm-section-critical .alarm-group:not(.alarm-group-active) .alarm-row:not(.alarm-thead)').length;

                // Now test with matching PM unit_name: "EF 01"
                pmHistoryList = [{
                    equip_type: 'EXHAUST_FAN',
                    unit_name: 'EF 01',
                    started_at: '2026-11-16 08:00:00',
                    ended_at: '2026-11-16 12:00:00'
                }];

                renderAlarm();
                const clearedRowsMatch = document.querySelectorAll('.alarm-section-critical .alarm-group:not(.alarm-group-active) .alarm-row:not(.alarm-thead)').length;

                return {
                    clearedRowsMismatch,
                    clearedRowsMatch
                };
            });

            const passed = res.clearedRowsMismatch === 1 && res.clearedRowsMatch === 0;
            results.push({ name: '15. Kunci pencocokan: nama peralatan, bukan nomor source (Koreksi C)', passed, details: res });
        }

        // 16. Hitungan dashboard sesuai daftar yang tampil (Koreksi D)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;

                // EF 01 is under active PM
                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                // 2 Active alarms: AHU 01 (unsuppressed) and EF 01 (suppressed)
                allData.ALARM = {
                    window_hours: 24,
                    alarms: [
                        { id: 1001, equipment: 'AHU 01', equip_type: 'AHU', event_class: 'ALARM', status: 'ACTIVE' },
                        { id: 1002, equipment: 'EF 01', equip_type: 'EF', event_class: 'ALARM', status: 'ACTIVE' }
                    ]
                };

                renderAlarm();

                const countsActiveAlarm = allData.ALARM.counts ? allData.ALARM.counts.active_alarm : null;
                const headerText = document.getElementById('unitCount') ? document.getElementById('unitCount').textContent : '';
                const summaryValue = document.querySelector('.alarm-stat-alarm .alarm-stat-value').textContent;

                return {
                    countsActiveAlarm,
                    headerText,
                    summaryValue
                };
            });

            const passed = res.countsActiveAlarm === 1 && res.headerText === '1 active' && res.summaryValue === '1';
            results.push({ name: '16. Hitungan dashboard (allData.ALARM.counts & UI summary) sesuai daftar tampil (Koreksi D)', passed, details: res });
        }

        // 17. Banner dan toggle Tampilkan/Sembunyikan (Tahap 2 & 3)
        {
            await resetState();
            const res = await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                pmHistoryFetchSuccess = true;

                const k = maintenanceKey('EXHAUST_FAN', 'EF 01');
                maintenanceByUnit.set(k, { active: 1, is_active: true, remaining_seconds: 3600, fetched_at: Date.now() });

                allData.AHU = [{ name: 'AHU 01', status: 1, run_status: 1, run: 1, hz: 50 }];
                lastAhuFetchTime = Date.now();

                allData.ALARM = {
                    window_hours: 24,
                    alarms: [
                        { id: 1101, equipment: 'AHU 01', equip_type: 'AHU', event_class: 'ALARM', status: 'ACTIVE' },
                        { id: 1102, equipment: 'EF 01', equip_type: 'EF', event_class: 'ALARM', status: 'ACTIVE' }
                    ]
                };

                // Initial render: hidden
                renderAlarm();
                const bannerBtn1 = document.querySelector('.alarm-pm-banner-btn');
                const btnText1 = bannerBtn1 ? bannerBtn1.textContent : '';
                const rowsCount1 = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)').length;

                // Click toggle to show hidden
                bannerBtn1.click();
                const bannerBtn2 = document.querySelector('.alarm-pm-banner-btn');
                const btnText2 = bannerBtn2 ? bannerBtn2.textContent : '';
                const rowsCount2 = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)').length;
                const suppressedRow = document.querySelector('.alarm-row.alarm-row-pm-suppressed');
                const hasPmBadge = suppressedRow ? !!suppressedRow.querySelector('.alarm-badge-pm') : false;

                // Click toggle to hide again
                bannerBtn2.click();
                const rowsCount3 = document.querySelectorAll('.alarm-group-critical.alarm-group-active .alarm-row:not(.alarm-thead)').length;

                return {
                    btnText1,
                    rowsCount1,
                    btnText2,
                    rowsCount2,
                    hasSuppressedClass: !!suppressedRow,
                    hasPmBadge,
                    rowsCount3
                };
            });

            const passed = res.btnText1 === 'Tampilkan' && res.rowsCount1 === 1 &&
                           res.btnText2 === 'Sembunyikan' && res.rowsCount2 === 2 &&
                           res.hasSuppressedClass && res.hasPmBadge &&
                           res.rowsCount3 === 1;
            results.push({ name: '17. Banner toggle Tampilkan/Sembunyikan & gaya baris PM redup (Tahap 2 & 3)', passed, details: res });
        }

        // =========================================================================
        // TAHAP 5 TESTS: DAIKIN STALE TELEMETRY IN ALARM SYSTEM (Cases 18 to 21)
        // =========================================================================

        // 18. EF stale when RUN -> does not report normal, does not trigger alarm
        {
            await resetState();
            const res = await page.evaluate(() => {
                allData.efServerTime = '2026-11-20 12:00:00';
                const fan = {
                    id: 58,
                    name: 'EF 58',
                    status: 1,
                    run: 1,
                    trip: 0,
                    last_update: '2026-11-16 18:29:12',
                    age_seconds: 259200
                };
                const status = getEfStatus(fan);
                evaluateAlarmTriggers([], [fan], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const isBlinking = document.querySelector('button[data-equipment="ALARM"]').classList.contains('tab-blinking');

                return { status, unackCount, lastMsg, isBlinking };
            });

            const passed = res.status === 'unknown' && res.unackCount === 0 && res.lastMsg !== 'PLAY_ALARM' && res.isBlinking === false;
            results.push({ name: '18. EF stale when RUN -> status unknown (not normal), does not trigger alarm', passed, details: res });
        }

        // 19. EF stale when OFF -> does not generate new synthetic alarm on fresh session
        {
            await resetState();
            const res = await page.evaluate(() => {
                allData.efServerTime = '2026-11-20 12:00:00';
                const fan = {
                    id: 58,
                    name: 'EF 58',
                    status: 0,
                    run: 0,
                    trip: 0,
                    last_update: '2026-11-16 18:29:12',
                    age_seconds: 259200
                };
                const status = getEfStatus(fan);
                evaluateAlarmTriggers([], [fan], [], true, true);

                const unackCount = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const lastMsg = window.__webviewMessages[window.__webviewMessages.length - 1];
                const hasTrigger = activeAlarmTriggerKeys.has('EFOFF:EF 58');
                const synthRows = syntheticLiveEfAlarms.filter(r => r.equipment === 'EF 58');

                return { status, unackCount, lastMsg, hasTrigger, synthRowsCount: synthRows.length };
            });

            const passed = res.status === 'unknown' && res.unackCount === 0 && !res.hasTrigger && res.synthRowsCount === 0 && res.lastMsg !== 'PLAY_ALARM';
            results.push({ name: '19. EF stale when OFF -> does not generate new synthetic alarm on fresh session', passed, details: res });
        }

        // 20. EF active alarm before becoming stale -> active alarm remains preserved (not cleared)
        {
            await resetState();
            const res = await page.evaluate(() => {
                allData.efServerTime = '2026-11-20 12:00:00';
                // Reading 1: Fresh telemetry, EF 58 is OFF (first reading)
                const freshFan1 = {
                    id: 58,
                    name: 'EF 58',
                    status: 0,
                    run: 0,
                    trip: 0,
                    last_update: '2026-11-20 11:59:45',
                    age_seconds: 15
                };
                evaluateAlarmTriggers([], [freshFan1], [], true, true);

                // Reading 2: Second reading confirms OFF (debounce >= 2 distinct readings) -> triggers alarm
                const freshFan2 = {
                    id: 58,
                    name: 'EF 58',
                    status: 0,
                    run: 0,
                    trip: 0,
                    last_update: '2026-11-20 11:59:50',
                    age_seconds: 10
                };
                evaluateAlarmTriggers([], [freshFan2], [], true, true);
                const tick1Trigger = activeAlarmTriggerKeys.has('EFOFF:EF 58');
                const tick1Unack = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;

                // Reading 3: Telemetry becomes stale (poller disconnects, age_seconds: 300)
                const staleFan = {
                    id: 58,
                    name: 'EF 58',
                    status: 0,
                    run: 0,
                    trip: 0,
                    last_update: '2026-11-20 11:59:50',
                    age_seconds: 300
                };
                evaluateAlarmTriggers([], [staleFan], [], true, true);
                const tick2Trigger = activeAlarmTriggerKeys.has('EFOFF:EF 58');
                const tick2Unack = Array.from(activeAlarmTriggerKeys).filter(k => !acknowledgedAlarmKeys.has(k)).length;
                const synthRows = syntheticLiveEfAlarms.filter(r => r.equipment === 'EF 58');

                return { tick1Trigger, tick1Unack, tick2Trigger, tick2Unack, synthRowsCount: synthRows.length };
            });

            const passed = res.tick1Trigger && res.tick1Unack === 1 && res.tick2Trigger && res.tick2Unack === 1 && res.synthRowsCount === 1;
            results.push({ name: '20. EF active alarm before becoming stale -> active alarm remains preserved (not cleared)', passed, details: res });
        }

        // 21. checkUnitIsDefinitivelyOff returns false (fail-safe sounds) when unit is stale
        {
            await resetState();
            const res = await page.evaluate(() => {
                allData.ahuServerTime = '2026-11-20 12:00:00';
                allData.efServerTime = '2026-11-20 12:00:00';
                lastAhuFetchTime = Date.now();
                lastHvacScFetchTime = Date.now();

                // 1. Fresh AHU OFF -> returns true (definitively off)
                allData.AHU = [{
                    name: 'AHU 01',
                    status: 0,
                    run: 0,
                    run_status: 0,
                    last_update: '2026-11-20 11:59:50',
                    age_seconds: 10
                }];
                const freshAhuOff = checkUnitIsDefinitivelyOff({ equip_type: 'AHU', equipment: 'AHU 01' });

                // 2. Stale AHU OFF -> fail-safe returns false (sounds)
                allData.AHU = [{
                    name: 'AHU 96',
                    status: 0,
                    run: 0,
                    run_status: 0,
                    last_update: '2026-11-16 18:29:12',
                    age_seconds: 259200
                }];
                const staleAhuOff = checkUnitIsDefinitivelyOff({ equip_type: 'AHU', equipment: 'AHU 96' });

                // 3. Fresh Chiller OFF -> returns true
                allData.CHILLER = [{
                    name: 'CHILLER 1',
                    run: 0,
                    last_update: '2026-11-20 11:59:50',
                    age_seconds: 10
                }];
                const freshChillerOff = checkUnitIsDefinitivelyOff({ equip_type: 'CHILLER', equipment: 'CHILLER 1' });

                // 4. Stale Chiller OFF -> fail-safe returns false (sounds)
                allData.CHILLER = [{
                    name: 'CHILLER 1',
                    run: 0,
                    last_update: '2026-11-16 18:29:12',
                    age_seconds: 259200
                }];
                const staleChillerOff = checkUnitIsDefinitivelyOff({ equip_type: 'CHILLER', equipment: 'CHILLER 1' });

                return { freshAhuOff, staleAhuOff, freshChillerOff, staleChillerOff };
            });

            const passed = res.freshAhuOff === true && res.staleAhuOff === false &&
                           res.freshChillerOff === true && res.staleChillerOff === false;
            results.push({ name: '21. checkUnitIsDefinitivelyOff returns false (fail-safe sounds) when unit is stale', passed, details: res });
        }

        await browser.close();

        console.log('\n================== REGRESSION TEST RESULTS ==================');
        let passCount = 0;
        let failCount = 0;
        for (const r of results) {
            if (r.passed) {
                passCount++;
                console.log(`[PASS] ${r.name}`);
            } else {
                failCount++;
                console.error(`[FAIL] ${r.name}`);
                console.error('       Details:', JSON.stringify(r.details));
            }
        }
        console.log('=============================================================');
        console.log(`Total: ${results.length} | Passed: ${passCount} | Failed: ${failCount}`);
        console.log('=============================================================\n');

        return failCount === 0;
    } catch (err) {
        if (browser) await browser.close();
        console.error('Test run failed with error:', err);
        return false;
    } finally {
        try { fs.unlinkSync(sessionFilePath); } catch (_) {}
    }
}

runRegressionSuite().then(ok => {
    process.exit(ok ? 0 : 1);
});
