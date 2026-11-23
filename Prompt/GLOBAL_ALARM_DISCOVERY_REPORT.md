# GLOBAL ALARM — DISCOVERY REPORT

Read-only discovery. **Tidak ada tabel dibuat, tidak ada ALTER/INSERT/UPDATE/DELETE,
tidak ada PHP/CSS/JS/poller diubah, tidak ada commit/push.**

Metode: baca source di `C:\xampp\htdocs\hvac_monitoring`, query read-only ke
`hvac_current` (user `hvac_web`, SELECT-only), dan baca poller EF di
`C:\Projects\monitoring_ef` (read-only). Setiap klaim ditandai
**[VERIFIED]** (dibaca langsung dari kode/DB) atau **[INFERRED]** (kesimpulan).

---

## 1. Existing alarm implementation

### 1.1 Kesimpulan utama

Sistem alarm saat ini **hanya VISUAL dan realtime**, tidak ada penyimpanan alarm
sama sekali untuk AHU / HVAC_SC / ROOM. Yang ada di DB hanyalah dua tabel event
**khusus EF** (`ef_alarm_events`, `ef_communication_events`) yang **kosong** dan
**tidak dipakai oleh aplikasi web** — ditulis oleh poller EF, bukan oleh web app.

Tidak ada: tabel alarm generic, alarm history, acknowledge, severity,
current_alarm, alarm_code, alarm_type, alarm_message.

### 1.2 Inventaris implementasi alarm

| File | Bagian | Jenis | Sumber | Disimpan? |
|---|---|---|---|---|
| `api_ahu.php:99-112` | `$tempMax` dari `threshold_direct` | threshold AI | DB | tidak — hanya dikirim sebagai `temp_max` |
| `api_ahu.php:170-178` | `alarm` = BI `AHU n Alarm` | alarm BI | DB | tidak |
| `api_ahu.php:139,175,188` | `points.alarm/run/temp` = `point_id` | identitas point | DB | tidak (hanya dikirim) |
| `api_room.php:67-80` | `temp_max`, `rh_max` dari `threshold_direct` | threshold AI | DB | tidak |
| `api_hvac_sc.php:161-199` | `threshold_by_kw`, `unit_motor_kw`, `chillerThresholds` | threshold AI | DB | tidak |
| `api_hvac_sc.php:117-137` | `hvac_sc_current_limits()` | resolusi limit | DB | tidak |
| `api_hvac_sc.php:35-52` | `HVAC_SC_FIELDS` `alarm`/`al` → `alarm` | alarm BI | DB | tidak |
| `api_hvac_sc.php:372-376` | CT cell inherit `run/alarm/frequency/current/drive_temperature` | pewarisan | — | tidak |
| `index.php:74-91` | `getStatus()` → `alarm`/`run`/`off`/`unknown` | status card | API | tidak |
| `index.php:99-114` | `isOutOfRange(value,min,max)` | threshold AI | API | tidak |
| `index.php:121-125` | `applyAlarmClass()` → class `.value-alarm` | visual | API | tidak |
| `index.php:127-141` | `getEfStatus()` — **tanpa konsep alarm** | status EF | API | tidak |
| `style.css:324-343` | `.status-alarm` (orange `#d87500`) + `alarmPulse` | visual | — | tidak |
| `style.css:1002-1029` | `.value-alarm` (merah `#ef4444`) + `blink-alarm` | visual | — | tidak |
| `ef.css:143-153` | `.ef-card.status-off` blink | visual | — | tidak |
| `ef_polling_engine_claude_v2.py:754-830` | `INSERT INTO hvac_current.ef_alarm_events` | **event** | poller | **YA** |
| `ef_polling_engine_claude_v2.py:440-660` | `INSERT INTO hvac_current.ef_communication_events` | **event** | poller | **YA** |

### 1.3 Dua jenis "alarm" yang ada (dan sering tertukar)

1. **Status card (visual, dari BI)** — `alarm === 1` → `getStatus()` mengembalikan
   `"alarm"` → card jadi orange + `alarmPulse`. **Tidak ada event, tidak ada
   history, tidak ada timestamp alarm.** Ini murni warna.
2. **Alarm threshold (visual, dari AI)** — `isOutOfRange()` → `.value-alarm`
   (merah + blink). Juga **murni visual**, dievaluasi ulang setiap render
   (10 detik) dari nilai saat itu.

Keduanya hilang begitu kondisi normal kembali — tidak ada jejak bahwa alarm
pernah terjadi.

### 1.4 Dokumentasi terkait

- `PROJECT.md:208-297` (§8) — dokumentasi threshold/alarm yang paling akurat dan
  sesuai kode.
- `fix_value_alarm_color.md` — prompt perbaikan warna `.value-alarm` (merah vs
  hitam pada card OFF). **Sudah diimplementasikan** (`style.css:1002-1029`).
  Dokumen ini bukan desain sistem alarm.
- **Tidak ada** `alarm_code`, `alarm_type`, `severity`, `trip`, `fault`,
  `acknowledge`, atau `current_alarm` di seluruh repo **[VERIFIED]**.

---

## 2. Existing alarm-related tables (DB `hvac_current`)

21 tabel total. Yang relevan:

### 2.1 Tabel event EF — ADA tapi KOSONG

```sql
ef_alarm_events
  id              BIGINT UNSIGNED  PK AI
  ef_point_id     INT UNSIGNED     NOT NULL  MUL  (FK → ef_points.id, tidak di-enforce)
  event_type      ENUM('ALARM','RECOVERED')  NOT NULL
  previous_status TINYINT(1)       NOT NULL
  current_status  TINYINT(1)       NOT NULL
  event_time      DATETIME(6)      NOT NULL
  KEY idx_ef_alarm_point_time (ef_point_id, event_time)
  -- 0 rows

ef_communication_events
  id          BIGINT UNSIGNED  PK AI
  panel_no    INT(11)          NOT NULL  MUL
  ip_address  VARCHAR(45)      NOT NULL  MUL
  event_type  ENUM('COMM_ALARM','COMM_RECOVERED')  NOT NULL
  event_time  DATETIME(6)      NOT NULL
  KEY idx_ef_comm_ip_time    (ip_address, event_time)
  KEY idx_ef_comm_panel_time (panel_no, event_time)
  -- 0 rows
```

