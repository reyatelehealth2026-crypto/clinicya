<?php
/**
 * Tab: storefront — Storefront Catalog Manager
 * Ref: docs/ODOO_PRODUCT_SYNC_PHP.md §12.1
 *
 * หน้าจัดการสินค้าที่ **วางขายหน้าร้านจริง** (curated subset ของ Odoo cache)
 *   - Filter: search, category, drug_type, storefront_status, price range, stock
 *   - Bulk ops:
 *       A. Row-based   — checkbox + ปิด/เปิด storefront
 *       B. Filter-based — shortcut: ปิดราคา 0, ปิด category, ปิด drug_type, ปิด Odoo-inactive
 *   - Inline toggle `storefront_enabled` (call api/storefront-bulk.php)
 *   - Pagination
 *
 * Scope: ใช้ line_account_id จาก session (`current_bot_id`)
 * Table: odoo_products_cache (+ columns จาก migration_storefront_split.sql)
 */

$currentBotId = (int) ($_SESSION['current_bot_id'] ?? 1);

// ─── Verify migration ran ──────────────────────────────────────────────────────
$migrationReady = false;
try {
    $check = $db->query("SHOW COLUMNS FROM odoo_products_cache LIKE 'storefront_enabled'");
    $migrationReady = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $migrationReady = false;
}

if (!$migrationReady) {
    ?>
    <div class="bg-yellow-50 border border-yellow-300 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-yellow-800 mb-2">
            <i class="fas fa-database mr-2"></i>ต้อง run migration ก่อน
        </h3>
        <p class="text-yellow-700 mb-3">Tab นี้ต้องการคอลัมน์ <code>storefront_enabled</code> ใน <code>odoo_products_cache</code></p>
        <div class="bg-white rounded-lg p-3 font-mono text-sm text-gray-700">
            mysql -u &lt;user&gt; -p &lt;db&gt; &lt; database/migration_storefront_split.sql
        </div>
    </div>
    <?php
    return;
}

// ─── Filter parameters ─────────────────────────────────────────────────────────
$search         = trim((string) ($_GET['search']          ?? ''));
$categoryFilter = trim((string) ($_GET['category']        ?? ''));
$drugTypeFilter = trim((string) ($_GET['drug_type']       ?? ''));
$statusFilter   = $_GET['storefront_status']              ?? 'all'; // all | enabled | disabled
$priceFilter    = $_GET['price_filter']                   ?? '';    // '' | zero | has_price | range
$priceMin       = isset($_GET['price_min']) && $_GET['price_min'] !== '' ? (float) $_GET['price_min'] : null;
$priceMax       = isset($_GET['price_max']) && $_GET['price_max'] !== '' ? (float) $_GET['price_max'] : null;
$stockFilter    = $_GET['stock_filter']                   ?? '';    // '' | in | low | out
$page           = max(1, (int) ($_GET['page']             ?? 1));
$perPage        = (int) ($_GET['per_page']                ?? 50);
if (!in_array($perPage, [20, 50, 100, 200], true)) {
    $perPage = 50;
}
$offset = ($page - 1) * $perPage;

// ─── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ['line_account_id = ?'];
$params = [$currentBotId];

if ($search !== '') {
    $where[] = "(name LIKE ? OR sku LIKE ? OR product_code LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($categoryFilter !== '') {
    $where[]  = "category = ?";
    $params[] = $categoryFilter;
}
if ($drugTypeFilter !== '') {
    $where[]  = "drug_type = ?";
    $params[] = $drugTypeFilter;
}
if ($statusFilter === 'enabled') {
    $where[] = "storefront_enabled = 1";
} elseif ($statusFilter === 'disabled') {
    $where[] = "storefront_enabled = 0";
}
if ($priceFilter === 'zero') {
    $where[] = "(online_price IS NULL OR online_price = 0) AND (list_price IS NULL OR list_price = 0)";
} elseif ($priceFilter === 'has_price') {
    $where[] = "(online_price > 0 OR list_price > 0)";
} elseif ($priceFilter === 'range' && ($priceMin !== null || $priceMax !== null)) {
    $priceCol = "COALESCE(NULLIF(online_price,0), list_price)";
    if ($priceMin !== null) {
        $where[]  = "{$priceCol} >= ?";
        $params[] = $priceMin;
    }
    if ($priceMax !== null) {
        $where[]  = "{$priceCol} <= ?";
        $params[] = $priceMax;
    }
}
if ($stockFilter === 'in') {
    $where[] = "saleable_qty > 5";
} elseif ($stockFilter === 'low') {
    $where[] = "saleable_qty > 0 AND saleable_qty <= 5";
} elseif ($stockFilter === 'out') {
    $where[] = "saleable_qty <= 0";
}

