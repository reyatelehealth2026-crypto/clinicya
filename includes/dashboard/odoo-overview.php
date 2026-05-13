<?php
/**
 * Odoo Overview Dashboard Tab
 * Read-only Odoo-focused overview for admin users.
 */

require_once __DIR__ . '/../odoo-order-analytics.php';
require_once __DIR__ . '/../components/kpi-card.php';
require_once __DIR__ . '/../components/section-card.php';

$currentBotId = $_SESSION['current_bot_id'] ?? null;

$overview = [
    'orders_total' => 0,
    'orders_today' => 0,
    'revenue_today' => 0.0,
    'revenue_month' => 0.0,
    'customers_total' => 0,
    'customers_new_today' => 0,
    'invoices_open' => 0,
    'invoices_paid' => 0,
    'products_total' => 0,
    'products_low_stock' => 0,
    'products_out_of_stock' => 0,
];

$recentOrders = [];
$fallbackNotes = [];

try {
    $db->query("SELECT 1 FROM odoo_webhooks_log LIMIT 1");

    $snapshotBundle = buildOdooWebhookSnapshotBase($db, $currentBotId);
    $baseSubquery = $snapshotBundle['base_subquery'];
    $params = $snapshotBundle['params'];
    $stateBuckets = getOdooOrderStateBuckets();
    $cancelledStates = "'" . implode("','", $stateBuckets['cancelled']) . "'";

    $snapshotSql = "
        SELECT
            order_key,
            MIN(processed_at) AS created_at,
            MAX(amount_total) AS amount_total,
            SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status,
            SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY processed_at DESC), ',', 1) AS customer_name
        FROM ({$baseSubquery}) s
        GROUP BY order_key
    ";

    $statsSql = "
        SELECT
            COUNT(*) AS total,
            SUM(DATE(created_at) = CURDATE()) AS today,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status NOT IN ({$cancelledStates}) THEN amount_total ELSE 0 END), 0) AS revenue_today,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status NOT IN ({$cancelledStates}) THEN amount_total ELSE 0 END), 0) AS revenue_month
        FROM ({$snapshotSql}) o
    ";

    $stmt = $db->prepare($statsSql);
    $stmt->execute($params);
    $orderStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $overview['orders_total'] = (int) ($orderStats['total'] ?? 0);
    $overview['orders_today'] = (int) ($orderStats['today'] ?? 0);
    $overview['revenue_today'] = (float) ($orderStats['revenue_today'] ?? 0);
    $overview['revenue_month'] = (float) ($orderStats['revenue_month'] ?? 0);

    $recentSql = "
        SELECT
            order_key AS order_number,
            MIN(processed_at) AS created_at,
            MAX(amount_total) AS total_amount,
            SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status,
            SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY processed_at DESC), ',', 1) AS customer_name
        FROM ({$baseSubquery}) s
        GROUP BY order_key
        ORDER BY created_at DESC
        LIMIT 8
    ";

    $stmt = $db->prepare($recentSql);
    $stmt->execute($params);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fallbackNotes[] = 'ไม่พบข้อมูลออเดอร์ Odoo จาก webhook log';
}

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $overview['customers_total'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE() AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $overview['customers_new_today'] = (int) $stmt->fetchColumn();
} catch (Exception $e) {
    $fallbackNotes[] = 'ไม่สามารถคำนวณข้อมูลลูกค้าได้';
}

try {
    $stmt = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) AS low_stock
        FROM products
        WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $productStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $overview['products_total'] = (int) ($productStats['total'] ?? 0);
    $overview['products_out_of_stock'] = (int) ($productStats['out_of_stock'] ?? 0);
    $overview['products_low_stock'] = (int) ($productStats['low_stock'] ?? 0);
} catch (Exception $e) {
    $fallbackNotes[] = 'ไม่สามารถคำนวณข้อมูลสินค้าในคลังได้';
}

