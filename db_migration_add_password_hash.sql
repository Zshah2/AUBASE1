-- Run once if your database was created before password_hash existed:
USE aubase;
ALTER TABLE User ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER phone;
