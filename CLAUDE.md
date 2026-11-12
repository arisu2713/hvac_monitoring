# HVAC Monitoring — Panduan Proyek

Aplikasi web monitoring HVAC (AHU, chiller, cooling tower, pompa, exhaust fan,
suhu/kelembapan ruangan, energi, alarm). Satu halaman PHP dirender server-side,
lalu seluruh dashboard dibangun ulang di browser dengan JavaScript vanilla.

**Stack:** PHP 8.2 (XAMPP, Apache port 8080) · MariaDB via PDO · HTML/CSS/JS
murni dalam satu `<script>` inline di `index.php` · tanpa build step.

## Peta file

| File / folder | Isi |
|---|---|
| `index.php` | Aplikasi utama: header, nav, satu blok `<script>` berisi semua logika UI |
| `style.css` / `ef.css` | CSS bersama semua halaman (LF) / CSS Exhaust Fan (CRLF, dimuat kedua) |
| `auth.php` | `session_start()`, `db()` (singleton PDO), `require_login()`, `current_user()` |
| `login.php` / `logout.php` | Login (`password_verify`, `is_active`, `session_regenerate_id`) dan logout |
| `config.php` | Kredensial DB — **gitignored, jangan pernah ditampilkan** (`config.example.php` = contoh) |
| `api_ahu.php` | AHU 1–93 (BACnet), 94–95 (HNX), 96–97 (Daikin), EC fan |
| `api_hvac_sc.php` / `api_chiller.php` | Chiller/CT/CCP/CHWP BACnet / Chiller MODBUS |
| `api_chiller_setpoint.php` | Riwayat `chiller_setpoint_hourly` (tab SETPOINT) |
| `api_ef.php` | `EXHAUST_FAN` (panel 1–9) + `EXHAUST_FAN_G8` (EF 58–61) |
| `api_room.php` / `api_temp_rh_hourly.php` | Suhu & RH live (termasuk Daikin/G8) / riwayat per jam |
| `api_energy.php` / `api_actlog.php` | kWh harian (`?summary=1` = H-1) / riwayat ON/OFF |
| `api_alarm.php` / `api_notes.php` | Daftar alarm & count / sticky notes (JSON only, cek Origin) |
| `temp_rh_snapshot.php` / `kwh_daily_snapshot.php` | Snapshot CLI-only, `INSERT IGNORE` |
| `SQL/` | DDL tabel baru — dijalankan manual oleh user |
| `docs/` · `Prompt/` · `sounds/` · `verify_ui.js` | Dokumen (sebagian usang) · catatan tugas lama · `alarm_beep.wav` (tidak dipakai browser) · skrip Playwright yang tidak berfungsi di sini |

## Tab dan cara menambah halaman

Urutan di `<nav id="equipmentNav">`: **MAIN · CHILLER · AHU · CT · CCP · CHWP ·
ROOM_TEMP_RH · EXHAUST_FAN · AHU_G8 · ALARM · ENERGY · ACTIVITY_LOG**.

Tab baru harus didaftarkan di **empat** tempat; melewatkan satu membuat halaman
hilang dari nav, URL, atau dispatch:

