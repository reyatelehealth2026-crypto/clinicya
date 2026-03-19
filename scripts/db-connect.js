#!/usr/bin/env node
/**
 * Database Connection Test
 * ทดสอบการเชื่อมต่อ MySQL โดยตรง
 * 
 * Run: node scripts/db-connect.js
 */

const mysql = require('mysql2/promise');

const DB_CONFIG = {
    host: process.env.DB_HOST || '118.27.146.16',
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USER || 'zrismpsz_cny',
    password: process.env.DB_PASS || 'zrismpsz_cny',
    database: process.env.DB_NAME || 'zrismpsz_cny',
    connectTimeout: 10000,
    acquireTimeout: 10000
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

async function testConnection() {
    log('');
    log('╔═══════════════════════════════════════════════════════════╗', 'cyan');
    log('║     Database Connection Test                              ║', 'cyan');
    log('╚═══════════════════════════════════════════════════════════╝', 'cyan');
    
    log('\n📡 กำลังเชื่อมต่อ...', 'cyan');
    log(`   Host: ${DB_CONFIG.host}:${DB_CONFIG.port}`, 'gray');
    log(`   Database: ${DB_CONFIG.database}`, 'gray');
    log(`   User: ${DB_CONFIG.user}`, 'gray');
    
    let connection;
    
    try {
        connection = await mysql.createConnection(DB_CONFIG);
        
        log('\n✅ เชื่อมต่อสำเร็จ!', 'green');
        
        // Test basic query
        const [rows] = await connection.execute('SELECT 1 as connected, NOW() as server_time');
        log(`   Server Time: ${rows[0].server_time}`, 'blue');
        
        // Get database info
        log('\n📊 Database Info:', 'cyan');
        const [versionRows] = await connection.execute('SELECT VERSION() as version');
        log(`   MySQL Version: ${versionRows[0].version}`, 'blue');
        
        // Check tables
        log('\n📋 ตารางหลัก:', 'cyan');
        const [tables] = await connection.execute(`
            SELECT 
                table_name,
                table_rows,
                ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb
            FROM information_schema.tables 
            WHERE table_schema = ? 
                AND table_name LIKE 'odoo_%'
            ORDER BY (data_length + index_length) DESC
            LIMIT 10
        `, [DB_CONFIG.database]);
        
        console.log('   ' + 'Table'.padEnd(30) + 'Rows'.padEnd(12) + 'Size MB');
        console.log('   ' + '-'.repeat(55));
        
        for (const table of tables) {
            const color = table.table_rows > 1000000 ? 'red' : 
                         table.table_rows > 100000 ? 'yellow' : 'green';
            log(
                '   ' + 
                table.table_name.padEnd(30) + 
                (table.table_rows || 0).toLocaleString().padEnd(12) + 
                table.size_mb,
                color
            );
        }
        
        // Check indexes on webhooks_log
        log('\n🔍 Indexes บน odoo_webhooks_log:', 'cyan');
        const [indexes] = await connection.execute(`
            SELECT 
                index_name,
                GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns
            FROM information_schema.statistics 
            WHERE table_schema = ? AND table_name = 'odoo_webhooks_log'
            GROUP BY index_name
            ORDER BY index_name
        `, [DB_CONFIG.database]);
        
        const newIndexes = indexes.filter(i => i.index_name.startsWith('idx_'));
        if (newIndexes.length > 0) {
            log(`   พบ ${newIndexes.length} custom indexes:`, 'green');
            for (const idx of newIndexes.slice(0, 5)) {
                log(`   - ${idx.index_name}: ${idx.columns}`, 'blue');
            }
            if (newIndexes.length > 5) {
                log(`   ... และอีก ${newIndexes.length - 5} indexes`, 'gray');
            }
        } else {
            log('   ⚠️  ยังไม่มี custom indexes (ต้องรัน migration)', 'yellow');
        }
        
        // Test query performance
        log('\n⏱️  ทดสอบ Query Performance:', 'cyan');
        
        const queries = [
            { name: 'webhooks_today', sql: 'SELECT COUNT(*) as cnt FROM odoo_webhooks_log WHERE created_at >= CURDATE()' },
            { name: 'notification_today', sql: 'SELECT COUNT(*) as cnt FROM odoo_notification_log WHERE sent_at >= CURDATE()' },
            { name: 'pending_slips', sql: "SELECT COUNT(*) as cnt FROM odoo_slip_uploads WHERE status IN ('new','pending')" }
        ];
        
        for (const q of queries) {
            const start = Date.now();
            try {
                const [result] = await connection.execute(q.sql);
                const elapsed = Date.now() - start;
                const color = elapsed < 100 ? 'green' : elapsed < 500 ? 'yellow' : 'red';
                log(`   ${q.name.padEnd(20)} ${elapsed.toString().padStart(4)}ms  (${result[0].cnt} rows)`, color);
            } catch (e) {
                log(`   ${q.name.padEnd(20)} ERROR: ${e.message}`, 'red');
            }
        }
        
        log('\n═══════════════════════════════════════════════════════════', 'cyan');
        log('✅ Connection test สำเร็จ!', 'green');
        log('═══════════════════════════════════════════════════════════', 'cyan');
        
    } catch (error) {
        log('\n❌ เชื่อมต่อไม่สำเร็จ!', 'red');
        log(`   Error: ${error.message}`, 'red');
        
        if (error.code === 'ECONNREFUSED') {
            log('\n💡 แนะนำ:', 'yellow');
            log('   - ตรวจสอบว่า MySQL port 3306 เปิดหรือไม่', 'yellow');
            log('   - ตรวจสอบว่า user มีสิทธิ์ remote access', 'yellow');
            log('   - ลองใช้ host: localhost แทน 118.27.146.16', 'yellow');
        }
        
        if (error.code === 'ER_ACCESS_DENIED_ERROR') {
            log('\n💡 แนะนำ:', 'yellow');
            log('   - ตรวจสอบ username/password', 'yellow');
            log('   - ตรวจสอบว่า user มีสิทธิ์เข้าถึง database', 'yellow');
        }
        
        process.exit(1);
    } finally {
        if (connection) {
            await connection.end();
        }
    }
}

// Check if mysql2 is installed
try {
    require.resolve('mysql2');
    testConnection();
} catch (e) {
    log('❌ ต้องติดตั้ง mysql2 ก่อน:', 'red');
    log('   npm install mysql2', 'yellow');
    process.exit(1);
}
