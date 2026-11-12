---
name: hvac-design
description: Aturan desain dan pola UI/UX untuk aplikasi HVAC Monitoring (index.php + style.css + ef.css). Gunakan skill ini sebelum mengubah tampilan, menambah halaman/tab, membuat tile, kartu, badge, atau popover, agar warna, tata letak, dan pola komponen tetap konsisten dengan yang sudah ada.
---

# HVAC Design — aturan tampilan

Semua aturan di bawah **diverifikasi dari `index.php`, `style.css`, dan `ef.css`**.
Jika ada bagian kode yang menyimpang dari aturan, itu dilaporkan sebagai
temuan (lihat bagian akhir), bukan dijadikan aturan baru.

## 1. Bahasa visual umum

- Tema gelap, latar `#0f1216` / panel `#151b22` / border `#222b34`.
- Angka utama memakai font monospace
  (`ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`).
- Teks sekunder: `#9aa7b3`; teks redup: `#6f7c88` / `#777`.
- Status **selalu** ditulis sebagai teks/label, tidak hanya warna. Contoh:
  kartu UNKNOWN menulis "UNKNOWN", tile punya label "CRITICAL/WARNING/NORMAL".
- `--` dan `—` adalah penanda "tidak ada data". **Jangan pernah memakai `0`**
  untuk nilai yang tidak terukur.

## 2. Warna status (hardcoded, tidak ada variabel status)

Warna status di `style.css` ditulis sebagai hex langsung — **tidak ada** custom
property untuk status. Salin nilai yang sudah ada, jangan menambah token baru.

| Makna | Latar | Aksen / teks | Catatan |
|---|---|---|---|
| WARNING (amber) | `#f5b83d` | border `#ffd98a`, tinta `#1a1300` | sel selalu **tinta gelap** di atas amber |
| CRITICAL / alarm | `#c62828` | border `#ff9a92`, teks `#fff` | tile summary memakai `#c62828` |
| OFF / stopped | `#b51d1d` | border `#ed3c3c` | kartu `.status-off` di EF |
| RUN / normal | `#4ade80` (teks) | latar kartu `#151b22` | dot hijau `.ahu-run-dot.is-run` |
| UNKNOWN | `#1b222a` | border putus-putus `#2c3640`, teks `#9aa7b3` | bukan fallback, ini band nyata |
| Nonaktif / abu | `#555` | border `#777` | `.status-unknown` lama |
| Teks alarm berkedip | `#ef4444` | — | `.value-alarm`, di kartu OFF menjadi `#000` |

Judul section: critical `#ff9a92`, warning `#f5b83d`, normal `#4ade80`,
unknown `#9aa7b3`.

## 3. Urutan halaman daftar (AHU, HVAC SC/CT/CCP/CHWP, ROOM TEMP & RH)

Urutan dari atas ke bawah:

1. **Summary tiles** — menghitung kesehatan, bukan kondisi operasi.
   - AHU: 4 tile CRITICAL / WARNING / NORMAL / UNKNOWN (`.ahu-summary`).
   - HVAC SC: 3 tile CRITICAL / WARNING / TOTAL UNIT (`.hsc-summary`).
   - ROOM: 5 tile WARNING / NORMAL / UNKNOWN / INDOOR AVG TEMP / INDOOR AVG RH
     (`.ahu-summary.rt-summary`).
2. **Strip status operasi** terpisah — RUNNING / STOPPED / OP UNKNOWN
   (`.ahu-ops`, `.hsc-ops`) dengan dot. Unit yang berhenti tetap sehat, jadi
   status operasi **tidak boleh** dicampur ke tile kesehatan.
3. **Filter & pencarian** — chip "Issues First" / "All N" (`.ahu-controls`,
   `.hsc-controls`) + kotak pencarian. Mode default = Issues First.
4. **Issues First**: unit WARNING dikumpulkan di section WARNING sendiri,
   paling parah lebih dulu (`roomSeverity()` / `ahuDeviation()`).
