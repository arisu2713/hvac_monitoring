<?php

require_once __DIR__ . '/auth.php';
require_login();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HVAC Monitoring</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="ef.css?v=<?= filemtime(__DIR__ . '/ef.css') ?>">
</head>

<body>

<header class="topbar">
    <div class="brand">
        <div class="title">HVAC MONITORING</div>
        <div class="subtitle">Building HVAC Status</div>
    </div>

    <div class="connection">
        <span id="connectionDot" class="connection-dot offline"></span>
        <span id="connectionText">OFFLINE</span>
        <span id="lastUpdate" class="last-update">DB update: --</span>
    </div>
</header>

<nav id="equipmentNav" class="equipment-nav">
    <button class="nav-button active" data-equipment="AHU">AHU</button>
    <button class="nav-button" data-equipment="HVAC_SC">HVAC SC</button>
    <button class="nav-button" data-equipment="ROOM_TEMP_RH">ROOM TEMP & RH</button>
    <button class="nav-button" data-equipment="EXHAUST_FAN">EXHAUST FAN</button>
    <button class="nav-button" data-equipment="AHU_G8">AHU G8</button>
    <button class="nav-button" data-equipment="ALARM">ALARM</button>
    <button class="nav-button" data-equipment="ENERGY">ENERGY</button>
</nav>

<main>
    <div class="section-header">
        <div id="sectionTitle">AHU</div>
        <div id="unitCount">0 units</div>
    </div>

    <div id="pageSwitch" class="page-switch"></div>

    <div id="pageFilters" class="page-filters"></div>

    <div id="equipmentGrid" class="equipment-grid"></div>
</main>

<footer>
    <span>HVAC Monitoring</span>
    
</footer>

<script>
const API_URL = "api.php";

let allData = {};
let currentEquipment = "AHU";

const grid = document.getElementById("equipmentGrid");
const sectionTitle = document.getElementById("sectionTitle");
const unitCount = document.getElementById("unitCount");
const connectionText = document.getElementById("connectionText");
const connectionDot = document.getElementById("connectionDot");
const lastUpdate = document.getElementById("lastUpdate");
const pageFilters = document.getElementById("pageFilters");
const pageSwitch = document.getElementById("pageSwitch");

function setConnection(online) {
    connectionText.textContent = online ? "ONLINE" : "OFFLINE";

    connectionDot.classList.remove("online", "offline");
    connectionDot.classList.add(online ? "online" : "offline");
}

function getStatus(unit) {
    const alarm = unit.alarm === null || unit.alarm === undefined ? null : Number(unit.alarm);
    const run = unit.run === null || unit.run === undefined ? null : Number(unit.run);

    if (alarm === 1) {
        return "alarm";
    }

    if (run === 1) {
        return "run";
    }

    if (run === 0) {
        return "off";
    }

    return "unknown";
}

/*
 * Alarm bounds come from the API as *_max / *_min fields (null when the
 * unit has no threshold configured). Only flag a value that is actually
 * present and numerically outside its bounds — a missing reading is not
 * an alarm.
 */
function isOutOfRange(value, min, max) {
    if (value === null || value === undefined || value === "") {
        return false;
    }

    const n = Number(value);

    if (!Number.isFinite(n)) {
        return false;
    }

    const hasMin = min !== null && min !== undefined && Number.isFinite(Number(min));
    const hasMax = max !== null && max !== undefined && Number.isFinite(Number(max));

    return (hasMin && n < Number(min)) || (hasMax && n > Number(max));
}

/*
 * Appends the blinking alarm class to a value element when the reading is
 * outside its bounds. Safe to call with missing limits (null) — then it
 * never alarms.
 */
function applyAlarmClass(element, value, min, max) {
    if (isOutOfRange(value, min, max)) {
        element.classList.add("value-alarm");
    }
}

function getEfStatus(unit) {
    const status = unit.status === null || unit.status === undefined
        ? null
        : Number(unit.status);

    if (status === 1) {
        return "run";
    }

    if (status === 0) {
        return "off";
    }

    return "unknown";
}

function createEfCard(unit) {
    const card = document.createElement("div");

    const status = getEfStatus(unit);
    card.className = "unit-card ef-card status-" + status;
    card.dataset.status = status;

    const name = document.createElement("div");
    name.className = "ef-name";
    name.textContent = unit.name ?? "";
    card.appendChild(name);

    return card;
}
function createCard(unit) {
    const card = document.createElement("div");

    const status = getStatus(unit);

    card.className = "unit-card status-" + status;
    card.dataset.status = status;

    const name = document.createElement("div");
    name.className = "unit-name";
    name.textContent = unit.name ?? "";
    card.appendChild(name);

    /*
     * Status-only units (Daikin AHU 96-97) carry a run status but no value on
     * this page: their RoomTemp1 / SensorHumidityROOM are shown on the
     * ROOM TEMP & RH page instead. The card is therefore just name + colour,
     * with no value line at all.
     */
    if (unit.status_only === true) {
        return card;
    }

    const temp = document.createElement("div");
    temp.className = "unit-value";

    if (unit.temp !== null && unit.temp !== undefined) {
        temp.textContent = Number(unit.temp).toFixed(1) + " °C";
    } else {
        temp.textContent = "-- °C";
    }

    applyAlarmClass(temp, unit.temp, null, unit.temp_max);

    card.appendChild(temp);

    return card;
}

function createAhuPlaceholderCard(number) {
    const card = document.createElement("div");
    card.className = "unit-card status-unknown";
    card.dataset.status = "unknown";

    const name = document.createElement("div");
    name.className = "unit-name";
    name.textContent = "AHU " + number;
    card.appendChild(name);

    const temp = document.createElement("div");
    temp.className = "unit-value";
    temp.textContent = "-- °C";
    card.appendChild(temp);

    const rh = document.createElement("div");
    rh.className = "unit-value";
    rh.textContent = "-- % RH";
    card.appendChild(rh);

    return card;
}

function createChillerCard(unit) {
    const card = document.createElement("div");
    const status = getStatus(unit);
    card.className = "unit-card hvac-card chiller-card status-" + status;
    card.dataset.status = status;

    const header = document.createElement("div");
    header.className = "hvac-card-header";

    const title = document.createElement("span");
    title.className = "hvac-card-title";
    title.textContent = "Chiller " + unit.name;

    header.appendChild(title);
    card.appendChild(header);

    const metrics = document.createElement("div");
    metrics.className = "chiller-metrics";

    const formatValue = (value, suffix) => {
        if (value === null || value === undefined || value === "") {
            return "--" + suffix;
        }

        const n = Number(value);

        return Number.isFinite(n)
            ? n.toFixed(1) + suffix
            : String(value) + suffix;
    };

    const fields = [
        { label: "SP:", val: unit.setpoint,            suffix: " °C" },
        { label: "RLA:", val: unit.rla,                suffix: " %" },
        { label: "EL:", val: unit.evap_leaving_temp,  suffix: " °C", max: unit.evap_leaving_temp_max },
        { label: "EE:", val: unit.evap_entering_temp, suffix: " °C" },
        { label: "CE:", val: unit.cond_entering_temp, suffix: " °C", max: unit.cond_entering_temp_max },
        { label: "CL:", val: unit.cond_leaving_temp,  suffix: " °C" }
    ];

    fields.forEach(f => {
        const item = document.createElement("div");
        item.className = "metric-item";

        item.innerHTML =
            `<span class="m-lbl">${f.label}</span>` +
            `<span class="m-val">${formatValue(f.val, f.suffix)}</span>`;

        applyAlarmClass(item.querySelector(".m-val"), f.val, f.min, f.max);

        metrics.appendChild(item);
    });

    card.appendChild(metrics);

    return card;
}