**Ini desain event/history yang sudah ada — tapi hanya untuk EF.** Ini preseden
penting: pola "event + previous/current status + event_time" sudah diterima di
proyek ini, dan **tidak ada** `severity`, `message`, `ack`, `point_id` generic,
atau `cleared_at`.

### 2.2 Tabel referensi threshold — TERISI dan DIPAKAI

```sql
threshold_direct  PK(equip_type, metric)   min_value FLOAT NULL, max_value FLOAT NULL   -- 5 rows
threshold_by_kw   PK(motor_kw)             min_value FLOAT NULL, max_value FLOAT NULL   -- 4 rows
unit_motor_kw     PK(equip_type,unit_name) motor_kw FLOAT NOT NULL                       -- 31 rows
```

Isi live **[VERIFIED]** (identik dengan `threshold_rules_seed.sql`):

| threshold_direct | metric | min | max |
|---|---|---|---|
| AHU | temp | NULL | 25 |
| ROOM | temp | NULL | 30 |
| ROOM | rh | NULL | 65 |
| CHILLER | evap_leaving_temp | NULL | 12 |
| CHILLER | cond_entering_temp | NULL | 32 |

`threshold_by_kw`: 7.5→12, 15→30, 30→56, 37→73 (semua `min_value` NULL).
`unit_motor_kw`: CCP 1-9=30; CHWP 1-3=30, 4-9=37; CT 1,2,3,4,9,10,11,12,13=15;
CT 5A/5B/6A/6B=7.5.

**Catatan penting:** semua `min_value` NULL → praktis **hanya batas atas** yang
aktif hari ini. Tabel sudah mendukung min, tapi belum ada data min.

### 2.3 Tabel data live (current, bukan history)

| Tabel | PK | Kolom | Rows | Isi |
|---|---|---|---|---|
| `points` | `point_id` VARCHAR(32) | `device_id, obj_type, instance, point_name, equip_type` | 824 | master point (identity) |
| `ai_current` | `point_id` | `value FLOAT NULL, read_at DATETIME` | 443 | nilai analog terkini |
| `bi_current` | `point_id` | `value TINYINT(1) NULL, read_at DATETIME` | 318 | nilai biner terkini (alarm/status) |
| `acc_current` | `point_id` | `value FLOAT NULL, read_at DATETIME` | 29 | energy meter (ACC) |
| `ef_current` | `ef_point_id` | `status TINYINT(1) NOT NULL, last_update DATETIME(6)` | 55 | **current, tanpa history** |
| `ef_points` | `id` AI | `panel_no, ip_address, ef_name, register_address, slave_id` | 55 | master EF |
| `daikin_points` / `daikin_current` | `id` / `point_id` | Niagara points, `status VARCHAR(32)` | 22 / 22 | **stale sejak 2026-09-19** |
| `users` | `id` AI | `username, password_hash, is_admin, is_active` | **1** (`admin`) | auth |
| `temp_rh_hourly` | `(recorded_at, point_id)` | long format history | 40 | **satu-satunya tabel history milik web app** |

**Tidak ada** tabel alarm/threshold/history lain. Tidak ada kolom
`severity`/`ack`/`active` di tabel mana pun selain `users.is_active` dan
`ef_current.status` **[VERIFIED]**.

**Penting:** `ai_current`/`bi_current`/`acc_current` **overwritten in-place** —
hanya menyimpan nilai terakhir. Karena itu alarm harus dievaluasi oleh proses
terpisah; tidak ada cara merekonstruksi alarm dari tabel current setelah kejadian.

### 2.4 Database lain

- `hvac_monitoring` (nama DB lama) **ADA** dan berisi
  `ef_alarm_events` (183 rows) + `ef_communication_events` (40 rows) +
  `ef_readings` (~1.054.048 rows) menurut `ef_migration_plan.md:24-47`.
  User `hvac_web` **tidak punya akses** ke DB ini (`ERROR 1142 SELECT command
  denied`) **[VERIFIED]** — jadi web app tidak bisa memakainya tanpa grant baru.
- `hvac_current` **belum punya `ef_readings`**; hanya 4 tabel `ef_*`
  **[VERIFIED]** — migrasi EF belum selesai.
- Hosting: `u468140406_hvac` (mirror, dual-write oleh poller).

---

## 3. Existing threshold implementation

### 3.1 Sumber threshold: **DB (live), bukan hardcoded**

Threshold dibaca dari `threshold_direct` / `threshold_by_kw` / `unit_motor_kw`
pada **setiap request**, sekali per request (bukan per unit) **[VERIFIED]**.
Tidak ada threshold hardcoded di PHP.

### 3.2 `threshold_rules_seed.sql` — dipakai atau draft?

**DIPAKAI, dan sudah diterapkan.** Bukti: isi ketiga tabel di DB persis sama
dengan nilai seed (5/4/31 baris) **[VERIFIED]**. File ini adalah *generated
artifact* dari `threshold_rules_ed3.xlsx` (lihat komentar baris 1) — jadi
**master sebenarnya adalah file Excel**, dan `.sql` adalah hasil generate.
File ini belum pernah di-commit (masih untracked).

### 3.3 Resolusi limit per endpoint

| Endpoint | Field | Query | Catatan |
|---|---|---|---|
| `api_ahu.php` | `temp_max` | `threshold_direct WHERE equip_type='AHU' AND metric='temp'` | 1 nilai untuk semua AHU |
| `api_room.php` | `temp_max`, `rh_max` | `threshold_direct WHERE equip_type='ROOM'` | **ROOM saja**; OUTDOOR sengaja tanpa limit |
| `api_hvac_sc.php` | `evap_leaving_temp_max`, `cond_entering_temp_max` | `threshold_direct WHERE equip_type='CHILLER'` | hanya 2 metric |
| `api_hvac_sc.php` | `current_min`, `current_max` | `unit_motor_kw` → `threshold_by_kw` | CCP/CHWP/CT |