try {
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN endpoint = '/reya/invoices' AND status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS invoices_open,
        SUM(CASE WHEN endpoint = '/reya/credit-status' AND status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS invoices_paid
        FROM odoo_api_logs
        WHERE (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $invoiceStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $overview['invoices_open'] = (int) ($invoiceStats['invoices_open'] ?? 0);
    $overview['invoices_paid'] = (int) ($invoiceStats['invoices_paid'] ?? 0);
} catch (Exception $e) {
    $fallbackNotes[] = 'ยังไม่มีข้อมูลใบแจ้งหนี้จาก Odoo API log (แสดงค่าเริ่มต้น 0)';
}
?>

<?= getKpiCardStyles() ?>
<?= getSectionCardStyles() ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Odoo Overview</h2>
            <p class="text-sm text-gray-500">ภาพรวมตามโหมด Odoo (อ่านอย่างเดียว)</p>
        </div>
        <a href="/shop/orders" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">
            ดูคำสั่งซื้อ Odoo
        </a>
    </div>

    <?php if (!empty($fallbackNotes)): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
        <div class="font-medium mb-2">หมายเหตุข้อมูล</div>
        <ul class="list-disc ml-5 space-y-1">
            <?php foreach ($fallbackNotes as $note): ?>
            <li><?= htmlspecialchars($note) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <?= renderKpiCard('indigo',  'ออเดอร์วันนี้',  number_format($overview['orders_today']),
            'รวมทั้งหมด ' . number_format($overview['orders_total']), 'fas fa-shopping-cart') ?>
        <?= renderKpiCard('emerald', 'ยอดขายวันนี้',  '฿' . number_format($overview['revenue_today'], 2),
            'เดือนนี้ ฿' . number_format($overview['revenue_month'], 2), 'fas fa-baht-sign') ?>
        <?= renderKpiCard('violet',  'ลูกค้า',         number_format($overview['customers_total']),
            'ใหม่วันนี้ ' . number_format($overview['customers_new_today']), 'fas fa-users') ?>
        <?= renderKpiCard('amber',   'ใบแจ้งหนี้',     number_format($overview['invoices_open']),
            'เครดิตสถานะ (hit) ' . number_format($overview['invoices_paid']), 'fas fa-file-invoice') ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <?php
        ob_start(); ?>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span>สินค้าทั้งหมด</span><strong><?= number_format($overview['products_total']) ?></strong></div>
                <div class="flex justify-between"><span>สินค้าใกล้หมด</span><strong class="text-yellow-600"><?= number_format($overview['products_low_stock']) ?></strong></div>
                <div class="flex justify-between"><span>สินค้าหมด</span><strong class="text-red-600"><?= number_format($overview['products_out_of_stock']) ?></strong></div>
            </div>
            <a href="/inventory?tab=products" class="inline-block mt-4 text-sm text-indigo-600 hover:underline">ไปที่จัดการสินค้า</a>
        <?php
        echo '<div class="lg:col-span-1">' . renderSectionCard('คลังสินค้า', 'fas fa-boxes', ob_get_clean(), null, 'amber') . '</div>';

        ob_start();
        if (empty($recentOrders)): ?>
            <div class="sc-empty">
                <div class="sc-empty__circle"><i class="fas fa-receipt" aria-hidden="true"></i></div>
                <p class="sc-empty__title">ยังไม่มีข้อมูลออเดอร์จาก Odoo</p>
            </div>
        <?php else: ?>
            <div class="divide-y">
                <?php foreach ($recentOrders as $order): ?>
                <div class="p-4 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="font-medium text-gray-800 truncate">#<?= htmlspecialchars($order['order_number']) ?></div>
                        <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($order['customer_name'] ?: '-') ?></div>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold text-emerald-600">฿<?= number_format((float) ($order['total_amount'] ?? 0), 2) ?></div>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars((string) ($order['status'] ?? '-')) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
        $ordersBody = ob_get_clean();
        $ordersAction = renderSectionActionLink('/shop/orders', 'ดูทั้งหมด');
        echo '<div class="lg:col-span-2">' . renderSectionCardFlush('ออเดอร์ล่าสุดจาก Odoo', 'fas fa-receipt', $ordersBody, $ordersAction, 'indigo') . '</div>';
        ?>
    </div>
</div>
