#!/usr/bin/env node
/**
 * tools/tests/main_dashboard_states.js
 *
 * Automated regression test suite for Main Dashboard states:
 * State 1: All telemetry empty / failed (telemetry data unavailable, no fake "All normal", "-" everywhere)
 * State 2: Normal data (all systems loaded, valid zero displayed as 0, "All systems operational/normal")
 * State 3: Partial failure (one source failed, others loaded, partial data note appears, failed card shows "-")
 *
 * Runs headless Chromium across 1600px (Desktop) and 390px (Mobile).
 * Captures screenshots to C:\Temp\hvac_shots\ and verifies 0 console errors and 0 page errors.
 */

const fs = require('fs');
const path = require('path');

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

const shotDir = 'C:\\Temp\\hvac_shots';
if (!fs.existsSync(shotDir)) {
    fs.mkdirSync(shotDir, { recursive: true });
}

const sessionFilePath = 'C:\\xampp\\tmp\\sess_antigravitytestsession';
const sessionData = 'user_id|i:1;username|s:5:"admin";is_admin|i:1;';

async function runStatesSuite() {
    fs.writeFileSync(sessionFilePath, sessionData, 'utf8');

    const results = [];
    const errors = [];
    const launchOptions = { headless: true };
    if (executablePath) {
        launchOptions.executablePath = executablePath;
    }

    const browser = await pw.chromium.launch(launchOptions);

    try {
        const viewports = [
            { name: 'desktop', width: 1600, height: 900 },
            { name: 'mobile', width: 390, height: 844 }
        ];

        for (const vp of viewports) {
            const context = await browser.newContext({
                viewport: { width: vp.width, height: vp.height },
                userAgent: vp.name === 'mobile'
                    ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1'
                    : undefined
            });

            await context.addCookies([{
                name: 'PHPSESSID',
                value: 'antigravitytestsession',
                domain: 'localhost',
                path: '/'
            }]);

            const page = await context.newPage();

            // Prevent background fetch interference during state checks
            await page.route('**/api_*.php*', route => {
                route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, equipment: {} })
                });
            });

            page.on('console', msg => {
                if (msg.type() === 'error') {
                    errors.push(`[Console Error - ${vp.name}]: ${msg.text()}`);
                }
            });
            page.on('pageerror', err => {
                errors.push(`[Page Error - ${vp.name}]: ${err.message}`);
            });

            await page.goto('http://localhost:8080/hvac_monitoring/index.php', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(500);

            // ==========================================
            // STATE 1: ALL EMPTY / FAILED
            // ==========================================
            await page.evaluate(() => {
                currentEquipment = "MAIN";
                allData = {
                    sourceStatus: {
                        AHU: false, CHILLER: false, CT: false, CCP: false,
                        CHWP: false, EF: false, G8: false, ROOM_TEMP_RH: false,
                        ALARM: false, ENERGY_SUMMARY: false
                    },
                    AHU: [],
                    CHILLER: [],
                    CT: [],
                    CCP: [],
                    CHWP: [],
                    EXHAUST_FAN: [],
                    EXHAUST_FAN_G8: [],
                    ROOM_TEMP_RH: [],
                    ALARM: null,
                    ENERGY_SUMMARY: null
                };
                renderMainDashboard();
            });
            await page.waitForTimeout(300);

            const shot1Path = path.join(shotDir, `state1_empty_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot1Path, fullPage: true });

            const s1Check = await page.evaluate((isMobile) => {
                const banner = isMobile
                    ? document.querySelector('.mob-hero-banner')
                    : document.querySelector('.status-banner');
                const bannerTitle = banner ? banner.querySelector(isMobile ? '.mob-hero-title' : '.banner-title')?.textContent : '';
                const hasEmptyClass = banner ? banner.classList.contains('is-empty') : false;
                const hasNormalClass = banner ? (banner.classList.contains('is-norm') || banner.classList.contains('is-normal')) : false;

                const text = document.body.innerText;
                const dummyNumbers = ['8891', '8886', '8904', '8873', '8868', '8841', '8832', '8660', '8836', '118 / 120', '28.4 °C', '28.4', '22.8 °C', '30.8 °C'];
                const foundDummies = dummyNumbers.filter(num => text.includes(num));

                const energyEmptyMsg = isMobile
                    ? document.querySelector('.mob-energy-chart')?.textContent.includes('No energy history data available')
                    : document.querySelector('.daily-chart-wrap')?.textContent.includes('No energy history data available');

                return {
                    bannerTitle,
                    hasEmptyClass,
                    hasNormalClass,
                    foundDummies,
                    energyEmptyMsg
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 1 (${vp.name}): Banner shows Telemetry data unavailable`,
                pass: s1Check.bannerTitle.includes('Telemetry data unavailable') && s1Check.hasEmptyClass && !s1Check.hasNormalClass,
                details: { bannerTitle: s1Check.bannerTitle, hasEmptyClass: s1Check.hasEmptyClass }
            });
            results.push({
                name: `State 1 (${vp.name}): No dummy literals or false normal status`,
                pass: s1Check.foundDummies.length === 0 && s1Check.energyEmptyMsg,
                details: { foundDummies: s1Check.foundDummies, energyEmptyMsg: s1Check.energyEmptyMsg }
            });

            // ==========================================
            // STATE 2: NORMAL COMPLETE DATA
            // ==========================================
            await page.evaluate(() => {
                allData = {
                    sourceStatus: {
                        AHU: true, CHILLER: true, CT: true, CCP: true,
                        CHWP: true, EF: true, G8: true, ROOM_TEMP_RH: true,
                        ALARM: true, ENERGY_SUMMARY: true
                    },
                    AHU: [
                        { name: "AHU 01", temp: 21.5, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0 },
                        { name: "AHU 02", temp: 22.0, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0 }
                    ],
                    CHILLER: [
                        { name: "CHILLER 1", run: 1, alarm: 0, cond_entering_temp: 29.5, evap_leaving_temp: 7.2, evap_leaving_temp_max: 12, cond_entering_temp_max: 32 }
                    ],
                    CT: [
                        { name: "CT 1A", run: 1, alarm: 0, return_temp: 28.0, return_temp_max: 32.0 }
                    ],
                    CCP: [
                        { name: "CCP 1", run: 1, alarm: 0, frequency: 49.8, current: 16.5 }
                    ],
                    CHWP: [
                        { name: "CHWP 1", run: 1, alarm: 0, frequency: 49.2, current: 15.8 }
                    ],
                    EXHAUST_FAN: [
                        { name: "EF 01", run: 1, trip: 0 }
                    ],
                    EXHAUST_FAN_G8: [],
                    ROOM_TEMP_RH: [
                        { name: "Room 101", type: "ROOM", temp: 23.5, rh: 55, temp_max: 30, rh_max: 65 }
                    ],
                    ALARM: {
                        counts: {
                            active_alarm: 0,
                            active_warning: 0,
                            active_total: 0
                        },
                        total_events: 120,
                        alarms: []
                    },
                    ENERGY_SUMMARY: {
                        report_kwh: 8520,
                        prev_kwh: 8600,
                        report_date: '2026-11-17',
                        prev_date: '2026-11-16',
                        is_yesterday: true,
                        daily_history: [
                            { date: '2026-11-17', label: '17 Nov', short_day: 'Tue', kwh: 8520, is_latest: true }
                        ],
                        peak_day: { date: '2026-11-17', label: '17 Nov', kwh: 8520 },
                        avg_daily_kwh: 8520
                    }
                };
                renderMainDashboard();
            });
            await page.waitForTimeout(300);

            const shot2Path = path.join(shotDir, `state2_normal_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot2Path, fullPage: true });

            const s2Check = await page.evaluate((isMobile) => {
                const banner = isMobile
                    ? document.querySelector('.mob-hero-banner')
                    : document.querySelector('.status-banner');
                const bannerTitle = banner ? banner.querySelector(isMobile ? '.mob-hero-title' : '.banner-title')?.textContent : '';
                const hasNormClass = banner ? (banner.classList.contains('is-norm') || banner.classList.contains('is-normal')) : false;

                const critNum = isMobile
                    ? document.querySelector('.mob-kpi-card.is-crit .mob-kpi-num')?.textContent.trim()
                    : document.querySelector('.kpi.red .kpi-val')?.textContent.trim();

                const warnNum = isMobile
                    ? document.querySelector('.mob-kpi-card.is-warn .mob-kpi-num')?.textContent.trim()
                    : document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim();

                const bucketCheck = !isMobile ? (() => {
                    const crit = Number(document.querySelector('.kpi.red .kpi-val')?.textContent.trim());
                    const warn = Number(document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim());
                    const norm = Number(document.querySelectorAll('.kpi-row .kpi')[2]?.querySelector('.kpi-val')?.textContent.trim());
                    const stop = Number(document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim());
                    const total = Number(document.querySelectorAll('.kpi-row .kpi')[4]?.querySelector('.kpi-val')?.textContent.trim());
                    return { crit, warn, norm, stop, total, sumEqualsTotal: (crit + warn + norm + stop) === total };
                })() : null;

                return {
                    bannerTitle,
                    hasNormClass,
                    critNum,
                    warnNum,
                    bucketCheck
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 2 (${vp.name}): Banner shows operational / normal and valid zeros display as 0`,
                pass: (s2Check.bannerTitle.includes('All systems operational') || s2Check.bannerTitle.includes('All systems normal')) &&
                      s2Check.hasNormClass && s2Check.critNum === '0' && s2Check.warnNum === '0',
                details: s2Check
            });

            if (vp.name === 'desktop') {
                results.push({
                    name: `State 2 (${vp.name}): KPI bucket sum equals total units (critical + warning + normal + stopped === total)`,
                    pass: s2Check.bucketCheck && s2Check.bucketCheck.sumEqualsTotal,
                    details: s2Check.bucketCheck
                });
            }

            // ==========================================
            // STATE 2b: EF OFF WITHOUT PM (CRITICAL)
            // ==========================================
            const efOffNoPmCheck = await page.evaluate((isMobile) => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                allData.EXHAUST_FAN = [{ name: "EF 01", status: 0 }];
                renderMainDashboard();

                const banner = isMobile ? document.querySelector('.mob-hero-banner') : document.querySelector('.status-banner');
                const bannerTitle = banner ? banner.querySelector(isMobile ? '.mob-hero-title' : '.banner-title')?.textContent : '';
                const critNum = isMobile
                    ? document.querySelector('.mob-kpi-card.is-crit .mob-kpi-num')?.textContent.trim()
                    : document.querySelector('.kpi.red .kpi-val')?.textContent.trim();
                const attnText = isMobile
                    ? (document.querySelector('.mob-card-list')?.textContent || '')
                    : (document.querySelector('.attn-list')?.textContent || '');

                const bucketCheck = !isMobile ? (() => {
                    const crit = Number(document.querySelector('.kpi.red .kpi-val')?.textContent.trim());
                    const warn = Number(document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim());
                    const norm = Number(document.querySelectorAll('.kpi-row .kpi')[2]?.querySelector('.kpi-val')?.textContent.trim());
                    const stop = Number(document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim());
                    const total = Number(document.querySelectorAll('.kpi-row .kpi')[4]?.querySelector('.kpi-val')?.textContent.trim());
                    return { crit, warn, norm, stop, total, sumEqualsTotal: (crit + warn + norm + stop) === total };
                })() : null;

                return {
                    bannerTitle,
                    critNum,
                    inAttention: attnText.includes('EF 01'),
                    bucketCheck
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 2b (${vp.name}): EF OFF without PM raises critical, turns banner red, and enters Needs attention`,
                pass: efOffNoPmCheck.bannerTitle.includes('critical') && efOffNoPmCheck.critNum === '1' && efOffNoPmCheck.inAttention &&
                      (vp.name === 'mobile' || (efOffNoPmCheck.bucketCheck && efOffNoPmCheck.bucketCheck.sumEqualsTotal)),
                details: efOffNoPmCheck
            });

            // ==========================================
            // STATE 2c: EF OFF WITH ACTIVE PM (STOPPED/MAINTENANCE, NOT CRITICAL)
            // ==========================================
            const efOffWithPmCheck = await page.evaluate((isMobile) => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                maintenanceByUnit.set("EXHAUST_FAN:EF 01", {
                    equip_type: "EXHAUST_FAN",
                    unit_name: "EF 01",
                    active: 1,
                    is_active: 1,
                    remaining_seconds: 3600,
                    fetched_at: Date.now()
                });
                renderMainDashboard();

                const banner = isMobile ? document.querySelector('.mob-hero-banner') : document.querySelector('.status-banner');
                const bannerTitle = banner ? banner.querySelector(isMobile ? '.mob-hero-title' : '.banner-title')?.textContent : '';
                const critNum = isMobile
                    ? document.querySelector('.mob-kpi-card.is-crit .mob-kpi-num')?.textContent.trim()
                    : document.querySelector('.kpi.red .kpi-val')?.textContent.trim();
                const stopNum = isMobile
                    ? document.querySelectorAll('.mob-kpi-card')[2]?.querySelector('.mob-kpi-num')?.textContent.trim()
                    : document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim();
                const attnText = isMobile
                    ? (document.querySelector('.mob-card-list')?.textContent || '')
                    : (document.querySelector('.attn-list')?.textContent || '');

                const bucketCheck = !isMobile ? (() => {
                    const crit = Number(document.querySelector('.kpi.red .kpi-val')?.textContent.trim());
                    const warn = Number(document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim());
                    const norm = Number(document.querySelectorAll('.kpi-row .kpi')[2]?.querySelector('.kpi-val')?.textContent.trim());
                    const stop = Number(document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim());
                    const total = Number(document.querySelectorAll('.kpi-row .kpi')[4]?.querySelector('.kpi-val')?.textContent.trim());
                    return { crit, warn, norm, stop, total, sumEqualsTotal: (crit + warn + norm + stop) === total };
                })() : null;

                return {
                    bannerTitle,
                    critNum,
                    stopNum,
                    inAttention: attnText.includes('EF 01'),
                    bucketCheck
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 2c (${vp.name}): EF OFF with active PM counts as stopped/maintenance, not critical, and not in Needs attention`,
                pass: !efOffWithPmCheck.bannerTitle.includes('critical') && efOffWithPmCheck.critNum === '0' && efOffWithPmCheck.stopNum === '1' && !efOffWithPmCheck.inAttention &&
                      (vp.name === 'mobile' || (efOffWithPmCheck.bucketCheck && efOffWithPmCheck.bucketCheck.sumEqualsTotal)),
                details: efOffWithPmCheck
            });

            // ==========================================
            // STATE 2d: PM FAIL-SAFE (PM FETCH FAILED -> TREAT EF OFF AS CRITICAL)
            // ==========================================
            const efPmFailSafeCheck = await page.evaluate((isMobile) => {
                maintenanceFetchSuccess = false; // PM failed to load
                renderMainDashboard();

                const banner = isMobile ? document.querySelector('.mob-hero-banner') : document.querySelector('.status-banner');
                const bannerTitle = banner ? banner.querySelector(isMobile ? '.mob-hero-title' : '.banner-title')?.textContent : '';
                const critNum = isMobile
                    ? document.querySelector('.mob-kpi-card.is-crit .mob-kpi-num')?.textContent.trim()
                    : document.querySelector('.kpi.red .kpi-val')?.textContent.trim();
                const attnText = isMobile
                    ? (document.querySelector('.mob-card-list')?.textContent || '')
                    : (document.querySelector('.attn-list')?.textContent || '');

                return {
                    bannerTitle,
                    critNum,
                    inAttention: attnText.includes('EF 01')
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 2d (${vp.name}): PM fetch failure fail-safe treats EF OFF as critical`,
                pass: efPmFailSafeCheck.bannerTitle.includes('critical') && efPmFailSafeCheck.critNum === '1' && efPmFailSafeCheck.inAttention,
                details: efPmFailSafeCheck
            });

            // Reset EF to running for State 3
            await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                allData.EXHAUST_FAN = [{ name: "EF 01", status: 1 }];
            });

            // ==========================================
            // STATE 3: PARTIAL FAILURE (e.g. AHU offline)
            // ==========================================
            await page.evaluate(() => {
                allData.sourceStatus.AHU = false;
                allData.AHU = [];
                renderMainDashboard();
            });
            await page.waitForTimeout(300);

            const shot3Path = path.join(shotDir, `state3_partial_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot3Path, fullPage: true });

            const s3Check = await page.evaluate((isMobile) => {
                const banner = isMobile
                    ? document.querySelector('.mob-hero-banner')
                    : document.querySelector('.status-banner');
                const bannerSub = banner ? banner.querySelector(isMobile ? '.mob-hero-sub' : '.banner-sub')?.textContent : '';

                let ahuVal = '';
                if (isMobile) {
                    const cards = Array.from(document.querySelectorAll('.mob-sys-card'));
                    const ahuCard = cards.find(c => c.querySelector('.mob-sys-name')?.textContent === 'AHU');
                    ahuVal = ahuCard ? ahuCard.querySelector('.mob-sys-val')?.textContent.trim() : '';
                } else {
                    const cards = Array.from(document.querySelectorAll('.scard'));
                    const ahuCard = cards.find(c => c.querySelector('.scard-name')?.textContent.includes('Air handling'));
                    ahuVal = ahuCard ? ahuCard.querySelector('.scard-num')?.textContent.trim() : '';
                }

                return {
                    bannerSub,
                    ahuVal,
                    hasPartialNote: bannerSub.includes('partial data')
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 3 (${vp.name}): Banner shows partial data note and failed card shows "-"`,
                pass: s3Check.hasPartialNote && s3Check.ahuVal.includes('-'),
                details: s3Check
            });

            await context.close();
        }

        console.log('\n================== MAIN DASHBOARD STATES TEST RESULTS ==================');
        let passCount = 0;
        let failCount = 0;
        for (const r of results) {
            if (r.pass) {
                passCount++;
                console.log(`[PASS] ${r.name}`);
            } else {
                failCount++;
                console.error(`[FAIL] ${r.name}`);
                console.error('       Details:', JSON.stringify(r.details));
            }
        }
        if (errors.length > 0) {
            console.error('\nConsole / Page Errors detected during test:');
            errors.forEach(e => console.error('  ' + e));
        } else {
            console.log('[PASS] 0 page errors and 0 console errors detected across all viewports and states.');
        }

        console.log('========================================================================');
        console.log(`Total: ${results.length} | Passed: ${passCount} | Failed: ${failCount}`);
        console.log(`Screenshots saved to: ${shotDir}`);
        console.log('========================================================================\n');

        await browser.close();
        return failCount === 0 && errors.length === 0;
    } catch (err) {
        if (browser) await browser.close();
        console.error('Test run failed with error:', err);
        return false;
    } finally {
        try { fs.unlinkSync(sessionFilePath); } catch (_) {}
    }
}

runStatesSuite().then(ok => {
    process.exit(ok ? 0 : 1);
});