$whereSql = implode(' AND ', $where);

// ─── Query data ────────────────────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil(max(1, $total) / $perPage));

$listStmt = $db->prepare(
    "SELECT id, product_code, sku, name, generic_name, category, drug_type,
            list_price, online_price, saleable_qty, is_active, storefront_enabled,
            featured_order, last_synced_at
     FROM odoo_products_cache
     WHERE {$whereSql}
     ORDER BY storefront_enabled DESC,
              featured_order IS NULL,
              featured_order ASC,
              name ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Dropdown options
$categories = [];
try {
    $catStmt = $db->prepare(
        "SELECT category, COUNT(*) AS n
         FROM odoo_products_cache
         WHERE line_account_id = ? AND category IS NOT NULL AND category <> ''
         GROUP BY category
         ORDER BY n DESC, category ASC"
    );
    $catStmt->execute([$currentBotId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

$drugTypes = [];
try {
    $dtStmt = $db->prepare(
        "SELECT drug_type, COUNT(*) AS n
         FROM odoo_products_cache
         WHERE line_account_id = ? AND drug_type IS NOT NULL AND drug_type <> ''
         GROUP BY drug_type
         ORDER BY n DESC, drug_type ASC"
    );
    $dtStmt->execute([$currentBotId]);
    $drugTypes = $dtStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $drugTypes = [];
}

// Storefront stats
$statStmt = $db->prepare(
    "SELECT
        SUM(storefront_enabled = 1)                              AS enabled_cnt,
        SUM(storefront_enabled = 0)                              AS disabled_cnt,
        SUM(storefront_enabled = 1 AND (online_price IS NULL OR online_price = 0)
                                    AND (list_price   IS NULL OR list_price   = 0)) AS enabled_zero_cnt,
        COUNT(*) AS total_cnt
     FROM odoo_products_cache
     WHERE line_account_id = ?"
);
$statStmt->execute([$currentBotId]);
$stats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Query builder helper (preserve filters)
if (!function_exists('buildStorefrontQuery')) {
    function buildStorefrontQuery(array $overrides = []): string
    {
        $params = array_merge($_GET, $overrides);
        $params['tab'] = 'storefront';
        unset($params['_']);
        return http_build_query($params);
    }
}
?>
<div class="space-y-4" x-data="storefrontTab()">
    <!-- ─── Stats bar ─────────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide">ทั้งหมด</div>
            <div class="text-2xl font-bold text-gray-800 mt-1"><?= number_format((int) ($stats['total_cnt'] ?? 0)) ?></div>
            <div class="text-xs text-gray-400 mt-1">รายการใน cache</div>
        </div>
        <div class="bg-green-50 rounded-xl shadow p-4 border border-green-100">
            <div class="text-xs text-green-700 uppercase tracking-wide">เปิดขาย</div>
            <div class="text-2xl font-bold text-green-700 mt-1"><?= number_format((int) ($stats['enabled_cnt'] ?? 0)) ?></div>
            <div class="text-xs text-green-600 mt-1">บนหน้าร้านจริง</div>
        </div>
        <div class="bg-gray-50 rounded-xl shadow p-4 border border-gray-100">
            <div class="text-xs text-gray-600 uppercase tracking-wide">ปิดอยู่</div>
            <div class="text-2xl font-bold text-gray-700 mt-1"><?= number_format((int) ($stats['disabled_cnt'] ?? 0)) ?></div>
            <div class="text-xs text-gray-500 mt-1">ซ่อนจากหน้าร้าน</div>
        </div>
        <div class="bg-amber-50 rounded-xl shadow p-4 border border-amber-100">
            <div class="text-xs text-amber-700 uppercase tracking-wide">⚠️ เปิดขายแต่ราคา 0</div>
            <div class="text-2xl font-bold text-amber-700 mt-1"><?= number_format((int) ($stats['enabled_zero_cnt'] ?? 0)) ?></div>
            <div class="text-xs text-amber-600 mt-1">ควรปิดหรือตั้งราคา</div>
        </div>
    </div>

    <!-- ─── Bulk shortcuts ────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="text-sm font-semibold text-gray-700 mb-3">
            <i class="fas fa-bolt text-amber-500 mr-1"></i>คำสั่งแบบกลุ่ม (Bulk shortcuts)
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" @click="bulkDisableZeroPrice()"
                    class="px-4 py-2 bg-amber-100 text-amber-800 rounded-lg hover:bg-amber-200 text-sm font-medium">
                <i class="fas fa-ban mr-1"></i>ปิดสินค้าราคา 0 ทั้งหมด
            </button>
            <button type="button" @click="bulkDisableByCategory()"
                    :disabled="!filterCategory"
                    :class="filterCategory ? 'bg-red-100 text-red-800 hover:bg-red-200' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                    class="px-4 py-2 rounded-lg text-sm font-medium">
                <i class="fas fa-folder-minus mr-1"></i>ปิดหมวดหมู่ที่เลือก
            </button>
            <button type="button" @click="bulkDisableByDrugType()"
                    :disabled="!filterDrugType"
                    :class="filterDrugType ? 'bg-red-100 text-red-800 hover:bg-red-200' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                    class="px-4 py-2 rounded-lg text-sm font-medium">
                <i class="fas fa-pills mr-1"></i>ปิดชนิดยาที่เลือก
            </button>
            <button type="button" @click="bulkDisableOdooInactive()"
                    class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 text-sm font-medium">
                <i class="fas fa-eye-slash mr-1"></i>ปิดสินค้าที่ Odoo inactive
            </button>
        </div>
        <div class="text-xs text-gray-500 mt-2">
            <i class="fas fa-info-circle mr-1"></i>ทุกปุ่มจะ dry-run ก่อน เพื่อดูจำนวนที่จะโดนปิด แล้วยืนยันก่อนทำจริง
        </div>
    </div>

    <!-- ─── Filter bar ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="tab" value="storefront">

            <div class="flex-1 min-w-[240px]">
                <label class="text-xs text-gray-500 block mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="ชื่อ / SKU / รหัส / barcode / generic"
                       class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">หมวดหมู่</label>
                <select name="category" x-model="filterCategory" @change="$el.form.submit()"
                        class="px-3 py-2 border rounded-lg text-sm w-48">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c['category']) ?>"
                                <?= $categoryFilter === $c['category'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['category']) ?> (<?= (int) $c['n'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">ชนิดยา</label>
                <select name="drug_type" x-model="filterDrugType" @change="$el.form.submit()"
                        class="px-3 py-2 border rounded-lg text-sm w-40">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($drugTypes as $d): ?>
                        <option value="<?= htmlspecialchars($d['drug_type']) ?>"
                                <?= $drugTypeFilter === $d['drug_type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['drug_type']) ?> (<?= (int) $d['n'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">สถานะ</label>
                <select name="storefront_status" class="px-3 py-2 border rounded-lg text-sm">
                    <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>ทั้งหมด</option>
                    <option value="enabled"  <?= $statusFilter === 'enabled'  ? 'selected' : '' ?>>เปิดขาย</option>
                    <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>ปิดอยู่</option>
                </select>
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">ราคา</label>
                <select name="price_filter" class="px-3 py-2 border rounded-lg text-sm">
                    <option value=""          <?= $priceFilter === ''          ? 'selected' : '' ?>>ทั้งหมด</option>
                    <option value="zero"      <?= $priceFilter === 'zero'      ? 'selected' : '' ?>>ราคา 0</option>
                    <option value="has_price" <?= $priceFilter === 'has_price' ? 'selected' : '' ?>>มีราคา</option>
                </select>
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">สต็อก</label>
                <select name="stock_filter" class="px-3 py-2 border rounded-lg text-sm">
                    <option value=""    <?= $stockFilter === ''    ? 'selected' : '' ?>>ทั้งหมด</option>
                    <option value="in"  <?= $stockFilter === 'in'  ? 'selected' : '' ?>>มี (&gt;5)</option>
                    <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>ใกล้หมด (≤5)</option>
                    <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>หมด</option>
                </select>
            </div>

            <div>
                <label class="text-xs text-gray-500 block mb-1">ต่อหน้า</label>
                <select name="per_page" class="px-3 py-2 border rounded-lg text-sm">
                    <?php foreach ([20, 50, 100, 200] as $n): ?>
                        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                <i class="fas fa-filter mr-1"></i>กรอง
            </button>
            <?php if ($search || $categoryFilter || $drugTypeFilter || $statusFilter !== 'all' || $priceFilter || $stockFilter): ?>
                <a href="?tab=storefront" class="px-3 py-2 text-gray-500 hover:text-gray-700 text-sm">
                    <i class="fas fa-times mr-1"></i>ล้าง
                </a>
            <?php endif; ?>
        </form>
        <div class="mt-3 text-sm text-gray-600">
            แสดง <?= number_format($total > 0 ? $offset + 1 : 0) ?>–<?= number_format(min($offset + $perPage, $total)) ?>
            จาก <?= number_format($total) ?> รายการ
        </div>
    </div>

    <!-- ─── Bulk row selection bar (sticky when any selected) ──────────────── -->
    <div x-show="selectedIds.length > 0"
         x-transition
         class="sticky top-0 z-10 bg-blue-600 text-white rounded-xl shadow-lg p-3 flex items-center justify-between">
        <div class="text-sm">
            <i class="fas fa-check-square mr-1"></i>
            เลือกแล้ว <span class="font-bold" x-text="selectedIds.length"></span> รายการ
        </div>
        <div class="flex gap-2">
            <button type="button" @click="bulkToggleSelected(1)"
                    class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm">
                <i class="fas fa-eye mr-1"></i>เปิดขาย
            </button>
            <button type="button" @click="bulkToggleSelected(0)"
                    class="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm">
                <i class="fas fa-eye-slash mr-1"></i>ปิดขาย
            </button>
            <button type="button" @click="selectedIds = []"
                    class="px-3 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-sm">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- ─── Table ──────────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-3 w-10 text-center">
                            <input type="checkbox" @change="toggleAll($event.target.checked)"
                                   :checked="allSelected" class="rounded">
                        </th>
                        <th class="px-3 py-3 text-left">รหัส / SKU</th>
                        <th class="px-3 py-3 text-left">ชื่อสินค้า</th>
                        <th class="px-3 py-3 text-left">หมวดหมู่</th>
                        <th class="px-3 py-3 text-left">ชนิดยา</th>
                        <th class="px-3 py-3 text-right">ราคา</th>
                        <th class="px-3 py-3 text-center">สต็อก</th>
                        <th class="px-3 py-3 text-center">Odoo</th>
                        <th class="px-3 py-3 text-center">หน้าร้าน</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-gray-400">
                                <i class="fas fa-box-open text-3xl mb-2 block"></i>
                                ไม่พบสินค้าตาม filter ที่เลือก
                                <?php if ((int) ($stats['total_cnt'] ?? 0) === 0): ?>
                                    <div class="mt-2 text-sm">
                                        ยังไม่มีข้อมูลใน cache —
                                        <a href="?tab=catalog-sync" class="text-blue-600 hover:underline">
                                            ไปโหลดรายการสินค้าหลัก
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $id       = (int) $r['id'];
                            $price    = (float) ($r['online_price'] ?: $r['list_price'] ?: 0);
                            $stock    = (float) ($r['saleable_qty'] ?? 0);
                            $enabled  = (int) ($r['storefront_enabled'] ?? 0);
                            $isActive = (int) ($r['is_active'] ?? 0);
                            $isZero   = $price <= 0;
                        ?>
                            <tr class="hover:bg-gray-50" :class="selectedIds.includes(<?= $id ?>) ? 'bg-blue-50' : ''">
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" :checked="selectedIds.includes(<?= $id ?>)"
                                           @change="toggleRow(<?= $id ?>, $event.target.checked)"
                                           data-row-id="<?= $id ?>"
                                           class="row-checkbox rounded">
                                </td>
                                <td class="px-3 py-2">
                                    <div class="font-mono text-xs text-gray-800"><?= htmlspecialchars((string) $r['product_code']) ?></div>
                                    <div class="font-mono text-xs text-gray-500"><?= htmlspecialchars((string) ($r['sku'] ?? '-')) ?></div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars((string) ($r['name'] ?? '-')) ?></div>
                                    <?php if (!empty($r['generic_name'])): ?>
                                        <div class="text-xs text-blue-600"><?= htmlspecialchars((string) $r['generic_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php if (!empty($r['category'])): ?>
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-xs">
                                            <?= htmlspecialchars((string) $r['category']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php if (!empty($r['drug_type'])):
                                        $dtColor = [
                                            'OTC'        => 'bg-green-100 text-green-700',
                                            'Rx'         => 'bg-red-100 text-red-700',
                                            'Controlled' => 'bg-purple-100 text-purple-700',
                                            'Supplement' => 'bg-blue-100 text-blue-700',
                                            'Cosmetic'   => 'bg-pink-100 text-pink-700',
                                        ][$r['drug_type']] ?? 'bg-gray-100 text-gray-700';
                                    ?>
                                        <span class="px-2 py-0.5 rounded text-xs <?= $dtColor ?>">
                                            <?= htmlspecialchars((string) $r['drug_type']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <?php if ($isZero): ?>
                                        <span class="text-red-500 font-medium">฿0</span>
                                    <?php else: ?>
                                        <span class="font-semibold text-gray-800">฿<?= number_format($price, 2) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php
                                    $stockClass = $stock <= 0 ? 'text-red-600' : ($stock <= 5 ? 'text-amber-600' : 'text-green-600');
                                    ?>
                                    <span class="font-medium <?= $stockClass ?>"><?= number_format($stock) ?></span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($isActive): ?>
                                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">active</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-500 rounded text-xs">inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button type="button"
                                            @click="toggleStorefront(<?= $id ?>, <?= $enabled ? 0 : 1 ?>, <?= $isZero ? 'true' : 'false' ?>)"
                                            class="relative inline-flex items-center h-6 w-11 rounded-full transition-colors
                                                   <?= $enabled ? 'bg-green-500' : 'bg-gray-300' ?>">
                                        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transform transition-transform
                                                     <?= $enabled ? 'translate-x-5' : 'translate-x-0.5' ?>"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ─── Pagination ─────────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center text-sm text-gray-600">
            <div>หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?></div>
            <div class="flex items-center gap-1">
                <?php if ($page > 1): ?>
                    <a href="?<?= buildStorefrontQuery(['page' => $page - 1]) ?>"
                       class="px-3 py-1 border rounded hover:bg-gray-100">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?<?= buildStorefrontQuery(['page' => $i]) ?>"
                       class="px-3 py-1 border rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'hover:bg-gray-100' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= buildStorefrontQuery(['page' => $page + 1]) ?>"
                       class="px-3 py-1 border rounded hover:bg-gray-100">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Alpine.js lightweight (ใช้สำหรับ Alpine directives) -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>

<script>
function storefrontTab() {
    return {
        selectedIds: [],
        filterCategory: <?= json_encode($categoryFilter, JSON_UNESCAPED_UNICODE) ?>,
        filterDrugType: <?= json_encode($drugTypeFilter, JSON_UNESCAPED_UNICODE) ?>,

        get allSelected() {
            const boxes = document.querySelectorAll('.row-checkbox');
            return boxes.length > 0 && this.selectedIds.length >= boxes.length;
        },

        toggleRow(id, checked) {
            id = parseInt(id);
            if (checked && !this.selectedIds.includes(id)) {
                this.selectedIds.push(id);
            } else if (!checked) {
                this.selectedIds = this.selectedIds.filter(x => x !== id);
            }
        },

        toggleAll(checked) {
            const boxes = document.querySelectorAll('.row-checkbox');
            if (checked) {
                this.selectedIds = Array.from(boxes)
                    .map(b => parseInt(b.getAttribute('data-row-id')))
                    .filter(n => !isNaN(n));
            } else {
                this.selectedIds = [];
            }
        },

        async apiCall(action, body = {}) {
            const fd = new FormData();
            fd.append('action', action);
            Object.entries(body).forEach(([k, v]) => {
                if (Array.isArray(v)) {
                    v.forEach(item => fd.append(k + '[]', item));
                } else {
                    fd.append(k, v);
                }
            });
            const res = await fetch('/api/storefront-bulk.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            return res.json();
        },

        async bulkDisableZeroPrice() {
            const dry = await this.apiCall('bulk_disable_zero_price', { dry_run: 1 });
            if (!dry.success) return alert('Error: ' + (dry.error || 'unknown'));
            if (dry.affected === 0) return alert('ไม่มีสินค้าราคา 0 ที่เปิดขายอยู่');
            if (!confirm(`ปิดการขาย ${dry.affected} รายการที่ราคา 0 — ยืนยัน?`)) return;
            const res = await this.apiCall('bulk_disable_zero_price');
            alert(res.success ? `ปิดแล้ว ${res.affected} รายการ` : 'Error: ' + res.error);
            if (res.success) location.reload();
        },

        async bulkDisableByCategory() {
            if (!this.filterCategory) return alert('กรุณาเลือกหมวดหมู่ก่อน');
            const dry = await this.apiCall('bulk_disable_by_category', { category: this.filterCategory, dry_run: 1 });
            if (!dry.success) return alert('Error: ' + (dry.error || 'unknown'));
            if (dry.affected === 0) return alert('ไม่มีสินค้าในหมวดหมู่นี้ที่เปิดขายอยู่');
            if (!confirm(`ปิดการขาย ${dry.affected} รายการในหมวด "${this.filterCategory}" — ยืนยัน?`)) return;
            const res = await this.apiCall('bulk_disable_by_category', { category: this.filterCategory });
            alert(res.success ? `ปิดแล้ว ${res.affected} รายการ` : 'Error: ' + res.error);
            if (res.success) location.reload();
        },

        async bulkDisableByDrugType() {
            if (!this.filterDrugType) return alert('กรุณาเลือกชนิดยาก่อน');
            const dry = await this.apiCall('bulk_disable_by_drug_type', { drug_type: this.filterDrugType, dry_run: 1 });
            if (!dry.success) return alert('Error: ' + (dry.error || 'unknown'));
            if (dry.affected === 0) return alert('ไม่มีสินค้าในชนิดยานี้ที่เปิดขายอยู่');
            if (!confirm(`ปิดการขาย ${dry.affected} รายการในชนิดยา "${this.filterDrugType}" — ยืนยัน?`)) return;
            const res = await this.apiCall('bulk_disable_by_drug_type', { drug_type: this.filterDrugType });
            alert(res.success ? `ปิดแล้ว ${res.affected} รายการ` : 'Error: ' + res.error);
            if (res.success) location.reload();
        },

        async bulkDisableOdooInactive() {
            const dry = await this.apiCall('bulk_disable_by_odoo_inactive', { dry_run: 1 });
            if (!dry.success) return alert('Error: ' + (dry.error || 'unknown'));
            if (dry.affected === 0) return alert('ไม่มีสินค้า Odoo inactive ที่ยังเปิดขายอยู่');
            if (!confirm(`ปิดการขาย ${dry.affected} รายการที่ Odoo inactive — ยืนยัน?`)) return;
            const res = await this.apiCall('bulk_disable_by_odoo_inactive');
            alert(res.success ? `ปิดแล้ว ${res.affected} รายการ` : 'Error: ' + res.error);
            if (res.success) location.reload();
        },

        async bulkToggleSelected(enabled) {
            if (this.selectedIds.length === 0) return;
            const verb = enabled ? 'เปิดขาย' : 'ปิดขาย';
            if (!confirm(`${verb} ${this.selectedIds.length} รายการ — ยืนยัน?`)) return;
            const res = await this.apiCall('bulk_toggle', {
                ids: this.selectedIds,
                enabled: enabled,
            });
            if (res.success) {
                alert(`${verb} แล้ว ${res.affected} รายการ`);
                location.reload();
            } else {
                alert('Error: ' + (res.error || 'unknown'));
            }
        },

        async toggleStorefront(id, newValue, isZero) {
            if (newValue === 1 && isZero) {
                alert('สินค้านี้ราคา 0 — กรุณาตั้งราคาก่อนเปิดขาย');
                return;
            }
            const res = await this.apiCall('bulk_toggle', { ids: [id], enabled: newValue });
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.error || 'unknown'));
            }
        },
    };
}
</script>
