<?php

require_once __DIR__ . '/auth.php';
require_login();

/**
 * Version shown in the header.
 *
 * The deployed checkout's own Git metadata is the only honest source: there is
 * no build step, so a hardcoded SHA would drift from the files actually being
 * served. Reads are filesystem-only and nothing is written; when the metadata
 * cannot be read the header says "unknown" instead of guessing.
 *
 * Git deploy keeps `.git` beside the files, so HEAD is usually a loose ref. A
 * fresh clone packs its objects, which this reader cannot inflate without a
 * pack parser; in that case only the SHA and branch are shown, and the commit
 * time is left out rather than approximated.
 */
function app_version_info(): ?array
{
    $dir = __DIR__ . '/.git';

    if (is_file($dir)) {
        $pointer = @file_get_contents($dir);

        if ($pointer !== false && preg_match('/^gitdir:\s*(.+)$/m', $pointer, $m)) {
            $dir = trim($m[1]);
            if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $dir)) {
                $dir = __DIR__ . '/' . $dir;
            }
        }
    }

    if (!is_dir($dir)) {
        return null;
    }

    $head = @file_get_contents($dir . '/HEAD');
    if ($head === false) {
        return null;
    }

    $head = trim($head);
    $sha = null;
    $branch = null;

    if (preg_match('/^ref:\s*(.+)$/', $head, $m)) {
        $ref = trim($m[1]);
        $branch = preg_replace('#^refs/heads/#', '', $ref);

        $refFile = $dir . '/' . $ref;
        if (is_file($refFile)) {
            $sha = trim((string)@file_get_contents($refFile));
        } else {
            $packed = @file_get_contents($dir . '/packed-refs');
            if ($packed !== false
                && preg_match('/^([0-9a-f]{40})\s+' . preg_quote($ref, '/') . '$/m', $packed, $pm)) {
                $sha = $pm[1];
            }
        }
    } elseif (preg_match('/^[0-9a-f]{40}$/i', $head)) {
        $sha = $head;
    }

    if ($sha === null || !preg_match('/^[0-9a-f]{40}$/i', $sha)) {
        return null;
    }

    $info = [
        'sha' => strtolower($sha),
        'short' => substr(strtolower($sha), 0, 7),
        'branch' => $branch,
        'time' => null,
    ];

    /* Commit time and subject, only from a loose object. */
    $raw = @file_get_contents($dir . '/objects/' . substr($info['sha'], 0, 2) . '/' . substr($info['sha'], 2));
    if ($raw !== false) {
        $body = @gzuncompress($raw);
        if ($body === false) {
            $body = @zlib_decode($raw);
        }

        if ($body !== false && strncmp($body, 'commit ', 7) === 0) {
            $split = strpos($body, "\n\n");
            $header = $split === false ? $body : substr($body, 0, $split);

            if (preg_match('/^committer\s+.*?>\s*(\d+)\s+([+-]\d{4})$/m', $header, $cm)) {
                $stamp = (int)$cm[1];
                $offset = $cm[2];

                $tz = new DateTimeZone(
                    ($offset[0] === '-' ? '-' : '+')
                    . substr($offset, 1, 2) . ':' . substr($offset, 3, 2)
                );

                $info['time'] = (new DateTimeImmutable('@' . $stamp))
                    ->setTimezone($tz)
                    ->format('Y-m-d H:i');
            }
        }
    }

    return $info;
}

