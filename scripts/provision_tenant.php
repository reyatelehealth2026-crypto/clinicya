<?php
declare(strict_types=1);
/**
 * scripts/provision_tenant.php — CLI tenant provisioning.
 *
 * Mirrors the admin/switch-tenant.php "provision" action so a super-admin can
 * create a tenant from the shell (required on cPanel hosts where `uapi`
 * Mysql create_database must run locally). Runs the full lifecycle:
 *   tenants row → physical DB (uapi) → grant → template schema → entitlements
 *   → status=active → initial admin_users row in the tenant DB.
 *
 * Usage:
 *   php scripts/provision_tenant.php \
 *     --slug=ponchaipharmacy --name="ร้านยา..." --email=a@b.com \
 *     --owner="ชื่อเจ้าของ" --phone=0812345678 --plan=starter \
 *     --admin-user=ponchaipharmacy --admin-pass=secret123
 *
 * Credentials are passed as CLI args (never committed). Exit 0 on success.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';

function pt_fail(string $msg): void
{
    fwrite(STDERR, 'ERROR: ' . $msg . "\n");
    exit(1);
}

// --- Parse --key=value args ---
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-zA-Z][a-zA-Z0-9-]*)=(.*)$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$slug        = strtolower(trim($opts['slug'] ?? ''));
$displayName = trim($opts['name'] ?? '');
$ownerEmail  = trim($opts['email'] ?? '');
$ownerName   = trim($opts['owner'] ?? '');
$ownerPhone  = trim($opts['phone'] ?? '');
$planSlug    = trim($opts['plan'] ?? 'starter');
$adminUser   = trim($opts['admin-user'] ?? '');
$adminPass   = (string) ($opts['admin-pass'] ?? '');

// --- Validate (same rules as admin/switch-tenant.php) ---
if ($slug === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $slug)) {
    pt_fail('slug must be lowercase letters/digits/hyphen, 2-32 chars');
}
if ($displayName === '') {
    pt_fail('--name (display name) is required');
}
if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    pt_fail('--email is not a valid email');
}
if (strlen($adminPass) < 8) {
    pt_fail('--admin-pass must be at least 8 characters');
}
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $adminUser)) {
    pt_fail('--admin-user must be 3-50 chars (a-z 0-9 . _ -)');
}

// Reserved-subdomain guard (same list the resolver enforces).
$reserved = ['www', 'api', 'admin', 'platform', 'app', 'shop', 'odoo', 'stg', 'dev'];
if (function_exists('reya_reserved_subdomains')) {
    $reserved = reya_reserved_subdomains();
}
if (in_array($slug, $reserved, true)) {
    pt_fail("slug '{$slug}' is reserved");
}

$platformDb = Database::platform()->getConnection();

// --- Slug uniqueness ---
$st = $platformDb->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
$st->execute([$slug]);
if ($st->fetchColumn()) {
    pt_fail("slug '{$slug}' is already used");
}

// --- Resolve plan ---
$st = $platformDb->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
$st->execute([$planSlug]);
$planId = (int) $st->fetchColumn();
if ($planId <= 0) {
    pt_fail("plan '{$planSlug}' not found");
}

// --- Pre-allocate tenant id (fresh tenants start at 100) ---
$maxId = (int) $platformDb->query('SELECT COALESCE(MAX(id), 0) FROM tenants')->fetchColumn();
$newTenantId = max($maxId + 1, 100);

// --- Default entitlements per plan (same as web flow) ---
$entitlementsMap = [
    'starter' => [
        ['entitlement_key' => 'max_branches',     'value_int' => 1],
        ['entitlement_key' => 'max_channels',     'value_int' => 1],
        ['entitlement_key' => 'max_admin_users',  'value_int' => 2],
        ['entitlement_key' => 'allow_documents',  'value_bool' => 1],
        ['entitlement_key' => 'allow_ai_chat',    'value_bool' => 1],
        ['entitlement_key' => 'storage_quota_mb', 'value_int' => 500],
    ],
    'pro' => [
        ['entitlement_key' => 'max_branches',     'value_int' => 3],
        ['entitlement_key' => 'max_channels',     'value_int' => 3],
        ['entitlement_key' => 'max_admin_users',  'value_int' => 5],
        ['entitlement_key' => 'allow_documents',  'value_bool' => 1],
        ['entitlement_key' => 'allow_ai_chat',    'value_bool' => 1],
        ['entitlement_key' => 'allow_telepharmacy', 'value_bool' => 1],
        ['entitlement_key' => 'storage_quota_mb', 'value_int' => 2000],
    ],
    'enterprise' => [
        ['entitlement_key' => 'max_branches',     'value_int' => 10],
        ['entitlement_key' => 'max_channels',     'value_int' => 10],
        ['entitlement_key' => 'max_admin_users',  'value_int' => 20],
        ['entitlement_key' => 'allow_documents',  'value_bool' => 1],
        ['entitlement_key' => 'allow_ai_chat',    'value_bool' => 1],
        ['entitlement_key' => 'allow_telepharmacy', 'value_bool' => 1],
        ['entitlement_key' => 'storage_quota_mb', 'value_int' => 10000],
    ],
];
$entitlements = $entitlementsMap[$planSlug] ?? [];

// --- Run full provisioning ---
try {
    $result = TenantProvisioning::fullProvision(
        $newTenantId,
        [
            'slug'         => $slug,
            'display_name' => $displayName,
            'plan_id'      => $planId,
            'owner_name'   => $ownerName,
            'owner_email'  => $ownerEmail,
            'owner_phone'  => $ownerPhone,
            'created_by'   => null,
        ],
        $entitlements
    );
} catch (\Throwable $e) {
    pt_fail('provisioning failed: ' . $e->getMessage());
}

// --- Create the initial admin user inside the new tenant DB ---
// The tenant template does not ship an admin_users table — AdminAuth normally
// creates it lazily on first login. Create it here so the owner can log in.
try {
    $tenantPdo = Database::forTenant($newTenantId)->getConnection();
    $tenantPdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100),
            display_name VARCHAR(100),
            role VARCHAR(20) DEFAULT 'admin',
            is_active TINYINT(1) DEFAULT 1,
            last_login TIMESTAMP NULL,
            login_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $hash = password_hash($adminPass, PASSWORD_BCRYPT);
    $tenantPdo->prepare(
        'INSERT INTO admin_users (username, password, email, display_name, role, is_active, created_at)
         VALUES (?, ?, ?, ?, "admin", 1, NOW())'
    )->execute([$adminUser, $hash, $ownerEmail, $ownerName !== '' ? $ownerName : $adminUser]);
} catch (\Throwable $e) {
    pt_fail("tenant DB created ({$result['db_name']}) but admin user insert failed: " . $e->getMessage());
}

echo "OK\n";
echo "tenant_id : {$newTenantId}\n";
echo "db_name   : {$result['db_name']}\n";
echo "slug      : {$slug}\n";
echo "login_url : https://{$slug}.re-ya.com/auth/login.php\n";
echo "admin     : {$adminUser}\n";
exit(0);