Resolusi current (3 langkah) **[VERIFIED]**:
1. cocokkan `(equip_type, unit_name)` di `unit_motor_kw`;
2. kalau tidak ada dan nama berakhiran huruf (`5A`), ulangi tanpa huruf (`5`) —
   cell CT berbagi satu VSD per tower;
3. `motor_kw` → `threshold_by_kw`;
4. gagal di langkah mana pun → `current_min/max = null` (**bukan error**, unit
   tetap dikirim).

### 3.4 Semantik "null"

`null` = **tidak ada limit terkonfigurasi, bukan alarm** **[VERIFIED]** di
`api_*` dan `index.php:99-114`. Pembacaan hilang/kosong juga **tidak pernah**
dianggap alarm.

### 3.5 Cakupan threshold hari ini

**Yang punya threshold:** AHU duct temp; ROOM temp + RH; chiller EL + CE;
CCP/CHWP/CT current.
**Yang TIDAK punya threshold (tidak pernah blink):** SP, RLA, EE, CL, CT
`frequency`, `drive_temperature`, semua OUTDOOR, EF (tidak ada nilai numerik),
ACC/energy **[VERIFIED]**.

---

## 4. Alarm source per equipment/page

Semua identitas berasal dari `points.point_id` (format
`<device>BI/AI<instance>`, mis. `0010202BI0000304`) **[VERIFIED]**.

### 4.1 AHU — 93 unit, `equip_type='AHU'`, 279 points

| Peran | point_name | obj_type | Jumlah | Sumber nilai |
|---|---|---|---|---|
| Alarm | `AHU <n> Alarm` | BI | 93 | `bi_current` |
| Status/run | `AHU <n> Status` | BI | 93 | `bi_current` |
| Duct temp | `AHU <n> Duct Temperature` | AI | 93 | `ai_current` |
| Threshold | `threshold_direct(AHU, temp)` max 25 | — | — | DB |

AHU 94-97 = **placeholder**, tidak ada point. AHU G8 = tab "Coming Soon",
datanya di `daikin_*` (AHU 96/97) tapi **stale sejak 2026-09-19** **[VERIFIED]**.

### 4.2 HVAC_SC — `equip_type IN ('CHILLER','CCP','CHWP','CT')`

| Equip | Alarm BI | Status BI | Threshold |
|---|---|---|---|
| CHILLER 1-9 | `CHILLER <n> Alarm` (9) | `CHILLER <n> Status` (9) | `evap_leaving_temp` max 12, `cond_entering_temp` max 32 |
| CCP 1-9 | `CCP <n> Alarm` (9) | `CCP <n> Status` (9) | current via `unit_motor_kw` |
| CHWP 1-9 | `CHWP <n> Alarm` (1-5) / `CHWP <n> AL` (6-9) | `CHWP <n> Status` (9) | current via `unit_motor_kw` |
| CT 1A-6B, 9-13 | `CT <n> Alarm` / `CT <nA\|nB> Alarm` (17) | 22 status | current via `unit_motor_kw` |

Catatan penting:
- **CHWP memakai dua nama berbeda** untuk alarm: `Alarm` (1-5) dan `AL` (6-9).
  Keduanya dipetakan ke field `alarm` (`api_hvac_sc.php:37-38`).
- **CT 5A/5B/6A/6B** punya `Drive Temp` cell-level sendiri; sisanya inherit
  dari tower. `CT 7` dan `CT 8` **tidak punya point sama sekali**.
- **CT memakai `equip_type='CT '` (dengan trailing space)** — 111 points.
  Dibandingkan dengan `IN ('...','CT')` di query API, ini **cocok** karena
  collation `utf8mb4_unicode_ci` bersifat PAD SPACE (`'CT ' = 'CT'` → 1)
  **[VERIFIED]**. Tetap perlu `TRIM()` saat grouping di kode baru.
- **CHILLER 1/3 MODBUS** tidak punya koneksi → `ai_current` NULL (SP/RLA null).
- G3 (chiller 6-9): `Temp Return Header Chil G3`, `Temp Return Head Conden G3`,
  `Temp Supp Head Condenso G3` adalah **satu sensor bersama** untuk 4 chiller.
- Ada juga `equip_type` `CHILLER <n> MODBUS` (27 points masing-masing) —
  punya BI tapi **bukan alarm**; tidak masuk query HVAC_SC.

### 4.3 EXHAUST FAN — tabel terpisah, **TANPA alarm point**

`ef_points` (55) + `ef_current` (55). Tidak ada point `Alarm` **[VERIFIED]**.

| Konsep | Sumber | Arti |
|---|---|---|
| `status` | `ef_current.status` TINYINT(1) | 1 = ON, 0 = OFF |
| **Alarm EF** | **turunan**: transisi `1 → 0` | Fan berhenti padahal seharusnya jalan |
| **Recovered** | **turunan**: transisi `0 → 1` | Fan jalan kembali |
| **Comm alarm** | turunan: `ONLINE/UNKNOWN → COMM_ERROR` | panel Modbus tidak terbaca |
| **Comm recovered** | turunan: `COMM_ERROR → ONLINE` | panel kembali terbaca |

Semantik ini **terdokumentasi di poller** `ef_polling_engine_claude_v2.py:300-307`
**[VERIFIED]**:
```
EF ALARM        : ON -> OFF
EF RECOVERED    : OFF -> ON
COMM ALARM      : ONLINE/UNKNOWN -> COMM_ERROR
COMM RECOVERED  : COMM_ERROR -> ONLINE
COMM_ERROR does NOT change ef_current.
COMM_ERROR does NOT create EF alarm.
Recovery synchronizes EF status without EF event.
```
Jadi: **tidak ada alarm BI untuk EF** — alarm EF murni *edge-detection* pada
`status` oleh poller. Ini berbeda total dari AHU/HVAC_SC.

