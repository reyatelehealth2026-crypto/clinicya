<?php
/**
 * Documents — List View
 * Renders the doc-flow visualization, status filter pills, search bar,
 * and the table of documents for the currently-selected doc type.
 *
 * Expected in scope:
 *   $db, $lineAccountId, $currentDocType, $currentStatus, $currentDocLabel
 *
 * Filters/state are read from $_GET and re-applied client-side too.
 *
 * @package Documents
 * @version 1.0.0
 */

require_once __DIR__ . '/../document-helpers.php';

$currentDocType   = $currentDocType   ?? 'QT';
$currentStatus    = $currentStatus    ?? '';
$currentDocLabel  = $currentDocLabel  ?? docTypeLabel($currentDocType);
$search           = trim((string)($_GET['q'] ?? ''));
$dateFrom         = trim((string)($_GET['from'] ?? ''));
$dateTo           = trim((string)($_GET['to']   ?? ''));

// Initial server-rendered query (200 max — client will paginate via API).
$where = ['line_account_id = ?', 'doc_type = ?'];
$params = [$lineAccountId, $currentDocType];

if (in_array($currentStatus, ['pending_approval','approved','cancelled'], true)) {
    $where[] = 'status = ?';
    $params[] = $currentStatus;
}
if ($search !== '') {
    $where[] = '(doc_number LIKE ? OR customer_name LIKE ? OR customer_tax_id LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($dateFrom !== '') { $where[] = 'issue_date >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'issue_date <= ?'; $params[] = $dateTo; }
$whereSql = implode(' AND ', $where);

