<?php
/**
 * SelfServeProvisioning — turn a verified Google identity + a chosen subdomain
 * into a fully provisioned (but LOCKED) tenant.
 *
 * The on-demand sibling of scripts/provision_beta_batch*.php. Differences:
 *   - tenant id is allocated atomically via tenants.AUTO_INCREMENT (no hardcode)
 *   - the new shop starts in status 'pending_setup' (locked) — resolve_subdomain
 *     shows a "waiting for approval" screen until a platform admin flips it to
 *     'active' (admin/tenant-approvals.php).
 *   - the owner is anchored in platform_users (auth_provider='google') so login
 *     resolves Google → platform_users.tenant_id → tenant scope.
 *
 * Reuses TenantProvisioning::create/grant/applySchema for the heavy lifting.
 *
 * Usage (Phase 2 — Google callback):
 *   $res = SelfServeProvisioning::provision([
 *       'google_id' => $sub, 'email' => $email, 'name' => $name,
 *       'shop_name' => $shopName, 'subdomain' => $slug, 'phone' => $phone,
 *   ]);
 */
declare(strict_types=1);

require_once __DIR__ . '/TenantProvisioning.php';

final class SelfServeProvisioning
{
    private const PLATFORM_DB = 'zrismpsz_reya_platform';
    private const DEFAULT_PLAN_ID = 1;

    /** Minimal reserved list — mirrors reya_reserved_subdomains() in bootstrap/resolve_subdomain.php. */
    private const RESERVED_FALLBACK = [
        'www', 'api', 'admin', 'platform', 'cdn', 'static', 'assets', 'mail', 'webmail',
        'smtp', 'imap', 'pop', 'webhook', 'webhooks', 'cpanel', 'whm', 'ftp', 'sftp',
        'ns1', 'ns2', 'app', 'dashboard', 'pharmacy', 'inventory', 'inbox', 'liff',
        'miniapp', 'docs', 'help', 'support', 'status', 'shop', 'odoo', 'stg', 'dev',
        'auth', 'login', 'signup', 'register', 'billing', 'pay', 'blog', 'news',
        'about', 'contact', 'legal', 'terms', 'privacy',
    ];

