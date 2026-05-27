<?php
/**
 * Sales Tax Register — รายการภาษีขาย
 * Read-only monthly report listing TAX invoices for a period with totals.
 *
 * Expected in scope:
 *   $db, $lineAccountId
 *
 * @package Documents
 * @version 1.0.0
 */

require_once __DIR__ . '/../document-helpers.php';

// Default to current month (Asia/Bangkok).
$tz = new DateTimeZone('Asia/Bangkok');
$now = new DateTimeImmutable('now', $tz);
$year  = (int)($_GET['year']  ?? $now->format('Y'));
$month = (int)($_GET['month'] ?? $now->format('n'));
if ($month < 1 || $month > 12) { $month = (int)$now->format('n'); }
if ($year  < 2000) { $year = (int)$now->format('Y'); }

$periodStart = sprintf('%04d-%02d-01', $year, $month);
$periodEnd   = (new DateTimeImmutable($periodStart, $tz))->modify('last day of this month')->format('Y-m-d');

$rows = [];
$tot = ['count' => 0, 'subtotal' => 0.0, 'vat' => 0.0, 'total' => 0.0];

try {
    $stmt = $db->prepare(
        "SELECT id, doc_number, issue_date, customer_name, customer_tax_id, customer_branch_code,
                subtotal, discount_amount, vat_amount, total_amount, status
           FROM business_documents
          WHERE line_account_id = ?
            AND doc_type = 'TAX'
            AND issue_date BETWEEN ? AND ?
          ORDER BY issue_date ASC, id ASC"
    );
    $stmt->execute([$lineAccountId, $periodStart, $periodEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $r) {
        if ($r['status'] === 'cancelled') { continue; } // exclude voided from totals
        $tot['count']++;
        $tot['subtotal'] += (float)$r['subtotal'];
        $tot['vat']      += (float)$r['vat_amount'];
        $tot['total']    += (float)$r['total_amount'];
    }
} catch (Throwable $e) {
    error_log('[sales-tax-register] ' . $e->getMessage());
}

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$thaiMonths = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',
    7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$buddhistYear = $year + 543;
?>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">รายการภาษีขาย</h2>
            <p class="text-sm text-slate-500">
                ประจำเดือน <?= $h($thaiMonths[$month]) ?> พ.ศ. <?= $h($buddhistYear) ?>
                · <?= $tot['count'] ?> ใบ
            </p>
        </div>
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="doc_type" value="TAX_REGISTER">
            <select name="month" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <?php foreach ($thaiMonths as $mNum => $mLabel): ?>
                    <option value="<?= $mNum ?>" <?= $mNum === $month ? 'selected' : '' ?>><?= $h($mLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                <?php for ($y = (int)$now->format('Y'); $y >= (int)$now->format('Y') - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= ($y + 543) ?></option>
                <?php endfor; ?>
            </select>
            <button class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800">ค้นหา</button>
            <a href="api/documents.php?action=export_csv&doc_type=TAX&from=<?= $h($periodStart) ?>&to=<?= $h($periodEnd) ?>"
               class="px-4 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 text-sm hover:bg-slate-50">
                <i class="fas fa-file-csv mr-1"></i> CSV
            </a>
        </form>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-3 py-3 text-right">#</th>
                    <th class="px-3 py-3 text-left">วันที่</th>
                    <th class="px-3 py-3 text-left">เลขที่เอกสาร</th>
                    <th class="px-3 py-3 text-left">ลูกค้า</th>
                    <th class="px-3 py-3 text-left">เลขผู้เสียภาษี</th>
                    <th class="px-3 py-3 text-right">ยอดก่อนภาษี</th>
                    <th class="px-3 py-3 text-right">VAT 7%</th>
                    <th class="px-3 py-3 text-right">ยอดรวม</th>
                    <th class="px-3 py-3 text-center">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="px-4 py-12 text-center text-slate-400">
                        <i class="fas fa-receipt text-3xl mb-2 block"></i>
                        ไม่มีใบกำกับภาษีในเดือนนี้
                    </td></tr>
                <?php else: $n = 0; foreach ($rows as $r): $n++; ?>
                    <tr class="hover:bg-slate-50 <?= $r['status']==='cancelled' ? 'opacity-60 line-through' : '' ?>">
                        <td class="px-3 py-2 text-right text-slate-500"><?= $n ?></td>
                        <td class="px-3 py-2 whitespace-nowrap"><?= $h(formatThaiDate((string)$r['issue_date'])) ?></td>
                        <td class="px-3 py-2 font-medium text-slate-900">
                            <a href="api/documents.php?action=pdf&id=<?= (int)$r['id'] ?>" target="_blank" class="hover:text-emerald-700">
                                <?= $h($r['doc_number']) ?>
                            </a>
                        </td>
                        <td class="px-3 py-2"><?= $h($r['customer_name'] ?: '-') ?></td>
                        <td class="px-3 py-2 text-slate-600">
                            <?= $h($r['customer_tax_id']) ?>
                            <?php if (!empty($r['customer_branch_code']) && $r['customer_branch_code'] !== '00000'): ?>
                                <span class="text-xs text-slate-400">(สาขา <?= $h($r['customer_branch_code']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap"><?= $h(formatMoney((float)$r['subtotal'])) ?></td>
                        <td class="px-3 py-2 text-right whitespace-nowrap"><?= $h(formatMoney((float)$r['vat_amount'])) ?></td>
                        <td class="px-3 py-2 text-right font-semibold whitespace-nowrap"><?= $h(formatMoney((float)$r['total_amount'])) ?></td>
                        <td class="px-3 py-2 text-center"><?= docStatusBadge((string)$r['status']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot class="bg-slate-100 font-semibold">
                <tr>
                    <td colspan="5" class="px-3 py-3 text-right">รวม (ไม่นับใบยกเลิก)</td>
                    <td class="px-3 py-3 text-right"><?= $h(formatMoney($tot['subtotal'])) ?></td>
                    <td class="px-3 py-3 text-right"><?= $h(formatMoney($tot['vat'])) ?></td>
                    <td class="px-3 py-3 text-right"><?= $h(formatMoney($tot['total'])) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>
