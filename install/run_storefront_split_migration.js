#!/usr/bin/env node
/**
 * Standalone Node.js migration runner
 * Idempotent version of install/run_storefront_split_migration.php
 *
 * Usage:
 *   node install/run_storefront_split_migration.js [DB_URL]
 *
 * ถ้าไม่ pass argument จะใช้ env var DB_URL หรือ DATABASE_URL
 */

const mysql = require('mysql2/promise');

const DB_URL =
    process.argv[2]
    || process.env.DB_URL
    || process.env.DATABASE_URL;

if (!DB_URL) {
    console.error('❌ DB_URL required. Pass as first arg or set DB_URL env var.');
    console.error('   Example: node install/run_storefront_split_migration.js mysql://user:pass@host:3306/db');
    process.exit(1);
}

const c = {
    info: (s) => console.log('\x1b[0m' + s + '\x1b[0m'),
    ok:   (s) => console.log('\x1b[32m' + s + '\x1b[0m'),
    warn: (s) => console.log('\x1b[33m' + s + '\x1b[0m'),
    err:  (s) => console.log('\x1b[31m' + s + '\x1b[0m'),
    skip: (s) => console.log('\x1b[36m' + s + '\x1b[0m'),
};

async function columnExists(conn, table, column) {
    const [rows] = await conn.execute(
        `SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?`,
        [table, column]
    );
    return rows[0].n > 0;
}

async function indexExists(conn, table, indexName) {
    const [rows] = await conn.execute(
        `SELECT COUNT(*) AS n FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?`,
        [table, indexName]
    );
    return rows[0].n > 0;
}

async function tableExists(conn, table) {
    const [rows] = await conn.execute(
        `SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?`,
        [table]
    );
    return rows[0].n > 0;
}

