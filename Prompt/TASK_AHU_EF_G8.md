# Task: Complete AHU / EF G8 Dashboard

## Tujuan
Selesaikan halaman AHU G8 yang masih kosong pada web app HVAC Monitoring. Ubah nama menu/header menjadi **AHU / EF G8** dan tampilkan 8 kartu: AHU 94–97 serta EF 58–61.

## 1. Audit sebelum mengubah kode
Periksa `index.php`, navigasi/tab, API dan frontend AHU/EF/Daikin, serta mapping point yang sudah ada. Periksa juga `git status` dan `git diff` sebelum mengubah apa pun. Pertahankan semua perubahan lokal yang sudah ada.

Identitas Daikin berdasarkan IP:
- `10.27.102.35` = AHU 96 + EF 58 + EF 59
- `10.27.102.36` = AHU 97 + EF 60 + EF 61

Jangan menentukan identitas equipment berdasarkan nama BFM1/BFM2.

## 2. AHU 94–95
Sumber: `hvac_current.hnx_g8_points` dan `hvac_current.hnx_g8_current`.

| Equipment | Fan status point ID | Duct Temperature point ID | Duct RH point ID |
|---|---|---|---|
| AHU 94 | 1, 3, 5 | 7 | 8 |
| AHU 95 | 9, 11, 13 | 15 | 16 |

`hnx_g8_current.point_id` terverifikasi berelasi dengan `hnx_g8_points.id`. Gunakan `last_update` untuk timestamp data per point.

Setiap kartu AHU 94/95 menampilkan:
- Status EC Fan 1, EC Fan 2, EC Fan 3
- Duct Temperature
- Duct RH

Ketentuan:
- Gunakan label **Duct Temperature** dan **Duct RH**, bukan Room Temperature/RH.
- Speed EC Fan 1–3 sebelumnya NULL. Jangan tampilkan NULL sebagai angka nol.
- Status agregat AHU menggunakan aturan OR yang telah disepakati: RUN jika minimal satu fan ON; jika semua status NULL, status agregat UNKNOWN. Ini adalah aturan presentasi, bukan status unit resmi dari controller.
- Jangan mengarang status atau nilai yang tidak tersedia.

## 3. AHU 96–97
Gunakan sumber Daikin/Niagara dan mapping yang sudah ada. Jangan mengubah atau membuat ulang poller.

Setiap kartu menampilkan:
- EC Fan 1–5: status/trip sesuai mapping aktual
- Frequency sesuai mapping aktual
- Tiga titik temperatur sesuai inventory aktual
- Room Temperature
- Room RH

Audit nama point, satuan, dan makna ketiga titik temperatur dari API, inventory, database, atau dokumentasi yang tersedia. Jangan mengarang mapping atau mencampur data AHU 96 dengan AHU 97.

## 4. EF 58–61
Setiap kartu menampilkan:
- Status
- Frequency
- Motor Current dalam ampere (`A`)

Gunakan mapping EF yang sudah tersedia. Pastikan setiap EF muncul tepat satu kali. Pertahankan filter `Sts_EF1` dan `Sts_EF2` yang memperbaiki duplikasi kartu EF G8; jangan menghapusnya tanpa bukti perubahan desain data.

## 5. Frontend dan tampilan
- Nama menu/header: **AHU / EF G8**
- Total: 4 kartu AHU + 4 kartu EF.
- Ikuti gaya visual, typography, warna status, dan responsive layout dashboard yang sudah ada.
- Nilai NULL/kosong tampil sebagai `—` atau indikator unavailable, bukan nol.
- Jangan merusak halaman AHU utama atau halaman modul lain.
- Hindari perubahan global CSS jika cukup dilakukan secara lokal.

## 6. Batasan
- Jangan mengubah schema atau isi database.
- Jangan mengubah poller, konfigurasi koneksi, service, atau proses akuisisi.
- Jangan membuat tabel baru.
- Jangan membuat ulang database `hvac_monitoring` yang sudah dihapus.
- Pertahankan perubahan lokal sebelumnya, termasuk `PROJECT.md`, `api_ef.php`, `api_ahu.php`, `index.php`, dan dokumentasi.
- Jangan menjalankan `git add -A`.
- Jangan menghapus file untracked atau menimpa pekerjaan yang sudah ada.

## 7. Pengujian sebelum commit
1. Jalankan `php -l` untuk semua file PHP yang berubah.
2. Verifikasi API untuk AHU 94–97 dan EF 58–61.
3. Pastikan jumlahnya tepat 8 kartu dan tidak ada duplikasi.
4. Pastikan nilai, label, sumber, dan satuan benar.
5. Pastikan AHU 1–93 tidak berubah akibat perubahan ini.
6. Uji halaman di browser; pengujian API saja tidak cukup.
7. Tinjau `git diff` dan pastikan tidak ada perubahan yang tidak terkait.
8. Jika mapping atau nilai belum dapat diverifikasi, laporkan dan jangan mengarang.

## 8. Commit dan push
Lakukan hanya setelah seluruh pengujian lulus.

- Tinjau semua perubahan tracked/untracked.
- Stage file secara selektif; jangan gunakan `git add -A`.
- Sertakan perubahan AHU 94–95, perbaikan EF G8, dan halaman AHU / EF G8 yang selesai.
- Jangan otomatis memasukkan artefak audit, laporan handoff, atau dokumentasi yang belum disetujui.
- Periksa perubahan `PROJECT.md` dan `docs/` secara teliti sebelum memutuskan apakah akan dimasukkan. Jangan menimpa atau membuang perubahan tersebut.
- Jika perubahan tidak dapat dipisahkan dengan aman, berhenti sebelum commit/push dan laporkan.
- Setelah tes lulus, commit dengan pesan yang jelas dan push ke branch `master`.

## 9. Laporan akhir
Laporkan:
- File yang berubah.
- Mapping dan sumber data setiap kartu.
- Hasil tes PHP, API, dan browser.
- Hash commit dan hasil push jika berhasil.
- Status working tree setelah push.
- Masalah atau mapping yang masih belum terverifikasi.
