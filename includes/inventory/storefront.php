<?php
/**
 * Tab: storefront — Storefront Catalog Manager
 *
 * หน้าจัดการสินค้าที่ **วางขายหน้าร้านจริง** (curated subset ของตารางสินค้า)
 *   - Filter: search, category, drug_type, storefront_status, price range, stock
 *   - Bulk ops:
 *       A. Row-based   — checkbox + ปิด/เปิด storefront
 *       B. Filter-based — shortcut: ปิดราคา 0, ปิด category, ปิด drug_type, ปิดสินค้าที่ถูกปิดในระบบหลัก
 *   - Inline toggle `storefront_enabled` (call api/storefront-bulk.php)
 *   - Pagination
 *
 * Scope: ใช้ line_account_id จาก session (`current_bot_id`)
 */

$currentBotId = (int) ($_SESSION['current_bot_id'] ?? 1);

// ─── Verify migration ran ──────────────────────────────────────────────────────
// Post-unification (2026-05-15) the storefront tab reads from business_items.
// We treat business_items.is_active as the "storefront enabled" flag — there's
// no separate storefront_enabled column on business_items.
$migrationReady = false;
$hasOverridesCol = false; // business_items has no admin_overrides JSON
try {
    $check = $db->query("SHOW TABLES LIKE 'business_items'");
    $migrationReady = $check && $check->rowCount() > 0;
} catch (Exception $e) {
    $migrationReady = false;
}

/**
 * รวมค่า admin_overrides (JSON) เข้ากับค่าจาก sync
 * Return:
 *   - effective: ค่าที่ใช้แสดง
 *   - sync: ค่าเดิมจาก sync (สำหรับ tooltip "ค่าจาก sync")
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
        <p class="text-yellow-700 mb-3">Tab นี้ต้องการคอลัมน์ <code>storefront_enabled</code> ในตารางสินค้า</p>
        <div class="bg-white rounded-lg p-3 font-mono text-sm text-gray-700">
            mysql -u &lt;user&gt; -p &lt;db&gt; &lt; database/migration_storefront_split.sql
        </div>
    </div>
    <?php
    return;
}

// ─── Inline create handler — "เพิ่มสินค้า" button on storefront tab ──────────
// NOTE (2026-05-15): unified to write to `business_items` (the canonical
// product table). The old `shop_products` cacheTable is being retired —
// see database/migration_2026-05-15_unify_products_to_business_items.sql.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'storefront_quick_create') {
    try {
        $cacheTable = 'business_items';
        // Best-effort ensure image_gallery column exists on business_items
        // (idempotent — same migration we ship as a versioned SQL file).
        try {
            $hasGallery = $db->query("SHOW COLUMNS FROM {$cacheTable} LIKE 'image_gallery'");
            if (!$hasGallery || $hasGallery->rowCount() === 0) {
                try { $db->exec("ALTER TABLE {$cacheTable} ADD COLUMN image_gallery LONGTEXT NULL AFTER image_url"); } catch (Exception $e) {}
            }
        } catch (Exception $e) { /* non-fatal */ }

        $skuInput   = trim((string) ($_POST['sku'] ?? ''));
        if ($skuInput === '') {
            $productCode = trim((string) ($_POST['product_code'] ?? ''));
            $skuInput = $productCode !== '' ? $productCode : ('LOC-' . time() . '-' . random_int(100, 999));
        }
        $price       = (float) ($_POST['price'] ?? 0);
        $salePrice   = ($_POST['sale_price'] ?? '') !== '' ? (float) $_POST['sale_price'] : null;

        // Image gallery: textarea is one URL per line; persist as JSON so the
        // mini-app product detail page can render multiple images.
        $galleryJson = null;
        if (!empty($_POST['image_gallery'])) {
            $urls = preg_split('/[\r\n,]+/', (string) $_POST['image_gallery']);
            $urls = array_values(array_filter(array_map('trim', $urls), function ($u) {
                return $u !== '';
            }));
            if (!empty($urls)) {
                $galleryJson = json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        // Resolve category text → business_categories.id. We look up by name
        // scoped to the current line_account first, then global. If nothing
        // matches we leave category_id NULL (free-text categories were
        // historically common in shop_products, so this is a safe default).
        $categoryText = trim((string) ($_POST['category'] ?? ''));
        $categoryId = null;
        if ($categoryText !== '') {
            try {
                $catStmt = $db->prepare(
                    "SELECT id FROM business_categories
                     WHERE name = ?
                       AND (line_account_id = ? OR line_account_id IS NULL)
                     ORDER BY (line_account_id = ?) DESC
                     LIMIT 1"
                );
                $catStmt->execute([$categoryText, (int) $currentBotId, (int) $currentBotId]);
                $catRow = $catStmt->fetchColumn();
                $categoryId = $catRow ? (int) $catRow : null;
            } catch (Exception $e) {
                $categoryId = null;
            }
        }

        $fields = [
            'line_account_id'    => (int) $currentBotId,
            'item_type'          => 'product',
            'category_id'        => $categoryId,
            'name'               => $_POST['name'] ?? '',
            'name_en'            => $_POST['name_en'] ?? null,
            'generic_name'       => $_POST['generic_name'] ?? null,
            'manufacturer'       => $_POST['manufacturer'] ?? null,
            'description'        => $_POST['description'] ?? null,
            'usage_instructions' => $_POST['usage_instructions'] ?? null,
            'image_url'          => $_POST['image_url'] ?? null,
            'image_gallery'      => $galleryJson,
            'sku'                => $skuInput,
            'barcode'            => $_POST['barcode'] ?? null,
            'price'              => $price,
            'sale_price'         => $salePrice,
            'stock'              => (float) ($_POST['stock'] ?? 0),
            'is_active'          => 1,
        ];
        $cols = array_keys($fields);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO {$cacheTable} (" . implode(',', $cols) . ") VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($fields));
        $_SESSION['storefront_message'] = 'สร้างสินค้าใหม่และเผยแพร่บนหน้าร้านสำเร็จ';
    } catch (Exception $e) {
        $_SESSION['storefront_error'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
    }
    $redirect = array_merge($_GET, ['tab' => 'storefront']);
    unset($redirect['_']);
    echo "<script>window.location.href='?" . http_build_query($redirect) . "';</script>";
    exit;
}

