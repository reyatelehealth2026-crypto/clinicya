-- =============================================
-- Landing Banners & Featured Products Migration
-- Version: 1.0
-- Description: Creates tables for Banner Slider and Featured Products
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- LANDING BANNERS TABLE (แบนเนอร์/โปสเตอร์สไลด์)
-- =============================================
CREATE TABLE IF NOT EXISTS `landing_banners` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `title` VARCHAR(255) NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `link_url` VARCHAR(500) NULL,
    `link_type` ENUM('none', 'internal', 'external') DEFAULT 'none',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_banner_account` (`line_account_id`),
    INDEX `idx_banner_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- LANDING FEATURED PRODUCTS TABLE (สินค้าแนะนำที่เลือกจากหลังบ้าน)
-- =============================================
CREATE TABLE IF NOT EXISTS `landing_featured_products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `product_id` INT NOT NULL,
    `product_source` VARCHAR(50) DEFAULT 'products' COMMENT 'products, business_items, cny_products',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_featured_account` (`line_account_id`),
    INDEX `idx_featured_product` (`product_id`),
    INDEX `idx_featured_active` (`is_active`, `sort_order`),
    UNIQUE KEY `uk_featured_product` (`line_account_id`, `product_id`, `product_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
