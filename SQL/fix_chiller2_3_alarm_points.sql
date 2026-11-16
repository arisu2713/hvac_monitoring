-- =====================================================================
-- File: SQL/fix_chiller2_3_alarm_points.sql
-- Purpose: Koreksi mapping titik alarm BACnet CHILLER 2 dan CHILLER 3
-- Target DB: Local MariaDB hvac_current
-- Execution user: hvac_web
--
-- Latar Belakang:
-- Instance BI:403 sebelumnya dinamai 'CHILLER 2 Alarm' padahal fisiknya CHILLER 3.
-- Instance BI:405 sebelumnya dinamai 'CHILLER 3 Alarm' padahal fisiknya CHILLER 2.
-- Koreksi ini menukar point_name di tabel points dan memperbarui 7 baris
-- riwayat alarm_events yang salah terlabel sebagai CHILLER 3.
-- =====================================================================

-- ---------------------------------------------------------------------
-- BLOK A: PRA-CEK (VERIFIKASI AWAL)
-- Pastikan tidak ada alarm ACTIVE pada kedua titik sebelum pertukaran.
-- Bila ada baris ACTIVE, batalkan eksekusi dan tangani terlebih dahulu.
-- Tampilkan juga kondisi point_name sekarang dan baris riwayat alarm.
-- ---------------------------------------------------------------------

-- A.1. Cek alarm ACTIVE (harus 0 baris / kosong)
SELECT 
    id, 
    equipment, 
    point_id, 
    event_class, 
    status, 
    raised_at, 
    description 
FROM alarm_events 
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405') 
  AND status = 'ACTIVE';

-- A.2. Tampilkan kondisi titik saat ini di tabel points
SELECT 
    point_id, 
    device_id, 
    obj_type, 
    instance, 
    point_name, 
    equip_type 
FROM points 
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405') 
ORDER BY instance ASC;

-- A.3. Tampilkan 7 baris riwayat alarm_events yang akan dikoreksi
SELECT 
    id, 
    equipment, 
    point_id, 
    description, 
    status, 
    raised_at, 
    cleared_at 
FROM alarm_events 
WHERE point_id = '0010203BI0000405' 
  AND equipment = 'CHILLER 3' 
ORDER BY id ASC;


-- ---------------------------------------------------------------------
-- BLOK B: UPDATE ATOMIK PADA TABEL POINTS
-- Tukar point_name antara BI:403 dan BI:405 secara atomik dalam satu query.
-- Kolom lain (device_id, obj_type, instance, equip_type) tidak berubah.
-- ---------------------------------------------------------------------

UPDATE points
SET point_name = CASE point_id
    WHEN '0010203BI0000403' THEN 'CHILLER 3 Alarm'
    WHEN '0010203BI0000405' THEN 'CHILLER 2 Alarm'
END
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405');


-- ---------------------------------------------------------------------
-- BLOK C: KOREKSI RIWAYAT ALARM_EVENTS YANG SALAH LABEL
-- Perbarui baris riwayat di mana BI:405 sebelumnya terlabel 'CHILLER 3'.
-- Ubah equipment menjadi 'CHILLER 2' dan perbarui deskripsi teks alarm.
-- ---------------------------------------------------------------------

UPDATE alarm_events
SET equipment = 'CHILLER 2',
    description = REPLACE(description, 'CHILLER 3 Alarm', 'CHILLER 2 Alarm')
WHERE point_id = '0010203BI0000405' 
  AND equipment = 'CHILLER 3';


-- ---------------------------------------------------------------------
-- BLOK D: PASCA-CEK (VERIFIKASI HASIL)
-- Pastikan titik di tabel points sudah tertukar sesuai fisik,
-- dan riwayat alarm_events sudah menjadi CHILLER 2.
-- ---------------------------------------------------------------------

-- D.1. Verifikasi tabel points setelah update
SELECT 
    point_id, 
    device_id, 
    obj_type, 
    instance, 
    point_name, 
    equip_type 
FROM points 
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405') 
ORDER BY instance ASC;

-- D.2. Verifikasi seluruh baris riwayat alarm_events pada kedua titik
SELECT 
    id, 
    equipment, 
    point_id, 
    description, 
    status, 
    raised_at, 
    cleared_at 
FROM alarm_events 
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405') 
ORDER BY id ASC;
