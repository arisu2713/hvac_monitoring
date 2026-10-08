USE hvac_current;

SELECT VERSION() AS server_version, DATABASE() AS current_db, @@time_zone AS session_tz, NOW(6) AS now6;
SELECT COUNT(*) AS rows_before FROM alarm_events;
SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='hvac_current' AND TABLE_NAME='alarm_events';
SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='hvac_current' AND TABLE_NAME='alarm_events' AND INDEX_NAME='uq_alarm_active';

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('WARNING','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','TEMP','PHASE1 TEST - AHU 65 supply temperature 28.4 C above limit 25 C','ACTIVE','THRESHOLD_AI:TEST_POINT_0001:TEMP:max',28.4,NULL,25,'2026-10-04 10:00:00.123456',NULL);
SELECT id,event_class,status,active_key,value,limit_min,limit_max,raised_at,cleared_at FROM alarm_events WHERE source='PHASE1_TEST';

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('WARNING','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','TEMP','PHASE1 TEST - duplicate probe (must be rejected)','ACTIVE','THRESHOLD_AI:TEST_POINT_0001:TEMP:max',28.5,NULL,25,'2026-10-04 10:01:00.000000',NULL);
SELECT COUNT(*) AS rows_after_dup_attempt FROM alarm_events WHERE source='PHASE1_TEST';

UPDATE alarm_events SET status='CLEARED',cleared_at='2026-10-04 10:15:30.654321',active_key=NULL WHERE active_key='THRESHOLD_AI:TEST_POINT_0001:TEMP:max' AND source='PHASE1_TEST';
SELECT id,status,active_key,raised_at,cleared_at,TIMESTAMPDIFF(SECOND,raised_at,cleared_at) AS duration_s FROM alarm_events WHERE source='PHASE1_TEST';

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('WARNING','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','TEMP','PHASE1 TEST - AHU 65 supply temperature 26.1 C above limit 25 C (recurrence)','ACTIVE','THRESHOLD_AI:TEST_POINT_0001:TEMP:max',26.1,NULL,25,'2026-10-04 11:00:00.000000',NULL);
SELECT id,status,active_key,value,raised_at,cleared_at FROM alarm_events WHERE source='PHASE1_TEST' ORDER BY id;

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('WARNING','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','TEMP','PHASE1 TEST - invalid: ACTIVE with NULL active_key','ACTIVE',NULL,1,NULL,1,'2026-10-04 12:00:00.000000',NULL);

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('WARNING','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','TEMP','PHASE1 TEST - invalid: CLEARED with non-NULL active_key','CLEARED','PHASE1_TEST_INVALID_KEY',1,NULL,1,'2026-10-04 12:00:00.000000','2026-10-04 12:05:00.000000');
SELECT COUNT(*) AS rows_after_check_tests FROM alarm_events WHERE source='PHASE1_TEST';

INSERT INTO alarm_events
(event_class,source,equip_type,equipment,point_id,metric,description,status,active_key,value,limit_min,limit_max,raised_at,cleared_at)
VALUES
('ALARM','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','STATUS','PHASE1 TEST - historical cleared row A (NULL active_key)','CLEARED',NULL,NULL,NULL,NULL,'2026-10-04 09:00:00.000000','2026-10-04 09:10:00.000000'),
('ALARM','PHASE1_TEST','AHU','AHU 65 TEST','TEST_POINT_0001','STATUS','PHASE1 TEST - historical cleared row B (NULL active_key)','CLEARED',NULL,NULL,NULL,NULL,'2026-10-04 09:20:00.000000','2026-10-04 09:30:00.000000');
SELECT COUNT(*) AS null_active_key_rows FROM alarm_events WHERE source='PHASE1_TEST' AND active_key IS NULL;

SELECT COUNT(*) AS test_rows_to_delete FROM alarm_events WHERE source='PHASE1_TEST' AND point_id='TEST_POINT_0001';
DELETE FROM alarm_events WHERE source='PHASE1_TEST' AND point_id='TEST_POINT_0001';
SELECT COUNT(*) AS test_rows_left FROM alarm_events WHERE source='PHASE1_TEST';
SELECT COUNT(*) AS rows_after FROM alarm_events;
