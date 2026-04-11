<?php
/**
 * Migration: Mini App Home Content Tables
 * Creates: miniapp_banners, miniapp_home_sections, miniapp_home_products
 * 
 * Run once to set up the flexible home page content system for LINE Mini App.
 */

define('ADMIN_BASE_PATH', dirname(__DIR__) . '/');
require_once ADMIN_BASE_PATH . 'config/config.php';
require_once ADMIN_BASE_PATH . 'config/database.php';

$db = Database::getInstance()->getConnection();

$results = [];

// =====================================================
// 1. miniapp_banners — แบนเนอร์สไลด์
// =====================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS miniapp_banners (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) COMMENT 'ชื่อแบนเนอร์',
        subtitle VARCHAR(500) COMMENT 'คำบรรยาย',
        description TEXT COMMENT 'รายละเอียดเพิ่มเติม',
        
        -- รูปภาพ
        image_url VARCHAR(500) NOT NULL COMMENT 'รูปภาพ Desktop/Tablet',
        image_mobile_url VARCHAR(500) COMMENT 'รูปภาพ Mobile (optional)',
        
        -- Universal Link
        link_type ENUM('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none' COMMENT 'ประเภทลิ้งค์',
        link_value VARCHAR(500) COMMENT 'URL / route / LIFF ID / deep link scheme',
        link_label VARCHAR(100) COMMENT 'CTA text เช่น ดูเพิ่มเติม',
        
        -- การแสดงผล
        position ENUM('home_top','home_middle','home_bottom') DEFAULT 'home_top' COMMENT 'ตำแหน่ง',
        display_order INT DEFAULT 0 COMMENT 'ลำดับ',
        is_active TINYINT(1) DEFAULT 1 COMMENT 'เปิด/ปิดการแสดงผล',
        bg_color VARCHAR(20) DEFAULT NULL COMMENT 'สีพื้นหลัง (optional)',
        
        -- กำหนดช่วงเวลาแสดง
        start_date DATETIME DEFAULT NULL COMMENT 'เริ่มแสดง',
        end_date DATETIME DEFAULT NULL COMMENT 'หยุดแสดง',
        
        -- LINE Account
        line_account_id INT DEFAULT NULL COMMENT 'รหัส LINE Account (NULL = ทุก account)',
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_position (position),
        INDEX idx_active (is_active),
        INDEX idx_order (display_order),
        INDEX idx_dates (start_date, end_date),
        INDEX idx_line_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='แบนเนอร์สไลด์ Mini App'");
    $results[] = '✅ miniapp_banners — created';
} catch (PDOException $e) {
    $results[] = '❌ miniapp_banners — ' . $e->getMessage();
}

// =====================================================
// 2. miniapp_home_sections — กลุ่มสินค้า/โปรโมชั่น
// =====================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS miniapp_home_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_key VARCHAR(50) UNIQUE NOT NULL COMMENT 'key ไม่ซ้ำ เช่น flash_sale_apr, recommended',
        title VARCHAR(200) NOT NULL COMMENT 'ชื่อ section เช่น GOLD CONTAINER 24 ชั่วโมงเท่านั้น!',
        subtitle VARCHAR(500) COMMENT 'คำบรรยายย่อย',
        
        -- รูปแบบ
        section_style ENUM('flash_sale','horizontal_scroll','grid','banner_list') DEFAULT 'horizontal_scroll' COMMENT 'รูปแบบการแสดงผล',
        bg_color VARCHAR(20) DEFAULT NULL COMMENT 'สีพื้นหลัง section',
        text_color VARCHAR(20) DEFAULT NULL COMMENT 'สีตัวอักษร',
        icon_url VARCHAR(500) DEFAULT NULL COMMENT 'icon/logo ของ section',
        
        -- Flash Sale
        countdown_ends_at DATETIME DEFAULT NULL COMMENT 'นับถอยหลังถึง (flash_sale only)',
        
        -- การแสดงผล
        display_order INT DEFAULT 0 COMMENT 'ลำดับ',
        is_active TINYINT(1) DEFAULT 1 COMMENT 'เปิด/ปิด',
        
        -- กำหนดช่วงเวลาแสดง
        start_date DATETIME DEFAULT NULL,
        end_date DATETIME DEFAULT NULL,
        
        -- LINE Account
        line_account_id INT DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_style (section_style),
        INDEX idx_active (is_active),
        INDEX idx_order (display_order),
        INDEX idx_dates (start_date, end_date),
        INDEX idx_line_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กลุ่มสินค้า/โปรโมชั่น Home Mini App'");
    $results[] = '✅ miniapp_home_sections — created';
} catch (PDOException $e) {
    $results[] = '❌ miniapp_home_sections — ' . $e->getMessage();
}

