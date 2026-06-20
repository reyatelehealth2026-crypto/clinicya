<?php
/**
 * provision_beta_batch4.php — Provision beta signups #16/17/19/20 → tenants
 * t_0014..0017. Unlike batch1/3 this also CREATES the tenant DB (cPanel uapi) +
 * applies the tenant template schema when the DB does not yet exist, via
 * TenantProvisioning::create/grant/applySchema. Fully idempotent.
 *
 * Owner login: username = subdomain, password = phone (bcrypt).
 * Writes preferred_subdomain back to beta_signups when it was empty.
 *
 * Usage:  php provision_beta_batch4.php           (dry-run preview)
 *         php provision_beta_batch4.php --apply
 */
declare(strict_types=1);
@set_time_limit(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';

$APPLY = in_array('--apply', $argv ?? [], true);

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
];
$platform = new PDO('mysql:host=' . DB_HOST . ';dbname=zrismpsz_reya_platform;charset=utf8mb4', DB_USER, DB_PASS, $opts);

// signup_id, subdomain, phone, tenant_id, db_name
$shops = [
    ['signup' => 16, 'slug' => 'sangthong',        'phone' => '0818099262', 'tid' => 14, 'db' => 'zrismpsz_reya_t_0014'],
    ['signup' => 17, 'slug' => 'huathalay',        'phone' => '0946982915', 'tid' => 15, 'db' => 'zrismpsz_reya_t_0015'],
    ['signup' => 19, 'slug' => 'chaengwattana-rx', 'phone' => '0611596519', 'tid' => 16, 'db' => 'zrismpsz_reya_t_0016'],
    ['signup' => 20, 'slug' => 'siampharma',       'phone' => '0929946669', 'tid' => 17, 'db' => 'zrismpsz_reya_t_0017'],
];

echo ($APPLY ? "=== APPLY MODE ===" : "=== DRY RUN (no writes) ===") . PHP_EOL . PHP_EOL;

// Guard: refuse if any chosen slug already belongs to a DIFFERENT tenant id.
$slugStmt = $platform->prepare('SELECT id FROM tenants WHERE slug = ? AND id <> ? LIMIT 1');
foreach ($shops as $s) {
    $slugStmt->execute([$s['slug'], $s['tid']]);
    if ($clash = $slugStmt->fetchColumn()) {
        fwrite(STDERR, "FATAL: slug '{$s['slug']}' already used by tenant #{$clash}. Aborting.\n");
        exit(1);
    }
}

foreach ($shops as $s) {
    echo "── {$s['slug']} (signup #{$s['signup']} → tenant {$s['tid']} / {$s['db']}) ──" . PHP_EOL;

    $sig = $platform->prepare('SELECT full_name, business_name FROM beta_signups WHERE id = ?');
    $sig->execute([$s['signup']]);
    $row = $sig->fetch();
    if (!$row) { echo "  !! signup not found — skip\n\n"; continue; }
    $bizName   = trim((string) $row['business_name']) ?: $s['slug'];
    $ownerName = trim((string) $row['full_name']) ?: $s['slug'];
    $phone     = $s['phone'];

    $dbExists = TenantProvisioning::exists($s['db']);
    echo "  ร้าน: {$bizName} / เจ้าของ: {$ownerName} / โทร: {$phone}\n";
    echo "  login → user: {$s['slug']}  pass: {$phone}\n";
    echo "  DB: " . ($dbExists ? "มีอยู่แล้ว" : "ยังไม่มี → จะสร้าง (uapi) + ลง schema") . "\n";

    if (!$APPLY) { echo "  (dry-run — no write)\n\n"; continue; }

    // 0) create the tenant DB + grant + schema when missing
    if (!$dbExists) {
        TenantProvisioning::create($s['tid']);
        echo "  ✓ created DB {$s['db']} (uapi)\n";
        TenantProvisioning::grant($s['db'], DB_USER);
        echo '  ✓ granted ' . DB_USER . " on {$s['db']}\n";
        TenantProvisioning::applySchema($s['db']);
        echo "  ✓ applied tenant template schema\n";
    }

    // write recommended subdomain back to signup when empty
    $platform->prepare(
        'UPDATE beta_signups SET preferred_subdomain = ? WHERE id = ? AND (preferred_subdomain IS NULL OR preferred_subdomain = "")'
    )->execute([$s['slug'], $s['signup']]);

    // 1) tenants row (idempotent upsert, status active)
    $platform->prepare(
        'INSERT INTO tenants (id, slug, display_name, db_name, db_host, plan_id, status, owner_name, owner_phone, created_at)
         VALUES (?, ?, ?, ?, "localhost", 1, "active", ?, ?, NOW())
         ON DUPLICATE KEY UPDATE slug=VALUES(slug), display_name=VALUES(display_name),
            db_name=VALUES(db_name), status="active", owner_name=VALUES(owner_name), owner_phone=VALUES(owner_phone)'
    )->execute([$s['tid'], $s['slug'], $bizName, $s['db'], $ownerName, $phone]);
    echo "  ✓ tenants row\n";

    // 2) connect to tenant DB
    $tdb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $s['db'] . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);

    // 3) line_accounts placeholder (unique channel_secret) — get/create id
    $la = $tdb->query('SELECT id FROM line_accounts WHERE is_default = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $lineAccountId = (int) ($la ?: 0);
    if ($lineAccountId === 0) {
        $tdb->prepare(
            'INSERT INTO line_accounts (name, channel_secret, channel_access_token, is_active, is_default, bot_mode, shop_enabled, created_at)
             VALUES (?, ?, ?, 1, 1, "shop", 1, NOW())'
        )->execute([$bizName, 'PENDING-' . $s['slug'], 'PENDING']);
        $lineAccountId = (int) $tdb->lastInsertId();
        echo "  ✓ line_accounts #{$lineAccountId} (placeholder — เชื่อม LINE ภายหลัง)\n";
    } else {
        echo "  • line_accounts #{$lineAccountId} (มีอยู่แล้ว)\n";
    }

    // 4) owner admin_users (username=subdomain, password=phone bcrypt)
    $chk = $tdb->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
    $chk->execute([$s['slug']]);
    if (!$chk->fetchColumn()) {
        $tdb->prepare(
            'INSERT INTO admin_users (username, password, phone, display_name, role, is_active, created_at)
             VALUES (?, ?, ?, ?, "super_admin", 1, NOW())'
        )->execute([$s['slug'], password_hash($phone, PASSWORD_DEFAULT), $phone, $bizName]);
        echo "  ✓ admin_users owner (user={$s['slug']})\n";
    } else {
        echo "  • admin_users owner exists — skip\n";
    }

    // 5) platform route (line_account_id → tenant)
    $platform->prepare(
        'INSERT INTO tenant_line_account_routes (line_account_id, tenant_id, tenant_db_name, oa_name, is_active, created_at)
         VALUES (?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE tenant_db_name=VALUES(tenant_db_name), oa_name=VALUES(oa_name), is_active=1'
    )->execute([$lineAccountId, $s['tid'], $s['db'], $bizName]);
    echo "  ✓ route line_account {$lineAccountId} → tenant {$s['tid']}\n";

    // 6) mark signup
    $platform->prepare('UPDATE beta_signups SET status = "signed_up" WHERE id = ?')->execute([$s['signup']]);
    echo "  ✓ signup #{$s['signup']} → signed_up\n";

    echo "  🎉 {$s['slug']}.re-ya.com พร้อมใช้งาน\n\n";
}

echo $APPLY ? "=== DONE ===\n" : "Re-run with --apply to write.\n";
