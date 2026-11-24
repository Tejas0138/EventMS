-- ==========================================================
-- Event Gate Visitors Management System (EventMS)
-- Database Schema & Pre-seeded Dataset
-- Compatible with MySQL 5.7+ / MySQL 8.x / MariaDB 10.3+
-- ==========================================================

CREATE DATABASE IF NOT EXISTS `eventms_db` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `eventms_db`;

-- (admins table preserved for RBAC authentication)

-- ==========================================================
-- 1. Allowed Visitors Table (2-Day Event Gate Tracking)
-- ==========================================================
CREATE TABLE IF NOT EXISTS `allowed_visitors` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `mobile` VARCHAR(20) NOT NULL DEFAULT '',
    `user_id` VARCHAR(50) NULL,
    `region` VARCHAR(100) NULL,
    `fincert` VARCHAR(100) NULL,
    `school_name` VARCHAR(255) NULL,
    `designation` VARCHAR(100) NULL,
    `email` VARCHAR(150) NULL,
    `is_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
    `checked_in_at` DATETIME NULL DEFAULT NULL,
    `is_day1_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
    `day1_checked_in_at` DATETIME NULL DEFAULT NULL,
    `is_day2_checked_in` TINYINT(1) NOT NULL DEFAULT 0,
    `day2_checked_in_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_id` (`user_id`),
    KEY `idx_checkin_status` (`is_checked_in`),
    KEY `idx_day1_status` (`is_day1_checked_in`),
    KEY `idx_day2_status` (`is_day2_checked_in`),
    KEY `idx_mobile` (`mobile`),
    KEY `idx_region` (`region`),
    KEY `idx_fincert` (`fincert`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================
-- 2. Gift Distribution System Schema
-- ==========================================================
CREATE TABLE IF NOT EXISTS `gift_distribution` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `mobile` VARCHAR(20) NOT NULL DEFAULT '',
    `user_id` VARCHAR(50) NULL,
    `region` VARCHAR(100) NULL,
    `fincert` VARCHAR(100) NULL,
    `school_name` VARCHAR(255) NULL,
    `designation` VARCHAR(100) NULL,
    `email` VARCHAR(150) NULL,
    `handed_over_by` VARCHAR(50) NULL,
    `counter_region` VARCHAR(100) NULL,
    `is_gift_collected` TINYINT(1) NOT NULL DEFAULT 0,
    `gift_collected_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_gift_user_id` (`user_id`),
    KEY `idx_gift_status` (`is_gift_collected`),
    KEY `idx_gift_mobile` (`mobile`),
    KEY `idx_gift_region` (`region`),
    KEY `idx_gift_fincert` (`fincert`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================================================
-- RBAC: Admins Table & Role Management
-- ==========================================================
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` VARCHAR(20) NOT NULL DEFAULT 'guard',
    `region` VARCHAR(100) NULL DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `last_login` DATETIME NULL DEFAULT NULL,
    `failed_attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Add columns if admins table was previously created without them
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `role` VARCHAR(20) NOT NULL DEFAULT 'guard';
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `region` VARCHAR(100) NULL DEFAULT NULL;
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'active';
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `last_login` DATETIME NULL DEFAULT NULL;
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `failed_attempts` INT NOT NULL DEFAULT 0;
ALTER TABLE `admins` ADD COLUMN IF NOT EXISTS `locked_until` DATETIME NULL DEFAULT NULL;

-- Seed default Super Admin account: username=admin, password=admin123
INSERT INTO `admins` (`username`, `password`, `role`)
VALUES (
    'admin',
    '$2y$10$plS8Hj6QnUymChrARsKItOsBx5VM6CXfwQ4/98f3ccUaXawawCDC6', -- password: admin123
    'super_admin'
)
ON DUPLICATE KEY UPDATE 
    `role` = 'super_admin';

-- Seed dedicated Guard account: username=guard1, password=guard123
INSERT INTO `admins` (`username`, `password`, `role`)
VALUES (
    'guard1',
    '$2y$10$K2SXWX8R2KkvpxkMmKqOlOmxu4drXlMqMS5hsGb.VdVwPs8TS/FDC', -- password: guard123
    'guard'
)
ON DUPLICATE KEY UPDATE 
    `role` = 'guard';

-- ==========================================================
-- Ensure uniform collation across all tables (prevent Illegal mix of collations on UNION/JOIN)
-- ==========================================================
ALTER TABLE `allowed_visitors` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `gift_distribution` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `admins` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