5. **Section per grup/band**:
   - AHU & HVAC SC: band CRITICAL → WARNING → NORMAL → UNKNOWN
     (`AHU_BANDS`, `HVAC_SC_BANDS`), band kosong tidak dirender.
   - ROOM: section per gedung (`ROOM_GROUP_DEFS` + `ROOM_GROUP_OTHER`), tiap
     section berisi tile dan badge kesehatan tertulis.
   - Mode "All" = satu grid urut nama, tanpa reorder severity.

Lihat kode: `renderAhuDashboard()`, `renderHvacScPage()`, `renderRoomTempRh()`.

## 4. Isi kartu dan grid

- **Kartu AHU** (`.ahu-card`): dot RUN (hijau/merah/abu), tag, nama, nilai
  suhu (tidak ada untuk AHU 96–97), label OFF/UNKNOWN bila tidak berjalan, dan
  badge note bila ada note aktif. Grid `.ahu-cards` =
  `repeat(auto-fill, minmax(104px, 1fr))`, min-height 78 px.
- **Tile room** (`.rt-tile`): nama ruangan, tag, angka suhu + RH, badge status.
  Grid `.rt-tiles` = `minmax(150px, 1fr)` (≥1100 px: `165px`; ≤600 px: 2 kolom).
- **Kartu EF** (`.unit-card.ef-card`): dot, nama, label OFF/UNKNOWN bila tidak
  berjalan. Dikelompokkan per panel (`.ef-panel`), panel 1–9; EF G8 dipisah.
- Jangan membuat kartu/panel baru dengan struktur berbeda bila kartu yang ada
  bisa dipakai.

## 5. MAIN dashboard — "Needs attention"

- Grid desktop (`.main-dashboard.desktop-only`) dan versi mobile
  (`.main-dashboard-mobile`), dipisah di breakpoint 768 px
  (`.desktop-only` / `.mobile-only`).
- Kartu "Needs attention" menampilkan maksimal 5 isu desktop (4 di mobile),
  dari array `issues` di `renderMainDashboard()`. Isi satu item: nama
  peralatan · nilai, alasan, dan dot merah (critical) / kuning (warning).
- Isu critical: AHU `alarm = 1` atau HVAC SC alarm. Isu warning: nilai di luar
  batas (suhu duct/room, CT return temp, RH ruangan).
- Klik item navigasi ke tab terkait dengan filter nama peralatan.
- **Jangan menambah sumber isu baru** tanpa mengubah `renderMainDashboard()`
  agar mobile dan desktop tetap sama.

## 6. Sticky note — badge dan popover "kertas"

- Badge kecil (`.ahu-note-btn`, 20×20 px) di sudut kiri atas kartu AHU. Di
  kartu WARNING amber, badge berubah menjadi latar gelap `#1a1300` dengan
  glyph terang agar terbaca.
- Popover `.unit-note-popover`: kartu "kertas" terang, fixed, non-modal.
  Gunakan **variabel** di `:root`, jangan hex baru:
  `--note-paper`, `--note-ink`, `--note-border`, `--note-accent-bar`,
  `--note-reason`, `--note-meta`, `--note-popover-btn-bg`,
  `--note-popover-btn-text`, `--note-popover-btn-hover`,
  `--note-badge-warn-bg`, `--note-badge-warn-ink`,
  `--note-badge-default-bg/ink/border`.
- Modal detail (`.ahu-detail-modal`) memakai set variabel gelap yang sama:
  `--note-surface`, `--note-surface-raised`, `--note-modal-border`,
  `--note-text`, `--note-text-muted`, `--note-accent-gold`, `--note-danger-bg`.
- Interaksi: hover (mouse) = popover; double-click / double-tap / long-press =
  modal detail. Single tap tidak melakukan apa-apa.

## 7. Header widget

- Topbar: brand + versi deploy (`.app-version`), `#globalAlarmWidget`, status
  koneksi (`#connectionDot`, `#connectionText`, `#lastUpdate`).
