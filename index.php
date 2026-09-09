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
    <button class="nav-button" data-equipment="CHILLER">CHILLER</button>
    <button class="nav-button" data-equipment="CCP">CCP</button>
    <button class="nav-button" data-equipment="CHWP">CHWP</button>
    <button class="nav-button" data-equipment="CT">CT</button>
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

function renderEquipment() {
    grid.innerHTML = "";

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
        const response = await fetch(API_URL + "?t=" + Date.now(), {
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

        const now = new Date();
        lastUpdate.textContent =
            "Last update: " +
            now.toLocaleTimeString();

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
    });
});

loadData();

setInterval(loadData, 5000);
</script>

</body>
</html>

