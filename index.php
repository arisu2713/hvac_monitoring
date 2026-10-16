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
    <button class="nav-button" data-equipment="AHU_G8">AHU / EF G8</button>
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


/* =========================================================
   AHU / EF G8 PAGE
   ========================================================= */

/*
 * The AHU / EF G8 page shows four AHU cards (94-97) and four EF cards
 * (58-61).
 *
 * The numbers are listed explicitly rather than derived from "everything on
 * the main AHU page that is not AHU 1-93": the AHU array from api_ahu.php
 * carries all 97 units, and selecting by position would silently start
 * showing the wrong four the moment a unit is added or dropped.
 */
const G8_AHU_NAMES = ["AHU 94", "AHU 95", "AHU 96", "AHU 97"];
const G8_EF_NAMES = ["EF 58", "EF 59", "EF 60", "EF 61"];

const G8_AHU_9495 = ["AHU 94", "AHU 95"];
const G8_AHU_9697 = ["AHU 96", "AHU 97"];

/*
 * An em dash, not "--" and emphatically not "0". A good part of this page is
 * genuinely unmeasured (see g8EcFanNote below), and a zero would read as a
 * measurement: a stopped fan, 0 °C, 0 A.
 */
const G8_EMPTY = "—";

function g8Format(value, suffix) {
    if (value === null || value === undefined || value === "") {
        return G8_EMPTY;
    }

    const n = Number(value);

    if (!Number.isFinite(n)) {
        return G8_EMPTY;
    }

    return n.toFixed(1) + suffix;
}

function g8BoolText(value, onText, offText) {
    if (value === null || value === undefined) {
        return G8_EMPTY;
    }

    const n = Number(value);

    if (n === 1) {
        return onText;
    }

    if (n === 0) {
        return offText;
    }

    return G8_EMPTY;
}

/*
 * Colour for a plain ON/OFF status: 1 = on (green), 0 = off (red).
 *
 * Use this only where 1 genuinely means "running". A fault flag is the
 * opposite polarity and must use g8TripClass instead — see the note there.
 */
function g8BoolClass(value) {
    if (value === null || value === undefined) {
        return "g8-val-unknown";
    }

    const n = Number(value);

    if (n === 1) {
        return "g8-val-run";
    }

    if (n === 0) {
        return "g8-val-off";
    }

    return "g8-val-unknown";
}

/*
 * Colour for the EC fan TRIP flag, whose polarity is INVERTED relative to a
 * run status: 1 = tripped (fault), 0 = healthy.
 *
 * This is why it cannot share g8BoolClass. Feeding a trip flag through the
 * run-status helper painted a healthy fan's "OK" red and would have painted a
 * real "TRIP" green — the colour would have said the exact opposite of the
 * word. Green is reserved for healthy, red for an actual fault.
 */
function g8TripClass(value) {
    if (value === null || value === undefined) {
        return "g8-val-unknown";
    }

    const n = Number(value);

    if (n === 1) {
        return "g8-val-trip";
    }

    if (n === 0) {
        return "g8-val-ok";
    }

    return "g8-val-unknown";
}

function g8Metric(label, value, valueClass) {
    const item = document.createElement("div");
    item.className = "metric-item";

    const lbl = document.createElement("span");
    lbl.className = "m-lbl";
    lbl.textContent = label;

    const val = document.createElement("span");
    val.className = "m-val" + (valueClass ? " " + valueClass : "");
    val.textContent = value;

    item.appendChild(lbl);
    item.appendChild(val);

    return item;
}

/*
 * The card header is the title alone.
 *
 * It used to carry a top-right status badge (RUN / OFF), which duplicated the
 * card's own background — the background is already painted from the same
 * status — so the same word appeared twice on every card. The status is not
 * lost: the background keeps it, and every card still lists its row-level
 * status values (per-fan status, EF Status) in the body.
 */
function g8CardHeader(titleText) {
    const header = document.createElement("div");
    header.className = "hvac-card-header";

    const title = document.createElement("span");
    title.className = "hvac-card-title";
    title.textContent = titleText;

    header.appendChild(title);

    return header;
}

/*
 * EC Fan 1-5 table for AHU 96-97.
 *
 * Column meanings come from the point master (daikin_points): TRIP is a fault
 * flag (1 = tripped, 0 = healthy), FREQ is the fan frequency in Hz, and the
 * three temperatures are the fan's own motor, electronics and module
 * temperatures in °C. They are labelled with the device part they measure —
 * never "Room" — because they are fan hardware temperatures, not air
 * temperatures.
 *
 * TRIP is rendered as TRIP / OK rather than RUN / OFF: it is a fault flag, so
 * "OFF" would invert its meaning.
 */
function createG8EcFanTable(unit) {
    const table = document.createElement("div");
    table.className = "g8-fan-table";

    ["", "Trip", "Freq", "Motor T", "Elec T", "Module T"].forEach((label, i) => {
        const cell = document.createElement("div");
        cell.className = i === 0 ? "g8-fan-name" : "g8-fan-head";
        cell.textContent = label;
        table.appendChild(cell);
    });

    const fans = unit.ec_fans || {};

    for (let n = 1; n <= 5; n++) {
        const fan = fans[n] || {};

        const name = document.createElement("div");
        name.className = "g8-fan-name";
        name.textContent = "Fan " + n;
        table.appendChild(name);

        const cells = [
            { text: g8BoolText(fan.trip, "TRIP", "OK"), cls: g8TripClass(fan.trip) },
            { text: g8Format(fan.frequency, " Hz"), cls: "" },
            { text: g8Format(fan.temp_motor, " °C"), cls: "" },
            { text: g8Format(fan.temp_electronic, " °C"), cls: "" },
            { text: g8Format(fan.temp_module, " °C"), cls: "" }
        ];

        cells.forEach(c => {
            const cell = document.createElement("div");
            cell.className = "g8-fan-cell" + (c.cls ? " " + c.cls : "");
            cell.textContent = c.text;
            table.appendChild(cell);
        });
    }

    return table;
}

/*
 * AHU 94-95 — HNX G8 (Modbus TCP).
 *
 * run is the OR of the three fan statuses, folded in api_ahu.php. The
 * individual fans are listed underneath so that decision stays auditable.
 * The temperature and humidity labels say Duct, not Room: these are duct
 * sensors, and the two are different measurements.
 *
 * Each fan shows its status and its frequency. The frequency comes from the
 * unit's own fan*_frequency field, which api_ahu.php reads from the
 * EC_FAN*_SPEED points — so it is a real reading when one exists and "—" when
 * the point has never been written. It is never defaulted to 0: a fan at 0 Hz
 * and a fan nobody has measured are different facts, and only one of them is
 * a fault.
 */
function createG8Ahu9495Card(unit) {
    const status = getStatus(unit);

    const card = document.createElement("div");
    card.className = "unit-card hvac-card compact-card g8-card status-" + status;
    card.dataset.status = status;

    card.appendChild(g8CardHeader(unit.name ?? ""));

    const metrics = document.createElement("div");
    metrics.className = "compact-metrics";

    [1, 2, 3].forEach(n => {
        const raw = unit["fan" + n + "_status"];

        metrics.appendChild(g8Metric(
            "EC Fan " + n,
            g8BoolText(raw, "RUN", "OFF"),
            g8BoolClass(raw)
        ));

        metrics.appendChild(g8Metric(
            "EC Fan " + n + " Frequency",
            g8Format(unit["fan" + n + "_frequency"], " Hz")
        ));
    });

    metrics.appendChild(g8Metric("Duct Temperature", g8Format(unit.temp, " °C")));
    metrics.appendChild(g8Metric("Duct RH", g8Format(unit.duct_rh, " % RH")));

    card.appendChild(metrics);

    return card;
}

/*
 * AHU 96-97 — Daikin / Niagara.
 *
 * Room Temperature and Room RH come from RoomTemp1 / SensorHumidityROOM, the
 * same two points the ROOM TEMP & RH page shows for these units.
 *
 * The EC fan table is built from the daikin_points master via a LEFT JOIN, so
 * a point with no current value renders as "—" rather than 0. As of
 * 2026-10-12 daikin_current carries these points with live values, so the
 * table shows real frequency and temperatures.
 */
function createG8Ahu9697Card(unit) {
    const status = getStatus(unit);

    const card = document.createElement("div");
    card.className = "unit-card hvac-card compact-card g8-card status-" + status;
    card.dataset.status = status;

    card.appendChild(g8CardHeader(unit.name ?? ""));

    const metrics = document.createElement("div");
    metrics.className = "compact-metrics";

    metrics.appendChild(g8Metric("Room Temperature", g8Format(unit.room_temp, " °C")));
    metrics.appendChild(g8Metric("Room RH", g8Format(unit.room_rh, " % RH")));

    card.appendChild(metrics);
    card.appendChild(createG8EcFanTable(unit));

    return card;
}

function createG8AhuCard(unit) {
    return G8_AHU_9495.includes(unit.name)
        ? createG8Ahu9495Card(unit)
        : createG8Ahu9697Card(unit);
}

/*
 * Placeholder for a G8 unit the API did not return.
 *
 * The card is drawn in its proper slot and names the unit, so a missing unit
 * reads as "this one is not reporting" rather than as a unit that does not
 * exist. It carries no numbers at all — not even a zero — because the whole
 * point is that nothing is known about it. Status is "unknown", which is the
 * same grey the page already uses for a unit whose state cannot be read.
 */
function createG8MissingCard(name) {
    const card = document.createElement("div");
    card.className = "unit-card hvac-card compact-card g8-card status-unknown";
    card.dataset.status = "unknown";

    card.appendChild(g8CardHeader(name));

    const metrics = document.createElement("div");
    metrics.className = "compact-metrics";

    metrics.appendChild(g8Metric("Status", "NO DATA"));

    card.appendChild(metrics);

    return card;
}

/*
 * EF 58-61 — Daikin / Niagara, via api_ef.php's EXHAUST_FAN_G8 key.
 *
 * Status, Frequency (Hz) and Motor Current (A). The status colours reuse the
 * same getEfStatus() rules as Panel 1-9, so a G8 fan and a panel fan read the
 * same way.
 */
function createG8EfCard(unit) {
    const status = getEfStatus(unit);

    const card = document.createElement("div");
    card.className = "unit-card hvac-card compact-card g8-card status-" + status;
    card.dataset.status = status;

    card.appendChild(g8CardHeader(unit.name ?? ""));

    const metrics = document.createElement("div");
    metrics.className = "compact-metrics";

    metrics.appendChild(g8Metric(
        "Status",
        g8BoolText(unit.status, "RUN", "OFF"),
        g8BoolClass(unit.status)
    ));
    metrics.appendChild(g8Metric("Frequency", g8Format(unit.frequency, " Hz")));
    metrics.appendChild(g8Metric("Motor Current", g8Format(unit.motor_current, " A")));

    card.appendChild(metrics);

    return card;
}

/*
 * One groupbox: header + count, a grid of cards, and an optional footnote.
 */
function g8Group(title, cards, note) {
    const group = document.createElement("section");
    group.className = "hvac-group-card g8-group";

    const header = document.createElement("div");
    header.className = "hvac-group-header";

    const titleEl = document.createElement("div");
    titleEl.className = "hvac-group-title";
    titleEl.textContent = title;

    const count = document.createElement("div");
    count.className = "hvac-group-count";
    count.textContent = cards.length + (cards.length === 1 ? " unit" : " units");

    header.appendChild(titleEl);
    header.appendChild(count);
    group.appendChild(header);

    const cardGrid = document.createElement("div");
    cardGrid.className = "g8-group-grid";

    cards.forEach(card => cardGrid.appendChild(card));
    group.appendChild(cardGrid);

    if (note) {
        const noteEl = document.createElement("div");
        noteEl.className = "g8-note";
        noteEl.textContent = note;
        group.appendChild(noteEl);
    }

    return group;
}

/*
 * Explains the dashes on the AHU cards, once, instead of leaving the reader to
 * guess whether they are a fault.
 *
 * Two separate points can be uncollected, and the note reports each from the
 * data rather than assuming a cause:
 *
 *  - AHU 96-97 EC fan telemetry (trip / frequency / motor, electronics and
 *    module temperature). These points are in the daikin_points master and the
 *    query LEFT JOINs them, so a unit with no written values shows "—".
 *
 *  - AHU 94-95 EC fan frequency (the EC_FAN*_SPEED points). The points exist
 *    in the hnx_g8_points master with unit "Hz", but hnx_g8_current holds no
 *    value for them yet.
 *
 * The note names only the units that actually have no value. It deliberately
 * does not claim which unit is "the broken one": it reports what is missing,
 * and disappears on its own the moment the values start arriving. Showing 0
 * instead would claim a measurement nobody took.
 */
