# GLOBAL ALARM — PHASE 2B
# IMPLEMENT BACNET + EF EQUIPMENT ALARM WRITERS

## APPROVED DESIGN DECISIONS

J1:
source menggunakan penulis nyata:
- BACNET_POLLER
- EF_POLLER
Jangan gunakan AHU_POLLER/HVAC_SC_POLLER/ROOM_POLLER.

J2:
native BI ALARM menggunakan:
metric = STATUS

J3:
shared G3 condenser sensor:
- satu physical/shared sensor = satu event
- jangan membuat 4 event untuk CHILLER 6,7,8,9
- gunakan identitas sensor yang benar-benar ada
- jika equipment identity shared belum dapat ditentukan secara pasti dari source,
  gunakan NOT ESTABLISHED dan STOP pada bagian tersebut.
Jangan menebak.

J4:
EF equipment alarm:
- ef_current.status 1 -> 0 = ALARM
- 0 -> 1 = CLEARED
- granularitas berdasarkan ef_points.id
- jangan memasukkan EF communication event pada phase ini.

J5:
native BI:
- ACTIVE event menyimpan value = 1
- ketika CLEARED, historical row tetap menyimpan value = 1
- jangan mengubah historical value menjadi 0.

J6:
EF COMM belum masuk alarm_events Phase 2B.
Jangan ubah ef_communication_events.
Jangan membuat kontrak COMM pada phase ini.

Daikin:
BELUM diimplementasikan karena event class Daikin belum established.

---

## OBJECTIVE

Implementasikan writer global alarm ke:

hvac_current.alarm_events

untuk:

1. BACNET WARNING
2. BACNET native ALARM
3. EF equipment ALARM

Gunakan data current yang sudah dipolling.

---

## HARD SAFETY RULES

Sebelum coding:

- inspect repository
- inspect poller yang akan diubah
- inspect current DB access/connection pattern
- inspect existing EF alarm transition logic
- inspect existing BACNET polling loop

Jangan mengarang filename, function, table, atau connection.

Jangan membuat database schema baru.

Jangan mengubah schema alarm_events.

Jangan mengubah threshold.

Jangan mengubah polling interval.

Jangan mengubah format current tables.

Jangan menghapus existing alarm logic.

Jangan menghapus ef_alarm_events.

Jangan menghapus ef_communication_events.

Jangan mengubah frontend/API.

Jangan mengubah PROJECT.md pada phase ini.

Jangan GRANT privilege.

Jangan commit/push.

Jika ada privilege/write-access problem:
STOP dan laporkan. Jangan mencari cara bypass privilege.

---

# PART A — BACNET POLLER

## A1. WARNING SOURCES

Implementasikan hanya threshold yang sudah established:

### AHU
AHU Duct Temperature:
- limit_max = 25
- ACTIVE: value > 25
- CLEAR: value <= 25

### ROOM
ROOM TEMP:
- limit_max = 30
- ACTIVE: value > 30
- CLEAR: value <= 30

ROOM RH:
- limit_max = 65
- ACTIVE: value > 65
- CLEAR: value <= 65

OUTDOOR:
- jangan dibuat WARNING.

### CHILLER
Evap Leaving:
- limit_max = 12
- ACTIVE: value > 12
- CLEAR: value <= 12

Condenser Entering:
- limit_max = 32
- ACTIVE: value > 32
- CLEAR: value <= 32

Gunakan hanya point yang sudah established.

### CCP
Current:
- limit_max = 56
- ACTIVE: value > 56
- CLEAR: value <= 56

### CHWP
Current:
- motor 1–3: limit_max = 56
- motor 4–9: limit_max = 73
- ACTIVE: value > applicable limit
- CLEAR: value <= applicable limit

### CT
Current:
- CT 1,2,3,4,9,10,11,12,13:
  limit_max = 30
- CT 5A,5B,6A,6B:
  limit_max = 12

JANGAN membuat synthetic CT 1A/1B current point.

Jika source hanya mempunyai CT 1 Current,
maka satu WARNING untuk CT 1.

CT 7 dan CT 8:
- tidak ada points
- jangan membuat event.

---

# PART B — BACNET NATIVE ALARM

Gunakan 137 established BI alarm points:

- AHU 93
- CHILLER 9
- CCP 9
- CHWP 9
- CT 17

ACTIVE:
BI value = 1

CLEAR:
BI value = 0

event_class:
ALARM

metric:
STATUS

source:
BACNET_POLLER

active_key:
NATIVE_ALARM:<point_id>:STATUS

value saat ACTIVE:
1

Historical CLEARED row:
tetap value = 1

Jangan memasukkan '* Status' points yang bukan alarm.

---

# PART C — ACTIVE_KEY

WARNING:

THRESHOLD_AI:<point_id>:<metric>:max

Gunakan metric yang sudah established:
- temp
- rh
- evap_leaving_temp
- cond_entering_temp
- current

Native:

NATIVE_ALARM:<point_id>:STATUS

EF:

EF_POINT:<ef_point_id>:STATUS

Jangan menggunakan database row id.

---

# PART D — RAISE/CLEAR ALGORITHM

Semua writer HARUS stateless terhadap process memory.

State ACTIVE harus ditentukan dari alarm_events.

### NORMAL -> ACTIVE

Jika condition ACTIVE:

Cari row:
status='ACTIVE'
AND active_key=<key>

Jika sudah ada:
- jangan INSERT
- jangan UPDATE raised_at
- tidak ada DB write.

Jika belum ada:
INSERT:

status='ACTIVE'
active_key=<key>
raised_at=<explicit WIB timestamp>

WARNING:
simpan value dan applicable limit.

ALARM:
value=1
limit NULL.

### ACTIVE -> CLEARED

