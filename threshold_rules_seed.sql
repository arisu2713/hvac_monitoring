-- Auto-generated from threshold_rules_ed3.xlsx

CREATE TABLE IF NOT EXISTS threshold_direct (
    equip_type VARCHAR(50) NOT NULL,
    metric     VARCHAR(50) NOT NULL,
    min_value  FLOAT NULL,
    max_value  FLOAT NULL,
    PRIMARY KEY (equip_type, metric)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS threshold_by_kw (
    motor_kw  FLOAT NOT NULL,
    min_value FLOAT NULL,
    max_value FLOAT NULL,
    PRIMARY KEY (motor_kw)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS unit_motor_kw (
    equip_type VARCHAR(50) NOT NULL,
    unit_name  VARCHAR(50) NOT NULL,
    motor_kw   FLOAT NOT NULL,
    PRIMARY KEY (equip_type, unit_name)
) ENGINE=InnoDB;

-- threshold_direct
INSERT INTO threshold_direct (equip_type, metric, min_value, max_value) VALUES ('AHU', 'temp', NULL, 25) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_direct (equip_type, metric, min_value, max_value) VALUES ('ROOM', 'temp', NULL, 30) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_direct (equip_type, metric, min_value, max_value) VALUES ('ROOM', 'rh', NULL, 65) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_direct (equip_type, metric, min_value, max_value) VALUES ('CHILLER', 'evap_leaving_temp', NULL, 12) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_direct (equip_type, metric, min_value, max_value) VALUES ('CHILLER', 'cond_entering_temp', NULL, 32) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);

-- threshold_by_kw
INSERT INTO threshold_by_kw (motor_kw, min_value, max_value) VALUES (30, NULL, 56) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_by_kw (motor_kw, min_value, max_value) VALUES (37, NULL, 73) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_by_kw (motor_kw, min_value, max_value) VALUES (15, NULL, 30) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);
INSERT INTO threshold_by_kw (motor_kw, min_value, max_value) VALUES (7.5, NULL, 12) ON DUPLICATE KEY UPDATE min_value=VALUES(min_value), max_value=VALUES(max_value);

-- unit_motor_kw
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '1', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '2', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '3', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '4', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '5', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '6', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '7', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '8', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CCP', '9', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '1', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '2', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '3', 30) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '4', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '5', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '6', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '7', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '8', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CHWP', '9', 37) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '1', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '2', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '3', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '4', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '5A', 7.5) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '5B', 7.5) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '6A', 7.5) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '6B', 7.5) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '9', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '10', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '11', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '12', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
INSERT INTO unit_motor_kw (equip_type, unit_name, motor_kw) VALUES ('CT', '13', 15) ON DUPLICATE KEY UPDATE motor_kw=VALUES(motor_kw);