function createCompactHvacCard(prefix, unit) {
    const card = document.createElement("div");
    const status = getStatus(unit);
    card.className = "unit-card hvac-card compact-card status-" + status;
    card.dataset.status = status;

    const header = document.createElement("div");
    header.className = "hvac-card-header";

    const title = document.createElement("span");
    title.className = "hvac-card-title";
    title.textContent = prefix + " " + unit.name;

    header.appendChild(title);
    card.appendChild(header);

    const metrics = document.createElement("div");
    metrics.className = "compact-metrics";

    const formatValue = (value, suffix) => {
        if (value === null || value === undefined || value === "") {
            return "--" + suffix;
        }

        const n = Number(value);

        return Number.isFinite(n)
            ? n.toFixed(1) + suffix
            : String(value) + suffix;
    };

    const fields = [
        { label: "Freq:", val: unit.frequency, suffix: " Hz" },
        { label: "Curr:", val: unit.current,   suffix: " A", min: unit.current_min, max: unit.current_max },
        { label: "Drv Tmp:", val: unit.drive_temperature, suffix: " °C" }
    ];

    fields.forEach(f => {
        const item = document.createElement("div");
        item.className = "metric-item";

        item.innerHTML =
            `<span class="m-lbl">${f.label}</span>` +
            `<span class="m-val">${formatValue(f.val, f.suffix)}</span>`;

        applyAlarmClass(item.querySelector(".m-val"), f.val, f.min, f.max);

        metrics.appendChild(item);
    });

    card.appendChild(metrics);

    return card;
}

function renderHvacSc() {
    sectionTitle.textContent = "HVAC SC";

    const chiller = Array.isArray(allData.CHILLER) ? allData.CHILLER : [];
    const ccp = Array.isArray(allData.CCP) ? allData.CCP : [];
    const chwp = Array.isArray(allData.CHWP) ? allData.CHWP : [];
    const ct = Array.isArray(allData.CT) ? allData.CT : [];

    unitCount.textContent =
        (chiller.length + ccp.length + chwp.length + ct.length) + " units";

    const container = document.createElement("div");
    container.className = "hvac-sc-container";


    // =============================================================
    // GROUP HVAC G1-G2
    // =============================================================

    const g12Group = document.createElement("section");
    g12Group.className = "hvac-group-card";

    g12Group.innerHTML = `
        <div class="hvac-group-header">
            <span class="hvac-group-title">HVAC G1-G2</span>
            <span class="hvac-group-count">27 units</span>
        </div>
    `;

    const g12Matrix = document.createElement("div");
    g12Matrix.className = "hvac-matrix";


    // CHILLER 1-5
    const ch15Col = document.createElement("div");
    ch15Col.className = "hvac-col";
    ch15Col.innerHTML =
        `<div class="hvac-col-header">CHILLER (1-5)</div>`;

    const ch15Items = document.createElement("div");
    ch15Items.className = "hvac-col-items";

    [1, 2, 3, 4, 5].forEach(num => {
        const unit = chiller.find(x => String(x.name) === String(num));

        if (unit) {
            ch15Items.appendChild(createChillerCard(unit));
        }
    });

    ch15Col.appendChild(ch15Items);
    g12Matrix.appendChild(ch15Col);


    // CCP 1-5
    const ccp15Col = document.createElement("div");
    ccp15Col.className = "hvac-col";
    ccp15Col.innerHTML =
        `<div class="hvac-col-header">CCP (1-5)</div>`;

    const ccp15Items = document.createElement("div");
    ccp15Items.className = "hvac-col-items";

    [1, 2, 3, 4, 5].forEach(num => {
        const unit = ccp.find(x => String(x.name) === String(num));

        if (unit) {
            ccp15Items.appendChild(
                createCompactHvacCard("CCP", unit)
            );
        }
    });

    ccp15Col.appendChild(ccp15Items);
    g12Matrix.appendChild(ccp15Col);


    // CHWP 1-5
    const chwp15Col = document.createElement("div");
    chwp15Col.className = "hvac-col";
    chwp15Col.innerHTML =
        `<div class="hvac-col-header">CHWP (1-5)</div>`;

    const chwp15Items = document.createElement("div");
    chwp15Items.className = "hvac-col-items";

    [1, 2, 3, 4, 5].forEach(num => {
        const unit = chwp.find(x => String(x.name) === String(num));

        if (unit) {
            chwp15Items.appendChild(
                createCompactHvacCard("CHWP", unit)
            );
        }
    });

    chwp15Col.appendChild(chwp15Items);
    g12Matrix.appendChild(chwp15Col);


    // CT 1A-6B
    const ct16Col = document.createElement("div");
    ct16Col.className = "hvac-col";
    ct16Col.innerHTML =
        `<div class="hvac-col-header">COOLING TOWER (1A-6B)</div>`;

    const ct16Items = document.createElement("div");
    ct16Items.className = "hvac-col-items";

    [
        ["1A", "1B"],
        ["2A", "2B"],
        ["3A", "3B"],
        ["4A", "4B"],
        ["5A", "5B"],
        ["6A", "6B"]
    ].forEach(pair => {
        const pairRow = document.createElement("div");
        pairRow.className = "ct-pair-row";

        pair.forEach(name => {
            const unit = ct.find(
                x => String(x.name).toUpperCase() === name
            );

            if (unit) {
                pairRow.appendChild(
                    createCompactHvacCard("CT", unit)
                );
            }
        });

        ct16Items.appendChild(pairRow);
    });

    ct16Col.appendChild(ct16Items);
    g12Matrix.appendChild(ct16Col);

    g12Group.appendChild(g12Matrix);
    container.appendChild(g12Group);


    // =============================================================
    // GROUP HVAC G3
    // =============================================================

    const g3Group = document.createElement("section");
    g3Group.className = "hvac-group-card";

    g3Group.innerHTML = `
        <div class="hvac-group-header">
            <span class="hvac-group-title">HVAC G3</span>
            <span class="hvac-group-count">22 units</span>
        </div>
    `;

    const g3Matrix = document.createElement("div");
    g3Matrix.className = "hvac-matrix";


    // CHILLER 6-9
    const ch69Col = document.createElement("div");
    ch69Col.className = "hvac-col";
    ch69Col.innerHTML =
        `<div class="hvac-col-header">CHILLER (6-9)</div>`;

    const ch69Items = document.createElement("div");
    ch69Items.className = "hvac-col-items";

    [6, 7, 8, 9].forEach(num => {
        const unit = chiller.find(x => String(x.name) === String(num));

        if (unit) {
            ch69Items.appendChild(createChillerCard(unit));
        }
    });

    ch69Col.appendChild(ch69Items);
    g3Matrix.appendChild(ch69Col);


    // CCP 6-9
    const ccp69Col = document.createElement("div");
    ccp69Col.className = "hvac-col";
    ccp69Col.innerHTML =
        `<div class="hvac-col-header">CCP (6-9)</div>`;

    const ccp69Items = document.createElement("div");
    ccp69Items.className = "hvac-col-items";

    [6, 7, 8, 9].forEach(num => {
        const unit = ccp.find(x => String(x.name) === String(num));

        if (unit) {
            ccp69Items.appendChild(
                createCompactHvacCard("CCP", unit)
            );
        }
    });

    ccp69Col.appendChild(ccp69Items);
    g3Matrix.appendChild(ccp69Col);


    // CHWP 6-9
    const chwp69Col = document.createElement("div");
    chwp69Col.className = "hvac-col";
    chwp69Col.innerHTML =
        `<div class="hvac-col-header">CHWP (6-9)</div>`;

    const chwp69Items = document.createElement("div");
    chwp69Items.className = "hvac-col-items";

    [6, 7, 8, 9].forEach(num => {
        const unit = chwp.find(x => String(x.name) === String(num));

        if (unit) {
            chwp69Items.appendChild(
                createCompactHvacCard("CHWP", unit)
            );
        }
    });

    chwp69Col.appendChild(chwp69Items);
    g3Matrix.appendChild(chwp69Col);


    // CT 9A-13B
    const ct913Col = document.createElement("div");
    ct913Col.className = "hvac-col";
    ct913Col.innerHTML =
        `<div class="hvac-col-header">COOLING TOWER (9A-13B)</div>`;

    const ct913Items = document.createElement("div");
    ct913Items.className = "hvac-col-items";

    [
        ["9A", "9B"],
        ["10A", "10B"],
        ["11A", "11B"],
        ["12A", "12B"],
        ["13A", "13B"]
    ].forEach(pair => {
        const pairRow = document.createElement("div");
        pairRow.className = "ct-pair-row";

        pair.forEach(name => {
            const unit = ct.find(
                x => String(x.name).toUpperCase() === name
            );

            if (unit) {
                pairRow.appendChild(
                    createCompactHvacCard("CT", unit)
                );
            }
        });

        ct913Items.appendChild(pairRow);
    });

    ct913Col.appendChild(ct913Items);
    g3Matrix.appendChild(ct913Col);

    g3Group.appendChild(g3Matrix);
    container.appendChild(g3Group);

    grid.appendChild(container);
}