$storefrontMessage = $_SESSION['storefront_message'] ?? null;
$storefrontError   = $_SESSION['storefront_error']   ?? null;
unset($_SESSION['storefront_message'], $_SESSION['storefront_error']);

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
// Reads from `business_items` joined to `business_categories` (FK
// p.category_id → c.id). The legacy shop_products columns map as follows:
//   product_code   → sku
//   list_price     → price
//   online_price   → sale_price
//   saleable_qty   → stock
//   storefront_enabled → is_active
//   category (varchar) → c.name (via JOIN on category_id)
// drug_type and featured_order have no equivalents on business_items and are
// silently dropped from filters / ordering.
$where  = ['p.line_account_id = ?'];
$params = [$currentBotId];

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.generic_name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($categoryFilter !== '') {
    $where[]  = "c.name = ?";
    $params[] = $categoryFilter;
}
// drug_type filter is intentionally a no-op (column does not exist on business_items).
if ($statusFilter === 'enabled') {
    $where[] = "p.is_active = 1";
} elseif ($statusFilter === 'disabled') {
    $where[] = "p.is_active = 0";
}
if ($priceFilter === 'zero') {
    $where[] = "(p.sale_price IS NULL OR p.sale_price = 0) AND (p.price IS NULL OR p.price = 0)";
} elseif ($priceFilter === 'has_price') {
    $where[] = "(p.sale_price > 0 OR p.price > 0)";
} elseif ($priceFilter === 'range' && ($priceMin !== null || $priceMax !== null)) {
    $priceCol = "COALESCE(NULLIF(p.sale_price,0), p.price)";
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
    $where[] = "p.stock > 5";
} elseif ($stockFilter === 'low') {
    $where[] = "p.stock > 0 AND p.stock <= 5";
} elseif ($stockFilter === 'out') {
    $where[] = "p.stock <= 0";
}

$whereSql = implode(' AND ', $where);

