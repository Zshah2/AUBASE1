-- Run once on existing DBs (schema already matches if you loaded db.sql fresh).
USE aubase;

ALTER TABLE `Order`
  ADD COLUMN ship_to_name VARCHAR(150) DEFAULT NULL AFTER tracking_number,
  ADD COLUMN ship_to_line1 VARCHAR(255) DEFAULT NULL AFTER ship_to_name,
  ADD COLUMN ship_to_line2 VARCHAR(255) DEFAULT NULL AFTER ship_to_line1,
  ADD COLUMN ship_to_city VARCHAR(100) DEFAULT NULL AFTER ship_to_line2,
  ADD COLUMN ship_to_region VARCHAR(100) DEFAULT NULL AFTER ship_to_city,
  ADD COLUMN ship_to_postal VARCHAR(32) DEFAULT NULL AFTER ship_to_region,
  ADD COLUMN ship_to_country VARCHAR(100) DEFAULT NULL AFTER ship_to_postal;