function renderExhaustFan() {
    const data = allData.EXHAUST_FAN;

    sectionTitle.textContent = "EXHAUST FAN";
    grid.classList.remove("hvac-view");

    if (!Array.isArray(data)) {
        unitCount.textContent = "0 units";
        return;
    }

    const g8Count = Array.isArray(allData.EXHAUST_FAN_G8)
        ? allData.EXHAUST_FAN_G8.length
        : 0;

    unitCount.textContent = (data.length + g8Count) + " units";
    const panels = {};

    data.forEach(unit => {
        const panelNo = Number(unit.panel_no);

        if (!panels[panelNo]) {
            panels[panelNo] = [];
        }

        panels[panelNo].push(unit);
    });

    Object.keys(panels)
        .sort((a, b) => Number(a) - Number(b))
        .forEach(panelNo => {
            const panel = document.createElement("section");
            panel.className = "ef-panel";

            const header = document.createElement("div");
            header.className = "ef-panel-header";
            header.textContent = "Panel " + panelNo;
            panel.appendChild(header);

            const panelGrid = document.createElement("div");
            panelGrid.className = "ef-grid";

            panels[panelNo].forEach(unit => {
                panelGrid.appendChild(createEfCard(unit));
            });

            panel.appendChild(panelGrid);
            grid.appendChild(panel);
        });

    /*
     * Exhaust Fan G8 — EF 58-61.
     *
     * A separate groupbox AFTER Panel 1-9. These fans come from a different
     * source (the Daikin poller, via the EXHAUST_FAN_G8 key) and must never be
     * merged into the panel grouping above.
     *
     * The header is intentionally not "Panel N" — there is no panel number for
     * these fans. Cards reuse createEfCard(), so the visual design (and the
     * ON = green / OFF = red / unknown = gray rules, with no ON/OFF text) is
     * exactly the same as Panel 1-9.
     */
    const g8Data = allData.EXHAUST_FAN_G8;

    if (Array.isArray(g8Data) && g8Data.length > 0) {
        const g8Panel = document.createElement("section");
        g8Panel.className = "ef-panel ef-panel-g8";

        const g8Header = document.createElement("div");
        g8Header.className = "ef-panel-header";
        g8Header.textContent = "Exhaust Fan G8";
        g8Panel.appendChild(g8Header);

        const g8Grid = document.createElement("div");
        g8Grid.className = "ef-grid";

        g8Data.forEach(unit => {
            g8Grid.appendChild(createEfCard(unit));
        });

        g8Panel.appendChild(g8Grid);
        grid.appendChild(g8Panel);
    }

    fitExhaustFanToViewport();
}

/*
 * Keeps the G8 cards exactly as wide as a Panel 1-9 card.
 *
 * The G8 band spans the full width of the grid, so with `1fr` columns its 4
 * cards would stretch to ~460px — 2.5x the panel cards and visibly oversized.
 * The panel card width is measured from the DOM instead of assumed, because
 * it depends on the viewport, the grid's column count and the gap. --ef-card-w
 * then sizes the G8 tracks (see ef.css).
 *
 * Falls back to leaving the CSS default in place when no panel card exists yet.
 */
function syncG8CardWidth() {
    const panelCard = grid.querySelector(".ef-panel:not(.ef-panel-g8) .unit-card.ef-card");

    if (!panelCard) {
        grid.style.removeProperty("--ef-card-w");
        return;
    }

    const w = panelCard.getBoundingClientRect().width;

    if (w > 0) {
        grid.style.setProperty("--ef-card-w", Math.round(w) + "px");
    }
}

/*
 * Fits the whole EXHAUST FAN page into the real viewport, with no scrolling.
 *
 * Requirement: 9 panels + the G8 band (59 fans) must all be visible on a
 * 1920x1080 desktop page, without shrinking the cards further than necessary.
 * A hard-coded card height cannot guarantee either half of that: the actual
 * CSS viewport is shorter than the physical 1080px once browser chrome is
 * subtracted, and it varies per machine.
 *
 * Rather than model the layout in arithmetic (panel padding + header + gaps +
 * rows per panel), this sets --ef-card-h and MEASURES the resulting grid
 * height, then binary-searches for the largest height that still fits. It
 * therefore cannot be wrong about the layout: whatever the panel padding,
 * gaps, wrapped rows or font metrics turn out to be, the measured height is
 * the real one.
 *
 * The search shrinks the cards only as far as the viewport actually requires,
 * and stops at a readable floor. If even the floor does not fit, the floor
 * wins and the page is allowed to overflow rather than become unreadable.
 *
 * Desktop keeps its overflow-y:hidden from style.css; mobile and tablet are
 * allowed to scroll and are left on the CSS defaults.
 */
function fitExhaustFanToViewport() {
    const MIN_CARD_H = 40;   // readable floor; never go below this
    const MAX_CARD_H = 90;   // never grow past the design's own card size
    const MIN_FIT_W = 1101;  // below this the grid drops to 2 columns

    /*
     * Only fit where the EF grid is still multi-column (laptop and up). Below
     * 1101px the responsive rules switch it to 2 columns and the panels stack
     * far too tall for a fit to be sensible, so the page is allowed to scroll
     * there — that covers tablet portrait and phones.
     */
    if (window.innerWidth < MIN_FIT_W) {
        grid.style.removeProperty("--ef-card-h");
        grid.style.removeProperty("gap");
        grid.style.removeProperty("padding-bottom");
        return;
    }

    if (!grid.querySelector(".ef-panel")) {
        grid.style.removeProperty("--ef-card-h");
        grid.style.removeProperty("gap");
        grid.style.removeProperty("padding-bottom");
        return;
    }

    // Start from the stylesheet's own spacing so a previous fit — possibly for
    // a much shorter viewport — cannot leave its tightened values behind.
    grid.style.removeProperty("gap");
    grid.style.removeProperty("padding-bottom");

    const footer = document.querySelector("footer");
    const footerH = footer ? footer.getBoundingClientRect().height : 0;

    // Space the grid may occupy inside the viewport, in document coordinates.
    const limit = window.innerHeight - footerH;

    const apply = h => {
        grid.style.setProperty("--ef-card-h", h + "px");
        syncG8CardWidth();
        // Read the real height back after the browser has laid it out. The
        // bounding rect is the border box, so the grid's own padding is
        // already included in what has to fit.
        return grid.getBoundingClientRect().bottom;
    };

    // Does the grid fit within the viewport at this card height?
    const fits = h => apply(h) <= limit;

    /*
     * Spend the gaps before the cards.
     *
     * The space BETWEEN panels costs nothing in readability, so on a short
     * viewport it is tightened first. Only when the tightest gap still leaves
     * the cards above the readable floor are the cards themselves shrunk.
     * This is what lets a 768px-tall laptop fit without unreadable cards.
     */
    for (const gap of [12, 6, 3]) {
        grid.style.gap = gap + "px";

        if (!fits(MIN_CARD_H)) {
            continue;   // still too tall even at the readable floor
        }

        if (fits(MAX_CARD_H)) {
            // Everything fits at full size — do not shrink at all.
            apply(MAX_CARD_H);
            return;
        }

        // Largest height in [MIN_CARD_H, MAX_CARD_H] whose grid still fits.
        let lo = MIN_CARD_H;
        let hi = MAX_CARD_H;

        while (hi - lo > 1) {
            const mid = Math.floor((lo + hi) / 2);
            if (fits(mid)) {
                lo = mid;
            } else {
                hi = mid;
            }
        }

        apply(lo);
        return;
    }

    /*
     * The floor plus the tightest gap still overflows — the remaining slack is
     * the grid's own bottom padding. Reclaim it before touching the cards
     * again, since trailing whitespace costs nothing either.
     */
    grid.style.gap = "3px";
    grid.style.paddingBottom = "6px";

    if (fits(MIN_CARD_H)) {
        apply(MIN_CARD_H);
        return;
    }

    /*
     * Genuinely out of room. Keep the readable floor rather than shrink the
     * cards further: the requirement is explicit that they must not be made
     * too small just to fit. The viewport is simply too short for 59 fans.
     */
    apply(MIN_CARD_H);
}
function createRoomCard(unit, index) {
    const card = document.createElement("div");
    card.id = "roomcard" + (index + 1);
    card.className = "unit-card room-card" + (unit.type === "OUTDOOR" ? " room-outdoor" : "");

    const name = document.createElement("div");
    name.className = "unit-name";

    const prefix = unit.type === "OUTDOOR" ? "OUTDOOR " : "ROOM ";
    const rawName = unit.name ?? "";

    /*
     * Daikin AHU 96-97 arrive already named "AHU 96" / "AHU 97". That is the
     * identity the API sent, so it is displayed verbatim — prefixing it with
     * "ROOM " would mislabel them. Existing ROOM / OUTDOOR names are
     * unchanged.
     */
    const upperName = rawName.toUpperCase();
    const isPrefixed = upperName.startsWith("ROOM")
        || upperName.startsWith("OUTDOOR")
        || upperName.startsWith("AHU");

    const displayName = isPrefixed ? rawName : prefix + rawName;

    name.textContent = displayName;
    card.appendChild(name);

    const tempVal = document.createElement("div");
    tempVal.className = "unit-value room-temp";
    tempVal.textContent = (unit.temp !== null && unit.temp !== undefined)
        ? Number(unit.temp).toFixed(1) + " °C"
        : "-- °C";
    applyAlarmClass(tempVal, unit.temp, null, unit.temp_max);
    card.appendChild(tempVal);

    const rhVal = document.createElement("div");
    rhVal.className = "unit-value room-rh";
    rhVal.textContent = (unit.rh !== null && unit.rh !== undefined)
        ? Number(unit.rh).toFixed(1) + " % RH"
        : "-- % RH";
    applyAlarmClass(rhVal, unit.rh, null, unit.rh_max);
    card.appendChild(rhVal);

    return card;
}

