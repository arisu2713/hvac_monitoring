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
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ef.css">
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
    </div>
</header>

<nav id="equipmentNav" class="equipment-nav">
    <button class="nav-button active" data-equipment="AHU">AHU</button>
    <button class="nav-button" data-equipment="HVAC_SC">HVAC SC</button>
    <button class="nav-button" data-equipment="ROOM_TEMP_RH">ROOM TEMP & RH</button>
    <button class="nav-button" data-equipment="EXHAUST_FAN">EXHAUST FAN</button>
    <button class="nav-button" data-equipment="AHU_G8">AHU G8</button>
</nav>

<main>
    <div class="section-header">
        <div id="sectionTitle">AHU</div>
        <div id="unitCount">0 units</div>
    </div>

    <div id="equipmentGrid" class="equipment-grid"></div>
</main>

<footer>
    <span>HVAC Monitoring</span>
    <span id="lastUpdate">Last update: --</span>
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

    const equipmentName =
        unit.name ??
        unit.equipment_name ??
        unit.unit ??
        "";

    name.textContent =
        currentEquipment + " " + equipmentName;

    card.appendChild(name);

    if (unit.temp !== null && unit.temp !== undefined) {
        const value = document.createElement("div");
        value.className = "unit-value";
        value.textContent = Number(unit.temp).toFixed(1) + " °C";
        card.appendChild(value);
    } else if (unit.temperature !== null && unit.temperature !== undefined) {
        const value = document.createElement("div");
        value.className = "unit-value";
        value.textContent = Number(unit.temperature).toFixed(1) + " °C";
        card.appendChild(value);
    } else if (unit.frequency !== null && unit.frequency !== undefined) {
        const value = document.createElement("div");
        value.className = "unit-value";
        value.textContent = Number(unit.frequency).toFixed(1) + " Hz";
        card.appendChild(value);
    }

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
        { label: "EL:", val: unit.evap_leaving_temp,  suffix: " °C" },
        { label: "EE:", val: unit.evap_entering_temp, suffix: " °C" },
        { label: "CE:", val: unit.cond_entering_temp, suffix: " °C" },
        { label: "CL:", val: unit.cond_leaving_temp,  suffix: " °C" }
    ];

    fields.forEach(f => {
        const item = document.createElement("div");
        item.className = "metric-item";

        item.innerHTML =
            `<span class="m-lbl">${f.label}</span>` +
            `<span class="m-val">${formatValue(f.val, f.suffix)}</span>`;

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
        { label: "Curr:", val: unit.current,   suffix: " A" },
        { label: "Drive:", val: unit.run,       suffix: "" }
    ];

    fields.forEach(f => {
        const item = document.createElement("div");
        item.className = "metric-item";

        item.innerHTML =
            `<span class="m-lbl">${f.label}</span>` +
            `<span class="m-val">${formatValue(f.val, f.suffix)}</span>`;

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

    unitCount.textContent = data.length + " units";

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
}
function createRoomCard(unit, index) {
    const card = document.createElement("div");
    card.id = "roomcard" + (index + 1);
    card.className = "unit-card room-card";

    const name = document.createElement("div");
    name.className = "unit-name";

    const prefix = unit.type === "OUTDOOR" ? "OUTDOOR " : "ROOM ";
    const rawName = unit.name ?? "";
    const displayName = (rawName.toUpperCase().startsWith("ROOM") || rawName.toUpperCase().startsWith("OUTDOOR"))
        ? rawName
        : prefix + rawName;

    name.textContent = displayName;
    card.appendChild(name);

    const tempVal = document.createElement("div");
    tempVal.className = "unit-value room-temp";
    tempVal.textContent = (unit.temp !== null && unit.temp !== undefined)
        ? Number(unit.temp).toFixed(1) + " °C"
        : "-- °C";
    card.appendChild(tempVal);

    const rhVal = document.createElement("div");
    rhVal.className = "unit-value room-rh";
    rhVal.textContent = (unit.rh !== null && unit.rh !== undefined)
        ? Number(unit.rh).toFixed(1) + " % RH"
        : "-- % RH";
    card.appendChild(rhVal);

    return card;
}

function renderRoomTempRh() {
    const data = allData.ROOM_TEMP_RH;

    sectionTitle.textContent = "ROOM TEMP & RH";
    grid.classList.remove("hvac-view");

    if (!Array.isArray(data)) {
        unitCount.textContent = "0 units";
        return;
    }

    unitCount.textContent = data.length + " units";

    data.forEach((unit, index) => {
        grid.appendChild(createRoomCard(unit, index));
    });
}

function renderEquipment() {
    grid.innerHTML = "";
    if (currentEquipment === "EXHAUST_FAN") {
        renderExhaustFan();
        return;
    }

    if (currentEquipment === "ROOM_TEMP_RH") {
        renderRoomTempRh();
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

    const placeholderCount = currentEquipment === "AHU" ? 4 : 0;
    sectionTitle.textContent = currentEquipment;
    unitCount.textContent = (data.length + placeholderCount) + " units";

    data.forEach(unit => {
        grid.appendChild(createCard(unit));
    });

    if (currentEquipment === "AHU") {
        const placeholderNumbers = ["94", "95", "96", "97"];
        placeholderNumbers.forEach(num => {
            grid.appendChild(createAhuPlaceholderCard(num));
        });
    }
}

async function loadData() {
    try {
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
            lastUpdate.textContent = "DB update: " + latest;
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

safeLoadData();
setInterval(safeLoadData, 10000);
</script>

</body>
</html>