function g8EcFanNote(ahuByName) {
    const metrics = ["trip", "frequency", "temp_motor", "temp_electronic", "temp_module"];

    /*
     * Units whose whole source could not be read (api_ahu.php sets
     * source_available=false when the hnx_g8 tables are unreachable). This is
     * reported FIRST and separately from the uncollected-point cases below: a
     * source that cannot be read at all is a different fact from a mapped
     * point nobody has written yet, and conflating them would send someone
     * looking for a poller that is running fine.
     */
    const sourceMissing = G8_AHU_9495.filter(name => {
        const unit = ahuByName[name];

        return unit && unit.source_available === false;
    });

    const ecFanMissing = G8_AHU_9697.filter(name => {
        const unit = ahuByName[name];

        if (!unit || !unit.ec_fans) {
            return false;
        }

        /* Report the unit only when it has no value at all. */
        return !Object.keys(unit.ec_fans).some(k => {
            const fan = unit.ec_fans[k];

            return fan && metrics.some(m => fan[m] !== null && fan[m] !== undefined);
        });
    });

    /*
     * Only units whose source IS readable can be reported as "points mapped
     * but not written" — for an unreachable source every point is unknown for
     * a different reason, already stated above.
     */
    const freqMissing = G8_AHU_9495.filter(name => {
        const unit = ahuByName[name];

        if (!unit || unit.source_available === false) {
            return false;
        }

        return [1, 2, 3].every(n => {
            const v = unit["fan" + n + "_frequency"];

            return v === null || v === undefined;
        });
    });

    const parts = [];

    if (sourceMissing.length) {
        parts.push("The HNX G8 source for " + sourceMissing.join(", ")
            + " is not reachable from this database, so these units are shown "
            + "as unknown " + G8_EMPTY + " rather than as stopped or zero.");
    }

    if (freqMissing.length) {
        parts.push("EC fan frequency (" + freqMissing.join(", ")
            + ") has no reading yet — the points are mapped but not written, so "
            + "they show " + G8_EMPTY + " rather than zero.");
    }

    if (ecFanMissing.length) {
        parts.push("EC fan telemetry (trip, frequency, motor / electronics / "
            + "module temperature) has no reading yet for "
            + ecFanMissing.join(", ") + " — the points are mapped but not "
            + "written, so they show " + G8_EMPTY + " rather than zero.");
    }

    return parts.length ? parts.join(" ") : null;
}

