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
$hasOverridesCol = false;
try {
    $check = $db->query("SHOW COLUMNS FROM odoo_products_cache LIKE 'storefront_enabled'");
    $migrationReady = $check && $check->rowCount() > 0;
    $check2 = $db->query("SHOW COLUMNS FROM odoo_products_cache LIKE 'admin_overrides'");
    $hasOverridesCol = $check2 && $check2->rowCount() > 0;
} catch (Exception $e) {
    $migrationReady = false;
    $hasOverridesCol = false;
}

/**
 * รวมค่า admin_overrides (JSON) เข้ากับค่าจาก sync
 * Return:
 *   - effective: ค่าที่ใช้แสดง
 *   - sync: ค่าเดิมจาก Odoo (สำหรับ tooltip "ค่าจาก sync")
 *   - overridden: map ว่า field ไหนถูก admin override
 */
function mergeOverrides(array $row): array
{
    $overrides = [];
    if (!empty($row['admin_overrides'])) {
        $decoded = is_string($row['admin_overrides'])
            ? json_decode($row['admin_overrides'], true)
            : $row['admin_overrides'];
        if (is_array($decoded)) {
            $overrides = $decoded;
        }
    }
    $fields = ['name', 'generic_name', 'list_price', 'online_price', 'category'];
    $effective  = [];
    $overridden = [];
    foreach ($fields as $f) {
        $hasOverride  = array_key_exists($f, $overrides) && $overrides[$f] !== null && $overrides[$f] !== '';
        $effective[$f]  = $hasOverride ? $overrides[$f] : ($row[$f] ?? null);
        $overridden[$f] = $hasOverride;
    }
    return [
        'effective'  => $effective,
        'sync'       => $row,
        'overridden' => $overridden,
        'any_override' => !empty(array_filter($overridden)),
    ];
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

$overridesSelect = $hasOverridesCol ? ', admin_overrides' : '';
$listStmt = $db->prepare(
    "SELECT id, product_code, sku, name, generic_name, category, drug_type,
            list_price, online_price, saleable_qty, is_active, storefront_enabled,
            featured_order, last_synced_at{$overridesSelect}
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
            <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
            <button type="button" @click="bulkDisableOdooInactive()"
                    class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 text-sm font-medium">
                <i class="fas fa-eye-slash mr-1"></i>ปิดสินค้าที่ Odoo inactive
            </button>
            <?php endif; ?>
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
                        <th class="px-3 py-3 text-center w-14">แก้ไข</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" class="px-4 py-10 text-center text-gray-400">
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
                            $merged   = mergeOverrides($r);
                            $eff      = $merged['effective'];
                            $ovr      = $merged['overridden'];
                            $anyOvr   = $merged['any_override'];
                            $effPrice = (float) (($eff['online_price'] ?: $eff['list_price']) ?: 0);
                            $stock    = (float) ($r['saleable_qty'] ?? 0);
                            $enabled  = (int) ($r['storefront_enabled'] ?? 0);
                            $isActive = (int) ($r['is_active'] ?? 0);
                            $isZero   = $effPrice <= 0;
                            // data payload for edit modal (JSON-safe)
                            $modalData = [
                                'id'           => $id,
                                'product_code' => (string) ($r['product_code'] ?? ''),
                                'sync'         => [
                                    'name'         => (string) ($r['name']         ?? ''),
                                    'generic_name' => (string) ($r['generic_name'] ?? ''),
                                    'category'     => (string) ($r['category']     ?? ''),
                                    'list_price'   => (float)  ($r['list_price']   ?? 0),
                                    'online_price' => (float)  ($r['online_price'] ?? 0),
                                ],
                                'override'     => [
                                    'name'         => $ovr['name']         ? $eff['name']         : null,
                                    'generic_name' => $ovr['generic_name'] ? $eff['generic_name'] : null,
                                    'category'     => $ovr['category']     ? $eff['category']     : null,
                                    'list_price'   => $ovr['list_price']   ? (float) $eff['list_price']   : null,
                                    'online_price' => $ovr['online_price'] ? (float) $eff['online_price'] : null,
                                ],
                            ];
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
                                    <div class="font-medium text-gray-800 flex items-center gap-1">
                                        <?= htmlspecialchars((string) ($eff['name'] ?? '-')) ?>
                                        <?php if ($ovr['name']): ?>
                                            <span title="แอดมินแก้ไข (ค่า sync: <?= htmlspecialchars((string) ($r['name'] ?? '')) ?>)"
                                                  class="text-amber-500 text-xs"><i class="fas fa-pen-square"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($eff['generic_name'])): ?>
                                        <div class="text-xs text-blue-600 flex items-center gap-1">
                                            <?= htmlspecialchars((string) $eff['generic_name']) ?>
                                            <?php if ($ovr['generic_name']): ?>
                                                <span title="แอดมินแก้ไข" class="text-amber-500"><i class="fas fa-pen-square text-[10px]"></i></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php if (!empty($eff['category'])): ?>
                                        <span class="px-2 py-0.5 bg-gray-100 text-gray-700 rounded text-xs inline-flex items-center gap-1">
                                            <?= htmlspecialchars((string) $eff['category']) ?>
                                            <?php if ($ovr['category']): ?>
                                                <i class="fas fa-pen-square text-amber-500 text-[10px]"></i>
                                            <?php endif; ?>
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
                                        <span class="font-semibold text-gray-800 inline-flex items-center gap-1">
                                            ฿<?= number_format($effPrice, 2) ?>
                                            <?php if ($ovr['online_price'] || $ovr['list_price']): ?>
                                                <span title="ราคาถูกแอดมินแก้ไข" class="text-amber-500"><i class="fas fa-pen-square text-[10px]"></i></span>
                                            <?php endif; ?>
                                        </span>
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
                                <td class="px-3 py-2 text-center">
                                    <button type="button" @click='openEditModal(<?= json_encode($modalData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                            class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-500 hover:text-blue-600 transition-colors <?= $anyOvr ? 'text-amber-500 hover:text-amber-600' : '' ?>"
                                            title="<?= $anyOvr ? 'แก้ไข (มี admin override อยู่)' : 'แก้ไข' ?>">
                                        <i class="fas fa-pen"></i>
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

    <!-- ─── Edit Modal (admin override) ─────────────────────────────────────── -->
    <div x-show="editModal.open"
         x-cloak
         @keydown.escape.window="closeModal()"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display:none">
        <div class="fixed inset-0 bg-gray-900/50" @click="closeModal()"></div>
        <div class="relative flex items-center justify-center min-h-screen p-4">
            <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl" @click.stop>
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-3 border-b">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-pen-to-square mr-1 text-blue-600"></i>แก้ไขสินค้า
                        </h3>
                        <div class="text-xs text-gray-500 font-mono mt-1" x-text="'รหัส: ' + editModal.code"></div>
                    </div>
                    <button @click="closeModal()" type="button"
                            class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>

                <!-- Body -->
                <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
                    <div class="text-xs bg-blue-50 border border-blue-200 rounded-lg p-3 text-blue-900">
                        <i class="fas fa-info-circle mr-1"></i>
                        ค่าที่แก้ไขจะถูกเก็บเป็น <b>admin override</b> —
                        <b>การ sync ครั้งถัดไปจะไม่เขียนทับ</b> ค่านี้
                        (กดปุ่ม <i class="fas fa-undo text-amber-600"></i> เพื่อกลับไปใช้ค่าจาก Odoo)
                    </div>

                    <!-- Text fields: name -->
                    <div>
                        <label class="flex items-center justify-between text-sm font-medium text-gray-700 mb-1">
                            <span>ชื่อสินค้า</span>
                            <button type="button" x-show="isOverridden('name')" @click="clearField('name')"
                                    class="text-xs text-amber-600 hover:text-amber-800">
                                <i class="fas fa-undo mr-1"></i>กลับไปใช้ค่า sync
                            </button>
                        </label>
                        <input type="text" x-model="editModal.form.name"
                               class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        <div class="text-xs text-gray-400 mt-1">
                            ค่าจาก sync: <span class="font-mono" x-text="editModal.sync.name || '—'"></span>
                        </div>
                    </div>

                    <!-- generic_name -->
                    <div>
                        <label class="flex items-center justify-between text-sm font-medium text-gray-700 mb-1">
                            <span>ชื่อสามัญ (generic name)</span>
                            <button type="button" x-show="isOverridden('generic_name')" @click="clearField('generic_name')"
                                    class="text-xs text-amber-600 hover:text-amber-800">
                                <i class="fas fa-undo mr-1"></i>กลับไปใช้ค่า sync
                            </button>
                        </label>
                        <input type="text" x-model="editModal.form.generic_name"
                               class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        <div class="text-xs text-gray-400 mt-1">
                            ค่าจาก sync: <span class="font-mono" x-text="editModal.sync.generic_name || '—'"></span>
                        </div>
                    </div>

                    <!-- category -->
                    <div>
                        <label class="flex items-center justify-between text-sm font-medium text-gray-700 mb-1">
                            <span>หมวดหมู่</span>
                            <button type="button" x-show="isOverridden('category')" @click="clearField('category')"
                                    class="text-xs text-amber-600 hover:text-amber-800">
                                <i class="fas fa-undo mr-1"></i>กลับไปใช้ค่า sync
                            </button>
                        </label>
                        <input type="text" x-model="editModal.form.category"
                               class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        <div class="text-xs text-gray-400 mt-1">
                            ค่าจาก sync: <span class="font-mono" x-text="editModal.sync.category || '—'"></span>
                        </div>
                    </div>

                    <!-- Price fields -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="flex items-center justify-between text-sm font-medium text-gray-700 mb-1">
                                <span>ราคาปกติ (list_price)</span>
                                <button type="button" x-show="isOverridden('list_price')" @click="clearField('list_price')"
                                        class="text-xs text-amber-600 hover:text-amber-800">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-400">฿</span>
                                <input type="number" step="0.01" min="0"
                                       x-model.number="editModal.form.list_price"
                                       class="w-full pl-7 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                sync: ฿<span class="font-mono" x-text="Number(editModal.sync.list_price || 0).toFixed(2)"></span>
                            </div>
                        </div>

                        <div>
                            <label class="flex items-center justify-between text-sm font-medium text-gray-700 mb-1">
                                <span>ราคาออนไลน์ (online_price)</span>
                                <button type="button" x-show="isOverridden('online_price')" @click="clearField('online_price')"
                                        class="text-xs text-amber-600 hover:text-amber-800">
                                    <i class="fas fa-undo"></i>
                                </button>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-400">฿</span>
                                <input type="number" step="0.01" min="0"
                                       x-model.number="editModal.form.online_price"
                                       class="w-full pl-7 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="text-xs text-gray-400 mt-1">
                                sync: ฿<span class="font-mono" x-text="Number(editModal.sync.online_price || 0).toFixed(2)"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between px-5 py-3 border-t bg-gray-50 rounded-b-xl">
                    <button @click="revertAll()" type="button"
                            class="text-sm text-red-600 hover:text-red-800">
                        <i class="fas fa-trash mr-1"></i>ล้าง override ทั้งหมด
                    </button>
                    <div class="flex gap-2">
                        <button @click="closeModal()" type="button"
                                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">
                            ยกเลิก
                        </button>
                        <button @click="saveAllChanges()" type="button"
                                :disabled="editModal.saving"
                                class="px-5 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-save mr-1" x-show="!editModal.saving"></i>
                            <i class="fas fa-spinner fa-spin mr-1" x-show="editModal.saving"></i>
                            บันทึก
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alpine.js lightweight (ใช้สำหรับ Alpine directives) -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>

<script>
function storefrontTab() {
    return {
        selectedIds: [],
        filterCategory: <?= json_encode($categoryFilter, JSON_UNESCAPED_UNICODE) ?>,
        filterDrugType: <?= json_encode($drugTypeFilter, JSON_UNESCAPED_UNICODE) ?>,

        // Edit modal state
        editModal: {
            open: false,
            id: null,
            code: '',
            sync: { name: '', generic_name: '', category: '', list_price: 0, online_price: 0 },
            override: { name: null, generic_name: null, category: null, list_price: null, online_price: null },
            form: { name: '', generic_name: '', category: '', list_price: 0, online_price: 0 },
            saving: false,
        },

        openEditModal(data) {
            const sync = data.sync || {};
            const ovr = data.override || {};
            this.editModal = {
                open: true,
                id: data.id,
                code: data.product_code || '',
                sync: {
                    name:         String(sync.name         ?? ''),
                    generic_name: String(sync.generic_name ?? ''),
                    category:     String(sync.category     ?? ''),
                    list_price:   Number(sync.list_price   ?? 0),
                    online_price: Number(sync.online_price ?? 0),
                },
                override: {
                    name:         ovr.name         ?? null,
                    generic_name: ovr.generic_name ?? null,
                    category:     ovr.category     ?? null,
                    list_price:   ovr.list_price   ?? null,
                    online_price: ovr.online_price ?? null,
                },
                // form = effective = override ?? sync
                form: {
                    name:         ovr.name         ?? String(sync.name         ?? ''),
                    generic_name: ovr.generic_name ?? String(sync.generic_name ?? ''),
                    category:     ovr.category     ?? String(sync.category     ?? ''),
                    list_price:   ovr.list_price   ?? Number(sync.list_price   ?? 0),
                    online_price: ovr.online_price ?? Number(sync.online_price ?? 0),
                },
                saving: false,
            };
        },

        closeModal() { this.editModal.open = false; },

        isOverridden(field) {
            const v = this.editModal.override[field];
            return v !== null && v !== undefined && v !== '';
        },

        async clearField(field) {
            const res = await this.apiCall('clear_override', {
                id: this.editModal.id,
                field: field,
            });
            if (res && res.success) {
                // revert form value to sync value in-place
                this.editModal.form[field] = this.editModal.sync[field];
                this.editModal.override[field] = null;
            } else {
                alert('Error: ' + (res?.error || 'unknown'));
            }
        },

        async saveAllChanges() {
            this.editModal.saving = true;
            try {
                const textFields    = ['name', 'generic_name', 'category'];
                const numericFields = ['list_price', 'online_price'];
                const fields = [...textFields, ...numericFields];

                const opsToRun = [];
                for (const f of fields) {
                    const isNum = numericFields.includes(f);
                    const newVal  = isNum
                        ? Number(this.editModal.form[f] ?? 0)
                        : String(this.editModal.form[f] ?? '').trim();
                    const syncVal = isNum
                        ? Number(this.editModal.sync[f] ?? 0)
                        : String(this.editModal.sync[f] ?? '').trim();
                    const hasOvr  = this.isOverridden(f);

                    // Case 1: new == sync → ensure no override (clear if exists)
                    if ((isNum ? newVal === syncVal : newVal === syncVal)) {
                        if (hasOvr) {
                            opsToRun.push({ action: 'clear_override', field: f });
                        }
                        continue;
                    }
                    // Case 2: new != sync → set override
                    opsToRun.push({ action: 'update_override', field: f, value: newVal });
                }

                if (opsToRun.length === 0) {
                    alert('ไม่มีการเปลี่ยนแปลง');
                    this.closeModal();
                    return;
                }

                let okCount = 0;
                for (const op of opsToRun) {
                    const res = await this.apiCall(op.action, {
                        id: this.editModal.id,
                        field: op.field,
                        ...(op.value !== undefined ? { value: op.value } : {}),
                    });
                    if (res && res.success) okCount++;
                }
                alert('บันทึก ' + okCount + '/' + opsToRun.length + ' การเปลี่ยนแปลง');
                location.reload();
            } finally {
                this.editModal.saving = false;
            }
        },

        async revertAll() {
            if (!confirm('ล้าง admin override ทั้งหมดของสินค้านี้ (กลับไปใช้ค่า sync) — ยืนยัน?')) return;
            const res = await this.apiCall('clear_all_overrides', { id: this.editModal.id });
            if (res && res.success) {
                alert('ล้างทั้งหมดแล้ว');
                location.reload();
            } else {
                alert('Error: ' + (res?.error || 'unknown'));
            }
        },

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