/*
 * ROOM TEMP & RH hosts two views under one nav tab: the live room cards and
 * the hourly history table. The switch is rendered first and the grid is
 * cleared here (rather than only in renderEquipment) because switching views
 * re-renders this page directly, without going through renderEquipment.
 */
function renderRoomTempRh() {
    sectionTitle.textContent = "ROOM TEMP & RH";
    grid.classList.remove("hvac-view");

    buildPageSwitch([
        { value: ROOM_VIEW_CURRENT, label: "CURRENT" },
        { value: ROOM_VIEW_HOURLY, label: "HOURLY HISTORY" }
    ], roomView, selectRoomView);

    grid.innerHTML = "";

    if (roomView === ROOM_VIEW_HOURLY) {
        renderRoomHourly();
        return;
    }

    /* Current view has no filters — the switch is the only control. */
    clearPageFilters();

    const data = allData.ROOM_TEMP_RH;

    if (!Array.isArray(data)) {
        unitCount.textContent = "0 units";
        return;
    }

    unitCount.textContent = data.length + " units";

    data.forEach((unit, index) => {
        grid.appendChild(createRoomCard(unit, index));
    });
}

/*
 * Switching views is an in-place re-render, not a navigation: the nav tab and
 * the section title stay put. The hourly view needs its own fetch, the current
 * view reuses the live data already in allData, so both go through
 * safeLoadData() — loadData() picks the right API from the current mode.
 */
function selectRoomView(view) {
    if (view === roomView) {
        return;
    }

    roomView = view;

    renderRoomTempRh();
    safeLoadData();

    /*
     * Mirrors the nav handler: the alarm poll is skipped while the hourly
     * (history) view is on screen, and returning to the live view restarts it.
     */
    if (!isHistoryPage()) {
        safeLoadAlarmData();
    }
}

/* =========================================================
   GLOBAL ALARM PAGE
   =========================================================

   This is a separate mechanism from the per-card alarm colouring above.
   getStatus()/applyAlarmClass() read the instantaneous `alarm` flag and
   threshold bounds that each API returns for a card; this page reads the
   alarm_events table the pollers write, where an event has a stable id, a
   lifecycle (ACTIVE -> CLEARED) and its own ALARM/WARNING category.

   Mute is browser-side only. It never hides, clears or acknowledges an
   alarm, and it never writes to the database.
   ========================================================= */

const ALARM_API_URL = "api_alarm.php";
const ALARM_POLL_MS = 10000;
const ALARM_MUTE_KEY = "hvac_alarm_muted";

/* Active rows shown before the list is folded behind a "show all" toggle. */
const ALARM_ACTIVE_PREVIEW = 50;

let alarmMuted = false;
let alarmSoundBlocked = false;
let alarmShowAll = false;

/*
 * New-alarm detection uses the primary key of alarm_events, which is stable
 * across polls and independent of row order. The first response only records
 * ids so that alarms already active on page load stay silent.
 */
let knownAlarmIds = null;
let alarmAudioContext = null;

function readAlarmMuted() {
    try {
        return window.localStorage.getItem(ALARM_MUTE_KEY) === "1";
    } catch (error) {
        /* Private mode or blocked storage: fall back to unmuted. */
        return false;
    }
}

function writeAlarmMuted(muted) {
    try {
        window.localStorage.setItem(ALARM_MUTE_KEY, muted ? "1" : "0");
    } catch (error) {
        /* Mute still applies for this session even if it cannot persist. */
    }
}

function getAlarmAudioContext() {
    const Ctor = window.AudioContext || window.webkitAudioContext;

    if (!Ctor) {
        return null;
    }

    if (!alarmAudioContext) {
        alarmAudioContext = new Ctor();
    }

    return alarmAudioContext;
}

/*
 * Two short beeps from an oscillator. No audio file, no CDN. The context is
 * resumed first because browsers suspend it until the user has interacted.
 */
function playAlarmTone() {
    const ctx = getAlarmAudioContext();

    if (!ctx) {
        return;
    }

    const beep = (startAt, frequency) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = "square";
        osc.frequency.value = frequency;

        /* Short ramps instead of hard edges: a raw square wave clicks. */
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.exponentialRampToValueAtTime(0.18, startAt + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, startAt + 0.32);

        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + 0.34);
    };

    const now = ctx.currentTime;
    beep(now, 880);
    beep(now + 0.42, 880);
}

function soundAlarm() {
    if (alarmMuted) {
        return;
    }

    const ctx = getAlarmAudioContext();

    if (!ctx) {
        return;
    }

    if (ctx.state === "suspended") {
        ctx.resume().then(() => {
            alarmSoundBlocked = false;
            updateAudioNotice();
            playAlarmTone();
        }).catch(() => {
            alarmSoundBlocked = true;
            updateAudioNotice();
        });
        return;
    }

    alarmSoundBlocked = false;
    updateAudioNotice();
    playAlarmTone();
}

function updateAudioNotice() {
    const notice = document.getElementById("alarmAudioNotice");

    if (!notice) {
        return;
    }

    if (alarmMuted) {
        notice.textContent = "Sound muted.";
    } else if (alarmSoundBlocked) {
        notice.textContent = "Sound blocked by the browser — press MUTE then UNMUTE to enable it.";
    } else {
        notice.textContent = "";
    }
}

function renderMuteButton() {
    const button = document.getElementById("alarmMuteButton");

    if (!button) {
        return;
    }

    button.textContent = alarmMuted ? "UNMUTE" : "MUTE";
    button.classList.toggle("muted", alarmMuted);
    button.setAttribute("aria-pressed", alarmMuted ? "true" : "false");
    button.title = alarmMuted
        ? "Alarm sound is off. Alarms are still listed."
        : "Silence the alarm sound. Alarms stay listed.";

    updateAudioNotice();
}

function toggleAlarmMute() {
    alarmMuted = !alarmMuted;
    writeAlarmMuted(alarmMuted);

    /*
     * Clicking the button is a user gesture, so it is the natural moment to
     * unlock a suspended audio context. This resumes the context without
     * bypassing anything: the browser still decides.
     */
    if (!alarmMuted) {
        const ctx = getAlarmAudioContext();

        if (ctx && ctx.state === "suspended") {
            ctx.resume().then(() => {
                alarmSoundBlocked = false;
                updateAudioNotice();
            }).catch(() => {});
        }
    }

    renderMuteButton();
}

