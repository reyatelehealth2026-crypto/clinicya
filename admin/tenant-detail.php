<?php
/**
 * admin/tenant-detail.php — Platform Owner per-tenant management.
 *
 * Shows usage (LINE accounts, customers, messages, products, points), shop
 * revenue summary (read from the tenant's own DB), subscription/billing
 * (manual tracker), and lets the owner change status (approve/suspend/resume/
 * terminate — NO db drop) and record subscription payments. Every mutation is
 * audited to super_admin_audit. Auth: $_SESSION['platform_user_id'].
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';
require_once __DIR__ . '/../classes/SelfServeProvisioning.php';
require_once __DIR__ . '/../includes/platform-billing-helpers.php';

if (empty($_SESSION['platform_user_id'])) {
    header('Location: /admin/platform-login.php');
    exit;
}
$platformUserId = (int) $_SESSION['platform_user_id'];
$db = Database::platform()->getConnection();
$h  = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$baseDomain = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';

$tenantId = (int) ($_GET['id'] ?? 0);

$loadTenant = static function (PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT t.*, p.display_name AS plan_name, p.price_monthly_thb AS plan_price
        FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id WHERE t.id = ? LIMIT 1");
    try { $st->execute([$id]); } catch (\Throwable $e) {
        // plans table may be absent → retry without join
        $st = $db->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $st->execute([$id]);
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

$tenant = $tenantId > 0 ? $loadTenant($db, $tenantId) : null;

// Audit writer (mirrors switch-tenant.php pattern).
$writeAudit = static function (string $action, array $meta = []) use ($db, $platformUserId, $tenantId): void {
    try {
        $db->prepare('INSERT INTO super_admin_audit
            (platform_user_id, tenant_id, action, ip_address, user_agent, request_method, request_uri, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
           ->execute([
               $platformUserId, $tenantId, $action,
               (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
               substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
               (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
               substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
               json_encode($meta, JSON_UNESCAPED_UNICODE),
           ]);
    } catch (\Throwable $e) {
        error_log('[tenant-detail] audit: ' . $e->getMessage());
    }
};

// ---------------------------------------------------------------------------
// POST — status mutations + billing
// ---------------------------------------------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenant) {
    $action = (string) ($_POST['action'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        switch ($action) {
            case 'approve':
                SelfServeProvisioning::approve($db, $tenantId, $platformUserId);
                $writeAudit('approve_tenant');
                $flash = ['ok', 'อนุมัติร้านแล้ว — เปิดใช้งานได้ทันที'];
                break;
            case 'suspend':
                TenantProvisioning::suspend($tenantId);
                $writeAudit('suspend_tenant', ['reason' => $reason]);
                $flash = ['ok', 'ระงับร้านแล้ว (ข้อมูลยังอยู่)'];
                break;
            case 'resume':
                TenantProvisioning::resume($tenantId);
                $writeAudit('resume_tenant', ['reason' => $reason]);
                $flash = ['ok', 'เปิดใช้งานร้านอีกครั้งแล้ว'];
                break;
            case 'terminate':
                TenantProvisioning::terminate($tenantId, false); // never drop DB here
                $writeAudit('terminate_tenant', ['reason' => $reason]);
                $flash = ['ok', 'ยกเลิกร้านแล้ว (เก็บข้อมูลไว้ ไม่ลบฐานข้อมูล)'];
                break;
            case 'record_payment':
                $amount  = (float) ($_POST['amount'] ?? 0);
                $paid    = (string) ($_POST['paid_date'] ?? date('Y-m-d'));
                $method  = (string) ($_POST['method'] ?? 'bank_transfer');
                $refn    = trim((string) ($_POST['reference'] ?? ''));
                $note    = trim((string) ($_POST['note'] ?? ''));
                if ($amount <= 0) {
                    $flash = ['error', 'กรุณาระบุยอดเงินที่ถูกต้อง'];
                    break;
                }
                $db->prepare('INSERT INTO tenant_payments
                    (tenant_id, paid_date, amount_thb, method, reference, note, recorded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
                   ->execute([$tenantId, $paid, $amount, $method, $refn ?: null, $note ?: null, $platformUserId]);
                // advance subscription next_due_date by one cycle
                $sub = $db->prepare('SELECT next_due_date, billing_cycle FROM tenant_subscriptions WHERE tenant_id = ?');
                $sub->execute([$tenantId]);
                $subRow = $sub->fetch(PDO::FETCH_ASSOC);
                if ($subRow) {
                    $cycle = (string) ($subRow['billing_cycle'] ?? 'monthly');
                    $base  = (string) ($subRow['next_due_date'] ?? $paid);
                    $newDue = addBillingCycle($base < $paid ? $paid : $base, $cycle);
                    $db->prepare('UPDATE tenant_subscriptions SET next_due_date = ?, last_paid_date = ? WHERE tenant_id = ?')
                       ->execute([$newDue, $paid, $tenantId]);
                }
                $writeAudit('record_payment', ['amount' => $amount, 'method' => $method]);
                $flash = ['ok', 'บันทึกการชำระเงิน ฿' . number_format($amount) . ' แล้ว'];
                break;
            case 'save_subscription':
                $amount = (float) ($_POST['amount_thb'] ?? 0);
                $cycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'quarterly', 'yearly'], true) ? $_POST['billing_cycle'] : 'monthly';
                $due    = (string) ($_POST['next_due_date'] ?? date('Y-m-d'));
                $snote  = trim((string) ($_POST['sub_note'] ?? ''));
                $start  = date('Y-m-d', strtotime((string) ($tenant['created_at'] ?? 'now')));
                $db->prepare('INSERT INTO tenant_subscriptions (tenant_id, start_date, billing_cycle, next_due_date, amount_thb, note)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE billing_cycle = VALUES(billing_cycle), next_due_date = VALUES(next_due_date),
                        amount_thb = VALUES(amount_thb), note = VALUES(note)')
                   ->execute([$tenantId, $start, $cycle, $due, $amount, $snote ?: null]);
                $writeAudit('save_subscription', ['amount' => $amount, 'cycle' => $cycle]);
                $flash = ['ok', 'บันทึกเงื่อนไข subscription แล้ว'];
                break;
        }
    } catch (\Throwable $e) {
        $flash = ['error', 'ทำรายการไม่สำเร็จ: ' . $e->getMessage()];
    }
    $tenant = $loadTenant($db, $tenantId); // reload after mutation
}

// ---------------------------------------------------------------------------
// Read-side: usage, revenue, billing, audit
// ---------------------------------------------------------------------------
$usage = ['num_users' => null, 'num_line_accounts' => null, 'num_messages' => null, 'num_products' => null];
$points = null;
$revenue = null;
$tenantDbError = false;
if ($tenant) {
    try {
        $tpdo = Database::forTenant($tenantId)->getConnection();
        $usage = countTenantUsage($tpdo);
        try {
            $points = (int) $tpdo->query("SELECT COALESCE(SUM(points),0) FROM points_transactions WHERE type='earn' AND points > 0")->fetchColumn();
        } catch (\Throwable $e) { $points = null; }
        foreach (['transactions', 'orders'] as $ot) {
            try {
                $rv = $tpdo->query("SELECT COUNT(*) cnt, COALESCE(SUM(grand_total),0) total,
                    COALESCE(SUM(CASE WHEN payment_status='paid' THEN grand_total ELSE 0 END),0) paid
                    FROM `{$ot}`")->fetch(PDO::FETCH_ASSOC);
                if ($rv) { $revenue = $rv; break; }
            } catch (\Throwable $e) { /* try next */ }
        }
    } catch (\Throwable $e) {
        $tenantDbError = true; // DB unreachable (e.g. dropped)
    }
}

