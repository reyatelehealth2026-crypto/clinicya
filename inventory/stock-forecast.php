<?php
/**
 * Stock Forecast — คาดการณ์สินค้าใกล้หมดจากยอดขาย (Phase 2)
 *
 * Thin read-only admin view over classes/StockPredictor.php: pulls recent
 * daily sales per product from transaction_items/transactions, current stock
 * from business_items, and shows which products are projected to run out
 * soon so the shop can reorder in time.
 *
 * Sales source is always transactions/transaction_items (this app's own
 * order data), never the Odoo API — Odoo cache tables (odoo_orders/
 * odoo_invoices) are read-only mirrors of Odoo-side orders and are out of
 * scope here. No Odoo-specific reads happen in this file, so no
 * $isOdooMode gate is needed for the forecast query itself; it is only
 * used to label the sales-data source in the UI when Odoo is the shop's
 * order system, so pharmacists aren't misled about what the forecast covers.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/StockPredictor.php';
require_once __DIR__ . '/../includes/shop-data-source.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'คาดการณ์สินค้าใกล้หมด (Stock Forecast)';

// Odoo kill-switch: only used to annotate the UI when this shop's order
// data actually lives in Odoo (transactions/transaction_items would then be
// incomplete for velocity purposes). We never query Odoo here.
$orderDataSource = function_exists('getShopOrderDataSource')
    ? getShopOrderDataSource($db, $lineAccountId)
    : 'internal';
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

/** Lookback window for sales velocity, in days. */
$lookbackDays = 30;
$windowStart = date('Y-m-d 00:00:00', strtotime("-{$lookbackDays} days"));

// Total units sold per product over the lookback window, scoped to this
// line account (falls back to all accounts when none is set, matching
// InventoryService's `OR line_account_id IS NULL` pattern).
$salesParams = [$windowStart];
$salesSql = "SELECT ti.product_id, SUM(ti.quantity) AS units_sold
    FROM transaction_items ti
    INNER JOIN transactions t ON t.id = ti.transaction_id
    WHERE ti.product_id IS NOT NULL
        AND t.created_at >= ?
        AND t.status NOT IN ('cancelled', 'refunded')";
if ($lineAccountId) {
    $salesSql .= " AND (t.line_account_id = ? OR t.line_account_id IS NULL)";
    $salesParams[] = $lineAccountId;
}
$salesSql .= " GROUP BY ti.product_id";

$unitsSoldByProduct = [];
try {
    $stmt = $db->prepare($salesSql);
    $stmt->execute($salesParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unitsSoldByProduct[(int) $row['product_id']] = (float) $row['units_sold'];
    }
} catch (Exception $e) {
    // transaction_items/transactions always exist in the schema; if this
    // fails, fall through with an empty sales map rather than fataling.
}

// Products with any current stock or any recent sales, scoped like other
// inventory reads in this app.
$productParams = [];
$productSql = "SELECT id, name, sku, stock FROM business_items WHERE is_active = 1";
if ($lineAccountId) {
    $productSql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
    $productParams[] = $lineAccountId;
}
$productSql .= " ORDER BY name ASC";

