<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/TenantProvisioning.php';
require_once __DIR__ . '/EmailService.php';

/**
 * TenantOnboardingService — high-level "owner details → live tenant" orchestration.
 *
 * Wraps TenantProvisioning::fullProvision() with the surrounding business steps a
 * bare provision doesn't cover: plan resolution, tenant-id allocation, per-plan
 * default entitlements, initial admin-user creation, and a welcome email with the
 * login URL + temporary credentials.
 *
 * Used by admin/beta-signups.php (one-click provision from a Beta lead). The same
 * sequence is currently inlined in admin/switch-tenant.php; that copy can later be
 * pointed at this helper to remove the duplication.
 */
class TenantOnboardingService
{
    /** Per-plan default entitlements (mirrors admin/switch-tenant.php). */
    private const PLAN_ENTITLEMENTS = [
        'starter' => [
            ['entitlement_key' => 'max_branches',     'value_int' => 1],
            ['entitlement_key' => 'max_channels',     'value_int' => 1],
            ['entitlement_key' => 'max_admin_users',  'value_int' => 2],
            ['entitlement_key' => 'allow_documents',  'value_bool' => 1],
            ['entitlement_key' => 'allow_ai_chat',    'value_bool' => 1],
            ['entitlement_key' => 'storage_quota_mb', 'value_int' => 500],
        ],
        'pro' => [
            ['entitlement_key' => 'max_branches',      'value_int' => 3],
            ['entitlement_key' => 'max_channels',      'value_int' => 3],
            ['entitlement_key' => 'max_admin_users',   'value_int' => 5],
            ['entitlement_key' => 'allow_documents',   'value_bool' => 1],
            ['entitlement_key' => 'allow_ai_chat',     'value_bool' => 1],
            ['entitlement_key' => 'allow_telepharmacy','value_bool' => 1],
            ['entitlement_key' => 'storage_quota_mb',  'value_int' => 2000],
        ],
        'enterprise' => [
            ['entitlement_key' => 'max_branches',      'value_int' => 10],
            ['entitlement_key' => 'max_channels',      'value_int' => 10],
            ['entitlement_key' => 'max_admin_users',   'value_int' => 20],
            ['entitlement_key' => 'allow_documents',   'value_bool' => 1],
            ['entitlement_key' => 'allow_ai_chat',     'value_bool' => 1],
            ['entitlement_key' => 'allow_telepharmacy','value_bool' => 1],
            ['entitlement_key' => 'storage_quota_mb',  'value_int' => 10000],
        ],
    ];