1. Tombol `<button class="nav-button" data-equipment="KEY">` di `index.php`.
2. Array `const valid = [...]` di `getInitialEquipment()`.
3. Array `const valid = [...]` di `navigateTo()` (duplikat dari #2).
4. Cabang `if` baru di rantai `renderEquipment()` yang memanggil renderer-nya.

`#page`/`?page=`/`?equipment=` dibaca `getInitialEquipment()`; `hashchange`
memanggilnya lagi. `HVAC_SC` bukan tab — dipetakan ke `CHILLER` + `chillerTab =
"BACNET"`. Tab Chiller punya sub-halaman via `buildPageSwitch` (MODBUS / BACNET
/ SETPOINT) dengan hash `#CHILLER`, `#CHILLER_BACNET`, `#CHILLER_SETPOINT`.

## Sumber data dan interval

| Halaman | Endpoint | Interval |
|---|---|---|
| MAIN | enam API sekaligus (`loadData()` cabang MAIN) | 10 s |
| AHU / CT / CCP / CHWP / EXHAUST_FAN / AHU_G8 / CHILLER / ROOM (CURRENT) | API halaman masing-masing via `safeLoadData()` | 10 s |
| ALARM | `api_alarm.php` + `api_ef.php` | 10 s (`ALARM_POLL_MS`), poll mandiri |
| ACTIVITY_LOG / ENERGY | `api_actlog.php` / `api_energy.php` | 30 s / 300 s via registry |
| ROOM_TEMP_RH (HOURLY) | `api_temp_rh_hourly.php` | manual, refresh bila kembali terlihat > 10 menit |
| Sticky notes (halaman AHU saja) | `api_notes.php` | 30 s (`NOTES_POLL_MS`), berhenti saat tab hidden |

Konstanta: `ACTLOG_REFRESH_INTERVAL_MS`, `ENERGY_REFRESH_INTERVAL_MS`,
`STATISTICS_REFRESH_INTERVAL_MS`, `STATS_STALE_THRESHOLD_MS`, `ALARM_POLL_MS`,
`ALARM_FETCH_TIMEOUT_MS`, `ALARM_GUARD_EXPIRY_MS`, `BACKOFF_STEPS`,
`RUN_STATE_MAX_AGE_MS`, `EF_OFF_CONFIRM_READINGS`, `NOTES_POLL_MS`.
`isHistoryPage()` membedakan halaman riwayat; di sana timer 10 s di-skip.

## Pemetaan gedung dan peralatan

- **Gedung** dari `device_id`: `10203` = G1-G2, `10202` = G3, `"daikin"` = G8
  (`ROOM_GROUP_DEFS`); yang tak dikenal masuk `"Other"`, tidak dibuang. Pemetaan
  area: `ROOM_DEVICE_AREA`, `ROOM_SOURCE_AREA`, `ROOM_AREA_ORDER`,
  `ENERGY_DEVICE_AREA`.
- **AHU 1–93** = Azbil BACnet; **94–95** = HNX G8 (Modbus TCP), `run` bisa
  `null`; **96–97** = Daikin, status-only tanpa sensor duct.
- **Exhaust Fan** = panel 1–9; **EF 58–61** = G8, dikirim terpisah sebagai
  `EXHAUST_FAN_G8` dan tidak boleh dicampur ke panel. Nama eksplisit:
  `G8_AHU_NAMES`, `G8_EF_NAMES`, `G8_AHU_9495`, `G8_AHU_9697`.

## Aturan alarm, notes, auto-refresh

**Alarm**

- `event_class` = `ALARM` (merah, berbunyi) atau `WARNING` (amber, **tidak
  pernah** berbunyi). Unit definitif OFF (`checkUnitIsDefinitivelyOff()`) tidak
  membunyikan alarm, tetapi EF OFF **selalu** jadi trigger (baris sintetis
  `EFOFF:`), dengan debounce `EF_OFF_CONFIRM_READINGS` (= 2) pembacaan berbeda.
- Mute = **acknowledge**, bukan toggle. Key di localStorage
  `hvac_alarm_acknowledged_keys`; jendela di `hvac_alarm_window_hours`; key
  legacy `hvac_alarm_muted` dihapus. Key aktif dipangkas hanya saat API
  sumbernya sukses.
- Bunyi diputar aplikasi C#/WebView2 (`postMessage("PLAY_ALARM"/"STOP_ALARM")`,
  balik `ACK_FROM_C#`), bukan browser. Widget `#globalAlarmWidget` menampilkan
  "ALARM FEED OFFLINE" setelah 3 kegagalan; tombol ACK web permanen `is-hidden`;
  tab ALARM mendapat `.tab-blinking` selama ada yang belum di-ack.

**Sticky notes**

- `api_notes.php`: GET daftar note terbuka (`?equip_type=` whitelist); POST
  **JSON saja** (415), cek Origin/Referer vs `HTTP_HOST` (403), sesi wajib (401),
  username sesi wajib (403), aksi `save`/`resolve`, maksimal 500 karakter.
- `unit_notes`: UNIQUE di kolom generated `active_unit_key`
  (`equip_type:unit_id`, NULL saat resolved); error 1062 di-retry sebagai
  UPDATE. Key frontend `noteKey()` → `"AHU:AHU 12"` (uppercase). UI: badge,
  popover "kertas" saat hover, modal detail (double-click/tap/long-press).
  Saat ini note hanya dimuat di halaman AHU.

**Auto-refresh (registry)**

