-- CNY Products Cache Table Migration
-- Run this to create the table for CNY Pharmacy product cache

CREATE TABLE IF NOT EXISTS cny_products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sku VARCHAR(100) UNIQUE NOT NULL,
    barcode VARCHAR(100),
    name TEXT,
    name_en TEXT,
    spec_name TEXT,
    description TEXT,
    properties_other TEXT,
    how_to_use TEXT,
    photo_path TEXT,
    qty DECIMAL(10,2) DEFAULT 0,
    qty_incoming DECIMAL(10,2) DEFAULT 0,
    enable CHAR(1) DEFAULT '1',
    product_price JSON,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku (sku),
    INDEX idx_enable (enable),
    INDEX idx_name (name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
