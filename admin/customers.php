<?php
/**
 * admin/customers.php — Platform Owner view of all shops (customers/tenants).
 *
 * Card grid ranked by usage (most-active shop first), reading cached counts
 * from tenant_usage_snapshots (fast — no live cross-tenant queries on load).
 * Search + status chips + sort + pagination + "refresh usage" button.
 * Auth: requires $_SESSION['platform_user_id'].
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/platform-billing-helpers.php';

if (empty($_SESSION['platform_user_id'])) {
    header('Location: /admin/platform-login.php');
    exit;
}

$db = Database::platform()->getConnection();
$h  = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$baseDomain = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';

// --- refresh usage cache (POST) -----------------------------------------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_usage') {
    try {
        $n = refreshAllTenantUsage($db);
        $flash = ['ok', "อัปเดตสถิติการใช้งาน {$n} ร้านแล้ว"];
    } catch (\Throwable $e) {
        $flash = ['error', 'อัปเดตสถิติไม่สำเร็จ: ' . $e->getMessage()];
    }
}

// --- filters / sort / page ----------------------------------------------
$VALID_STATUS = ['active', 'pending_setup', 'suspended', 'terminated'];
$fStatus = (string) ($_GET['status'] ?? '');
$q       = trim((string) ($_GET['q'] ?? ''));
$sort    = (string) ($_GET['sort'] ?? 'usage');
// each sort: [order-by expression]. NULL snapshots always sink to the bottom.
$SORTS = [
    'usage'    => '(us.num_users IS NULL), us.num_users DESC',
    'messages' => '(us.num_messages IS NULL), us.num_messages DESC',
    'orders'   => '(us.num_orders IS NULL), us.num_orders DESC',
    'newest'   => 't.id DESC',
    'name'     => 't.display_name ASC',
];
$orderSql = $SORTS[$sort] ?? $SORTS['usage'];

$perPage = 24;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where = [];
$params = [];
if (in_array($fStatus, $VALID_STATUS, true)) {
    $where[] = 't.status = ?';
    $params[] = $fStatus;
}
if ($q !== '') {
    $where[] = '(t.display_name LIKE ? OR t.slug LIKE ? OR t.owner_name LIKE ? OR t.owner_email LIKE ? OR t.owner_phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$cstmt = $db->prepare("SELECT COUNT(*) FROM tenants t {$whereSql}");
$cstmt->execute($params);
$totalMatch = (int) $cstmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalMatch / $perPage));

// main rows — JOIN subscription + usage snapshot; fall back if tables absent
$rows = [];
$fullQuery = "SELECT t.id, t.slug, t.display_name, t.status, t.owner_name, t.owner_phone, t.created_at,
        ts.amount_thb sub_amount, ts.billing_cycle,
        us.num_users, us.num_messages, us.num_orders, us.num_products, us.num_line_accounts,
        us.last_message_at, us.computed_at
    FROM tenants t
    LEFT JOIN tenant_subscriptions ts ON ts.tenant_id = t.id
    LEFT JOIN tenant_usage_snapshots us ON us.tenant_id = t.id
    {$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}";
try {
    $stmt = $db->prepare($fullQuery);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $stmt = $db->prepare("SELECT t.id, t.slug, t.display_name, t.status, t.owner_name, t.owner_phone, t.created_at
        FROM tenants t {$whereSql} ORDER BY t.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$byStatus = [];
try {
    $byStatus = $db->query("SELECT status, COUNT(*) n FROM tenants GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (\Throwable $e) {}
$totalAll = array_sum(array_map('intval', $byStatus));

// latest usage-cache time (for the "updated X ago" hint)
$cacheAge = null;
try { $cacheAge = $db->query("SELECT MAX(computed_at) FROM tenant_usage_snapshots")->fetchColumn() ?: null; } catch (\Throwable $e) {}

$STATUS_LABEL = ['active' => 'ใช้งาน', 'pending_setup' => 'รออนุมัติ', 'suspended' => 'ระงับ', 'terminated' => 'ปิด'];

$qs = static function (array $over) use ($q, $fStatus, $sort, $page): string {
    $base = ['q' => $q, 'status' => $fStatus, 'sort' => $sort, 'page' => $page];
    $merged = array_filter(array_merge($base, $over), fn ($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($merged);
};
$nf = static fn ($v) => $v === null ? '—' : number_format((int) $v);

$actions = '<form method="POST" class="inline">'
    . '<input type="hidden" name="action" value="refresh_usage">'
    . '<button class="pf-btn pf-btn-ghost" title="ดึงสถิติล่าสุดจากทุกร้าน"><i class="fas fa-rotate"></i> อัปเดตสถิติ</button>'
    . '</form>'
    . '<a href="/admin/switch-tenant.php" class="pf-btn pf-btn-primary"><i class="fas fa-plus"></i> เพิ่มร้าน</a>';

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('customers', 'ลูกค้า / ร้านค้า', number_format($totalAll) . ' ร้านในระบบ', $actions);
?>

<?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-xl text-sm <?= $flash[0] === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-800' ?>"><?= $h($flash[1]) ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="pf-card pf-card-pad mb-4">
    <form method="GET" class="flex flex-col lg:flex-row lg:items-center gap-3">
        <div class="relative flex-1 min-w-[220px]">
            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input type="text" name="q" value="<?= $h($q) ?>" placeholder="ค้นชื่อร้าน / slug / เจ้าของ / อีเมล / โทร" class="pf-input" style="padding-left:2.2rem;">
        </div>
        <div class="flex items-center gap-2">
            <select name="sort" onchange="this.form.submit()" class="pf-input" style="width:auto;">
                <option value="usage" <?= $sort === 'usage' ? 'selected' : '' ?>>เรียงตามลูกค้า (มาก→น้อย)</option>
                <option value="messages" <?= $sort === 'messages' ? 'selected' : '' ?>>เรียงตามข้อความ</option>
                <option value="orders" <?= $sort === 'orders' ? 'selected' : '' ?>>เรียงตามออเดอร์</option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>ใหม่สุด</option>
                <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>ชื่อ A→Z</option>
            </select>
            <button class="pf-btn pf-btn-dark"><i class="fas fa-magnifying-glass"></i> ค้นหา</button>
        </div>
    </form>
    <div class="flex items-center gap-2 mt-3 flex-wrap">
        <a href="<?= $h($qs(['status' => '', 'page' => 1])) ?>" class="pf-chip <?= $fStatus === '' ? 'on' : '' ?>">ทั้งหมด <span class="pf-chip-n"><?= number_format($totalAll) ?></span></a>
        <?php foreach (['active', 'pending_setup', 'suspended', 'terminated'] as $st): ?>
        <a href="<?= $h($qs(['status' => $st, 'page' => 1])) ?>" class="pf-chip <?= $fStatus === $st ? 'on' : '' ?>"><?= $h($STATUS_LABEL[$st]) ?> <span class="pf-chip-n"><?= number_format((int) ($byStatus[$st] ?? 0)) ?></span></a>
        <?php endforeach; ?>
        <?php if ($cacheAge): ?><span class="text-[11px] text-slate-400 ml-auto"><i class="fas fa-clock mr-1"></i>สถิติอัปเดต <?= $h(date('d M H:i', strtotime((string) $cacheAge))) ?></span><?php endif; ?>
    </div>
</div>

<!-- Card grid (ranked by usage) -->
<?php if (empty($rows)): ?>
    <div class="pf-card pf-empty"><i class="fas fa-store-slash text-3xl mb-3 block text-slate-300"></i>ไม่พบร้านตามเงื่อนไข</div>
<?php else: ?>
<div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($rows as $i => $r):
        $rank = $offset + $i + 1;
        $usageSorted = in_array($sort, ['usage', 'messages', 'orders'], true);
        $statRow = [
            ['fa-users', 'ลูกค้า', $r['num_users'] ?? null, 'usage'],
            ['fa-message', 'ข้อความ', $r['num_messages'] ?? null, 'messages'],
            ['fa-cart-shopping', 'ออเดอร์', $r['num_orders'] ?? null, 'orders'],
            ['fa-box', 'สินค้า', $r['num_products'] ?? null, 'products'],
        ];
    ?>
    <a href="/admin/tenant-detail.php?id=<?= (int) $r['id'] ?>" class="pf-card pf-card-pad pf-int block relative">
        <?php if ($usageSorted): ?><span class="absolute top-3 right-3 text-[11px] font-bold text-slate-300 tnum">#<?= $rank ?></span><?php endif; ?>
        <div class="flex items-start gap-3">
            <div class="w-11 h-11 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-lg font-extrabold flex-shrink-0"><?= $h(mb_substr($r['display_name'], 0, 1)) ?></div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-800 truncate"><?= $h($r['display_name']) ?></span>
                    <span class="pf-pill" data-st="<?= $h($r['status']) ?>"><?= $h($STATUS_LABEL[$r['status']] ?? $r['status']) ?></span>
                </div>
                <div class="text-xs text-emerald-600 font-mono truncate"><?= $h($r['slug']) ?>.<?= $h($baseDomain) ?></div>
                <div class="text-[11px] text-slate-400 mt-0.5 flex items-center gap-2 flex-wrap">
                    <?php if (!empty($r['owner_name'])): ?><span><i class="fas fa-user text-[10px]"></i> <?= $h($r['owner_name']) ?></span><?php endif; ?>
                    <?php if (!empty($r['sub_amount'])): ?><span class="tnum">฿<?= number_format((float) $r['sub_amount']) ?>/<?= $r['billing_cycle'] === 'yearly' ? 'ปี' : ($r['billing_cycle'] === 'quarterly' ? '3ด.' : 'ด.') ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <!-- usage stats -->
        <div class="grid grid-cols-4 gap-2 mt-4 pt-3 border-t border-slate-100">
            <?php foreach ($statRow as [$ic, $lab, $val, $key]): $hot = $usageSorted && $sort === $key; ?>
            <div class="text-center <?= $hot ? '' : '' ?>">
                <div class="text-[10px] text-slate-400"><i class="fas <?= $ic ?> <?= $hot ? 'text-emerald-500' : '' ?>"></i> <?= $lab ?></div>
                <div class="font-bold tnum <?= $hot ? 'text-emerald-600' : 'text-slate-700' ?>" style="font-size:.95rem"><?= $nf($val) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($r['last_message_at'])): ?>
        <div class="text-[11px] text-slate-400 mt-3"><i class="fas fa-clock mr-1"></i>ใช้งานล่าสุด <?= $h(date('d M Y', strtotime((string) $r['last_message_at']))) ?></div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- pagination -->
<div class="flex items-center justify-between gap-3 flex-wrap mt-5">
    <span class="text-xs text-slate-400">แสดง <span class="tnum"><?= $totalMatch ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $totalMatch) ?></span> จาก <span class="tnum"><?= number_format($totalMatch) ?></span> ร้าน</span>
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center gap-1">
        <a href="<?= $h($qs(['page' => max(1, $page - 1)])) ?>" class="pf-iconbtn <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" aria-label="ก่อนหน้า"><i class="fas fa-chevron-left text-xs"></i></a>
        <span class="text-xs text-slate-500 px-2 tnum">หน้า <?= $page ?> / <?= $totalPages ?></span>
        <a href="<?= $h($qs(['page' => min($totalPages, $page + 1)])) ?>" class="pf-iconbtn <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>" aria-label="ถัดไป"><i class="fas fa-chevron-right text-xs"></i></a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php platform_shell_bottom(); ?>