function formatAlarmTime(value) {
    if (!value) {
        return "--";
    }

    /* SQL datetime(6) arrives as "YYYY-MM-DD HH:MM:SS.ffffff". */
    return String(value).replace("T", " ").slice(0, 19);
}

function alarmSourceLabel(alarm) {
    const parts = [];

    if (alarm.point_id) {
        parts.push(alarm.point_id);
    }

    if (alarm.ef_point_id !== null && alarm.ef_point_id !== undefined) {
        parts.push("EF #" + alarm.ef_point_id);
    }

    if (alarm.panel_no !== null && alarm.panel_no !== undefined) {
        parts.push("Panel " + alarm.panel_no);
    }

    if (!parts.length && alarm.metric) {
        parts.push(alarm.metric);
    }

    if (!parts.length) {
        parts.push(alarm.source || "--");
    }

    return parts.join(" · ");
}

function alarmLimitsLabel(alarm) {
    const hasMin = alarm.limit_min !== null && alarm.limit_min !== undefined;
    const hasMax = alarm.limit_max !== null && alarm.limit_max !== undefined;

    if (!hasMin && !hasMax) {
        return "--";
    }

    const min = hasMin ? alarm.limit_min : "-inf";
    const max = hasMax ? alarm.limit_max : "+inf";

    return min + " … " + max;
}

function createAlarmCell(label, value, extraClass) {
    const cell = document.createElement("div");

    cell.className = "alarm-cell" + (extraClass ? " " + extraClass : "");
    cell.dataset.label = label;
    cell.textContent = value;

    return cell;
}

function createAlarmRow(alarm) {
    const row = document.createElement("div");
    const isActive = alarm.status === "ACTIVE";

    row.className = "alarm-row "
        + (isActive ? "alarm-row-active" : "alarm-row-cleared")
        + " alarm-row-class-" + String(alarm.event_class).toLowerCase();

    const category = document.createElement("div");
    category.className = "alarm-cell";
    category.dataset.label = "Category";

    const badge = document.createElement("span");
    badge.className = "alarm-badge "
        + (alarm.event_class === "ALARM" ? "alarm-badge-alarm" : "alarm-badge-warning");
    badge.textContent = alarm.event_class;
    category.appendChild(badge);

    const status = document.createElement("div");
    status.className = "alarm-cell";
    status.dataset.label = "Status";

    const statusText = document.createElement("span");
    statusText.className = "alarm-status "
        + (isActive ? "alarm-status-active" : "alarm-status-cleared");
    statusText.textContent = alarm.status;
    status.appendChild(statusText);

    row.appendChild(category);
    row.appendChild(status);
    row.appendChild(createAlarmCell("Equipment", alarm.equipment || "--", "alarm-cell-equip"));
    row.appendChild(createAlarmCell("Source", alarmSourceLabel(alarm), "alarm-cell-source"));
    row.appendChild(createAlarmCell("Message", alarm.description || "--", "alarm-cell-desc"));
    row.appendChild(createAlarmCell("Limits", alarmLimitsLabel(alarm), "alarm-cell-limits"));
    row.appendChild(createAlarmCell("Raised", formatAlarmTime(alarm.raised_at), "alarm-cell-time"));

    const cleared = document.createElement("div");
    cleared.className = "alarm-cell alarm-cell-time";
    cleared.dataset.label = "Cleared";
    cleared.textContent = isActive ? "--" : formatAlarmTime(alarm.cleared_at);
    row.appendChild(cleared);

    /* The stable event identifier, shown so an alarm can be referenced. */
    row.appendChild(createAlarmCell("Event ID", alarm.id, "alarm-cell-id"));

    return row;
}

function createAlarmHeaderRow() {
    const row = document.createElement("div");

    row.className = "alarm-row alarm-thead";
    ["Category", "Status", "Equipment", "Source / point", "Message",
        "Limits", "Raised at", "Cleared at", "Event ID"].forEach(label => {
        const cell = document.createElement("div");
        cell.textContent = label;
        row.appendChild(cell);
    });

    return row;
}

function createAlarmGroup(title, alarms, isActive) {
    const group = document.createElement("div");

    group.className = "alarm-group" + (isActive ? " alarm-group-active" : "");

    const header = document.createElement("div");
    header.className = "alarm-group-header";

    const titleEl = document.createElement("div");
    titleEl.className = "alarm-group-title";
    titleEl.textContent = title;

    const countEl = document.createElement("div");
    countEl.className = "alarm-group-count";
    countEl.textContent = alarms.length + (alarms.length === 1 ? " event" : " events");

    header.appendChild(titleEl);
    header.appendChild(countEl);
    group.appendChild(header);

    if (!alarms.length) {
        const empty = document.createElement("div");
        empty.className = "alarm-empty";
        empty.textContent = isActive ? "No active alarms" : "No cleared alarms in the window";
        group.appendChild(empty);
        return group;
    }

    group.appendChild(createAlarmHeaderRow());
    alarms.forEach(alarm => group.appendChild(createAlarmRow(alarm)));

    return group;
}

function renderAlarm() {
    const data = allData.ALARM;

    /*
     * Cleared here, not only in renderEquipment(): the background poll
     * re-renders this page directly, and without this the list would stack
     * a fresh copy on every cycle.
     */
    grid.innerHTML = "";

    sectionTitle.textContent = "GLOBAL ALARM";
    grid.classList.remove("hvac-view");

    if (!data || !Array.isArray(data.alarms)) {
        unitCount.textContent = "0 active";
        return;
    }

    const counts = data.counts || {};
    const activeTotal = Number(counts.active_total || 0);
    const activeAlarms = data.alarms.filter(a => a.status === "ACTIVE");
    const clearedAlarms = data.alarms.filter(a => a.status !== "ACTIVE");

    unitCount.textContent = activeTotal + " active";

    const page = document.createElement("div");
    page.className = "alarm-page";

    /* ---- Active summary, straight from the table-wide counts ---- */
    const summary = document.createElement("div");
    summary.className = "alarm-summary";

    [
        { label: "ACTIVE ALARM", value: counts.active_alarm || 0, cls: "alarm-stat-alarm" },
        { label: "ACTIVE WARNING", value: counts.active_warning || 0, cls: "alarm-stat-warning" },
        { label: "TOTAL ACTIVE", value: activeTotal, cls: "alarm-stat-total" }
    ].forEach(stat => {
        const box = document.createElement("div");
        box.className = "alarm-stat " + stat.cls;

        const value = document.createElement("div");
        value.className = "alarm-stat-value";
        value.textContent = stat.value;

        const label = document.createElement("div");
        label.className = "alarm-stat-label";
        label.textContent = stat.label;

        box.appendChild(value);
        box.appendChild(label);
        summary.appendChild(box);
    });

    /* ---- Mute control. Mute affects sound only, never the list. ---- */
    const toolbar = document.createElement("div");
    toolbar.className = "alarm-toolbar";

    const muteButton = document.createElement("button");
    muteButton.type = "button";
    muteButton.id = "alarmMuteButton";
    muteButton.className = "mute-button";
    muteButton.addEventListener("click", toggleAlarmMute);
    toolbar.appendChild(muteButton);

    const notice = document.createElement("div");
    notice.id = "alarmAudioNotice";
    notice.className = "audio-notice";
    toolbar.appendChild(notice);

    summary.appendChild(toolbar);
    page.appendChild(summary);

    /* ---- ACTIVE is the prominent group ---- */
    const shownActive = alarmShowAll
        ? activeAlarms
        : activeAlarms.slice(0, ALARM_ACTIVE_PREVIEW);

    page.appendChild(createAlarmGroup("ACTIVE ALARMS / WARNINGS", shownActive, true));

    if (shownActive.length < activeAlarms.length) {
        const more = document.createElement("button");
        more.type = "button";
        more.className = "mute-button";
        more.textContent = "SHOW ALL " + activeAlarms.length + " ACTIVE";
        more.addEventListener("click", () => {
            alarmShowAll = true;
            renderAlarm();
        });
        page.appendChild(more);
    }

    /* ---- Cleared history, compact and receded ---- */
    page.appendChild(createAlarmGroup(
        "CLEARED (last " + (data.window_hours || 24) + "h)",
        clearedAlarms,
        false
    ));

    grid.appendChild(page);
    renderMuteButton();
}

