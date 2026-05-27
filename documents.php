<?php
/**
 * Documents — เอกสาร
 * Thai-standard accounting document suite (QT/BL/INV/RE/TAX/DN/CN/PO/GR/...).
 *
 * Layout: left sidebar with doc-type nav + main panel with filters/table.
 * Each doc-type renders includes/documents/list.php; the special
 * `TAX_REGISTER` slot renders sales-tax-register.php (รายการภาษีขาย).
 *
 * @package Documents
 * @version 1.0.0
 */

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toast.php';
require_once __DIR__ . '/includes/document-helpers.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$pageTitle = 'เอกสาร';
$db = Database::getInstance()->getConnection();
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);

$currentDocType = strtoupper(trim((string)($_GET['doc_type'] ?? 'QT')));
$currentStatus  = trim((string)($_GET['status'] ?? ''));

// Special non-doc-type panel for "รายการภาษีขาย" report.
$validSlots = array_merge(array_keys(REYA_DOCUMENT_TYPES), ['TAX_REGISTER']);
if (!in_array($currentDocType, $validSlots, true)) {
    $currentDocType = 'QT';
}
$currentDocLabel = $currentDocType === 'TAX_REGISTER'
    ? 'รายการภาษีขาย'
    : docTypeLabel($currentDocType);

// Resolve main panel content (capture so we can also enqueue modal+JS below).
ob_start();
if ($lineAccountId <= 0) {
    echo '<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-800">'
       . '<i class="fas fa-exclamation-triangle mr-2"></i> โปรดเลือก LINE Official Account ก่อนใช้งานเอกสาร'
       . '</div>';
} elseif ($currentDocType === 'TAX_REGISTER') {
    include __DIR__ . '/includes/documents/sales-tax-register.php';
} else {
    include __DIR__ . '/includes/documents/list.php';
}
$mainContent = ob_get_clean();

// Sidebar groups.
$salesNav = [
    'QT'  => 'fa-file-signature',
    'BL'  => 'fa-file-invoice',
    'INV' => 'fa-file-invoice-dollar',
    'RE'  => 'fa-receipt',
    'TAX' => 'fa-file-contract',
    'DN'  => 'fa-plus-circle',
    'CN'  => 'fa-minus-circle',
];
$reportNav = [
    'TAX_REGISTER' => ['icon' => 'fa-table-list', 'label' => 'รายการภาษีขาย'],
];
$purchaseNav = [
    'PO'  => 'fa-cart-shopping',
    'GR'  => 'fa-box-open',
    'DNP' => 'fa-plus-circle',
    'CNP' => 'fa-minus-circle',
];

require_once __DIR__ . '/includes/header.php';
echo getPageHeaderStyles();
echo getToastStyles();

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$navLink = function ($code, $label, $icon) use ($currentDocType, $h) {
    $active = ($currentDocType === $code);
    $cls = $active
        ? 'bg-emerald-50 text-emerald-700 border-l-2 border-emerald-500 font-semibold'
        : 'text-slate-700 hover:bg-slate-50 border-l-2 border-transparent';
    return '<a href="documents.php?doc_type=' . $h($code) . '" class="flex items-center gap-2 px-4 py-2 text-sm transition ' . $cls . '">'
         . '<i class="fas ' . $h($icon) . ' w-4 text-center text-slate-400"></i>'
         . '<span>' . $h($label) . '</span></a>';
};
?>

<?= renderToastContainer() ?>

<?= renderPageHeader(
    'เอกสาร',
    'จัดการใบเสนอราคา / ใบกำกับภาษี / ใบเสร็จ และเอกสารทางบัญชีอื่นๆ',
    null,
    [
        ['label' => 'หน้าหลัก', 'href' => '/'],
        ['label' => 'เอกสาร',   'href' => null],
    ]
) ?>

<div class="flex flex-col lg:flex-row gap-4 mt-4">
    <!-- Left sidebar -->
    <aside class="w-full lg:w-64 flex-shrink-0">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden sticky top-4">
            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200">
                <div class="text-xs uppercase tracking-wider text-slate-500 font-semibold">เอกสารขาย</div>
            </div>
            <nav class="py-1">
                <?php foreach ($salesNav as $code => $icon): ?>
                    <?= $navLink($code, docTypeLabel($code) . ' (' . $code . ')', $icon) ?>
                <?php endforeach; ?>
            </nav>
            <div class="px-4 py-3 bg-slate-50 border-y border-slate-200">
                <div class="text-xs uppercase tracking-wider text-slate-500 font-semibold">รายงาน</div>
            </div>
            <nav class="py-1">
                <?php foreach ($reportNav as $code => $r): ?>
                    <?= $navLink($code, $r['label'], $r['icon']) ?>
                <?php endforeach; ?>
            </nav>
            <div class="px-4 py-3 bg-slate-50 border-y border-slate-200">
                <div class="text-xs uppercase tracking-wider text-slate-500 font-semibold">เอกสารซื้อ</div>
            </div>
            <nav class="py-1">
                <?php foreach ($purchaseNav as $code => $icon): ?>
                    <?= $navLink($code, docTypeLabel($code) . ' (' . $code . ')', $icon) ?>
                <?php endforeach; ?>
            </nav>
            <div class="px-4 py-3 border-t border-slate-200">
                <a href="settings.php?tab=shop_tax" class="text-xs text-slate-500 hover:text-slate-800 inline-flex items-center gap-1">
                    <i class="fas fa-cog"></i> ตั้งค่าข้อมูลภาษีของร้าน
                </a>
            </div>
        </div>
    </aside>

    <!-- Main panel -->
    <main class="flex-1 min-w-0">
        <?= $mainContent ?>
    </main>
</div>

<?php
// Modal + JS lives outside the captured content so it loads exactly once.
if ($lineAccountId > 0 && $currentDocType !== 'TAX_REGISTER') {
    include __DIR__ . '/includes/documents/create-modal.php';
}
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