// =====================================================
// 3. miniapp_home_products — สินค้าในแต่ละ section
// =====================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS miniapp_home_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id INT NOT NULL COMMENT 'FK → miniapp_home_sections.id',
        
        -- ข้อมูลสินค้า
        title VARCHAR(500) NOT NULL COMMENT 'ชื่อสินค้า',
        short_description VARCHAR(500) COMMENT 'รายละเอียดสั้น เช่น 1 กก. x 5',
        
        -- รูปภาพ
        image_url VARCHAR(500) NOT NULL COMMENT 'รูปภาพหลัก',
        image_gallery JSON COMMENT 'แกลเลอรี่รูปภาพ [\"url1\",\"url2\"]',
        
        -- ราคา
        original_price DECIMAL(12,2) DEFAULT NULL COMMENT 'ราคาเดิม',
        sale_price DECIMAL(12,2) DEFAULT NULL COMMENT 'ราคาลด',
        discount_percent DECIMAL(5,2) DEFAULT NULL COMMENT 'เปอร์เซ็นต์ลด (auto-calc or manual)',
        price_unit VARCHAR(50) DEFAULT NULL COMMENT 'หน่วยราคา เช่น /กก., /แพ็ค',
        
        -- รายละเอียดโปรโมชั่น
        promotion_tags JSON COMMENT 'แท็กโปร [\"ซื้อ 999฿ รับส่วนลด 10฿\", \"ซื้อ 499฿ ได้รับ 40 พอยท์\"]',
        promotion_label VARCHAR(100) COMMENT 'ป้ายโปร เช่น 24 ชม.',
        badges JSON COMMENT 'badges [{\"text\":\"3+ units\",\"color\":\"orange\"}]',
        custom_label VARCHAR(200) COMMENT 'ข้อความเพิ่มเติม',
        
        -- สต็อก
        stock_qty INT DEFAULT NULL COMMENT 'จำนวนสต็อก',
        limit_qty INT DEFAULT NULL COMMENT 'จำนวนจำกัดต่อคน',
        show_stock_badge TINYINT(1) DEFAULT 0 COMMENT 'แสดง badge จำนวนจำกัด',
        
        -- ข้อมูลจัดส่ง
        delivery_options JSON COMMENT 'ตัวเลือกจัดส่ง [\"สั่งเช้า ส่งเย็น\", \"จำนวนจำกัด\"]',
        
        -- Universal Link
        link_type ENUM('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none' COMMENT 'ประเภทลิ้งค์',
        link_value VARCHAR(500) COMMENT 'URL / route / LIFF ID / deep link scheme',
        
        -- การแสดงผล
        display_order INT DEFAULT 0 COMMENT 'ลำดับ',
        is_active TINYINT(1) DEFAULT 1 COMMENT 'เปิด/ปิด',
        
        -- กำหนดช่วงเวลาแสดง
        start_date DATETIME DEFAULT NULL,
        end_date DATETIME DEFAULT NULL,
        
        -- LINE Account
        line_account_id INT DEFAULT NULL,
        
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        INDEX idx_section (section_id),
        INDEX idx_active (is_active),
        INDEX idx_order (display_order),
        INDEX idx_dates (start_date, end_date),
        INDEX idx_line_account (line_account_id),
        FOREIGN KEY (section_id) REFERENCES miniapp_home_sections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สินค้าใน section Home Mini App'");
    $results[] = '✅ miniapp_home_products — created';
} catch (PDOException $e) {
    $results[] = '❌ miniapp_home_products — ' . $e->getMessage();
}

