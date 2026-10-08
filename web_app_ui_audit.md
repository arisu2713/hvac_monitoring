# WEB APP UI AUDIT — ENERGY / TEMP-RH HOURLY / ALARM / SOUND MUTE

## Tujuan
Audit READ-ONLY terhadap Web App HVAC Monitoring.

Target:
1. Cek apakah halaman/section Alarm sudah ada.
2. Cek apakah Energy sudah memiliki tampilan tabel di Web App.
3. Cek apakah Temperature & RH Hourly sudah memiliki tampilan tabel di Web App.
4. Cek apakah Sound Alarm dan tombol Mute sudah ada.

## Batasan
- HANYA audit source code Web App.
- Jangan mengubah file apa pun.
- Jangan menjalankan git add / commit / push.
- Jangan menyentuh database/schema.
- Jangan menyentuh poller:
  - C:\Projects\azbil_bacnet
  - C:\Projects\monitoring_ef
- Jangan membuat data dummy.
- Jangan mengimplementasikan fitur.
- Gunakan command/PowerShell untuk inspeksi.
- Setelah audit selesai, STOP dan berikan laporan.

## Repository
C:\xampp\htdocs\hvac_monitoring

## Langkah 1 — Status Git

    cd C:\xampp\htdocs\hvac_monitoring
    git status --short --branch
    git log -1 --oneline

Jangan mengubah working tree.

## Langkah 2 — Inventaris file Web App

    Get-ChildItem -File | Select-Object Name,Length,LastWriteTime

    Get-ChildItem -Recurse -File -Include *.php,*.css,*.js | Select-Object FullName

## Langkah 3 — Audit ALARM PAGE

    Get-ChildItem -Recurse -File -Include *.php,*.css,*.js | Select-String -Pattern 'alarm_events|global alarm|Global Alarm|WARNING|ALARM|alarm' -CaseSensitive:$false

    Get-ChildItem -Recurse -File -Include *.php,*.js | Select-String -Pattern 'alarm' -CaseSensitive:$false

Tentukan:
- Apakah halaman alarm benar-benar sudah ada?
- File entry point?
- Menu/link?
- Placeholder atau membaca data?
- Pemisahan WARNING dan ALARM?
- Status ACTIVE/CLEAR?
- Detail equipment/source/message/time?

Jangan menyimpulkan "sudah ada" hanya karena ditemukan kata alarm. Harus ada bukti implementasi UI/API.

## Langkah 4 — Audit ENERGY TABLE UI

    Get-ChildItem -Recurse -File -Include *.php,*.css,*.js | Select-String -Pattern 'energy|kwh|kWh|KWH|energy_daily|energy_hourly|daily.*energy|hourly.*energy' -CaseSensitive:$false

    Get-ChildItem -Recurse -File -Include *.php,*.js | Select-String -Pattern 'Energy|energy|kWh|KWH' -CaseSensitive:$false

Tentukan:
- Ada menu/halaman Energy?
- Ada tabel HTML/grid yang benar-benar menampilkan data energy?
- File PHP/API?
- Ada date filter/range selector?
- Kolom yang ditampilkan?
- Hanya chart/card atau benar-benar tabel?
- Jika belum ada: NOT IMPLEMENTED — UI TABLE.

Jangan mengubah apa pun.

## Langkah 5 — Audit TEMP & RH HOURLY TABLE UI

    Get-ChildItem -Recurse -File -Include *.php,*.css,*.js | Select-String -Pattern 'temp_rh|temperature|humidity|RH|hourly' -CaseSensitive:$false

    Get-ChildItem -Recurse -File -Include *.php,*.js | Select-String -Pattern 'temp_rh_hourly|hourly' -CaseSensitive:$false

Tentukan:
- Ada halaman/section Temp & RH Hourly?
- Ada tabel HTML/grid?
- API/PHP?
- Data hourly benar-benar ditampilkan?
- Ada filter tanggal?
- Kolom yang ditampilkan?
- Jika hanya chart, jangan menyebutnya table.
- Jika belum ada: NOT IMPLEMENTED — UI TABLE.

## Langkah 6 — Audit SOUND ALARM + MUTE

    Get-ChildItem -Recurse -File -Include *.php,*.html,*.js,*.css | Select-String -Pattern 'audio|Audio|sound|Sound|beep|alarm.*sound|sound.*alarm|play\(' -CaseSensitive:$false

    Get-ChildItem -Recurse -File -Include *.php,*.html,*.js,*.css | Select-String -Pattern 'mute|Mute|unmute|Unmute' -CaseSensitive:$false

Tentukan:
- Ada audio element/file/sound generator?
- Apa trigger suara?
- Benar-benar memanggil playback?
- Ada tombol Mute?
- Mute hanya audio browser/local UI?
- Persistent atau runtime?
- Setelah mute alarm baru tetap muncul secara visual?

Jangan menganggap adanya kata mute berarti fitur sudah bekerja. Harus ada implementasi event/logic.

## Langkah 7 — Audit index/menu

    Select-String -Path .\index.php -Pattern 'Alarm|Energy|Temp|RH|Hourly|Mute|Sound' -CaseSensitive:$false

Jika ada JS terpisah, periksa juga.

## Langkah 8 — Jangan melakukan perubahan

Setelah audit:
- jangan edit source
- jangan membuat file di repository
- jangan commit
- jangan push
- jangan restart service
- jangan mengubah DB

## FORMAT LAPORAN AKHIR

# WEB APP UI AUDIT RESULT

## 1. Alarm Page
STATUS: IMPLEMENTED / PARTIAL / NOT IMPLEMENTED

Evidence:
- file:
- route/menu:
- API/data source:
- UI behavior:

## 2. Energy Table
STATUS: IMPLEMENTED / PARTIAL / NOT IMPLEMENTED

Evidence:
- file:
- route/menu:
- API/data source:
- table columns:
- filter:

## 3. Temp & RH Hourly Table
STATUS: IMPLEMENTED / PARTIAL / NOT IMPLEMENTED

Evidence:
- file:
- route/menu:
- API/data source:
- table columns:
- filter:

## 4. Sound Alarm
STATUS: IMPLEMENTED / PARTIAL / NOT IMPLEMENTED

Evidence:
- file:
- trigger:
- playback mechanism:

## 5. Mute Button
STATUS: IMPLEMENTED / PARTIAL / NOT IMPLEMENTED

Evidence:
- file:
- button:
- behavior:
- persistence:

## 6. Summary

| Feature | Status | Evidence |
|---|---|---|
| Alarm page | | |
| Energy table | | |
| Temp & RH hourly table | | |
| Sound alarm | | |
| Mute button | | |

## 7. Git Safety

Laporkan:
- branch:
- HEAD:
- working tree sebelum audit:
- apakah ada file yang berubah selama audit?

HARUS berakhir dengan:

`AUDIT ONLY — NO FILES MODIFIED`
