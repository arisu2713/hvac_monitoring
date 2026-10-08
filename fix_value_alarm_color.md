# Prompt --- Perbaikan Warna Value Alarm HVAC

## Tujuan

Perbaiki **warna value yang terkena alarm threshold** pada dashboard
HVAC Monitoring.

Jangan mengubah logic threshold, API PHP, parsing CT/Chiller, atau logic
status card yang sudah ada.

------------------------------------------------------------------------

## Konteks desain

Threshold alarm value berasal dari tiga tabel database yang SUDAH ADA
dan SUDAH TERISI:

-   `threshold_direct`
-   `threshold_by_kw`
-   `unit_motor_kw`

Logic threshold yang sudah dibuat sebelumnya **SUDAH BENAR**.

Threshold hanya menentukan apakah suatu nilai mendapatkan class
`.value-alarm`.

**Threshold TIDAK menentukan warna card.**

------------------------------------------------------------------------

## Aturan visual FINAL

### 1. Card hijau / RUNNING

Jika nilai melewati threshold `min` atau `max`:

-   value = `#ef4444` (**MERAH**)
-   blinking = `1.2s`
-   tetap blinking

Contoh:

``` text
Card hijau
Temperature 26.2 °C  ← MERAH + BLINK
```

Ini WAJIB terlihat merah.

------------------------------------------------------------------------

### 2. Card merah / STATUS OFF

Jika unit berstatus OFF DAN card memang berwarna merah:

-   value alarm = `#000000` (**HITAM**)
-   tetap blinking `1.2s`

Tujuannya supaya angka alarm tetap terlihat jelas di atas background
card merah.

Contoh:

``` text
Card merah / OFF
Temperature 26.2 °C  ← HITAM + BLINK
```

**Aturan hitam hanya berlaku pada card merah yang berstatus OFF.**

------------------------------------------------------------------------

### 3. Card bukan merah

Jika value melewati threshold:

-   value alarm = `#ef4444` (**MERAH**)
-   blinking = `1.2s`

Jangan gunakan orange/amber.

------------------------------------------------------------------------

### 4. Value normal

Jika nilai masih dalam batas threshold:

-   tidak mendapat `.value-alarm`
-   warna normal tetap seperti sebelumnya
-   tidak blinking

------------------------------------------------------------------------

## Masalah saat ini

Pada tampilan aktual, value yang melewati threshold pada **card hijau**
masih terlihat **ORANGE**, padahal harus:

``` css
color: #ef4444 !important;
```

Contoh threshold AHU:

``` text
AHU temperature > 25 °C
→ value harus MERAH + BLINKING
```

Card hijau tidak boleh membuat value alarm menjadi orange.

------------------------------------------------------------------------

## Tugas debugging

Jangan hanya melihat deklarasi `.value-alarm`.

Gunakan **real browser / computed style** pada value alarm yang
benar-benar tampil di halaman.

Cari selector CSS atau inline style yang menyebabkan warna orange.

Periksa kemungkinan:

-   `.status-alarm`
-   selector status/card lainnya
-   `.value`
-   `.m-val`
-   inline `style`
-   CSS specificity yang lebih tinggi
-   class tambahan yang diberikan saat render
-   selector lain yang meng-override `.value-alarm`

Cari penyebab sebenarnya dari warna orange.

------------------------------------------------------------------------

## CSS yang diinginkan

Default:

``` css
.value-alarm {
    color: #ef4444 !important;
    animation: blink-alarm 1.2s step-start infinite;
}
```

Khusus card OFF:

``` css
.status-off .value-alarm {
    color: #000000 !important;
}
```

Jika selector `.status-off .value-alarm` sudah ada, **jangan membuat
duplikat yang tidak perlu**.

Jika ada selector yang membuat `.value-alarm` menjadi orange, hilangkan
atau override **hanya bagian yang menyebabkan konflik tersebut**.

------------------------------------------------------------------------

## Prinsip penting

Jangan mengubah:

-   `threshold_direct`
-   `threshold_by_kw`
-   `unit_motor_kw`
-   logic pembacaan threshold
-   logic `current_min/current_max`
-   logic `temp_max`
-   logic `rh_max`
-   logic chiller limits
-   logic status card
-   warna/background card
-   PHP API
-   parsing CT
-   parsing Chiller
-   generic error message
-   `display_errors`
-   geometry card
-   typography AHU yang baru diperbaiki
-   grid
-   gap
-   ukuran card

Perubahan harus seminimal mungkin dan hanya untuk menyelesaikan masalah
warna value alarm.

------------------------------------------------------------------------

# Verifikasi wajib

## A. Card hijau + value alarm

Temukan contoh nyata pada halaman yang:

-   card berwarna hijau / RUNNING
-   value melewati threshold
-   value memiliki `.value-alarm`

Periksa computed style.

Expected:

``` text
color:
rgb(239, 68, 68)

animation-name:
blink-alarm

animation-duration:
1.2s
```

Value harus benar-benar terlihat **merah**, bukan orange.

------------------------------------------------------------------------

## B. Card merah + OFF + value alarm

Temukan contoh nyata:

-   card merah
-   status OFF
-   value melewati threshold
-   value memiliki `.value-alarm`

Expected:

``` text
color:
rgb(0, 0, 0)

animation-name:
blink-alarm

animation-duration:
1.2s
```

------------------------------------------------------------------------

## C. Value normal

Pastikan value yang tidak melewati threshold:

``` text
hasAlarmClass:
false

animation-name:
none
```

Dan warna normal tidak berubah.

------------------------------------------------------------------------

## D. Pastikan threshold tetap bekerja

Pastikan alarm tetap berasal dari:

``` text
threshold_direct
threshold_by_kw
unit_motor_kw
```

Jangan mengubah nilai threshold atau logic lookup.

------------------------------------------------------------------------

## E. Regression check

Pastikan tidak ada perubahan pada:

-   PHP/API
-   threshold logic
-   parsing CT/Chiller
-   generic error
-   display_errors
-   card geometry
-   AHU typography
-   status card

------------------------------------------------------------------------

## F. Diff

Setelah selesai:

``` bash
git diff --stat
git diff
```

Tampilkan diff untuk review manusia.

**JANGAN COMMIT.**

Test scaffolding yang dibuat selama verifikasi harus dibersihkan sebelum
selesai.

## Output akhir

Laporkan:

1.  Penyebab warna orange.
2.  File yang diubah.
3.  Perubahan yang dilakukan.
4.  Hasil computed style card hijau + alarm.
5.  Hasil computed style card merah + OFF + alarm.
6.  Hasil value normal.
7.  Konfirmasi tidak ada PHP/API/threshold/parsing logic yang berubah.
8.  `git diff --stat`.
9.  Konfirmasi bahwa **belum commit**.