- `#globalAlarmMuteBtn` berisi teks **ACK ALARM** (ada alarm belum di-ack),
  **ACKED (n)** (semua sudah di-ack), atau kosong. Tombol ACK web sengaja
  disembunyikan permanen (`is-hidden` di setiap cabang); acknowledge dilakukan
  dari aplikasi C#/WebView2 lewat `ACK_FROM_C#`. Tombol ini berbasis
  acknowledge, bukan mute — jangan mengubahnya menjadi toggle mute.
- `#globalAudioNotice` menampilkan "ALARM FEED OFFLINE" setelah 3 kegagalan.
- Nav tab ALARM memakai `.tab-blinking` saat ada alarm belum di-ack.
  `document.title` tetap "HVAC Monitoring" (tidak berkedip).

## 8. Layout HP

- Acuan lebar 390 px. Semua tab harus terlihat sekaligus: nav wrap, bukan
  scroll horizontal (aturan di sekitar breakpoint 768 px).
- Target sentuh minimum **44×44 px** (`.ahu-note-btn::after`, tombol header).
- Jangan membuat teks lebih kecil dari 12 px untuk isi. (Kode saat ini masih
  memakai 9–11 px di beberapa label — lihat temuan.)
- Grid ringkas: AHU `96px` minimum di ≤900 px, room 2 kolom di ≤600 px.
- MAIN memakai dashboard mobile terpisah di ≤768 px, bukan desktop yang
  diperkecil.

## 9. Wall display (layar besar)

- Blok `@media (min-width: 1600px) and (max-width: 2499px)` memakukan
  `html, body` ke tinggi viewport dengan `overflow-y: hidden`.
- Untuk halaman yang lebih tinggi dari layar, pakai pola **escape hatch**
  `html:has(.kelas-halaman), body:has(.kelas-halaman) { height: auto;
  overflow-y: auto; }`. Kelas yang sudah punya escape hatch: `.history-page
  .history-chart-stack`, `.alarm-page`, `.actlog-page`/`.is-actlog-view`,
  `.ahu-dashboard`, `.room-dashboard`, `.hvac-sc-dashboard`.
- Halaman yang butuh perilaku sama wajib memakai pola ini dengan kelas khusus
  halamannya — **jangan** melonggarkan clip untuk semua halaman.
- Di wall display: tile room lebih lebar, popover note 320 px / teks 16 px.

## 10. Jangan menambah warna atau pola baru

- Jangan menambah custom property atau token warna untuk status.
- Jangan menambah komponen baru (kartu, chip, tile, badge) bila komponen yang
  ada bisa dipakai ulang dengan modifier class.
- Jangan mengubah font, radius (`8/10/12 px`), atau shadow di luar yang sudah
  ada.
- Perubahan CSS halaman tertentu sebaiknya tidak menyentuh aturan halaman lain
  (lihat arah refactor CSS per halaman di memory proyek — belum dikerjakan).

## 11. Penyimpangan yang ditemukan di kode (jangan dijadikan aturan)

- **Angka fallback hardcoded** di `renderMainDashboard()`: rata-rata AHU
  `"22.8"`, CT `"30.8"`, suhu ruangan `"22.4"`, RH `58` dipakai ketika tidak ada
  data — bertentangan dengan aturan "tidak ada data = —".
- Riwayat energi MAIN punya array 7 hari hardcoded (21–27 Oct) sebagai fallback
  bila `daily_history` kosong.
- Teks `"2 new in 24 h"` di kartu Global Alarm System selalu statis.
- Kolom waktu "Needs attention" memakai label palsu (`now`, `5 m`, `42 m`,
  `2 h`, `3 h`) dan bukan waktu kejadian sebenarnya.
- Beberapa label memakai font 9–11 px (di bawah target minimum 12 px), mis.
  `.rt-panel-sub`, `.rt-tag`, badge nav di HP (11.5 px).
- `style.css` seluruhnya LF (bukan campuran CRLF/LF); hanya `ef.css` yang CRLF.
- `knownAlarmIds` dideklarasikan dan di-reset tetapi tidak dibaca di mana pun
  (sisa kode lama).