// ─── Query data ────────────────────────────────────────────────────────────────
$countStmt = $db->prepare(
    "SELECT COUNT(*)
     FROM business_items p
     LEFT JOIN business_categories c ON c.id = p.category_id
     WHERE {$whereSql}"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil(max(1, $total) / $perPage));

// Alias business_items columns back to the shop_products names the template
// (further down this file) still expects, so we don't have to rewrite the
// table markup. drug_type / featured_order / admin_overrides have no
// equivalents and surface as NULL.
$listStmt = $db->prepare(
    "SELECT p.id,
            p.sku                AS product_code,
            p.sku                AS sku,
            p.name,
            p.generic_name,
            c.name               AS category,
            NULL                 AS drug_type,
            p.price              AS list_price,
            p.sale_price         AS online_price,
            p.stock              AS saleable_qty,
            p.is_active,
            p.is_active          AS storefront_enabled,
            NULL                 AS featured_order,
            p.updated_at         AS last_synced_at,
            NULL                 AS admin_overrides,
            p.image_url,
            p.unit,
            pu.unit_list,
            pu.unit_count
     FROM business_items p
     LEFT JOIN business_categories c ON c.id = p.category_id
     LEFT JOIN (
        SELECT product_id,
               GROUP_CONCAT(unit_name ORDER BY factor ASC SEPARATOR ' · ') AS unit_list,
               COUNT(*) AS unit_count
        FROM product_units
        WHERE is_active = 1
        GROUP BY product_id
     ) pu ON pu.product_id = p.id
     WHERE {$whereSql}
     ORDER BY p.is_active DESC, p.name ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Dropdown options
$categories = [];
try {
    $catStmt = $db->prepare(
        "SELECT c.name AS category, COUNT(*) AS n
         FROM business_items p
         INNER JOIN business_categories c ON c.id = p.category_id
         WHERE p.line_account_id = ? AND c.name IS NOT NULL AND c.name <> ''
         GROUP BY c.name
         ORDER BY n DESC, c.name ASC"
    );
    $catStmt->execute([$currentBotId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

// drug_type has no equivalent on business_items; surface an empty list so
// the dropdown stays present but inert.
$drugTypes = [];

// Storefront stats — keyed off business_items.is_active (= storefront_enabled)
$statStmt = $db->prepare(
    "SELECT
        SUM(is_active = 1)                              AS enabled_cnt,
        SUM(is_active = 0)                              AS disabled_cnt,
        SUM(is_active = 1 AND (sale_price IS NULL OR sale_price = 0)
                          AND (price      IS NULL OR price      = 0)) AS enabled_zero_cnt,
        COUNT(*) AS total_cnt
     FROM business_items
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
    <?php if (!empty($storefrontMessage)): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-3 text-sm text-green-700"><?= htmlspecialchars($storefrontMessage) ?></div>
    <?php endif; ?>
    <?php if (!empty($storefrontError)): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700"><?= htmlspecialchars($storefrontError) ?></div>
    <?php endif; ?>

    <!-- ─── Quick action: เพิ่มสินค้า + Import/Export CSV ─────────────────── -->
    <div class="bg-white rounded-xl shadow p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-sm text-gray-700">
                <i class="fas fa-store text-blue-500 mr-1"></i>เพิ่มสินค้าใหม่ลงหน้าร้านทันที (จะถูกตั้งให้เผยแพร่อัตโนมัติ)
            </div>
            <button type="button" onclick="openStorefrontProductModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                <i class="fas fa-plus mr-1"></i>เพิ่มสินค้า
            </button>
        </div>

        <!-- CSV Import/Export panel — 2026-05-25 -->
        <div class="border-t border-gray-100 pt-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-gray-600 flex items-center gap-2">
                    <i class="fas fa-file-csv text-emerald-600"></i>
                    <span><strong>นำเข้า/ส่งออกผ่าน CSV</strong> — เพิ่ม/อัปเดตสินค้าครั้งละหลายรายการ
                        พร้อมรายละเอียดตัวยา (ผู้ผลิต, ตัวยาสำคัญ, วิธีใช้, สรรพคุณ, รูปภาพ)
                        <span class="text-gray-400">— Excel ให้ Save As → CSV ก่อน</span>
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="reyaMasterCatalogOpen()"
                            class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs inline-flex items-center gap-1 shadow-sm">
                        <i class="fas fa-store"></i> เลือกจากคลังกลาง REYA
                    </button>
                    <a href="/api/inventory-csv.php?action=template" download
                       class="px-3 py-1.5 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 rounded-lg text-xs inline-flex items-center gap-1">
                        <i class="fas fa-download"></i> ดาวน์โหลด Template
                    </a>
                    <button type="button" onclick="document.getElementById('csvImportInput').click()"
                            class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg text-xs inline-flex items-center gap-1">
                        <i class="fas fa-file-import"></i> นำเข้า CSV
                    </button>
                    <a href="/api/inventory-csv.php?action=export"
                       class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-xs inline-flex items-center gap-1">
                        <i class="fas fa-file-export"></i> ส่งออกทั้งหมด
                    </a>
                </div>
            </div>
            <input id="csvImportInput" type="file" accept=".csv,text/csv" hidden onchange="reyaImportCSV(this)">
            <div id="csvImportStatus" class="mt-3 hidden text-xs"></div>
        </div>
    </div>

    <script>
    // Inline CSV import handler — kept in same file to avoid extra JS bundle
    function reyaImportCSV(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('action', 'import');
        fd.append('file', file);
        fd.append('mode', 'upsert');  // update existing SKUs

        const box = document.getElementById('csvImportStatus');
        box.className = 'mt-3 text-xs p-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-700';
        box.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> กำลังนำเข้า ' + file.name + ' …';
        box.classList.remove('hidden');

        fetch('/api/inventory-csv.php?action=import', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => {
                if (j.ok) {
                    box.className = 'mt-3 text-xs p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800';
                    let msg = '<i class="fas fa-check-circle mr-1"></i> นำเข้าสำเร็จ — '
                            + 'เพิ่ม ' + j.inserted + ' รายการ · '
                            + 'อัปเดต ' + j.updated + ' รายการ · '
                            + 'ข้าม ' + j.skipped + ' รายการ.';
                    if (j.errors && j.errors.length) {
                        msg += '<br><span class="text-amber-700">⚠ บางบรรทัดข้าม: ' + j.errors.slice(0, 5).join(' / ') + '</span>';
                    }
                    msg += '<br><a href="" class="text-emerald-700 underline">รีโหลดเพื่อดูสินค้าใหม่</a>';
                    box.innerHTML = msg;
                    setTimeout(() => location.reload(), 2500);
                } else {
                    box.className = 'mt-3 text-xs p-3 rounded-lg bg-red-50 border border-red-200 text-red-800';
                    box.innerHTML = '<i class="fas fa-exclamation-circle mr-1"></i> ผิดพลาด: ' + (j.message || j.error);
                }
            })
            .catch(e => {
                box.className = 'mt-3 text-xs p-3 rounded-lg bg-red-50 border border-red-200 text-red-800';
                box.innerHTML = '<i class="fas fa-exclamation-circle mr-1"></i> เครือข่าย: ' + e.message;
            })
            .finally(() => { input.value = ''; });
    }

    // 2026-06-02: delete a single product from the storefront list
    function reyaDeleteProduct(id, name) {
        if (!id) return;
        if (!confirm('ลบสินค้า "' + (name || ('#' + id)) + '" ?\n\nการลบนี้ถาวร — ถ้าสินค้าเคยถูกใช้ในออเดอร์/จ่ายยา ระบบจะปิดการขายแทนการลบเพื่อกันข้อมูลเสียหาย')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fetch('/api/inventory-csv.php?action=delete', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => {
                if (j.ok) {
                    if (j.soft) {
                        alert('สินค้านี้มีประวัติการใช้งาน — ปิดการขายแทนการลบ (ข้อมูลยังอยู่)');
                    }
                    location.reload();
                } else {
                    alert('ลบไม่สำเร็จ: ' + (j.message || j.error || 'unknown'));
                }
            })
            .catch(e => alert('เครือข่าย: ' + e.message));
    }
    </script>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Master Catalog Picker — เลือกสินค้าจากคลังกลาง REYA (4,297 รายการ)
         ═══════════════════════════════════════════════════════════════════════ -->
    <div id="masterCatalogModal" class="fixed inset-0 z-[10000] hidden">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" onclick="reyaMasterCatalogClose()"></div>

        <!-- Dialog -->
        <div class="relative mx-auto my-6 max-w-6xl bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col"
             style="max-height: calc(100vh - 3rem);">

            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 bg-gradient-to-r from-teal-50 to-emerald-50">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-teal-600 text-white flex items-center justify-center">
                        <i class="fas fa-store text-lg"></i>
                    </span>
                    <div>
                        <div class="font-bold text-slate-900">คลังกลางสินค้า REYA</div>
                        <div class="text-xs text-slate-500">เลือกสินค้าที่ต้องการเพิ่มเข้าหน้าร้านของคุณ —
                            รายละเอียดตัวยาจะถูกนำเข้าให้ทันที</div>
                    </div>
                </div>
                <button type="button" onclick="reyaMasterCatalogClose()"
                        class="w-9 h-9 rounded-full hover:bg-slate-200 text-slate-600 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Search + summary bar -->
            <div class="px-5 py-3 border-b border-slate-100 flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px] relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input id="mcSearch" type="text" placeholder="ค้นหา ชื่อสินค้า, SKU, ตัวยา, ผู้ผลิต…"
                           class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div class="text-xs text-slate-600">
                    <span>พบ <strong id="mcTotal" class="text-slate-900">0</strong> รายการ ·</span>
                    <span>เลือก <strong id="mcSelected" class="text-teal-700">0</strong> รายการ</span>
                </div>
            </div>

            <!-- Item list -->
            <div id="mcList" class="flex-1 overflow-y-auto px-5 py-3 bg-slate-50">
                <div class="text-center text-slate-400 py-12">
                    <i class="fas fa-spinner fa-spin text-2xl"></i>
                    <div class="text-sm mt-2">กำลังโหลด…</div>
                </div>
            </div>

            <!-- Pagination -->
            <div id="mcPagination" class="px-5 py-2 border-t border-slate-100 flex items-center justify-center gap-2 text-xs"></div>

            <!-- Footer / import bar -->
            <div class="px-5 py-3 border-t border-slate-200 bg-slate-50 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <label class="flex items-center gap-1 text-slate-600">
                        ราคาเริ่มต้น
                        <input id="mcDefaultPrice" type="number" min="0" step="0.01" value="0"
                               class="w-20 px-2 py-1 border border-slate-300 rounded text-right">
                        บาท
                    </label>
                    <label class="flex items-center gap-1 text-slate-600">
                        สต็อกเริ่มต้น
                        <input id="mcDefaultStock" type="number" min="0" step="1" value="0"
                               class="w-20 px-2 py-1 border border-slate-300 rounded text-right">
                    </label>
                    <label class="flex items-center gap-1 text-slate-700">
                        <input id="mcActivate" type="checkbox" class="rounded">
                        เปิดขายทันทีหลังนำเข้า
                    </label>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="reyaMasterClearSelection()"
                            class="px-3 py-2 text-xs text-slate-600 hover:text-slate-900">
                        ล้างการเลือก
                    </button>
                    <button type="button" id="mcImportBtn" onclick="reyaMasterImport()" disabled
                            class="px-4 py-2 bg-teal-600 hover:bg-teal-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium inline-flex items-center gap-1">
                        <i class="fas fa-file-import"></i>
                        <span id="mcImportBtnLabel">นำเข้า 0 รายการ</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    /* ─────────────────────────────────────────────────────────────────────────
       Master Catalog Picker — state + behaviour
       ───────────────────────────────────────────────────────────────────────── */
    (function () {
        const PER_PAGE = 50;
        const state = {
            query:       '',
            page:        1,
            totalPages:  1,
            total:       0,
            items:       [],
            selected:    new Map(),  // id → {sku, name}
            loading:     false,
            searchDebounce: null,
        };

        // --- Helpers ---
        const $ = (id) => document.getElementById(id);
        const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        function updateImportBtn() {
            const n = state.selected.size;
            $('mcSelected').textContent = n;
            $('mcImportBtnLabel').textContent = 'นำเข้า ' + n + ' รายการ';
            $('mcImportBtn').disabled = (n === 0);
        }

        // --- Fetch one page ---
        async function loadPage(page = state.page) {
            state.loading = true;
            state.page = page;
            $('mcList').innerHTML =
                '<div class="text-center text-slate-400 py-12"><i class="fas fa-spinner fa-spin text-2xl"></i><div class="text-sm mt-2">กำลังโหลด…</div></div>';

            const url = '/api/master-catalog.php?action=list'
                      + '&q=' + encodeURIComponent(state.query)
                      + '&page=' + page
                      + '&per_page=' + PER_PAGE;
            try {
                const r = await fetch(url, { credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) throw new Error(j.message || j.error || 'load_failed');

                state.items      = j.items || [];
                state.total      = j.total | 0;
                state.totalPages = j.pages | 0 || 1;
                $('mcTotal').textContent = state.total.toLocaleString('th-TH');
                renderList();
                renderPagination();
            } catch (e) {
                $('mcList').innerHTML =
                    '<div class="text-center text-red-600 py-12"><i class="fas fa-exclamation-circle text-2xl"></i><div class="text-sm mt-2">' + escHtml(e.message) + '</div></div>';
            } finally {
                state.loading = false;
            }
        }

        function renderList() {
            if (!state.items.length) {
                $('mcList').innerHTML =
                    '<div class="text-center text-slate-400 py-12"><i class="fas fa-search text-2xl"></i><div class="text-sm mt-2">ไม่พบสินค้าที่ตรงกัน</div></div>';
                return;
            }
            const placeholderImg = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Crect width=%2264%22 height=%2264%22 fill=%22%23f1f5f9%22/%3E%3Ctext x=%2232%22 y=%2240%22 text-anchor=%22middle%22 font-size=%2228%22 fill=%22%23cbd5e1%22%3E📦%3C/text%3E%3C/svg%3E';

            const rows = state.items.map(item => {
                const id = +item.id;
                const isImported  = !!item.already_imported;
                const isSelected  = state.selected.has(id);
                const sub = [
                    item.manufacturer,
                    item.generic_name,
                    item.pack_size,
                    item.unit,
                ].filter(Boolean).map(escHtml).join(' · ');
                return `
                <label class="block bg-white rounded-lg border ${isSelected ? 'border-teal-500 ring-2 ring-teal-200' : 'border-slate-200'} p-3 cursor-pointer hover:border-teal-400 transition mb-2">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" data-master-id="${id}" data-sku="${escHtml(item.sku)}" data-name="${escHtml(item.name)}"
                               ${isSelected ? 'checked' : ''} ${isImported ? 'disabled' : ''}
                               class="mt-1 w-4 h-4 text-teal-600 rounded">
                        <img src="${escHtml(item.image_url || '')}" onerror="this.src='${placeholderImg}'"
                             class="w-12 h-12 rounded-lg object-cover bg-slate-100 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-sm text-slate-900 truncate">${escHtml(item.name)}</span>
                                ${isImported ? '<span class="text-[10px] px-1.5 py-0.5 bg-emerald-100 text-emerald-700 rounded">นำเข้าแล้ว</span>' : ''}
                            </div>
                            <div class="text-[11px] text-slate-500 mt-0.5">SKU ${escHtml(item.sku)}${item.name_en ? ' · <span class="text-slate-400">' + escHtml(item.name_en) + '</span>' : ''}</div>
                            ${sub ? '<div class="text-[11px] text-slate-600 mt-0.5">' + sub + '</div>' : ''}
                        </div>
                    </div>
                </label>`;
            });
            $('mcList').innerHTML = rows.join('');

            // Wire checkbox events
            $('mcList').querySelectorAll('input[type=checkbox][data-master-id]').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const id = +e.target.dataset.masterId;
                    if (e.target.checked) {
                        state.selected.set(id, {
                            sku: e.target.dataset.sku,
                            name: e.target.dataset.name,
                        });
                    } else {
                        state.selected.delete(id);
                    }
                    // Re-style the parent card
                    const card = e.target.closest('label');
                    if (e.target.checked) {
                        card.classList.add('border-teal-500', 'ring-2', 'ring-teal-200');
                        card.classList.remove('border-slate-200');
                    } else {
                        card.classList.remove('border-teal-500', 'ring-2', 'ring-teal-200');
                        card.classList.add('border-slate-200');
                    }
                    updateImportBtn();
                });
            });
        }

        function renderPagination() {
            const wrap = $('mcPagination');
            if (state.totalPages <= 1) { wrap.innerHTML = ''; return; }

            const cur = state.page;
            const tot = state.totalPages;
            const btn = (label, page, disabled = false, active = false) =>
                `<button type="button" ${disabled ? 'disabled' : ''} onclick="window.__mcGoto(${page})"
                    class="px-2.5 py-1 rounded ${active ? 'bg-teal-600 text-white' : disabled ? 'text-slate-300' : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-700'}">${label}</button>`;

            const pages = [];
            const around = 2;
            for (let p = 1; p <= tot; p++) {
                if (p === 1 || p === tot || Math.abs(p - cur) <= around) {
                    pages.push(p);
                } else if (pages[pages.length - 1] !== '…') {
                    pages.push('…');
                }
            }
            wrap.innerHTML =
                btn('« แรก', 1, cur === 1) +
                btn('‹', cur - 1, cur === 1) +
                pages.map(p => p === '…' ? '<span class="px-1 text-slate-400">…</span>' : btn(String(p), p, false, p === cur)).join('') +
                btn('›', cur + 1, cur === tot) +
                btn('สุดท้าย »', tot, cur === tot);
        }

        // Selection-clear button
        window.reyaMasterClearSelection = function () {
            state.selected.clear();
            renderList();
            updateImportBtn();
        };

        // Pagination jump
        window.__mcGoto = function (page) {
            page = Math.max(1, Math.min(state.totalPages, page | 0));
            if (page === state.page) return;
            loadPage(page);
        };

        // Open modal — fetch first page if not loaded
        window.reyaMasterCatalogOpen = function () {
            $('masterCatalogModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            // Reset
            state.query = '';
            $('mcSearch').value = '';
            state.selected.clear();
            updateImportBtn();
            loadPage(1);
            setTimeout(() => $('mcSearch').focus(), 100);
        };

        window.reyaMasterCatalogClose = function () {
            $('masterCatalogModal').classList.add('hidden');
            document.body.style.overflow = '';
        };

        // Search (debounced)
        document.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'mcSearch') {
                const v = e.target.value;
                clearTimeout(state.searchDebounce);
                state.searchDebounce = setTimeout(() => {
                    state.query = v.trim();
                    loadPage(1);
                }, 300);
            }
        });

        // Esc to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !$('masterCatalogModal').classList.contains('hidden')) {
                reyaMasterCatalogClose();
            }
        });

        // Import handler
        window.reyaMasterImport = async function () {
            if (!state.selected.size) return;
            const btn = $('mcImportBtn');
            btn.disabled = true;
            const origLabel = $('mcImportBtnLabel').textContent;
            $('mcImportBtnLabel').textContent = 'กำลังนำเข้า…';

            const payload = {
                ids:           Array.from(state.selected.keys()),
                default_price: parseFloat($('mcDefaultPrice').value) || 0,
                default_stock: parseInt($('mcDefaultStock').value, 10) || 0,
                activate:      $('mcActivate').checked,
            };

            try {
                const r = await fetch('/api/master-catalog.php?action=import', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const j = await r.json();
                if (!j.ok) throw new Error(j.message || j.error || 'import_failed');

                // Show success toast and reload
                const msg = 'นำเข้าสำเร็จ — เพิ่ม ' + j.inserted + ' รายการ, อัปเดต ' + j.updated + ' รายการ';
                const t = document.createElement('div');
                t.className = 'fixed top-6 right-6 z-[20000] px-4 py-3 bg-emerald-600 text-white rounded-lg shadow-2xl text-sm';
                t.textContent = msg;
                document.body.appendChild(t);
                setTimeout(() => t.remove(), 3500);

                state.selected.clear();
                reyaMasterCatalogClose();
                setTimeout(() => location.reload(), 1500);
            } catch (e) {
                alert('นำเข้าไม่สำเร็จ: ' + e.message);
            } finally {
                btn.disabled = false;
                $('mcImportBtnLabel').textContent = origLabel;
                updateImportBtn();
            }
        };
    })();
    </script>

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
            <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
            <button type="button" @click="bulkDisableOdooInactive()"
                    class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 text-sm font-medium">
                <i class="fas fa-eye-slash mr-1"></i>ปิดสินค้าที่ถูกปิดในระบบหลัก
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