$appVersion = app_version_info();

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
        <span class="connection-meta">
            <span id="lastUpdate" class="last-update">DB update: --</span>
            <span class="app-version"
                  title="<?= $appVersion
                      ? 'Deployed from Git commit ' . htmlspecialchars($appVersion['sha'], ENT_QUOTES, 'UTF-8')
                      : 'Git metadata unavailable on this deployment' ?>"
            ><span class="av-sha">Version: <?= $appVersion
                    ? htmlspecialchars($appVersion['short'], ENT_QUOTES, 'UTF-8')
                    : 'unknown' ?></span><?php
                if ($appVersion && $appVersion['branch'] !== null && $appVersion['branch'] !== '') {
                    echo '<span class="av-branch"> &middot; '
                        . htmlspecialchars($appVersion['branch'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
                if ($appVersion && $appVersion['time'] !== null) {
                    echo '<span class="av-time"> &middot; '
                        . htmlspecialchars($appVersion['time'], ENT_QUOTES, 'UTF-8') . '</span>';
                }
            ?></span>
        </span>
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

/*
 * The operating dot, drawn in every health band.
 *
 * It used to be drawn only on healthy units, on the theory that a green dot
 * beside a red ALARM badge would read as "fine". The result was worse: a
 * stopped unit in alarm showed no operating state at all, so the one fact an
 * operator most needs — is this thing running? — disappeared exactly where it
 * mattered. The dot is now its own axis and carries its own colour, so it
 * cannot be mistaken for a health verdict.
 */
function hvacScRunDot(unit) {
    const state = hvacScRunState(unit);

    const dot = document.createElement("span");
    dot.className = "hsc-run-dot is-" + state;
    dot.title = state === "on" ? "RUN" : (state === "off" ? "OFF" : "Operating status unknown");

    return dot;
}

/*
 * One card per unit in the All Units view.
 *
 * The presentation is the Issues First design language — a dark neutral card
 * with a restrained severity accent — rather than the old solid red/green
 * blocks. The band, badge, reason and readings all come from the shared
 * helpers above, so this view can never classify a unit differently from the
 * issues view.
 *
 * The readings are not capped at three the way a collapsed issue card is:
 * this view is the inventory, so every value the API sends is shown, and a
 * metric with no reading stays "--" rather than becoming a number.
 */
function hvacScUnitCard(entry) {
    const { kind, label, unit } = entry;
    const band = hvacScBandOf(kind, unit);

    const card = document.createElement("div");
    card.className = "hsc-card hsc-u is-" + band;
    card.dataset.band = band;
    card.dataset.run = hvacScRunState(unit);

    const row = document.createElement("div");
    row.className = "hsc-card-row";

    row.appendChild(hvacScRunDot(unit));

    const name = document.createElement("span");
    name.className = "hsc-card-name";
    name.textContent = label;
    row.appendChild(name);

    const badgeText = hvacScBadge(kind, unit, band);

    if (badgeText) {
        const badge = document.createElement("span");
        badge.className = "hsc-badge";
        badge.textContent = badgeText;
        row.appendChild(badge);
    }

    card.appendChild(row);

    const reason = hvacScReason(kind, unit, band);

    if (reason) {
        const why = document.createElement("p");
        why.className = "hsc-why";
        why.textContent = reason;
        card.appendChild(why);
    }

    const chips = document.createElement("div");
    chips.className = "hsc-chips";

    hvacScMetrics(kind, unit).forEach(m => chips.appendChild(
        hvacScValueChip(
            m,
            hvacScNum(m.value) === null
                ? "n"
                : (hvacScIsViolation(m) ? "is-bad" : "")
        )
    ));

    card.appendChild(chips);

    return card;
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
function setRoomReading(el, raw, unitText) {
    const has = raw !== null && raw !== undefined;

    const num = document.createElement("span");
    num.className = "rv-num";
    num.textContent = has ? Number(raw).toFixed(1) : "--";

    const u = document.createElement("span");
    u.className = "rv-unit";
    u.textContent = " " + unitText;

    el.textContent = "";
    el.appendChild(num);
    el.appendChild(u);
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
    setRoomReading(tempVal, unit.temp, "°C");
    applyAlarmClass(tempVal, unit.temp, null, unit.temp_max);
    card.appendChild(tempVal);

    const rhVal = document.createElement("div");
    rhVal.className = "unit-value room-rh";
    setRoomReading(rhVal, unit.rh, "% RH");
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
     * space each sticky bar must clear - so they are the bars' FLOW heights,
     * not their painted positions.
     *
     * This used to read `getBoundingClientRect().bottom + window.scrollY`.
     * That is only correct while the bars are still in flow. Once the page is
     * scrolled past them they are STUCK: getBoundingClientRect returns the
     * pinned viewport position (top 0 / 60), and adding scrollY then counts
     * the scroll offset twice. Measured on a phone at scrollY 900: the nav
     * resolved to top=960px instead of 60px, so it jumped down over the alarm
     * cards and left its own 72px flow slot empty - the blank band under the
     * header. The value then persisted, because a later render while still
     * scrolled re-measured the same wrong numbers, so scrolling back to the
     * top left the nav stranded mid-page.
     *
     * Heights are scroll-independent, and the topbar is the first element in
     * body (html/body carry no margin or padding), so its flow top is 0 and
     * the two heights are the whole stack. The nav's own height already
     * includes its wrap to two lines on a phone.
     */
    const navTop = topbar.offsetHeight;
    const headerTop = navTop + nav.offsetHeight;

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
const ENERGY_VIEW_COMBINED = "combined";

/*
 * Equipment scope. "All Units" keeps the previous behaviour; "By Area"
 * narrows to the devices that belong to one area; "Individual Unit" charts
 * exactly one channel.
 *
 * Areas are keyed by ACC device id, which api_energy.php reads from the point
 * master and sends per unit. The mapping is a fact about the installation, not
 * about a particular response, so it lives here as a lookup rather than being
 * inferred from unit numbers (CT 1-6 sit on 10203 and CT 9-13 on 10202, but
 * the id is the thing that actually defines the area).
 */
const ENERGY_SCOPE_ALL = "all";
const ENERGY_SCOPE_AREA = "area";
const ENERGY_SCOPE_UNIT = "unit";

const ENERGY_DEVICE_AREA = {
    10202: "G3",
    10203: "G1-G2"
};

const ENERGY_AREA_ORDER = ["G1-G2", "G3"];

/*
 * ROOM TEMP & RH equipment scope: one area/equipment selector.
 *
 * Areas are keyed by the ACC device id the hourly rows already carry (10202 =
 * G3, 10203 = G1-G2). AHU 96-97 are deliberately a separate area, G8: their
 * readings come from the Daikin poller, not from the Azbil devices, and
 * folding them into either Azbil area would state a device identity they do
 * not have.
 *
 * The selector's values are the area names themselves, plus two sentinels:
 * "all" and the Individual Point mode, which reveals a second selector.
 */
const ROOM_AREA_ALL = "all";
const ROOM_AREA_POINT = "__point__";
const ROOM_ALL_POINTS = "__all__";

const ROOM_DEVICE_AREA = {
    10202: "G3",
    10203: "G1-G2"
};

/* Areas that are not a device: they are named by their own source. */
const ROOM_SOURCE_AREA = {
    daikin: "G8"
};

const ROOM_AREA_ORDER = ["G8", "G1-G2", "G3"];

/*
 * How each area is named in the selector. The area value stays the bare name
 * so the mapping above remains the single source of truth; only the text the
 * operator reads is spelled out, because "G3" alone does not say which
 * polled device it came from.
 */
const ROOM_AREA_LABEL = {
    "G8": "G8: AHU 96–97 (Daikin poller)",
    "G3": "G3: device ID 10202",
    "G1-G2": "G1-G2: device ID 10203"
};

/* Area of one room, or null when neither its device nor source is known. */
function roomPointArea(room) {
    if (!room) {
        return null;
    }

    if (room.source && ROOM_SOURCE_AREA[room.source]) {
        return ROOM_SOURCE_AREA[room.source];
    }

    const device = Number(room.device);

    if (!Number.isFinite(device) || device === 0) {
        return null;
    }

    return ROOM_DEVICE_AREA[device] ?? null;
}

/* Area of one unit, or null when its device is unknown/outside the map. */
function energyUnitArea(unit) {
    const device = Number(unit && unit.device);

    if (!Number.isFinite(device) || device === 0) {
        return null;
    }

    return ENERGY_DEVICE_AREA[device] ?? null;
}

/* Active filter values, kept out of the DOM so a re-render can restore them. */
const historyFilters = {
    ENERGY: {
        category: "CT",
        from: "",
        to: "",
        scope: ENERGY_SCOPE_ALL,
        area: "",
        unit: ENERGY_ALL_UNITS,
        view: ENERGY_VIEW_OVERVIEW
    },
    TEMP_RH_HOURLY: {
        metric: "temp",
        from: "",
        to: "",
        area: ROOM_AREA_ALL,
        point: ROOM_ALL_POINTS
    }
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

    const addSelect = (name, label, options, current, onChange) => {
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

        /*
         * Optional live reaction to a selection. The ENERGY page uses it so
         * the scope control can repopulate the area/unit list before APPLY,
         * without a round trip and without losing the dates already typed.
         */
        if (onChange) {
            select.addEventListener("change", () => onChange(select.value, inputs));
        }

        inputs[name] = select;
        wrap.appendChild(select);
        pageFilters.appendChild(wrap);

        return { wrap: wrap, select: select };
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
            const field = addSelect(def.name, def.label, def.options, values[def.name], def.onChange);

            /*
             * A selector that only applies to one scope (Area, Unit) starts
             * hidden. It stays in the DOM so its value survives switching
             * scope, and so APPLY always sees it.
             */
            if (def.hidden) {
                field.wrap.hidden = true;
            }
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
     * back to All Units rather than charting equipment that is not there.
     */
    if (historyFilters.ENERGY.scope === ENERGY_SCOPE_UNIT
        && !units.some(u => String(u.unit) === String(historyFilters.ENERGY.unit))) {
        historyFilters.ENERGY.scope = ENERGY_SCOPE_ALL;
        historyFilters.ENERGY.unit = ENERGY_ALL_UNITS;
    }

    if (historyFilters.ENERGY.scope === ENERGY_SCOPE_AREA
        && !energyAreaUnits(units, historyFilters.ENERGY.area).length) {
        historyFilters.ENERGY.scope = ENERGY_SCOPE_ALL;
        historyFilters.ENERGY.area = "";
    }

    const bar = buildFilterBar([
        {
            type: "select",
            name: "category",
            label: "Category",
            options: (data.categories || ["CT"]).map(c => ({ value: c, text: c }))
        },
        {
            type: "select",
            name: "scope",
            label: "Equipment scope",
            options: [
                { value: ENERGY_SCOPE_ALL, text: "All Units" },
                { value: ENERGY_SCOPE_AREA, text: "By Area" },
                { value: ENERGY_SCOPE_UNIT, text: "Individual Unit" }
            ],
            onChange: (value, inputs) => syncEnergyScopeInputs(inputs, value)
        },
        {
            type: "select",
            name: "area",
            label: "Area",
            options: energyAreaOptions(units),
            hidden: historyFilters.ENERGY.scope !== ENERGY_SCOPE_AREA
        },
        {
            type: "select",
            name: "unit",
            label: "Unit",
            options: energyUnitOptions(units, category),
            hidden: historyFilters.ENERGY.scope !== ENERGY_SCOPE_UNIT
        },
        { type: "date", name: "from", label: "From" },
        { type: "date", name: "to", label: "To" }
    ], historyFilters.ENERGY, applyEnergyFilter);

    appendHistoryButtons(bar, {
        onToggle: () => {},
        onExport: () => exportEnergy(data)
    });

    /*
     * The view switch now lives WITH the chart, not in #pageSwitch above the
     * filter bar: it only changes how the chart is drawn, so it belongs beside
     * the thing it changes. It is built inside the chart stack below.
     */
    clearPageSwitch();

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

        /* View switch + the equipment it applies to, right above the plot. */
        const controls = document.createElement("div");
        controls.className = "history-chart-controls";

        const viewSwitch = document.createElement("div");
        viewSwitch.className = "page-switch history-chart-view-switch";

        [
            { value: ENERGY_VIEW_OVERVIEW, label: "OVERVIEW — ONE PANEL EACH" },
            { value: ENERGY_VIEW_COMBINED, label: category + " COMBINED" }
        ].forEach(opt => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "switch-button"
                + (historyFilters.ENERGY.view === opt.value ? " is-active" : "");
            button.textContent = opt.label;
            button.addEventListener("click", () => setEnergyView(opt.value));
            viewSwitch.appendChild(button);
        });

        controls.appendChild(viewSwitch);

        const scopeLabel = document.createElement("span");
        scopeLabel.className = "history-chart-scope";
        scopeLabel.textContent = energyScopeLabel(units, category);
        controls.appendChild(scopeLabel);

        stack.appendChild(controls);

        const panels = document.createElement("div");
        panels.className = "history-chart-panels";
        panels.appendChild(buildEnergyChart(data));
        stack.appendChild(panels);

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
 * ENERGY chart: Overview / Combined.
 *
 * Both modes plot the same `usage` values the table's columns show, over the
 * same days, through the same formatters, so the chart and the table can
 * never disagree. They differ only in whether the units share a plot or get
 * one panel each.
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

/* Units in one area, in unit order. Empty when the area has none. */
function energyAreaUnits(units, area) {
    if (!area) {
        return [];
    }

    return units.filter(u => energyUnitArea(u) === area);
}

/* Area options present in this response, in the project's usual order. */
function energyAreaOptions(units) {
    const present = new Set();

    units.forEach(u => {
        const area = energyUnitArea(u);
        if (area) present.add(area);
    });

    const ordered = ENERGY_AREA_ORDER.filter(a => present.has(a));

    /* Anything outside the known map still gets an option, never dropped. */
    Array.from(present).forEach(a => {
        if (ordered.indexOf(a) < 0) ordered.push(a);
    });

    return ordered.map(a => ({ value: a, text: a }));
}

function energyUnitOptions(units, category) {
    return units.map(u => ({
        value: String(u.unit),
        text: category + " " + u.unit + (energyUnitArea(u) ? " (" + energyUnitArea(u) + ")" : "")
    }));
}

/* The units the current scope selects. */
function energyScopeUnits(data) {
    const units = (data && data.units) || [];
    const f = historyFilters.ENERGY;

    if (f.scope === ENERGY_SCOPE_UNIT) {
        const one = units.filter(u => String(u.unit) === String(f.unit));
        return one.length ? one : units;
    }

    if (f.scope === ENERGY_SCOPE_AREA) {
        const area = energyAreaUnits(units, f.area);
        return area.length ? area : units;
    }

    return units;
}

/* Short description of what the chart is currently showing. */
function energyScopeLabel(units, category) {
    const f = historyFilters.ENERGY;
    const count = energyScopeUnits({ units: units }).length;

    if (f.scope === ENERGY_SCOPE_UNIT && f.unit !== ENERGY_ALL_UNITS) {
        return "Scope: " + category + " " + f.unit;
    }

    if (f.scope === ENERGY_SCOPE_AREA && f.area) {
        return "Scope: Area " + f.area + " (" + count + " units)";
    }

    return "Scope: All units (" + units.length + ")";
}

/*
 * Show only the selector the chosen scope uses. The other one is hidden rather
 * than removed so its value survives a round trip through All Units, and so
 * APPLY always sees both inputs.
 */
function syncEnergyScopeInputs(inputs, scope) {
    if (!inputs || !inputs.area || !inputs.unit) {
        return;
    }

    inputs.area.closest(".filter-field").hidden = scope !== ENERGY_SCOPE_AREA;
    inputs.unit.closest(".filter-field").hidden = scope !== ENERGY_SCOPE_UNIT;
}

function buildEnergyChart(data) {
    const units = data.units || [];
    const days = (data.days || []).slice().reverse();
    const category = data.category || "CT";
    const mode = historyFilters.ENERGY.view;

    /*
     * The scope decides which units are charted; the view mode only decides
     * how they are drawn. Keeping the two apart means switching Overview /
     * Combined never changes the subject of the chart.
     */
    const charted = energyScopeUnits(data);

    const isSubset = charted.length !== units.length;

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
    const channelWord = series.length === 1 ? " channel" : " channels";
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
            meta: span + " — " + series.length + channelWord + ", one panel each, shared 0-" + formatCompactNumber(Math.round(top)) + " kWh scale",
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

    /*
     * Combined: every unit in scope on ONE shared axis, with a legend that
     * shows and hides individual series.
     *
     * This is the only mode that can carry more series than the palette has
     * slots, which is why the palette was extended to eleven validated hues
     * rather than cycled. `combine` opts out of the shared panel's faceting
     * fallback; the legend toggle is opted into separately so ROOM TEMP & RH,
     * which shares this panel, keeps its current display-only legend.
     */
    return buildHistoryChartPanel({
        title: "DAILY ENERGY USAGE — " + (isSubset ? "SELECTED " : "ALL ") + category
            + " (" + days.length + dayWord + ")",
        meta: span + " — " + series.length + channelWord + " on one shared scale (kWh)",
        series: series,
        note: note,
        combine: true,
        legendToggle: true,
        emptyText: "No daily usage to chart for this filter.",
        options: energyChartOptions()
    });
}

/*
 * Shared chart options for all ENERGY modes.
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
 * Category, scope and view mode are NOT date fields, so RESET deliberately
 * leaves them alone: it clears the date range, which is the only thing the
 * RESET button has ever cleared on this page. Silently resetting the category
 * to CT or the view to Overview as well would make one button do three jobs and
 * lose the reader's place.
 *
 * Changing category always re-fetches, because the unit list (and with it the
 * area and unit options) is derived from the response. Changing the scope only
 * re-renders from data already in hand: it selects a subset of the same rows,
 * so making the reader wait on an identical request would only flash the page
 * empty.
 */
function applyEnergyFilter(inputs, isReset) {
    const f = historyFilters.ENERGY;
    const prev = { category: f.category, unit: f.unit, from: f.from, to: f.to };

    f.category = inputs.category.value;
    f.scope = inputs.scope ? inputs.scope.value : ENERGY_SCOPE_ALL;
    f.area = inputs.area ? inputs.area.value : "";
    f.unit = inputs.unit ? inputs.unit.value : ENERGY_ALL_UNITS;
    f.from = isReset ? "" : inputs.from.value;
    f.to = isReset ? "" : inputs.to.value;

    /*
     * A unit selection belongs to the category it was made in — CT 11 is not a
     * CCP channel — so a category change drops it back to All Units rather
     * than carrying a stale selection across.
     */
    if (f.category !== prev.category) {
        f.scope = ENERGY_SCOPE_ALL;
        f.area = "";
        f.unit = ENERGY_ALL_UNITS;
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
 * two modes can be compared without a round trip.
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

/* "2026-10-17 21:00" -> two lines, so the sticky Hour column stays narrow. */
function roomHourStamp(text) {
    const s = String(text);
    const k = s.indexOf(" ");

    return k > 0 ? s.slice(0, k) + "\n" + s.slice(k + 1) : s;
}

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

    const allRooms = data.rooms || [];

    /*
     * A selection can stop being valid (a room drops out of the response
     * after a range change). Fall back to All Units rather than showing an
     * empty frame that reads as "this filter has no data".
     */
    if (historyFilters.TEMP_RH_HOURLY.area !== ROOM_AREA_ALL
        && historyFilters.TEMP_RH_HOURLY.area !== ROOM_AREA_POINT
        && !roomScopeAreaRooms(allRooms, historyFilters.TEMP_RH_HOURLY.area).length) {
        historyFilters.TEMP_RH_HOURLY.area = ROOM_AREA_ALL;
    }

    if (historyFilters.TEMP_RH_HOURLY.area === ROOM_AREA_POINT
        && !allRooms.some(r => r.key === historyFilters.TEMP_RH_HOURLY.point)) {
        historyFilters.TEMP_RH_HOURLY.area = ROOM_AREA_ALL;
        historyFilters.TEMP_RH_HOURLY.point = ROOM_ALL_POINTS;
    }

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
        {
            type: "select",
            name: "area",
            label: "Equipment scope",
            options: roomAreaOptions(allRooms),
            onChange: (value, inputs) => syncRoomScopeInputs(inputs, value)
        },
        {
            type: "select",
            name: "point",
            label: "Point",
            options: roomPointOptions(allRooms),
            hidden: historyFilters.TEMP_RH_HOURLY.area !== ROOM_AREA_POINT
        },
        { type: "date", name: "from", label: "From" },
        { type: "date", name: "to", label: "To" }
    ], historyFilters.TEMP_RH_HOURLY, applyTempRhFilter);

    appendHistoryButtons(bar, {
        onToggle: () => {},
        onExport: () => exportTempRh(data)
    });

    /*
     * Everything below reads from the scoped set, so the table, the charts and
     * the export are three views of the same rooms and hours.
     */
    const rooms = roomScopeRooms(allRooms);
    const hours = data.hours || [];

    unitCount.textContent = hours.length + (hours.length === 1 ? " hour" : " hours");

    const page = document.createElement("div");
    page.className = "history-page is-room-history";

    const notice = document.createElement("div");
    notice.className = "history-notice";
    notice.textContent = filterNotice(data.available)
        + " Showing " + (data.from || "--") + " to " + (data.to || "--")
        + " — hourly " + (data.metric === "rh" ? "humidity" : "temperature")
        + " in " + (data.unit || "") + "."
        + " " + roomScopeLabel(allRooms) + ".";
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
            value: roomHourStamp(formatHour(hour.recorded_at)),
            className: "history-cell-key history-cell-stamp"
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
         *
         * The spread is taken over the rooms IN SCOPE, not over every room in
         * the response: a spread across rooms that are no longer on screen
         * would describe a different set of points than the columns do.
         */
        const readAt = roomReadAtLabel(hour, rooms) || "--";

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
/*
 * The chart-local chip filter (buildPointFilter / roomChartSel) is gone. It
 * selected chart series only, so the table and the export could disagree with
 * what was on screen. The equipment scope in the filter bar replaces it and
 * drives all three.
 */

/* =========================================================
   ROOM TEMP & RH — EQUIPMENT SCOPE
   =========================================================

   One selector narrows the whole view — the table, both charts and the
   export — to a set of points:

     All Units          every room the API reports
     G8                 AHU 96-97 (Daikin poller)
     G1-G2              rooms on device 10203
     G3                 rooms on device 10202
     Individual Point   exactly one room

   The filtering is client-side: the API already sends every room with the
   device id it was read from, and the export is built from the same arrays
   the table is built from, so the two cannot disagree.
   ========================================================= */

/* Rooms in one area, in the order the API sent them. */
function roomScopeAreaRooms(rooms, area) {
    if (!area || area === ROOM_AREA_ALL || area === ROOM_AREA_POINT) {
        return [];
    }

    return rooms.filter(r => roomPointArea(r) === area);
}

/* Areas present in this response, in the project's usual order. */
function roomAreaOptions(rooms) {
    const present = new Set();

    rooms.forEach(r => {
        const area = roomPointArea(r);
        if (area) present.add(area);
    });

    const ordered = ROOM_AREA_ORDER.filter(a => present.has(a));

    /* Anything outside the known map still gets an option, never dropped. */
    Array.from(present).forEach(a => {
        if (ordered.indexOf(a) < 0) ordered.push(a);
    });

    return [{ value: ROOM_AREA_ALL, text: "All Units" }]
        .concat(ordered.map(a => ({ value: a, text: ROOM_AREA_LABEL[a] || a })))
        .concat([{ value: ROOM_AREA_POINT, text: "Individual Point" }]);
}

function roomPointOptions(rooms) {
    return rooms.map(r => ({
        value: r.key,
        text: r.label
    }));
}

/* The rooms the current scope selects. */
function roomScopeRooms(rooms) {
    const f = historyFilters.TEMP_RH_HOURLY;

    if (f.area === ROOM_AREA_POINT) {
        const one = rooms.filter(r => r.key === f.point);
        return one.length ? one : rooms;
    }

    if (f.area && f.area !== ROOM_AREA_ALL) {
        const area = roomScopeAreaRooms(rooms, f.area);
        return area.length ? area : rooms;
    }

    return rooms;
}

/* Short description of what the table and charts are currently showing. */
function roomScopeLabel(rooms) {
    const f = historyFilters.TEMP_RH_HOURLY;
    const count = roomScopeRooms(rooms).length;

    if (f.area === ROOM_AREA_POINT) {
        const one = rooms.find(r => r.key === f.point);
        return "Scope: " + (one ? one.label : "one point");
    }

    if (f.area && f.area !== ROOM_AREA_ALL) {
        return "Scope: " + f.area + " (" + count + (count === 1 ? " point)" : " points)");
    }

    return "Scope: All points (" + rooms.length + ")";
}

/* Show only the selector the current mode uses; both stay in the DOM. */
function syncRoomScopeInputs(inputs, area) {
    if (!inputs || !inputs.point) {
        return;
    }

    inputs.point.closest(".filter-field").hidden = area !== ROOM_AREA_POINT;
}

/*
 * Re-render from the data already in hand — no request needed.
 *
 * Goes through renderRoomTempRh() rather than renderRoomHourly() directly:
 * the page's grid is cleared there, so the new table replaces the old one
 * instead of being appended below it.
 */
function redrawRoomHourly() {
    renderRoomTempRh();
}

/*
 * Earliest/latest read_at across the rooms in scope for one hour.
 *
 * The API's own read_at_min/read_at_max cover every room in the response, so
 * they cannot be used once the scope narrows the columns. The fallback to
 * them is for a response that predates per-room read times (no read_at map at
 * all); once the map exists, a room missing from it has no reading that hour
 * and must report none — falling back then would print another room's
 * timestamp beside an empty cell.
 */
function roomReadAtExtreme(hour, rooms, which) {
    const byRoom = hour.read_at || {};
    const hasMap = Object.keys(byRoom).length > 0;
    let best = null;

    rooms.forEach(room => {
        const at = byRoom[room.key];

        if (!at) {
            return;
        }

        if (best === null
            || (which === "min" ? at < best : at > best)) {
            best = at;
        }
    });

    if (best !== null || hasMap) {
        return best;
    }

    return which === "min" ? hour.read_at_min : hour.read_at_max;
}

/*
 * The "Reading at" text for one hour, over the rooms in scope.
 *
 * The spread is compared AFTER formatting: two readings taken seconds apart
 * (the Daikin samples are 07:38:28 and 07:38:38) format to the same minute,
 * and "07:38 … 07:38" would read as a bug rather than as the spread it is.
 */
function roomReadAtLabel(hour, rooms) {
    const min = roomReadAtExtreme(hour, rooms, "min");
    const max = roomReadAtExtreme(hour, rooms, "max");

    if (!min || !max) {
        return "";
    }

    const lo = formatHour(min);
    const hi = formatHour(max);

    return lo === hi ? lo : lo + " … " + hi;
}

function buildRoomChartPanel(data, metricKey, panelId) {
    const allRooms = data.rooms || [];

    /*
     * The equipment scope decides which rooms are drawn; the table uses the
     * same helper, so the chart and the table always describe the same set.
     * `roomChartSel` (the old chip filter) is gone — one selector, one answer.
     */
    const rooms = roomScopeRooms(allRooms);

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
        title: (isRh ? "HUMIDITY" : "TEMPERATURE")
            + (rooms.length === allRooms.length ? " — ALL ROOMS (" : " — SELECTED ROOMS (")
            + hours.length + (hours.length === 1 ? " hour" : " hours") + ")",
        meta: (data.from || "--") + " to " + (data.to || "--")
            + " — " + series.length + " rooms, reference line at " + ref.label,
        series: series,
        note: note,
        combine: true,
        legendToggle: true,
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

    const rooms = (primary && primary.rooms) || [];

    const panels = document.createElement("div");
    panels.className = "history-chart-panels";

    const render = () => {
        panels.textContent = "";

        const primaryMetric = primary && primary.metric === "rh" ? "rh" : "temp";
        const peerMetric = primaryMetric === "rh" ? "temp" : "rh";
        const hasPeer = peer && Array.isArray(peer.hours) && peer.hours.length > 0;

        if (hasPeer) {
            const byMetric = {};
            byMetric[primaryMetric] = primary;
            byMetric[peerMetric] = peer;

            /* Fixed order: Temperature first, then Humidity. */
            ["temp", "rh"].forEach(metricKey => {
                panels.appendChild(buildRoomChartPanel(byMetric[metricKey], metricKey, metricKey));
            });

            return;
        }

        panels.appendChild(buildRoomChartPanel(primary, primaryMetric, primaryMetric));

        const warn = document.createElement("div");
        warn.className = "history-chart-note";
        warn.textContent = "The " + (peerMetric === "rh" ? "humidity" : "temperature")
            + " chart is unavailable: that reading could not be loaded for this range.";
        panels.appendChild(warn);
    };

    /*
     * No chip filter here any more: the equipment scope in the filter bar is
     * the one control, and it drives the table and the export too, which a
     * chart-local chip set never did.
     */
    wrap.appendChild(panels);
    render();

    return wrap;
}

/*
 * Export exactly what the table shows: same hours, same room columns, same
 * newest-first order, same timestamp rendering. "Reading at" is written with
 * the same formatter the table uses (a spread is shown as "min … max" rather
 * than silently collapsed), so every cell in the file matches the screen.
 *
 * The room list comes through the same scope helper the table uses, so the
 * equipment filter applies to the file exactly as it applies to the screen.
 */
function exportTempRh(data) {
    const allRooms = data.rooms || [];
    const rooms = roomScopeRooms(allRooms);
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

        row.push(roomReadAtLabel(hour, rooms));

        return row;
    });

    const bytes = buildXlsxWorkbook(
        "TEMP RH " + (data.metric === "rh" ? "HUMIDITY" : "TEMPERATURE"),
        columns,
        rows
    );

    downloadXlsx(exportFilename("temp_rh_" + (data.metric || "temp"), data.from, data.to), bytes);
}

/*
 * APPLY and RESET both come through here.
 *
 * Only the metric and the dates need a request: the equipment scope selects a
 * subset of rows already in hand, so it re-renders from cache instead of
 * flashing the page empty for a response that would carry the same numbers.
 *
 * RESET clears the dates — the only thing it has ever cleared here — and
 * leaves the scope and the metric alone, so one button does not silently do
 * three jobs and lose the reader's place.
 */
function applyTempRhFilter(inputs, isReset) {
    const f = historyFilters.TEMP_RH_HOURLY;
    const prevMetric = f.metric;
    const prevFrom = f.from;
    const prevTo = f.to;

    f.metric = inputs.metric.value;
    f.area = inputs.area ? inputs.area.value : ROOM_AREA_ALL;
    f.point = inputs.point ? inputs.point.value : ROOM_ALL_POINTS;
    f.from = isReset ? "" : inputs.from.value;
    f.to = isReset ? "" : inputs.to.value;

    if (f.area !== ROOM_AREA_POINT) {
        f.point = ROOM_ALL_POINTS;
    }

    /*
     * Nothing the API serves has changed, so redraw rather than refetch. The
     * date inputs still hold what the reader typed, and renderRoomHourly()
     * rebuilds the bar from the stored values, so no state is lost.
     */
    if (f.metric === prevMetric && f.from === prevFrom && f.to === prevTo) {
        redrawRoomHourly();
        return;
    }

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

/* =========================================================
   AHU SEVERITY DASHBOARD
   ========================================================= */

const AHU_VIEW_ISSUES = "issues";
const AHU_VIEW_ALL = "all";

/*
 * View state lives outside the DOM, like historyFilters, so the 10s poll's
 * re-render cannot silently reset the reader's sort or search.
 */
const ahuView = {
    mode: AHU_VIEW_ISSUES,
    /* Exactly what the reader typed, so the box redraws unchanged. */
    searchRaw: "",
    /* Normalised once here; matching never re-derives it. */
    search: "",
    /*
     * Whether the search box held focus at the last render.
     *
     * Captured by reading document.activeElement at the TOP of
     * renderEquipment(), before the grid is cleared. A focus/blur listener
     * cannot do this job: focus events do not fire at all while the document
     * itself is unfocused, and clearing the grid detaches the input without
     * firing blur, so both the event and a post-clear activeElement read
     * report nothing. Before the clear is the only moment the answer exists.
     */
    searchFocused: false
};

let ahuSectionsHost = null;
let ahuUnits = [];

/*
 * HEALTH bands only. Whether a unit is running is a separate axis and is
 * drawn as the RUN dot on every card (see ahuRunState / ahuCreateCard), so a
 * stopped unit no longer disappears from NORMAL and an unread run point no
 * longer counts as OFF.
 */
const AHU_BANDS = [
    { key: "critical", title: "CRITICAL", note: "action required" },
    { key: "warning",  title: "WARNING", note: "outside normal range" },
    { key: "normal",   title: "NORMAL", note: "within limits" },
    { key: "unknown",  title: "UNKNOWN", note: "no health reading" }
];

function ahuUnitNumber(name) {
    const m = String(name ?? "").match(/(\d+)/);
    return m ? Number(m[1]) : Number.MAX_SAFE_INTEGER;
}

/*
 * Read a numeric field WITHOUT letting a missing value become a number.
 *
 * Number(null) is 0 and Number("") is 0, so a plain Number() turns "nobody
 * measured this" into a measured zero. On this page that would report an
 * unread AHU as 0.0 °C and as switched off — two claims the data does not
 * support. api_ahu.php emits run=null for AHU 94/95 whenever its hnx_g8
 * source is unavailable, so this is a reachable state, not a hypothetical.
 *
 * Returns null for absent, empty and non-finite input; otherwise the number.
 */
function ahuNum(value) {
    if (value === null || value === undefined || value === "") {
        return null;
    }

    const n = Number(value);

    return Number.isFinite(n) ? n : null;
}

function ahuCompareName(a, b) {
    return ahuUnitNumber(a.name) - ahuUnitNumber(b.name);
}

/*
 * The reading that represents this unit's HEALTH, with the limit that
 * belongs to it.
 *
 * Most AHUs publish a duct temperature (temp / temp_min / temp_max). The
 * Daikin units in the G8 building (AHU 96-97) have no duct sensor at all —
 * that is the installed design, not a failed sensor — and publish a ROOM
 * temperature instead (RoomTemp1, surfaced as room_temp with the ROOM limit
 * as room_temp_max).
 *
 * Judging those two units on `temp` alone made them permanently UNKNOWN: the
 * reading existed and was valid, but it was not the field the band looked at,
 * so the page called them "NO DATA" and printed "-- °C". The absence of a
 * duct sensor was being reported as the absence of every reading.
 *
 * Precedence is duct first, room second, so a unit that has both is judged on
 * its duct reading exactly as before. Only units with no duct reading but a
 * valid room reading change behaviour, which is AHU 96-97.
 *
 * The limits travel WITH the value. A room reading must never be judged
 * against the AHU duct limit (25 °C): 29 °C of room air is inside the ROOM
 * limit (30 °C) and would otherwise be reported as a duct fault.
 */
function ahuHealth(unit) {
    const temp = ahuNum(unit.temp);

    if (temp !== null) {
        return {
            value: temp,
            min: ahuNum(unit.temp_min),
            max: ahuNum(unit.temp_max),
            room: false
        };
    }

    const room = ahuNum(unit.room_temp);

    if (room !== null) {
        return {
            value: room,
            min: null,
            max: ahuNum(unit.room_temp_max),
            room: true
        };
    }

    return { value: null, min: null, max: null, room: false };
}

/*
 * HEALTH band: what the unit's own readings say about its condition.
 *
 * This deliberately does NOT look at `run`. "Not running" and "not healthy"
 * are different questions, and folding them together is what made a unit
 * whose run point never reported look switched off. Operating state is a
 * second, independent axis drawn as the RUN dot on every card (see
 * ahuRunState / ahuCreateCard) and in the operating-status line under the
 * summary.
 *
 * Precedence is alarm > out-of-range temperature > no health reading. A
 * measured violation is a real fault, so it must not be hidden by a missing
 * alarm point.
 *
 * UNKNOWN means there is no health evidence at all: no alarm point and no
 * temperature of either kind (see ahuHealth). AHU 96-97 are NOT this case —
 * they have no duct sensor, but they do publish a room temperature, so they
 * carry a real health reading and are banded on it. Calling them NORMAL would
 * be wrong only if that reading did not exist; calling them UNKNOWN while it
 * does is what this function used to do.
 */
function ahuBandOf(unit) {
    if (ahuNum(unit.alarm) === 1) {
        return "critical";
    }

    const health = ahuHealth(unit);

    if (isOutOfRange(health.value, health.min, health.max)) {
        return "warning";
    }

    if (ahuNum(unit.alarm) === null && health.value === null) {
        return "unknown";
    }

    return "normal";
}

/*
 * OPERATING state: what the unit's run point says.
 *
 * run=1 is running, run=0 is stopped, and anything else — null, absent, or
 * non-numeric — is UNKNOWN. It is never inferred from temperature, and it is
 * never collapsed into "off": Number(null) === 0 would turn "nobody could
 * look" into "switched off", which is the one claim the data cannot support.
 * api_ahu.php emits run=null for AHU 94/95 when its hnx_g8 source cannot be
 * read, so this is a reachable state.
 */
function ahuRunState(unit) {
    const run = ahuNum(unit.run);

    if (run === 1) {
        return "on";
    }

    if (run === 0) {
        return "off";
    }

    return "unknown";
}

/* Signed distance outside the configured bounds, or null when inside them. */
function ahuDeviation(unit) {
    const health = ahuHealth(unit);
    const t = health.value;

    if (t === null) {
        return null;
    }

    const max = health.max;

    if (max !== null && t > max) {
        return t - max;
    }

    const min = health.min;

    if (min !== null && t < min) {
        return t - min;
    }

    return null;
}

function ahuFormatTemp(value) {
    const t = ahuNum(value);

    return t === null ? "-- °C" : t.toFixed(1) + " °C";
}

/*
 * The value line a card prints, or null when it prints none.
 *
 * Duct temperature is the normal case, and "-- °C" when a duct unit has no
 * reading — the behaviour every other AHU already had.
 *
 * AHU 96-97 have no duct sensor. Their room temperature and RH are monitored
 * on the Room Temp & RH page, so their card here carries operating status
 * only: no value line, no RH line. Returning null means "draw no reading".
 */
function ahuCardReading(unit) {
    const health = ahuHealth(unit);

    if (health.room) {
        return null;
    }

    return ahuFormatTemp(health.value);
}

/*
 * The health tag. It describes the HEALTH band only — operating state has its
 * own dot and label, so a stopped unit that is otherwise within limits keeps
 * its health reading instead of having the word OFF written over it.
 */
function ahuTagFor(unit, band) {
    if (band === "critical") {
        return "ALARM";
    }

    if (band === "warning") {
        /*
         * Room-only units (AHU 96-97) print no temperature on this card, so a
         * signed "▲ +1.0" would be a bare number with nothing to compare it
         * to. They still get the warning band — the room reading is real
         * evidence — but the tag names the condition instead of a delta.
         */
        if (ahuHealth(unit).room) {
            return "OUT OF RANGE";
        }

        const d = ahuDeviation(unit);

        if (d === null) {
            return "OUT OF RANGE";
        }

        return (d > 0 ? "▲ +" : "▼ ") + d.toFixed(1);
    }

    if (band === "unknown") {
        return "NO DATA";
    }

    return "";
}

function ahuCreateCard(unit) {
    const band = ahuBandOf(unit);
    const state = ahuRunState(unit);

    const card = document.createElement("div");
    card.className = "ahu-card is-" + band;
    card.dataset.band = band;
    card.dataset.run = state;

    /*
     * RUN indicator: the OPERATING axis, drawn on every card in every health
     * band. Green when running, red when stopped, grey when no run point
     * reported. It is a separate element from the health band on purpose —
     * "this unit is stopped" and "this unit is in alarm" can both be true, and
     * neither may overwrite the other.
     *
     * The grey case is a real state, not a fallback: api_ahu.php emits
     * run=null for AHU 94/95 when its hnx_g8 source cannot be read, and
     * Number(null) === 0 would paint those red — "off" — when the truth is
     * that nobody could look.
     */
    const dot = document.createElement("span");
    dot.className = "ahu-run-dot is-" + (state === "on" ? "run" : state);
    dot.title = state === "on" ? "RUN" : (state === "off" ? "OFF" : "Operating status unknown");
    card.appendChild(dot);

    const tagText = ahuTagFor(unit, band);

    if (tagText) {
        const tag = document.createElement("span");
        tag.className = "ahu-card-tag";
        tag.textContent = tagText;
        card.appendChild(tag);
    }

    const name = document.createElement("span");
    name.className = "ahu-card-name";
    name.textContent = unit.name ?? "";
    card.appendChild(name);

    const reading = ahuCardReading(unit);

    /*
     * Null for AHU 96-97: those units report no duct temperature, and their
     * room readings live on the Room Temp & RH page, so their card shows
     * operating status only.
     */
    if (reading !== null) {
        const value = document.createElement("span");
        value.className = "ahu-card-value";
        value.textContent = reading;
        card.appendChild(value);
    }

    /*
     * Explicit operating label when the unit is not running. A green dot alone
     * is unambiguous; a grey one is not, so "UNKNOWN" has to be written out
     * rather than left to a colour nobody can interpret.
     */
    if (state !== "on") {
        const label = document.createElement("span");
        label.className = "ahu-run-label is-" + state;
        label.textContent = state === "off" ? "OFF" : "UNKNOWN";
        card.appendChild(label);
    }

    return card;
}

function ahuMatchesSearch(unit) {
    if (!ahuView.search) {
        return true;
    }

    return String(unit.name ?? "").toLowerCase().includes(ahuView.search);
}

/*
 * Rebuilds only the band sections, leaving the controls (and therefore the
 * search input's focus and caret) untouched. This is what makes typing in the
 * search box safe both between keystrokes and across the 10s poll.
 */
function ahuRenderSections() {
    if (!ahuSectionsHost) {
        return;
    }

    ahuSectionsHost.innerHTML = "";

    const visible = ahuUnits.filter(ahuMatchesSearch);

    if (visible.length === 0) {
        const empty = document.createElement("div");
        empty.className = "ahu-empty";
        empty.textContent = ahuView.search
            ? "No units match your search."
            : "No AHU units to display.";
        ahuSectionsHost.appendChild(empty);
        return;
    }

    /* "All" is one flat, name-ordered grid — no bands, no severity order. */
    if (ahuView.mode === AHU_VIEW_ALL) {
        ahuSectionsHost.appendChild(
            ahuBuildSection(
                { key: "normal", title: "ALL UNITS",
                  note: visible.length + (visible.length === 1 ? " unit" : " units") },
                visible.slice().sort(ahuCompareName)
            )
        );
        return;
    }

    AHU_BANDS.forEach(band => {
        const members = visible.filter(u => ahuBandOf(u) === band.key);

        if (members.length === 0) {
            return;
        }

        members.sort(band.key === "warning"
            ? (a, b) => (ahuDeviation(b) ?? 0) - (ahuDeviation(a) ?? 0) || ahuCompareName(a, b)
            : ahuCompareName);

        ahuSectionsHost.appendChild(
            ahuBuildSection(
                Object.assign({}, band, {
                    note: band.key === "normal"
                        ? members.length + (members.length === 1 ? " unit" : " units")
                        : band.note
                }),
                members
            )
        );
    });
}

function ahuBuildSection(band, units) {
    const section = document.createElement("section");
    section.className = "ahu-section is-" + band.key;

    const head = document.createElement("div");
    head.className = "ahu-section-head";

    const title = document.createElement("h2");
    title.className = "ahu-section-title";
    title.textContent = band.title;
    head.appendChild(title);

    if (band.note) {
        const note = document.createElement("span");
        note.className = "ahu-section-note";
        note.textContent = band.note;
        head.appendChild(note);
    }

    section.appendChild(head);

    const cards = document.createElement("div");
    cards.className = "ahu-cards";

    units.forEach(unit => cards.appendChild(ahuCreateCard(unit)));

    section.appendChild(cards);

    return section;
}

function ahuBuildTile(label, value, modifier) {
    const tile = document.createElement("div");
    tile.className = "ahu-tile " + modifier;

    const number = document.createElement("span");
    number.className = "ahu-tile-value";
    number.textContent = value;

    const text = document.createElement("span");
    text.className = "ahu-tile-label";
    text.textContent = label;

    tile.appendChild(number);
    tile.appendChild(text);

    return tile;
}

function renderAhuDashboard(units) {
    ahuUnits = Array.isArray(units) ? units : [];
    ahuSectionsHost = null;

    sectionTitle.textContent = "AHU";
    unitCount.textContent = ahuUnits.length + " units";

    /* Health counts, plus the independent operating counts. */
    const counts = { critical: 0, warning: 0, normal: 0, unknown: 0 };
    const ops = { on: 0, off: 0, unknown: 0 };

    ahuUnits.forEach(unit => {
        counts[ahuBandOf(unit)] += 1;
        ops[ahuRunState(unit)] += 1;
    });

    const page = document.createElement("div");
    page.className = "ahu-dashboard";

    /* ---- Summary tiles: HEALTH ---- */
    const summary = document.createElement("div");
    summary.className = "ahu-summary";
    summary.appendChild(ahuBuildTile("CRITICAL", counts.critical, "is-critical"));
    summary.appendChild(ahuBuildTile("WARNING", counts.warning, "is-warning"));
    summary.appendChild(ahuBuildTile("NORMAL", counts.normal, "is-normal"));
    summary.appendChild(ahuBuildTile("UNKNOWN", counts.unknown, "is-unknown"));
    page.appendChild(summary);

    /*
     * Operating status is its own row, not a fifth health tile. A unit can be
     * stopped and healthy at the same time, so summing these into the health
     * tiles would make the two rows disagree with the sections below.
     */
    const opRow = document.createElement("div");
    opRow.className = "ahu-ops";

    [
        { key: "on",      label: "RUNNING", count: ops.on },
        { key: "off",     label: "STOPPED", count: ops.off },
        { key: "unknown", label: "OP UNKNOWN", count: ops.unknown }
    ].forEach(item => {
        const el = document.createElement("span");
        el.className = "ahu-op is-" + item.key;

        const dot = document.createElement("span");
        dot.className = "ahu-op-dot";
        el.appendChild(dot);

        const text = document.createElement("span");
        text.textContent = item.label + " " + item.count;
        el.appendChild(text);

        opRow.appendChild(el);
    });

    page.appendChild(opRow);

    /* ---- Sort chips + search ---- */
    const controls = document.createElement("div");
    controls.className = "ahu-controls";

    const chips = [
        { mode: AHU_VIEW_ISSUES, label: "Issues First" },
        { mode: AHU_VIEW_ALL, label: "All " + ahuUnits.length }
    ];

    chips.forEach(chip => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "ahu-chip" + (ahuView.mode === chip.mode ? " is-on" : "");
        button.textContent = chip.label;
        button.addEventListener("click", () => {
            if (ahuView.mode === chip.mode) {
                return;
            }

            ahuView.mode = chip.mode;
            controls.querySelectorAll(".ahu-chip").forEach(other => {
                other.classList.toggle("is-on", other === button);
            });
            ahuRenderSections();
        });
        controls.appendChild(button);
    });

    const search = document.createElement("input");
    search.type = "search";
    search.className = "ahu-search";
    search.placeholder = "Search…";
    search.value = ahuView.searchRaw;
    search.setAttribute("aria-label", "Search AHU units");
    search.addEventListener("input", () => {
        ahuView.searchRaw = search.value;
        ahuView.search = search.value.trim().toLowerCase();
        ahuRenderSections();
    });
    controls.appendChild(search);

    page.appendChild(controls);

    /* ---- Band sections ---- */
    const host = document.createElement("div");
    ahuSectionsHost = host;
    page.appendChild(host);

    grid.appendChild(page);

    ahuRenderSections();

    /*
     * The 10s poll rebuilds this whole subtree, which would otherwise drop the
     * caret out of the search box mid-typing. The value survives in ahuView;
     * only the focus has to be handed back.
     */
    if (ahuView.searchFocused) {
        search.focus();
        search.setSelectionRange(search.value.length, search.value.length);
    }
}

/* =========================================================
   HVAC SC SEVERITY DASHBOARD
   ========================================================= */

const HVAC_SC_VIEW_ISSUES = "issues";
const HVAC_SC_VIEW_ALL = "all";

/*
 * View state lives outside the DOM, like ahuView, so the 10s poll's re-render
 * cannot silently reset the reader's sort, search, or open cards.
 */
const hvacScView = {
    mode: HVAC_SC_VIEW_ISSUES,
    /* Exactly what the reader typed, so the box redraws unchanged. */
    searchRaw: "",
    /* Normalised once here; matching never re-derives it. */
    search: "",
    /*
     * Whether the search box held focus at the last render. Captured by
     * reading document.activeElement at the TOP of renderEquipment(), before
     * the grid is cleared — clearing detaches the input without firing blur.
     */
    searchFocused: false,
    /* Which issue cards the reader opened, keyed by unit label, so the poll's
     * full re-render does not collapse them mid-read. */
    expanded: {}
};

let hvacScSectionsHost = null;

const HVAC_SC_BANDS = [
    { key: "critical", title: "CRITICAL", note: "alarm from the system" },
    { key: "warning",  title: "WARNING",  note: "value outside its configured limit" },
    { key: "normal",   title: "NORMAL",   note: "values within limits" },
    { key: "unknown",  title: "UNKNOWN",  note: "no health reading" }
];

/*
 * Read a numeric field WITHOUT letting a missing value become a number.
 *
 * Number(null) is 0 and Number("") is 0, so a plain Number() turns "nobody
 * measured this" into a measured zero — which on this page would report an
 * unread chiller as 0.0 °C and as stopped. api_hvac_sc.php leaves every field
 * null when its point is absent, so this is a reachable state, not a
 * hypothetical. Kept separate from the AHU dashboard's reader so neither
 * page's behaviour can drift when the other changes.
 */
function hvacScNum(value) {
    if (value === null || value === undefined || value === "") {
        return null;
    }

    const n = Number(value);

    return Number.isFinite(n) ? n : null;
}

/*
 * The metrics shown per unit, with exactly the bounds the existing cards
 * already use — no new threshold is introduced here. Chiller EL/CE carry the
 * API's evap_leaving_temp_max / cond_entering_temp_max; CCP/CHWP/CT current
 * carries current_min/current_max. SP, RLA, EE, CL, Freq and Drv Tmp have no
 * bound in the data, so they can never raise a WARNING.
 */
function hvacScMetrics(kind, unit) {
    if (kind === "chiller") {
        return [
            { label: "SP",  value: unit.setpoint,           suffix: " °C" },
            { label: "RLA", value: unit.rla,                suffix: " %" },
            { label: "EL",  value: unit.evap_leaving_temp,  suffix: " °C", max: unit.evap_leaving_temp_max },
            { label: "EE",  value: unit.evap_entering_temp, suffix: " °C" },
            { label: "CE",  value: unit.cond_entering_temp, suffix: " °C", max: unit.cond_entering_temp_max },
            { label: "CL",  value: unit.cond_leaving_temp,  suffix: " °C" }
        ];
    }

    const metrics = [
        { label: "Freq",    value: unit.frequency,         suffix: " Hz" },
        { label: "Curr",    value: unit.current,           suffix: " A", min: unit.current_min, max: unit.current_max },
        { label: "Drv Tmp", value: unit.drive_temperature, suffix: " °C" }
    ];

    /*
     * return_temp is a CT-only reading: the API fills it from the cell's own
     * "Return Temp" point and leaves the key absent on CCP / CHWP. Testing for
     * the key rather than for the type keeps this honest if a CCP ever gains
     * the point — and stops a permanently "-- °C" chip appearing on every
     * pump that can never have one.
     */
    if (Object.prototype.hasOwnProperty.call(unit, "return_temp")) {
        metrics.push({ label: "Ret Tmp", value: unit.return_temp, suffix: " °C" });
    }

    return metrics;
}

function hvacScFormat(value, suffix) {
    const n = hvacScNum(value);

    return n === null ? "--" + suffix : n.toFixed(1) + suffix;
}

/* Is this one metric outside its own configured bound? */
function hvacScIsViolation(metric) {
    return isOutOfRange(metric.value, metric.min, metric.max);
}

/* Metrics currently outside their own configured bound. Empty when none are. */
function hvacScViolations(kind, unit) {
    return hvacScMetrics(kind, unit).filter(hvacScIsViolation);
}

/*
 * HEALTH band for HVAC SC: what the unit's own readings say.
 *
 * `run` is deliberately NOT part of this decision. A stopped unit is not
 * necessarily unhealthy, and a unit whose status point never reported is not
 * stopped. Operating state is the separate hvacScRunState axis, drawn as the
 * dot on every card and counted in its own summary tile.
 *
 * Precedence is alarm > out-of-range value > no health reading.
 */
function hvacScBandOf(kind, unit) {
    if (hvacScNum(unit.alarm) === 1) {
        return "critical";
    }

    if (hvacScViolations(kind, unit).length > 0) {
        return "warning";
    }

    /*
     * No alarm point AND no readings at all: nothing was measured, so there is
     * no basis for calling the unit healthy. This is the same UNKNOWN band the
     * AHU page uses.
     */
    if (hvacScNum(unit.alarm) === null &&
        !hvacScMetrics(kind, unit).some(m => hvacScNum(m.value) !== null)) {
        return "unknown";
    }

    return "normal";
}

/*
 * OPERATING state: what the unit's status point says. run=1 on, run=0 off,
 * anything else UNKNOWN. Never inferred from current, frequency, temperature
 * or the health bounds — a CHWP reporting 0.0 Hz is only known to be stopped
 * if its own status point says so.
 */
function hvacScRunState(unit) {
    const run = hvacScNum(unit.run);

    if (run === 1) {
        return "on";
    }

    if (run === 0) {
        return "off";
    }

    return "unknown";
}

/*
 * "We cannot see this unit" and "this unit is switched off" are different
 * facts, so they get different words rather than both reading as OFF.
 */
function hvacScRunTag(unit) {
    const state = hvacScRunState(unit);

    if (state === "off") {
        return "OFF";
    }

    return state === "on" ? "" : "UNKNOWN";
}

function hvacScBadge(kind, unit, band) {
    if (band === "critical") {
        return "ALARM";
    }

    if (band === "warning") {
        const v = hvacScViolations(kind, unit)[0];

        if (!v) {
            return "OUT OF RANGE";
        }

        const n = hvacScNum(v.value);
        const max = hvacScNum(v.max);
        const min = hvacScNum(v.min);

        if (max !== null && n !== null && n > max) {
            return "▲ OUT OF RANGE";
        }

        if (min !== null && n !== null && n < min) {
            return "▼ OUT OF RANGE";
        }

        return "OUT OF RANGE";
    }

    if (band === "unknown") {
        return "NO DATA";
    }

    return "";
}

/*
 * One line saying why this unit is in this band, in the reader's terms. Only
 * claims what the data supports: for an alarm it says the cause is not in this
 * data source rather than guessing one, and for a violation it names the
 * metric and the limit it crossed.
 */
function hvacScReason(kind, unit, band) {
    if (band === "critical") {
        return "Alarm reported by the system. The cause is not included in this data source.";
    }

    if (band === "warning") {
        return hvacScViolations(kind, unit).map(m => {
            const n = hvacScNum(m.value);
            const max = hvacScNum(m.max);
            const min = hvacScNum(m.min);

            if (max !== null && n !== null && n > max) {
                return m.label + " " + hvacScFormat(m.value, m.suffix)
                    + " is above its limit of " + max + m.suffix + ".";
            }

            if (min !== null && n !== null && n < min) {
                return m.label + " " + hvacScFormat(m.value, m.suffix)
                    + " is below its limit of " + min + m.suffix + ".";
            }

            return m.label + " is outside its configured limit.";
        }).join(" ");
    }

    if (band === "unknown") {
        return "Neither an alarm point nor any reading was reported by this data source.";
    }

    return "";
}

/* Compact one-line summary for a healthy row, e.g. "SP 9.0 °C · RLA 55.9 %". */
function hvacScSummary(kind, unit) {
    const picks = kind === "chiller"
        ? [["SP", unit.setpoint, " °C"], ["RLA", unit.rla, " %"]]
        : [["Freq", unit.frequency, " Hz"], ["Curr", unit.current, " A"]];

    return picks
        .filter(p => hvacScNum(p[1]) !== null)
        .map(p => p[0] + " " + hvacScFormat(p[1], p[2]))
        .join(" · ");
}

function hvacScAllUnits() {
    const chiller = Array.isArray(allData.CHILLER) ? allData.CHILLER : [];
    const ccp = Array.isArray(allData.CCP) ? allData.CCP : [];
    const chwp = Array.isArray(allData.CHWP) ? allData.CHWP : [];
    const ct = Array.isArray(allData.CT) ? allData.CT : [];

    const out = [];

    chiller.forEach(u => out.push({ kind: "chiller", prefix: "Chiller", label: "Chiller " + u.name, unit: u }));
    ccp.forEach(u => out.push({ kind: "compact", prefix: "CCP", label: "CCP " + u.name, unit: u }));
    chwp.forEach(u => out.push({ kind: "compact", prefix: "CHWP", label: "CHWP " + u.name, unit: u }));
    ct.forEach(u => out.push({ kind: "compact", prefix: "CT", label: "CT " + u.name, unit: u }));

    return out;
}

const HVAC_SC_PREFIX_ORDER = { "Chiller": 0, "CCP": 1, "CHWP": 2, "CT": 3 };

function hvacScCompare(a, b) {
    const pa = HVAC_SC_PREFIX_ORDER[a.prefix] ?? 9;
    const pb = HVAC_SC_PREFIX_ORDER[b.prefix] ?? 9;

    if (pa !== pb) {
        return pa - pb;
    }

    const na = hvacScNum(String(a.unit.name).match(/\d+/)?.[0]);
    const nb = hvacScNum(String(b.unit.name).match(/\d+/)?.[0]);

    if (na !== nb) {
        return (na ?? 0) - (nb ?? 0);
    }

    return String(a.unit.name).localeCompare(String(b.unit.name));
}

function hvacScMatchesSearch(label) {
    if (!hvacScView.search) {
        return true;
    }

    return String(label).toLowerCase().includes(hvacScView.search);
}

/*
 * The chips and the search box, built once per render and left in place when
 * only the sections are redrawn — that is what keeps the caret in the box
 * across keystrokes and across the 10s poll.
 */
function hvacScValueChip(metric, state) {
    const chip = document.createElement("span");
    chip.className = "hsc-v" + (state ? " " + state : "");

    const small = document.createElement("small");
    small.textContent = metric.label;
    chip.appendChild(small);

    const val = document.createElement("b");
    val.textContent = hvacScFormat(metric.value, metric.suffix);
    chip.appendChild(val);

    if (state === "is-bad") {
        const n = hvacScNum(metric.value);
        const mx = hvacScNum(metric.max);
        const mn = hvacScNum(metric.min);
        const lim = (mx !== null && n > mx) ? "max " + mx
                  : (mn !== null && n < mn) ? "min " + mn : "";

        if (lim) {
            const em = document.createElement("em");
            em.textContent = lim;
            chip.appendChild(em);
        }
    }

    return chip;
}

/*
 * An issue card: operating dot, name, health badge, one line of reason, the
 * values that matter, and an expander for the rest. Open state is remembered
 * per unit in hvacScView.
 *
 * The badge states the HEALTH finding and the dot states the OPERATING state,
 * so "OUT OF RANGE while stopped" is readable as exactly that instead of one
 * of the two facts overwriting the other.
 */
function hvacScIssueCard(entry) {
    const { kind, label, unit } = entry;
    const band = hvacScBandOf(kind, unit);
    const metrics = hvacScMetrics(kind, unit);

    const card = document.createElement("div");
    card.className = "hsc-card is-" + band;
    card.dataset.band = band;
    card.dataset.run = hvacScRunState(unit);

    const row = document.createElement("div");
    row.className = "hsc-card-row";

    row.appendChild(hvacScRunDot(unit));

    const name = document.createElement("span");
    name.className = "hsc-card-name";
    name.textContent = label;
    row.appendChild(name);

    const badgeText = hvacScBadge(kind, unit, band);

    if (badgeText) {
        const badge = document.createElement("span");
        badge.className = "hsc-badge";
        badge.textContent = badgeText;
        row.appendChild(badge);
    }

    card.appendChild(row);

    const reason = hvacScReason(kind, unit, band);

    if (reason) {
        const why = document.createElement("p");
        why.className = "hsc-why";
        why.textContent = reason;
        card.appendChild(why);
    }

    const isOpen = hvacScView.expanded[label] === true;

    /*
     * Collapsed: every metric that actually has a reading.
     *
     * It used to show only the violated metrics, which meant a chiller whose CE
     * had crossed its limit rendered as a single CE chip — the other five
     * parameters it was reporting (SP, RLA, EL, EE, CL) were invisible until
     * the reader knew to press "Show all values". The violation is still the
     * thing that stands out, because hvacScValueChip paints it with is-bad and
     * the card itself carries the warning band, but it no longer hides the rest
     * of the unit's state.
     *
     * Missing readings are still never dressed up as values: they stay in the
     * expanded view as "--", where their absence is visible but not implied.
     */
    const shown = metrics.filter(m => hvacScNum(m.value) !== null);

    const chips = document.createElement("div");
    chips.className = "hsc-chips";
    chips.dataset.n = shown.length;

    if (shown.length === 0) {
        const none = document.createElement("span");
        none.className = "hsc-v n";
        none.textContent = "no readings";
        chips.appendChild(none);
    } else {
        shown.forEach(m => chips.appendChild(
            hvacScValueChip(m, isOutOfRange(m.value, m.min, m.max) ? "is-bad" : "")
        ));
    }

    card.appendChild(chips);

    /*
     * Expanded: every metric, including the ones with no reading, so the
     * absence is visible rather than hidden by the collapse.
     */
    if (isOpen) {
        const all = document.createElement("div");
        all.className = "hsc-chips is-all";
        all.dataset.n = metrics.length;

        metrics.forEach(m => all.appendChild(
            hvacScValueChip(
                m,
                hvacScNum(m.value) === null
                    ? "n"
                    : (isOutOfRange(m.value, m.min, m.max) ? "is-bad" : "")
            )
        ));

        card.appendChild(all);
    }

    const more = document.createElement("button");
    more.type = "button";
    more.className = "hsc-more";
    more.textContent = isOpen ? "Hide values ▴" : "Show all values ▾";
    more.setAttribute("aria-expanded", isOpen ? "true" : "false");
    more.addEventListener("click", () => {
        if (isOpen) {
            delete hvacScView.expanded[label];
        } else {
            hvacScView.expanded[label] = true;
        }

        hvacScRenderSections();
    });

    card.appendChild(more);

    return card;
}

function hvacScSummaryParts(kind, unit) {
    const picks = kind === "chiller"
        ? [["SP", unit.setpoint, " \u00b0C"], ["RLA", unit.rla, " %"]]
        : [["Freq", unit.frequency, " Hz"], ["Curr", unit.current, " A"]];

    return picks.map(p => [p[0], hvacScFormat(p[1], p[2])]);
}

function hvacScNormalRow(entry) {
    const { kind, label, unit } = entry;
    const run = hvacScRunState(unit);

    const row = document.createElement("div");
    row.className = "hsc-o";
    row.dataset.run = run;

    const head = document.createElement("div");
    head.className = "hsc-o-head";
    head.appendChild(hvacScRunDot(unit));

    const name = document.createElement("b");
    name.textContent = label;

    const st = document.createElement("small");
    st.textContent = run === "on" ? "Running" : (run === "off" ? "Stopped" : "Unknown");
    name.appendChild(st);
    head.appendChild(name);
    row.appendChild(head);

    /* Every unit shows every parameter, same as the All Units tab. */
    const metrics = hvacScMetrics(kind, unit);

    const chips = document.createElement("div");
    chips.className = "hsc-chips";
    chips.dataset.n = metrics.length;

    metrics.forEach(m => chips.appendChild(
        hvacScValueChip(
            m,
            hvacScNum(m.value) === null
                ? "n"
                : (isOutOfRange(m.value, m.min, m.max) ? "is-bad" : "")
        )
    ));

    row.appendChild(chips);

    return row;
}

function hvacScBuildSection(band, entries) {
    const section = document.createElement("section");
    section.className = "hsc-section is-" + band.key;

    const head = document.createElement("div");
    head.className = "hsc-section-head";

    const title = document.createElement("h2");
    title.className = "hsc-section-title";
    title.textContent = band.title;
    head.appendChild(title);

    const note = document.createElement("span");
    note.className = "hsc-section-note";
    note.textContent = entries.length + (entries.length === 1 ? " unit" : " units");
    head.appendChild(note);

    section.appendChild(head);

    if (band.key === "normal") {
        /* Healthy units are one compact container of rows, not full cards. */
        const box = document.createElement("div");
        box.className = "hsc-ok";
        entries.forEach(e => box.appendChild(hvacScNormalRow(e)));
        section.appendChild(box);
        return section;
    }

    /*
     * The cards get their own grid container so the stylesheet can flow them
     * into columns on a wide display. On a phone that grid is a single column,
     * so this is the same stack of full-width cards it has always been.
     */
    const cards = document.createElement("div");
    cards.className = "hsc-cards";
    entries.forEach(e => cards.appendChild(hvacScIssueCard(e)));
    section.appendChild(cards);

    return section;
}

/*
 * Rebuilds only the band sections, leaving the controls (and therefore the
 * search input's focus and caret) untouched.
 */
function hvacScRenderSections() {
    if (!hvacScSectionsHost) {
        return;
    }

    /*
     * Mark which view is on screen so the stylesheet can hand the All Units
     * matrix its original grid padding back. Without this the issues view's
     * padding rule (same specificity, later in the file) would silently
     * shrink the matrix's bottom and mobile gutters.
     */
    const page = hvacScSectionsHost.closest(".hvac-sc-dashboard");

    if (page) {
        page.classList.toggle("is-all", hvacScView.mode === HVAC_SC_VIEW_ALL);
    }

    hvacScSectionsHost.innerHTML = "";

    if (hvacScView.mode === HVAC_SC_VIEW_ALL) {
        hvacScSectionsHost.appendChild(hvacScMatrixElement());
        return;
    }

    const visible = hvacScAllUnits().filter(e => hvacScMatchesSearch(e.label));

    if (visible.length === 0) {
        const empty = document.createElement("div");
        empty.className = "hsc-empty";
        empty.textContent = hvacScView.search
            ? "No units match your search."
            : "No HVAC SC units to display.";
        hvacScSectionsHost.appendChild(empty);
        return;
    }

    HVAC_SC_BANDS.forEach(band => {
        const members = visible.filter(e => hvacScBandOf(e.kind, e.unit) === band.key);

        if (members.length === 0) {
            return;
        }

        members.sort(hvacScCompare);

        hvacScSectionsHost.appendChild(hvacScBuildSection(band, members));
    });
}

function hvacScBuildTile(label, value, modifier) {
    const tile = document.createElement("div");
    tile.className = "hsc-tile " + modifier;

    const number = document.createElement("b");
    number.textContent = value;

    const text = document.createElement("span");
    text.textContent = label;

    tile.appendChild(number);
    tile.appendChild(text);

    return tile;
}

function renderHvacSc() {
    sectionTitle.textContent = "HVAC SC";

    const units = hvacScAllUnits();

    unitCount.textContent = units.length + " units";

    /* Health counts, plus the independent operating counts. */
    const counts = { critical: 0, warning: 0, normal: 0, unknown: 0 };
    const ops = { on: 0, off: 0, unknown: 0 };

    units.forEach(e => {
        counts[hvacScBandOf(e.kind, e.unit)] += 1;
        ops[hvacScRunState(e.unit)] += 1;
    });

    const page = document.createElement("div");
    page.className = "hvac-sc-dashboard";

    /* ---- Summary: health tiles ---- */
    const summary = document.createElement("div");
    summary.className = "hsc-summary";
    summary.appendChild(hvacScBuildTile("CRITICAL", counts.critical, "is-critical"));
    summary.appendChild(hvacScBuildTile("WARNING", counts.warning, "is-warning"));
    summary.appendChild(hvacScBuildTile("TOTAL UNIT", units.length, "is-total"));
    page.appendChild(summary);

    /*
     * Operating status is a separate strip, not more health tiles: a stopped
     * unit can be perfectly healthy, so the two counts are not partitions of
     * the same total.
     */
    const opRow = document.createElement("div");
    opRow.className = "hsc-ops";

    [
        { key: "on",      label: "RUNNING", count: ops.on },
        { key: "off",     label: "STOPPED", count: ops.off },
        { key: "unknown", label: "OP UNKNOWN", count: ops.unknown }
    ].forEach(item => {
        const el = document.createElement("span");
        el.className = "hsc-op is-" + item.key;

        const dot = document.createElement("span");
        dot.className = "hsc-op-dot";
        el.appendChild(dot);

        const text = document.createElement("span");
        text.textContent = item.label + " " + item.count;
        el.appendChild(text);

        opRow.appendChild(el);
    });

    page.appendChild(opRow);

    /* ---- Sort chips + search ---- */
    const controls = document.createElement("div");
    controls.className = "hsc-controls";

    [
        { mode: HVAC_SC_VIEW_ISSUES, label: "Issues First" },
        { mode: HVAC_SC_VIEW_ALL, label: "All Units" }
    ].forEach(chip => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "hsc-chip" + (hvacScView.mode === chip.mode ? " is-on" : "");
        button.textContent = chip.label;
        button.addEventListener("click", () => {
            if (hvacScView.mode === chip.mode) {
                return;
            }

            hvacScView.mode = chip.mode;
            controls.querySelectorAll(".hsc-chip").forEach(other => {
                other.classList.toggle("is-on", other === button);
            });
            hvacScRenderSections();
        });
        controls.appendChild(button);
    });

    const search = document.createElement("input");
    search.type = "search";
    search.className = "hsc-search";
    search.placeholder = "Search…";
    search.value = hvacScView.searchRaw;
    search.setAttribute("aria-label", "Search HVAC SC units");
    search.addEventListener("input", () => {
        hvacScView.searchRaw = search.value;
        hvacScView.search = search.value.trim().toLowerCase();
        hvacScRenderSections();
    });
    controls.appendChild(search);

    page.appendChild(controls);

    const host = document.createElement("div");
    hvacScSectionsHost = host;
    page.appendChild(host);

    grid.appendChild(page);

    hvacScRenderSections();

    /* The poll rebuilds this subtree; hand the caret back if it was ours. */
    if (hvacScView.searchFocused) {
        search.focus();
        search.setSelectionRange(search.value.length, search.value.length);
    }
}

/*
 * The "All Units" view.
 *
 * Same inventory as before — the G1-G2 / G3 groups, the four categories, the
 * per-category name lists and the exact name lookups are unchanged, so the
 * membership and ordering are identical. What changed is the presentation:
 * each category is now a full-width block whose unit cards flow into as many
 * columns as the display has room for, instead of four hand-tuned matrix
 * columns that wrapped names and values at narrow widths.
 *
 * The category name lists are the original ones: chillers/CCP/CHWP are matched
 * on the bare number, cooling towers on the upper-cased tower name.
 */
function hvacScGroupElement(title, categories, present) {
    const group = document.createElement("section");
    group.className = "hvac-group-card";

    const body = document.createElement("div");
    body.className = "hvac-group-body";

    let count = 0;

    categories.forEach(cat => {
        const block = document.createElement("div");
        /*
         * The category kind drives the card grid's minimum track width. A
         * chiller card carries six two-part chips plus a name row that must
         * hold "▲ OUT OF RANGE", so it needs far more width than a pump card
         * with three short chips. Without this marker both got the same floor,
         * which forced pumps and towers onto one full-width column on a phone.
         */
        block.className = "hvac-cat is-" + String(cat.kind).toLowerCase();

        const head = document.createElement("div");
        head.className = "hvac-col-header";
        head.textContent = cat.header;
        block.appendChild(head);

        const items = document.createElement("div");
        items.className = "hvac-cat-items";

        cat.names.forEach(name => {
            const unit = present(cat, name);

            if (unit) {
                items.appendChild(hvacScUnitCard(unit));
                count++;
            }
        });

        block.appendChild(items);
        body.appendChild(block);
    });

    const header = document.createElement("div");
    header.className = "hvac-group-header";

    const titleEl = document.createElement("span");
    titleEl.className = "hvac-group-title";
    titleEl.textContent = title;

    const countEl = document.createElement("span");
    countEl.className = "hvac-group-count";
    countEl.textContent = count + " units";

    header.appendChild(titleEl);
    header.appendChild(countEl);
    group.appendChild(header);
    group.appendChild(body);

    return group;
}

function hvacScMatrixElement() {
    const chiller = Array.isArray(allData.CHILLER) ? allData.CHILLER : [];
    const ccp = Array.isArray(allData.CCP) ? allData.CCP : [];
    const chwp = Array.isArray(allData.CHWP) ? allData.CHWP : [];
    const ct = Array.isArray(allData.CT) ? allData.CT : [];

    const seq = (kind, prefix, from, to) => {
        const names = [];

        for (let n = from; n <= to; n++) {
            names.push(String(n));
        }

        return { kind, header: prefix + " (" + from + "-" + to + ")", names };
    };

    const towers = (header, list) => ({ kind: "CT", header, names: list });

    const lookup = {
        CHILLER: n => chiller.find(x => String(x.name) === String(n)),
        CCP: n => ccp.find(x => String(x.name) === String(n)),
        CHWP: n => chwp.find(x => String(x.name) === String(n)),
        CT: n => ct.find(x => String(x.name).toUpperCase() === String(n).toUpperCase())
    };

    const find = (cat, name) => {
        const unit = lookup[cat.kind](name);

        if (!unit) {
            return null;
        }

        return {
            kind: cat.kind === "CHILLER" ? "chiller" : "compact",
            prefix: cat.kind === "CHILLER" ? "Chiller" : cat.kind,
            label: (cat.kind === "CHILLER" ? "Chiller " : cat.kind + " ") + unit.name,
            unit
        };
    };

    const container = document.createElement("div");
    container.className = "hvac-sc-container";

    container.appendChild(hvacScGroupElement("HVAC G1-G2", [
        seq("CHILLER", "CHILLER", 1, 5),
        seq("CCP", "CCP", 1, 5),
        seq("CHWP", "CHWP", 1, 5),
        towers("COOLING TOWER (1A-6B)", [
            "1A", "1B", "2A", "2B", "3A", "3B",
            "4A", "4B", "5A", "5B", "6A", "6B"
        ])
    ], find));

    container.appendChild(hvacScGroupElement("HVAC G3", [
        seq("CHILLER", "CHILLER", 6, 9),
        seq("CCP", "CCP", 6, 9),
        seq("CHWP", "CHWP", 6, 9),
        towers("COOLING TOWER (9A-13B)", [
            "9A", "9B", "10A", "10B", "11A", "11B",
            "12A", "12B", "13A", "13B"
        ])
    ], find));

    return container;
}

function renderEquipment() {
    /*
     * Read focus BEFORE the grid is cleared. Clearing detaches the search
     * input without firing blur, and a detached node is no longer
     * document.activeElement, so after this point the answer is unrecoverable.
     * See the ahuView.searchFocused comment for why a listener cannot do it.
     */
    const active = document.activeElement;
    ahuView.searchFocused = !!(active && active.classList
        && active.classList.contains("ahu-search"));
    hvacScView.searchFocused = !!(active && active.classList
        && active.classList.contains("hsc-search"));

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
     * AHU gets the severity dashboard: KRITIS / PERHATIAN / OFF / NORMAL bands,
     * issues first, with a search box. Every other equipment type keeps the
     * flat grid it has always had.
     */
    if (currentEquipment === "AHU") {
        renderAhuDashboard(data);
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

