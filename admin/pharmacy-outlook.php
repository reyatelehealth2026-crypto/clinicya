<?php
/**
 * admin/pharmacy-outlook.php — Platform Owner "Pharmacy Outlook" report.
 *
 * Phase 4 (Scale & Ecosystem Lock-in), increment 1: a READ-ONLY, anonymized
 * cross-tenant rollup (order volume, revenue, OTC/Rx product mix) built by
 * classes/PharmacyOutlook.php. PDPA-safe by construction — the service only
 * ever computes SQL aggregates per tenant and merges bucket-level totals;
 * no per-customer row ever reaches this page. Buckets backed by fewer than
 * PharmacyOutlook::MIN_COHORT distinct tenants are suppressed before this
 * page ever sees them.
 *
 * Auth: requires $_SESSION['platform_user_id'] (Platform Owner team only).
 * Never linked from tenant-facing admin pages.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PharmacyOutlook.php';

if (empty($_SESSION['platform_user_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="th"><meta charset="UTF-8"><title>403</title>'
       . '<body style="font-family:sans-serif;padding:40px">'
       . '<h1>403 — Platform Owner only</h1>'
       . '<p><a href="/admin/platform-login.php">Sign in</a></p></body></html>';
    exit;
}

$h = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

// --- period selection (defaults to current calendar month) ---------------
$today = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$fromDate = $_GET['from'] ?? $today->format('Y-m-01');
$toDate   = $_GET['to'] ?? $today->format('Y-m-d');

$isValidDate = static fn (string $d): bool => (bool) DateTime::createFromFormat('Y-m-d', $d);
if (!$isValidDate($fromDate)) {
    $fromDate = $today->format('Y-m-01');
}
if (!$isValidDate($toDate)) {
    $toDate = $today->format('Y-m-d');
}

$report = null;
$reportError = null;
try {
    $outlook = new PharmacyOutlook(Database::platform()->getConnection());
    $report = $outlook->buildReport($fromDate, $toDate);
} catch (\Throwable $e) {
    $reportError = 'ไม่สามารถสร้างรายงานได้ในขณะนี้ (Report generation failed)';
}

$CATEGORY_LABEL = [
    'otc'          => 'ยาสามัญ (OTC)',
    'dangerous'    => 'ยาอันตราย',
    'controlled'   => 'ยาควบคุมพิเศษ',
    'unclassified' => 'ไม่ระบุประเภท',
];

require_once __DIR__ . '/../includes/platform_shell.php';
platform_shell_top(
    'outlook',
    'Pharmacy Outlook',
    'ภาพรวมกิจกรรมร้านยาแบบไม่ระบุตัวตน (anonymized cross-tenant) · ' . $h($fromDate) . ' – ' . $h($toDate)
);
?>

<form method="get" class="pf-card pf-card-pad mb-5 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1">จากวันที่</label>
        <input type="date" name="from" value="<?= $h($fromDate) ?>" class="pf-input">
    </div>
    <div>
        <label class="block text-xs font-semibold text-slate-500 mb-1">ถึงวันที่</label>
        <input type="date" name="to" value="<?= $h($toDate) ?>" class="pf-input">
    </div>
    <button type="submit" class="pf-btn pf-btn-primary">อัปเดตรายงาน</button>
</form>

<?php if ($reportError): ?>
    <div class="pf-card pf-card-pad text-red-600"><?= $h($reportError) ?></div>
<?php else: ?>

    <div class="grid grid-cols-2 xl:grid-cols-3 gap-4 mb-5">
        <div class="pf-card pf-card-pad">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">ร้านที่รวมในรายงาน</div>
            <div class="pf-kpi-fig mt-1"><?= number_format($report['tenant_count']) ?></div>
        </div>
        <div class="pf-card pf-card-pad">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">จำนวนออเดอร์ (ชำระแล้ว)</div>
            <div class="pf-kpi-fig mt-1"><?= number_format($report['order_count']) ?></div>
        </div>
        <div class="pf-card pf-card-pad">
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">ยอดขายรวม (บาท)</div>
            <div class="pf-kpi-fig mt-1">฿<?= number_format($report['revenue_total'], 2) ?></div>
        </div>
    </div>

    <div class="pf-card pf-card-pad">
        <h3 class="font-bold text-slate-800 mb-1">สัดส่วนประเภทยา (OTC vs Rx)</h3>
        <p class="text-xs text-slate-400 mb-3">
            นับจากสินค้าที่เปิดขายอยู่ (ไม่ใช่ข้อมูลลูกค้ารายบุคคล) ·
            หมวดที่มีร้านค้าน้อยกว่า <?= (int) PharmacyOutlook::MIN_COHORT ?> ร้าน จะถูกซ่อนเพื่อความเป็นส่วนตัว (PDPA)
        </p>
        <?php if (empty($report['drug_category_counts'])): ?>
            <div class="pf-empty">ยังไม่มีข้อมูลเพียงพอสำหรับช่วงเวลานี้</div>
        <?php else: ?>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 text-xs uppercase">
                        <th class="py-2">ประเภทยา</th>
                        <th class="py-2 text-right">จำนวนสินค้า (รวมทุกร้าน)</th>
                        <th class="py-2 text-right">จำนวนร้านที่มีข้อมูล</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['drug_category_counts'] as $bucket => $count): ?>
                    <tr class="border-t border-slate-100">
                        <td class="py-2"><?= $h($CATEGORY_LABEL[$bucket] ?? $bucket) ?></td>
                        <td class="py-2 text-right"><?= number_format($count) ?></td>
                        <td class="py-2 text-right"><?= number_format($report['drug_category_tenants'][$bucket] ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($report['suppressed_buckets'])): ?>
            <p class="text-xs text-slate-400 mt-3">
                <i class="fas fa-lock"></i> ซ่อน <?= count($report['suppressed_buckets']) ?> หมวดเนื่องจากมีร้านค้าเข้าร่วมน้อยเกินไป
            </p>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php
platform_shell_bottom();