Saat discovery, **55/55 EF berstatus 1 (ON)** dan `ef_current` baru saja
di-update → tidak ada alarm EF aktif.

### 4.4 ROOM TEMP & RH — threshold saja, **tanpa alarm BI**

`equip_type='ROOM TEMP & RH'`, 20 AI points (18 ROOM + 2 OUTDOOR).
**Tidak ada point alarm BI** **[VERIFIED]**. Alarm hanya dari threshold
`ROOM temp > 30` / `ROOM rh > 65`. OUTDOOR **sengaja tanpa limit**.

### 4.5 ACC / energy

`acc_current` 29 rows (CCP/CHWP/CT), dipakai hanya oleh `kwh_daily_snapshot.php`.
Tidak ada threshold/alarm **[VERIFIED]** — kandidat alarm masa depan.

### 4.6 Ringkasan identitas equipment

| Page | `equip_type` | Identitas unit | Identity untuk alarm |
|---|---|---|---|
| AHU | `AHU` | `AHU <n>` | `points.point_id` |
| HVAC SC | `CHILLER`/`CCP`/`CHWP`/`CT ` | `<TYPE> <n>` | `points.point_id` |
| ROOM | `ROOM TEMP & RH` | `ROOM <label>` | `points.point_id` |
| EF | `ef_points` (tabel sendiri) | `EF <n>` | **`ef_points.id`** (bukan point_id) |
| Comm | panel | `Panel <n>` | **`panel_no` + `ip_address`** |

**Tiga namespace identitas berbeda** (points.point_id, ef_points.id,
panel_no) — ini tantangan utama desain global.

---

## 5. Current API behavior

Semua API: session-gated, `session_write_close()`, generic error + `error_log`,
`success` + `server_time` + `equipment`.

| Endpoint | Alarm-related output | Alarm asli? |
|---|---|---|
| `api_ahu.php` | `alarm` (int/null), `run`, `temp`, `temp_max`, **`points.{run,alarm,temp}` = point_id** | `alarm` = **BI asli**; `temp_max` = limit |
| `api_hvac_sc.php` | `alarm` (int/null), `run`, `current_min/max`, `evap_leaving_temp_max`, `cond_entering_temp_max` | `alarm` = **BI asli**; sisanya limit |
| `api_room.php` | `temp_max`, `rh_max` | **limit saja — tidak ada alarm** |
| `api_ef.php` | `status` (0/1/null), `id` (ef_point_id), `panel_no` | **tidak ada alarm** — hanya status |
| `api.php` | — | **FILE TIDAK ADA di disk** **[VERIFIED]**; masih direferensikan `index.php:55` untuk tab AHU G8 → 404 |

**Poin penting:** API mengirim **bahan** alarm (nilai + limit + BI alarm), tapi
**keputusan alarm diambil di frontend**. Tidak ada API yang mengembalikan
"apakah sedang alarm", "sejak kapan", "severity", atau history.

`point_id` **hanya** diekspos `api_ahu.php` (objek `points`). `api_hvac_sc.php`,
`api_room.php` tidak mengirim `point_id` sama sekali. `api_ef.php` mengirim
`id` (= `ef_points.id`).

---

## 6. Current frontend behavior

`index.php` — satu halaman, 5 tab (AHU, HVAC SC, ROOM TEMP & RH, EXHAUST FAN,
AHU G8), polling satu endpoint per tab tiap 10 detik (`index.php:824`).

### 6.1 Alarm dicampur dengan status equipment? **YA — tercampur.**

`getStatus()` (`index.php:74-91`) menggabungkan alarm dan status menjadi satu
nilai yang menentukan warna card:

```
alarm === 1        → "alarm"   (orange #d87500 + alarmPulse)
run === 1          → "run"     (hijau)
run === 0          → "off"     (merah)
selain itu         → "unknown" (abu)
```

Artinya: **alarm BI dan status run berebut satu warna card.** Alarm menang.
Tidak ada elemen UI terpisah yang mengatakan "ini alarm" atau "sejak kapan".

### 6.2 Dua mekanisme visual yang tidak saling tahu

| Mekanisme | Trigger | Tampilan | Scope |
|---|---|---|---|
| Status card | `getStatus()` dari BI | background card orange + pulse | AHU, CHILLER, CCP, CHWP, CT |
| Value alarm | `applyAlarmClass()` dari threshold | **teks** merah + blink 1.2s | AHU temp, ROOM temp/RH, chiller EL/CE, CCP/CHWP/CT current |

Keduanya bisa aktif bersamaan (card orange + angka merah blink). ROOM card
**tidak punya status class** (selalu gelap) sehingga alarm value selalu merah.
EF card tidak punya nilai numerik → `.value-alarm` tidak mungkin muncul.

### 6.3 Yang tidak ada di frontend

Tidak ada: halaman/tab alarm, daftar alarm, counter alarm, badge, teks alarm,
timestamp alarm, acknowledge, filter, suara, notifikasi **[VERIFIED]**.

### 6.4 Utilitas yang bisa dipakai ulang

`isOutOfRange(value, min, max)` (`index.php:99-114`) sudah benar dan
generik (aman terhadap null/non-numerik). Logika ini bisa dipindahkan ke
evaluator server-side dengan semantik yang sama.

---

## 7. Gap

