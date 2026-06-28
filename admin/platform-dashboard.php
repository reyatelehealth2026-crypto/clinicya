<?php
/**
 * admin/platform-dashboard.php — Platform Owner console home.
 *
 * "Data-Dense Dashboard": KPI cards with sparklines, tenant-growth line chart,
 * plan/MRR donut, beta funnel, recent-activity feed, recent tenants/leads.
 * All new queries are defensive (plans / super_admin_audit may not exist yet on
 * a given DB) and degrade to empty states. Auth: $_SESSION['platform_user_id'].
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['platform_user_id'])) {
    header('Location: /admin/platform-login.php');
    exit;
}

$db = Database::platform()->getConnection();
$h  = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

/** Build a left-to-right N-day series (oldest→today) from a [Y-m-d => n] map. */
$pf_series = static function (array $byDate, int $days): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = (int) ($byDate[date('Y-m-d', strtotime("-{$i} day"))] ?? 0);
    }
    return $out;
};
/** Run a query, return [] on any error (table missing etc.). */
$pf_safe = static function (string $sql) use ($db) {
    try {
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
};

// --- core counts ---------------------------------------------------------
$tStat = $db->query("SELECT COUNT(*) total, SUM(status='active') active,
    SUM(status='pending_setup') pending, SUM(status='suspended') suspended FROM tenants")->fetch(PDO::FETCH_ASSOC) ?: [];
$bStat = $db->query("SELECT COUNT(*) total, SUM(status='new') new, SUM(status='signed_up') signed FROM beta_signups")->fetch(PDO::FETCH_ASSOC) ?: [];

$activeCount  = (int) ($tStat['active'] ?? 0);
$pendingCount = (int) ($tStat['pending'] ?? 0);
$newBeta      = (int) ($bStat['new'] ?? 0);

// --- MRR + billing-cycle distribution (from tenant_subscriptions) --------
// MRR normalises every cycle to a monthly figure (yearly/12, quarterly/3).
$mrr = 0.0; $planDist = [];
try {
    $row = $db->query("SELECT COALESCE(SUM(
            CASE ts.billing_cycle WHEN 'yearly' THEN ts.amount_thb/12
                                  WHEN 'quarterly' THEN ts.amount_thb/3
                                  ELSE ts.amount_thb END),0) mrr
        FROM tenant_subscriptions ts JOIN tenants t ON t.id = ts.tenant_id
        WHERE t.status='active'")->fetch(PDO::FETCH_ASSOC);
    $mrr = (float) ($row['mrr'] ?? 0);
    $planDist = $db->query("SELECT ts.billing_cycle plan, COUNT(*) cnt, ROUND(AVG(ts.amount_thb)) price
        FROM tenant_subscriptions ts JOIN tenants t ON t.id = ts.tenant_id
        WHERE t.status='active' GROUP BY ts.billing_cycle ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cycleLabel = ['monthly' => 'รายเดือน', 'quarterly' => 'ราย 3 เดือน', 'yearly' => 'รายปี'];
    foreach ($planDist as &$pd) { $pd['plan'] = $cycleLabel[$pd['plan']] ?? $pd['plan']; }
    unset($pd);
} catch (\Throwable $e) { /* billing tables not installed */ }

// --- time series ---------------------------------------------------------
$growthMap = [];
foreach ($pf_safe("SELECT DATE(created_at) d, COUNT(*) n FROM tenants
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at)") as $r) {
    $growthMap[$r['d']] = (int) $r['n'];
}
$growth30 = $pf_series($growthMap, 30);
$growthLabels = [];
for ($i = 29; $i >= 0; $i--) { $growthLabels[] = date('j M', strtotime("-{$i} day")); }

$tSparkMap = [];
foreach ($pf_safe("SELECT DATE(created_at) d, COUNT(*) n FROM tenants
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)") as $r) {
    $tSparkMap[$r['d']] = (int) $r['n'];
}
$tSpark = $pf_series($tSparkMap, 14);
$tSparkWeek = array_sum(array_slice($tSpark, -7));

$lSparkMap = [];
foreach ($pf_safe("SELECT DATE(created_at) d, COUNT(*) n FROM beta_signups
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)") as $r) {
    $lSparkMap[$r['d']] = (int) $r['n'];
}
$lSpark = $pf_series($lSparkMap, 14);
$lSparkWeek = array_sum(array_slice($lSpark, -7));

// --- beta funnel ---------------------------------------------------------
$funnelMap = [];
foreach ($pf_safe("SELECT status, COUNT(*) n FROM beta_signups GROUP BY status") as $r) {
    $funnelMap[$r['status']] = (int) $r['n'];
}
$funnelStages = ['new' => 'ใหม่', 'contacted' => 'ติดต่อแล้ว', 'demo_booked' => 'นัดเดโม', 'trial_started' => 'ทดลอง', 'signed_up' => 'สมัครจริง'];
$betaTotal = (int) ($bStat['total'] ?? 0);
$convRate  = $betaTotal > 0 ? round(((int) ($bStat['signed'] ?? 0)) / $betaTotal * 100, 1) : 0.0;

// --- recent TENANT activity (login / points / LINE connect) --------------
$activity = [];
try {
    $activity = $db->query("SELECT al.created_at, al.event_type, al.actor, al.detail, t.display_name shop
        FROM tenant_activity_log al LEFT JOIN tenants t ON t.id = al.tenant_id
        ORDER BY al.created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { /* activity log not installed yet */ }
$EVENT_LABEL = ['login' => 'เข้าสู่ระบบ', 'points_award' => 'แจกแต้ม', 'line_connect' => 'เชื่อม LINE'];
$EVENT_ICON  = ['login' => 'fa-right-to-bracket', 'points_award' => 'fa-star', 'line_connect' => 'fa-comments'];
$EVENT_COLOR = ['login' => 'text-emerald-500', 'points_award' => 'text-amber-500', 'line_connect' => 'text-green-500'];

// --- recent tenants + leads ---------------------------------------------
$recentTenants = $pf_safe("SELECT id, slug, display_name, status, owner_name, created_at
    FROM tenants ORDER BY id DESC LIMIT 6");
$recentLeads = $pf_safe("SELECT id, full_name, business_name, lead_score, status, created_at
    FROM beta_signups ORDER BY id DESC LIMIT 6");

$STATUS_LABEL = ['active' => 'ใช้งาน', 'pending_setup' => 'รออนุมัติ', 'suspended' => 'ระงับ', 'terminated' => 'ปิด',
    'new' => 'ใหม่', 'contacted' => 'ติดต่อแล้ว', 'signed_up' => 'สมัครจริง', 'demo_booked' => 'นัดเดโม',
    'trial_started' => 'ทดลอง', 'spam' => 'สแปม', 'disqualified' => 'ตัดออก'];

$now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$summary = number_format($activeCount) . ' ร้านใช้งาน · '
    . ($mrr > 0 ? '฿' . number_format($mrr) . ' MRR · ' : '') . number_format($pendingCount) . ' รออนุมัติ';

$relTime = static function ($ts): string {
    $diff = time() - strtotime((string) $ts);
    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชม.ที่แล้ว';
    return floor($diff / 86400) . ' วันที่แล้ว';
};

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top('dashboard', 'แดชบอร์ด', 'ภาพรวมแพลตฟอร์ม REYA · ' . $now->format('j M Y'));
?>

<!-- Greeting + needs-attention -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <div>
        <h2 class="text-xl font-extrabold text-slate-900">สวัสดี, <?= $h(explode(' ', (string) ($_SESSION['platform_user_name'] ?? 'Owner'))[0]) ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $h($summary) ?></p>
    </div>
    <?php if ($pendingCount > 0 || $newBeta > 0): ?>
    <div class="flex items-center gap-2 text-sm">
        <?php if ($pendingCount > 0): ?>
        <a href="/admin/tenant-approvals.php" class="pf-btn pf-btn-ghost" style="border-color:#fde68a;color:#b45309;">
            <i class="fas fa-hourglass-half"></i> <?= $pendingCount ?> รออนุมัติ
        </a>
        <?php endif; ?>
        <?php if ($newBeta > 0): ?>
        <a href="/admin/beta-signups.php" class="pf-btn pf-btn-ghost" style="border-color:#bfdbfe;color:#1d4ed8;">
            <i class="fas fa-inbox"></i> <?= $newBeta ?> lead ใหม่
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- KPI row -->
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
    <a href="/admin/customers.php?status=active" class="pf-card pf-card-pad pf-int block">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">ร้านใช้งาน</div>
                <div class="pf-kpi-fig mt-1"><?= number_format($activeCount) ?></div>
            </div>
            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><i class="fas fa-circle-check"></i></div>
        </div>
        <div class="mt-3 flex items-center justify-between">
            <span class="text-[11px] text-emerald-600 font-semibold"><i class="fas fa-arrow-trend-up"></i> +<?= $tSparkWeek ?> สัปดาห์นี้</span>
            <canvas id="spkTenant" width="80" height="28" class="max-h-7"></canvas>
        </div>
    </a>
    <a href="/admin/tenant-approvals.php" class="pf-card pf-card-pad pf-int block">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">รออนุมัติ</div>
                <div class="pf-kpi-fig mt-1 <?= $pendingCount > 0 ? 'text-amber-600' : '' ?>"><?= number_format($pendingCount) ?></div>
            </div>
            <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center"><i class="fas fa-hourglass-half"></i></div>
        </div>
        <div class="mt-3 text-[11px] font-semibold <?= $pendingCount > 0 ? 'text-amber-600' : 'text-slate-400' ?>">
            <?= $pendingCount > 0 ? 'ต้องดำเนินการ →' : 'ไม่มีค้าง' ?>
        </div>
    </a>
    <a href="/admin/beta-signups.php" class="pf-card pf-card-pad pf-int block">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Beta lead ใหม่</div>
                <div class="pf-kpi-fig mt-1"><?= number_format($newBeta) ?></div>
            </div>
            <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fas fa-inbox"></i></div>
        </div>
        <div class="mt-3 flex items-center justify-between">
            <span class="text-[11px] text-blue-600 font-semibold"><i class="fas fa-arrow-trend-up"></i> +<?= $lSparkWeek ?> สัปดาห์นี้</span>
            <canvas id="spkLead" width="80" height="28" class="max-h-7"></canvas>
        </div>
    </a>
    <div class="pf-card pf-card-pad">
        <div class="flex items-start justify-between">
            <div>
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">MRR (รายเดือน)</div>
                <div class="pf-kpi-fig mt-1"><?= $mrr > 0 ? '฿' . number_format($mrr) : '—' ?></div>
            </div>
            <div class="w-9 h-9 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center"><i class="fas fa-coins"></i></div>
        </div>
        <div class="mt-3 text-[11px] font-semibold text-slate-400">
            <?= $mrr > 0 ? 'ARR ≈ ฿' . number_format($mrr * 12) : 'ยังไม่มีข้อมูลแพ็กเกจ' ?>
        </div>
    </div>
</div>

<!-- charts row -->
<div class="grid lg:grid-cols-3 gap-4 mb-5">
    <div class="pf-card pf-card-pad lg:col-span-2">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-slate-800">การเติบโตของร้าน <span class="text-xs font-normal text-slate-400">· 30 วัน</span></h3>
            <a href="/admin/customers.php" class="text-xs text-emerald-600 font-semibold hover:underline">ดูทั้งหมด</a>
        </div>
        <?php if (array_sum($growth30) > 0): ?>
            <canvas id="chartGrowth" height="90"></canvas>
        <?php else: ?>
            <div class="pf-empty"><i class="fas fa-chart-line text-3xl mb-2 block text-slate-300"></i>ยังไม่มีร้านใหม่ใน 30 วันนี้</div>
        <?php endif; ?>
    </div>
    <div class="pf-card pf-card-pad">
        <h3 class="font-bold text-slate-800 mb-3">แพ็กเกจ &amp; รายได้</h3>
        <?php if (!empty($planDist)): ?>
            <canvas id="chartPlans" height="150"></canvas>
            <div class="mt-3 space-y-1.5">
                <?php foreach ($planDist as $pd): ?>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-600"><?= $h($pd['plan']) ?></span>
                    <span class="text-slate-400 tnum"><?= (int) $pd['cnt'] ?> ร้าน · ฿<?= number_format((float) $pd['price']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="pf-empty"><i class="fas fa-layer-group text-3xl mb-2 block text-slate-300"></i>ยังไม่มีร้านที่ผูกแพ็กเกจ<br><span class="text-xs">(ตั้งราคาแพ็กเกจเพื่อดู MRR)</span></div>
        <?php endif; ?>
    </div>
</div>

<!-- funnel + activity -->
<div class="grid lg:grid-cols-3 gap-4 mb-5">
    <div class="pf-card pf-card-pad lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-slate-800">เส้นทาง Beta Lead</h3>
            <span class="text-xs font-semibold text-emerald-600">Conversion <?= $convRate ?>%</span>
        </div>
        <?php
        $maxFunnel = max(1, ...array_map(fn ($k) => (int) ($funnelMap[$k] ?? 0), array_keys($funnelStages)));
        $funnelColor = ['new' => 'bg-blue-500', 'contacted' => 'bg-cyan-500', 'demo_booked' => 'bg-violet-500', 'trial_started' => 'bg-amber-500', 'signed_up' => 'bg-emerald-500'];
        ?>
        <?php if ($betaTotal > 0): ?>
        <div class="space-y-2.5">
            <?php foreach ($funnelStages as $k => $label): $n = (int) ($funnelMap[$k] ?? 0); $w = round($n / $maxFunnel * 100); ?>
            <div class="flex items-center gap-3">
                <div class="w-20 text-xs text-slate-500 flex-shrink-0"><?= $h($label) ?></div>
                <div class="flex-1 bg-slate-100 rounded-full h-6 overflow-hidden">
                    <div class="<?= $funnelColor[$k] ?> h-full rounded-full flex items-center justify-end pr-2" style="width:<?= max((int) $w, $n > 0 ? 8 : 0) ?>%">
                        <?php if ($n > 0): ?><span class="text-[11px] font-bold text-white tnum"><?= $n ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="pf-empty"><i class="fas fa-filter text-3xl mb-2 block text-slate-300"></i>ยังไม่มี Beta lead</div>
        <?php endif; ?>
    </div>
    <div class="pf-card pf-card-pad">
        <h3 class="font-bold text-slate-800 mb-3">กิจกรรมร้านล่าสุด</h3>
        <?php if (!empty($activity)): ?>
        <div class="space-y-3">
            <?php foreach ($activity as $a): $ev = (string) $a['event_type']; ?>
            <div class="flex items-start gap-2.5 text-xs">
                <div class="w-7 h-7 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0 mt-0.5"><i class="fas <?= $h($EVENT_ICON[$ev] ?? 'fa-bolt') ?> <?= $h($EVENT_COLOR[$ev] ?? 'text-slate-500') ?> text-[10px]"></i></div>
                <div class="min-w-0">
                    <div class="text-slate-700"><span class="font-semibold"><?= $h($a['shop'] ?: 'ร้าน') ?></span> · <?= $h($EVENT_LABEL[$ev] ?? $ev) ?><?= $a['detail'] ? ' · ' . $h($a['detail']) : '' ?></div>
                    <div class="text-slate-400"><?= $h($relTime($a['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="pf-empty py-8"><i class="fas fa-bolt text-2xl mb-2 block text-slate-300"></i><span class="text-xs">ยังไม่มีกิจกรรมร้าน</span></div>
        <?php endif; ?>
    </div>
</div>

<!-- recent tenants + leads -->
<div class="grid lg:grid-cols-2 gap-4">
    <div class="pf-card">
        <div class="pf-card-pad flex items-center justify-between border-b border-slate-100">
            <h3 class="font-bold text-slate-800"><i class="fas fa-store text-slate-400 mr-1.5"></i> ร้านล่าสุด</h3>
            <a href="/admin/customers.php" class="text-xs text-emerald-600 font-semibold hover:underline">ดูทั้งหมด</a>
        </div>
        <div class="divide-y divide-slate-50">
            <?php foreach ($recentTenants as $t): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="font-semibold text-slate-800 text-sm truncate"><?= $h($t['display_name']) ?></div>
                    <div class="text-[11px] text-slate-400 font-mono truncate"><?= $h($t['slug']) ?>.re-ya.com</div>
                </div>
                <span class="pf-pill" data-st="<?= $h($t['status']) ?>"><?= $h($STATUS_LABEL[$t['status']] ?? $t['status']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($recentTenants)): ?><div class="pf-empty py-8 text-sm">ยังไม่มีร้าน</div><?php endif; ?>
        </div>
    </div>
    <div class="pf-card">
        <div class="pf-card-pad flex items-center justify-between border-b border-slate-100">
            <h3 class="font-bold text-slate-800"><i class="fas fa-user-plus text-slate-400 mr-1.5"></i> ผู้สมัครล่าสุด</h3>
            <a href="/admin/beta-signups.php" class="text-xs text-emerald-600 font-semibold hover:underline">ดูทั้งหมด</a>
        </div>
        <div class="divide-y divide-slate-50">
            <?php foreach ($recentLeads as $l): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center font-bold text-sm tnum flex-shrink-0"><?= (int) $l['lead_score'] ?></div>
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-800 text-sm truncate"><?= $h($l['full_name']) ?></div>
                        <div class="text-[11px] text-slate-400 truncate"><?= $h($l['business_name']) ?></div>
                    </div>
                </div>
                <span class="pf-pill" data-st="<?= $h($l['status']) ?>"><?= $h($STATUS_LABEL[$l['status']] ?? $l['status']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($recentLeads)): ?><div class="pf-empty py-8 text-sm">ยังไม่มีผู้สมัคร</div><?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    if (!window.Chart) { return; }
    Chart.defaults.font.family = "'Sarabun','Inter',sans-serif";
    Chart.defaults.plugins.legend.display = false;
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var anim = reduce ? false : { duration: 600 };

    function spark(id, data, color) {
        var el = document.getElementById(id); if (!el) return;
        new Chart(el, {
            type: 'line',
            data: { labels: data.map(function (_, i) { return i; }),
                datasets: [{ data: data, borderColor: color, borderWidth: 2, fill: true,
                    backgroundColor: color + '22', tension: .4, pointRadius: 0 }] },
            options: { animation: anim, responsive: false, plugins: { tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false, min: 0 } } }
        });
    }
    spark('spkTenant', <?= json_encode($tSpark) ?>, '#059669');
    spark('spkLead', <?= json_encode($lSpark) ?>, '#2563eb');

    var growthEl = document.getElementById('chartGrowth');
    if (growthEl) {
        new Chart(growthEl, {
            type: 'line',
            data: { labels: <?= json_encode($growthLabels) ?>,
                datasets: [{ label: 'ร้านใหม่', data: <?= json_encode($growth30) ?>,
                    borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.10)', borderWidth: 2.5,
                    fill: true, tension: .35, pointRadius: 0, pointHoverRadius: 4 }] },
            options: { animation: anim, maintainAspectRatio: false,
                plugins: { tooltip: { mode: 'index', intersect: false } },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8, color: '#94a3b8', font: { size: 10 } } },
                    y: { beginAtZero: true, ticks: { precision: 0, color: '#94a3b8', font: { size: 10 } }, grid: { color: '#f1f5f9' } } } }
        });
    }

    var plansEl = document.getElementById('chartPlans');
    if (plansEl) {
        new Chart(plansEl, {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_map(fn ($p) => $p['plan'], $planDist)) ?>,
                datasets: [{ data: <?= json_encode(array_map(fn ($p) => (int) $p['cnt'], $planDist)) ?>,
                    backgroundColor: ['#059669', '#0ea5e9', '#8b5cf6', '#f59e0b', '#64748b'], borderWidth: 0 }] },
            options: { animation: anim, cutout: '64%', plugins: { legend: { display: true, position: 'bottom',
                labels: { boxWidth: 10, font: { size: 11 }, color: '#475569' } } } }
        });
    }
})();
</script>

<?php platform_shell_bottom(); ?>