/*
 * A poll that finds a newly ACTIVE alarm id sounds once. Ids are compared
 * against the previous poll, never against row position, so a reordered or
 * truncated list cannot fake a new alarm.
 */function applyAlarmData(data) {
    const alarms = Array.isArray(data.alarms) ? data.alarms : [];
    const currentIds = new Set(
        alarms.filter(a => a.status === "ACTIVE").map(a => String(a.id))
    );

    if (knownAlarmIds === null) {
        /* First response: record the baseline and stay silent. */
        knownAlarmIds = currentIds;
    } else {
        let hasNew = false;

        currentIds.forEach(id => {
            if (!knownAlarmIds.has(id)) {
                hasNew = true;
            }
        });

        knownAlarmIds = currentIds;

        if (hasNew) {
            soundAlarm();
        }
    }

    allData.ALARM = data;

    if (currentEquipment === "ALARM") {
        renderAlarm();
    }
}

/*
 * The alarm poll always targets api_alarm.php, so it keeps running while
 * another equipment page is on screen and can still sound for a new alarm.
 */
async function loadAlarmData() {
    const response = await fetch(ALARM_API_URL + "?t=" + Date.now(), {
        cache: "no-store"
    });

    if (!response.ok) {
        throw new Error("HTTP " + response.status);
    }

    const data = await response.json();

    if (!data.success) {
        throw new Error("Alarm API returned success=false");
    }

    applyAlarmData(data);
}

async function safeLoadAlarmData() {
    try {
        await loadAlarmData();
    } catch (error) {
        console.error("HVAC alarm API error:", error);

        if (currentEquipment === "ALARM") {
            setConnection(false);
        }
    }
}

/* =========================================================
   HISTORY VIEWS (ENERGY + the ROOM TEMP & RH hourly view)
   =========================================================

   These are historical tables rather than live cards, and they take filters,
   so they do not use the 10s auto-refresh the equipment pages use. They load
   once when opened and again when the user applies a filter — history does
   not change between two polls, and re-fetching it every 10s would be pure
   waste.

   The filter controls live in #pageFilters, outside #equipmentGrid, so a
   re-render does not destroy the inputs the user is typing into.
   ========================================================= */

const ENERGY_API_URL = "api_energy.php";
const TEMP_RH_HOURLY_API_URL = "api_temp_rh_hourly.php";

/*
 * ROOM TEMP & RH holds two views under one nav tab: the live room cards
 * ("current") and the hourly history table ("hourly"). The hourly view is NOT
 * a page of its own — it is a mode of this page, switched in place without
 * navigating. Only this page has the switch, so the mode is ignored elsewhere.
 */
const ROOM_VIEW_CURRENT = "current";
const ROOM_VIEW_HOURLY = "hourly";

let roomView = ROOM_VIEW_CURRENT;

/* Active filter values, kept out of the DOM so a re-render can restore them. */
const historyFilters = {
    ENERGY: { category: "CT", from: "", to: "" },
    TEMP_RH_HOURLY: { metric: "temp", from: "", to: "" }
};

function clearPageFilters() {
    if (pageFilters) {
        pageFilters.innerHTML = "";
    }
}

function clearPageSwitch() {
    if (pageSwitch) {
        pageSwitch.innerHTML = "";
    }
}

/*
 * The Current / Hourly History switch. Built with the same markup and classes
 * as the filter controls so it matches the existing bar styling, and rendered
 * into its own container so switching views never disturbs the filters.
 */
function buildPageSwitch(options, current, onSelect) {
    clearPageSwitch();

    if (!pageSwitch) {
        return;
    }

    options.forEach(opt => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "switch-button" + (opt.value === current ? " is-active" : "");
        button.textContent = opt.label;
        button.addEventListener("click", () => onSelect(opt.value));
        pageSwitch.appendChild(button);
    });
}

function buildFilterBar(defs, values, onApply) {
    clearPageFilters();

    if (!pageFilters) {
        return;
    }

    const inputs = {};

    const addSelect = (name, label, options, current) => {
        const wrap = document.createElement("label");
        wrap.className = "filter-field";

        const text = document.createElement("span");
        text.className = "filter-label";
        text.textContent = label;
        wrap.appendChild(text);

        const select = document.createElement("select");
        select.className = "filter-input";
        select.name = name;

        options.forEach(opt => {
            const o = document.createElement("option");
            o.value = opt.value;
            o.textContent = opt.text;
            if (String(opt.value) === String(current)) {
                o.selected = true;
            }
            select.appendChild(o);
        });

        inputs[name] = select;
        wrap.appendChild(select);
        pageFilters.appendChild(wrap);
    };

    const addDate = (name, label, value) => {
        const wrap = document.createElement("label");
        wrap.className = "filter-field";

        const text = document.createElement("span");
        text.className = "filter-label";
        text.textContent = label;
        wrap.appendChild(text);

        const input = document.createElement("input");
        input.type = "date";
        input.className = "filter-input";
        input.name = name;
        input.value = value || "";
        inputs[name] = input;
        wrap.appendChild(input);
        pageFilters.appendChild(wrap);
    };

    defs.forEach(def => {
        if (def.type === "select") {
            addSelect(def.name, def.label, def.options, values[def.name]);
        } else {
            addDate(def.name, def.label, values[def.name]);
        }
    });

    const apply = document.createElement("button");
    apply.type = "button";
    apply.className = "filter-button";
    apply.textContent = "APPLY";
    apply.addEventListener("click", () => onApply(inputs));
    pageFilters.appendChild(apply);

    const reset = document.createElement("button");
    reset.type = "button";
    reset.className = "filter-button filter-button-reset";
    reset.textContent = "RESET";
    reset.addEventListener("click", () => {
        Object.keys(inputs).forEach(k => {
            if (inputs[k].type === "date") {
                inputs[k].value = "";
            }
        });
        onApply(inputs, true);
    });
    pageFilters.appendChild(reset);
}

function filterNotice(available) {
    if (!available || !available.min || !available.max) {
        return "No history recorded yet.";
    }

    const min = String(available.min).slice(0, 10);
    const max = String(available.max).slice(0, 10);

    return min === max
        ? "History available for " + min + " only."
        : "History available " + min + " to " + max + ".";
}

/*
 * Values are written into textContent, never into markup, so a description
 * or room name coming from the database can never inject HTML.
 */
function createTable(columns, rows, options) {
    const wrap = document.createElement("div");
    wrap.className = "history-table-wrap";

    const table = document.createElement("div");
    table.className = "history-table" + (options && options.compact ? " is-compact" : "");

    table.style.setProperty("--history-cols", columns.length);

    const head = document.createElement("div");
    head.className = "history-row history-head";

    columns.forEach(col => {
        const cell = document.createElement("div");
        cell.className = "history-cell";
        cell.textContent = col.label;
        head.appendChild(cell);
    });

    table.appendChild(head);

    if (!rows.length) {
        const empty = document.createElement("div");
        empty.className = "history-empty";
        empty.textContent = (options && options.emptyText) || "No data for this filter.";
        table.appendChild(empty);
    } else {
        rows.forEach(row => {
            const line = document.createElement("div");
            line.className = "history-row" + (row.className ? " " + row.className : "");

            row.cells.forEach(cell => {
                const el = document.createElement("div");
                el.className = "history-cell" + (cell.className ? " " + cell.className : "");
                el.dataset.label = cell.label || "";
                el.textContent = cell.value;
                line.appendChild(el);
            });

            table.appendChild(line);
        });
    }

    wrap.appendChild(table);

    return wrap;
}

function formatNumber(value, digits) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return "--";
    }

    return Number(value).toLocaleString("en-US", {
        minimumFractionDigits: digits === undefined ? 0 : digits,
        maximumFractionDigits: digits === undefined ? 0 : digits
    });
}

function formatHour(value) {
    if (!value) {
        return "--";
    }

    /* "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DD HH:00" */
    return String(value).replace("T", " ").slice(0, 16);
}

