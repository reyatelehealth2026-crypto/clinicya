/**
 * Seed business_categories + auto-assign category_id on business_items for clinicya DB.
 * Idempotent — safe to re-run.
 *
 *   node install/run_seed_categories.cjs
 */
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');

const DB = {
  host: process.env.DB_HOST || '118.27.146.16',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'zrismpsz_clinicya',
  password: process.env.DB_PASSWORD || 'zrismpsz_clinicya',
  database: process.env.DB_NAME || 'zrismpsz_clinicya',
  multipleStatements: true,
};

(async () => {
  const conn = await mysql.createConnection(DB);
  console.log(`>  Connected to ${DB.user}@${DB.host}/${DB.database}`);

  const sqlPath = path.join(__dirname, 'miniapp_seed_categories.sql');
  const sql = fs.readFileSync(sqlPath, 'utf8');

  try {
    const results = await conn.query(sql);
    console.log('✔  Seed executed');

    // Print summary rows from final SELECT
    const finalResult = Array.isArray(results[0]) ? results[0] : [];
    const summary = finalResult.find(r => Array.isArray(r) && r.length && r[0].entity);
    if (summary) {
      console.log('\n— Summary —');
      for (const row of summary) {
        console.log(`  ${String(row.entity).padEnd(30)} = ${row.total}`);
      }
    }
  } catch (err) {
    console.error('✖  Seed failed:', err.message);
    process.exit(1);
  } finally {
    await conn.end();
  }
})();
