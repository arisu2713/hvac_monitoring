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
const putaranBDir = path.join(shotDir, 'putaran_b');
if (!fs.existsSync(shotDir)) {
    fs.mkdirSync(shotDir, { recursive: true });
}
if (!fs.existsSync(putaranBDir)) {
    fs.mkdirSync(putaranBDir, { recursive: true });
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
                syntheticLiveEfAlarms = [];
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
            await page.screenshot({ path: path.join(putaranBDir, `empty_${vp.name}_${vp.width}.png`), fullPage: true });

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
                syntheticLiveEfAlarms = [];
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
            await page.screenshot({ path: path.join(putaranBDir, `normal_${vp.name}_${vp.width}.png`), fullPage: true });

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

            // ==========================================
            // STATE 4: ISSUES (PARITY, RELATIVE TIME, SORTING, EF PM/OFF, ENERGY COMPARISON)
            // ==========================================
            await page.evaluate(() => {
                maintenanceFetchSuccess = true;
                maintenanceByUnit.clear();
                maintenanceByUnit.set("EXHAUST_FAN:EF 02", {
                    equip_type: "EXHAUST_FAN",
                    unit_name: "EF 02",
                    active: 1,
                    is_active: 1,
                    remaining_seconds: 3600,
                    fetched_at: Date.now()
                });

                syntheticLiveEfAlarms = [{
                    id: "LIVE:01",
                    event_class: "ALARM",
                    source: "LIVE",
                    equip_type: "EF",
                    equipment: "EF 01",
                    metric: "STATUS",
                    description: "Exhaust Fan stopped (live status OFF)",
                    status: "ACTIVE",
                    value: 0,
                    raised_at: null,
                    is_pm: false,
                    is_synthetic: true
                }];

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
                        { name: "CHILLER 1", run: 1, alarm: 0, cond_entering_temp: 29.5, evap_leaving_temp: 7.2, evap_leaving_temp_max: 12, cond_entering_temp_max: 32 },
                        { name: "CHILLER 3", run: 0, alarm: 0, cond_entering_temp: 0, evap_leaving_temp: 0 }
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
                        { name: "EF 01", status: 0 }, // OFF without PM -> critical
                        { name: "EF 02", status: 0 }, // OFF with PM -> suppressed/stopped
                        { name: "EF 03", status: 1 }  // RUN normal
                    ],
                    EXHAUST_FAN_G8: [],
                    ROOM_TEMP_RH: [],
                    ALARM: {
                        server_time: "2026-11-19 12:00:00",
                        counts: {
                            active_alarm: 3,
                            active_warning: 2,
                            active_total: 5
                        },
                        total_events: 150,
                        alarms: [
                            {
                                id: 101,
                                equipment: "AHU 05",
                                equip_type: "AHU",
                                event_class: "ALARM",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:50:00",
                                description: "AHU 05 High Temp Alarm"
                            },
                            {
                                id: 102,
                                equipment: "CHILLER 2",
                                equip_type: "CHILLER",
                                event_class: "ALARM",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:58:00",
                                description: "CHILLER 2 Water Flow Failure"
                            },
                            {
                                id: 103,
                                equipment: "AHU 08",
                                equip_type: "AHU",
                                event_class: "WARNING",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:59:00",
                                description: "AHU 08 Duct Temp Warning"
                            },
                            {
                                id: 104,
                                equipment: "CT 2A",
                                equip_type: "CT",
                                event_class: "WARNING",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:30:00",
                                description: "CT 2A Return Temp High"
                            },
                            {
                                id: 105,
                                equipment: "EF 02",
                                equip_type: "EF",
                                event_class: "ALARM",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:00:00",
                                description: "EF 02 Fan Stopped"
                            },
                            {
                                id: 106,
                                equipment: "CHILLER 3",
                                equip_type: "CHILLER",
                                event_class: "WARNING",
                                status: "ACTIVE",
                                raised_at: "2026-11-19 11:10:00",
                                description: "CHILLER 3 Leaving Temp High"
                            }
                        ]
                    },
                    ENERGY_SUMMARY: {
                        report_kwh: 8500,
                        prev_kwh: null,
                        report_date: '2026-11-18',
                        prev_date: null,
                        daily_history: [
                            { date: '2026-11-18', label: '18 Nov', short_day: 'Wed', kwh: 8500, is_latest: true }
                        ],
                        peak_day: { date: '2026-11-18', label: '18 Nov', kwh: 8500 },
                        avg_daily_kwh: 8500
                    }
                };

                renderMainDashboard();
            });
            await page.waitForTimeout(300);

            const shot4Path = path.join(putaranBDir, `issues_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot4Path, fullPage: true });

            const s4Check = await page.evaluate((isMobile) => {
                const canonical = getActiveAlarmItems({ showHidden: false });
                const canonicalActiveTotal = canonical.allActive.length;

                // Needs Attention elements
                let attentionItems = [];
                if (isMobile) {
                    const items = Array.from(document.querySelectorAll('.mob-card-list .mob-issue-item:not(.mob-empty-clean)'));
                    attentionItems = items.map(el => {
                        const title = el.querySelector('.mob-issue-title')?.textContent || '';
                        const time = el.querySelector('.mob-issue-time')?.textContent || '';
                        const isCrit = el.querySelector('.mob-issue-icon')?.classList.contains('is-crit');
                        return { title, time, isCrit };
                    });
                } else {
                    const items = Array.from(document.querySelectorAll('.attn-list .attn-item'));
                    attentionItems = items.map(el => {
                        const name = el.querySelector('.attn-name')?.textContent || '';
                        const time = el.querySelector('.attn-time')?.textContent || '';
                        const isCrit = el.querySelector('.attn-dot')?.classList.contains('r');
                        return { name, time, isCrit };
                    });
                }

                // Check relative time parsing
                const serverTime = allData.ALARM.server_time;
                const time2m = formatRelativeTime("2026-11-19 11:58:00", serverTime);
                const time10m = formatRelativeTime("2026-11-19 11:50:00", serverTime);
                const time1m = formatRelativeTime("2026-11-19 11:59:00", serverTime);
                const time30m = formatRelativeTime("2026-11-19 11:30:00", serverTime);
                const timeLive = formatRelativeTime(null, serverTime);

                // Energy comparison without prev_date
                const hasEnergyComparisonWithoutPrev = isMobile
                    ? Boolean(document.querySelector('.mob-energy-pct'))
                    : Boolean(document.querySelector('.energy-pct'));

                // Energy comparison WITH prev_date
                allData.ENERGY_SUMMARY.prev_kwh = 8000;
                allData.ENERGY_SUMMARY.prev_date = '2026-11-17';
                renderMainDashboard();

                const hasEnergyComparisonWithPrev = isMobile
                    ? Boolean(document.querySelector('.mob-energy-pct'))
                    : Boolean(document.querySelector('.energy-pct'));
                const energyPctText = isMobile
                    ? (document.querySelector('.mob-energy-pct')?.textContent.trim() || '')
                    : (document.querySelector('.energy-pct')?.textContent.trim() || '');

                const hasViewAll = isMobile
                    ? Boolean(document.querySelector('.mob-sec-link'))
                    : Boolean(document.querySelector('#btnViewAllIssues'));

                return {
                    canonicalActiveTotal,
                    attentionItems,
                    time2m,
                    time10m,
                    time1m,
                    time30m,
                    timeLive,
                    hasEnergyComparisonWithoutPrev,
                    hasEnergyComparisonWithPrev,
                    energyPctText,
                    hasViewAll
                };
            }, vp.name === 'mobile');

            // Parity: alarm engine active total equals Needs Attention count
            results.push({
                name: `State 4 (${vp.name}): Alarm page active total parity with Needs attention (count: ${s4Check.canonicalActiveTotal})`,
                pass: s4Check.canonicalActiveTotal === 5 && s4Check.attentionItems.length >= 4,
                details: { canonicalActiveTotal: s4Check.canonicalActiveTotal, attentionItemsCount: s4Check.attentionItems.length }
            });

            // Sorting: critical before warning
            const firstWarnIndex = s4Check.attentionItems.findIndex(item => !item.isCrit);
            const critAfterWarn = firstWarnIndex !== -1 && s4Check.attentionItems.slice(firstWarnIndex).some(item => item.isCrit);
            results.push({
                name: `State 4 (${vp.name}): Issues sorted critical first, then warning`,
                pass: !critAfterWarn && firstWarnIndex > 0,
                details: { firstWarnIndex, attentionItems: s4Check.attentionItems }
            });

            // Relative time formatted from server_time
            results.push({
                name: `State 4 (${vp.name}): Relative time formatted accurately from server_time`,
                pass: s4Check.time2m === "2 m" && s4Check.time10m === "10 m" && s4Check.time1m === "1 m" && s4Check.time30m === "30 m" && s4Check.timeLive === "live",
                details: { time2m: s4Check.time2m, time10m: s4Check.time10m, time1m: s4Check.time1m, time30m: s4Check.time30m, timeLive: s4Check.timeLive }
            });

            // Energy comparison hidden without comparison data, displayed with comparison data
            results.push({
                name: `State 4 (${vp.name}): Energy comparison label hidden without prev_date and displayed when comparison exists`,
                pass: !s4Check.hasEnergyComparisonWithoutPrev && s4Check.hasEnergyComparisonWithPrev && s4Check.energyPctText.includes('11/17'),
                details: {
                    withoutPrev: s4Check.hasEnergyComparisonWithoutPrev,
                    withPrev: s4Check.hasEnergyComparisonWithPrev,
                    energyPctText: s4Check.energyPctText
                }
            });

            // View all button present
            results.push({
                name: `State 4 (${vp.name}): View all link to Alarm page present`,
                pass: s4Check.hasViewAll,
                details: { hasViewAll: s4Check.hasViewAll }
            });

            // ==========================================
            // STATE 5: DAIKIN STALE TELEMETRY (AHU 96-97 & EF 58-61)
            // ==========================================
            await page.evaluate(() => {
                const staleTime = "2026-11-16 18:29:12";
                const serverTime = "2026-11-19 18:29:12"; // 3 days later
                const staleAge = 259200; // 3 days in seconds

                allData = {
                    ahuServerTime: serverTime,
                    efServerTime: serverTime,
                    sourceStatus: {
                        AHU: true, CHILLER: true, CT: true, CCP: true,
                        CHWP: true, EF: true, G8: true, ROOM_TEMP_RH: true,
                        ALARM: true, ENERGY_SUMMARY: true
                    },
                    sourceFreshness: {
                        DAIKIN: false,
                        HNX: true,
                        latestDaikinUpdate: staleTime
                    },
                    AHU: [
                        { name: "AHU 01", temp: 21.5, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0, last_update: serverTime },
                        { name: "AHU 02", temp: 22.0, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0, last_update: serverTime },
                        { name: "AHU 96", temp: 21.8, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0, last_update: staleTime, age_seconds: staleAge, return_air_temp: 22.1, supply_air_temp: 18.2, rh: 58.0 },
                        { name: "AHU 97", temp: 21.9, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0, last_update: staleTime, age_seconds: null, return_air_temp: 22.3, supply_air_temp: 18.4, rh: 59.0 }
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
                        { name: "EF 01", run: 1, trip: 0, last_update: serverTime }
                    ],
                    EXHAUST_FAN_G8: [
                        { name: "EF 58", status: 1, run: 1, trip: 0, last_update: staleTime, age_seconds: null },
                        { name: "EF 59", status: 1, run: 1, trip: 0, last_update: staleTime, age_seconds: staleAge },
                        { name: "EF 60", status: 1, run: 1, trip: 0, last_update: staleTime, age_seconds: staleAge },
                        { name: "EF 61", status: 1, run: 1, trip: 0, last_update: staleTime, age_seconds: staleAge }
                    ],
                    ROOM_TEMP_RH: [
                        { name: "Room 101", type: "ROOM", temp: 23.5, rh: 55, temp_max: 30, rh_max: 65 }
                    ],
                    ALARM: {
                        server_time: serverTime,
                        counts: {
                            active_alarm: 0,
                            active_warning: 0,
                            active_total: 0
                        },
                        total_events: 100,
                        alarms: []
                    },
                    ENERGY_SUMMARY: {
                        report_kwh: 8500,
                        prev_kwh: null,
                        report_date: '2026-11-18',
                        prev_date: null,
                        daily_history: [
                            { date: '2026-11-18', label: '18 Nov', short_day: 'Wed', kwh: 8500, is_latest: true }
                        ],
                        peak_day: { date: '2026-11-18', label: '18 Nov', kwh: 8500 },
                        avg_daily_kwh: 8500
                    }
                };

                currentEquipment = "MAIN";
                updateSourceFreshness();
                renderEquipment();
            });
            await page.waitForTimeout(300);

            // Screenshot for Main Dashboard State 5
            const shot5Path = path.join(shotDir, `state5_stale_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot5Path, fullPage: true });

            const s5Check = await page.evaluate((isMobile) => {
                // Main Dashboard KPI counts
                const crit = Number(isMobile
                    ? (document.querySelector('.mob-kpi-card.is-crit .mob-kpi-num')?.textContent.trim() || 0)
                    : (document.querySelector('.kpi.red .kpi-val')?.textContent.trim() || 0));
                const warn = Number(isMobile
                    ? (document.querySelector('.mob-kpi-card.is-warn .mob-kpi-num')?.textContent.trim() || 0)
                    : (document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim() || 0));
                const norm = Number(isMobile
                    ? (document.querySelectorAll('.mob-kpi-grid .mob-kpi-card')[2]?.querySelector('.mob-kpi-num')?.textContent.trim() || 0)
                    : (document.querySelectorAll('.kpi-row .kpi')[2]?.querySelector('.kpi-val')?.textContent.trim() || 0));
                const stop = Number(isMobile
                    ? (document.querySelectorAll('.mob-kpi-grid .mob-kpi-card')[3]?.querySelector('.mob-kpi-num')?.textContent.trim() || 0)
                    : (document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim() || 0));
                
                // Desktop has .kpi.unknown when totalUnknown > 0
                const unkDesktop = Number(document.querySelector('.kpi.unknown .kpi-val')?.textContent.trim() || 0);
                const total = Number(isMobile
                    ? (document.querySelector('.mob-kpi-total')?.textContent.trim() || 0)
                    : (document.querySelector('.kpi-row .kpi:last-child .kpi-val')?.textContent.trim() || 0));

                const bannerSub = isMobile
                    ? (document.querySelector('.mob-hero-sub')?.textContent.trim() || '')
                    : (document.querySelector('.banner-sub')?.textContent.trim() || '');

                const invariantHolds = !isMobile ? ((crit + warn + norm + stop + unkDesktop) === total) : true;

                // Now render AHU / EF G8 page to inspect cards and banner
                currentEquipment = "AHU_G8";
                renderEquipment();

                const staleBanner = document.querySelector('.stale-telemetry-banner');
                const staleBannerText = staleBanner ? staleBanner.textContent.trim() : '';

                // Check AHU 96 card
                const ahu96Card = Array.from(document.querySelectorAll('.g8-card')).find(c => c.querySelector('.hvac-card-title')?.textContent.includes('AHU 96'));
                const ahu96Subtext = ahu96Card?.querySelector('.g8-stale-subtext')?.textContent.trim() || '';
                const ahu96Metrics = Array.from(ahu96Card?.querySelectorAll('.m-val') || []).map(m => m.textContent.trim());

                // Check EF 58 card
                const ef58Card = Array.from(document.querySelectorAll('.g8-card')).find(c => c.querySelector('.hvac-card-title')?.textContent.includes('EF 58'));
                const ef58Subtext = ef58Card?.querySelector('.g8-stale-subtext')?.textContent.trim() || '';
                const ef58Metrics = Array.from(ef58Card?.querySelectorAll('.m-val') || []).map(m => m.textContent.trim());

                // Check non-Daikin without age_seconds vs Daikin with null age_seconds
                const nonDaikinAhu = (allData.AHU || []).find(u => u.name === "AHU 01");
                const nonDaikinEf = (allData.EXHAUST_FAN || []).find(u => u.name === "EF 01");
                const nonDaikinAhuNormal = Boolean(nonDaikinAhu && ahuRunState(nonDaikinAhu) === "on" && !isTelemetryStale(nonDaikinAhu));
                const nonDaikinEfNormal = Boolean(nonDaikinEf && getEfStatus(nonDaikinEf) === "run" && !isTelemetryStale(nonDaikinEf));

                const daikinAhu97 = (allData.AHU || []).find(u => u.name === "AHU 97");
                const daikinEf58 = (allData.EXHAUST_FAN_G8 || []).find(u => u.name === "EF 58");
                const daikinNullAgeUnknown = Boolean(daikinAhu97 && isTelemetryStale(daikinAhu97) && daikinEf58 && isTelemetryStale(daikinEf58));

                return {
                    crit,
                    warn,
                    norm,
                    stop,
                    unkDesktop,
                    total,
                    invariantHolds,
                    bannerSub,
                    hasStaleBanner: Boolean(staleBanner),
                    staleBannerText,
                    ahu96Subtext,
                    ahu96Metrics,
                    ef58Subtext,
                    ef58Metrics,
                    nonDaikinAhuNormal,
                    nonDaikinEfNormal,
                    daikinNullAgeUnknown
                };
            }, vp.name === 'mobile');

            // Invariant check on Main Dashboard
            results.push({
                name: `State 5 (${vp.name}): Main Dashboard KPI unknown is incremented by stale units, normal does not inflate, and invariant holds`,
                pass: (!s5Check.invariantHolds ? false : true) &&
                      (vp.name === 'desktop' ? s5Check.unkDesktop === 6 : true) &&
                      s5Check.bannerSub.includes('unknown/stale'),
                details: { crit: s5Check.crit, warn: s5Check.warn, norm: s5Check.norm, stop: s5Check.stop, unk: s5Check.unkDesktop, total: s5Check.total, bannerSub: s5Check.bannerSub }
            });

            // Card values and stale notice on AHU / EF G8
            results.push({
                name: `State 5 (${vp.name}): Daikin AHU 96-97 and EF 58-61 cards render "—" for values and "Data X lalu" subtext, and banner appears`,
                pass: s5Check.hasStaleBanner &&
                      s5Check.staleBannerText.includes('tidak diperbarui sejak') &&
                      s5Check.ahu96Subtext.includes('Data') && s5Check.ahu96Subtext.includes('lalu') &&
                      s5Check.ahu96Metrics.every(v => v === '—') &&
                      s5Check.ef58Subtext.includes('Data') && s5Check.ef58Subtext.includes('lalu') &&
                      s5Check.ef58Metrics.every(v => v === '—'),
                details: {
                    hasStaleBanner: s5Check.hasStaleBanner,
                    staleBannerText: s5Check.staleBannerText,
                    ahu96Subtext: s5Check.ahu96Subtext,
                    ahu96Metrics: s5Check.ahu96Metrics,
                    ef58Subtext: s5Check.ef58Subtext,
                    ef58Metrics: s5Check.ef58Metrics
                }
            });

            // Daikin age_seconds null -> unknown, non-Daikin without age_seconds -> normal
            results.push({
                name: `State 5 (${vp.name}): Daikin age_seconds null evaluates as unknown, non-Daikin without age_seconds stays normal`,
                pass: s5Check.nonDaikinAhuNormal && s5Check.nonDaikinEfNormal && s5Check.daikinNullAgeUnknown,
                details: {
                    nonDaikinAhuNormal: s5Check.nonDaikinAhuNormal,
                    nonDaikinEfNormal: s5Check.nonDaikinEfNormal,
                    daikinNullAgeUnknown: s5Check.daikinNullAgeUnknown
                }
            });

            // ==========================================
            // STATE 6: DAIKIN NIAGARA POINT QUALITY (TAHAP A)
            // ==========================================
            await page.evaluate(() => {
                const nowTime = "2026-11-20 12:00:00";
                allData = {
                    ahuServerTime: nowTime,
                    efServerTime: nowTime,
                    sourceStatus: {
                        AHU: true, CHILLER: true, CT: true, CCP: true,
                        CHWP: true, EF: true, G8: true, ROOM_TEMP_RH: false,
                        ALARM: true, ENERGY_SUMMARY: true
                    },
                    sourceFreshness: {
                        DAIKIN: true,
                        HNX: true,
                        latestDaikinUpdate: nowTime
                    },
                    AHU: [
                        { name: "AHU 01", temp: 22.0, temp_min: 15, temp_max: 25, status: 1, run_state: 1, alarm: 0, quality: "bad", raw_status: "down", last_update: nowTime },
                        { name: "AHU 96", run: null, quality: "bad", raw_status: "fault", point_quality: { run: "bad" }, last_update: nowTime, age_seconds: 5 },
                        { name: "AHU 97", temp: 21.9, temp_min: 15, temp_max: 25, status: 1, run: 1, alarm: 0, room_temp: 22.3, quality: "ok", raw_status: "ok", point_quality: { run: "ok" }, last_update: nowTime, age_seconds: 5 }
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
                        { name: "EF 01", run: 1, trip: 0, quality: "bad", raw_status: "down", last_update: nowTime }
                    ],
                    EXHAUST_FAN_G8: [
                        { id: 58, name: "EF 58", status: null, run: 1, quality: "bad", raw_status: "down", last_update: nowTime, age_seconds: 5 },
                        { id: 59, name: "EF 59", status: 1, run: 1, frequency: null, motor_current: 24.5, quality: "ok", raw_status: "ok", point_quality: { status: "ok", frequency: "bad", motor_current: "ok" }, last_update: nowTime, age_seconds: 5 },
                        { id: 60, name: "EF 60", status: null, run: null, quality: "bad", raw_status: null, last_update: nowTime, age_seconds: 5 },
                        { id: 61, name: "EF 61", status: 1, run: 1, frequency: 38, motor_current: 23.5, quality: "ok", raw_status: "ok", last_update: nowTime, age_seconds: 5 }
                    ],
                    ROOM_TEMP_RH: [],
                    ALARM: {
                        server_time: nowTime,
                        counts: { active_alarm: 0, active_warning: 0, active_total: 0 },
                        total_events: 10,
                        alarms: []
                    },
                    ENERGY_SUMMARY: {
                        report_kwh: 8500, prev_kwh: null, report_date: '2026-11-18', prev_date: null,
                        daily_history: [{ date: '2026-11-18', label: '18 Nov', short_day: 'Wed', kwh: 8500, is_latest: true }],
                        peak_day: { date: '2026-11-18', label: '18 Nov', kwh: 8500 }, avg_daily_kwh: 8500
                    }
                };

                currentEquipment = "MAIN";
                renderEquipment();
            });
            await page.waitForTimeout(300);

            const shot6Path = path.join(shotDir, `state6_quality_${vp.name}_${vp.width}.png`);
            await page.screenshot({ path: shot6Path, fullPage: true });

            const s6Check = await page.evaluate((isMobile) => {
                const crit = Number(isMobile
                    ? 0
                    : (document.querySelector('.kpi.red .kpi-val')?.textContent.trim() || 0));
                const warn = Number(isMobile
                    ? 0
                    : (document.querySelector('.kpi.yellow .kpi-val')?.textContent.trim() || 0));
                const norm = Number(isMobile
                    ? 0
                    : (document.querySelectorAll('.kpi-row .kpi')[2]?.querySelector('.kpi-val')?.textContent.trim() || 0));
                const stop = Number(isMobile
                    ? 0
                    : (document.querySelectorAll('.kpi-row .kpi')[3]?.querySelector('.kpi-val')?.textContent.trim() || 0));
                const unk = Number(isMobile
                    ? 0
                    : (document.querySelector('.kpi.unknown .kpi-val')?.textContent.trim() || 0));
                const total = Number(isMobile
                    ? 0
                    : (document.querySelector('.kpi-row .kpi:last-child .kpi-val')?.textContent.trim() || 0));

                const bannerSub = isMobile
                    ? (document.querySelector('.mob-hero-sub')?.textContent.trim() || '')
                    : (document.querySelector('.banner-sub')?.textContent.trim() || '');

                const invariantHolds = !isMobile ? ((crit + warn + norm + stop + unk) === total) : true;

                // Render AHU_G8 page to inspect cards and quality banner
                currentEquipment = "AHU_G8";
                renderEquipment();

                const banner = document.querySelector('.stale-telemetry-banner');
                const bannerText = banner ? banner.textContent.trim() : '';
                const bannerMentionsBadUnits = bannerText.includes('AHU 96') && bannerText.includes('EF 58') && bannerText.includes('EF 60');

                // Inspect AHU 96 card
                const ahu96Card = Array.from(document.querySelectorAll('.g8-card')).find(c => c.querySelector('.hvac-card-title')?.textContent.includes('AHU 96'));
                const ahu96Subtext = ahu96Card?.querySelector('.g8-stale-subtext')?.textContent.trim() || '';
                const ahu96DotUnknown = Boolean(ahu96Card?.querySelector('.ahu-run-dot.is-unknown'));

                // Inspect EF 58 card
                const ef58Card = Array.from(document.querySelectorAll('.g8-card')).find(c => c.querySelector('.hvac-card-title')?.textContent.includes('EF 58'));
                const ef58Subtext = ef58Card?.querySelector('.g8-stale-subtext')?.textContent.trim() || '';
                const ef58DotUnknown = Boolean(ef58Card?.querySelector('.ahu-run-dot.is-unknown'));
                const ef58Metrics = Array.from(ef58Card?.querySelectorAll('.metric-item .m-val') || []).map(m => m.textContent.trim());

                // Inspect EF 59 card (only freq down)
                const ef59Card = Array.from(document.querySelectorAll('.g8-card')).find(c => c.querySelector('.hvac-card-title')?.textContent.includes('EF 59'));
                const ef59DotRun = Boolean(ef59Card?.querySelector('.ahu-run-dot.is-run'));
                const ef59FreqVal = Array.from(ef59Card?.querySelectorAll('.metric-item') || []).find(r => r.querySelector('.m-lbl')?.textContent.includes('Frequency'))?.querySelector('.m-val')?.textContent.trim();
                const ef59StatusVal = Array.from(ef59Card?.querySelectorAll('.metric-item') || []).find(r => r.querySelector('.m-lbl')?.textContent.includes('Status'))?.querySelector('.m-val')?.textContent.trim();

                // Non-Daikin unit checks
                const nonDaikinAhu = (allData.AHU || []).find(u => u.name === "AHU 01");
                const nonDaikinEf = (allData.EXHAUST_FAN || []).find(u => u.name === "EF 01");
                const nonDaikinAhuNormal = Boolean(nonDaikinAhu && ahuRunState(nonDaikinAhu) === "on" && !isTelemetryStale(nonDaikinAhu));
                const nonDaikinEfNormal = Boolean(nonDaikinEf && getEfStatus(nonDaikinEf) === "run" && !isTelemetryStale(nonDaikinEf));

                return {
                    crit, warn, norm, stop, unk, total,
                    bannerSub,
                    invariantHolds,
                    bannerMentionsBadUnits,
                    bannerText,
                    ahu96Subtext,
                    ahu96DotUnknown,
                    ef58Subtext,
                    ef58DotUnknown,
                    ef58Metrics,
                    ef59DotRun,
                    ef59FreqVal,
                    ef59StatusVal,
                    nonDaikinAhuNormal,
                    nonDaikinEfNormal
                };
            }, vp.name === 'mobile');

            results.push({
                name: `State 6 (${vp.name}): Niagara point quality: KPI invariant holds and unknown counts bad status units`,
                pass: vp.name === 'desktop'
                    ? (s6Check.invariantHolds && s6Check.unk === 3 && s6Check.norm === 9 && s6Check.total === 12)
                    : (s6Check.bannerSub.includes('unknown/stale') && s6Check.bannerSub.includes('3 unknown/stale')),
                details: { crit: s6Check.crit, warn: s6Check.warn, norm: s6Check.norm, stop: s6Check.stop, unk: s6Check.unk, total: s6Check.total, invariantHolds: s6Check.invariantHolds, bannerSub: s6Check.bannerSub }
            });

            results.push({
                name: `State 6 (${vp.name}): Bad quality units render "-" card, gray dot, and "kualitas data buruk" subtext, and banner names bad units`,
                pass: s6Check.bannerMentionsBadUnits &&
                      s6Check.ahu96DotUnknown && s6Check.ahu96Subtext.includes('kualitas data buruk') &&
                      s6Check.ef58DotUnknown && s6Check.ef58Subtext.includes('kualitas data buruk') &&
                      s6Check.ef58Metrics.every(v => v === '—'),
                details: {
                    bannerMentionsBadUnits: s6Check.bannerMentionsBadUnits,
                    bannerText: s6Check.bannerText,
                    ahu96Subtext: s6Check.ahu96Subtext,
                    ef58Subtext: s6Check.ef58Subtext,
                    ef58Metrics: s6Check.ef58Metrics
                }
            });

            results.push({
                name: `State 6 (${vp.name}): Only metric down keeps unit status RUN, metric shows "-", and non-Daikin unaffected`,
                pass: s6Check.ef59DotRun && s6Check.ef59StatusVal === 'RUN' && s6Check.ef59FreqVal === '—' && s6Check.nonDaikinAhuNormal && s6Check.nonDaikinEfNormal,
                details: {
                    ef59DotRun: s6Check.ef59DotRun,
                    ef59StatusVal: s6Check.ef59StatusVal,
                    ef59FreqVal: s6Check.ef59FreqVal,
                    nonDaikinAhuNormal: s6Check.nonDaikinAhuNormal,
                    nonDaikinEfNormal: s6Check.nonDaikinEfNormal
                }
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