Jika condition sudah normal:

Cari ACTIVE row berdasarkan active_key.

Jika ada:
UPDATE:
status='CLEARED'
cleared_at=<explicit WIB timestamp>
active_key=NULL

Jangan mengubah:
- raised_at
- value
- limit_min
- limit_max
- description

Jika tidak ada:
tidak ada DB write.

### CLEARED -> ACTIVE

INSERT row baru.

active_key boleh sama dengan historical CLEARED row karena
CLEARED selalu active_key=NULL.

---

# PART E — DUPLICATE SAFETY

alarm_events mempunyai:

UNIQUE(active_key)

Jika race condition menyebabkan INSERT mendapatkan:

ERROR 1062

perlakukan sebagai:
"event sudah ACTIVE"

Jangan crash poller.

Jangan membuat duplicate.

Namun jangan menyembunyikan error SQL lain.

Hanya error duplicate key untuk uq_alarm_active yang boleh
diperlakukan sebagai idempotent no-op.

---

# PART F — TIMESTAMP

Jangan mengandalkan DEFAULT database.

Writer harus menghasilkan timestamp eksplisit dalam WIB.

Gunakan connection/session timezone yang konsisten dengan pola
poller existing.

Target:
Asia/Jakarta / UTC+07:00.

Pastikan microsecond DATETIME(6) tetap didukung.

Jangan mengubah DB server timezone global.

---

# PART G — DB CONNECTION

Gunakan existing DB connection/pattern poller.

Jangan membuat koneksi database baru untuk setiap alarm.

Jangan membuat satu koneksi per point.

Gunakan koneksi poller yang sudah ada jika memungkinkan.

Jangan mengubah polling interval.

---

# PART H — EF EQUIPMENT ALARM

Gunakan existing EF poller logic sebagai sumber transition.

Existing semantic:

ef_current.status:
1 -> 0 = ALARM
0 -> 1 = RECOVERED

Global alarm:

ALARM:
event_class = ALARM
source = EF_POLLER
equip_type = EF
equipment = ef_points.ef_name
ef_point_id = ef_points.id
panel_no = ef_points.panel_no jika tersedia
metric = STATUS
value = 1
limit NULL

active_key:
EF_POINT:<ef_point_id>:STATUS

Ketika EF transition 1 -> 0:
buat ACTIVE global event.

Ketika transition 0 -> 1:
clear global ACTIVE event.

PENTING:
ef_current.status = 0 tidak selalu berarti equipment ALARM
jika tidak ada established transition/previous running state.

Pertahankan semantic transition existing poller.

Jangan membuat semua EF yang saat ini OFF menjadi historical
alarm sekaligus saat startup.

Startup/resync harus mengikuti existing EF poller semantics.

---

# PART I — EF EXISTING TABLES

Jangan menghapus atau mengganti:

ef_alarm_events
ef_communication_events

Phase 2B hanya menambahkan global alarm_events writer.

Jika existing EF event write sudah terjadi pada transition yang sama,
jangan menghapusnya.

---

# PART J — DESCRIPTION

Description harus menjelaskan event dengan informasi yang
tersedia saat event dibuat.

WARNING contoh:
"AHU 65 Duct Temperature 28.4 C above limit 25 C"

Native:
"AHU 65 Alarm active"

EF:
"EF 18 status changed from ON to OFF"

Jangan membuat description yang membutuhkan data yang tidak tersedia.

---

# PART K — SHARED G3 SENSOR

Cari point identity dan existing mapping secara langsung.

Jika sensor:
"Temp Return Head Conden G3"

memang hanya satu physical/shared point untuk CHILLER 6–9:

buat satu WARNING event untuk satu point_id.

Jangan membuat:
CHILLER 6
CHILLER 7
CHILLER 8
CHILLER 9

secara sintetis.

Equipment field harus menggunakan identity shared yang benar-benar
didukung source.

Jika tidak bisa ditentukan secara authoritative:
STOP implementasi bagian G3 dan laporkan.

---

# PART L — WRITE SCOPE

Phase 2B hanya boleh menulis:

hvac_current.alarm_events

Tidak boleh menulis:
- ai_current
- bi_current
- acc_current
- ef_current
- points
- threshold tables
- ef_alarm_events
- ef_communication_events

Poller tetap menjadi pemilik current data.

---

# PART M — VERIFICATION

Setelah implementasi, jangan langsung commit.

Lakukan verification read-only.

Minimum:

1. syntax check
2. service/process status
3. poller still running
4. alarm_events row count
5. existing current tables still updating
6. no duplicate active_key
7. no invalid CHECK state
8. no schema changes
9. no threshold changes
10. no polling interval changes

Query:

SELECT status, COUNT(*)
FROM alarm_events
GROUP BY status;

SELECT event_class, COUNT(*)
FROM alarm_events
GROUP BY event_class;

SELECT active_key, COUNT(*)
FROM alarm_events
WHERE status='ACTIVE'
GROUP BY active_key
HAVING COUNT(*) > 1;

Expected duplicate query:
0 rows.

IMPORTANT:
Do NOT fabricate test alarms by changing production points.

Do NOT force equipment alarm states.

Do NOT change thresholds.

Use naturally occurring state only.

If no alarm condition is active, alarm_events may remain 0 rows.
That is valid.

---

# PART N — REPORT

Laporkan:

1. Files changed
2. Exact functions/sections changed
3. BACNET WARNING implementation
4. BACNET native ALARM implementation
5. EF implementation
6. Shared G3 handling
7. active_key implementation
8. timestamp handling
9. duplicate handling
10. verification results
11. any unresolved issue
12. exact commands needed for manual testing, if any

STOP.

NO COMMIT.
NO PUSH.
NO PROJECT.md UPDATE.