    /**
     * Provision a brand-new tenant from owner-supplied details.
     *
     * @param array $owner keys: slug, display_name, owner_name, owner_email,
     *                     owner_phone, plan_slug, admin_username, admin_password
     *                     (admin_password auto-generated if blank), created_by.
     * @return array ['tenant_id','db_name','slug','login_url','admin_username',
     *                'admin_password','email_sent']
     * @throws InvalidArgumentException on bad input / dup slug / unknown plan
     * @throws RuntimeException on provisioning failure (rolled back by fullProvision)
     */
    public static function provisionFromOwner(array $owner): array
    {
        $platformDb = Database::platform()->getConnection();

        $slug        = strtolower(trim((string) ($owner['slug'] ?? '')));
        $displayName = trim((string) ($owner['display_name'] ?? ''));
        $ownerEmail  = trim((string) ($owner['owner_email'] ?? ''));
        $ownerName   = trim((string) ($owner['owner_name'] ?? ''));
        $ownerPhone  = trim((string) ($owner['owner_phone'] ?? ''));
        $planSlug    = trim((string) ($owner['plan_slug'] ?? 'starter')) ?: 'starter';
        $adminUser   = trim((string) ($owner['admin_username'] ?? 'admin')) ?: 'admin';
        $adminPass   = (string) ($owner['admin_password'] ?? '');
        if ($adminPass === '') {
            $adminPass = self::generatePassword();
        }
        $createdBy = isset($owner['created_by']) ? (int) $owner['created_by'] : null;

        // ---- Validate input (same rules as switch-tenant.php) ----
        if ($slug === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $slug)) {
            throw new \InvalidArgumentException('Slug ต้องเป็น lowercase + ตัวเลข + ขีดกลาง 2-32 ตัวอักษร');
        }
        // Use the SAME reserved list the subdomain resolver enforces.
        if (!function_exists('reya_reserved_subdomains')) {
            $resolver = __DIR__ . '/../bootstrap/resolve_subdomain.php';
            if (is_file($resolver)) {
                require_once $resolver;
            }
        }
        $reserved = function_exists('reya_reserved_subdomains')
            ? reya_reserved_subdomains()
            : ['www','api','admin','platform','app','shop','odoo','stg','dev','beta'];
        if (in_array($slug, $reserved, true)) {
            throw new \InvalidArgumentException("Slug '{$slug}' สงวนไว้ — เลือกชื่ออื่น");
        }
        if ($displayName === '') {
            throw new \InvalidArgumentException('กรุณาระบุชื่อร้าน (display name)');
        }
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email ของเจ้าของร้านไม่ถูกต้อง — ต้องมีอีเมลเพื่อส่ง credential');
        }
        if (strlen($adminPass) < 8) {
            throw new \InvalidArgumentException('Password เริ่มต้นต้องยาวอย่างน้อย 8 ตัว');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $adminUser)) {
            throw new \InvalidArgumentException('Admin username ต้อง 3-50 ตัวอักษร (a-z 0-9 . _ -)');
        }

        // ---- Slug uniqueness ----
        $stmt = $platformDb->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn()) {
            throw new \InvalidArgumentException("Slug '{$slug}' ถูกใช้แล้ว");
        }

        // ---- Resolve plan_id ----
        $stmt = $platformDb->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
        $stmt->execute([$planSlug]);
        $planId = (int) $stmt->fetchColumn();
        if ($planId <= 0) {
            throw new \InvalidArgumentException("Plan '{$planSlug}' ไม่พบ");
        }

        // ---- Pre-allocate tenant_id (reserve 1-5 dev DBs; start fresh at 100) ----
        $maxId       = (int) $platformDb->query('SELECT COALESCE(MAX(id), 0) FROM tenants')->fetchColumn();
        $newTenantId = max($maxId + 1, 100);

        $entitlements = self::PLAN_ENTITLEMENTS[$planSlug] ?? [];

        // ---- Run provisioning (creates DB, applies schema, seeds entitlements) ----
        $result = TenantProvisioning::fullProvision(
            $newTenantId,
            [
                'slug'         => $slug,
                'display_name' => $displayName,
                'plan_id'      => $planId,
                'owner_name'   => $ownerName,
                'owner_email'  => $ownerEmail,
                'owner_phone'  => $ownerPhone,
                'created_by'   => $createdBy,
            ],
            $entitlements
        );

        // ---- Create initial admin user in the new tenant DB ----
        $tenantPdo = Database::forTenant($newTenantId)->getConnection();
        $hash      = password_hash($adminPass, PASSWORD_BCRYPT);
        $tenantPdo->prepare(
            'INSERT INTO admin_users (username, password, email, display_name, role, is_active, created_at)
             VALUES (?, ?, ?, ?, "admin", 1, NOW())'
        )->execute([$adminUser, $hash, $ownerEmail, $ownerName ?: $adminUser]);

        // ---- Landing V2 default (2026-07-03): tenant ใหม่ได้หน้าเว็บโฉมใหม่เลย ----
        // ค่าเริ่มต้นซ่อน section ที่ไม่มีข้อมูลเอง จึงปลอดภัยแม้ร้านยังไม่กรอกอะไร
        try {
            require_once __DIR__ . '/LandingV2Config.php';
            $v2Json = json_encode(LandingV2Config::defaults(), JSON_UNESCAPED_UNICODE);
            // idempotent: provisioning ที่ retry จะไม่สร้างแถวซ้ำ (unique key ไม่กัน NULL)
            $v2Seed = $tenantPdo->prepare(
                'INSERT INTO landing_settings (line_account_id, setting_key, setting_value)
                 SELECT NULL, :k, :v FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM landing_settings WHERE setting_key = :k2 AND line_account_id IS NULL
                 )'
            );
            $v2Seed->execute([':k' => LandingV2Config::DRAFT_KEY, ':v' => $v2Json, ':k2' => LandingV2Config::DRAFT_KEY]);
            $v2Seed->execute([':k' => LandingV2Config::PUBLISHED_KEY, ':v' => $v2Json, ':k2' => LandingV2Config::PUBLISHED_KEY]);
        } catch (\Throwable $v2Ex) {
            error_log('[TenantOnboarding] landing v2 seed failed for tenant ' . $newTenantId . ': ' . $v2Ex->getMessage());
        }

        // ---- Seed trial subscription in master DB ----
        try {
            $trialDays = defined('SUBSCRIPTION_TRIAL_DAYS') ? (int) SUBSCRIPTION_TRIAL_DAYS : 14;

            $priceStmt = $platformDb->prepare('SELECT price_monthly_thb FROM plans WHERE id = ? LIMIT 1');
            $priceStmt->execute([$planId]);
            $planPrice = (float) ($priceStmt->fetchColumn() ?: 0.0);

            $platformDb->prepare(
                'INSERT INTO tenant_subscriptions
                    (tenant_id, start_date, billing_cycle, next_due_date, trial_ends_at, amount_thb, billing_contact_email, auto_suspend_enabled, created_at)
                 VALUES
                    (:tid, CURDATE(), \'monthly\', DATE_ADD(CURDATE(), INTERVAL :d DAY), DATE_ADD(CURDATE(), INTERVAL :d DAY), :amt, :email, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                    trial_ends_at = VALUES(trial_ends_at),
                    next_due_date  = VALUES(next_due_date),
                    amount_thb     = VALUES(amount_thb)'
            )->execute([
                ':tid'   => $newTenantId,
                ':d'     => $trialDays,
                ':amt'   => $planPrice,
                ':email' => $ownerEmail,
            ]);
        } catch (\Throwable $subEx) {
            error_log('[TenantOnboarding] subscription seed failed for tenant ' . $newTenantId . ': ' . $subEx->getMessage());
        }

        // ---- Welcome email with login URL + temp credentials ----
        $loginUrl  = "https://{$slug}.re-ya.com/auth/login.php";
        $emailSent = self::sendWelcomeEmail(
            $platformDb, $ownerEmail, $ownerName, $displayName, $loginUrl, $adminUser, $adminPass
        );

        return [
            'tenant_id'      => $newTenantId,
            'db_name'        => $result['db_name'],
            'slug'           => $slug,
            'login_url'      => $loginUrl,
            'admin_username' => $adminUser,
            'admin_password' => $adminPass,
            'email_sent'     => $emailSent,
        ];
    }

    /**
     * Return the default entitlements array for a plan slug.
     * Returns an empty array for unknown slugs (no-op at caller).
     */
    public static function planEntitlements(string $slug): array
    {
        return self::PLAN_ENTITLEMENTS[$slug] ?? [];
    }

    /**
     * Generate a readable temporary password — avoids visually ambiguous chars
     * (0/O, 1/l/I) so owners can type it from a phone call without confusion.
     */
    public static function generatePassword(int $len = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return 'Reya-' . $out;
    }

    /**
     * Send the welcome / credentials email. Reuses the platform EmailService
     * (SMTP if email_settings is configured, otherwise PHP mail()).
     * Returns true on success; never throws (failure is reported, not fatal).
     */
    public static function sendWelcomeEmail(
        \PDO $db,
        string $to,
        string $ownerName,
        string $shopName,
        string $loginUrl,
        string $username,
        string $password
    ): bool {
        try {
            $mailer = new EmailService($db);
            $e      = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $greet  = $ownerName !== '' ? $e($ownerName) : 'เจ้าของร้าน';
            $subject = 'ยินดีต้อนรับสู่ REYA — ข้อมูลเข้าใช้งานร้าน ' . $shopName;

            $body = '<div style="font-family:Sarabun,Arial,sans-serif;max-width:560px;margin:0 auto;color:#0f172a">'
                . '<div style="background:linear-gradient(135deg,#064e3b,#059669);padding:28px 24px;border-radius:16px 16px 0 0;color:#fff">'
                . '<h1 style="margin:0;font-size:22px">ยินดีต้อนรับสู่ REYA 🎉</h1>'
                . '<p style="margin:8px 0 0;opacity:.9;font-size:14px">ร้านของคุณพร้อมใช้งานแล้ว</p>'
                . '</div>'
                . '<div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 16px 16px;padding:24px">'
                . '<p>สวัสดีคุณ ' . $greet . ' 🙏</p>'
                . '<p>เราได้สร้างระบบร้าน <strong>' . $e($shopName) . '</strong> ให้เรียบร้อยแล้ว '
                . 'พร้อมสิทธิ์ Beta <strong>ฟรีค่าตั้งระบบ มูลค่า 2,000 บาท</strong></p>'
                . '<div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:16px;margin:18px 0">'
                . '<p style="margin:0 0 10px;font-weight:600;color:#065f46">ข้อมูลเข้าใช้งาน</p>'
                . '<table style="font-size:14px;line-height:1.9">'
                . '<tr><td style="color:#64748b;padding-right:12px">เข้าสู่ระบบที่</td><td><a href="' . $e($loginUrl) . '" style="color:#059669;font-weight:600">' . $e($loginUrl) . '</a></td></tr>'
                . '<tr><td style="color:#64748b;padding-right:12px">Username</td><td><strong>' . $e($username) . '</strong></td></tr>'
                . '<tr><td style="color:#64748b;padding-right:12px">Password</td><td><strong style="font-family:monospace">' . $e($password) . '</strong></td></tr>'
                . '</table>'
                . '</div>'
                . '<p style="font-size:13px;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px">'
                . '⚠️ เพื่อความปลอดภัย กรุณาเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบครั้งแรก</p>'
                . '<p style="font-size:14px">ทีมงานจะติดต่อกลับเพื่อช่วยตั้งค่าเริ่มต้นและพาทัวร์ระบบ '
                . 'หากมีคำถามทักไลน์ <a href="https://line.me/R/ti/p/@reya" style="color:#06C755;font-weight:600">@reya</a> ได้เลย</p>'
                . '<p style="margin-top:20px;color:#94a3b8;font-size:12px">REYA Platform · re-ya.com</p>'
                . '</div></div>';

            return (bool) $mailer->send($to, $subject, $body, true);
        } catch (\Throwable $ex) {
            error_log('[TenantOnboarding] welcome email failed: ' . $ex->getMessage());
            return false;
        }
    }
}
