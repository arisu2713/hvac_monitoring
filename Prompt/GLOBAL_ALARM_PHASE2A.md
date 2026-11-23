# GLOBAL ALARM — PHASE 2A
# EVENT CONTRACT DISCOVERY & DESIGN

Tujuan:
Susun kontrak final untuk global alarm system berdasarkan:
- GLOBAL_ALARM_DISCOVERY_REPORT.md
- GLOBAL_ALARM_PHASE1_FINAL_GO.md
- schema hvac_current.alarm_events yang SUDAH dibuat
- hasil behavioral test Phase 1 yang SUDAH PASS

MODE:
- READ/DISCOVERY/DESIGN ONLY.
- Jangan mengubah database.
- Jangan mengubah schema alarm_events.
- Jangan mengubah poller.
- Jangan mengubah API.
- Jangan mengubah frontend.
- Jangan mengubah PROJECT.md.
- Jangan GRANT privilege apa pun.
- Jangan commit/push.
- STOP setelah laporan selesai.

## 1. EVENT CLASS

WARNING = threshold/value-limit violation.

ALARM = native equipment alarm/status point.

WARNING dan ALARM adalah dua kategori berbeda.
Jangan mengonversi WARNING menjadi ALARM atau sebaliknya.

Equipment yang sama boleh mempunyai WARNING dan ALARM aktif secara bersamaan sebagai event terpisah.

## 2. ACTIVE KEY

Tentukan format active_key FINAL untuk:

- AHU
- CHILLER
- CCP
- CHWP
- CT
- EF
- ROOM TEMP & RH

active_key harus:

- deterministic
- stabil antar polling cycle
- unik untuk satu kondisi alarm aktif
- memungkinkan event CLEARED muncul kembali sebagai event baru
- tidak bergantung pada database row id

Gunakan identity yang benar-benar ditemukan pada discovery.

Jika belum didukung discovery, tulis NOT ESTABLISHED.
Jangan menebak.

## 3. WARNING CONTRACT

Untuk setiap threshold source yang memang sudah ditemukan:

- source point
- metric
- limit_min
- limit_max
- kondisi ACTIVE
- kondisi CLEAR
- active_key
- equipment identity
- point identity

Threshold harus berasal dari sumber yang sudah ditemukan.

Jangan membuat threshold baru.

## 4. ALARM CONTRACT

Untuk setiap native alarm/status source yang sudah ditemukan:

- source point
- kondisi ACTIVE
- kondisi CLEAR
- active_key
- equipment identity
- point identity

Jangan mengubah native ALARM menjadi WARNING.

## 5. EF

Periksa kembali discovery EF:

- ef_points.id sebagai point identity
- panel_no/ip untuk communication identity
- derived ON→OFF event yang sudah ditemukan
- OFF→ON sebagai recovery jika memang didukung discovery
- communication event harus dipisahkan dari equipment ALARM

Jangan mengubah desain EF tanpa bukti discovery.

Jika granularity alarm EF masih belum established, tulis NOT ESTABLISHED.

## 6. CT

Pertahankan struktur CT yang sudah ditemukan:

- CT 1–4 = satu VSD untuk A+B
- CT 5A/5B dan 6A/6B = masing-masing VSD
- CT 9–13 = satu VSD untuk A+B

Jangan membuat active_key seolah-olah CT 1A dan CT 1B adalah dua VSD berbeda.

## 7. ROOM TEMP & RH

Pertahankan point_id sebagai identity.

ROOM temperature/RH threshold menghasilkan WARNING.

Outdoor point tidak boleh diberi threshold tanpa bukti discovery.

## 8. alarm_events MAPPING

Untuk setiap jenis event tentukan mapping:

- event_class
- source
- equip_type
- equipment
- point_id
- ef_point_id
- panel_no
- ip_address
- metric
- description
- status
- active_key
- value
- limit_min
- limit_max
- raised_at
- cleared_at

Jangan mengisi identity yang tidak didukung sumber.

## 9. RAISE/CLEAR STATE MACHINE

Gunakan model:

NORMAL
  -> ACTIVE
  -> CLEARED
  -> ACTIVE lagi

Polling berulang ketika kondisi tetap ACTIVE:

- TIDAK membuat row baru
- TIDAK mengubah raised_at
- TIDAK membuat duplicate active_key

Ketika kondisi kembali normal:

- row ACTIVE menjadi CLEARED
- cleared_at diisi
- active_key menjadi NULL

Jika kondisi aktif kembali setelah CLEARED:

- buat row baru
- gunakan active_key yang sama
- raised_at baru
- jangan mengubah historical CLEARED row

## 10. SOURCE CONTRACT

Evaluasi source/writer berikut:

AHU_POLLER
HVAC_SC_POLLER
EF_POLLER
ROOM_POLLER

Pastikan source mempunyai arti sebagai writer/source system,
bukan event category.

event_class tetap menentukan WARNING atau ALARM.

## 11. OUTPUT REPORT

Buat laporan dengan bagian:

A. Source → Event Class matrix

B. Active_key contract table

C. WARNING contract table

D. ALARM contract table

E. EF special handling

F. CT identity handling

G. ROOM TEMP & RH handling

H. Raise/Clear state machine

I. alarm_events field mapping

J. Ambiguities/gaps yang masih membutuhkan keputusan user

K. Recommended Phase 2B implementation order

Gunakan hanya fakta yang didukung discovery.

Jika suatu mapping belum established:
tulis NOT ESTABLISHED.

Jangan melakukan perubahan sebagai bagian dari laporan.

STOP setelah laporan selesai.