$products = [];
try {
    $stmt = $db->prepare($productSql);
    $stmt->execute($productParams);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// Build the forecast list: only products with recent sales OR that are
// already out of stock are worth showing (matches "reorder in time" intent —
// a product with no sales and no stock issue has nothing to forecast).
$forecasts = [];
foreach ($products as $p) {
    $productId = (int) $p['id'];
    $stock = (int) $p['stock'];
    $unitsSold = $unitsSoldByProduct[$productId] ?? 0.0;

    if ($unitsSold <= 0.0 && $stock > 0) {
        continue; // nothing selling and stock is fine — not forecast-worthy
    }

    $result = StockPredictor::forecastFromTotal($stock, $unitsSold, $lookbackDays);
    if ($result === null) {
        continue;
    }

    $forecasts[] = array_merge($p, $result, ['units_sold' => $unitsSold]);
}

// Out-soon first, then watch, then ok; within a level, soonest runout first.
$riskOrder = [
    StockPredictor::RISK_OUT_SOON => 0,
    StockPredictor::RISK_WATCH => 1,
    StockPredictor::RISK_OK => 2,
];
usort($forecasts, function ($a, $b) use ($riskOrder) {
    $riskCompare = $riskOrder[$a['risk_level']] <=> $riskOrder[$b['risk_level']];
    if ($riskCompare !== 0) {
        return $riskCompare;
    }
    $aDays = $a['days_to_runout'] ?? PHP_FLOAT_MAX;
    $bDays = $b['days_to_runout'] ?? PHP_FLOAT_MAX;
    return $aDays <=> $bDays;
});

$outSoonCount = count(array_filter($forecasts, fn($f) => $f['risk_level'] === StockPredictor::RISK_OUT_SOON));
$watchCount = count(array_filter($forecasts, fn($f) => $f['risk_level'] === StockPredictor::RISK_WATCH));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <?php if ($isOdooMode): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 text-sm text-blue-700">
        <i class="fas fa-info-circle mr-1"></i>
        ร้านนี้ใช้ Odoo เป็นระบบออเดอร์หลัก — การคาดการณ์นี้อ้างอิงจากยอดขายในระบบ (transactions) เท่านั้น อาจไม่ครอบคลุมออเดอร์ที่บันทึกใน Odoo โดยตรง
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-red-50 border border-red-200 rounded-xl p-4">
            <p class="text-red-600 text-sm font-medium">ใกล้หมดเร่งด่วน (&le; <?= StockPredictor::OUT_SOON_DAYS ?> วัน)</p>
            <p class="text-3xl font-bold text-red-700"><?= $outSoonCount ?></p>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <p class="text-yellow-600 text-sm font-medium">ควรจับตา (&le; <?= StockPredictor::WATCH_DAYS ?> วัน)</p>
            <p class="text-3xl font-bold text-yellow-700"><?= $watchCount ?></p>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
            <p class="text-gray-600 text-sm font-medium">คำนวณจากยอดขายย้อนหลัง</p>
            <p class="text-3xl font-bold text-gray-700"><?= $lookbackDays ?> วัน</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-chart-line mr-2 text-red-500"></i>รายการคาดการณ์สินค้าใกล้หมด</h2>
        </div>
        <?php if (empty($forecasts)): ?>
        <div class="p-8 text-center text-gray-500">
            <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
            <p>ไม่มีสินค้าที่มีความเสี่ยงจะหมดในเร็วๆ นี้</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อกปัจจุบัน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ขาย/วันเฉลี่ย</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">คาดว่าหมดใน (วัน)</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ความเสี่ยง</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($forecasts as $f):
                        $riskColors = [
                            StockPredictor::RISK_OUT_SOON => 'red',
                            StockPredictor::RISK_WATCH => 'yellow',
                            StockPredictor::RISK_OK => 'green',
                        ];
                        $riskLabels = [
                            StockPredictor::RISK_OUT_SOON => 'ใกล้หมดเร่งด่วน',
                            StockPredictor::RISK_WATCH => 'ควรจับตา',
                            StockPredictor::RISK_OK => 'ปกติ',
                        ];
                        $color = $riskColors[$f['risk_level']];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($f['name']) ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($f['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><?= (int) $f['stock'] ?></td>
                        <td class="px-4 py-3 text-center"><?= number_format($f['daily_velocity'], 2) ?></td>
                        <td class="px-4 py-3 text-center font-bold">
                            <?= $f['days_to_runout'] !== null ? number_format($f['days_to_runout'], 1) : '—' ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $color ?>-100 text-<?= $color ?>-700 rounded text-xs">
                                <?= $riskLabels[$f['risk_level']] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
