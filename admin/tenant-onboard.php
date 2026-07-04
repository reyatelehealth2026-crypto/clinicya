<?php
/**
 * admin/tenant-onboard.php — Platform Owner guided onboarding wizard for a
 * freshly-provisioned tenant.
 *
 * After admin/beta-signups.php (or switch-tenant.php) calls
 * TenantOnboardingService::provisionFromOwner(), the tenant DB exists but is
 * otherwise empty. This page walks the platform admin through the essentials
 * on the new tenant's behalf, step by step:
 *
 *   1. shop — shop profile (name / contact) → tenant.shop_settings
 *   2. line — first LINE OA channel (token/secret) → tenant.line_accounts
 *   3. ai   — AI settings (Gemini key + toggles) → tenant.ai_settings /
 *             tenant.ai_pharmacy_settings (skippable)
 *   4. done — summary + links into the tenant

 * Step order/validation/completion is delegated to classes/OnboardingWizard.php
 * (pure, DB-free, unit tested). This page only wires it to storage:
 *   - progress persisted in master.tenant_onboarding_progress (resumable)
 *   - actual settings written into the TENANT's own DB via Database::forTenant()
 *
 * Reuses TenantProvisioning (tenant existence / db name) — does NOT duplicate
 * provisioning logic, which lives in TenantOnboardingService/TenantProvisioning.
 *
 * Auth: requires $_SESSION['platform_user_id'] (super admin), same gate as
 * every other admin/*.php platform page.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';
require_once __DIR__ . '/../classes/OnboardingWizard.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>403</title>'
       . '<body style="font-family:sans-serif;padding:40px">'
       . '<h1>403 — Platform Owner only</h1>'
       . '<p><a href="/admin/platform-login.php">Sign in</a></p></body></html>';
    exit;
}

$platformUserId = (int) $_SESSION['platform_user_id'];
$platformDb     = Database::platform()->getConnection();
$h              = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$tenantId = (int) ($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);

$tenantStmt = $platformDb->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
$tenantStmt->execute([$tenantId]);
$tenant = $tenantId > 0 ? ($tenantStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

if (!$tenant) {
    require_once __DIR__ . '/../includes/platform_shell.php';
    platform_shell_top('tenants', 'ไม่พบร้าน', '');
    echo '<div class="pf-card pf-card-pad text-center text-slate-500">'
       . '<i class="fas fa-triangle-exclamation text-4xl text-amber-400 mb-3"></i>'
       . '<p>ไม่พบ tenant_id ที่ระบุ — กลับไปที่ <a href="/admin/switch-tenant.php" class="text-emerald-700 font-semibold">รายชื่อร้านค้า</a></p>'
       . '</div>';
    platform_shell_bottom();
    exit;
}

// ---------------------------------------------------------------------------
// Progress persistence (master.tenant_onboarding_progress) — resumable.
// ---------------------------------------------------------------------------
function reya_onboard_load_progress(PDO $platformDb, int $tenantId): OnboardingWizard
{
    try {
        $s = $platformDb->prepare('SELECT progress_json FROM tenant_onboarding_progress WHERE tenant_id = ? LIMIT 1');
        $s->execute([$tenantId]);
        $json = $s->fetchColumn();
        return OnboardingWizard::fromJson($json !== false ? (string) $json : null);
    } catch (\Throwable $e) {
        // Migration not applied yet — fail open with a blank wizard.
        return OnboardingWizard::fromJson(null);
    }
}

function reya_onboard_save_progress(PDO $platformDb, int $tenantId, OnboardingWizard $wizard): void
{
    try {
        $platformDb->prepare(
            'INSERT INTO tenant_onboarding_progress (tenant_id, progress_json)
                VALUES (:tid, :json)
             ON DUPLICATE KEY UPDATE progress_json = VALUES(progress_json)'
        )->execute([':tid' => $tenantId, ':json' => $wizard->toJson()]);
    } catch (\Throwable $e) {
        error_log('[tenant-onboard] progress save failed for tenant ' . $tenantId . ': ' . $e->getMessage());
    }
}

$wizard = reya_onboard_load_progress($platformDb, $tenantId);

// ---------------------------------------------------------------------------
// Tenant DB connection (where the actual settings live).
// ---------------------------------------------------------------------------
try {
    $tenantPdo = Database::forTenant($tenantId)->getConnection();
} catch (\Throwable $e) {
    require_once __DIR__ . '/../includes/platform_shell.php';
    platform_shell_top('tenants', $tenant['display_name'], 'ไม่พบ database ของร้าน');
    echo '<div class="pf-card pf-card-pad text-red-700">เชื่อมต่อ database ร้านไม่สำเร็จ: ' . $h($e->getMessage()) . '</div>';
    platform_shell_bottom();
    exit;
}

$flash = null;
$requestedStep = (string) ($_GET['step'] ?? $_POST['step'] ?? ($wizard->currentStep() ?? 'done'));
if (!$wizard->isValidStep($requestedStep)) {
    $requestedStep = $wizard->currentStep() ?? 'done';
}

// ---------------------------------------------------------------------------
// POST — save current step
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step   = (string) ($_POST['step'] ?? '');
    $action = (string) ($_POST['form_action'] ?? 'save');

    if (!$wizard->isValidStep($step)) {
        $flash = ['type' => 'error', 'msg' => 'ขั้นตอนไม่ถูกต้อง'];
    } elseif ($action === 'skip' && $wizard->isSkippable($step)) {
        $wizard = $wizard->markSkipped($step);
        reya_onboard_save_progress($platformDb, $tenantId, $wizard);
        header('Location: /admin/tenant-onboard.php?tenant_id=' . $tenantId . '&step=' . ($wizard->currentStep() ?? 'done'));
        exit;
    } else {
        $missing = $wizard->validate($step, $_POST);
        if (!empty($missing)) {
            $flash = ['type' => 'error', 'msg' => 'กรุณากรอกให้ครบ: ' . implode(', ', $missing)];
            $requestedStep = $step;
        } else {
            try {
                switch ($step) {
                    case 'shop':
                        $exists = $tenantPdo->prepare('SELECT id FROM shop_settings WHERE line_account_id IS NULL LIMIT 1');
                        $exists->execute();
                        $rowId = $exists->fetchColumn();
                        $fields = [
                            'shop_name'     => trim((string) $_POST['shop_name']),
                            'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
                            'shop_address'  => trim((string) ($_POST['shop_address'] ?? '')),
                            'shop_email'    => trim((string) ($_POST['shop_email'] ?? '')),
                        ];
                        if ($rowId) {
                            $tenantPdo->prepare(
                                'UPDATE shop_settings SET shop_name = :shop_name, contact_phone = :contact_phone,
                                    shop_address = :shop_address, shop_email = :shop_email
                                 WHERE id = :id'
                            )->execute($fields + [':id' => $rowId]);
                        } else {
                            $tenantPdo->prepare(
                                'INSERT INTO shop_settings (line_account_id, shop_name, contact_phone, shop_address, shop_email)
                                 VALUES (NULL, :shop_name, :contact_phone, :shop_address, :shop_email)'
                            )->execute($fields);
                        }
                        break;

                    case 'line':
                        $manager = new LineAccountManager($tenantPdo);
                        $existingId = (int) $tenantPdo->query('SELECT id FROM line_accounts ORDER BY id ASC LIMIT 1')->fetchColumn();
                        $lineData = [
                            'name'                  => trim((string) ($_POST['name'] ?? $tenant['display_name'])),
                            'channel_id'            => trim((string) $_POST['channel_id']),
                            'channel_secret'        => trim((string) $_POST['channel_secret']),
                            'channel_access_token'  => trim((string) $_POST['channel_access_token']),
                            'is_default'            => 1,
                            'is_active'             => 1,
                        ];
                        if ($existingId > 0) {
                            $manager->updateAccount($existingId, $lineData);
                        } else {
                            $manager->createAccount($lineData);
                        }
                        break;

                    case 'ai':
                        $lineAccountId = (int) $tenantPdo->query('SELECT id FROM line_accounts ORDER BY id ASC LIMIT 1')->fetchColumn() ?: null;
                        $tenantPdo->prepare(
                            'INSERT INTO ai_settings (line_account_id, is_enabled, gemini_api_key, model)
                                VALUES (:la, 1, :key, :model)
                             ON DUPLICATE KEY UPDATE
                                is_enabled = 1, gemini_api_key = VALUES(gemini_api_key), model = VALUES(model)'
                        )->execute([
                            ':la'    => $lineAccountId,
                            ':key'   => trim((string) $_POST['gemini_api_key']),
                            ':model' => trim((string) ($_POST['model'] ?? 'gemini-flash-latest')),
                        ]);
                        // ai_pharmacy_settings toggles (require_pharmacist_approval etc.)
                        $tenantPdo->prepare(
                            'INSERT INTO ai_pharmacy_settings (line_account_id, triage_enabled, require_pharmacist_approval)
                                VALUES (:la, 1, :approval)
                             ON DUPLICATE KEY UPDATE
                                triage_enabled = 1, require_pharmacist_approval = VALUES(require_pharmacist_approval)'
                        )->execute([
                            ':la'       => $lineAccountId,
                            ':approval' => isset($_POST['require_pharmacist_approval']) ? 1 : 0,
                        ]);
                        break;

                    case 'done':
                        // No data to persist — marking 'done' finishes the wizard.
                        break;
                }

                $wizard = $wizard->markCompleted($step);
                reya_onboard_save_progress($platformDb, $tenantId, $wizard);
                header('Location: /admin/tenant-onboard.php?tenant_id=' . $tenantId . '&step=' . ($wizard->currentStep() ?? 'done'));
                exit;
            } catch (\Throwable $e) {
                $flash = ['type' => 'error', 'msg' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()];
                $requestedStep = $step;
                error_log('[tenant-onboard] save failed tenant=' . $tenantId . ' step=' . $step . ': ' . $e->getMessage());
            }
        }
    }
}

$step = $requestedStep;

// ---------------------------------------------------------------------------
// Prefill for the active step (best-effort — tables may still be empty).
// ---------------------------------------------------------------------------
$prefill = [];
try {
    if ($step === 'shop') {
        $s = $tenantPdo->query('SELECT * FROM shop_settings WHERE line_account_id IS NULL LIMIT 1');
        $prefill = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($step === 'line') {
        $s = $tenantPdo->query('SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1');
        $prefill = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($step === 'ai') {
        $s = $tenantPdo->query('SELECT * FROM ai_settings ORDER BY id ASC LIMIT 1');
        $prefill = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable $e) {
    $prefill = [];
}

$stepMeta = [
    'shop' => ['label' => 'ข้อมูลร้าน', 'icon' => 'fa-store'],
    'line' => ['label' => 'เชื่อม LINE OA', 'icon' => 'fa-line'],
    'ai'   => ['label' => 'ตั้งค่า AI', 'icon' => 'fa-brain'],
    'done' => ['label' => 'เสร็จสิ้น', 'icon' => 'fa-circle-check'],
];

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top(
    'tenants',
    'Onboarding: ' . $tenant['display_name'],
    'tenant #' . $tenantId . ' · ' . $tenant['slug'] . '.re-ya.com'
);
?>
<div class="max-w-3xl mx-auto">
    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
            <?= $h($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Step progress -->
    <div class="flex items-center justify-between mb-6">
        <?php foreach ($wizard->steps() as $i => $s): ?>
            <?php
            $isDone   = $wizard->isStepCompleted($s);
            $isActive = $s === $step;
            $reachable = $wizard->canEnterStep($s);
            ?>
            <div class="flex items-center flex-1">
                <a href="<?= $reachable ? ('/admin/tenant-onboard.php?tenant_id=' . $tenantId . '&step=' . $s) : '#' ?>"
                   class="flex flex-col items-center gap-1 <?= $reachable ? '' : 'pointer-events-none opacity-40' ?>">
                    <span class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold
                        <?= $isDone ? 'bg-emerald-600 text-white' : ($isActive ? 'bg-emerald-100 text-emerald-700 border-2 border-emerald-600' : 'bg-slate-100 text-slate-400') ?>">
                        <?php if ($isDone): ?><i class="fas fa-check"></i><?php else: ?><?= $i + 1 ?><?php endif; ?>
                    </span>
                    <span class="text-xs font-medium <?= $isActive ? 'text-emerald-700' : 'text-slate-500' ?>"><?= $h($stepMeta[$s]['label']) ?></span>
                </a>
                <?php if ($i < count($wizard->steps()) - 1): ?>
                    <div class="flex-1 h-0.5 <?= $isDone ? 'bg-emerald-600' : 'bg-slate-200' ?> mx-2"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="pf-card pf-card-pad">
    <?php if ($step === 'shop'): ?>
        <h2 class="text-lg font-bold text-slate-900 mb-1"><i class="fas fa-store text-emerald-600 mr-2"></i>ข้อมูลร้าน</h2>
        <p class="text-sm text-slate-500 mb-4">ชื่อร้านและข้อมูลติดต่อพื้นฐานที่ลูกค้าจะเห็น</p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <input type="hidden" name="step" value="shop">
            <label class="block">
                <span class="text-xs font-medium text-slate-600">ชื่อร้าน <span class="text-red-500">*</span></span>
                <input type="text" name="shop_name" required maxlength="255"
                       value="<?= $h($prefill['shop_name'] ?? $tenant['display_name']) ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">เบอร์โทรติดต่อ</span>
                <input type="text" name="contact_phone" maxlength="20"
                       value="<?= $h($prefill['contact_phone'] ?? $tenant['owner_phone'] ?? '') ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">อีเมลร้าน</span>
                <input type="email" name="shop_email" maxlength="255"
                       value="<?= $h($prefill['shop_email'] ?? $tenant['owner_email'] ?? '') ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">ที่อยู่ร้าน</span>
                <textarea name="shop_address" rows="2"
                          class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= $h($prefill['shop_address'] ?? '') ?></textarea>
            </label>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 rounded-lg text-sm">
                บันทึก &amp; ถัดไป <i class="fas fa-arrow-right ml-1"></i>
            </button>
        </form>

    <?php elseif ($step === 'line'): ?>
        <h2 class="text-lg font-bold text-slate-900 mb-1"><i class="fab fa-line text-emerald-600 mr-2"></i>เชื่อม LINE Official Account</h2>
        <p class="text-sm text-slate-500 mb-4">
            Webhook URL: <code class="bg-slate-100 px-2 py-0.5 rounded text-xs">https://<?= $h($tenant['slug']) ?>.re-ya.com/webhook.php?account=1</code>
        </p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <input type="hidden" name="step" value="line">
            <label class="block">
                <span class="text-xs font-medium text-slate-600">ชื่อบัญชี</span>
                <input type="text" name="name" maxlength="255" value="<?= $h($prefill['name'] ?? $tenant['display_name']) ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Channel ID <span class="text-red-500">*</span></span>
                <input type="text" name="channel_id" required maxlength="100" value="<?= $h($prefill['channel_id'] ?? '') ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Channel Secret <span class="text-red-500">*</span></span>
                <input type="text" name="channel_secret" required maxlength="100" value="<?= $h($prefill['channel_secret'] ?? '') ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Channel Access Token <span class="text-red-500">*</span></span>
                <textarea name="channel_access_token" required rows="3"
                          class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= $h($prefill['channel_access_token'] ?? '') ?></textarea>
            </label>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 rounded-lg text-sm">
                บันทึก &amp; ถัดไป <i class="fas fa-arrow-right ml-1"></i>
            </button>
        </form>

    <?php elseif ($step === 'ai'): ?>
        <h2 class="text-lg font-bold text-slate-900 mb-1"><i class="fas fa-brain text-emerald-600 mr-2"></i>ตั้งค่า AI (ไม่บังคับ)</h2>
        <p class="text-sm text-slate-500 mb-4">เชื่อม Gemini API key เพื่อเปิดใช้ AI ตอบแชท — ข้ามได้และมาตั้งค่าทีหลัง</p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
            <input type="hidden" name="step" value="ai">
            <label class="block">
                <span class="text-xs font-medium text-slate-600">Gemini API Key <span class="text-red-500">*</span></span>
                <input type="text" name="gemini_api_key" required maxlength="255" value="<?= $h($prefill['gemini_api_key'] ?? '') ?>"
                       class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-emerald-500">
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="require_pharmacist_approval" value="1" checked
                       class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                ต้องให้เภสัชกรอนุมัติก่อนแนะนำสินค้า (แนะนำ)
            </label>
            <div class="flex gap-2">
                <button type="submit" formaction="/admin/tenant-onboard.php" name="form_action" value="save"
                        class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 rounded-lg text-sm">
                    บันทึก &amp; ถัดไป <i class="fas fa-arrow-right ml-1"></i>
                </button>
                <button type="submit" name="form_action" value="skip" formnovalidate
                        class="px-4 py-2.5 text-sm text-slate-500 hover:text-slate-800 border border-slate-200 rounded-lg">
                    ข้ามขั้นนี้
                </button>
            </div>
        </form>

    <?php else: /* done */ ?>
        <div class="text-center py-6">
            <?php if (!$wizard->isStepCompleted('done')): ?>
                <i class="fas fa-party-horn text-5xl text-emerald-500 mb-4"></i>
                <h2 class="text-xl font-bold text-slate-900 mb-2">พร้อมเปิดใช้งาน!</h2>
                <p class="text-sm text-slate-500 mb-6">ตั้งค่าเบื้องต้นครบแล้ว — กด "เสร็จสิ้น" เพื่อปิดการตั้งค่า</p>
                <form method="POST">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <input type="hidden" name="step" value="done">
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 px-8 rounded-lg text-sm">
                        <i class="fas fa-check mr-1"></i> เสร็จสิ้น
                    </button>
                </form>
            <?php else: ?>
                <i class="fas fa-circle-check text-5xl text-emerald-500 mb-4"></i>
                <h2 class="text-xl font-bold text-slate-900 mb-2">ตั้งค่าเสร็จสมบูรณ์ (<?= $wizard->completionPercent() ?>%)</h2>
                <div class="text-left bg-slate-50 rounded-xl p-4 my-4 text-sm space-y-1.5">
                    <div><span class="text-slate-500">ร้าน:</span> <strong><?= $h($tenant['display_name']) ?></strong></div>
                    <div><span class="text-slate-500">URL:</span>
                        <a href="https://<?= $h($tenant['slug']) ?>.re-ya.com/" target="_blank" class="text-emerald-700 font-medium">
                            <?= $h($tenant['slug']) ?>.re-ya.com <i class="fas fa-external-link-alt text-[10px]"></i>
                        </a>
                    </div>
                    <div><span class="text-slate-500">LINE OA:</span> <?= $wizard->isStepCompleted('line') ? '✅ เชื่อมแล้ว' : '⏳ ยังไม่ได้เชื่อม' ?></div>
                    <div><span class="text-slate-500">AI:</span> <?= $wizard->wasSkipped('ai') ? '⏭️ ข้ามไว้' : ($wizard->isStepCompleted('ai') ? '✅ ตั้งค่าแล้ว' : '⏳ ยังไม่ได้ตั้งค่า') ?></div>
                </div>
                <div class="flex items-center justify-center gap-3">
                    <a href="/admin/tenant-detail.php?id=<?= $tenantId ?>" class="pf-btn pf-btn-dark">
                        <i class="fas fa-arrow-left mr-1"></i> กลับไปหน้ารายละเอียดร้าน
                    </a>
                    <a href="/admin/tenant-onboard.php?tenant_id=<?= $tenantId ?>&step=shop" class="text-sm text-slate-500 hover:text-slate-800">
                        แก้ไขขั้นตอนอีกครั้ง
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php platform_shell_bottom(); ?>
