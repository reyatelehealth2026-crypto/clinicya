#!/usr/bin/env node
/**
 * Database Pre-flight Check Script
 * ตรวจสอบสถานะ database ก่อนรัน migration
 * 
 * Run: node scripts/preflight-check.js [--dry-run]
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const DB_CONFIG = {
    host: 'localhost',
    database: 'zrismpsz_cny',
    user: 'zrismpsz_cny',
    password: 'zrismpsz_cny'
};

const colors = {
    reset: '\x1b[0m',
    red: '\x1b[31m',
    green: '\x1b[32m',
    yellow: '\x1b[33m',
    blue: '\x1b[34m',
    cyan: '\x1b[36m'
};

function log(msg, color = 'reset') {
    console.log(`${colors[color]}${msg}${colors.reset}`);
}

function runSQL(sql) {
    const cmd = `mysql -h ${DB_CONFIG.host} -u ${DB_CONFIG.user} -p${DB_CONFIG.password} ${DB_CONFIG.database} -e "${sql}" 2>/dev/null`;
    try {
        return execSync(cmd, { encoding: 'utf8' });
    } catch (e) {
        return null;
    }
}

function checkMySQLConnection() {
    log('\n📡 ตรวจสอบการเชื่อมต่อ MySQL...', 'cyan');
    const result = runSQL('SELECT 1 as connected');
    if (result && result.includes('connected')) {
        log('✅ เชื่อมต่อ MySQL สำเร็จ', 'green');
        return true;
    }
    log('❌ เชื่อมต่อ MySQL ไม่สำเร็จ', 'red');
    log('   ตรวจสอบ: mysql client ติดตั้งแล้วหรือไม่?', 'yellow');
    return false;
}

function getTableStats() {
    log('\n📊 สถิติตารางหลัก...', 'cyan');
    
    const tables = [
        'odoo_webhooks_log',
        'odoo_notification_log', 
        'odoo_line_users',
        'odoo_slip_uploads',
        'odoo_bdos',
        'odoo_bdo_context',
        'odoo_webhook_dlq',
        'odoo_orders',
        'odoo_invoices',
        'odoo_orders_summary',
        'odoo_customers_cache'
    ];
    
    const stats = {};
    
    console.log('\n' + 'Table'.padEnd(30) + 'Rows'.padEnd(15) + 'Size MB'.padEnd(12) + 'Status');
    console.log('-'.repeat(70));
    
    for (const table of tables) {
        const result = runSQL(`SELECT COUNT(*) as cnt FROM ${table}`);
        if (!result) {
            console.log(table.padEnd(30) + 'N/A'.padEnd(15) + 'N/A'.padEnd(12) + `${colors.red}ไม่พบตาราง${colors.reset}`);
            stats[table] = { exists: false };
            continue;
        }
        
        const count = result.trim().split('\n')[1];
        const sizeResult = runSQL(`SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) as size FROM information_schema.tables WHERE table_schema = '${DB_CONFIG.database}' AND table_name = '${table}'`);
        const size = sizeResult ? sizeResult.trim().split('\n')[1] : '0';
        
        const numCount = parseInt(count);
        const status = numCount > 1_000_000 ? `${colors.red}>1M rows!${colors.reset}` :
                      numCount > 500_000 ? `${colors.yellow}>500K rows${colors.reset}` :
                      `${colors.green}OK${colors.reset}`;
        
        console.log(table.padEnd(30) + parseInt(count).toLocaleString().padEnd(15) + size.padEnd(12) + status);
        stats[table] = { exists: true, rows: numCount, size: parseFloat(size) };
    }
    
    return stats;
}

function getExistingIndexes() {
    log('\n🔍 ตรวจสอบ Index ที่มีอยู่...', 'cyan');
    
    const result = runSQL(`
        SELECT 
            table_name,
            index_name,
            GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns
        FROM information_schema.statistics 
        WHERE table_schema = '${DB_CONFIG.database}'
            AND table_name LIKE 'odoo_%'
        GROUP BY table_name, index_name
        ORDER BY table_name, index_name
    `);
    
    if (!result) {
        log('❌ ไม่สามารถดึงข้อมูล index ได้', 'red');
        return {};
    }
    
    const indexes = {};
    const lines = result.trim().split('\n').slice(1); // Skip header
    
    for (const line of lines) {
        const [table, index, columns] = line.split('\t');
        if (!indexes[table]) indexes[table] = [];
        indexes[table].push({ name: index, columns });
    }
    
    // Display summary
    for (const [table, idxs] of Object.entries(indexes)) {
        if (idxs.length > 1) { // Skip PRIMARY only tables
            console.log(`\n${colors.blue}${table}${colors.reset}:`);
            for (const idx of idxs) {
                const isNew = idx.name.startsWith('idx_') && 
                    ['idx_webhooks', 'idx_notif', 'idx_bdo', 'idx_slips', 'idx_cust', 'idx_orders_sum'].some(p => idx.name.startsWith(p));
                const icon = isNew ? `${colors.green}[NEW]${colors.reset}` : '     ';
                console.log(`  ${icon} ${idx.name.padEnd(40)} ${idx.columns}`);
            }
        }
    }
    
    return indexes;
}

function checkMigrationFiles() {
    log('\n📁 ตรวจสอบ Migration Files...', 'cyan');
    
    const migrations = [
        'database/migration_odoo_api_performance.sql',
        'database/migration_missing_indexes.sql'
    ];
    
    const odooPath = path.join(__dirname, '..');
    
    for (const migration of migrations) {
        const fullPath = path.join(odooPath, migration);
        if (fs.existsSync(fullPath)) {
            const content = fs.readFileSync(fullPath, 'utf8');
            const alterCount = (content.match(/ALTER TABLE/g) || []).length;
            const indexCount = (content.match(/ADD INDEX/g) || []).length;
            log(`✅ ${migration}`, 'green');
            log(`   ${alterCount} ALTER TABLE, ${indexCount} ADD INDEX statements`, 'blue');
        } else {
            log(`❌ ${migration} - ไม่พบไฟล์`, 'red');
        }
    }
}

function estimateMigrationTime(stats) {
    log('\n⏱️ ประมาณการเวลา Migration...', 'cyan');
    
    let totalMinutes = 0;
    
    for (const [table, info] of Object.entries(stats)) {
        if (!info.exists) continue;
        
        // Large tables take longer to add indexes
        if (info.rows > 1_000_000) {
            totalMinutes += 3; // ~3 min per large table
        } else if (info.rows > 100_000) {
            totalMinutes += 1; // ~1 min per medium table
        } else {
            totalMinutes += 0.1; // ~6 sec per small table
        }
    }
    
    log(`ประมาณการ: ${Math.ceil(totalMinutes)} นาที`, 'yellow');
    log('หมายเหตุ: ตาราง >1M rows อาจใช้เวลานานและ lock table', 'yellow');
}

function generateRecommendations(stats, indexes) {
    log('\n💡 คำแนะนำ...', 'cyan');
    
    const recommendations = [];
    
    // Check for missing critical indexes
    const criticalTables = ['odoo_webhooks_log', 'odoo_notification_log', 'odoo_line_users'];
    for (const table of criticalTables) {
        if (!stats[table]?.exists) {
            recommendations.push(`${table}: ❌ ไม่พบตาราง`);
        }
    }
    
    // Check for large tables without indexes
    for (const [table, info] of Object.entries(stats)) {
        if (info.exists && info.rows > 500_000) {
            const tableIndexes = indexes[table] || [];
            const customIndexes = tableIndexes.filter(i => !['PRIMARY', 'UNIQUE'].includes(i.name));
            if (customIndexes.length < 3) {
                recommendations.push(`${table}: ⚠️ มี ${info.rows.toLocaleString()} rows แต่มี index น้อย (${customIndexes.length})`);
            }
        }
    }
    
    if (recommendations.length === 0) {
        log('✅ ไม่พบปัญหา', 'green');
    } else {
        for (const rec of recommendations) {
            console.log(`  • ${rec}`);
        }
    }
}

function main() {
    const dryRun = process.argv.includes('--dry-run');
    
    log('╔═══════════════════════════════════════════════════════════╗', 'cyan');
    log('║     Odoo Database Pre-flight Check                        ║', 'cyan');
    log('╚═══════════════════════════════════════════════════════════╝', 'cyan');
    
    if (!checkMySQLConnection()) {
        process.exit(1);
    }
    
    const stats = getTableStats();
    const indexes = getExistingIndexes();
    checkMigrationFiles();
    estimateMigrationTime(stats);
    generateRecommendations(stats, indexes);
    
    log('\n' + '═'.repeat(60), 'cyan');
    if (dryRun) {
        log('🏁 DRY RUN MODE - ไม่มีการเปลี่ยนแปลงใดๆ', 'yellow');
    } else {
        log('✅ Pre-flight check เสร็จสิ้น', 'green');
        log('\nคำสั่งถัดไป:', 'blue');
        log('  1. node scripts/preflight-check.js --dry-run', 'yellow');
        log('  2. bash scripts/run-migrations.sh (รันจริง)', 'yellow');
    }
    log('═'.repeat(60), 'cyan');
}

main();