<?php /* ชนิดยา filter ลบออก — drug_type ไม่มีใน business_items (2026-05-26) */ ?>

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
                        <th class="px-3 py-3 text-center w-14">รูป</th>
                        <th class="px-3 py-3 text-left">รหัส / SKU</th>
                        <th class="px-3 py-3 text-left">ชื่อสินค้า</th>
                        <th class="px-3 py-3 text-left">หมวดหมู่</th>
                        <th class="px-3 py-3 text-right">ราคา</th>
                        <th class="px-3 py-3 text-center">สต็อก</th>
                        <th class="px-3 py-3 text-center">หน่วย</th>
                        <th class="px-3 py-3 text-center">สถานะระบบ</th>
                        <th class="px-3 py-3 text-center">หน้าร้าน</th>
                        <th class="px-3 py-3 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" class="px-4 py-10 text-center text-gray-400"><!-- 11 cols: ☑ + รูป + รหัส + ชื่อ + หมวด + ราคา + สต็อก + หน่วย + สถานะ + หน้าร้าน + รายละเอียด -->
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
                            <?php $imgUrl = trim((string) ($r['image_url'] ?? '')); ?>
                            <tr class="hover:bg-gray-50" :class="selectedIds.includes(<?= $id ?>) ? 'bg-blue-50' : ''">
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" :checked="selectedIds.includes(<?= $id ?>)"
                                           @change="toggleRow(<?= $id ?>, $event.target.checked)"
                                           data-row-id="<?= $id ?>"
                                           class="row-checkbox rounded">
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($imgUrl !== ''): ?>
                                        <a href="/inventory/product-detail.php?id=<?= $id ?>" title="ดูรายละเอียดสินค้า">
                                            <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES) ?>" alt=""
                                                 loading="lazy"
                                                 class="w-12 h-12 rounded-lg object-cover border border-gray-200 inline-block bg-white"
                                                 onerror="this.outerHTML='<div class=\'w-12 h-12 rounded-lg bg-gray-100 border border-gray-200 inline-flex items-center justify-center text-gray-300\'><i class=\'fas fa-image\'></i></div>';">
                                        </a>
                                    <?php else: ?>
                                        <div class="w-12 h-12 rounded-lg bg-gray-100 border border-gray-200 inline-flex items-center justify-center text-gray-300">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
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
                                <td class="px-3 py-2 text-center text-xs">
                                    <?php
                                    $unitList  = trim((string) ($r['unit_list']  ?? ''));
                                    $unitCount = (int)  ($r['unit_count'] ?? 0);
                                    $baseUnit  = trim((string) ($r['unit']       ?? ''));
                                    ?>
                                    <?php if ($unitCount > 1): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 border border-indigo-100 font-medium"
                                              title="<?= htmlspecialchars($unitList, ENT_QUOTES) ?>">
                                            <i class="fas fa-layer-group text-[10px]"></i>
                                            <?= $unitCount ?> หน่วย
                                        </span>
                                        <div class="text-[10px] text-gray-500 mt-0.5 truncate" style="max-width:140px;" title="<?= htmlspecialchars($unitList, ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($unitList) ?>
                                        </div>
                                    <?php elseif ($unitList !== ''): ?>
                                        <span class="text-gray-700"><?= htmlspecialchars($unitList) ?></span>
                                    <?php elseif ($baseUnit !== ''): ?>
                                        <span class="text-gray-700"><?= htmlspecialchars($baseUnit) ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                    <?php endif; ?>
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
                                    <div class="inline-flex items-center gap-1">
                                        <a href="/inventory/product-detail?id=<?= $id ?>"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-100 <?= $anyOvr ? 'ring-1 ring-amber-300' : '' ?>"
                                           title="<?= $anyOvr ? 'แก้ไข/ดูรายละเอียด (มี admin override)' : 'เปิดหน้ารายละเอียดสินค้า' ?>">
                                            <i class="fas fa-eye"></i>
                                            <span>ดูรายละเอียด</span>
                                            <?php if ($anyOvr): ?><i class="fas fa-pen-square text-amber-500 text-[10px]"></i><?php endif; ?>
                                        </a>
                                        <button type="button"
                                                onclick="reyaDeleteProduct(<?= $id ?>, <?= htmlspecialchars(json_encode((string)($eff['name'] ?? $r['name'] ?? '')), ENT_QUOTES) ?>)"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs bg-red-50 hover:bg-red-100 text-red-600 border border-red-100"
                                                title="ลบสินค้า">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
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
                        (กดปุ่ม <i class="fas fa-undo text-amber-600"></i> เพื่อกลับไปใช้ค่าจาก sync)
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
            if (dry.affected === 0) return alert('ไม่มีสินค้าที่ถูกปิดในระบบหลักที่ยังเปิดขายอยู่');
            if (!confirm(`ปิดการขาย ${dry.affected} รายการที่ถูกปิดในระบบหลัก — ยืนยัน?`)) return;
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