/* =========================================================
   ENERGY
   ========================================================= */

function renderEnergy() {
    const data = allData.ENERGY;

    grid.innerHTML = "";
    sectionTitle.textContent = "ENERGY";
    grid.classList.remove("hvac-view");

    if (!data || !Array.isArray(data.days)) {
        unitCount.textContent = "0 days";
        buildFilterBar([
            { type: "select", name: "category", label: "Category", options: [{ value: "CT", text: "CT" }] },
            { type: "date", name: "from", label: "From" },
            { type: "date", name: "to", label: "To" }
        ], historyFilters.ENERGY, applyEnergyFilter);
        return;
    }

    const units = data.units || [];
    const days = data.days || [];

    unitCount.textContent = days.length + (days.length === 1 ? " day" : " days");

    buildFilterBar([
        {
            type: "select",
            name: "category",
            label: "Category",
            options: (data.categories || ["CT"]).map(c => ({ value: c, text: c }))
        },
        { type: "date", name: "from", label: "From" },
        { type: "date", name: "to", label: "To" }
    ], historyFilters.ENERGY, applyEnergyFilter);

    const page = document.createElement("div");
    page.className = "history-page";

    const notice = document.createElement("div");
    notice.className = "history-notice";
    notice.textContent = filterNotice(data.available)
        + " Showing " + (data.from || "--") + " to " + (data.to || "--")
        + " — daily usage in kWh.";
    page.appendChild(notice);

    if (!units.length) {
        const empty = document.createElement("div");
        empty.className = "history-empty";
        empty.textContent = "No snapshots recorded for " + (data.category || "this category") + " yet.";
        page.appendChild(empty);
        grid.appendChild(page);
        return;
    }

    /*
     * One row per day, one column per unit, newest day first. ONLY the daily
     * usage is displayed — the cumulative TOTAL meter readings are backend
     * calculation data (they exist so kwh_daily_snapshot.php can diff one day
     * against the previous one) and are deliberately not shown here. The API
     * still returns them; the UI just never reads them.
     *
     * Headers are prefixed with the equipment category ("CT 1", "CCP 1",
     * "CHWP 1") rather than a generic "Unit 1", so a screenshot of the table
     * says which equipment it is about.
     */
    const category = data.category || "CT";

    const columns = [{ label: "Date" }];

    units.forEach(u => {
        columns.push({ label: category + " " + u.unit });
    });

    columns.push({ label: "Usage Sum" });

    const rows = days.map(day => {
        const cells = [{ label: "Date", value: day.date, className: "history-cell-key" }];

        units.forEach(u => {
            const v = (day.values || {})[String(u.unit)] || {};

            cells.push({
                label: category + " " + u.unit,
                value: formatNumber(v.usage, 1),
                className: v.usage === null || v.usage === undefined ? "history-cell-null" : "history-cell-num"
            });
        });

        cells.push({
            label: "Usage Sum",
            value: formatNumber(day.sum_usage, 1),
            className: "history-cell-num history-cell-sum"
        });

        return { cells: cells };
    });

    page.appendChild(createTable(columns, rows, {
        compact: true,
        emptyText: "No readings for this date range."
    }));

    grid.appendChild(page);
}

function applyEnergyFilter(inputs, isReset) {
    historyFilters.ENERGY.category = inputs.category.value;
    historyFilters.ENERGY.from = isReset ? "" : inputs.from.value;
    historyFilters.ENERGY.to = isReset ? "" : inputs.to.value;
    safeLoadData();
}

function buildEnergyUrl() {
    const f = historyFilters.ENERGY;
    let url = ENERGY_API_URL + "?category=" + encodeURIComponent(f.category);

    if (f.from) {
        url += "&from=" + encodeURIComponent(f.from);
    }

    if (f.to) {
        url += "&to=" + encodeURIComponent(f.to);
    }

    return url + "&t=" + Date.now();
}

/* =========================================================
   ROOM TEMP & RH — HOURLY HISTORY VIEW
   =========================================================

   Rendered INSIDE the ROOM TEMP & RH page, below the Current / Hourly
   History switch. There is no separate nav tab or page for this view.

   The caller (renderRoomTempRh) has already set the section title, built the
   switch and cleared the grid, so this only builds the filter bar and the
   table. Both views share the one page, so switching never navigates.
   ========================================================= */

function renderRoomHourly() {
    const data = allData.TEMP_RH_HOURLY;

    if (!data || !Array.isArray(data.hours)) {
        unitCount.textContent = "0 hours";
        buildFilterBar([
            { type: "select", name: "metric", label: "Metric", options: [{ value: "temp", text: "TEMPERATURE" }] },
            { type: "date", name: "from", label: "From" },
            { type: "date", name: "to", label: "To" }
        ], historyFilters.TEMP_RH_HOURLY, applyTempRhFilter);
        return;
    }

    const rooms = data.rooms || [];
    const hours = data.hours || [];

    unitCount.textContent = hours.length + (hours.length === 1 ? " hour" : " hours");

    buildFilterBar([
        {
            type: "select",
            name: "metric",
            label: "Metric",
            options: (data.metrics || ["temp"]).map(m => ({
                value: m,
                text: m === "rh" ? "HUMIDITY (%RH)" : "TEMPERATURE (C)"
            }))
        },
        { type: "date", name: "from", label: "From" },
        { type: "date", name: "to", label: "To" }
    ], historyFilters.TEMP_RH_HOURLY, applyTempRhFilter);

    const page = document.createElement("div");
    page.className = "history-page";

    const notice = document.createElement("div");
    notice.className = "history-notice";
    notice.textContent = filterNotice(data.available)
        + " Showing " + (data.from || "--") + " to " + (data.to || "--")
        + " — hourly " + (data.metric === "rh" ? "humidity" : "temperature")
        + " in " + (data.unit || "") + ".";
    page.appendChild(notice);

    if (!rooms.length || !hours.length) {
        const empty = document.createElement("div");
        empty.className = "history-empty";
        empty.textContent = "No hourly snapshots recorded for this date range.";
        page.appendChild(empty);
        grid.appendChild(page);
        return;
    }

    const columns = [{ label: "Hour" }];
    rooms.forEach(r => columns.push({ label: r.label }));
    columns.push({ label: "Reading at" });

    const rows = hours.map(hour => {
        const cells = [{
            label: "Hour",
            value: formatHour(hour.recorded_at),
            className: "history-cell-key"
        }];

        rooms.forEach(room => {
            const value = (hour.values || {})[room.key];

            cells.push({
                label: room.label,
                value: value === null || value === undefined ? "--" : formatNumber(value, 1),
                className: value === null || value === undefined
                    ? "history-cell-null"
                    : "history-cell-num"
            });
        });

        /*
         * read_at is the poller's own timestamp. If it differs across the
         * hour's points the spread is shown, because a frozen point is a
         * real condition and hiding it would make stale data look live.
         */
        const min = hour.read_at_min;
        const max = hour.read_at_max;
        let readAt = "--";

        if (min && max) {
            readAt = min === max
                ? formatHour(min)
                : formatHour(min) + " … " + formatHour(max);
        }

        cells.push({ label: "Reading at", value: readAt, className: "history-cell-time" });

        return { cells: cells };
    });

    page.appendChild(createTable(columns, rows, {
        emptyText: "No readings for this date range."
    }));

    grid.appendChild(page);
}

function applyTempRhFilter(inputs, isReset) {
    historyFilters.TEMP_RH_HOURLY.metric = inputs.metric.value;
    historyFilters.TEMP_RH_HOURLY.from = isReset ? "" : inputs.from.value;
    historyFilters.TEMP_RH_HOURLY.to = isReset ? "" : inputs.to.value;
    safeLoadData();
}

function buildTempRhHourlyUrl() {
    const f = historyFilters.TEMP_RH_HOURLY;
    let url = TEMP_RH_HOURLY_API_URL + "?metric=" + encodeURIComponent(f.metric);

    if (f.from) {
        url += "&from=" + encodeURIComponent(f.from);
    }

    if (f.to) {
        url += "&to=" + encodeURIComponent(f.to);
    }

    return url + "&t=" + Date.now();
}