    /**
     * Validate a candidate subdomain. Returns ['ok'=>bool, 'error'=>?string].
     * Mirrors the slug rule in resolve_subdomain.php: lowercase letters/digits/
     * hyphen, no leading/trailing hyphen, 3..30 chars, not reserved.
     */
    public static function validateSubdomain(string $slug): array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return ['ok' => false, 'error' => 'กรุณาระบุชื่อ subdomain'];
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/', $slug)) {
            return ['ok' => false, 'error' => 'ใช้ a-z, 0-9, ขีดกลาง (3–30 ตัว, ห้ามขึ้น/ลงท้ายด้วยขีด)'];
        }
        $reserved = function_exists('reya_reserved_subdomains')
            ? reya_reserved_subdomains()
            : self::RESERVED_FALLBACK;
        if (in_array($slug, $reserved, true)) {
            return ['ok' => false, 'error' => 'ชื่อนี้สงวนไว้ กรุณาเลือกชื่ออื่น'];
        }
        return ['ok' => true, 'error' => null];
    }

    public static function isSubdomainAvailable(PDO $platform, string $slug): bool
    {
        $stmt = $platform->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute([strtolower(trim($slug))]);
        return $stmt->fetchColumn() === false;
    }

    /**
     * Provision a locked tenant for a Google-authenticated owner.
     *
     * @param array $in google_id, email, name, shop_name, subdomain, phone(optional)
     * @return array tenant_id, subdomain, db_name, status, owner_platform_user_id
     * @throws RuntimeException on validation failure or provisioning error.
     */
    public static function provision(array $in): array
    {
        $googleId = trim((string)($in['google_id'] ?? ''));
        $email    = strtolower(trim((string)($in['email'] ?? '')));
        $name     = trim((string)($in['name'] ?? '')) ?: $email;
        $shopName = trim((string)($in['shop_name'] ?? ''));
        $slug     = strtolower(trim((string)($in['subdomain'] ?? '')));
        $phone    = trim((string)($in['phone'] ?? '')) ?: null;

        if ($googleId === '' || $email === '') {
            throw new RuntimeException('Missing Google identity (google_id/email).');
        }
        if ($shopName === '') {
            throw new RuntimeException('กรุณาระบุชื่อร้าน');
        }
        $v = self::validateSubdomain($slug);
        if (!$v['ok']) {
            throw new RuntimeException((string)$v['error']);
        }

        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ];
        $platform = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . self::PLATFORM_DB . ';charset=utf8mb4',
            DB_USER, DB_PASS, $opts
        );

        // Idempotency: if this Google account already owns a tenant, return it.
        $own = $platform->prepare('SELECT tenant_id FROM platform_users WHERE google_id = ? AND tenant_id IS NOT NULL LIMIT 1');
        $own->execute([$googleId]);
        if ($existingTid = $own->fetchColumn()) {
            $t = $platform->prepare('SELECT id, slug, db_name, status FROM tenants WHERE id = ?');
            $t->execute([(int)$existingTid]);
            if ($row = $t->fetch()) {
                return [
                    'tenant_id' => (int)$row['id'], 'subdomain' => $row['slug'],
                    'db_name' => $row['db_name'], 'status' => $row['status'],
                    'already_owned' => true,
                ];
            }
        }

        if (!self::isSubdomainAvailable($platform, $slug)) {
            throw new RuntimeException('subdomain นี้ถูกใช้แล้ว กรุณาเลือกชื่ออื่น');
        }

        // 1) Atomically allocate tenant id via AUTO_INCREMENT (db_name placeholder first).
        $platform->prepare(
            'INSERT INTO tenants (slug, display_name, db_name, db_host, plan_id, status, owner_name, owner_email, owner_phone, created_at)
             VALUES (?, ?, ?, "localhost", ?, "pending_setup", ?, ?, ?, NOW())'
        )->execute([$slug, $shopName, '__provisioning__', self::DEFAULT_PLAN_ID, $name, $email, $phone]);
        $tid    = (int)$platform->lastInsertId();
        $dbName = TenantProvisioning::tenantIdToDbName($tid);

        try {
            // 2) create DB + grant + schema (idempotent)
            if (!TenantProvisioning::exists($dbName)) {
                TenantProvisioning::create($tid);
                TenantProvisioning::grant($dbName, DB_USER);
                TenantProvisioning::applySchema($dbName);
            }
            $platform->prepare('UPDATE tenants SET db_name = ? WHERE id = ?')->execute([$dbName, $tid]);

            // 3) tenant-side seed: line_accounts placeholder + owner admin_users
            $tdb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);

            $la = $tdb->query('SELECT id FROM line_accounts WHERE is_default = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
            $lineAccountId = (int)($la ?: 0);
            if ($lineAccountId === 0) {
                $tdb->prepare(
                    'INSERT INTO line_accounts (name, channel_secret, channel_access_token, is_active, is_default, bot_mode, shop_enabled, created_at)
                     VALUES (?, ?, ?, 1, 1, "shop", 1, NOW())'
                )->execute([$shopName, 'PENDING-' . $slug, 'PENDING']);
                $lineAccountId = (int)$tdb->lastInsertId();
            }

            // owner admin_users (the freshly-templated tenant DB has no admin_users table)
            $tdb->exec(
                "CREATE TABLE IF NOT EXISTS `admin_users` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `username` varchar(100) NOT NULL,
                  `email` varchar(255) NOT NULL,
                  `phone` varchar(20) DEFAULT NULL,
                  `password` varchar(255) NOT NULL,
                  `display_name` varchar(255) DEFAULT NULL,
                  `avatar_url` varchar(500) DEFAULT NULL,
                  `role` varchar(20) DEFAULT 'admin',
                  `line_account_id` int(11) DEFAULT NULL,
                  `is_active` tinyint(1) DEFAULT 1,
                  `last_login` timestamp NULL DEFAULT NULL,
                  `login_count` int(11) DEFAULT 0,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  `line_user_id` varchar(50) DEFAULT NULL,
                  `notification_enabled` tinyint(1) DEFAULT 1,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `username` (`username`),
                  UNIQUE KEY `email` (`email`),
                  KEY `idx_admin_users_role` (`role`),
                  KEY `idx_admin_users_line_account` (`line_account_id`),
                  KEY `idx_line_user` (`line_user_id`),
                  KEY `idx_role_active` (`role`,`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $chk = $tdb->prepare('SELECT id FROM admin_users WHERE username = ? OR email = ? LIMIT 1');
            $chk->execute([$slug, $email]);
            if (!$chk->fetchColumn()) {
                // Google owners never use this password; store an unguessable random hash.
                $randomPw = bin2hex(random_bytes(24));
                $tdb->prepare(
                    'INSERT INTO admin_users (username, email, password, phone, display_name, role, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, "super_admin", 1, NOW())'
                )->execute([$slug, $email, password_hash($randomPw, PASSWORD_DEFAULT), $phone, $shopName]);
            }

            // 3b) seed sample (demo) data so the dashboard looks alive while the
            //     shop is in pending_setup/demo mode. Non-fatal.
            self::seedDemoData($tdb, $lineAccountId);

            // 4) platform route (line_account_id → tenant)
            $platform->prepare(
                'INSERT INTO tenant_line_account_routes (line_account_id, tenant_id, tenant_db_name, oa_name, is_active, created_at)
                 VALUES (?, ?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE tenant_db_name=VALUES(tenant_db_name), oa_name=VALUES(oa_name), is_active=1'
            )->execute([$lineAccountId, $tid, $dbName, $shopName]);

            // 5) anchor the owner identity at platform level (upsert by google_id)
            $pu = $platform->prepare('SELECT id FROM platform_users WHERE google_id = ? OR email = ? LIMIT 1');
            $pu->execute([$googleId, $email]);
            $ownerPuId = (int)($pu->fetchColumn() ?: 0);
            if ($ownerPuId === 0) {
                $platform->prepare(
                    'INSERT INTO platform_users (email, google_id, auth_provider, name, role, tenant_id, is_active, created_at)
                     VALUES (?, ?, "google", ?, "owner", ?, 1, NOW())'
                )->execute([$email, $googleId, $name, $tid]);
                $ownerPuId = (int)$platform->lastInsertId();
            } else {
                $platform->prepare(
                    'UPDATE platform_users SET google_id = ?, auth_provider = "google", tenant_id = COALESCE(tenant_id, ?), name = ? WHERE id = ?'
                )->execute([$googleId, $tid, $name, $ownerPuId]);
            }

            return [
                'tenant_id' => $tid, 'subdomain' => $slug, 'db_name' => $dbName,
                'status' => 'pending_setup', 'owner_platform_user_id' => $ownerPuId,
                'already_owned' => false,
            ];
        } catch (\Throwable $e) {
            // Best-effort cleanup so a failed provision doesn't squat the slug/id.
            try { $platform->prepare('DELETE FROM tenants WHERE id = ? AND status = "pending_setup" AND db_name = "__provisioning__"')->execute([$tid]); } catch (\Throwable $ignore) {}
            error_log('[SelfServeProvisioning] provision failed for slug=' . $slug . ' tid=' . $tid . ': ' . $e->getMessage());
            throw new RuntimeException('สร้างร้านไม่สำเร็จ กรุณาลองใหม่ หรือติดต่อทีมงาน');
        }
    }

    /**
     * Seed sample products / customers / a category so a brand-new shop's
     * dashboard looks alive during demo (pending_setup) mode. Non-fatal — any
     * schema mismatch is logged and skipped, never blocks provisioning.
     */
    private static function seedDemoData(PDO $tdb, int $lineAccountId): void
    {
        try {
            $tdb->prepare('INSERT INTO business_categories (line_account_id, name, is_active, created_at) VALUES (?, ?, 1, NOW())')
                ->execute([$lineAccountId, 'ยาสามัญประจำบ้าน']);
            $catId = (int) $tdb->lastInsertId();

            $items = [
                ['พาราเซตามอล 500mg (10 เม็ด)', 25.00, 100],
                ['ยาแก้แพ้ คลอเฟนิรามีน (10 เม็ด)', 20.00, 80],
                ['วิตามินซี 1000mg (30 เม็ด)', 150.00, 50],
                ['ยาธาตุน้ำขาว', 18.00, 60],
                ['แอลกอฮอล์เจล 50ml', 35.00, 120],
            ];
            $si = $tdb->prepare('INSERT INTO business_items (line_account_id, category_id, item_type, name, price, stock, is_active, created_at) VALUES (?, ?, "physical", ?, ?, ?, 1, NOW())');
            foreach ($items as $it) {
                $si->execute([$lineAccountId, $catId, $it[0], $it[1], $it[2]]);
            }

            $customers = [
                ['DEMO-U00000000000000000000000000001', 'คุณสมชาย (ตัวอย่าง)', '0810000001', 1250.00, 80],
                ['DEMO-U00000000000000000000000000002', 'คุณสมหญิง (ตัวอย่าง)', '0810000002', 540.00, 65],
                ['DEMO-U00000000000000000000000000003', 'คุณมานี (ตัวอย่าง)', '0810000003', 2100.00, 92],
            ];
            $sc = $tdb->prepare('INSERT INTO users (line_account_id, platform, line_user_id, display_name, real_name, phone, total_spent, customer_score, created_at) VALUES (?, "line", ?, ?, ?, ?, ?, ?, NOW())');
            foreach ($customers as $c) {
                $sc->execute([$lineAccountId, $c[0], $c[1], $c[1], $c[2], $c[3], $c[4]]);
            }
        } catch (\Throwable $e) {
            error_log('[SelfServeProvisioning] seedDemoData skipped: ' . $e->getMessage());
        }
    }

    /**
     * Approve a pending shop — flip status to active so resolve_subdomain unlocks it.
     * Returns true if a row was updated.
     */
    public static function approve(PDO $platform, int $tenantId, int $approvedByPlatformUserId): bool
    {
        $stmt = $platform->prepare(
            'UPDATE tenants SET status = "active", created_by = COALESCE(created_by, ?) WHERE id = ? AND status = "pending_setup"'
        );
        $stmt->execute([$approvedByPlatformUserId, $tenantId]);
        return $stmt->rowCount() > 0;
    }
}