function renderAhuEfG8() {
    grid.classList.add("hvac-view");

    sectionTitle.textContent = "AHU / EF G8";

    const ahuData = Array.isArray(allData.AHU) ? allData.AHU : [];
    const efData = Array.isArray(allData.EXHAUST_FAN_G8) ? allData.EXHAUST_FAN_G8 : [];

    /*
     * Indexed by name and then walked in the order the constants declare, so
     * the page always lays the four cards out in the same positions and a unit
     * the API did not send leaves a gap instead of shifting its neighbours.
     */
    const ahuByName = {};
    ahuData.forEach(unit => {
        if (unit && unit.name) {
            ahuByName[unit.name] = unit;
        }
    });

    const efByName = {};
    efData.forEach(unit => {
        if (unit && unit.name) {
            efByName[unit.name] = unit;
        }
    });

    /*
     * Walked in the order the constants declare, and a unit the API did not
     * send becomes an explicit placeholder card rather than being filtered
     * out. The comment above promises the four cards keep their positions and
     * a missing unit "leaves a gap instead of shifting its neighbours", but
     * .filter() did the opposite: it collapsed the row, so AHU 95 slid into
     * AHU 94's place and the page silently showed three cards where the
     * reader expects four. The placeholder holds the slot and names the unit,
     * so the absence is visible instead of disguised.
     */
    const ahuCards = G8_AHU_NAMES.map(name => {
        return ahuByName[name]
            ? createG8AhuCard(ahuByName[name])
            : createG8MissingCard(name);
    });

    const efCards = G8_EF_NAMES.map(name => {
        return efByName[name]
            ? createG8EfCard(efByName[name])
            : createG8MissingCard(name);
    });

    unitCount.textContent = (ahuCards.length + efCards.length) + " units";

    const page = document.createElement("div");
    page.className = "g8-page";

    page.appendChild(g8Group("AHU 94 - 97", ahuCards, g8EcFanNote(ahuByName)));
    page.appendChild(g8Group("EXHAUST FAN 58 - 61", efCards, null));

    grid.appendChild(page);
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
const ALARM_WINDOW_KEY = "hvac_alarm_window_hours";

/* Active rows shown before the list is folded behind a "show all" toggle. */
const ALARM_ACTIVE_PREVIEW = 50;

/*
 * The cleared-history window. api_alarm.php accepts ?hours= clamped to 1-168;
 * the two values the page offers are the ends of that range. 168 is 7 days
 * exactly, which is the API's own ceiling - the page cannot ask for more.
 */
const ALARM_WINDOW_OPTIONS = [
    { value: 24, label: "LAST 24 HOURS" },
    { value: 168, label: "LAST 7 DAYS" }
];
const ALARM_WINDOW_DEFAULT = 24;

let alarmMuted = false;
let alarmSoundBlocked = false;
let alarmShowAll = false;

/*
 * The selected window, kept out of the DOM so the 10s poll can re-render
 * without losing the user's choice. Persisted in localStorage so it also
 * survives a reload, matching how the mute state behaves.
 */
let alarmWindowHours = ALARM_WINDOW_DEFAULT;

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

/*
 * The selected history window, read once at load. Only the two offered values
 * are accepted; anything else in storage (hand-edited, or left by an older
 * build) falls back to the default rather than being sent to the API.
 */
function readAlarmWindowHours() {
    let stored = null;

    try {
        stored = window.localStorage.getItem(ALARM_WINDOW_KEY);
    } catch (error) {
        return ALARM_WINDOW_DEFAULT;
    }

    const value = parseInt(stored, 10);

    return ALARM_WINDOW_OPTIONS.some(opt => opt.value === value)
        ? value
        : ALARM_WINDOW_DEFAULT;
}

function writeAlarmWindowHours(hours) {
    try {
        window.localStorage.setItem(ALARM_WINDOW_KEY, String(hours));
    } catch (error) {
        /* The choice still applies for this session if it cannot persist. */
    }
}

/*
 * Switching the window re-fetches immediately rather than waiting up to 10s
 * for the next poll, so the list and the counts agree with the control the
 * moment it is used. The selection itself lives in alarmWindowHours, so the
 * poll that follows keeps it.
 */
function selectAlarmWindow(hours) {
    if (hours === alarmWindowHours) {
        return;
    }

    alarmWindowHours = hours;
    writeAlarmWindowHours(hours);

    /*
     * A new window is a new row set; the fold state and the "already seen"
     * baseline both belong to the old one. Resetting the baseline keeps a
     * window change from being mistaken for newly raised alarms and sounding.
     */
    alarmShowAll = false;
    knownAlarmIds = null;

    /*
     * The page is not re-rendered from the old payload here: the group heading
     * is derived from the window, so drawing it before the new rows arrive
     * would label 24h of rows "last 168h" for the length of one request. The
     * switch shows the change immediately, and the fetch below redraws the
     * whole page - heading, rows and counts - in one consistent pass.
     */
    updateAlarmWindowSwitch();

    safeLoadAlarmData();
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

/*
 * The history-window switch, built into #pageSwitch - the same container and
 * the same classes ROOM TEMP & RH uses for its CURRENT / HOURLY switch, so it
 * matches the existing bar styling without introducing new markup.
 *
 * Rebuilt on every render so the active state always reflects
 * alarmWindowHours; the click handler calls selectAlarmWindow, which re-fetches
 * rather than waiting for the next poll.
 */
function updateAlarmWindowSwitch() {
    buildPageSwitch(
        ALARM_WINDOW_OPTIONS.map(opt => ({ value: opt.value, label: opt.label })),
        alarmWindowHours,
        selectAlarmWindow
    );
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

function createAlarmGroup(title, alarms, isActive, unavailable) {
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

        /*
         * "No active alarms" is a claim about the building. It can only be
         * made when the source was actually read; otherwise the honest
         * statement is that nothing is known.
         */
        empty.textContent = unavailable
            ? "No data — the alarm source is unavailable"
            : (isActive ? "No active alarms" : "No cleared alarms in the window");

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

    /*
     * The window actually applied by the API, not the one the page asked for:
     * the endpoint clamps ?hours= to 1-168, so echoing its value back keeps the
     * heading honest even if the request were ever out of range.
     */
    const windowHours = Number(data.window_hours || alarmWindowHours);

    unitCount.textContent = activeTotal + " active";

    /* The history-window switch lives in #pageSwitch, above the grid. */
    updateAlarmWindowSwitch();

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

    /*
     * Source banner.
     *
     * The summary above reads all zeros in three different situations that
     * mean completely different things:
     *
     *   1. the alarm source cannot be read at all (source_available false);
     *   2. it reads fine but has never held a row (total_events 0);
     *   3. it holds history and there is simply nothing active right now.
     *
     * Only (3) is "the building is fine". Rendering (1) and (2) identically to
     * (3) is what made the hosted page look like a working feed with nothing to
     * report. Each case now says which one it is, and the banner disappears
     * entirely in case (3) so a healthy page is not cluttered by a warning.
     */
    const sourceAvailable = data.source_available !== false;
    const totalEvents = Number(data.total_events || 0);

    let sourceMessage = null;

    if (!sourceAvailable) {
        sourceMessage = "ALARM SOURCE UNAVAILABLE — this database has no "
            + "readable alarm_events table, so the counts below are not a "
            + "statement about the building. No alarm data is being reported.";
    } else if (totalEvents === 0) {
        sourceMessage = "NO ALARM DATA — the alarm source is reachable but has "
            + "never recorded an event, so nothing has been reported yet. "
            + "This is not the same as a building with no alarms.";
    }

    if (sourceMessage) {
        const banner = document.createElement("div");
        banner.className = "alarm-notice alarm-source-notice";
        banner.textContent = sourceMessage;
        page.appendChild(banner);
    }

    /* ---- ACTIVE is the prominent group ---- */
    const shownActive = alarmShowAll
        ? activeAlarms
        : activeAlarms.slice(0, ALARM_ACTIVE_PREVIEW);

    page.appendChild(createAlarmGroup(
        "ACTIVE ALARMS / WARNINGS",
        shownActive,
        true,
        !sourceAvailable
    ));

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
    /*
     * The heading names both timestamps because that is what the window now
     * means: an event is listed when it was RAISED in the window or CLEARED in
     * it. Saying only "last 24h" was what made a long-lived alarm look like it
     * had been dropped - it was raised before the window and cleared inside it.
     */
    page.appendChild(createAlarmGroup(
        "CLEARED — RAISED OR CLEARED IN THE LAST " + windowHours + "h",
        clearedAlarms,
        false,
        !sourceAvailable
    ));

    /*
     * Truncation notice.
     *
     * The API caps its payload (LIMIT, default 1000). When the cap bites, the
     * oldest rows in the window are dropped by the ORDER BY, and the page must
     * say so rather than presenting a partial list as the whole history. The
     * counts come from the API's own before-LIMIT count over the identical
     * predicate, so this is exact and not an estimate.
     *
     * "returned" and "matched" are both whole-window figures, not per-class: the
     * API's LIMIT applies to the combined ACTIVE + CLEARED result set. Naming
     * the window explicitly is what keeps the two numbers unambiguous - a bare
     * "in this window" reads as the wrong window the moment the user switches.
     */
    if (data.truncated) {
        const notice2 = document.createElement("div");
        notice2.className = "alarm-notice";
        notice2.textContent = "SHOWING " + data.returned
            + " OF " + data.matched + " EVENTS IN THE LAST " + windowHours + "h"
            + " — the oldest " + (data.matched - data.returned)
            + " are beyond the " + (data.limit || 1000) + "-row API limit.";
        page.appendChild(notice2);
    }

    grid.appendChild(page);
    renderMuteButton();
    syncAlarmStickyOffset();
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
 *
 * The selected window is sent on every request, including the first, so a
 * reload restores the user's choice instead of silently reverting to 24h.
 */
async function loadAlarmData() {
    const response = await fetch(
        ALARM_API_URL + "?hours=" + encodeURIComponent(alarmWindowHours)
            + "&t=" + Date.now(),
        { cache: "no-store" }
    );

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

/*
 * The sticky chrome stacks: topbar, then nav, then the alarm table's own
 * column header. CSS cannot know where the second one ends - the nav wraps to
 * two lines at narrower widths, and the topbar's height changes with the media
 * query - so both offsets are measured here and published as custom properties.
 *
 * Measured from the two elements' real rects rather than hardcoded, and only
 * while the ALARM page is on screen, so no other page pays for it. Called after
 * every alarm render and on resize.
 */
function syncAlarmStickyOffset() {
    if (currentEquipment !== "ALARM") {
        return;
    }

    const topbar = document.querySelector(".topbar");
    const nav = document.getElementById("equipmentNav");

    if (!topbar || !nav) {
        return;
    }

    /*
     * Both offsets are distances from the document top, which is exactly the
     * space each sticky bar must clear. getBoundingClientRect is viewport
     * relative, so the current scroll offset is added back in.
     */
    const scrollTop = window.scrollY;
    const navTop = Math.round(topbar.getBoundingClientRect().bottom + scrollTop);
    const headerTop = Math.round(nav.getBoundingClientRect().bottom + scrollTop);

    const root = document.documentElement;
    root.style.setProperty("--alarm-nav-top", Math.max(0, navTop) + "px");
    root.style.setProperty("--alarm-sticky-offset", Math.max(0, headerTop) + "px");
}

window.addEventListener("resize", () => {
    if (currentEquipment === "ALARM") {
        syncAlarmStickyOffset();
    }
});

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

/*
 * The ENERGY page's three view modes and its "all equipment" sentinel.
 *
 * Declared here, above `historyFilters`, because that object's initialiser runs
 * at load time and `const` has no hoisting - referencing these further down the
 * file would throw a ReferenceError before the page ever rendered.
 *
 * The sentinel is a string that cannot collide with a unit number, since unit
 * numbers come from the API as digits.
 */
const ENERGY_ALL_UNITS = "__all__";
const ENERGY_VIEW_OVERVIEW = "overview";
const ENERGY_VIEW_DETAIL = "detail";
const ENERGY_VIEW_COMBINED = "combined";

/* Active filter values, kept out of the DOM so a re-render can restore them. */
const historyFilters = {
    ENERGY: { category: "CT", from: "", to: "", unit: ENERGY_ALL_UNITS, view: ENERGY_VIEW_OVERVIEW },
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

    /*
     * Returned so a page can append its own controls (CHART / EXPORT) to the
     * same bar rather than introducing a second one.
     */
    return pageFilters;
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
    const category = data.category || "CT";

    unitCount.textContent = days.length + (days.length === 1 ? " day" : " days");

    /*
     * A unit can stop appearing in the response (a meter dropped from the
     * snapshot list, or a category swap that leaves a stale selection). Fall
     * back to the combined view rather than charting a unit that is not there.
     */
    if (historyFilters.ENERGY.unit !== ENERGY_ALL_UNITS
        && !units.some(u => String(u.unit) === String(historyFilters.ENERGY.unit))) {
        historyFilters.ENERGY.unit = ENERGY_ALL_UNITS;
    }

    const bar = buildFilterBar([
        {
            type: "select",
            name: "category",
            label: "Category",
            options: (data.categories || ["CT"]).map(c => ({ value: c, text: c }))
        },
        /*
         * Equipment options are derived from THIS response's unit list, so the
         * selector can only ever offer equipment the selected category actually
         * has - CT offers 1-6 and 9-13, CCP and CHWP offer 1-9. "Combined" is
         * first because it is the default, and it is what the chart shows
         * whenever no single unit is chosen.
         */
        {
            type: "select",
            name: "unit",
            label: "Equipment",
            options: [{ value: ENERGY_ALL_UNITS, text: "Combined — All equipment" }]
                .concat(units.map(u => ({ value: String(u.unit), text: category + " " + u.unit })))
        },
        { type: "date", name: "from", label: "From" },
        { type: "date", name: "to", label: "To" }
    ], historyFilters.ENERGY, applyEnergyFilter);

    appendHistoryButtons(bar, {
        onToggle: () => {},
        onExport: () => exportEnergy(data)
    });

    /*
     * The three view modes, in its own container above the filter bar so
     * switching modes never disturbs the filters (and vice versa). All three
     * are available regardless of how many units the category has: with a
     * single unit, Overview and Combined simply draw the same one line.
     */
    buildPageSwitch([
        { value: ENERGY_VIEW_OVERVIEW, label: "OVERVIEW — ALL EQUIPMENT" },
        { value: ENERGY_VIEW_DETAIL, label: "DETAIL — SELECTED" },
        { value: ENERGY_VIEW_COMBINED, label: "COMBINED — ONE CHART" }
    ], historyFilters.ENERGY.view, setEnergyView);

    const page = document.createElement("div");
    page.className = "history-page";

    const notice = document.createElement("div");
    notice.className = "history-notice";
    notice.textContent = filterNotice(data.available)
        + " Showing " + (data.from || "--") + " to " + (data.to || "--")
        + " — daily usage in kWh.";
    page.appendChild(notice);

    /*
     * The chart sits directly under the notice, above the table. Rendered
     * oldest-first so time runs left to right, while the table keeps its own
     * newest-first order.
     *
     * Wrapped in .history-chart-stack so the stylesheet can tell "ENERGY with a
     * chart open" apart from "ENERGY with the chart closed" — the wall-display
     * overflow override keys off that class, and emitting it only when a chart
     * exists is what keeps the chartless page byte-identical to before.
     */
    if (showChart && units.length && days.length) {
        const stack = document.createElement("div");
        stack.className = "history-chart-stack";
        stack.appendChild(buildEnergyChart(data));
        page.appendChild(stack);
    }

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
     *
     * The table always shows every unit in the category, in every view mode.
     * Narrowing it to the selected unit would hide the comparison the combined
     * chart exists to make, and would silently change what EXPORT writes — the
     * task requires the export to keep matching the table it sits beside.
     */
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

    /* Measure only once the table is in the document. */
    syncHistoryTableOffset();
}

/*
 * ENERGY chart: the hybrid Overview / Detail / Combined experience.
 *
 * All three modes plot the same `usage` values the table's columns show, over
 * the same days, through the same formatters, so the chart and the table can
 * never disagree.
 *
 * A null usage means "not derivable" (no previous day, or a meter reset). It is
 * left as a gap in the line and counted in the note — never plotted as 0, which
 * would invent a real reading of zero. That distinction is load-bearing on this
 * page: several channels (CT 3, CCP 8, CHWP 2, CHWP 7) read a genuine 0 every
 * single day, so a zero and a gap appear side by side in the same table and
 * must not be conflated.
 *
 * Every mode pins an explicit y-range and asks for real tick counts. That is
 * deliberate: the shared panel's facet path requests only two ticks and pins no
 * step, which on this data drew just "0" and "100" across a 0-152 band (CCP:
 * "0"/"500" on 0-696; CHWP: "0"/"500" on 0-888) and left the top of every plot
 * unlabelled.
 */
function buildEnergyChart(data) {
    const units = data.units || [];
    const days = (data.days || []).slice().reverse();
    const category = data.category || "CT";
    const selected = historyFilters.ENERGY.unit;
    const mode = historyFilters.ENERGY.view;

    /*
     * Which equipment each mode draws:
     *   Combined - every unit in the category, on one shared axis.
     *   Detail   - exactly the selected unit.
     *   Overview - every unit in the category, one small chart each.
     *
     * Overview and Combined therefore plot the same set; they differ only in
     * whether the units share a plot or get their own. Keeping them on the same
     * set is what makes toggling between them a change of presentation rather
     * than a change of subject.
     */
    const charted = mode === ENERGY_VIEW_DETAIL
        ? units.filter(u => String(u.unit) === String(selected))
        : units;

    if (!charted.length) {
        return buildHistoryChartPanel({
            title: "DAILY ENERGY USAGE",
            meta: "No equipment selected",
            series: [],
            emptyText: "No equipment to chart for this filter.",
            options: energyChartOptions()
        });
    }

    /* Colour is keyed to the equipment's identity, never its array position. */
    const series = charted.map(u => ({
        name: category + " " + u.unit,
        color: energyChartColor(units, u.unit),
        points: days.map(day => {
            const v = (day.values || {})[String(u.unit)] || {};
            return {
                label: day.date,
                full: day.date,
                value: isMissingValue(v.usage) ? null : Number(v.usage)
            };
        })
    }));

    let missing = 0;

    series.forEach(s => s.points.forEach(p => {
        if (isMissingValue(p.value)) missing++;
    }));

    const note = missing
        ? missing + " of " + (series.length * days.length)
          + " readings have no derivable usage and are left as gaps (not plotted as 0)."
        : "All " + (series.length * days.length) + " readings have a derivable usage.";

    const dayWord = days.length === 1 ? " day" : " days";
    const span = (data.from || "--") + " to " + (data.to || "--");

    if (mode === ENERGY_VIEW_OVERVIEW) {
        /*
         * One small chart per unit, all on ONE shared scale, zero-based.
         *
         * A shared scale is what makes the panels comparable — separate scales
         * would make a channel that idles at 12 kWh look as busy as one that
         * swings between 400 and 858, and the reader would have no way to tell
         * which panel is the big consumer. The band starts at 0 for the same
         * reason: usage is a quantity, and two panels whose axes start at
         * different heights cannot be compared by eye at all.
         *
         * The top is padded so the tallest line never rides the frame.
         */
        let peak = 0;

        series.forEach(s => s.points.forEach(p => {
            if (!isMissingValue(p.value) && p.value > peak) peak = p.value;
        }));

        /* All-zero category: give the panels a clean 0-1 axis to sit on rather
         * than a zero-height band. */
        if (!(peak > 0)) peak = 1;

        const top = peak * 1.08;

        return buildHistoryChartPanel({
            title: "DAILY ENERGY USAGE — " + category + " OVERVIEW (" + days.length + dayWord + ")",
            meta: span + " — " + series.length + " channels, one panel each, shared 0-" + formatCompactNumber(Math.round(top)) + " kWh scale",
            series: series,
            note: note,
            /*
             * `combine` keeps the shared panel from applying its own faceting
             * rule, so this mode controls the layout. `forceFacets` asks for the
             * one-panel-per-channel layout explicitly, and the pinned range is
             * the shared band computed above.
             */
            combine: true,
            forceFacets: true,
            facetColumns: 3,
            facetTickTarget: 5,
            facetRange: { min: 0, max: top },
            emptyText: "No daily usage to chart for this filter.",
            options: energyChartOptions()
        });
    }

    if (mode === ENERGY_VIEW_DETAIL) {
        const unit = charted[0];

        return buildHistoryChartPanel({
            title: "DAILY ENERGY USAGE — " + category + " " + unit.unit + " (" + days.length + dayWord + ")",
            meta: span + " — daily usage in kWh",
            series: series,
            note: note,
            emptyText: "No daily usage to chart for this filter.",
            options: energyChartOptions()
        });
    }

    /*
     * Combined: every unit in the category on ONE shared axis, with a legend
     * that shows and hides individual series.
     *
     * This is the only mode that can carry more series than the palette has
     * slots, which is why the palette was extended to eleven validated hues
     * rather than cycled. `combine` opts out of the shared panel's faceting
     * fallback; the legend toggle is opted into separately so ROOM TEMP & RH,
     * which shares this panel, keeps its current display-only legend.
     */
    return buildHistoryChartPanel({
        title: "DAILY ENERGY USAGE — ALL " + category + " (" + days.length + dayWord + ")",
        meta: span + " — " + series.length + " channels on one shared scale (kWh)",
        series: series,
        note: note,
        combine: true,
        legendToggle: true,
        emptyText: "No daily usage to chart for this filter.",
        options: energyChartOptions()
    });
}

/*
 * Shared chart options for all three ENERGY modes.
 *
 * `padFraction` is what switches on adaptive scaling in the geometry (a caller
 * passing neither it nor `autoScale` gets the plain data range), so every mode
 * gets a padded, outward-snapped band that keeps the extreme lines off the
 * frame and can never clip an outlier.
 *
 * The tick target is 6, not the facet path's 2, and `preferredSteps` lists the
 * steps that read cleanly for energy in kWh — 50/100/200/250/500 and their
 * powers-of-ten relatives. A preferred step is only taken when it leaves a
 * legible number of intervals, so a narrow range falls back to the general
 * ladder rather than collapsing to one or two giant intervals.
 */
function energyChartOptions() {
    return {
        unit: "kWh",
        axisLabel: formatAxisDate,
        tipTitle: label => label,
        padFraction: 0.06,
        tickTarget: 6,
        preferredSteps: [50, 100, 200, 250, 500, 1000, 2000, 2500, 5000],
        /*
         * Daily usage is a non-negative quantity, so the axis is floored at 0
         * rather than padded below it. Without this a 0-858 channel is drawn on
         * a -200..1000 axis, which labels negative energy.
         */
        nonNegative: true
    };
}

/*
 * Export exactly what the table shows: same days, same columns, same
 * newest-first order, same numbers. Nulls are written as empty cells so a
 * missing reading is not silently exported as 0.
 */
function exportEnergy(data) {
    const units = data.units || [];
    const days = data.days || [];
    const category = data.category || "CT";

    const columns = ["Date"];

    units.forEach(u => columns.push(category + " " + u.unit + " (kWh)"));

    columns.push("Usage Sum (kWh)");

    const rows = days.map(day => {
        const row = [day.date];

        units.forEach(u => {
            const v = (day.values || {})[String(u.unit)] || {};
            row.push(isMissingValue(v.usage) ? null : Number(v.usage));
        });

        row.push(isMissingValue(day.sum_usage) ? null : Number(day.sum_usage));

        return row;
    });

    const bytes = buildXlsxWorkbook(
        "ENERGY " + category,
        columns,
        rows
    );

    downloadXlsx(exportFilename("energy_" + category, data.from, data.to), bytes);
}

/*
 * APPLY and RESET both come through here.
 *
 * Category, equipment and view mode are NOT date fields, so RESET deliberately
 * leaves them alone: it clears the date range, which is the only thing the
 * RESET button has ever cleared on this page. Silently resetting the category
 * to CT or the view to Overview as well would make one button do three jobs and
 * lose the reader's place.
 *
 * Changing category always re-fetches, because the equipment list and the
 * equipment selector are both derived from the response. Changing only the
 * equipment or the view mode does not: both re-present data already in hand, so
 * they re-render from cache instead of making the reader wait on a request that
 * would return the same numbers — and, more importantly, so the page does not
 * flash empty in between.
 */
function applyEnergyFilter(inputs, isReset) {
    const f = historyFilters.ENERGY;
    const prev = { category: f.category, unit: f.unit, from: f.from, to: f.to };

    f.category = inputs.category.value;
    f.unit = inputs.unit.value;
    f.from = isReset ? "" : inputs.from.value;
    f.to = isReset ? "" : inputs.to.value;

    /*
     * A unit selection belongs to the category it was made in — CT 11 is not a
     * CCP channel — so a category change drops it back to the combined view
     * rather than carrying a stale selection across.
     */
    if (f.category !== prev.category) {
        f.unit = ENERGY_ALL_UNITS;
    }

    /*
     * DETAIL is about one piece of equipment, so it cannot be showing with no
     * equipment chosen. Picking "Combined — All equipment" while DETAIL is the
     * active mode therefore means the reader wants all of them, which is
     * OVERVIEW — so the mode follows the selection rather than drawing an empty
     * frame that reads as "this filter has no data".
     */
    if (f.unit === ENERGY_ALL_UNITS && f.view === ENERGY_VIEW_DETAIL) {
        f.view = ENERGY_VIEW_OVERVIEW;
    }

    const dataUnchanged = f.category === prev.category
        && f.from === prev.from && f.to === prev.to;

    if (dataUnchanged && allData.ENERGY) {
        renderEquipment();
        return;
    }

    safeLoadData();
}

/*
 * Switch view mode in place. Re-renders from the data already loaded, so the
 * three modes can be compared without a round trip.
 */
function setEnergyView(view) {
    if (historyFilters.ENERGY.view === view) return;

    historyFilters.ENERGY.view = view;
    renderEquipment();
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

    const bar = buildFilterBar([
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

    appendHistoryButtons(bar, {
        onToggle: () => {},
        onExport: () => exportTempRh(data)
    });

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

    if (showChart) {
        page.appendChild(buildRoomCharts(data, allData.TEMP_RH_HOURLY_PEER));
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

    /* Measure only once the table is in the document. */
    syncHistoryTableOffset();
}

/*
 * One combined chart for one metric: every room is a line, all sharing the
 * hours the table is showing.
 *
 * The series list is built from the rooms the API reports for the range, and
 * each line is keyed to the room's own key — so a room keeps its colour when
 * the range narrows and the same room is the same colour in both charts.
 *
 * A missing reading stays null. The chart geometry skips nulls, which leaves a
 * real gap in the line; it is never coerced to 0, which would draw a plunge to
 * the baseline and read as a genuine measurement.
 */
function buildRoomChartPanel(data, metricKey, panelId) {
    const rooms = data.rooms || [];
    const hours = (data.hours || []).slice().reverse();
    const isRh = metricKey === "rh";
    /*
     * The API reports the temperature unit as plain "C". The chart is asked for
     * degrees, so the display unit is mapped here — in the chart only. The
     * table, the notice and the export keep reading `data.unit`, so nothing
     * outside this panel changes spelling.
     */
    const unit = isRh ? (data.unit || "%RH") : "°C";
    const ref = ROOM_CHART_REFERENCE[isRh ? "rh" : "temp"];
    const axis = ROOM_CHART_AXIS[isRh ? "rh" : "temp"];

    const series = rooms.map(room => ({
        name: room.label,
        color: roomChartColor(room.key),
        points: hours.map(hour => {
            const v = (hour.values || {})[room.key];
            return {
                label: hour.recorded_at,
                full: hour.recorded_at,
                value: isMissingValue(v) ? null : Number(v)
            };
        })
    }));

    let missing = 0;

    series.forEach(s => s.points.forEach(p => {
        if (isMissingValue(p.value)) missing++;
    }));

    const total = series.length * hours.length;

    const note = missing
        ? missing + " of " + total
          + " readings are missing and are left as gaps (not plotted as 0)."
        : "All " + total + " readings are present.";

    return buildHistoryChartPanel({
        panelId: panelId,
        title: (isRh ? "HUMIDITY" : "TEMPERATURE") + " — ALL ROOMS ("
            + hours.length + (hours.length === 1 ? " hour" : " hours") + ")",
        meta: (data.from || "--") + " to " + (data.to || "--")
            + " — " + series.length + " rooms, reference line at " + ref.label,
        series: series,
        note: note,
        combine: true,
        emptyText: "No hourly readings to chart for this filter.",
        options: {
            unit: unit,
            axisLabel: formatAxisHour,
            tipTitle: label => formatHour(label),
            /*
             * No pinned range: the band is derived from the readings in view,
             * padded and snapped outward, and re-derived on every filter
             * change. `preferred` only steers the tick step — 2/5 °C and
             * 5/10 %RH read best for these two metrics. `unit` above is the
             * chart's own spelling and does not feed the tooltip; the tooltip
             * header unit still comes from `data.unit`, so it is unchanged.
             */
            padFraction: axis.pad,
            tickTarget: axis.target,
            preferredSteps: axis.preferred,
            referenceLines: [{ value: ref.value, label: ref.label }]
        }
    });
}

/*
 * ROOM TEMP & RH charts: exactly two panels, always Temperature then Humidity,
 * regardless of which metric the filter bar has selected. The filter still
 * drives the table and the export (which remain single-metric), and it still
 * drives the date range both charts are drawn from.
 *
 * The order is fixed rather than led by the selected metric so the pair never
 * swaps places: on a wall display the reader learns "top is temperature" once
 * and it stays true.
 *
 * The peer metric is a separate fetch, so it can be absent — after a failed
 * load, or on the very first paint. That case renders the selected metric's
 * chart alone and says why, rather than drawing an empty frame that would look
 * like "this room has no data".
 */
function buildRoomCharts(primary, peer) {
    const wrap = document.createElement("div");
    wrap.className = "history-chart-stack";

    const primaryMetric = primary && primary.metric === "rh" ? "rh" : "temp";
    const peerMetric = primaryMetric === "rh" ? "temp" : "rh";

    const hasPeer = peer && Array.isArray(peer.hours) && peer.hours.length > 0;

    if (hasPeer) {
        const byMetric = {};
        byMetric[primaryMetric] = primary;
        byMetric[peerMetric] = peer;

        /* Fixed order: Temperature first, then Humidity. */
        ["temp", "rh"].forEach(metricKey => {
            wrap.appendChild(buildRoomChartPanel(byMetric[metricKey], metricKey, metricKey));
        });

        return wrap;
    }

    wrap.appendChild(buildRoomChartPanel(primary, primaryMetric, primaryMetric));

    const warn = document.createElement("div");
    warn.className = "history-chart-note";
    warn.textContent = "The " + (peerMetric === "rh" ? "humidity" : "temperature")
        + " chart is unavailable: that reading could not be loaded for this range.";
    wrap.appendChild(warn);

    return wrap;
}

/*
 * Export exactly what the table shows: same hours, same room columns, same
 * newest-first order, same timestamp rendering. "Reading at" is written with
 * the same formatter the table uses (a spread is shown as "min … max" rather
 * than silently collapsed), so every cell in the file matches the screen.
 */
function exportTempRh(data) {
    const rooms = data.rooms || [];
    const hours = data.hours || [];
    const unit = data.unit || (data.metric === "rh" ? "%RH" : "C");

    const columns = ["Hour"];

    rooms.forEach(r => columns.push(r.label + " (" + unit + ")"));

    columns.push("Reading at");

    const rows = hours.map(hour => {
        const row = [formatHour(hour.recorded_at)];

        rooms.forEach(room => {
            const v = (hour.values || {})[room.key];
            row.push(isMissingValue(v) ? null : Number(v));
        });

        const min = hour.read_at_min;
        const max = hour.read_at_max;
        let readAt = "";

        if (min && max) {
            readAt = min === max
                ? formatHour(min)
                : formatHour(min) + " … " + formatHour(max);
        }

        row.push(readAt);

        return row;
    });

    const bytes = buildXlsxWorkbook(
        "TEMP RH " + (data.metric === "rh" ? "HUMIDITY" : "TEMPERATURE"),
        columns,
        rows
    );

    downloadXlsx(exportFilename("temp_rh_" + (data.metric || "temp"), data.from, data.to), bytes);
}

function applyTempRhFilter(inputs, isReset) {
    historyFilters.TEMP_RH_HOURLY.metric = inputs.metric.value;
    historyFilters.TEMP_RH_HOURLY.from = isReset ? "" : inputs.from.value;
    historyFilters.TEMP_RH_HOURLY.to = isReset ? "" : inputs.to.value;
    safeLoadData();
}

/*
 * The ROOM page needs BOTH metrics at once — one chart each — but the endpoint
 * serves one metric per request, so the caller may ask for the other half
 * explicitly. Everything else about the query (the date range, the cache
 * buster) stays identical, so the two responses describe the same hours.
 */
function buildTempRhHourlyUrl(metricOverride) {
    const f = historyFilters.TEMP_RH_HOURLY;
    const metric = metricOverride || f.metric;
    let url = TEMP_RH_HOURLY_API_URL + "?metric=" + encodeURIComponent(metric);

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

    if (currentEquipment === "AHU_G8") {
        renderAhuEfG8();
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
     * AHU 94-95 are no longer placeholders: they now arrive as real units from
     * api_ahu.php (HNX G8 / Modbus TCP, hnx_g8_points + hnx_g8_current), so
     * they are rendered by the createCard() loop above. Keeping them here as
     * well would draw each of them twice.
     */
    const placeholderNumbers = [];

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
            /*
             * The table and the export follow the selected metric; the charts
             * show BOTH. The peer metric is fetched over the identical date
             * range so the two charts describe the same hours.
             *
             * The selected metric is fatal — it drives the table, so a failure
             * there is a real load failure. The peer is NOT: it feeds one chart,
             * and letting it blank the whole page would mean a single flaky
             * request costs the reader the table as well. It degrades to one
             * chart plus a note saying why.
             */
            const selectedMetric = historyFilters.TEMP_RH_HOURLY.metric;
            const peerMetric = selectedMetric === "rh" ? "temp" : "rh";

            allData.TEMP_RH_HOURLY = await loadHistoryPage(buildTempRhHourlyUrl(selectedMetric));

            try {
                allData.TEMP_RH_HOURLY_PEER = await loadHistoryPage(buildTempRhHourlyUrl(peerMetric));
            } catch (peerError) {
                allData.TEMP_RH_HOURLY_PEER = null;
                console.warn("HVAC peer metric unavailable:", peerError);
            }

            setConnection(true);
            renderRoomTempRh();
            lastUpdate.textContent = "Hourly history: " + (allData.TEMP_RH_HOURLY.from || "--")
                + " to " + (allData.TEMP_RH_HOURLY.to || "--");
            return;
        }

        const apiUrl = currentEquipment === "AHU" || currentEquipment === "AHU_G8"
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

        /*
         * AHU / EF G8 is the one page fed by TWO endpoints: its AHU cards come
         * from api_ahu.php (above) and its EF cards from api_ef.php. Fetching
         * the second one here rather than duplicating either unit's data into a
         * third endpoint keeps every unit on exactly one source of truth — the
         * same one its own page uses.
         *
         * If this second call fails the catch below marks the connection
         * offline, but the AHU cards fetched above are already in allData and
         * stay on screen: a half-drawn page beats a blank one.
         */
        if (currentEquipment === "AHU_G8") {
            const efResponse = await fetch("api_ef.php?t=" + Date.now(), {
                cache: "no-store"
            });

            if (!efResponse.ok) {
                throw new Error("HTTP " + efResponse.status);
            }

            const efData = await efResponse.json();

            if (!efData.success) {
                throw new Error("API returned success=false");
            }

            allData = Object.assign({}, allData, efData.equipment || {});
        }

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

        /*
         * The chart toggle is per-visit: leaving a history page closes its
         * chart, so returning to it shows the page as it was before. Without
         * this the panel would reappear unrequested on the next visit.
         */
        showChart = false;

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

/* =========================================================
   SHARED CELL FORMATTERS (table + chart + export)
   =========================================================

   The table, the chart's tooltip and the exported file all have to show the
   same number for the same reading. They therefore share these formatters
   rather than each formatting independently, so a value can never disagree
   with its own export.
   ========================================================= */

/*
 * formatNumber() renders 87 as "87.0", which is right for a table cell but
 * would turn a whole-number y-axis tick into "100.0". Ticks and tooltips drop
 * the decimal when the value is whole.
 */
function formatCompactNumber(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return "--";
    }

    const n = Number(value);

    return Number.isInteger(n) ? String(n) : formatNumber(n, 1);
}

/*
 * The x-axis label. ENERGY rows are days, so the date alone is the identity;
 * the hourly table keeps the hour. Shortened so 11 tick labels fit at 320px
 * without colliding.
 */
function formatAxisDate(value) {
    const s = String(value || "");

    return s.length >= 10 ? s.slice(5, 10) : s;
}

function formatAxisHour(value) {
    const s = String(value || "").replace("T", " ");

    return s.length >= 13 ? s.slice(11, 13) + ":00" : s;
}

/* Detect the honesty note behind a value: null means "no reading", not zero. */
function isMissingValue(value) {
    return value === null || value === undefined || value === "";
}

/* =========================================================
   HISTORY CHARTS
   =========================================================

   One canvas renderer serves both history pages. It is written by hand rather
   than with a library: the app loads no JS dependencies at all, and a single
   line chart does not justify introducing one (Chart.js alone is ~200KB).

   Every number plotted comes from the same `allData` payload the table is
   built from, through the same formatters, so the chart and the table can
   never disagree.

   Colour: the categorical slot-1 blue (#3987e5) for a single series, and the
   documented dark categorical order for the ENERGY multi-series view. A
   line's colour is keyed to its series identity (room key / unit number), not
   to its position in the array, so filtering never repaints the survivors.
   Validated against this panel's #161616 surface (see the task report).
   ========================================================= */

/* Documented dark categorical slots, in fixed order. Never cycled or generated. */
const HISTORY_SERIES_COLORS = [
    "#3987e5", "#d95926", "#199e70", "#c98500",
    "#d55181", "#008300", "#9085e9", "#e66767"
];

/*
 * ROOM TEMP & RH carries ten rooms, and the task requires every one of them as
 * a distinct line on ONE chart — so faceting is not available as the escape
 * hatch the eight-slot palette normally uses, and cycling would paint two rooms
 * the same colour.
 *
 * Slots 1-8 are the documented order above, unchanged. Slots 9-10 are DERIVED,
 * not from the documented palette: candidate hues were generated in OKLCH,
 * filtered to the dark lightness band (L 0.48-0.67), the chroma floor (C>=0.10)
 * and >=3:1 contrast on #161616, then scored with the same CVD model the
 * documented slots are validated against. The resulting ten-slot order passes
 * every adjacent-pair gate - worst CVD dE 8.4 (protan), worst normal-vision
 * dE 19.3. This deviation from the "documented palette only" rule is forced by
 * the task's ten-series, two-chart requirement and is reported as such.
 */
const ROOM_CHART_COLORS = HISTORY_SERIES_COLORS.concat(["#62891b", "#6274c1"]);

/*
 * Room -> colour slot, assigned on first sight and kept for the life of the
 * page. Colour follows the room's identity, not its position in the current
 * room list, so narrowing the date range never repaints the rooms that remain,
 * and the Temperature and Humidity charts always agree on a room's colour.
 *
 * Ten rooms fill ten slots exactly. An eleventh room would wrap onto slot 1,
 * which is why the count is asserted in the panel note rather than left
 * implicit.
 */
const ROOM_COLOR_SLOTS = new Map();

function roomChartColor(roomKey) {
    if (!ROOM_COLOR_SLOTS.has(roomKey)) {
        ROOM_COLOR_SLOTS.set(roomKey, ROOM_COLOR_SLOTS.size % ROOM_CHART_COLORS.length);
    }

    return ROOM_CHART_COLORS[ROOM_COLOR_SLOTS.get(roomKey)];
}

/*
 * ENERGY carries eleven CT channels, nine CCP and nine CHWP, and the task
 * requires every one of them as a distinct line on ONE combined chart — so the
 * eight documented slots cannot cover it and the usual "facet instead of
 * cycling" escape hatch is unavailable (facets are the Overview mode, not the
 * combined one).
 *
 * Slots 1-8 are the documented order, unchanged. Slots 9-11 are DERIVED, by the
 * same method as the ROOM extension above: candidates generated in OKLCH,
 * filtered to the dark lightness band (L 0.48-0.67), the chroma floor (C>=0.10)
 * and >=3:1 contrast on this panel's #161616 surface, then hill-climbed against
 * the same CVD model.
 *
 * Verified with the skill's validator on the real surface:
 *   node scripts/validate_palette.js "<11 slots>" --mode dark --surface "#161616"
 *   -> ALL CHECKS PASS, no warnings. Worst adjacent pair CVD dE 8.4 (protan),
 *      worst adjacent normal-vision dE 19.3.
 *
 * An earlier search reported that no clone-free eleven-hue set existed. That was
 * a sampling artefact, not a real ceiling: a hill-climb that trades adjacent
 * headroom against base-spread finds this trio, whose worst distance to ANY
 * other slot is 16.3 - comfortably clear of a near-clone.
 */
const ENERGY_CHART_COLORS = HISTORY_SERIES_COLORS.concat(["#7136f5", "#835c00", "#c91fe8"]);

/*
 * Unit -> colour slot.
 *
 * Keyed by the unit's POSITION in the category's full inventory, not by the
 * unit number and not by the order it appears in a chart. Three reasons:
 *
 *  - Unit numbers are not contiguous. CT is 1-6 then 9-13; indexing by number
 *    would run off the end of an eleven-slot palette and wrap CT 12 onto CT 1's
 *    colour, which is exactly the "two channels, one hue" failure the extended
 *    palette exists to prevent.
 *  - The inventory order is stable, so a channel's colour survives a legend
 *    hide/show and a date-range change. An insertion-ordered map would hand out
 *    a fresh slot on every rebuild and repaint the lines still on screen.
 *  - Each category numbers its own units from its own inventory, so CT 1, CCP 1
 *    and CHWP 1 all take slot 1 — the same convention the table's columns use,
 *    where "CT 1" and "CCP 1" are both the first column.
 *
 * `units` is the category's FULL unit list from the API, never the subset being
 * charted, so narrowing the view never shifts a colour.
 */
function energyChartColor(units, unit) {
    const i = units.findIndex(u => String(u.unit) === String(unit));

    return ENERGY_CHART_COLORS[(i < 0 ? 0 : i) % ENERGY_CHART_COLORS.length];
}

/*
 * Fixed, exact thresholds for the two ROOM charts. These are annotation only:
 * they colour nothing, alarm nothing and classify nothing — the panel merely
 * draws a line where the value is reached.
 */
const ROOM_CHART_REFERENCE = {
    temp: { value: 30, label: "30 °C" },
    rh:   { value: 65, label: "65 %RH" }
};

/*
 * Readable y-axes for the two ROOM charts.
 *
 * The band follows the readings currently in view. A fixed instrument range
 * reads well on paper but badly on a wall display: pinning 0-50 °C squeezes a
 * 26-36 °C day into the bottom fifth of the plot, and a 45-90 %RH day into the
 * middle half, so the lines flatten and the day-to-day shape — the thing the
 * chart exists to show — disappears.
 *
 * So the band is derived per draw: the data range (plus any reference line, so
 * the threshold stays on screen) is padded, then snapped outward to whole
 * multiples of the tick step. Snapping outward is what guarantees the axis
 * never clips an outlier — the bounds can only move away from the data, never
 * into it — and the padding is what keeps the extreme lines off the frame.
 *
 * `preferred` lists the steps that read best for each metric (2 or 5 °C,
 * 5 or 10 %RH). They are a preference, not a rule: a preferred step is used
 * only when it leaves a legible number of intervals, so a narrow filter falls
 * back to a finer step rather than collapsing to one or two giant intervals.
 * `pad` is a fraction of the data span, and `target` is the interval count the
 * step search aims for.
 */
const ROOM_CHART_AXIS = {
    temp: { pad: 0.06, target: 6, preferred: [2, 5] },
    rh:   { pad: 0.06, target: 6, preferred: [5, 10] }
};

const HISTORY_CHART = {
    gridline: "#2c2c2a",
    axis: "#383835",
    muted: "#898781",
    text: "#c3c2b7",
    /*
     * The reference line. The panel's primary ink, chosen by measurement over
     * the app's other ink and status colours: it separates from all ten room
     * colours (worst all-pairs dE 28.0 under CVD, 31.4 normal) and clears the
     * 15.7:1 contrast mark on #161616. The muted grey and the status reds
     * COLLIDE with room hues under CVD (dE 1.1 and 1.3) and are not usable here.
     */
    reference: "#f0efec"
};

/* Pad the x span so the first/last marker is not clipped by the plot edge. */
const HISTORY_X_PAD = 10;
const HISTORY_TOP_PAD = 10;
const HISTORY_BOTTOM_PAD = 22;
const HISTORY_LEFT_PAD = 54;
const HISTORY_RIGHT_PAD = 14;

/*
 * Facets are drawn a third of the width, so the y-axis gutter is trimmed and
 * only a few ticks are shown — at that size a full tick column would be wider
 * than the plot it labels.
 */
const HISTORY_FACET_LEFT_PAD = 38;

/*
 * The readable band for y-axis intervals. Below five the grid is coarse enough
 * that a reader cannot interpolate a value between the lines; above eight the
 * labels crowd and the chart turns into a ladder. The general step search only
 * considers steps that land inside this band, which is what stops a narrow date
 * filter from collapsing to two or three huge intervals.
 *
 * A step the caller explicitly prefers gets a wider band, so a 20 °C span can
 * still take the preferred 5 °C step (four intervals) rather than being pushed
 * to an unpreferred 2.5 °C just to reach five. Four intervals is still legible;
 * the lower bound here exists only to reject a preferred step that would leave
 * the axis nearly unlabelled.
 */
const HISTORY_TICK_MIN = 5;
const HISTORY_TICK_MAX = 8;
const HISTORY_PREFERRED_TICK_MIN = 4;
const HISTORY_PREFERRED_TICK_MAX = 10;

/*
 * Normalise `options.referenceLines` into a list of { value, label }.
 *
 * Optional everywhere: ENERGY never passes it, so its charts are untouched.
 * Anything that is not a finite number is dropped rather than coerced, so a
 * bad caller cannot silently draw a line at 0.
 */
function historyReferenceLines(opts) {
    const raw = opts && opts.referenceLines;

    if (!Array.isArray(raw)) return [];

    return raw
        .filter(r => r && Number.isFinite(Number(r.value)))
        .map(r => ({
            value: Number(r.value),
            label: r.label === undefined ? String(r.value) : String(r.label)
        }));
}

function historyChartGeometry(canvas, series, options) {
    const opts = options || {};
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    const w = Math.max(160, Math.round(rect.width));
    const h = Math.max(90, Math.round(rect.height));

    /* Backing store at device resolution; CSS size stays as laid out. */
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);

    const ctx = canvas.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    const plot = {
        left: opts.compact ? HISTORY_FACET_LEFT_PAD : HISTORY_LEFT_PAD,
        right: w - HISTORY_RIGHT_PAD,
        top: HISTORY_TOP_PAD,
        /*
         * A facet still draws its first/last x labels, so it needs the same
         * label band as a full chart — trimming it here clips the text.
         */
        bottom: h - HISTORY_BOTTOM_PAD
    };

    let min = Infinity;
    let max = -Infinity;

    series.forEach(s => s.points.forEach(p => {
        if (p.value === null || p.value === undefined) return;
        if (p.value < min) min = p.value;
        if (p.value > max) max = p.value;
    }));

    if (!Number.isFinite(min)) {
        min = 0;
        max = 1;
    }

    /*
     * A shared scale across facets is what makes them comparable, so the
     * caller can pin the range. Only fall back to the local range when it
     * was not supplied.
     */
    if (Number.isFinite(opts.yMin)) min = opts.yMin;
    if (Number.isFinite(opts.yMax)) max = opts.yMax;

    /*
     * A reference line has to be on screen to be a reference. When a narrow
     * date range leaves the threshold outside the data band, the band is
     * widened to reach it rather than letting the line vanish off-plot —
     * an invisible 30 °C line would read as "no threshold here". Only an
     * explicitly pinned range is left alone.
     *
     * A pinned range also means the band is the caller's fixed scale, and that
     * scale must not depend on whether a reference line was passed: widening it
     * would hand the same chart two different grids, one per call site.
     */
    const pinnedRange = Number.isFinite(opts.yMin) || Number.isFinite(opts.yMax);

    if (!pinnedRange) {
        historyReferenceLines(opts).forEach(ref => {
            if (ref.value < min) min = ref.value;
            if (ref.value > max) max = ref.value;
        });
    }

    if (min === max) {
        /* A flat series still needs a band, or every point sits on the edge. */
        min -= 1;
        max += 1;
    }

    /*
     * Headroom so the extreme line never rides the frame, and an outward snap
     * of the bounds to whole tick steps. Both are opt-in: ENERGY does not ask
     * for them and must keep the band it has always drawn, so a caller that
     * passes neither `autoScale` nor `padFraction` sees the plain data range.
     *
     * The padding is a fraction of the span (not a fixed amount), so the
     * proportion is the same whether the readings span 4 °C or 40. A flat
     * series — min and max already separated above — still pads, because the
     * span it is given is the widened one, not zero.
     */
    const autoScale = opts.autoScale === true || Number.isFinite(opts.padFraction);
    let step = Number.isFinite(opts.yStep) && opts.yStep > 0 ? opts.yStep : null;

    if (!pinnedRange && autoScale) {
        const frac = Number.isFinite(opts.padFraction) ? opts.padFraction : 0;
        /*
         * The flat-series case was already widened above, so the span here is
         * never zero and the padding is always a real fraction of it.
         */
        const pad = frac > 0 ? (max - min) * frac : 0;

        if (pad > 0) {
            min -= pad;
            max += pad;
        }

        /*
         * A quantity that cannot be negative must not be given a negative axis.
         *
         * Daily kWh usage is always >= 0, but the padding above is symmetric, so
         * a channel whose minimum is 0 gets its band pushed below zero — and
         * since the snap then rounds OUTWARD to a whole step, a 0-858 channel
         * with a 200 step lands on -200..1000. That draws negative energy on the
         * axis and wastes a fifth of the plot on a range that cannot occur.
         *
         * The floor is applied BEFORE the step is chosen and the bounds snapped,
         * so 0 stays an exact multiple of the step and the top gridline still
         * lands on the frame. Clamping after the snap would break that.
         */
        if (opts.nonNegative === true) {
            if (min < 0) min = 0;
            if (max <= min) max = min + 1;
        }

        /*
         * Snap the padded band outward to whole multiples of the tick step, so
         * the top and bottom gridlines land exactly on the plot frame instead
         * of floating just inside it. Outward only: the bounds can move away
         * from the data but never into it, which is what guarantees no outlier
         * is clipped even after rounding. The step is decided here rather than
         * in the tick builder so the bounds and the ticks cannot disagree.
         */
        step = historyAxisStep(max - min, opts.preferredSteps, opts.tickTarget);

        if (step > 0) {
            min = Math.floor(min / step + 1e-9) * step;
            max = Math.ceil(max / step - 1e-9) * step;
        }
    }

    const valueSpan = max - min;
    const pointCount = series.length ? series[0].points.length : 1;

    /*
     * A single point has no span to interpolate across, so it is centred
     * rather than left sitting on the y-axis.
     */
    const xAt = i => pointCount <= 1
        ? (plot.left + plot.right) / 2
        : plot.left + (plot.right - plot.left) * (i / (pointCount - 1));
    const yAt = v => plot.bottom - (plot.bottom - plot.top) * ((v - min) / valueSpan);

    return { ctx, w, h, plot, min, max, xAt, yAt, pointCount, dpr, step };
}

/*
 * Pick the y-axis interval for a band, preferring the steps that read best for
 * the metric (2/5 °C, 5/10 %RH).
 *
 * A preferred step is accepted over a wider interval count than the general
 * ladder, because "prefer 2 or 5 °C" is the stronger instruction: a 20 °C span
 * is better served by 5 °C (four intervals) than by a fallback 2.5 °C, even
 * though four is under the five-interval aim. Only when no preferred step is
 * reasonable at all — a narrow filter, where every preferred step collapses to
 * two or three intervals — does the search widen to the full 1/2/5 ladder, and
 * there the stricter five-to-eight band applies. Either way the count is
 * bounded, so the axis can never come out crowded.
 */
function historyAxisStep(span, preferred, target) {
    const goal = Math.max(2, Number.isFinite(target) ? target : 6);
    const mag = Math.pow(10, Math.floor(Math.log10(span / goal)));
    const band = (n, lo, hi) => n >= lo && n <= hi;
    /* Nearest to the target count wins; ties break toward the finer step. */
    const better = (a, b) => b === null
        || Math.abs(a.n - goal) < Math.abs(b.n - goal)
        || (Math.abs(a.n - goal) === Math.abs(b.n - goal) && a.step < b.step);

    let best = null;

    (Array.isArray(preferred) ? preferred : []).forEach(step => {
        if (!(step > 0)) return;
        const n = Math.round(span / step);
        if (band(n, HISTORY_PREFERRED_TICK_MIN, HISTORY_PREFERRED_TICK_MAX)
            && better({ step, n }, best)) {
            best = { step, n };
        }
    });

    if (best) return best.step;

    [0.5, 1, 2, 2.5, 5, 10, 20].forEach(m => {
        const step = m * mag;
        const n = Math.round(span / step);
        if (band(n, HISTORY_TICK_MIN, HISTORY_TICK_MAX) && better({ step, n }, best)) {
            best = { step, n };
        }
    });

    /* Nothing lands in the band (a very wide or very narrow span): take the
     * magnitude that comes closest rather than leaving the axis unlabelled. */
    if (!best) {
        const step = mag > 0 ? mag : span / goal;
        return step > 0 ? step : span;
    }

    return best.step;
}

/*
 * Axis ticks rounded to clean numbers.
 *
 * The step is chosen by how many ticks actually land inside the data range,
 * not by the span alone. A span-derived step can fall almost entirely outside
 * the range — a 25.6-37.8 band with a step of 10 leaves a single usable tick —
 * which is unreadable, so every candidate step is tried and the one closest to
 * the requested count (but never fewer than two) wins. Ticks are then built
 * from the first one at or above `min`, so none are drawn outside the plot.
 */
function historyAxisTicks(min, max, target, step) {
    const span = max - min;

    if (!(span > 0)) return [min];

    /*
     * A caller can pin the step, for an axis that must read the same on every
     * date range and every screen. Ticks are still built from the first
     * multiple at or above `min`, so a pinned step can never place a tick
     * outside the plot — the same guarantee the derived path below gives.
     */
    if (Number.isFinite(step) && step > 0) {
        const first = Math.ceil(min / step - 1e-9) * step;
        const ticks = [];

        for (let v = first; v <= max + 1e-9; v += step) {
            ticks.push(Number(v.toFixed(10)));
        }

        return ticks.length ? ticks : [min];
    }

    const goal = Math.max(2, target);
    const mag = Math.pow(10, Math.floor(Math.log10(span / goal)));

    let best = null;

    [0.5, 1, 2, 2.5, 5, 10, 20].forEach(m => {
        const step = m * mag;
        const first = Math.ceil(min / step) * step;
        const count = Math.floor((max - first) / step + 1e-9) + 1;

        if (count < 2) return;

        const score = Math.abs(count - goal);

        if (best === null || score < best.score) best = { step, first, count, score };
    });

    /* Too narrow for any step to fit twice: label the ends rather than nothing. */
    if (!best) return [min, max];

    const ticks = [];

    for (let i = 0; i < best.count; i++) {
        ticks.push(Number((best.first + i * best.step).toFixed(10)));
    }

    return ticks;
}

/*
 * Draw one chart. `series` is [{ name, color, points: [{ label, value, full }] }].
 *
 * A point whose value is null leaves a real gap in the line — the geometry
 * simply skips it — so a missing reading reads as missing rather than as zero
 * at the baseline.
 */
function drawHistoryChart(canvas, series, options) {
    const opts = options || {};
    const unit = opts.unit || "";
    const visible = series.filter(s => s.points.some(p => !isMissingValue(p.value)));

    if (!visible.length) {
        return null;
    }

    const geo = historyChartGeometry(canvas, visible, opts);
    const { ctx, plot, min, max, xAt, yAt, pointCount } = geo;

    canvas.dataset.chartMin = String(min);
    canvas.dataset.chartMax = String(max);

    /*
     * The step comes back from the geometry, which chose it to snap the bounds.
     * A facet is drawn a third of the width, so its labels are given more room
     * by asking for fewer intervals; the step preference is unchanged. An
     * explicit `tickTarget` overrides that default — a facet that carries a real
     * axis needs real ticks, and two is what left the ENERGY plots labelled only
     * at the bottom. When the geometry pinned a step, that step governs and this
     * count is not consulted at all.
     */
    const tickTarget = Number.isFinite(opts.tickTarget)
        ? opts.tickTarget
        : (opts.compact ? 2 : 5);
    const ticks = historyAxisTicks(min, max, tickTarget, geo.step || opts.yStep);

    /* Recessive chart chrome: solid hairlines one step off the surface. */
    ctx.lineWidth = 1;
    ctx.strokeStyle = HISTORY_CHART.gridline;
    ctx.fillStyle = HISTORY_CHART.muted;
    ctx.font = "10px Arial, Helvetica, sans-serif";
    ctx.textAlign = "right";
    ctx.textBaseline = "middle";

    ticks.forEach(t => {
        const y = Math.round(yAt(t)) + 0.5;
        ctx.beginPath();
        ctx.moveTo(plot.left, y);
        ctx.lineTo(plot.right, y);
        ctx.stroke();
        ctx.fillText(formatCompactNumber(t), plot.left - 6, y);
    });

    /* Baseline is a step stronger than the gridlines. */
    ctx.strokeStyle = HISTORY_CHART.axis;
    ctx.beginPath();
    ctx.moveTo(plot.left, Math.round(plot.bottom) + 0.5);
    ctx.lineTo(plot.right, Math.round(plot.bottom) + 0.5);
    ctx.stroke();

    /*
     * X labels: thinned so they never collide on a narrow screen. A facet
     * shows the first and last tick only — its title carries the identity.
     */
    ctx.textAlign = "center";
    ctx.textBaseline = "top";
    ctx.fillStyle = HISTORY_CHART.muted;

    if (opts.hideAxisLabels) {
        if (pointCount > 0) {
            const first = opts.axisLabel
                ? opts.axisLabel(visible[0].points[0].label)
                : visible[0].points[0].label;
            const last = opts.axisLabel
                ? opts.axisLabel(visible[0].points[pointCount - 1].label)
                : visible[0].points[pointCount - 1].label;
            ctx.textAlign = "left";
            ctx.fillText(first, plot.left, plot.bottom + 5);
            ctx.textAlign = "right";
            ctx.fillText(last, plot.right, plot.bottom + 5);
        }
    } else {
        const stepX = Math.max(1, Math.ceil(pointCount / Math.max(2, Math.floor(geo.w / 78))));

        visible[0].points.forEach((p, i) => {
            if (i % stepX !== 0 && i !== pointCount - 1) return;
            const label = opts.axisLabel ? opts.axisLabel(p.label) : p.label;
            ctx.fillText(label, xAt(i), plot.bottom + 6);
        });
    }

    /* Series, thinnest first so the loudest line ends on top. */
    visible.forEach(s => {
        ctx.strokeStyle = s.color;
        ctx.lineWidth = opts.compact ? 1.5 : 2;
        ctx.lineJoin = "round";
        ctx.lineCap = "round";
        ctx.beginPath();

        let open = false;

        s.points.forEach((p, i) => {
            if (isMissingValue(p.value)) {
                open = false;
                return;
            }

            const x = xAt(i);
            const y = yAt(p.value);

            if (open) {
                ctx.lineTo(x, y);
            } else {
                ctx.moveTo(x, y);
                open = true;
            }
        });

        ctx.stroke();
    });

    /*
     * End markers: one per series, never one per point. A 2px surface ring
     * keeps them legible where lines cross.
     */
    visible.forEach(s => {
        for (let i = s.points.length - 1; i >= 0; i--) {
            if (isMissingValue(s.points[i].value)) continue;

            const x = xAt(i);
            const y = yAt(s.points[i].value);

            ctx.beginPath();
            ctx.arc(x, y, opts.compact ? 3 : 4, 0, Math.PI * 2);
            ctx.fillStyle = s.color;
            ctx.fill();
            ctx.lineWidth = 2;
            ctx.strokeStyle = "#161616";
            ctx.stroke();
            break;
        }
    });

    /*
     * Reference lines are drawn AFTER the series and dashed on purpose.
     *
     * Underneath ten lines a solid rule would simply be painted over wherever
     * it matters, so it goes on top; and it is dashed rather than solid so a
     * room crossing the threshold stays visible through the gaps. The dash is
     * what separates it from the gridlines, which are solid and recessive —
     * dashing a gridline would be noise, dashing a real threshold is the
     * signal.
     */
    historyReferenceLines(opts).forEach(ref => {
        /* Skip a threshold that the plot band does not actually reach. */
        if (ref.value < min || ref.value > max) return;

        const y = Math.round(yAt(ref.value)) + 0.5;

        ctx.save();
        ctx.setLineDash([6, 4]);
        ctx.lineWidth = 1.5;
        ctx.strokeStyle = HISTORY_CHART.reference;
        ctx.beginPath();
        ctx.moveTo(plot.left, y);
        ctx.lineTo(plot.right, y);
        ctx.stroke();
        ctx.restore();

        /*
         * A label chip, so the value is read off the chart, not guessed.
         *
         * It sits at the LEFT edge, just inside the plot. The right edge is
         * where every series carries its end marker, and a 14px chip there
         * would cover one; the left edge is only line starts.
         */
        ctx.font = "10px Arial, Helvetica, sans-serif";
        ctx.textBaseline = "middle";
        ctx.textAlign = "left";

        const text = ref.label;
        const tw = ctx.measureText(text).width;
        const bx = plot.left + 3;
        const by = y - 7;

        ctx.fillStyle = HISTORY_CHART.reference;
        ctx.fillRect(bx, by, tw + 8, 14);

        ctx.fillStyle = "#161616";
        ctx.fillText(text, bx + 4, y + 0.5);
    });

    /* Y-axis unit caption, so the scale is never ambiguous. */
    if (unit && !opts.compact) {
        ctx.save();
        ctx.translate(12, (plot.top + plot.bottom) / 2);
        ctx.rotate(-Math.PI / 2);
        ctx.textAlign = "center";
        ctx.textBaseline = "top";
        ctx.fillStyle = HISTORY_CHART.muted;
        ctx.font = "10px Arial, Helvetica, sans-serif";
        ctx.fillText(unit, 0, 0);
        ctx.restore();
    }

    return geo;
}

/*
 * Tooltip. A crosshair picks the nearest x index rather than requiring the
 * pointer to land on a 4px dot, so the hit target is a whole column.
 */
function attachHistoryChartHover(canvas, series, options) {
    const geoState = canvas.__historyGeo;
    if (!geoState) return;

    const opts = options || {};
    const visible = series.filter(s => s.points.some(p => !isMissingValue(p.value)));

    if (!visible.length) return;

    let tip = canvas.parentElement.querySelector(".history-chart-tooltip");

    if (!tip) {
        tip = document.createElement("div");
        tip.className = "history-chart-tooltip";
        canvas.parentElement.appendChild(tip);
    }

    const move = ev => {
        const rect = canvas.getBoundingClientRect();
        const x = ev.clientX - rect.left;
        const points = visible[0].points;
        const span = geoState.plot.right - geoState.plot.left;

        let idx = 0;

        if (points.length > 1 && span > 0) {
            idx = Math.round((x - geoState.plot.left) / span * (points.length - 1));
            idx = Math.max(0, Math.min(points.length - 1, idx));
        }

        const rows = visible.map(s => {
            const p = s.points[idx] || {};
            return {
                name: s.name,
                color: s.color,
                value: formatCompactNumber(p.value),
                missing: isMissingValue(p.value)
            };
        });

        const head = opts.tipTitle
            ? opts.tipTitle(points[idx] ? points[idx].label : "")
            : (points[idx] ? points[idx].label : "");

        tip.innerHTML = "";

        const h = document.createElement("div");
        h.className = "history-chart-tip-head";
        h.textContent = head + (opts.unit ? "  (" + opts.unit + ")" : "");
        tip.appendChild(h);

        rows.forEach(r => {
            const line = document.createElement("div");
            line.className = "history-chart-tip-row";

            const sw = document.createElement("span");
            sw.className = "history-chart-swatch";
            sw.style.background = r.missing ? "transparent" : r.color;
            if (r.missing) sw.style.border = "1px solid " + HISTORY_CHART.muted;
            line.appendChild(sw);

            const nm = document.createElement("span");
            nm.className = "history-chart-tip-name";
            nm.textContent = r.name;
            line.appendChild(nm);

            const val = document.createElement("span");
            val.className = "history-chart-tip-value";
            val.textContent = r.missing ? "no reading" : r.value;
            if (r.missing) val.style.color = HISTORY_CHART.muted;
            line.appendChild(val);

            tip.appendChild(line);
        });

        tip.style.display = "block";

        /* Flip the tip to the other side of the crosshair near the right edge. */
        const tipW = tip.offsetWidth;
        const px = geoState.xAt(idx);
        tip.style.left = (px + 14 + tipW > rect.width ? px - 14 - tipW : px + 14) + "px";
        tip.style.top = "10px";
    };

    const leave = () => { tip.style.display = "none"; };

    /*
     * Replace rather than accumulate. A panel can now be redrawn without being
     * rebuilt - the ENERGY legend toggles a series on and off in place - and
     * stacking a second listener per redraw would leave stale closures holding
     * a superseded geometry, so a hover could report the previous filter's
     * values.
     */
    if (canvas.__historyHover) {
        canvas.removeEventListener("mousemove", canvas.__historyHover.move);
        canvas.removeEventListener("mouseleave", canvas.__historyHover.leave);
    }

    canvas.__historyHover = { move, leave };

    canvas.addEventListener("mousemove", move);
    canvas.addEventListener("mouseleave", leave);
}

/* Tracked so a re-render replaces the previous resize handler instead of
   stacking a new listener on every chart draw. */
let historyChartResizeEntries = [];
let historyChartResizePruneQueued = false;
let historyChartResizeHandler = null;
let historyChartResizeTimer = null;

/*
 * Build the chart panel. The caller supplies the series and the labels; this
 * owns only the DOM shell, so both pages look identical.
 */
function buildHistoryChartPanel(config) {
    const panel = document.createElement("div");
    panel.className = "history-chart-panel";

    if (config.panelId) {
        panel.dataset.chartPanel = config.panelId;
    }

    const head = document.createElement("div");
    head.className = "history-chart-head";

    const title = document.createElement("div");
    title.className = "history-chart-title";
    title.textContent = config.title;
    head.appendChild(title);

    const meta = document.createElement("div");
    meta.className = "history-chart-meta";
    meta.textContent = config.meta || "";
    head.appendChild(meta);

    panel.appendChild(head);

    const hasData = config.series.some(s => s.points.some(p => !isMissingValue(p.value)));

    if (!hasData) {
        const empty = document.createElement("div");
        empty.className = "history-chart-empty";
        empty.textContent = config.emptyText || "No chartable values for this filter.";
        panel.appendChild(empty);
        return panel;
    }

    /*
     * Series are drawn in the documented order and never cycled. Past the
     * eight slots the chart does not generate a ninth hue — it facets into
     * small multiples, one panel per series, each keeping its own slot colour.
     * That is also the honest choice for this data: eleven lines on one plot
     * would overlap into an unreadable band.
     *
     * `config.combine` opts out of that fallback. The ROOM page sets it because
     * its task requires exactly two charts with all ten rooms combined, and it
     * supplies its own validated ten-slot palette to make that legible. ENERGY
     * does not set it, so its faceting behaviour is unchanged.
     */
    const visible = config.series.filter(s => s.points.some(p => !isMissingValue(p.value)));

    if (config.forceFacets || (!config.combine && visible.length > HISTORY_SERIES_COLORS.length)) {
        return buildHistoryFacets(panel, config, visible);
    }

    const canvasWrap = document.createElement("div");
    canvasWrap.className = "history-chart-canvas-wrap";

    const canvas = document.createElement("canvas");
    canvasWrap.appendChild(canvas);
    panel.appendChild(canvasWrap);

    /*
     * A legend is shown only for two or more series. One series needs no
     * legend box — the panel title already names what is plotted.
     *
     * When `config.legendToggle` is set the legend becomes the series control:
     * clicking an entry hides or shows that one line, and the axis is re-derived
     * from whatever remains on screen. The flag is opt-in because this panel is
     * shared with ROOM TEMP & RH, whose legend stays exactly as it was.
     *
     * The hidden set is keyed by series NAME, not index: the same panel is
     * rebuilt on every filter change and the series array can be reordered or
     * shortened, and an index-keyed set would hide a different channel after a
     * re-render.
     */
    const hidden = new Set();

    if (visible.length >= 2) {
        const legend = document.createElement("div");
        legend.className = "history-chart-legend";

        visible.forEach(s => {
            const item = document.createElement("span");
            item.className = "history-chart-legend-item";

            const sw = document.createElement("span");
            sw.className = "history-chart-swatch";
            sw.style.background = s.color;
            item.appendChild(sw);

            const txt = document.createElement("span");
            txt.textContent = s.name;
            item.appendChild(txt);

            if (config.legendToggle) {
                item.classList.add("is-toggleable");
                item.setAttribute("role", "button");
                item.setAttribute("tabindex", "0");

                const itemKey = s.name;

                const toggle = () => {
                    /*
                     * Never hide the last line. An empty plot with a live legend
                     * reads as "this filter has no data", which is a different
                     * and false statement — so the final visible series stays.
                     */
                    if (hidden.has(itemKey)) {
                        hidden.delete(itemKey);
                    } else if (visible.length - hidden.size > 1) {
                        hidden.add(itemKey);
                    }

                    item.classList.toggle("is-hidden", hidden.has(itemKey));
                    item.setAttribute("aria-pressed", hidden.has(itemKey) ? "false" : "true");
                    redraw();
                };

                item.setAttribute("aria-pressed", "true");
                item.addEventListener("click", toggle);
                item.addEventListener("keydown", ev => {
                    if (ev.key === "Enter" || ev.key === " ") {
                        ev.preventDefault();
                        toggle();
                    }
                });
            }

            legend.appendChild(item);
        });

        panel.appendChild(legend);
    }

    if (config.note) {
        const note = document.createElement("div");
        note.className = "history-chart-note";
        note.textContent = config.note;
        panel.appendChild(note);
    }

    const canvases = [canvas];

    /*
     * Draw after layout so the canvas has a real width. getBoundingClientRect
     * inside a 0-height parent would otherwise size the plot to nothing.
     *
     * `redraw` is what the legend calls: it re-derives the plot from the series
     * still shown, so hiding a line rescales the axis to the lines that remain
     * instead of leaving them squashed into the corner of a range set by a line
     * no longer on screen. It is deliberately the same function the resize path
     * uses, so both go through one code path.
     */
    const redraw = () => {
        const shown = config.series.filter(s => !hidden.has(s.name));
        const geo = drawHistoryChart(canvas, shown, config.options || {});
        canvas.__historyGeo = geo;
        if (geo) attachHistoryChartHover(canvas, shown, config.options || {});
    };

    requestAnimationFrame(redraw);
    registerHistoryChartResize(canvases, redraw);

    return panel;
}

/*
 * Small multiples for a page with more series than the palette carries. Each
 * facet is its own canvas with its own title, all sharing ONE y-scale so the
 * panels stay comparable — separate scales would make a flat room look as
 * volatile as a swinging one.
 *
 * The shared band is normally computed here from the visible series. A caller
 * may instead pin it with `config.facetRange`, which ENERGY's Overview mode does
 * so the band can start at 0 (usage is a quantity, so a zero base is what makes
 * the panels comparable at all). The pinned range is also the natural place to
 * put that zero base: a facet is a fixed 116px tall, so a zero-based band on a
 * per-facet scale would collapse an all-zero channel to a degenerate 0-1 axis
 * and stack its ticks on top of each other.
 */
function buildHistoryFacets(panel, config, visible) {
    let yMin = Infinity;
    let yMax = -Infinity;

    if (config.facetRange && Number.isFinite(config.facetRange.min)
        && Number.isFinite(config.facetRange.max)) {
        yMin = config.facetRange.min;
        yMax = config.facetRange.max;
    } else {
        visible.forEach(s => s.points.forEach(p => {
            if (isMissingValue(p.value)) return;
            if (p.value < yMin) yMin = p.value;
            if (p.value > yMax) yMax = p.value;
        }));

        if (!Number.isFinite(yMin)) { yMin = 0; yMax = 1; }
        if (yMin === yMax) { yMin -= 1; yMax += 1; }
    }

    const grid = document.createElement("div");
    grid.className = "history-chart-facets";

    /*
     * A fixed column count, when asked for, instead of the stylesheet's
     * auto-fit. Eleven CT channels land as four tidy rows of three rather than
     * a ragged last row, which keeps the panels the same width as each other.
     */
    if (Number.isFinite(config.facetColumns) && config.facetColumns > 0) {
        grid.style.gridTemplateColumns = "repeat(" + config.facetColumns + ", minmax(0, 1fr))";
    }

    const canvases = [];
    const renders = [];

    visible.forEach(s => {
        const facet = document.createElement("div");
        facet.className = "history-chart-facet";

        const name = document.createElement("div");
        name.className = "history-chart-facet-name";
        name.textContent = s.name;
        facet.appendChild(name);

        const wrap = document.createElement("div");
        wrap.className = "history-chart-canvas-wrap is-facet";

        const canvas = document.createElement("canvas");
        wrap.appendChild(canvas);
        facet.appendChild(wrap);

        grid.appendChild(facet);

        canvases.push(canvas);

        renders.push(() => {
            const opts = Object.assign({}, config.options || {}, {
                compact: true,
                hideAxisLabels: true,
                /*
                 * The facet path normally asks for two ticks. That is what left
                 * every ENERGY plot with only its bottom tick labelled — "0" and
                 * "100" across a 0-152 band, "0" and "500" across 0-888 — so the
                 * caller can raise the count, and Overview does.
                 */
                tickTarget: Number.isFinite(config.facetTickTarget)
                    ? config.facetTickTarget
                    : 2,
                /* The shared band, pinned so every facet reads the same scale. */
                yMin: yMin,
                yMax: yMax
            });

            const geo = drawHistoryChart(canvas, [s], opts);
            canvas.__historyGeo = geo;
            if (geo) attachHistoryChartHover(canvas, [s], opts);
        });
    });

    panel.appendChild(grid);

    if (config.note) {
        const note = document.createElement("div");
        note.className = "history-chart-note";
        note.textContent = config.note;
        panel.appendChild(note);
    }

    const render = () => renders.forEach(fn => fn());

    requestAnimationFrame(render);
    registerHistoryChartResize(canvases, render);

    return panel;
}

/*
 * One resize listener for the whole page, shared by every chart panel.
 *
 * A page can now carry more than one panel (ROOM TEMP & RH draws two), and
 * each panel registers as it is built. Replacing the handler outright — as a
 * single-panel page could — would leave only the last panel redrawing on
 * resize, so registrations ACCUMULATE.
 *
 * Stale entries are pruned so the list does not grow across re-renders, but the
 * prune is deferred to the next frame. A panel registers while its canvas is
 * still detached — the caller appends the panel only after building it — so an
 * immediate prune would evict every panel of the current pass but the last,
 * which is exactly the bug it is meant to prevent.
 */
function registerHistoryChartResize(canvases, render) {
    historyChartResizeEntries.push({ canvases: canvases, render: render });

    if (!historyChartResizePruneQueued) {
        historyChartResizePruneQueued = true;

        requestAnimationFrame(() => {
            historyChartResizePruneQueued = false;
            historyChartResizeEntries = historyChartResizeEntries
                .filter(entry => entry.canvases.some(c => c.isConnected));
        });
    }

    if (historyChartResizeHandler) {
        window.removeEventListener("resize", historyChartResizeHandler);
    }

    historyChartResizeHandler = () => {
        clearTimeout(historyChartResizeTimer);
        historyChartResizeTimer = setTimeout(() => {
            /* Also skip anything detached, in case a prune has not run yet. */
            historyChartResizeEntries.forEach(entry => {
                if (entry.canvases.some(c => c.isConnected)) entry.render();
            });
        }, 150);
    };

    window.addEventListener("resize", historyChartResizeHandler);
}

/* =========================================================
   EXCEL EXPORT (.xlsx)
   =========================================================

   Writes a genuine OOXML workbook — a ZIP container holding the
   [Content_Types].xml, _rels/.rels, xl/workbook.xml, xl/styles.xml and
   xl/worksheets/sheet1.xml parts Excel actually reads. Not CSV or HTML
   renamed, and not the SpreadsheetML 2003 single-file XML this used to emit.

   Why it changed: SpreadsheetML 2003 is what Excel for Android refuses
   outright ("file format is not supported"), and desktop Excel only opened it
   behind a "the file format and extension don't match" warning. `.xlsx` is the
   format every Excel build opens without a warning, so the bytes now match the
   extension on Android as well as on desktop.

   The ZIP is STORE (method 0, no deflate): an uncompressed xlsx is valid and
   opens everywhere, and deflate would need an implementation. That keeps the
   export dependency-free — no library, no CDN, no build step — which is the
   constraint that forced SpreadsheetML in the first place.

   Number and NULL handling are unchanged from the SpreadsheetML version, so
   the exported data still matches the table exactly: numbers stay numbers and
   a NULL reading stays an EMPTY cell, never a 0. Exporting a gap as 0 would
   claim a measurement nobody took.
   ========================================================= */

function xmlEscape(value) {
    return String(value)
        /* XML 1.0 forbids most C0 controls outright; a stray one would make the
           part unparseable and the workbook unopenable. */
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&apos;");
}

/* ---------- ZIP container (store-only) ---------- */

const CRC32_TABLE = (() => {
    const table = new Uint32Array(256);

    for (let n = 0; n < 256; n++) {
        let c = n;

        for (let k = 0; k < 8; k++) {
            c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
        }

        table[n] = c >>> 0;
    }

    return table;
})();

function crc32(bytes) {
    let c = 0xFFFFFFFF;

    for (let i = 0; i < bytes.length; i++) {
        c = CRC32_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    }

    return (c ^ 0xFFFFFFFF) >>> 0;
}

/*
 * A ZIP archive with every entry stored uncompressed. `files` is a list of
 * { name, data } where data is a string (UTF-8 encoded here) or a Uint8Array.
 *
 * The modification time is the fixed DOS epoch 1980-01-01 rather than "now":
 * an export has no meaningful mtime, and a constant keeps two exports of the
 * same data byte-identical, which is what makes the output testable.
 */
function zipStore(files) {
    const encoder = new TextEncoder();
    const chunks = [];
    const central = [];
    let offset = 0;

    const dosTime = 0;
    const dosDate = 33; /* 1980-01-01 */

    files.forEach(file => {
        const nameBytes = encoder.encode(file.name);
        const data = typeof file.data === "string"
            ? encoder.encode(file.data)
            : file.data;
        const crc = crc32(data);

        const local = new Uint8Array(30 + nameBytes.length);
        const lv = new DataView(local.buffer);
        lv.setUint32(0, 0x04034B50, true);  /* local file header */
        lv.setUint16(4, 20, true);          /* version needed */
        lv.setUint16(6, 0x0800, true);      /* names are UTF-8 */
        lv.setUint16(8, 0, true);           /* method: store */
        lv.setUint16(10, dosTime, true);
        lv.setUint16(12, dosDate, true);
        lv.setUint32(14, crc, true);
        lv.setUint32(18, data.length, true);
        lv.setUint32(22, data.length, true);
        lv.setUint16(26, nameBytes.length, true);
        lv.setUint16(28, 0, true);          /* extra field length */
        local.set(nameBytes, 30);

        chunks.push(local, data);

        const cd = new Uint8Array(46 + nameBytes.length);
        const cv = new DataView(cd.buffer);
        cv.setUint32(0, 0x02014B50, true);  /* central directory header */
        cv.setUint16(4, 20, true);          /* version made by */
        cv.setUint16(6, 20, true);          /* version needed */
        cv.setUint16(8, 0x0800, true);
        cv.setUint16(10, 0, true);
        cv.setUint16(12, dosTime, true);
        cv.setUint16(14, dosDate, true);
        cv.setUint32(16, crc, true);
        cv.setUint32(20, data.length, true);
        cv.setUint32(24, data.length, true);
        cv.setUint16(28, nameBytes.length, true);
        cv.setUint16(30, 0, true);          /* extra */
        cv.setUint16(32, 0, true);          /* comment */
        cv.setUint16(34, 0, true);          /* disk number */
        cv.setUint16(36, 0, true);          /* internal attributes */
        cv.setUint32(38, 0, true);          /* external attributes */
        cv.setUint32(42, offset, true);     /* offset of local header */
        cd.set(nameBytes, 46);

        central.push(cd);
        offset += local.length + data.length;
    });

    const centralSize = central.reduce((n, c) => n + c.length, 0);

    const end = new Uint8Array(22);
    const ev = new DataView(end.buffer);
    ev.setUint32(0, 0x06054B50, true);      /* end of central directory */
    ev.setUint16(4, 0, true);
    ev.setUint16(6, 0, true);
    ev.setUint16(8, files.length, true);
    ev.setUint16(10, files.length, true);
    ev.setUint32(12, centralSize, true);
    ev.setUint32(16, offset, true);
    ev.setUint16(20, 0, true);              /* comment length */

    const out = new Uint8Array(offset + centralSize + end.length);
    let p = 0;

    chunks.forEach(c => { out.set(c, p); p += c.length; });
    central.forEach(c => { out.set(c, p); p += c.length; });
    out.set(end, p);

    return out;
}

/* ---------- OOXML parts ---------- */

/* A1, B1, ... Z1, AA1 — the reference each <c> must carry. */
function xlsxColumnName(index) {
    let name = "";
    let n = index + 1;

    while (n > 0) {
        const rem = (n - 1) % 26;
        name = String.fromCharCode(65 + rem) + name;
        n = Math.floor((n - 1) / 26);
    }

    return name;
}

/*
 * Excel rejects a sheet name containing []:*?/\ or longer than 31 characters,
 * so the caller's label is sanitised rather than trusted.
 */
function xlsxSheetName(name) {
    const clean = String(name).replace(/[\[\]:*?\/\\]/g, " ").trim();

    return (clean || "Sheet1").slice(0, 31);
}

function xlsxCell(ref, value, style) {
    const s = style ? ' s="' + style + '"' : "";

    if (value === null || value === undefined || value === "") {
        return '<c r="' + ref + '"' + s + '/>';
    }

    if (typeof value === "number" && Number.isFinite(value)) {
        return '<c r="' + ref + '"' + s + '><v>' + value + '</v></c>';
    }

    /* Inline strings avoid a sharedStrings part entirely, which is one fewer
       thing to get wrong, and Excel reads them identically. */
    return '<c r="' + ref + '"' + s + ' t="inlineStr"><is><t xml:space="preserve">'
        + xmlEscape(value) + '</t></is></c>';
}

function xlsxWorksheetXml(columns, rows) {
    const header = columns
        .map((v, c) => xlsxCell(xlsxColumnName(c) + "1", v, 1))
        .join("");

    const body = rows.map((row, r) => {
        const cells = row
            .map((v, c) => xlsxCell(xlsxColumnName(c) + (r + 2), v))
            .join("");

        return '<row r="' + (r + 2) + '">' + cells + '</row>';
    }).join("");

    const lastCol = xlsxColumnName(Math.max(0, columns.length - 1));
    const lastRow = rows.length + 1;

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        + '<dimension ref="A1:' + lastCol + lastRow + '"/>'
        + '<sheetData>'
        + '<row r="1">' + header + '</row>'
        + body
        + '</sheetData>'
        + '</worksheet>';
}

/*
 * Minimal but complete styles part. Only one style is used — xf index 1, the
 * bold header font — but a workbook that references a style must declare it,
 * and the fills/borders/cellStyleXfs boilerplate is required for Excel to
 * accept the part at all.
 */
function xlsxStylesXml() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        + '<fonts count="2">'
        + '<font><sz val="11"/><name val="Calibri"/></font>'
        + '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        + '</fonts>'
        + '<fills count="2">'
        + '<fill><patternFill patternType="none"/></fill>'
        + '<fill><patternFill patternType="gray125"/></fill>'
        + '</fills>'
        + '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        + '<cellXfs count="2">'
        + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        + '</cellXfs>'
        + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        + '</styleSheet>';
}

/*
 * `columns` is a list of header strings; `rows` is a list of arrays whose
 * entries are string | number | null. Null becomes an empty cell.
 *
 * Returns the workbook as bytes, ready for a Blob.
 */
function buildXlsxWorkbook(sheetName, columns, rows) {
    const workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        + ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        + '<sheets><sheet name="' + xmlEscape(xlsxSheetName(sheetName))
        + '" sheetId="1" r:id="rId1"/></sheets>'
        + '</workbook>';

    const contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        + '<Default Extension="xml" ContentType="application/xml"/>'
        + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        + '</Types>';

    const rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        + '<Relationship Id="rId1"'
        + ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        + ' Target="xl/workbook.xml"/>'
        + '</Relationships>';

    const workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        + '<Relationship Id="rId1"'
        + ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
        + ' Target="worksheets/sheet1.xml"/>'
        + '<Relationship Id="rId2"'
        + ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
        + ' Target="styles.xml"/>'
        + '</Relationships>';

    /*
     * Order matters only for readability; OOXML resolves parts through the
     * relationship graph, not through archive order.
     */
    return zipStore([
        { name: "[Content_Types].xml", data: contentTypes },
        { name: "_rels/.rels", data: rootRels },
        { name: "xl/workbook.xml", data: workbook },
        { name: "xl/_rels/workbook.xml.rels", data: workbookRels },
        { name: "xl/styles.xml", data: xlsxStylesXml() },
        { name: "xl/worksheets/sheet1.xml", data: xlsxWorksheetXml(columns, rows) }
    ]);
}

/*
 * The MIME type is the one Excel for Android matches on before it even looks
 * inside, so it has to be the real xlsx type — not the generic
 * application/vnd.ms-excel the SpreadsheetML version used.
 */
function downloadXlsx(filename, bytes) {
    const blob = new Blob([bytes], {
        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    });

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    /* Revoke on the next tick: revoking synchronously can cancel the download. */
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function exportFilename(prefix, from, to) {
    const f = from || "all";
    const t = to || "all";

    return prefix + "_" + f + "_to_" + t + ".xlsx";
}

/* =========================================================
   HISTORY TABLE HEIGHT
   =========================================================

   Measured rather than hardcoded: the control bar above the table wraps to a
   different number of lines as the window narrows, so the space left below it
   is not a constant. Everything is measured relative to the top of the table
   wrapper, so an error in the offset cannot accumulate.
   ========================================================= */

function syncHistoryTableOffset() {
    const wrap = document.querySelector(".history-table-wrap");

    if (!wrap) return;

    const top = wrap.getBoundingClientRect().top + window.scrollY;
    document.documentElement.style.setProperty("--history-vh-offset", Math.round(top) + "px");
}

let historyTableOffsetTimer = null;

window.addEventListener("resize", () => {
    if (!document.querySelector(".history-table-wrap")) return;

    clearTimeout(historyTableOffsetTimer);
    historyTableOffsetTimer = setTimeout(syncHistoryTableOffset, 150);
});

/* =========================================================
   HISTORY CONTROLS (CHART + EXPORT TO EXCEL)
   =========================================================

   These live in the filter bar because that is the bar already carrying the
   page's controls, so the layout gains a button rather than a new region. The
   filter bar is rebuilt on every render, so the button state is read from
   `showChart` rather than from the DOM.
   ========================================================= */

let showChart = false;

/*
 * Appended after APPLY/RESET so the existing controls keep their order and
 * position. Returns nothing the caller has to wire up.
 */
function appendHistoryButtons(bar, config) {
    if (!bar) return;

    const chart = document.createElement("button");
    chart.type = "button";
    chart.className = "filter-button filter-button-chart" + (showChart ? " is-active" : "");
    chart.textContent = "CHART";
    chart.setAttribute("aria-pressed", showChart ? "true" : "false");
    chart.addEventListener("click", () => {
        showChart = !showChart;
        config.onToggle();
        renderEquipment();
    });
    bar.appendChild(chart);

    const exportBtn = document.createElement("button");
    exportBtn.type = "button";
    exportBtn.className = "filter-button filter-button-export";
    exportBtn.textContent = "EXPORT TO EXCEL";
    exportBtn.addEventListener("click", config.onExport);
    bar.appendChild(exportBtn);
}



/*
 * Restore the persisted mute state before anything can sound, so a refresh
 * during an active alarm never produces a burst of sound.
 */
alarmMuted = readAlarmMuted();

/*
 * Restore the persisted history window before the first alarm request, so the
 * initial load already asks for the window the user last chose.
 */
alarmWindowHours = readAlarmWindowHours();

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

