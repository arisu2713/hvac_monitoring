-- =====================================================================
-- File: SQL/fix_chiller2_3_alarm_points_remote.sql
-- Purpose: Koreksi mapping titik alarm BACnet CHILLER 2 dan CHILLER 3 pada Remote Hostinger
-- Target DB: Remote MySQL u468140406_hvac (Hostinger)
-- JANGAN JALANKAN SECARA OTOMATIS DARI SKRIP LOKAL!
--
-- Petunjuk Eksekusi Manual di Hostinger:
-- Opsi A: Lewat phpMyAdmin cPanel Hostinger:
--   1. Login ke cPanel Hostinger -> Masuk ke phpMyAdmin.
--   2. Pilih database `u468140406_hvac` di panel kiri.
--   3. Buka tab "SQL".
--   4. Jalankan BLOK A (Pra-Cek). Pastikan hasil A.1 tidak ada alarm berstatus ACTIVE.
--   5. Jalankan BLOK B (Update Points) dan BLOK C (Update alarm_events).
--   6. Jalankan BLOK D (Pasca-Cek) untuk memverifikasi point_name dan riwayat event.
--
-- Opsi B: Lewat SSH / CLI Server Hostinger:
--   mysql -u <remote_user> -p u468140406_hvac < fix_chiller2_3_alarm_points_remote.sql
--
-- Latar Belakang:
-- Instance BI:403 sebelumnya dinamai 'CHILLER 2 Alarm' padahal fisiknya CHILLER 3.
-- Instance BI:405 sebelumnya dinamai 'CHILLER 3 Alarm' padahal fisiknya CHILLER 2.
-- Filter update riwayat hanya menggunakan kombinasi point_id dan equipment
-- tanpa bergantung pada kolom primary key 'id' lokal.
-- =====================================================================

-- ---------------------------------------------------------------------
-- BLOK A: PRA-CEK (VERIFIKASI AWAL PADA DATABASE REMOTE)
-- Pastikan tidak ada alarm ACTIVE pada kedua titik sebelum pertukaran.
-- Bila ada baris ACTIVE, hentikan eksekusi dan verifikasi status chiller.
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

-- A.2. Tampilkan kondisi titik saat ini di tabel points remote
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

-- A.3. Tampilkan baris riwayat alarm_events yang akan dikoreksi
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
-- BLOK B: UPDATE ATOMIK PADA TABEL POINTS REMOTE
-- Tukar point_name antara BI:403 dan BI:405 secara atomik dalam satu query.
-- Catatan: Poller Azbil juga memiliki mekanisme sync points otomatis saat
-- signature berubah, namun skrip ini memastikan update langsung di remote.
-- ---------------------------------------------------------------------

UPDATE points
SET point_name = CASE point_id
    WHEN '0010203BI0000403' THEN 'CHILLER 3 Alarm'
    WHEN '0010203BI0000405' THEN 'CHILLER 2 Alarm'
END
WHERE point_id IN ('0010203BI0000403', '0010203BI0000405');


-- ---------------------------------------------------------------------
-- BLOK C: KOREKSI RIWAYAT ALARM_EVENTS PADA REMOTE
-- Koreksi baris riwayat di mana BI:405 sebelumnya tercatat sebagai CHILLER 3.
-- Ubah equipment menjadi 'CHILLER 2' dan perbarui string deskripsi.
-- Filter tidak bergantung pada ID lokal, aman untuk remote.
-- ---------------------------------------------------------------------

UPDATE alarm_events
SET equipment = 'CHILLER 2',
    description = REPLACE(description, 'CHILLER 3 Alarm', 'CHILLER 2 Alarm')
WHERE point_id = '0010203BI0000405' 
  AND equipment = 'CHILLER 3';


-- ---------------------------------------------------------------------
-- BLOK D: PASCA-CEK (VERIFIKASI HASIL PADA REMOTE)
-- ---------------------------------------------------------------------

-- D.1. Verifikasi tabel points remote setelah update
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

-- D.2. Verifikasi riwayat alarm_events setelah update
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