$rows = [];
try {
    $stmt = $db->prepare("SELECT id, doc_type, doc_number, issue_date, due_date,
                                 customer_name, customer_tax_id,
                                 subtotal, vat_amount, total_amount,
                                 status, created_at
                            FROM business_documents
                           WHERE {$whereSql}
                           ORDER BY issue_date DESC, id DESC
                           LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[documents.list.php] ' . $e->getMessage());
}

$flowSteps = ['QT', 'BL', 'INV', 'RE', 'TAX'];
$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Build URLs that preserve doc_type but vary the status pill.
$pillHref = function ($status) use ($currentDocType) {
    $qs = ['doc_type' => $currentDocType];
    if ($status !== '') { $qs['status'] = $status; }
    return 'documents.php?' . http_build_query($qs);
};
?>

<!-- Doc flow visualisation -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4">
    <div class="flex items-center gap-2 text-sm text-slate-500 mb-2">
        <i class="fas fa-route"></i> ขั้นตอนเอกสารขาย
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <?php foreach ($flowSteps as $i => $step):
            $isActive = ($step === $currentDocType);
            $cls = $isActive
                ? 'bg-slate-900 text-white border-slate-900'
                : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100';
        ?>
        <a href="documents.php?doc_type=<?= $h($step) ?>" class="px-4 py-2 rounded-full border text-sm font-medium transition <?= $cls ?>">
            <span class="text-xs opacity-70 mr-1"><?= $h($step) ?></span>
            <?= $h(docTypeLabel($step)) ?>
        </a>
        <?php if ($i < count($flowSteps) - 1): ?>
            <i class="fas fa-arrow-right text-slate-300"></i>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!-- Status filter pills + search -->
<div class="flex items-center gap-2 mb-4 flex-wrap">
    <?php
    $pills = [
        '' => ['label' => 'ทั้งหมด',  'cls' => 'bg-slate-800 text-white'],
        'pending_approval' => ['label' => 'รออนุมัติ', 'cls' => 'bg-amber-100 text-amber-800 border border-amber-200'],
        'approved'         => ['label' => 'อนุมัติ',   'cls' => 'bg-emerald-100 text-emerald-800 border border-emerald-200'],
        'cancelled'        => ['label' => 'ยกเลิก',    'cls' => 'bg-rose-100 text-rose-800 border border-rose-200'],
    ];
    foreach ($pills as $k => $v):
        $active = ($currentStatus === $k);
        $opacityCls = $active ? 'ring-2 ring-offset-1 ring-slate-400' : 'opacity-90 hover:opacity-100';
    ?>
        <a href="<?= $h($pillHref($k)) ?>" class="px-4 py-2 rounded-full text-sm font-medium transition <?= $v['cls'] ?> <?= $opacityCls ?>">
            <?= $h($v['label']) ?>
        </a>
    <?php endforeach; ?>

    <div class="ml-auto flex items-center gap-2 flex-wrap">
        <form method="get" class="flex items-center gap-2">
            <input type="hidden" name="doc_type" value="<?= $h($currentDocType) ?>">
            <?php if ($currentStatus !== ''): ?>
                <input type="hidden" name="status" value="<?= $h($currentStatus) ?>">
            <?php endif; ?>
            <input type="date" name="from" value="<?= $h($dateFrom) ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <span class="text-slate-400 text-sm">–</span>
            <input type="date" name="to" value="<?= $h($dateTo) ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
            <input type="search" name="q" placeholder="ค้นหาเลขที่ / ชื่อลูกค้า / เลขผู้เสียภาษี"
                   value="<?= $h($search) ?>"
                   class="px-4 py-2 rounded-full border border-slate-200 text-sm w-72">
            <button class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm hover:bg-slate-200">
                <i class="fas fa-filter mr-1"></i> กรอง
            </button>
        </form>
    </div>
</div>

<!-- Section title + create button -->
<div class="flex items-center justify-between mb-3">
    <h2 class="text-xl font-semibold text-slate-900">
        <?= $h($currentDocLabel) ?>
        <span class="text-sm text-slate-500 font-normal ml-2"><?= count($rows) ?> รายการ</span>
    </h2>
    <div class="flex items-center gap-2">
        <a href="api/documents.php?action=export_csv&doc_type=<?= $h($currentDocType) ?><?= $dateFrom !== '' ? '&from=' . urlencode($dateFrom) : '' ?><?= $dateTo !== '' ? '&to=' . urlencode($dateTo) : '' ?>"
           class="px-3 py-2 rounded-lg bg-white border border-slate-200 text-slate-700 text-sm hover:bg-slate-50">
            <i class="fas fa-file-csv mr-1"></i> CSV
        </a>
        <button type="button" onclick="openModalShell('docCreateModal'); window.__docCreateInit && window.__docCreateInit('<?= $h($currentDocType) ?>')"
                class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800">
            <i class="fas fa-plus mr-1"></i> สร้างเอกสาร
        </button>
    </div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">วันที่</th>
                    <th class="px-4 py-3 text-left">เลขที่เอกสาร</th>
                    <th class="px-4 py-3 text-left">ลูกค้า</th>
                    <th class="px-4 py-3 text-right">ยอดสุทธิ</th>
                    <th class="px-4 py-3 text-center">สถานะ</th>
                    <th class="px-4 py-3 text-right">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">
                        <i class="fas fa-inbox text-3xl mb-2 block"></i>
                        ยังไม่มี<?= $h($currentDocLabel) ?>
                    </td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-4 py-3 text-slate-700 whitespace-nowrap">
                            <?= $h(formatThaiDate((string)$r['issue_date'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="api/documents.php?action=pdf&id=<?= (int)$r['id'] ?>" target="_blank"
                               class="font-medium text-slate-900 hover:text-emerald-700">
                                <?= $h($r['doc_number']) ?>
                            </a>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                            <div class="font-medium"><?= $h($r['customer_name'] ?: '-') ?></div>
                            <?php if (!empty($r['customer_tax_id'])): ?>
                                <div class="text-xs text-slate-400">TAX <?= $h($r['customer_tax_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right font-medium text-slate-900 whitespace-nowrap">
                            <?= $h(formatMoney((float)$r['total_amount'])) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?= docStatusBadge((string)$r['status']) ?>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="api/documents.php?action=pdf&id=<?= (int)$r['id'] ?>" target="_blank"
                               class="text-slate-500 hover:text-slate-800 px-2" title="พิมพ์">
                                <i class="fas fa-print"></i>
                            </a>
                            <button type="button" onclick="window.__docView && window.__docView(<?= (int)$r['id'] ?>)"
                                    class="text-slate-500 hover:text-slate-800 px-2" title="ดูรายละเอียด">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($r['status'] === 'pending_approval'): ?>
                                <button type="button" onclick="window.__docApprove && window.__docApprove(<?= (int)$r['id'] ?>)"
                                        class="text-emerald-600 hover:text-emerald-800 px-2" title="อนุมัติ">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'cancelled'): ?>
                                <button type="button" onclick="window.__docCancel && window.__docCancel(<?= (int)$r['id'] ?>)"
                                        class="text-rose-600 hover:text-rose-800 px-2" title="ยกเลิก">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'approved' && in_array($r['doc_type'], ['QT','BL','INV'], true)): ?>
                                <button type="button" onclick="window.__docConvert && window.__docConvert(<?= (int)$r['id'] ?>, '<?= $h($r['doc_type']) ?>')"
                                        class="text-indigo-600 hover:text-indigo-800 px-2" title="แปลงเป็นเอกสารถัดไป">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