| # | Gap | Dampak |
|---|---|---|
| G1 | **Tidak ada penyimpanan alarm** untuk AHU/HVAC_SC/ROOM | alarm hilang tanpa jejak; tidak bisa lihat "tadi malam chiller trip" |
| G2 | **Tidak ada tabel alarm generic** — hanya `ef_*_events` khusus EF | equipment baru butuh tabel sendiri (persis yang ingin dihindari) |
| G3 | **Alarm dievaluasi di frontend**, bukan di server | alarm hanya ada saat browser terbuka; 2 client = 2 evaluasi; tidak ada satu sumber kebenaran |
| G4 | **Tidak ada severity** | tidak bisa bedakan "AHU 65 alarm" vs "suhu ruangan 25.1 °C" |
| G5 | **Tidak ada acknowledgement** | tidak bisa tandai "sudah ditangani" |
| G6 | **Tidak ada timestamp alarm** | hanya ada `read_at` nilai, bukan waktu alarm mulai |
| G7 | **Alarm BI dan status run bercampur** di satu warna card | operator tidak bisa bedakan "mati normal" vs "trip" |
| G8 | **Tidak ada deteksi komunikasi/stale** untuk BACnet (AHU/HVAC_SC/ROOM) | point mati = nilai beku, tidak terdeteksi; EF sudah punya konsep ini |
| G9 | **EF tidak punya alarm BI**; alarm EF hanya edge-detection di poller | `ef_alarm_events` kosong; alarm EF tidak terlihat di web sama sekali |
| G10 | **`ef_alarm_events`/`ef_communication_events` kosong & tidak dibaca web** | infrastruktur event sudah dibangun tapi tidak terpakai |
| G11 | **Tiga namespace identitas** (point_id / ef_point_id / panel_no) | perlu disatukan agar bisa satu tabel |
| G12 | **`ef_current` tidak punya state UNKNOWN** | comm failure membekukan nilai, tidak bisa dibedakan dari "ON" |
| G13 | **Tidak ada history nilai** (kecuali `temp_rh_hourly`) | nilai saat alarm tidak bisa direkonstruksi; harus disimpan di baris alarm |
| G14 | **`hvac_web` SELECT-only** | proses penulis alarm butuh user/grant terpisah |
| G15 | **Alarm tidak ikut dual-write ke hosting** | halaman alarm di hosting akan kosong kecuali dirancang khusus |
| G16 | **Tidak ada cakupan AHU G8/daikin & energy/ACC** | equipment masa depan belum punya jalur alarm |

---

## 8. Rekomendasi schema global alarm

### 8.1 Perbandingan opsi (sesuai §7 spec)

**Opsi A — `current_alarm` saja** (satu baris per alarm aktif, dihapus saat clear)
- ✔ sederhana, halaman cepat, filter Active trivial
- ✘ **tidak ada history** — begitu clear, hilang. Tidak bisa menjawab "apa yang
  terjadi tadi malam"
- ✘ tidak bisa memenuhi filter **Cleared** yang diminta
- ✘ kehilangan timestamp kejadian

**Opsi B — event/history + active state** (pola `ef_alarm_events` yang sudah ada)
- ✔ history lengkap, bisa audit
- ✔ pola sudah dikenal di proyek ini (EF)
- ✘ kalau murni append-only, satu alarm = 2 baris (ACTIVE + CLEARED) →
  kolom **STATUS** per baris jadi janggal, dan tabel halaman menampilkan
  duplikat

**REKOMENDASI: Opsi C — "alarm instance"** = satu baris per **kejadian alarm**,
di-`UPDATE` saat clear. Ini Opsi B tanpa kelemahan duplikat, dan **persis
memetakan** ke tabel target yang diminta:

```
TIME | EQUIPMENT | POINT | ALARM | VALUE | LIMIT | SEVERITY | STATUS
```

### 8.2 DDL yang diusulkan (BELUM dieksekusi — untuk direview)