// subscription + payments (tables may not exist pre-migration)
$sub = null; $payments = [];
try {
    $s = $db->prepare("SELECT * FROM tenant_subscriptions WHERE tenant_id = ?");
    $s->execute([$tenantId]);
    $sub = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    $p = $db->prepare("SELECT tp.*, u.name recorder FROM tenant_payments tp
        LEFT JOIN platform_users u ON u.id = tp.recorded_by
        WHERE tp.tenant_id = ? ORDER BY tp.paid_date DESC, tp.id DESC LIMIT 20");
    $p->execute([$tenantId]);
    $payments = $p->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* billing not installed */ }
$payState = $sub ? paymentState($sub['next_due_date'] ?? null, date('Y-m-d')) : 'unknown';

// shop activity feed (login / points / LINE connect) for this tenant
$auditRows = [];
try {
    $a = $db->prepare("SELECT created_at, event_type, actor, detail FROM tenant_activity_log
        WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 15");
    $a->execute([$tenantId]);
    $auditRows = $a->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {}
$EVENT_LABEL = ['login' => 'เข้าสู่ระบบ', 'points_award' => 'แจกแต้ม', 'line_connect' => 'เชื่อม LINE'];
$EVENT_ICON  = ['login' => 'fa-right-to-bracket', 'points_award' => 'fa-star', 'line_connect' => 'fa-comments'];

$STATUS_LABEL = ['active' => 'ใช้งาน', 'pending_setup' => 'รออนุมัติ', 'suspended' => 'ระงับ', 'terminated' => 'ปิด'];
$PAY_LABEL = ['paid' => ['จ่ายแล้ว', 'active'], 'due' => ['ใกล้ครบกำหนด', 'pending_setup'], 'overdue' => ['เกินกำหนด', 'suspended'], 'unknown' => ['ยังไม่กำหนด', 'terminated']];
$METHOD_LABEL = ['bank_transfer' => 'โอนธนาคาร', 'promptpay' => 'พร้อมเพย์', 'credit_card' => 'บัตรเครดิต', 'cash' => 'เงินสด', 'other' => 'อื่นๆ'];

$relTime = static function ($ts): string {
    $diff = time() - strtotime((string) $ts);
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชม.ที่แล้ว';
    return floor($diff / 86400) . ' วันที่แล้ว';
};

$backBtn = '<a href="/admin/customers.php" class="pf-btn pf-btn-ghost"><i class="fas fa-arrow-left"></i> กลับ</a>';

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('customers', $tenant ? $tenant['display_name'] : 'ไม่พบร้าน', $tenant ? ('รายละเอียดร้าน #' . $tenantId) : '', $backBtn);

if (!$tenant) {
    echo '<div class="pf-card pf-empty"><i class="fas fa-store-slash text-4xl mb-3 block text-slate-300"></i>ไม่พบร้าน #' . (int) $tenantId . '</div>';
    platform_shell_bottom();
    exit;
}

$st = (string) $tenant['status'];
?>

<?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-xl text-sm <?= $flash[0] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
        <?= $h($flash[1]) ?>
    </div>
<?php endif; ?>

<!-- Header card -->
<div class="pf-card pf-card-pad mb-4">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="flex items-start gap-4 min-w-0">
            <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-2xl font-extrabold flex-shrink-0"><?= $h(mb_substr($tenant['display_name'], 0, 1)) ?></div>
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h2 class="text-xl font-extrabold text-slate-900"><?= $h($tenant['display_name']) ?></h2>
                    <span class="pf-pill" data-st="<?= $h($st) ?>"><?= $h($STATUS_LABEL[$st] ?? $st) ?></span>
                </div>
                <a href="https://<?= $h($tenant['slug']) ?>.<?= $h($baseDomain) ?>/" target="_blank" class="text-sm text-emerald-600 font-mono hover:underline"><?= $h($tenant['slug']) ?>.<?= $h($baseDomain) ?> <i class="fas fa-external-link-alt text-[10px]"></i></a>
                <div class="text-xs text-slate-500 mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
                    <?php if (!empty($tenant['owner_name'])): ?><span><i class="fas fa-user text-slate-400 mr-1"></i><?= $h($tenant['owner_name']) ?></span><?php endif; ?>
                    <?php if (!empty($tenant['owner_email'])): ?><span><i class="fas fa-envelope text-slate-400 mr-1"></i><?= $h($tenant['owner_email']) ?></span><?php endif; ?>
                    <?php if (!empty($tenant['owner_phone'])): ?><span class="tnum"><i class="fas fa-phone text-slate-400 mr-1"></i><?= $h($tenant['owner_phone']) ?></span><?php endif; ?>
                    <span><i class="fas fa-calendar text-slate-400 mr-1"></i>สร้าง <?= $h(date('d M Y', strtotime((string) $tenant['created_at']))) ?></span>
                    <?php if (!empty($tenant['plan_name'])): ?><span><i class="fas fa-layer-group text-slate-400 mr-1"></i><?= $h($tenant['plan_name']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <!-- status actions -->
        <div class="flex items-center gap-2 flex-wrap">
            <a href="/admin/switch-tenant.php" class="pf-btn pf-btn-ghost"><i class="fas fa-right-to-bracket"></i> เข้าจัดการ</a>
            <?php if ($st === 'pending_setup'): ?>
                <form method="POST" onsubmit="return confirm('อนุมัติเปิดร้านนี้?');"><input type="hidden" name="action" value="approve"><button class="pf-btn pf-btn-primary"><i class="fas fa-check"></i> อนุมัติ</button></form>
            <?php elseif ($st === 'active'): ?>
                <form method="POST" onsubmit="return confirm('ระงับร้านนี้ชั่วคราว? ลูกค้าจะเข้าเว็บไม่ได้จนกว่าจะเปิดใหม่');"><input type="hidden" name="action" value="suspend"><button class="pf-btn pf-btn-ghost" style="border-color:#fde68a;color:#b45309;"><i class="fas fa-pause"></i> ระงับ</button></form>
            <?php elseif ($st === 'suspended'): ?>
                <form method="POST" onsubmit="return confirm('เปิดใช้งานร้านนี้อีกครั้ง?');"><input type="hidden" name="action" value="resume"><button class="pf-btn pf-btn-primary"><i class="fas fa-play"></i> เปิดใช้งาน</button></form>
            <?php endif; ?>
            <?php if ($st !== 'terminated'): ?>
                <form method="POST" onsubmit="return confirm('ยกเลิกร้านนี้? (เก็บข้อมูลไว้ ไม่ลบฐานข้อมูล แต่ร้านจะเข้าใช้ไม่ได้)');"><input type="hidden" name="action" value="terminate"><button class="pf-btn pf-btn-danger"><i class="fas fa-ban"></i> ยกเลิก</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Usage stats -->
<?php if ($tenantDbError): ?>
    <div class="pf-card pf-card-pad mb-4 text-sm text-slate-400"><i class="fas fa-database mr-1"></i> เชื่อมต่อฐานข้อมูลร้านไม่ได้ (อาจถูกลบหรือยังไม่พร้อม)</div>
<?php else: ?>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-4">
    <?php
    $cards = [
        ['LINE ที่เชื่อม', $usage['num_line_accounts'], 'fa-comments', 'text-green-600'],
        ['ลูกค้า', $usage['num_users'], 'fa-users', 'text-blue-600'],
        ['ข้อความ', $usage['num_messages'], 'fa-message', 'text-cyan-600'],
        ['สินค้า', $usage['num_products'], 'fa-box', 'text-violet-600'],
        ['แต้มที่แจก', $points, 'fa-star', 'text-amber-600'],
    ];
    foreach ($cards as [$lab, $val, $ic, $col]): ?>
    <div class="pf-card pf-card-pad">
        <div class="flex items-center gap-2 text-xs text-slate-500"><i class="fas <?= $ic ?> <?= $col ?>"></i> <?= $lab ?></div>
        <div class="pf-kpi-fig mt-1" style="font-size:1.5rem"><?= $val === null ? '—' : number_format((int) $val) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-4 mb-4">
    <!-- Billing -->
    <div class="pf-card pf-card-pad lg:col-span-2">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-slate-800"><i class="fas fa-receipt text-slate-400 mr-1.5"></i> ค่าบริการ (Subscription)</h3>
            <span class="pf-pill" data-st="<?= $h($PAY_LABEL[$payState][1]) ?>"><?= $h($PAY_LABEL[$payState][0]) ?></span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div><div class="text-[11px] text-slate-400">ยอด/รอบ</div><div class="font-bold text-slate-800 tnum"><?= $sub ? '฿' . number_format((float) $sub['amount_thb']) : '—' ?></div></div>
            <div><div class="text-[11px] text-slate-400">รอบบิล</div><div class="font-semibold text-slate-700 text-sm"><?= $sub ? $h($sub['billing_cycle']) : '—' ?></div></div>
            <div><div class="text-[11px] text-slate-400">ครบกำหนด</div><div class="font-semibold text-slate-700 text-sm tnum"><?= $sub && $sub['next_due_date'] ? $h(date('d M Y', strtotime((string) $sub['next_due_date']))) : '—' ?></div></div>
            <div><div class="text-[11px] text-slate-400">จ่ายล่าสุด</div><div class="font-semibold text-slate-700 text-sm tnum"><?= $sub && !empty($sub['last_paid_date']) ? $h(date('d M Y', strtotime((string) $sub['last_paid_date']))) : '—' ?></div></div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <details class="inline-block">
                <summary class="pf-btn pf-btn-primary cursor-pointer list-none"><i class="fas fa-plus"></i> บันทึกการชำระเงิน</summary>
                <form method="POST" class="mt-3 p-4 bg-slate-50 rounded-xl grid sm:grid-cols-2 gap-3">
                    <input type="hidden" name="action" value="record_payment">
                    <div><label class="text-xs text-slate-500">ยอดเงิน (฿)</label><input type="number" step="0.01" min="0" name="amount" value="<?= $sub ? (float) $sub['amount_thb'] : '' ?>" required class="pf-input tnum"></div>
                    <div><label class="text-xs text-slate-500">วันที่จ่าย</label><input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" required class="pf-input"></div>
                    <div><label class="text-xs text-slate-500">วิธีชำระ</label><select name="method" class="pf-input"><?php foreach ($METHOD_LABEL as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select></div>
                    <div><label class="text-xs text-slate-500">อ้างอิง/สลิป</label><input type="text" name="reference" class="pf-input" placeholder="เลขอ้างอิง"></div>
                    <div class="sm:col-span-2 flex justify-end"><button class="pf-btn pf-btn-primary"><i class="fas fa-save"></i> บันทึก</button></div>
                </form>
            </details>
            <details class="inline-block">
                <summary class="pf-btn pf-btn-ghost cursor-pointer list-none"><i class="fas fa-pen"></i> ตั้งค่ารอบบิล</summary>
                <form method="POST" class="mt-3 p-4 bg-slate-50 rounded-xl grid sm:grid-cols-2 gap-3">
                    <input type="hidden" name="action" value="save_subscription">
                    <div><label class="text-xs text-slate-500">ยอด/รอบ (฿)</label><input type="number" step="0.01" min="0" id="subAmt" name="amount_thb" value="<?= $sub ? (float) $sub['amount_thb'] : 599 ?>" required class="pf-input tnum"></div>
                    <div><label class="text-xs text-slate-500">รอบบิล</label><select name="billing_cycle" id="subCyc" onchange="reyaFillPrice()" class="pf-input"><?php foreach (['monthly' => 'รายเดือน', 'quarterly' => 'ราย 3 เดือน', 'yearly' => 'รายปี'] as $k => $v): ?><option value="<?= $k ?>" <?= ($sub['billing_cycle'] ?? 'monthly') === $k ? 'selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
                    <div><label class="text-xs text-slate-500">ครบกำหนดถัดไป</label><input type="date" name="next_due_date" value="<?= $h($sub['next_due_date'] ?? date('Y-m-d', strtotime('+1 month'))) ?>" required class="pf-input"></div>
                    <div><label class="text-xs text-slate-500">หมายเหตุ</label><input type="text" name="sub_note" value="<?= $h($sub['note'] ?? '') ?>" class="pf-input"></div>
                    <div class="sm:col-span-2 flex justify-end"><button class="pf-btn pf-btn-primary"><i class="fas fa-save"></i> บันทึก</button></div>
                </form>
            </details>
        </div>
        <!-- payment history -->
        <?php if (!empty($payments)): ?>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-slate-100"><th class="pf-th">วันที่</th><th class="pf-th">ยอด</th><th class="pf-th">วิธี</th><th class="pf-th">อ้างอิง</th><th class="pf-th">บันทึกโดย</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($payments as $pm): ?>
                    <tr>
                        <td class="px-4 py-2.5 text-slate-600 tnum"><?= $h(date('d M Y', strtotime((string) $pm['paid_date']))) ?></td>
                        <td class="px-4 py-2.5 font-semibold text-slate-800 tnum">฿<?= number_format((float) $pm['amount_thb']) ?></td>
                        <td class="px-4 py-2.5 text-slate-500 text-xs"><?= $h($METHOD_LABEL[$pm['method']] ?? $pm['method']) ?></td>
                        <td class="px-4 py-2.5 text-slate-400 text-xs"><?= $h($pm['reference'] ?: '—') ?></td>
                        <td class="px-4 py-2.5 text-slate-400 text-xs"><?= $h($pm['recorder'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="mt-4 text-xs text-slate-400 text-center py-4">ยังไม่มีประวัติการชำระเงิน</div>
        <?php endif; ?>
    </div>

    <!-- Shop revenue + audit -->
    <div class="space-y-4">
        <div class="pf-card pf-card-pad">
            <h3 class="font-bold text-slate-800 mb-3"><i class="fas fa-cart-shopping text-slate-400 mr-1.5"></i> ยอดขายร้าน</h3>
            <?php if ($revenue): ?>
            <div class="space-y-2.5">
                <div class="flex items-center justify-between"><span class="text-sm text-slate-500">ออเดอร์ทั้งหมด</span><span class="font-bold text-slate-800 tnum"><?= number_format((int) $revenue['cnt']) ?></span></div>
                <div class="flex items-center justify-between"><span class="text-sm text-slate-500">ยอดขายรวม</span><span class="font-bold text-slate-800 tnum">฿<?= number_format((float) $revenue['total']) ?></span></div>
                <div class="flex items-center justify-between"><span class="text-sm text-slate-500">ชำระแล้ว</span><span class="font-bold text-emerald-600 tnum">฿<?= number_format((float) $revenue['paid']) ?></span></div>
            </div>
            <p class="text-[11px] text-slate-400 mt-3">ข้อมูลรายได้ของร้าน (ลูกค้าจ่ายให้ร้าน) — แยกจากค่าบริการแพลตฟอร์ม</p>
            <?php else: ?>
            <div class="text-xs text-slate-400 text-center py-4"><?= $tenantDbError ? 'เชื่อมต่อ DB ร้านไม่ได้' : 'ยังไม่มีออเดอร์' ?></div>
            <?php endif; ?>
        </div>
        <div class="pf-card pf-card-pad">
            <h3 class="font-bold text-slate-800 mb-3"><i class="fas fa-bolt text-slate-400 mr-1.5"></i> กิจกรรมร้านล่าสุด</h3>
            <?php if (!empty($auditRows)): ?>
            <div class="space-y-2.5">
                <?php foreach ($auditRows as $ar): $ev = (string) $ar['event_type']; ?>
                <div class="flex items-start gap-2 text-xs">
                    <i class="fas <?= $h($EVENT_ICON[$ev] ?? 'fa-bolt') ?> text-slate-400 mt-0.5"></i>
                    <div class="min-w-0">
                        <div class="text-slate-700"><?= $h($EVENT_LABEL[$ev] ?? $ev) ?><?= $ar['detail'] ? ' · ' . $h($ar['detail']) : '' ?></div>
                        <div class="text-slate-400"><?= $h($relTime($ar['created_at'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="text-xs text-slate-400 text-center py-4">ยังไม่มีกิจกรรม</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-fill the subscription amount from the standard REYA price list when the
// billing cycle changes: monthly ฿599 · quarterly ฿1,797 · yearly ฿5,990.
function reyaFillPrice(){
    var price={monthly:599,quarterly:1797,yearly:5990};
    var c=document.getElementById('subCyc'),a=document.getElementById('subAmt');
    if(c&&a&&price[c.value]!=null){a.value=price[c.value];}
}
</script>

<?php platform_shell_bottom(); ?>