<!-- Storefront Quick-Add Product Modal -->
<div id="storefrontProductModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-5xl mx-4 max-h-[95vh] overflow-hidden flex flex-col">
        <form method="POST" id="storefrontProductForm">
            <input type="hidden" name="action" value="storefront_quick_create">

            <div class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white flex justify-between items-center">
                <h3 class="text-lg font-semibold">เพิ่มสินค้าใหม่ลงหน้าร้าน</h3>
                <button type="button" onclick="closeStorefrontProductModal()" class="text-white hover:text-blue-200 text-2xl"><i class="fas fa-times"></i></button>
            </div>

            <div class="flex-1 overflow-y-auto p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">รหัสสินค้า</label>
                                <input type="text" name="product_code" class="w-full px-2 py-1.5 border rounded-lg text-sm" placeholder="เว้นว่างจะ auto-generate">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">SKU</label>
                                <input type="text" name="sku" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">บาร์โค้ด</label>
                            <input type="text" name="barcode" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">ชื่อสินค้า *</label>
                            <input type="text" name="name" required class="w-full px-2 py-1.5 border rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">ชื่อภาษาอังกฤษ</label>
                            <input type="text" name="name_en" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">ชื่อสามัญ / Generic Name</label>
                            <input type="text" name="generic_name" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">หมวดหมู่</label>
                                <input type="text" name="category" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">ผู้ผลิต</label>
                                <input type="text" name="manufacturer" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">รายละเอียด</label>
                            <textarea name="description" rows="3" class="w-full px-2 py-1.5 border rounded-lg text-sm" placeholder="คำอธิบายสินค้า สรรพคุณ ฯลฯ"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">วิธีใช้</label>
                            <textarea name="usage_instructions" rows="3" class="w-full px-2 py-1.5 border rounded-lg text-sm" placeholder="วิธีใช้ ขนาดยา คำเตือน ฯลฯ"></textarea>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">URL รูปภาพหลัก</label>
                            <input type="url" name="image_url" class="w-full px-2 py-1.5 border rounded-lg text-sm" placeholder="https://...">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">รูปเพิ่มเติม (หลาย URL — 1 บรรทัดต่อ 1 รูป)</label>
                            <textarea name="image_gallery" rows="3" class="w-full px-2 py-1.5 border rounded-lg text-sm font-mono" placeholder="https://...&#10;https://..."></textarea>
                            <p class="text-[10px] text-gray-500 mt-1">รูปหลักจะถูกเพิ่มเข้าแกลเลอรีอัตโนมัติ; ที่นี่ใส่เฉพาะรูปเสริม</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">ราคา (list_price) *</label>
                                <input type="number" name="price" required min="0" step="0.01" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">ราคาออนไลน์/ลด</label>
                                <input type="number" name="sale_price" min="0" step="0.01" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Stock</label>
                                <input type="number" name="stock" value="0" step="0.01" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">หน่วยนับ</label>
                                <input type="text" name="base_unit" class="w-full px-2 py-1.5 border rounded-lg text-sm" placeholder="ขวด, กล่อง">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">หน่วยจำนวน</label>
                                <input type="text" name="unit" class="w-full px-2 py-1.5 border rounded-lg text-sm">
                            </div>
                        </div>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-xs text-blue-700">
                            <i class="fas fa-info-circle mr-1"></i>สินค้าที่สร้างจากที่นี่จะถูกตั้ง <b>เผยแพร่บนหน้าร้านทันที</b> (storefront_enabled = 1)
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-4 py-3 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeStorefrontProductModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50 text-sm">ยกเลิก</button>
                <button type="submit" class="px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm"><i class="fas fa-save mr-1"></i>บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStorefrontProductModal() {
    const m = document.getElementById('storefrontProductModal');
    m.classList.remove('hidden'); m.classList.add('flex');
    document.getElementById('storefrontProductForm').reset();
}
function closeStorefrontProductModal() {
    const m = document.getElementById('storefrontProductModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
</script>