```sql
CREATE TABLE alarm_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Identitas alarm yang stabil; dipakai untuk dedupe & clear.
    --   BI        : 'BI:<point_id>'            contoh 'BI:0010202BI0000304'
    --   THRESHOLD : 'TH:<point_id>:<metric>:<max|min>'
    --   EF        : 'EF:<ef_point_id>'         (turunan dari transisi status)
    --   COMM      : 'COMM:<panel_no>'
    alarm_key     VARCHAR(160) NOT NULL,

    source_type   ENUM('POINT_BI','THRESHOLD_AI','EF_STATUS','COMM') NOT NULL,

    -- Identitas equipment (denormalised supaya halaman tidak perlu JOIN
    -- ke points/ef_points, dan tetap terbaca setelah point dihapus).
    equip_type    VARCHAR(50)  NOT NULL,  -- 'AHU','CHILLER','CCP','CHWP','CT',
                                          -- 'ROOM','OUTDOOR','EF','PANEL'
    equipment     VARCHAR(64)  NOT NULL,  -- 'AHU 65','CT 5A','EF 12','Panel 3','ROOM 1056'
    point_id      VARCHAR(32)  NULL,      -- points.point_id (NULL utk COMM)
    ef_point_id   INT UNSIGNED NULL,      -- ef_points.id (khusus EF)

    metric        VARCHAR(32)  NULL,      -- 'duct_temp','temp','rh','current',
                                          -- 'evap_leaving_temp','cond_entering_temp',
                                          -- 'status' (EF)
    message       VARCHAR(255) NOT NULL,  -- 'AHU 65 Alarm', 'Duct temp 26.2 > 25',
                                          -- 'EF 12 berhenti', 'Panel 3 comm error'

    severity      ENUM('CRITICAL','WARNING') NOT NULL,

    -- Nilai & limit pada saat alarm muncul (WAJIB disimpan: ai_current
    -- overwritten in-place, tidak bisa direkonstruksi nanti).
    value         FLOAT NULL,
    limit_min     FLOAT NULL,
    limit_max     FLOAT NULL,

    -- Waktu
    raised_at     DATETIME(6) NOT NULL,   -- kolom TIME (status Active)
    cleared_at    DATETIME(6) NULL,       -- kolom TIME (status Cleared)

    -- Acknowledgement (tidak meng-clear alarm)
    ack_by        INT UNSIGNED NULL,      -- users.id
    ack_at        DATETIME(6) NULL,

    -- Kunci anti-duplikat: berisi alarm_key selama ACTIVE, NULL setelah CLEAR.
    -- MySQL mengizinkan banyak NULL di UNIQUE → satu alarm aktif per key.
    active_key    VARCHAR(160) NULL,

    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at  DATETIME(6) NOT NULL,   -- alarm masih terdeteksi (heartbeat)

    PRIMARY KEY (id),
    UNIQUE KEY uq_alarm_active (active_key),
    KEY idx_alarm_raised     (raised_at),
    KEY idx_alarm_status     (cleared_at, raised_at),
    KEY idx_alarm_equip      (equip_type, raised_at),
    KEY idx_alarm_point      (point_id, raised_at),
    KEY idx_alarm_severity   (severity, raised_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Catatan desain:**

1. **`active_key` + `UNIQUE`** adalah inti anti-duplikat. Saat alarm raise:
   `active_key = alarm_key`. Saat clear: `active_key = NULL`, `cleared_at = now()`.
   Alarm yang sama muncul lagi → baris baru (kejadian baru) → history utuh.
   Ini juga membuat evaluator **idempotent** dan aman dari dua instance
   (masalah nyata: `ef_migration_plan.md:318-348` mencatat **dua proses poller
   EF berjalan bersamaan**).
2. **`STATUS` tidak perlu kolom sendiri** — diturunkan: `cleared_at IS NULL`
   → Active, else Cleared. Tidak ada risiko status tidak sinkron.
3. **`value`/`limit_*` disimpan di baris alarm** — wajib, karena
   `ai_current`/`bi_current` tidak punya history.
4. **`first_seen_at`/`last_seen_at`** memisahkan "kapan mulai" dari "kapan
   terakhir terlihat masih alarm" — berguna untuk alarm yang flapping.
5. **`equip_type` + `equipment` denormalised** supaya filter per page
   (AHU/HVAC_SC/EF) tidak butuh JOIN dan tetap benar setelah point diubah.
6. **Enum `source_type`** memungkinkan equipment baru masuk tanpa tabel baru —
   cukup tambah nilai enum atau pakai VARCHAR kalau mau lebih fleksibel.

### 8.3 Tabel pendukung: aturan per point (opsional tapi disarankan)

Threshold sudah ada di `threshold_direct`/`threshold_by_kw`/`unit_motor_kw` —
**jangan diduplikasi**. Tapi BI alarm tidak punya metadata severity/message:

```sql
-- OPSIONAL — bisa juga diturunkan dari konvensi point_name
CREATE TABLE alarm_point_rules (
    point_id    VARCHAR(32) NOT NULL,   -- points.point_id
    enabled     TINYINT(1)  NOT NULL DEFAULT 1,
    severity    ENUM('CRITICAL','WARNING') NOT NULL DEFAULT 'WARNING',
    message     VARCHAR(255) NULL,      -- NULL = pakai default dari point_name
    PRIMARY KEY (point_id)
) ENGINE=InnoDB;
```

Kalau tidak mau tabel baru: severity bisa di-hardcode per `source_type`
(BI → CRITICAL, THRESHOLD → WARNING, COMM → WARNING) dan `message` dibentuk
dari `point_name`. **Ini keputusan yang perlu dikonfirmasi.**

### 8.4 Hubungan dengan tabel EF yang sudah ada

**Jangan drop `ef_alarm_events` / `ef_communication_events`.** Rekomendasi:
- `alarm_events` = **lapisan terpadu** untuk halaman global (semua equipment).
- `ef_alarm_events` / `ef_communication_events` = **detail history khusus EF**,
  tetap ditulis poller, tetap sebagai sumber rinci.
- Evaluator menulis `alarm_events` untuk EF dengan membaca transisi yang
  **sudah ditulis poller** ke `ef_alarm_events` — sehingga poller **tidak
  perlu diubah sama sekali**.

Ini penting: desain ini menghormati batasan "jangan ubah poller".

---

## 9. Rekomendasi alur alarm

### 9.1 Prinsip

**Satu evaluator server-side, di luar web request dan di luar poller.**

- **Bukan di frontend** — alarm harus ada walau tidak ada browser terbuka,
  dan harus satu sumber kebenaran (menutup G3).
- **Bukan di poller BACnet/EF** — poller hanya menulis nilai; menambah logika
  alarm di poller berarti mengubah poller (dilarang) dan menggandakan logika
  per poller (melanggar tujuan "satu sistem global").
- **Bukan di API** — API harus tetap read-only & cepat; mengevaluasi alarm di
  API berarti evaluasi berjalan hanya saat ada request.

### 9.2 Alur

```
[poller BACnet]  → ai_current / bi_current   ─┐
[poller EF]      → ef_current / ef_*_events  ─┤
                                              ▼
                        ┌──────────────────────────────────┐
                        │  alarm_evaluator.php  (CLI cron) │
                        │  tiap 1 menit                    │
                        └──────────────────────────────────┘
                                      │
        ┌─────────────────────────────┼─────────────────────────────┐
        ▼                             ▼                             ▼
 1. POINT_BI                   2. THRESHOLD_AI                3. EF_STATUS / COMM
 baca points+bi_current        baca points+ai_current         baca ef_*_events
 WHERE obj_type='BI'           JOIN limit dari 3 tabel        (ALARM/RECOVERED,
 AND point_name alarm          referensi                      COMM_*)
        │                             │                             │
        └─────────────────────────────┼─────────────────────────────┘
                                      ▼
                    bandingkan dengan alarm_events WHERE active_key IS NOT NULL
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
          kondisi TRUE & belum ada baris        kondisi FALSE & ada baris
          → INSERT baris baru                  → UPDATE cleared_at=now(),
            (active_key = alarm_key)              active_key=NULL
          kondisi TRUE & sudah ada
          → UPDATE last_seen_at