(async () => {
    const startedAt = Date.now();
    const actions = [];
    let conn;

    try {
        conn = await mysql.createConnection({
            uri: DB_URL,
            multipleStatements: false,
            timezone: '+07:00',
        });

        const [[dbInfo]] = await conn.query('SELECT DATABASE() AS db, VERSION() AS ver');
        c.info(`🔗 Connected: DB=${dbInfo.db} VERSION=${dbInfo.ver}`);
        c.info('');

        // ─── Step 1: prerequisite ────────────────────────────────────────────
        c.info('─── Step 1: ตรวจสอบตาราง shop_products ───');
        if (!(await tableExists(conn, 'shop_products'))) {
            c.err('❌ ไม่พบตาราง shop_products — ต้องมีก่อนรัน migration นี้');
            c.info('   ให้เปิด /inventory/?tab=catalog-sync ครั้งเดียวก่อนเพื่อ auto-create');
            process.exit(2);
        }
        c.ok('✓ ตาราง shop_products มีอยู่');
        c.info('');

        // ─── Step 2: add columns ─────────────────────────────────────────────
        c.info('─── Step 2: เพิ่ม column ใน shop_products ───');
        const columnsToAdd = [
            ['storefront_enabled', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=แสดงบนหน้าร้านจริง, 0=ซ่อน' AFTER `is_active`"],
            ['drug_type',          "VARCHAR(64) NULL DEFAULT NULL COMMENT 'OTC/Rx/Controlled/Supplement/Cosmetic/Other' AFTER `category`"],
            ['featured_order',     "INT NULL DEFAULT NULL COMMENT 'ลำดับ pin (NULL=ไม่ pin)' AFTER `storefront_enabled`"],
            ['admin_overrides',    "JSON NULL DEFAULT NULL COMMENT 'admin override ต่อ field — sync ไม่แตะ' AFTER `featured_order`"],
        ];
        for (const [col, def] of columnsToAdd) {
            if (await columnExists(conn, 'shop_products', col)) {
                c.skip(`  ↷ SKIP   column \`${col}\` มีอยู่แล้ว`);
            } else {
                await conn.query(`ALTER TABLE \`shop_products\` ADD COLUMN \`${col}\` ${def}`);
                c.ok(`  ✓ ADDED  column \`${col}\``);
                actions.push(`added column ${col}`);
            }
        }
        c.info('');

        // ─── Step 3: add indexes ─────────────────────────────────────────────
        c.info('─── Step 3: เพิ่ม index ───');
        const indexesToAdd = [
            ['idx_storefront',     '(`line_account_id`, `storefront_enabled`, `is_active`)'],
            ['idx_drug_type',      '(`line_account_id`, `drug_type`)'],
            ['idx_featured_order', '(`line_account_id`, `featured_order`)'],
        ];
        for (const [idx, cols] of indexesToAdd) {
            if (await indexExists(conn, 'shop_products', idx)) {
                c.skip(`  ↷ SKIP   index \`${idx}\` มีอยู่แล้ว`);
            } else {
                await conn.query(`ALTER TABLE \`shop_products\` ADD INDEX \`${idx}\` ${cols}`);
                c.ok(`  ✓ ADDED  index \`${idx}\``);
                actions.push(`added index ${idx}`);
            }
        }
        c.info('');

        // ─── Step 4: drug_type_rules table ──────────────────────────────────
        c.info('─── Step 4: สร้างตาราง drug_type_rules ───');
        if (await tableExists(conn, 'drug_type_rules')) {
            c.skip('  ↷ SKIP   ตาราง drug_type_rules มีอยู่แล้ว');
        } else {
            await conn.query(`
                CREATE TABLE \`drug_type_rules\` (
                    \`id\` INT AUTO_INCREMENT PRIMARY KEY,
                    \`line_account_id\` INT NULL COMMENT 'NULL=ใช้ทุกบัญชี',
                    \`match_type\` ENUM('category', 'name_contains', 'sku_prefix') NOT NULL,
                    \`match_value\` VARCHAR(128) NOT NULL,
                    \`drug_type\` VARCHAR(64) NOT NULL,
                    \`priority\` INT NOT NULL DEFAULT 100 COMMENT 'เลขน้อย=match ก่อน',
                    \`is_active\` TINYINT(1) NOT NULL DEFAULT 1,
                    \`created_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    \`updated_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX \`idx_match\` (\`match_type\`, \`match_value\`),
                    INDEX \`idx_line_priority\` (\`line_account_id\`, \`priority\`, \`is_active\`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='กฎ map drug_type จาก category/name/sku'
            `);
            c.ok('  ✓ CREATED ตาราง drug_type_rules');
            actions.push('created table drug_type_rules');
        }
        c.info('');

        // ─── Step 5: seed default rules ──────────────────────────────────────
        c.info('─── Step 5: Seed default rules ───');
        const [[{ n: existing }]] = await conn.query('SELECT COUNT(*) AS n FROM `drug_type_rules`');
        if (existing > 0) {
            c.skip(`  ↷ SKIP   drug_type_rules มี ${existing} แถวอยู่แล้ว ไม่ seed ซ้ำ`);
        } else {
            const rules = [
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
            for (const [type, val, dt, prio] of rules) {
                await conn.execute(
                    `INSERT INTO \`drug_type_rules\`
                     (line_account_id, match_type, match_value, drug_type, priority, is_active)
                     VALUES (NULL, ?, ?, ?, ?, 1)`,
                    [type, val, dt, prio]
                );
            }
            c.ok(`  ✓ SEEDED ${rules.length} กฎ default (scope=global)`);
            actions.push(`seeded ${rules.length} drug_type_rules`);
        }
        c.info('');

        // ─── Step 6: verify ──────────────────────────────────────────────────
        c.info('─── Step 6: ตรวจสอบ ───');
        const [cols] = await conn.query(
            `SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'shop_products'
               AND COLUMN_NAME IN ('storefront_enabled', 'drug_type', 'featured_order')
             ORDER BY ORDINAL_POSITION`
        );
        for (const col of cols) {
            c.ok(`  ✓ ${col.COLUMN_NAME.padEnd(20)} ${col.COLUMN_TYPE.padEnd(30)} DEFAULT ${col.COLUMN_DEFAULT ?? 'NULL'}`);
        }
        const [[{ n: ruleCount }]] = await conn.query('SELECT COUNT(*) AS n FROM `drug_type_rules`');
        c.ok(`  ✓ drug_type_rules = ${ruleCount} แถว`);

        // ─── Summary ─────────────────────────────────────────────────────────
        const tookMs = Date.now() - startedAt;
        c.info('');
        if (actions.length === 0) {
            c.ok(`✅ Migration เรียบร้อย — ไม่มีอะไรเปลี่ยนแปลง (idempotent, ${tookMs}ms)`);
        } else {
            c.ok(`✅ Migration เสร็จสิ้น (${tookMs}ms) — ทำ ${actions.length} การกระทำ:`);
            for (const a of actions) c.ok(`   • ${a}`);
        }
    } catch (err) {
        c.err('');
        c.err('❌ ERROR: ' + err.message);
        if (err.stack) {
            console.error(err.stack.split('\n').slice(0, 3).join('\n'));
        }
        process.exitCode = 1;
    } finally {
        if (conn) await conn.end();
    }
})();
