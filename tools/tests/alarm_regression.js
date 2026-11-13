#!/usr/bin/env node
/**
 * tools/tests/alarm_regression.js
 *
 * Automated regression test suite for Alarm & Warning system (Phase 2 safety fixes).
 * Uses headless Chromium (via pre-installed Playwright) with in-memory page mocks.
 * Zero database writes.
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
                initialAlarmEvaluationDone = true;
                pendingEarlyAckUntil = 0;
            });
        };

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
                // Set active unacknowledged alarm
                activeAlarmTriggerKeys.add('ALARM:555');
                updateGlobalAlarmWidget();
                const msgBeforePagehide = window.__webviewMessages[window.__webviewMessages.length - 1];

                // Dispatch pagehide
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

                // Wrapper sends early ACK before first eval
                window.chrome.webview._dispatchMessage('ACK_FROM_C#');
                const pendingRecorded = pendingEarlyAckUntil > 0;

                // First eval runs with active alarm
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

                // ACK arrives when no alarms active
                window.chrome.webview._dispatchMessage('ACK_FROM_C#');
                const pendingHeld = pendingEarlyAckUntil > 0;

                // 5 seconds later, new alarm arrives
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

                // Wrapper sends early ACK
                window.chrome.webview._dispatchMessage('ACK_FROM_C#');

                // Simulate 16 seconds pass (expired)
                pendingEarlyAckUntil = Date.now() - 1000;

                // First eval runs now
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