async function loadHistoryPage(apiUrl) {
    const response = await fetch(apiUrl, { cache: "no-store" });

    if (!response.ok) {
        throw new Error("HTTP " + response.status);
    }

    const data = await response.json();

    if (!data.success) {
        throw new Error("API returned success=false");
    }

    return data;
}

function renderEquipment() {
    grid.innerHTML = "";

    /*
     * Both bars are cleared up front, not at the end: several branches below
     * return early (EF, ALARM, ENERGY), so a trailing clear never ran for
     * them and a switch or filter bar left over from another page stayed on
     * screen. Each page that wants them builds its own.
     */
    clearPageFilters();
    clearPageSwitch();

    if (currentEquipment === "EXHAUST_FAN") {
        renderExhaustFan();
        return;
    }

    if (currentEquipment === "ROOM_TEMP_RH") {
        renderRoomTempRh();
        return;
    }

    if (currentEquipment === "ALARM") {
        renderAlarm();
        return;
    }

    if (currentEquipment === "ENERGY") {
        renderEnergy();
        return;
    }

    const placeholderMenus = {
        AHU_G8: "AHU G8"
    };

    if (placeholderMenus[currentEquipment]) {
        sectionTitle.textContent = placeholderMenus[currentEquipment];
        unitCount.textContent = "Coming Soon";
        grid.classList.remove("hvac-view");
        const placeholder = document.createElement("div");
        placeholder.className = "coming-soon";
        placeholder.textContent = "Coming Soon";
        grid.appendChild(placeholder);
        return;
    }
    if (currentEquipment === "HVAC_SC") {
        grid.classList.add("hvac-view");
        renderHvacSc();
        return;
    } else {
        grid.classList.remove("hvac-view");
    }

    const data = allData[currentEquipment];

    if (!Array.isArray(data)) {
        unitCount.textContent = "0 units";
        return;
    }

    /*
     * AHU 94-95 are still placeholders: they have no data source yet.
     * AHU 96-97 are NOT placeholders — they now arrive as real units from
     * api_ahu.php (Daikin / G8), so they are rendered by the createCard()
     * loop above and must not be drawn twice.
     */
    const placeholderNumbers = currentEquipment === "AHU" ? ["94", "95"] : [];

    sectionTitle.textContent = currentEquipment;
    unitCount.textContent = (data.length + placeholderNumbers.length) + " units";

    data.forEach(unit => {
        grid.appendChild(createCard(unit));
    });

    placeholderNumbers.forEach(num => {
        grid.appendChild(createAhuPlaceholderCard(num));
    });
}

async function loadData() {
    try {
        if (currentEquipment === "ALARM") {
            /*
             * The dedicated alarm poll owns fetching, so this path only fills
             * the gap on a first visit. Without this guard the two 10s timers
             * would request api_alarm.php twice per cycle.
             */
            if (!allData.ALARM) {
                await loadAlarmData();
            } else {
                renderAlarm();
            }

            setConnection(true);

            const serverTime = allData.ALARM && allData.ALARM.server_time;
            lastUpdate.textContent = "DB update: "
                + String(serverTime || "--").slice(0, 19);
            return;
        }

        if (currentEquipment === "ENERGY") {
            allData.ENERGY = await loadHistoryPage(buildEnergyUrl());
            setConnection(true);
            renderEnergy();
            lastUpdate.textContent = "Energy history: " + (allData.ENERGY.from || "--")
                + " to " + (allData.ENERGY.to || "--");
            return;
        }

        /*
         * ROOM TEMP & RH serves two views from one tab, so which API to call
         * depends on the mode: the hourly history table reads
         * api_temp_rh_hourly.php, the live cards read api_room.php below.
         */
        if (currentEquipment === "ROOM_TEMP_RH" && roomView === ROOM_VIEW_HOURLY) {
            allData.TEMP_RH_HOURLY = await loadHistoryPage(buildTempRhHourlyUrl());
            setConnection(true);
            renderRoomTempRh();
            lastUpdate.textContent = "Hourly history: " + (allData.TEMP_RH_HOURLY.from || "--")
                + " to " + (allData.TEMP_RH_HOURLY.to || "--");
            return;
        }

        const apiUrl = currentEquipment === "AHU"
            ? "api_ahu.php"
            : (currentEquipment === "HVAC_SC"
                ? "api_hvac_sc.php"
                : (currentEquipment === "EXHAUST_FAN"
                    ? "api_ef.php"
                    : (currentEquipment === "ROOM_TEMP_RH" ? "api_room.php" : API_URL)));
        const response = await fetch(apiUrl + "?t=" + Date.now(), {
            cache: "no-store"
        });

        if (!response.ok) {
            throw new Error("HTTP " + response.status);
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error("API returned success=false");
        }

        allData = data.equipment || {};

        setConnection(true);
        renderEquipment();
        const timestamps = Object.values(allData)
            .flatMap(group =>
                Array.isArray(group) ? group : Object.values(group || {})
            )
            .map(item => item?.last_update)
            .filter(Boolean);

        if (timestamps.length) {
            const latest = timestamps.sort().at(-1);
            lastUpdate.textContent = "DB update: " + String(latest).slice(0, 19);
        } else {
            lastUpdate.textContent = "DB update: --";
        }

    } catch (error) {
        console.error("HVAC API error:", error);
        setConnection(false);
    }
}

document.querySelectorAll(".nav-button").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll(".nav-button")
            .forEach(btn => btn.classList.remove("active"));

        button.classList.add("active");

        currentEquipment = button.dataset.equipment;
        renderEquipment();
        safeLoadData();

        /*
         * The alarm poll does not run on the history pages (see
         * isHistoryPage). Returning to a live page re-baselines it, so an
         * alarm that arrived while history was on screen is recorded
         * silently instead of firing the moment the user navigates back.
         */
        if (!isHistoryPage()) {
            safeLoadAlarmData();
        }
    });
});

let loadingData = false;

async function safeLoadData() {
    if (loadingData) return;
    loadingData = true;
    try {
        await loadData();
    } finally {
        loadingData = false;
    }
}

/*
 * History views are not auto-refreshed: past days and past hours do not
 * change, so re-fetching them every 10s would be waste. The alarm poll is
 * also skipped there, because a sound triggered by a view that is showing
 * neither alarms nor live equipment is more confusing than useful.
 *
 * ROOM TEMP & RH counts as a history view only while its Hourly History mode
 * is on screen — the Current mode is live equipment and keeps both timers.
 */
function isHistoryPage() {
    if (currentEquipment === "ENERGY") {
        return true;
    }

    return currentEquipment === "ROOM_TEMP_RH" && roomView === ROOM_VIEW_HOURLY;
}

async function safeLoadAlarmData() {
    if (isHistoryPage()) {
        return;
    }

    try {
        await loadAlarmData();
    } catch (error) {
        console.error("HVAC alarm API error:", error);

        if (currentEquipment === "ALARM") {
            setConnection(false);
        }
    }
}

/*
 * The wall display can change resolution, and the viewport can change when a
 * browser's UI appears or disappears. Re-fit rather than waiting for the next
 * poll, but only while the EF view is the one on screen.
 */
let efFitTimer = null;

window.addEventListener("resize", () => {
    if (currentEquipment !== "EXHAUST_FAN") return;

    clearTimeout(efFitTimer);
    efFitTimer = setTimeout(fitExhaustFanToViewport, 120);
});

/*
 * Restore the persisted mute state before anything can sound, so a refresh
 * during an active alarm never produces a burst of sound.
 */
alarmMuted = readAlarmMuted();

safeLoadData();

/*
 * The alarm poll is independent of the visible page so a new alarm still
 * sounds while another equipment view is on screen. The first response only
 * records the baseline; it never sounds.
 */
safeLoadAlarmData();

/*
 * The 10s refresh re-fetches live equipment only. History views (ENERGY, and
 * ROOM TEMP & RH while its Hourly History mode is on) are left alone: their
 * data does not change between two polls, and re-rendering the table every
 * cycle would also throw away the reader's scroll position.
 */
setInterval(() => {
    if (!isHistoryPage()) {
        safeLoadData();
    }
}, 10000);

setInterval(safeLoadAlarmData, ALARM_POLL_MS);
</script>

</body>
</html>