- `REFRESH_REGISTRY` berisi `ACTIVITY_LOG`, `ENERGY`, `ROOM_TEMP_RH_HOURLY`.
  Aturan di `executeRefresh()`/`scheduleNextTick()`: tidak ada request
  tumpang tindih, berhenti saat tab hidden, tunda bila modal/popover terbuka
  atau user mengetik < 10 s (`isRefreshBlocked()`), backoff `BACKOFF_STEPS`,
  teks "Updated HH:MM:SS (stale)" via `updateStatusTextUI()`.
- Halaman baru yang butuh auto-refresh: entri di `REFRESH_REGISTRY` + key di
  `getActiveRefreshKey()`.

## Aturan penting

1. **Data tidak ada ≠ angka 0.** Tampilkan `—`/`--`. Pakai `ahuNum()`,
   `hvacScNum()`, `roomNum()`, `G8_EMPTY`, `isOutOfRange()` — jangan `Number()`
   mentah.
2. **Jangan ubah struktur tabel yang sudah ada** tanpa konfirmasi user.
3. **Jangan menambah warna atau pola komponen baru** bila yang ada cukup
   (lihat skill `hvac-design`).
4. **Jangan commit/push tanpa persetujuan user**; jangan `git add .` — tambahkan
   file eksplisit karena working tree sering berisi perubahan tak terkait.
5. **Tabel DB baru butuh persetujuan user**; user menjalankan SQL sendiri,
   urutannya **remote dulu, baru push kode**.
6. Jangan menaruh kredensial, isi `config.php`, token, atau IP internal di
   dokumen yang bisa di-push.

## Verifikasi WAJIB

`php -l` hanya memeriksa PHP — **tidak** menangkap error JavaScript. Dulu satu
`const` duplikat membuat seluruh situs kosong. Selalu jalankan:

```bash
# 1. Lint PHP (jangan pakai `php` polos — tidak ada di PATH)
/c/xampp/php/php.exe -l index.php
for f in *.php; do /c/xampp/php/php.exe -l "$f" || break; done

# 2. Ekstrak blok <script> inline, ganti tag PHP dengan null, cek sintaks +
#    variabel global tak terdefinisi
node -e "const fs=require('fs');const s=fs.readFileSync('index.php','utf8');const m=s.match(/<script>([\s\S]*)<\/script>/);fs.writeFileSync(process.env.TEMP+'/hvac_inline_check.js',m[1].replace(/<\?=([\s\S]*?)\?>/g,'null'));console.log('extracted',m[1].length,'chars')"
node --check "$TEMP/hvac_inline_check.js"
npx --yes eslint@8 --no-eslintrc --env browser,es2022 \
    --rule '{"no-undef":"error"}' "$TEMP/hvac_inline_check.js"
```

3. Cek UI di browser pada **390 px dan 1600 px**: nol `pageerror`, nol
   `console.error`, 12 tab terlihat. Harness CDP tanpa dependency (Node 22
   `WebSocket` + Chrome headless + cookie `PHPSESSID`) pernah dipakai;
   `verify_ui.js` di repo **tidak bisa dipakai** (Playwright absen).

Laporkan output perintah yang benar-benar dijalankan — jangan menulis
"verified" tanpa menjalankannya.

## Cara kerja

- Tugas besar: **Phase 1** = rencana + tunggu persetujuan, **Phase 2** =
  implementasi. Jangan digabung.
- Prompt "read-only" berarti benar-benar read-only: jangan ubah file, SQL,
  config, atau DB.
- User sering bekerja dari HP: kirim file **utuh**, komentar di setiap blok
  penting, penjelasan singkat.
- Selalu lihat diff sebelum commit dan tunggu review user.
- `docs/` dan `Prompt/` bisa usang; kode, DB, dan output perintah adalah sumber
  kebenaran.

## Belum diketahui / TODO: confirm

- Cadence poller **remote** (dual-write ke DB hosting) — dokumen menyebut tiap
  tick ke-6 (~30 s); tidak bisa diverifikasi dari repo.
- Paritas skema local (`hvac_current`) vs remote (`u468140406_hvac`); mungkin
  ada tabel yang belum dibuat di remote.
- Cadence poller EF: dokumen lama 30 s, pengukuran lokal ~2 s — mana yang
  berlaku di produksi?
- Apakah C#/WebView2 mengabaikan `PLAY_ALARM` berulang (kontraknya di luar repo).
- Scheduler `temp_rh_snapshot.php` dan `kwh_daily_snapshot.php` tidak ada di repo.
