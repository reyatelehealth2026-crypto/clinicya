<?php
/**
 * provision_beta_batch2.php — Provision the 3 beta signups that need brand-new
 * tenant DBs (t_0006/0007/0008). Assumes the physical DB + zrismpsz_demo grant
 * + schema have ALREADY been applied (done in shell before running this).
 * Fully idempotent. Owner login: username=subdomain, password=phone (bcrypt).
 *
 * Usage:  php provision_beta_batch2.php           (dry-run)
 *         php provision_beta_batch2.php --apply
 */
declare(strict_types=1);
@set_time_limit(0);

require_once __DIR__ . '/../config/config.php';

$APPLY = in_array('--apply', $argv ?? [], true);

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
];
$platform = new PDO('mysql:host=' . DB_HOST . ';dbname=zrismpsz_reya_platform;charset=utf8mb4', DB_USER, DB_PASS, $opts);

$shops = [
    ['signup' => 10, 'slug' => 'wellpharmacy',        'phone' => '0616547871', 'tid' => 6, 'db' => 'zrismpsz_reya_t_0006'],
    ['signup' => 12, 'slug' => 'punyapharmacyhuahin', 'phone' => '0920916365', 'tid' => 7, 'db' => 'zrismpsz_reya_t_0007'],
    ['signup' => 13, 'slug' => 'mintpharma',          'phone' => '0851899815', 'tid' => 8, 'db' => 'zrismpsz_reya_t_0008'],
];

echo ($APPLY ? "=== APPLY MODE ===" : "=== DRY RUN ===") . PHP_EOL . PHP_EOL;

foreach ($shops as $s) {
    echo "── {$s['slug']} (signup #{$s['signup']} → tenant {$s['tid']} / {$s['db']}) ──" . PHP_EOL;

    // Guard: DB must exist + have admin_users before we proceed
    $exists = $platform->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' .
        $platform->quote($s['db']) . ' AND TABLE_NAME = "admin_users"'
    )->fetchColumn();
    if (!$exists) {
        echo "  !! DB หรือ admin_users ยังไม่พร้อม — ข้าม (สร้าง DB+schema ก่อน)\n\n";
        continue;
    }

    $sig = $platform->prepare('SELECT full_name, business_name FROM beta_signups WHERE id = ?');
    $sig->execute([$s['signup']]);
    $row = $sig->fetch();
    if (!$row) { echo "  !! signup not found\n\n"; continue; }
    $bizName   = trim((string) $row['business_name']) ?: $s['slug'];
    $ownerName = trim((string) $row['full_name']) ?: $s['slug'];
    $phone     = $s['phone'];

    echo "  ร้าน: {$bizName} / เจ้าของ: {$ownerName} / โทร: {$phone}\n";
    echo "  login → user: {$s['slug']}  pass: {$phone}\n";
    if (!$APPLY) { echo "  (dry-run)\n\n"; continue; }

    $platform->prepare(
        'INSERT INTO tenants (id, slug, display_name, db_name, db_host, plan_id, status, owner_name, owner_phone, created_at)
         VALUES (?, ?, ?, ?, "localhost", 1, "active", ?, ?, NOW())
         ON DUPLICATE KEY UPDATE slug=VALUES(slug), display_name=VALUES(display_name),
            db_name=VALUES(db_name), status="active", owner_name=VALUES(owner_name), owner_phone=VALUES(owner_phone)'
    )->execute([$s['tid'], $s['slug'], $bizName, $s['db'], $ownerName, $phone]);
    echo "  ✓ tenants row\n";

    $tdb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $s['db'] . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);

    $la = $tdb->query('SELECT id FROM line_accounts WHERE is_default = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    $lineAccountId = (int) ($la ?: 0);
    if ($lineAccountId === 0) {
        $tdb->prepare(
            'INSERT INTO line_accounts (name, channel_secret, channel_access_token, is_active, is_default, bot_mode, shop_enabled, created_at)
             VALUES (?, ?, ?, 1, 1, "shop", 1, NOW())'
        )->execute([$bizName, 'PENDING-' . $s['slug'], 'PENDING']);
        $lineAccountId = (int) $tdb->lastInsertId();
        echo "  ✓ line_accounts #{$lineAccountId} (placeholder)\n";
    } else {
        echo "  • line_accounts #{$lineAccountId} (มีอยู่)\n";
    }

    $chk = $tdb->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
    $chk->execute([$s['slug']]);
    if (!$chk->fetchColumn()) {
        $tdb->prepare(
            'INSERT INTO admin_users (username, password, phone, display_name, role, is_active, created_at)
             VALUES (?, ?, ?, ?, "super_admin", 1, NOW())'
        )->execute([$s['slug'], password_hash($phone, PASSWORD_DEFAULT), $phone, $bizName]);
        echo "  ✓ admin_users owner (user={$s['slug']})\n";
    } else {
        echo "  • admin_users owner exists\n";
    }

    $platform->prepare(
        'INSERT INTO tenant_line_account_routes (line_account_id, tenant_id, tenant_db_name, oa_name, is_active, created_at)
         VALUES (?, ?, ?, ?, 1, NOW())
         ON DUPLICATE KEY UPDATE tenant_db_name=VALUES(tenant_db_name), oa_name=VALUES(oa_name), is_active=1'
    )->execute([$lineAccountId, $s['tid'], $s['db'], $bizName]);
    echo "  ✓ route line_account {$lineAccountId} → tenant {$s['tid']}\n";

    $platform->prepare('UPDATE beta_signups SET status = "signed_up" WHERE id = ?')->execute([$s['signup']]);
    echo "  ✓ signup #{$s['signup']} → signed_up\n";
    echo "  🎉 {$s['slug']}.re-ya.com พร้อมใช้งาน\n\n";
}

echo $APPLY ? "=== DONE ===\n" : "Re-run with --apply.\n";