```

### 9.3 Sifat evaluator

- **Stateless / idempotent.** "Apakah alarm ini aktif?" dijawab dari
  `alarm_events.active_key`, **bukan** dari state in-memory. Ini secara
  sengaja menghindari bug yang ada di poller EF: `previous_status` disimpan
  in-memory (`point["status"]`), sehingga **restart poller = kehilangan
  transisi** (alarm bisa terlewat atau salah terdeteksi). Desain ini kebal
  terhadap restart.
- **Aman dijalankan dua kali** berkat `UNIQUE(active_key)`.
- **Semantik edge-on-change**: alarm raise saat kondisi pertama kali TRUE,
  clear saat kembali FALSE — sejalan dengan makna "ACTIVE/CLEARED" di tabel
  target, dan tidak spam baris baru tiap menit.

### 9.4 Severity (usulan awal, perlu konfirmasi)

| source_type | severity usulan | alasan |
|---|---|---|
| `POINT_BI` (AHU/CHILLER/CCP/CHWP/CT alarm) | **CRITICAL** | perangkat melaporkan fault sendiri |
| `EF_STATUS` (ON→OFF) | **CRITICAL** | fan berhenti |
| `COMM` (panel/point tidak terbaca) | **WARNING** | masalah pengukuran, bukan mesin |
| `THRESHOLD_AI` | **WARNING** | batas operasional, bukan trip |

### 9.5 Mapping ke kolom halaman target

| Kolom UI | Sumber |
|---|---|
| TIME | `raised_at` (Active) / `cleared_at` (Cleared) |
| EQUIPMENT | `equipment` |
| POINT | `point_id` / `ef_point_id` + `metric` (tampilan ramah) |
| ALARM | `message` |
| VALUE | `value` |
| LIMIT | `limit_max` (atau `limit_min` bila yang dilanggar batas bawah) |
| SEVERITY | `severity` |
| STATUS | `cleared_at IS NULL` → Active / Cleared |

Filter: **All** (tanpa WHERE), **Active** (`cleared_at IS NULL`),
**Cleared** (`cleared_at IS NOT NULL`), **AHU/HVAC_SC/EF** (`equip_type`),
**severity** (`severity`). Semua ter-index.

### 9.6 Keputusan yang masih terbuka (butuh jawaban user)

1. **Cadence evaluator** — 1 menit? (poller ~10s; 1 menit cukup, tapi
   lonjakan singkat <1 menit tidak akan tercatat — sama seperti keterbatasan
   polling EF saat ini).
2. **Hysteresis/deadband** — nilai yang duduk tepat di batas (mis. 25.0 °C
   dengan limit 25) akan flapping raise/clear tiap siklus. Perlu deadband
   kecil atau syarat "N sampel berturut-turut".
3. **Stale reading** — `ai_current` punya `read_at` dari 2026-09-23 sampai
   hari ini **[VERIFIED]**; point yang mati menyimpan nilai beku. Perlukah
   alarm STALE/COMM untuk BACnet (menutup G8), dan apakah nilai stale boleh
   memicu alarm threshold?
4. **Retensi** — `alarm_events` tumbuh selamanya; perlu kebijakan arsip.
5. **Acknowledgement** — siapa boleh ack, dan apakah ack menghentikan
   re-notifikasi atau hanya menandai.
6. **Dual-write ke hosting** — apakah evaluator menulis ke
   `u468140406_hvac` juga (seperti poller), atau hosting menjalankan
   evaluatornya sendiri.
7. **Severity mapping** di §9.4 — setuju atau ada perubahan.

---

## 10. File yang nantinya perlu diubah

**Belum diubah pada task ini.** Daftar untuk implementasi nanti:

| File | Perubahan | Catatan |
|---|---|---|
| **`alarm_evaluator.php`** (BARU) | evaluator CLI | pola sama `temp_rh_snapshot.php`: guard `PHP_SAPI !== 'cli'`, `date_default_timezone_set('Asia/Jakarta')`, `SET time_zone='+07:00'` |
| **`api_alarm.php`** (BARU) | endpoint read-only halaman alarm | auth + `session_write_close()` + generic error, sama seperti API lain |
| **`alarm.css`** atau tambahan di `style.css` | style halaman alarm | additive; **jangan** ubah `.value-alarm` / `.status-*` yang ada |
| **`index.php`** | tambah nav button + fungsi render halaman alarm | **hanya tambahan**; `getStatus()`/`isOutOfRange()`/`applyAlarmClass()` tidak diubah |
| **skema DB** | `CREATE TABLE alarm_events` (+ opsional `alarm_point_rules`) | butuh persetujuan terpisah |
| **grant DB** | user penulis alarm (INSERT/UPDATE pada `alarm_events`) | `hvac_web` SELECT-only; **jangan** beri write ke user web |
| **`.htaccess`** | blokir akses HTTP ke `alarm_evaluator.php` | sudah ada pola serupa |
| **`PROJECT.md`** | dokumentasi §12 baru untuk alarm | wajib menurut aturan PROJECT.md sendiri |

Opsional (hanya bila ingin `point_id` tampil di halaman equipment):
`api_hvac_sc.php`, `api_room.php` — saat ini tidak mengirim `point_id`.
**Untuk halaman alarm tidak diperlukan**, karena `api_alarm.php` membaca
`alarm_events` yang sudah menyimpan `point_id`.

---

## 11. File yang tidak perlu diubah

| File | Alasan |
|---|---|
| `api_ahu.php` | alarm BI + `temp_max` sudah dikirim; evaluator baca DB langsung |
| `api_hvac_sc.php` | idem (`alarm`, limit) |
| `api_ef.php` | idem (`status`, `id`) |
| `api_room.php` | idem (`temp_max`, `rh_max`) |
| **semua poller** | `ef_polling_engine_claude_v2.py`, `curated_poll_client.py`, `azbil_bacnet/Program.cs`, `ahu_poller_unified.py` — **tidak disentuh**; evaluator membaca output mereka |
| `threshold_direct` / `threshold_by_kw` / `unit_motor_kw` | sudah jadi sumber limit; tidak diduplikasi |
| `threshold_rules_seed.sql` | sudah diterapkan; tetap jadi artifact |
| `ef_alarm_events` / `ef_communication_events` | tetap ditulis poller, tidak di-drop/di-alter |
| `auth.php`, `login.php`, `logout.php` | auth tidak berubah |
| `temp_rh_snapshot.php`, `kwh_daily_snapshot.php` | tidak terkait |
| `ef.css` | tidak terkait |
| `config.php` | dipakai ulang apa adanya (kecuali butuh kredensial user penulis) |
| `fix_value_alarm_color.md` | dokumen historis |

---

## 12. Risiko / hal yang harus diperhatikan

### Risiko tinggi

1. **`hvac_web` hanya SELECT.** Penulis alarm butuh user/grant baru.
   **Jangan** menaikkan privilege `hvac_web` — itu membuka web app (yang
   menghadap internet di hosting) untuk menulis DB.
2. **Dual-write ke hosting tidak otomatis.** Poller melakukan dual-write,
   evaluator baru **tidak**. Kalau tidak dirancang, halaman alarm di hosting
   kosong / tidak sinkron. Ini keputusan arsitektur, bukan detail.
3. **`ai_current` overwritten in-place.** Kalau `value`/`limit` tidak
   disimpan di baris alarm saat raise, nilainya **hilang selamanya**.
   Ini alasan `value`, `limit_min`, `limit_max` wajib ada di `alarm_events`.
4. **Flapping.** Threshold tanpa deadband → alarm raise/clear berulang tiap
   menit saat nilai mendekati batas. Bisa membanjiri tabel dan halaman.
   Wajib diputuskan sebelum implementasi.
5. **Nilai stale memicu alarm palsu.** `read_at` ada yang tertinggal di
   2026-09-23 **[VERIFIED]**. Point mati menyimpan nilai beku; kalau nilai
   beku itu di atas limit, alarm akan aktif selamanya. Perlu aturan staleness.

### Risiko sedang

6. **Restart poller EF kehilangan transisi** (state `previous_status`
   in-memory). Desain evaluator harus **tidak** meniru pola ini — pakai
   `alarm_events` sebagai state. Sudah diakomodasi di §9.3.
7. **Dua proses poller EF berjalan bersamaan** (`ef_migration_plan.md:318-348`)
   → potensi event ganda. `UNIQUE(active_key)` melindungi `alarm_events`,
   tapi sumbernya bisa dobel.
8. **Tiga namespace identitas** (point_id / ef_point_id / panel_no).
   Salah mapping → alarm menunjuk equipment yang salah. Perlu mapping eksplisit
   dan diuji.
9. **`equip_type='CT '` (trailing space).** Aman untuk query (collation PAD
   SPACE, sudah diverifikasi), tapi **wajib `TRIM()`** saat grouping/denormalisasi
   ke `alarm_events.equip_type`, supaya filter halaman konsisten.
10. **`min_value` semua NULL.** Limit bawah belum pernah dipakai. Kalau nanti
    diisi, logika "LIMIT" di UI harus menampilkan batas yang benar-benar
    dilanggar, bukan selalu `limit_max`.
11. **Timestamp.** `read_at` adalah WIB naive; `@@session.time_zone` = SYSTEM
    **[VERIFIED]**. Evaluator wajib mem-pin `Asia/Jakarta` / `+07:00` seperti
    `temp_rh_snapshot.php`, kalau tidak `raised_at` bisa bergeser.
    Catatan: `PROJECT.md:127-132` menyatakan timezone PHP belum diperbaiki,
    tapi **sudah** `Asia/Jakarta` **[VERIFIED]** — dokumentasi itu usang,
    jangan "diperbaiki" lagi.
12. **Cakupan tidak lengkap sejak awal.** Belum termasuk: AHU G8/daikin
    (stale sejak 2026-09-19), ACC/energy, CT 7/8 (tidak ada point), CHILLER
    MODBUS (BI tanpa alarm), ROOM/OUTDOOR (tanpa BI). Halaman alarm akan
    tampak "kurang" kalau ekspektasi tidak disamakan.
13. **EF tidak punya alarm BI.** Kalau evaluator hanya membaca BI, EF tidak
    akan pernah muncul. EF butuh jalur `EF_STATUS`/`COMM` dari
    `ef_*_events`/transisi `ef_current`.
14. **Alarm ganda untuk satu kejadian.** Contoh: chiller trip bisa memicu
    alarm BI **dan** alarm threshold current secara bersamaan. Perlu keputusan
    apakah keduanya ditampilkan (jujur, tapi berisik) atau digabung.

### Risiko rendah

15. **`.htaccess` belum memblokir file baru** — `alarm_evaluator.php` harus
    ikut dilindungi (atau di luar webroot).
16. **`users` hanya 1 akun** — fitur ack akan menampilkan "admin" untuk semua.
17. **Referensi `api.php` di `index.php:55`** tetap 404 untuk tab AHU G8
    (pre-existing, tidak terkait alarm).
18. **Retensi `alarm_events`** tanpa kebijakan arsip → tabel tumbuh tak terbatas.

---

## Ringkasan eksekutif

- Alarm hari ini **100% visual & realtime**, dievaluasi di **frontend**,
  tidak pernah disimpan. Hanya ada 2 tabel event **khusus EF** yang kosong.
- Threshold **sudah benar dan berbasis DB** (`threshold_direct` /
  `threshold_by_kw` / `unit_motor_kw`), seed-nya sudah diterapkan. Ini fondasi
  yang bisa dipakai langsung — **jangan diduplikasi**.
- Desain global yang direkomendasikan: **satu tabel `alarm_events`**
  (satu baris per kejadian, `active_key` UNIQUE untuk dedupe/clear,
  `STATUS` diturunkan dari `cleared_at`), ditulis oleh **satu evaluator CLI**
  yang membaca `ai_current`/`bi_current`/`ef_current`/`ef_*_events` +
  tabel threshold.
- **Poller tidak perlu diubah** dan **API yang ada tidak perlu diubah** —
  evaluator membaca DB langsung, dan `api_alarm.php` (baru) melayani halaman.
- Keputusan yang harus diambil sebelum implementasi: cadence, hysteresis,
  aturan stale, retensi, ack, **dual-write hosting**, dan severity mapping.

**Tidak ada perubahan file/database pada task ini. Tidak ada commit/push.**
