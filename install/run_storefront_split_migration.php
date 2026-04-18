<?php
/**
 * Run migration: Storefront Split (Two-Page Architecture)
 * Ref: docs/ODOO_PRODUCT_SYNC_PHP.md §12-15
 *
 * เปิด URL นี้ 1 ครั้ง:
 *   https://clinicya.re-ya.com/install/run_storefront_split_migration.php
 *
 * Idempotent — ถ้า column/index/table มีอยู่แล้ว จะ skip
 *
 * สิ่งที่ทำ:
 *   1. ALTER odoo_products_cache — เพิ่ม storefront_enabled, drug_type, featured_order
 *   2. ADD INDEX idx_storefront, idx_drug_type, idx_featured_order
 *   3. CREATE TABLE drug_type_rules + seed 11 default rules (ถ้า table ว่าง)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// รองรับ CLI และ web
$isCli = php_sapi_name() === 'cli';

function h(string $txt, string $type = 'info'): string
{
    global $isCli;
    $colors = [
        'info'    => ['cli' => "\033[0m",    'web' => 'color:#555;'],
        'success' => ['cli' => "\033[32m",  'web' => 'color:#0a6;'],
        'warn'    => ['cli' => "\033[33m",  'web' => 'color:#c80;'],
        'error'   => ['cli' => "\033[31m",  'web' => 'color:#c00;'],
        'skip'    => ['cli' => "\033[36m",  'web' => 'color:#888;'],
    ];
    $c = $colors[$type] ?? $colors['info'];
    if ($isCli) {
        return $c['cli'] . $txt . "\033[0m";
    }
    return "<span style='{$c['web']}'>" . htmlspecialchars($txt) . "</span>";
}

function out(string $line, string $type = 'info'): void
{
    global $isCli;
    if ($isCli) {
        echo h($line, $type) . "\n";
    } else {
        echo "<div style='font-family:monospace;padding:2px 0;'>" . h($line, $type) . "</div>\n";
        @ob_flush();
        @flush();
    }
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

function indexExists(PDO $db, string $table, string $indexName): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND INDEX_NAME   = ?"
    );
    $stmt->execute([$table, $indexName]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

// ─── HTML wrapper ──────────────────────────────────────────────────────────────
if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'>";
    echo "<title>Migration: Storefront Split</title>";
    echo "<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:880px;margin:32px auto;padding:0 16px;background:#f7f7f7;color:#333;}";
    echo "h1{font-size:22px;margin-bottom:4px;}h2{font-size:16px;margin-top:24px;color:#555;}";
    echo ".box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin:12px 0;}";
    echo ".ok{background:#e6f7ee;border-color:#a0d9b4;}.err{background:#fde7e7;border-color:#f3a7a7;}";
    echo ".btn{display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;margin-right:8px;}";
    echo ".btn.secondary{background:#6b7280;}</style></head><body>";
    echo "<h1>🛠 Migration: Storefront Split</h1>";
    echo "<p>Ref: <code>docs/ODOO_PRODUCT_SYNC_PHP.md §12-15</code> | DB: <code>" . htmlspecialchars(DB_NAME) . "</code></p>";
    echo "<div class='box'>";
}

$startedAt = microtime(true);
$actions   = [];
$errors    = [];

try {
    $db = Database::getInstance()->getConnection();

    // ─── 1. Check prerequisite: odoo_products_cache must exist ────────────────
    out('─── Step 1: ตรวจสอบตาราง odoo_products_cache ───', 'info');
    if (!tableExists($db, 'odoo_products_cache')) {
        out('❌ ไม่พบตาราง odoo_products_cache — ต้องมีก่อนรัน migration นี้', 'error');
        out('   ให้เปิดหน้า /inventory/?tab=catalog-sync ครั้งเดียว หรือรัน migration หลักก่อน', 'info');
        $errors[] = 'missing table: odoo_products_cache';
        throw new RuntimeException('Prerequisite missing');
    }
    out('✓ ตาราง odoo_products_cache มีอยู่', 'success');

    // ─── 2. ALTER odoo_products_cache: เพิ่ม column ──────────────────────────
    out('', 'info');
    out('─── Step 2: เพิ่ม column ใน odoo_products_cache ───', 'info');

    $columnsToAdd = [
        'storefront_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=แสดงบนหน้าร้านจริง, 0=ซ่อน' AFTER `is_active`",
        'drug_type'          => "VARCHAR(64) NULL DEFAULT NULL COMMENT 'ชนิดยา: OTC/Rx/Controlled/Supplement/Cosmetic/Other' AFTER `category`",
        'featured_order'     => "INT NULL DEFAULT NULL COMMENT 'ลำดับ pin (NULL=ไม่ pin)' AFTER `storefront_enabled`",
    ];
    foreach ($columnsToAdd as $col => $def) {
        if (columnExists($db, 'odoo_products_cache', $col)) {
            out("  ↷ SKIP   column `{$col}` มีอยู่แล้ว", 'skip');
        } else {
            $db->exec("ALTER TABLE `odoo_products_cache` ADD COLUMN `{$col}` {$def}");
            out("  ✓ ADDED  column `{$col}`", 'success');
            $actions[] = "added column {$col}";
        }
    }

    // ─── 3. ADD INDEX ─────────────────────────────────────────────────────────
    out('', 'info');
    out('─── Step 3: เพิ่ม index ───', 'info');

    $indexesToAdd = [
        'idx_storefront'     => '(`line_account_id`, `storefront_enabled`, `is_active`)',
        'idx_drug_type'      => '(`line_account_id`, `drug_type`)',
        'idx_featured_order' => '(`line_account_id`, `featured_order`)',
    ];
    foreach ($indexesToAdd as $idx => $cols) {
        if (indexExists($db, 'odoo_products_cache', $idx)) {
            out("  ↷ SKIP   index `{$idx}` มีอยู่แล้ว", 'skip');
        } else {
            $db->exec("ALTER TABLE `odoo_products_cache` ADD INDEX `{$idx}` {$cols}");
            out("  ✓ ADDED  index `{$idx}`", 'success');
            $actions[] = "added index {$idx}";
        }
    }

    // ─── 4. CREATE TABLE drug_type_rules ──────────────────────────────────────
    out('', 'info');
    out('─── Step 4: สร้างตาราง drug_type_rules ───', 'info');

    if (tableExists($db, 'drug_type_rules')) {
        out('  ↷ SKIP   ตาราง drug_type_rules มีอยู่แล้ว', 'skip');
    } else {
        $db->exec("
            CREATE TABLE `drug_type_rules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL COMMENT 'NULL=ใช้ทุกบัญชี',
                `match_type` ENUM('category', 'name_contains', 'sku_prefix') NOT NULL,
                `match_value` VARCHAR(128) NOT NULL,
                `drug_type` VARCHAR(64) NOT NULL,
                `priority` INT NOT NULL DEFAULT 100 COMMENT 'เลขน้อย=match ก่อน',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_match` (`match_type`, `match_value`),
                INDEX `idx_line_priority` (`line_account_id`, `priority`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='กฎ map drug_type จาก category/name/sku'
        ");
        out('  ✓ CREATED ตาราง drug_type_rules', 'success');
        $actions[] = 'created table drug_type_rules';
    }

    // ─── 5. Seed default rules ถ้า table ยังว่าง ─────────────────────────────
    out('', 'info');
    out('─── Step 5: Seed default rules ───', 'info');

    $cntStmt = $db->query("SELECT COUNT(*) FROM `drug_type_rules`");
    $existing = (int) $cntStmt->fetchColumn();

    if ($existing > 0) {
        out("  ↷ SKIP   drug_type_rules มี {$existing} แถวอยู่แล้ว ไม่ seed ซ้ำ", 'skip');
    } else {
        $rules = [
            // [match_type, match_value, drug_type, priority]
            ['category',      'ยาแก้ปวด',      'OTC',        10],
            ['category',      'ยาลดไข้',       'OTC',        10],
            ['category',      'ยาแก้แพ้',       'OTC',        10],
            ['category',      'ยาปฏิชีวนะ',    'Rx',         10],
            ['category',      'ยาควบคุมพิเศษ', 'Controlled', 5],
            ['category',      'วิตามิน',       'Supplement', 20],
            ['category',      'อาหารเสริม',    'Supplement', 20],
            ['category',      'เครื่องสำอาง',   'Cosmetic',   30],
            ['name_contains', 'ยาดม',          'OTC',        50],
            ['name_contains', 'ครีมทา',        'Cosmetic',   50],
            ['sku_prefix',    'CTL-',          'Controlled', 5],
            ['sku_prefix',    'RX-',           'Rx',         5],
        ];

        $insertStmt = $db->prepare(
            "INSERT INTO `drug_type_rules`
             (line_account_id, match_type, match_value, drug_type, priority, is_active)
             VALUES (NULL, ?, ?, ?, ?, 1)"
        );
        $seeded = 0;
        foreach ($rules as [$type, $val, $dt, $prio]) {
            $insertStmt->execute([$type, $val, $dt, $prio]);
            $seeded++;
        }
        out("  ✓ SEEDED {$seeded} กฎ default (scope=global)", 'success');
        $actions[] = "seeded {$seeded} drug_type_rules";
    }

    // ─── 6. Verify ────────────────────────────────────────────────────────────
    out('', 'info');
    out('─── Step 6: ตรวจสอบ ───', 'info');

    $checkStmt = $db->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'odoo_products_cache'
           AND COLUMN_NAME IN ('storefront_enabled', 'drug_type', 'featured_order')
         ORDER BY ORDINAL_POSITION"
    );
    $cols = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        out(sprintf(
            '  ✓ %-20s %-30s DEFAULT %s',
            $c['COLUMN_NAME'],
            $c['COLUMN_TYPE'],
            $c['COLUMN_DEFAULT'] ?? 'NULL'
        ), 'success');
    }

    $ruleCountStmt = $db->query("SELECT COUNT(*) AS n FROM `drug_type_rules`");
    $ruleCount = (int) $ruleCountStmt->fetchColumn();
    out("  ✓ drug_type_rules = {$ruleCount} แถว", 'success');

    // ─── Summary ──────────────────────────────────────────────────────────────
    $tookMs = (int) ((microtime(true) - $startedAt) * 1000);
    out('', 'info');
    if (empty($actions)) {
        out("✅ Migration เรียบร้อย — ไม่มีอะไรเปลี่ยนแปลง (idempotent, {$tookMs}ms)", 'success');
    } else {
        out("✅ Migration เสร็จสิ้น ({$tookMs}ms) — ทำ " . count($actions) . " การกระทำ:", 'success');
        foreach ($actions as $a) {
            out("   • {$a}", 'success');
        }
    }
} catch (\Throwable $e) {
    out('', 'info');
    out('❌ ERROR: ' . $e->getMessage(), 'error');
    out('   File: ' . $e->getFile() . ':' . $e->getLine(), 'error');
    error_log('[run_storefront_split_migration] ' . $e->getMessage());
    $errors[] = $e->getMessage();
}

// ─── Footer ────────────────────────────────────────────────────────────────────
if (!$isCli) {
    echo "</div>";
    if (empty($errors)) {
        echo "<div class='box ok'>";
        echo "<h2>✅ พร้อมใช้งาน</h2>";
        echo "<p>เปิดหน้า Inventory เพื่อใช้ 2 tab ใหม่:</p>";
        echo "<a class='btn' href='/inventory/?tab=storefront'>สินค้าหน้าร้าน</a>";
        echo "<a class='btn secondary' href='/inventory/?tab=catalog-sync'>โหลดรายการสินค้าหลัก</a>";
        echo "</div>";
    } else {
        echo "<div class='box err'>";
        echo "<h2>❌ Migration มีข้อผิดพลาด</h2>";
        echo "<ul>";
        foreach ($errors as $e) {
            echo "<li>" . htmlspecialchars($e) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    echo "</body></html>";
}
