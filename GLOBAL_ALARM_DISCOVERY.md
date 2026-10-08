# GLOBAL ALARM — DISCOVERY INSTRUCTIONS

Lakukan DISCOVERY ONLY untuk desain GLOBAL ALARM pada project HVAC Monitoring.

TUJUAN:
Saya ingin membuat satu halaman ALARM global yang mencakup seluruh equipment/page:
- AHU
- HVAC_SC
- EXHAUST FAN / EF
- nantinya dapat mencakup equipment HVAC lain tanpa membuat tabel alarm terpisah per page.

PENTING:
- DISCOVERY SAJA.
- Jangan membuat tabel.
- Jangan ALTER database.
- Jangan INSERT/UPDATE/DELETE data.
- Jangan mengubah PHP/CSS/JS.
- Jangan mengubah poller.
- Jangan mengubah api_ahu.php, api_hvac_sc.php, api_ef.php, api_room.php, index.php.
- Jangan commit/push.
- Jangan membuat file baru selain file laporan discovery ini.
- Jangan menganggap desain alarm sebelumnya sebagai final.

1. DISCOVERY ALARM EXISTING
Cari seluruh implementasi alarm yang sudah ada di repository.
Cari: alarm, threshold, isOutOfRange, alarm_code, alarm_type, severity, alarm status, trip, fault, warning, high/low limit, min/max, alarm-related CSS/JS, fix_value_alarm_color.md, threshold_rules_seed.sql.
Laporkan file, fungsi, query, field, logic, apakah hanya visual alarm atau sudah ada alarm event/history, dan apakah alarm disimpan ke database atau dihitung realtime.

2. DISCOVERY DATABASE
Inspect database structure READ-ONLY untuk hvac_current.
Cari table/column terkait alarm, threshold, limit, warning, fault, trip, status, severity, acknowledge, active.
Inspect points, ai_current, bi_current, acc_current, users dan table relevan.
Laporkan table, column, datatype, key/index, contoh nilai bila aman, dan apakah current value atau history.
JANGAN INSERT/UPDATE/DELETE.

3. DISCOVERY POINT ALARM
Petakan alarm source:
AHU: alarm BI, status/run BI, AI threshold, equipment identity.
HVAC_SC: Chiller, CCP, CHWP, CT, alarm BI, trip/fault/status, threshold alarm.
EF: alarm/status point dan run/off/unknown logic.
Untuk setiap equipment type tunjukkan equipment, point_id, point_name, obj_type, current source, alarm meaning jika terdokumentasi. Jangan menebak.

4. DISCOVERY EXISTING ALARM LOGIC
Tentukan apakah alarm sekarang:
A. realtime,
B. event,
C. current alarm,
D. kombinasi.
Untuk threshold: sumber threshold, hardcoded atau DB, PROJECT.md, dan apakah threshold_rules_seed.sql dipakai atau draft. Jelaskan alur aktual.

5. DISCOVERY API
Inspect api.php, api_ahu.php, api_hvac_sc.php, api_ef.php dan API relevan.
Cari alarm, alarm state, threshold, min/max, status, last_update.
Bedakan yang hanya untuk warna/status card dengan alarm sebenarnya.

6. DISCOVERY FRONTEND
Inspect index.php dan style.css.
Cari alarm color, status color, alarm text, warning indicator, card color, alarm JS.
Tentukan apakah alarm dicampur dengan equipment status atau sudah terpisah.

7. DESIGN RECOMMENDATION
Setelah discovery selesai, JANGAN implementasi.
Bandingkan:
A. current_alarm saja
B. alarm event/history + active state
Pertimbangkan AHU, HVAC_SC, EF, future equipment, alarm BI, threshold AI, active/cleared, acknowledgement, timestamp, point_id, equipment identity, severity, alarm message, history.

8. TARGET DESAIN
Saya ingin satu global alarm system, bukan ahu_alarm/hvac_sc_alarm/ef_alarm.
Satu struktur generic yang menunjuk equipment/point melalui point_id.
Alarm page nantinya:
TIME | EQUIPMENT | POINT | ALARM | VALUE | LIMIT | SEVERITY | STATUS
Filter: All, Active, Cleared, AHU, HVAC_SC, EF, severity.
Jangan implementasi UI.

OUTPUT WAJIB:
1. Existing alarm implementation
2. Existing alarm-related tables
3. Existing threshold implementation
4. Alarm source per equipment/page
5. Current API behavior
6. Current frontend behavior
7. Gap
8. Rekomendasi schema global alarm
9. Rekomendasi alur alarm
10. File yang nantinya perlu diubah
11. File yang tidak perlu diubah
12. Risiko/hal yang harus diperhatikan

Tidak ada perubahan file/database pada task ini.
Tidak ada commit/push.
