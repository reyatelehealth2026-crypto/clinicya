<?php
/**
 * admin/switch-tenant.php — Platform Owner UI for entering/exiting a tenant.
 *
 * Auth model (ADR-006 §"Session model"):
 *   - Requires $_SESSION['platform_user_id'] (super admin from platform_users)
 *   - NOT the same auth as $_SESSION['admin_user'] (which is tenant-scoped admin_users)
 *   - 403s anyone who isn't a platform user
 *
 * Effect of "Enter":
 *   Sets $_SESSION['admin_switched_to_tenant_id'] = X
 *   Writes audit row (action=switch_tenant_in) to platform.super_admin_audit
 *   Redirects to /dashboard.php — the rest of the app will then see the tenant
 *   context (TenantContext picked up by includes/auth_check.php on next request).
 *
 * Effect of "Exit":
 *   Unsets the switched_to_tenant_id session var
 *   Writes audit row (action=switch_tenant_out)
 *   Redirects back to this page (Platform Mode dashboard listing tenants)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php'; // pulls in classes/Database.php
require_once __DIR__ . '/../classes/TenantContext.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';

// ---------------------------------------------------------------------------
// Auth gate — Platform Owner only.
// ---------------------------------------------------------------------------
if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>403</title>'
        . '<body style="font-family:sans-serif;padding:40px">'
        . '<h1>403 — Platform Owner only</h1>'
        . '<p>This page is reserved for super admins. '
        . '<a href="/admin/platform-login.php">Sign in as platform owner</a>.</p>'
        . '</body></html>';
    exit;
}

$platformUserId   = (int) $_SESSION['platform_user_id'];
$platformUserName = (string) ($_SESSION['platform_user_name'] ?? 'Platform Owner');

// ---------------------------------------------------------------------------
// Connect to master DB. If unreachable (Wave 2 not yet applied) — show banner.
// ---------------------------------------------------------------------------
try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>Platform not ready</title>'
        . '<body style="font-family:sans-serif;padding:40px">'
        . '<h1>Platform DB not provisioned yet</h1>'
        . '<p>The master database <code>zrismpsz_reya_platform</code> isn\'t reachable. '
        . 'Apply Wave 2 migrations first.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>'
        . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// Helper to write an audit row.
// ---------------------------------------------------------------------------
$writeAudit = function (
    PDO $db,
    int $platformUserId,
    ?int $tenantId,
    string $action,
    array $metadata = []
) {
    try {
        $stmt = $db->prepare(
            'INSERT INTO super_admin_audit
                (platform_user_id, tenant_id, action, ip_address, user_agent,
                 request_method, request_uri, metadata, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $platformUserId,
            $tenantId,
            $action,
            $_SERVER['REMOTE_ADDR']      ?? null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            $_SERVER['REQUEST_METHOD']   ?? null,
            substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (\Throwable $e) {
        error_log('[switch-tenant] audit write failed: ' . $e->getMessage());
    }
};

// ---------------------------------------------------------------------------
// POST handling — enter or exit tenant context.
// ---------------------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'enter') {
        $targetId = (int) ($_POST['tenant_id'] ?? 0);
        $reason   = trim((string) ($_POST['reason'] ?? ''));

        // Verify target is real and active
        $stmt = $platformDb->prepare(
            'SELECT id, slug, display_name, status FROM tenants WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $flash = ['type' => 'error', 'msg' => 'ไม่พบ tenant id นี้'];
        } elseif ($target['status'] === 'terminated') {
            $flash = ['type' => 'error', 'msg' => 'Tenant นี้ถูกระงับถาวรแล้ว — ห้ามเข้า'];
        } else {
            $_SESSION['admin_switched_to_tenant_id'] = (int) $target['id'];
            TenantContext::setCurrentTenantId((int) $target['id']);

            $writeAudit($platformDb, $platformUserId, (int) $target['id'], 'switch_tenant_in', [
                'tenant_slug'         => $target['slug'],
                'tenant_display_name' => $target['display_name'],
                'tenant_status'       => $target['status'],
                'reason'              => $reason !== '' ? $reason : null,
            ]);

            header('Location: /dashboard.php');
            exit;
        }
    } elseif ($action === 'provision') {
        // Create a new tenant — full lifecycle: DB + schema + entitlements + admin user.
        $slug         = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $displayName  = trim((string) ($_POST['display_name'] ?? ''));
        $ownerEmail   = trim((string) ($_POST['owner_email'] ?? ''));
        $ownerName    = trim((string) ($_POST['owner_name'] ?? ''));
        $ownerPhone   = trim((string) ($_POST['owner_phone'] ?? ''));
        $planSlug     = trim((string) ($_POST['plan_slug'] ?? 'starter'));
        $adminUser    = trim((string) ($_POST['admin_username'] ?? ''));
        $adminPass    = (string) ($_POST['admin_password'] ?? '');

        try {
            // ---- Validate input ----
            if ($slug === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/', $slug)) {
                throw new \InvalidArgumentException('Slug ต้องเป็น lowercase + ตัวเลข + ขีดกลาง 2-32 ตัวอักษร');
            }
            if (in_array($slug, ['www','api','admin','platform','app','shop','odoo','stg','dev','mail','cdn','assets','blog','www','support','help','docs'], true)) {
                throw new \InvalidArgumentException('Slug นี้สงวนไว้ — เลือกชื่ออื่น');
            }
            if ($displayName === '') {
                throw new \InvalidArgumentException('กรุณาระบุชื่อร้าน');
            }
            if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Email ของเจ้าของร้านไม่ถูกต้อง');
            }
            if (strlen($adminPass) < 8) {
                throw new \InvalidArgumentException('Password เริ่มต้นต้องยาวอย่างน้อย 8 ตัว');
            }
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $adminUser)) {
                throw new \InvalidArgumentException('Admin username ต้อง 3-50 ตัวอักษร (a-z 0-9 . _ -)');
            }

            // ---- Check slug uniqueness ----
            $stmt = $platformDb->prepare('SELECT id FROM tenants WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn()) {
                throw new \InvalidArgumentException("Slug '{$slug}' ถูกใช้แล้ว");
            }

            // ---- Get plan_id ----
            $stmt = $platformDb->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
            $stmt->execute([$planSlug]);
            $planId = (int) $stmt->fetchColumn();
            if ($planId <= 0) {
                throw new \InvalidArgumentException("Plan '{$planSlug}' ไม่พบ");
            }

            // ---- Pre-allocate tenant_id (next available, skip gaps for the 5 we created) ----
            $maxId = (int) $platformDb->query('SELECT COALESCE(MAX(id), 0) FROM tenants')->fetchColumn();
            // Reserve 1-5 as pre-existing dev DBs — start fresh tenants at 100
            $newTenantId = max($maxId + 1, 100);

            // ---- Default entitlements per plan ----
            $entitlements = [
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
                    ['entitlement_key' => 'allow_telepharmacy','value_bool' => 1],
                    ['entitlement_key' => 'storage_quota_mb', 'value_int' => 2000],
                ],
                'enterprise' => [
                    ['entitlement_key' => 'max_branches',     'value_int' => 10],
                    ['entitlement_key' => 'max_channels',     'value_int' => 10],
                    ['entitlement_key' => 'max_admin_users',  'value_int' => 20],
                    ['entitlement_key' => 'allow_documents',  'value_bool' => 1],
                    ['entitlement_key' => 'allow_ai_chat',    'value_bool' => 1],
                    ['entitlement_key' => 'allow_telepharmacy','value_bool' => 1],
                    ['entitlement_key' => 'storage_quota_mb', 'value_int' => 10000],
                ],
            ][$planSlug] ?? [];

            // ---- Run provisioning ----
            $result = TenantProvisioning::fullProvision(
                $newTenantId,
                [
                    'slug'         => $slug,
                    'display_name' => $displayName,
                    'plan_id'      => $planId,
                    'owner_name'   => $ownerName,
                    'owner_email'  => $ownerEmail,
                    'owner_phone'  => $ownerPhone,
                    'created_by'   => $platformUserId,
                ],
                $entitlements
            );

            // ---- Create initial admin user in the new tenant DB ----
            $tenantPdo = Database::forTenant($newTenantId)->getConnection();
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $insAdmin = $tenantPdo->prepare(
                'INSERT INTO admin_users (username, password, email, display_name, role, is_active, created_at)
                 VALUES (?, ?, ?, ?, "admin", 1, NOW())'
            );
            $insAdmin->execute([$adminUser, $hash, $ownerEmail, $ownerName ?: $adminUser]);

            $writeAudit($platformDb, $platformUserId, $newTenantId, 'provision_tenant', [
                'slug'      => $slug,
                'plan'      => $planSlug,
                'admin'     => $adminUser,
                'db_name'   => $result['db_name'],
            ]);

            $flash = [
                'type' => 'ok',
                'msg'  => "สร้าง tenant สำเร็จ! 🎉 URL: https://{$slug}.re-ya.com/auth/login.php — "
                        . "Login: {$adminUser} / (password ที่คุณตั้ง)",
            ];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Provisioning failed: ' . $e->getMessage()];
            error_log('[switch-tenant provision] ' . $e->getMessage());
        }
    } elseif ($action === 'exit') {
        $previousTenantId = isset($_SESSION['admin_switched_to_tenant_id'])
            ? (int) $_SESSION['admin_switched_to_tenant_id']
            : null;

        unset($_SESSION['admin_switched_to_tenant_id']);
        TenantContext::enterPlatformContext();

        $writeAudit($platformDb, $platformUserId, $previousTenantId, 'switch_tenant_out');

        header('Location: /admin/switch-tenant.php');
        exit;
    }
}

// ---------------------------------------------------------------------------
// GET — render the switch UI.
// ---------------------------------------------------------------------------
$tenants = $platformDb->query(
    'SELECT t.id, t.slug, t.display_name, t.status, t.db_name,
            p.display_name AS plan_name,
            t.created_at
       FROM tenants t
       LEFT JOIN plans p ON p.id = t.plan_id
      ORDER BY t.id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$activeTenantId = !empty($_SESSION['admin_switched_to_tenant_id'])
    ? (int) $_SESSION['admin_switched_to_tenant_id']
    : null;
$activeTenant = null;
if ($activeTenantId) {
    foreach ($tenants as $t) {
        if ((int) $t['id'] === $activeTenantId) {
            $activeTenant = $t;
            break;
        }
    }
}
?>
<?php
require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('tenants', 'ร้านค้า / Switch Tenant', 'สลับเข้าจัดการแต่ละร้าน — ทุกการกระทำถูกบันทึก audit');
?>

<?php if ($activeTenant): ?>
<div role="alert"
     style="all: revert"
     class="w-full bg-red-600 text-white px-6 py-3 sticky top-0 z-50 shadow-lg">
    <div class="max-w-7xl mx-auto flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-3 text-sm font-semibold">
            <i class="fas fa-triangle-exclamation text-xl"></i>
            <span>
                Currently viewing as Tenant
                <strong>#<?= (int) $activeTenant['id'] ?></strong>
                (<?= htmlspecialchars($activeTenant['display_name']) ?>)
                — all actions are being audited.
            </span>
        </div>
        <form method="POST" class="m-0">
            <input type="hidden" name="action" value="exit">
            <button type="submit"
                    class="bg-white text-red-600 font-semibold px-4 py-2 rounded-lg hover:bg-red-50 transition">
                <i class="fas fa-sign-out-alt mr-2"></i>Exit tenant context
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="max-w-5xl mx-auto px-6 py-10">
    <header class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-emerald-100 text-emerald-700">
                <i class="fas fa-user-shield text-xl"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Switch tenant — Platform Owner only</h1>
                <p class="text-sm text-slate-500">
                    Signed in as <strong><?= htmlspecialchars($platformUserName) ?></strong>
                    (platform user #<?= $platformUserId ?>)
                </p>
            </div>
        </div>
        <p class="text-sm text-slate-600 max-w-3xl">
            เลือก tenant เพื่อเข้าดูข้อมูลในบริบทของร้านนั้น. การเข้าถึงทุกครั้งจะถูกบันทึกใน
            <code class="text-xs">platform.super_admin_audit</code> และไม่สามารถลบโดย super admin ได้.
        </p>
    </header>

    <?php if ($flash): ?>
        <div class="mb-6 p-4 rounded-xl border <?= $flash['type'] === 'error'
            ? 'bg-red-50 border-red-200 text-red-700'
            : 'bg-green-50 border-green-200 text-green-700' ?>">
            <i class="fas fa-info-circle mr-2"></i><?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Provisioning panel — create new tenant -->
    <details class="pf-card mb-6 overflow-hidden group">
        <summary class="px-6 py-4 cursor-pointer flex items-center justify-between hover:bg-indigo-50 transition">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-indigo-600 text-white">
                    <i class="fas fa-plus text-lg"></i>
                </span>
                <div>
                    <h2 class="font-semibold text-slate-900">สร้าง Tenant ใหม่ (Provision)</h2>
                    <p class="text-xs text-slate-500">กรอกข้อมูลร้าน → ระบบสร้าง DB + subdomain + initial admin อัตโนมัติ</p>
                </div>
            </div>
            <i class="fas fa-chevron-down text-slate-400 group-open:rotate-180 transition"></i>
        </summary>

        <form method="POST" class="px-6 py-5 border-t border-slate-100 space-y-4 bg-slate-50/50"
              onsubmit="return confirm('ยืนยันสร้าง tenant ใหม่? — ระบบจะสร้าง database + apply schema (~30 วินาที)');">
            <input type="hidden" name="action" value="provision">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Slug <span class="text-red-500">*</span>
                        <span class="text-xs text-slate-400 font-normal">(URL: {slug}.re-ya.com)</span>
                    </label>
                    <input type="text" name="slug" required pattern="[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?" maxlength="32"
                           placeholder="smilepharm"
                           class="pf-input">
                    <p class="text-xs text-slate-400 mt-1">a-z, 0-9, hyphen เท่านั้น (2-32 ตัว) — เปลี่ยนไม่ได้ภายหลัง</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        ชื่อร้าน (Display Name) <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="display_name" required maxlength="120"
                           placeholder="ร้านยา สไมล์ ฟาร์ม สาขาเซ็นทรัล"
                           class="pf-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Owner Name</label>
                    <input type="text" name="owner_name" maxlength="100"
                           placeholder="ภญ. สมศรี"
                           class="pf-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Owner Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="owner_email" required maxlength="120"
                           placeholder="owner@pharmacy.com"
                           class="pf-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Owner Phone</label>
                    <input type="tel" name="owner_phone" maxlength="20"
                           placeholder="0812345678"
                           class="pf-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Plan</label>
                    <select name="plan_slug" class="pf-input">
                        <option value="starter">Starter — 1 สาขา / 1 LINE OA (990฿/เดือน)</option>
                        <option value="pro">Pro — 3 สาขา / 3 LINE OA (2,990฿/เดือน)</option>
                        <option value="enterprise">Enterprise — 10 สาขา / 10 LINE OA (9,990฿/เดือน)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Initial Admin Username <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="admin_username" required pattern="[a-zA-Z0-9._-]{3,50}" maxlength="50"
                           placeholder="admin"
                           class="pf-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Initial Admin Password <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="admin_password" required minlength="8" maxlength="64"
                           value="Reya@<?= date('Y') ?>"
                           class="pf-input font-mono">
                    <p class="text-xs text-slate-400 mt-1">อย่างน้อย 8 ตัว — แจ้งเจ้าของร้านให้เปลี่ยน password หลัง login ครั้งแรก</p>
                </div>
            </div>

            <div class="flex justify-end pt-3 border-t border-slate-100">
                <button type="submit" class="pf-btn pf-btn-primary">
                    <i class="fas fa-rocket"></i>
                    Provision Tenant
                </button>
            </div>
        </form>
    </details>

    <div class="pf-card overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">
                Tenants (<?= count($tenants) ?>)
            </h2>
            <div class="flex items-center gap-4">
                <a href="/admin/customers.php"
                   class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">
                    <i class="fas fa-users mr-1"></i>ลูกค้า / Customers
                </a>
                <a href="/admin/platform-login.php?action=logout"
                   class="text-sm text-slate-500 hover:text-red-600">
                    <i class="fas fa-sign-out-alt mr-1"></i>Sign out (platform)
                </a>
            </div>
        </div>

        <?php if (empty($tenants)): ?>
            <div class="px-6 py-16 text-center text-slate-500">
                <i class="fas fa-inbox text-4xl mb-3 text-slate-300"></i>
                <p>ยังไม่มี tenant ใน <code class="text-xs">zrismpsz_reya_platform.tenants</code>.</p>
                <p class="text-xs mt-2">ใช้ <code>scripts/provision_tenant.php</code> หรือ <code>TenantProvisioning::fullProvision()</code> เพื่อสร้าง tenant แรก.</p>
            </div>
        <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 uppercase text-xs">
                    <tr>
                        <th class="pf-th">ID</th>
                        <th class="pf-th">Slug</th>
                        <th class="pf-th">ชื่อร้าน</th>
                        <th class="pf-th">Plan</th>
                        <th class="pf-th">Status</th>
                        <th class="pf-th">DB</th>
                        <th class="pf-th" style="text-align:right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                <?php foreach ($tenants as $t): ?>
                    <?php
                    $statusColor = match ($t['status']) {
                        'active'        => 'bg-green-100 text-green-700',
                        'suspended'     => 'bg-amber-100 text-amber-700',
                        'pending_setup' => 'bg-blue-100 text-blue-700',
                        'terminated'    => 'bg-red-100 text-red-700',
                        default         => 'bg-slate-100 text-slate-700',
                    };
                    $isActive = $activeTenantId === (int) $t['id'];
                    ?>
                    <tr class="<?= $isActive ? 'bg-red-50' : 'hover:bg-slate-50' ?>">
                        <td class="px-6 py-4 font-mono text-slate-700">#<?= (int) $t['id'] ?></td>
                        <td class="px-6 py-4 text-slate-600"><?= htmlspecialchars((string) $t['slug']) ?></td>
                        <td class="px-6 py-4 text-slate-900 font-medium">
                            <?= htmlspecialchars((string) $t['display_name']) ?>
                            <?php if ($isActive): ?>
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-700">
                                    <i class="fas fa-eye text-xs mr-1"></i>currently inside
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-slate-600"><?= htmlspecialchars((string) ($t['plan_name'] ?? '—')) ?></td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $statusColor ?>">
                                <?= htmlspecialchars((string) $t['status']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-500"><?= htmlspecialchars((string) $t['db_name']) ?></td>
                        <td class="px-6 py-4 text-right">
                            <?php if ($t['status'] === 'terminated'): ?>
                                <span class="text-xs text-slate-400">terminated</span>
                            <?php else: ?>
                                <form method="POST" class="inline-flex items-center gap-2"
                                      onsubmit="return confirmEnter(this);">
                                    <input type="hidden" name="action" value="enter">
                                    <input type="hidden" name="tenant_id" value="<?= (int) $t['id'] ?>">
                                    <input type="text"
                                           name="reason"
                                           placeholder="reason (optional)"
                                           class="pf-input text-xs w-40" style="padding:.35rem .6rem;">
                                    <button type="submit"
                                            class="pf-btn pf-btn-primary" style="padding:.4rem .8rem;font-size:.75rem;">
                                        <i class="fas fa-sign-in-alt"></i>Enter
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <footer class="mt-8 text-xs text-slate-400 text-center">
        ADR-001 • ADR-006 — Every tenant switch and every write inside a tenant context is audited.
    </footer>
</div>

<script>
function confirmEnter(form) {
    const reason = form.querySelector('input[name="reason"]').value.trim();
    if (reason.length > 0 && reason.length < 5) {
        alert('Please describe the reason in at least 5 characters, or leave it blank.');
        return false;
    }
    return confirm('Enter this tenant context? This will be recorded in the audit log.');
}
</script>

<?php platform_shell_bottom(); ?>