// =====================================================
// Insert Sample Data
// =====================================================
try {
    // Sample banners
    $check = $db->query("SELECT COUNT(*) FROM miniapp_banners")->fetchColumn();
    if ($check == 0) {
        $db->exec("INSERT INTO miniapp_banners (title, subtitle, image_url, link_type, link_value, position, display_order, is_active) VALUES
            ('สงกรานต์สาดความคุ้ม', 'ลดสูงสุด 60% + รับเพิ่มสูงสุด x4 PRO POINT', '/img/summer.png', 'url', 'https://cny.re-ya.com/shop', 'home_top', 1, 1),
            ('สมาชิกรับสิทธิพิเศษ', 'สะสมคะแนนแลกส่วนลด', '/img/summer.png', 'miniapp', '/rewards', 'home_top', 2, 1)
        ");
        $results[] = '✅ Sample banners inserted';
    }

    // Sample section
    $check = $db->query("SELECT COUNT(*) FROM miniapp_home_sections")->fetchColumn();
    if ($check == 0) {
        $db->exec("INSERT INTO miniapp_home_sections (section_key, title, subtitle, section_style, bg_color, text_color, countdown_ends_at, display_order, is_active) VALUES
            ('flash_sale_demo', 'GOLD CONTAINER', '24 ชั่วโมงเท่านั้น!', 'flash_sale', '#8B0000', '#FFFFFF', DATE_ADD(NOW(), INTERVAL 24 HOUR), 1, 1),
            ('recommended', 'สินค้าแนะนำ', 'คัดสรรมาเพื่อคุณ', 'horizontal_scroll', NULL, NULL, NULL, 2, 1)
        ");

        // Sample products for flash sale
        $sectionId = $db->query("SELECT id FROM miniapp_home_sections WHERE section_key = 'flash_sale_demo'")->fetchColumn();
        if ($sectionId) {
            $db->exec("INSERT INTO miniapp_home_products (section_id, title, short_description, image_url, original_price, sale_price, discount_percent, promotion_tags, promotion_label, badges, delivery_options, link_type, link_value, display_order, is_active) VALUES
                ({$sectionId}, 'เบญจรงค์ ข้าวหอม 100% 1 กก. x 5', '1 กก. x 5', '/img/summer.png', 175, 132, 24, '[\"ซื้อ 999฿ รับส่วนลด 10฿\"]', '24 ชม.', '[{\"text\":\"+2\",\"color\":\"red\"}]', '[\"สั่งเช้า ส่งเย็น\", \"จำนวนจำกัด\"]', 'url', 'https://cny.re-ya.com/shop/product/1', 1, 1),
                ({$sectionId}, 'คาร์เนชัน เอ็กซ์ตร้า ครีมเทียมพร่องไขมัน', '1 ล. x 12', '/img/summer.png', 723, 720, 0, '[\"ซื้อ 499฿ ได้รับ 40 พอยท์\"]', '24 ชม.', '[{\"text\":\"3+ units\",\"color\":\"orange\"}]', '[\"สั่งเช้า ส่งเย็น\"]', 'url', 'https://cny.re-ya.com/shop/product/2', 2, 1),
                ({$sectionId}, 'เบสท์ฟู้ดส์ สปาเกตตี้ 1 กก.', '1 กก.', '/img/summer.png', 125, 89, 28, '[\"ซื้อ 499฿ ได้รับ 40 พอยท์\"]', '24 ชม.', '[]', '[\"สั่งเช้า ส่งเย็น\", \"จำนวนจำกัด\"]', 'none', '', 3, 1)
            ");
        }

        // Sample products for recommended
        $sectionId2 = $db->query("SELECT id FROM miniapp_home_sections WHERE section_key = 'recommended'")->fetchColumn();
        if ($sectionId2) {
            $db->exec("INSERT INTO miniapp_home_products (section_id, title, short_description, image_url, original_price, sale_price, discount_percent, link_type, link_value, display_order, is_active) VALUES
                ({$sectionId2}, 'วิตามินซี 1000mg', 'กระปุก 60 เม็ด', '/img/summer.png', 590, 490, 17, 'url', 'https://cny.re-ya.com/shop/product/10', 1, 1),
                ({$sectionId2}, 'เจลล้างมือ แอลกอฮอล์ 70%', '500ml', '/img/summer.png', 199, 149, 25, 'miniapp', '/orders', 2, 1)
            ");
        }

        $results[] = '✅ Sample sections + products inserted';
    }
} catch (PDOException $e) {
    $results[] = '⚠️ Sample data — ' . $e->getMessage();
}

// Output results
echo "<h2>Mini App Home Content Migration</h2>";
echo "<pre>" . implode("\n", $results) . "</pre>";
echo "<p>Done at " . date('c') . "</p>";
