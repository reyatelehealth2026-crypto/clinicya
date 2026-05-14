<?php
/**
 * Inventory Product Detail / Edit Page
 * หน้ารายละเอียดและแก้ไขสินค้า (business_items)
 *
 * Route:
 *   Clean:  /inventory/product-detail?id=N
 *   Direct: /inventory/product-detail.php?id=N
 *
 * Reads/writes a single `business_items` row scoped to the current
 * line_account_id (from session current_bot_id). The form exposes
 * every column an admin is allowed to edit: basic info, pricing,
 * stock, drug-specific fields, descriptions, and the multi-image
 * gallery (image_gallery column — gracefully optional until the
 * Phase A migration runs on prod).
 *
 * Save handler: same-page POST with action=update.
 *
 * Sibling pages in inventory/ use the standard
 * includes/header.php + includes/footer.php pattern.
 *
 * @package Inventory
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 1);

// ─── Helpers ────────────────────────────────────────────────────────────────
/**
 * Check whether a column exists on a table. Results cached per request.
 */
if (!function_exists('pd_hasColumn')) {
    function pd_hasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $safeTable  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $stmt = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            $cache[$key] = $stmt && $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

/**
 * Decode JSON-array stored in image_gallery to a string array.
 */
if (!function_exists('pd_decodeGallery')) {
    function pd_decodeGallery($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw), 'strlen'));
        }
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded), 'strlen'));
        }
        // Fallback: newline-separated legacy value
        $parts = preg_split('/[\r\n]+/', (string) $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }
}

/**
 * Encode textarea (1 URL/line) to JSON for storage.
 */
if (!function_exists('pd_encodeGallery')) {
    function pd_encodeGallery(string $text): ?string
    {
        $urls = preg_split('/[\r\n,]+/', $text) ?: [];
        $urls = array_values(array_filter(array_map('trim', $urls), 'strlen'));
        if (empty($urls)) {
            return null;
        }
        return json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

// ─── Resolve product id ─────────────────────────────────────────────────────
$productId = (int) ($_GET['id'] ?? 0);
$skuParam  = trim((string) ($_GET['sku'] ?? ''));

// If only sku is provided (e.g. linked from the storefront tab which still
// shows shop_products rows whose id ≠ business_items.id), resolve to the
// matching business_items row for this tenant.
if ($productId <= 0 && $skuParam !== '') {
    try {
        $lookup = $db->prepare(
            'SELECT id FROM business_items
             WHERE sku = :sku AND (line_account_id = :lid OR line_account_id IS NULL)
             ORDER BY (line_account_id = :lid2) DESC, id ASC
             LIMIT 1'
        );
        $lookup->execute([':sku' => $skuParam, ':lid' => $lineAccountId, ':lid2' => $lineAccountId]);
        $found = (int) ($lookup->fetchColumn() ?: 0);
        if ($found > 0) {
            $productId = $found;
        }
    } catch (Throwable $e) {
        // fall through to error below
    }
}

if ($productId <= 0) {
    http_response_code(404);
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-6">'
        . '<i class="fas fa-exclamation-circle mr-2"></i>'
        . 'ไม่พบรหัสสินค้า (Product ID missing or SKU not in business_items). '
        . '<a href="/inventory" class="underline text-emerald-700">กลับหน้า Inventory</a>'
        . '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Detect optional/legacy columns once
$hasImageGallery       = pd_hasColumn($db, 'business_items', 'image_gallery');
$hasGenericName        = pd_hasColumn($db, 'business_items', 'generic_name');
$hasManufacturer       = pd_hasColumn($db, 'business_items', 'manufacturer');
$hasUsageInstructions  = pd_hasColumn($db, 'business_items', 'usage_instructions');
$hasDosageForm         = pd_hasColumn($db, 'business_items', 'dosage_form');
$hasDrugCategory       = pd_hasColumn($db, 'business_items', 'drug_category');
$hasStrength           = pd_hasColumn($db, 'business_items', 'strength');
$hasWarnings           = pd_hasColumn($db, 'business_items', 'warnings');
$hasContraindications  = pd_hasColumn($db, 'business_items', 'contraindications');
$hasDosage             = pd_hasColumn($db, 'business_items', 'dosage');
$hasSideEffects        = pd_hasColumn($db, 'business_items', 'side_effects');
$hasStorageConditions  = pd_hasColumn($db, 'business_items', 'storage_conditions');
$hasRequiresPx         = pd_hasColumn($db, 'business_items', 'requires_prescription');
$hasReorderPoint       = pd_hasColumn($db, 'business_items', 'reorder_point');
$hasMinStock           = pd_hasColumn($db, 'business_items', 'min_stock');
$hasIsFeatured         = pd_hasColumn($db, 'business_items', 'is_featured');
$hasIsActive           = pd_hasColumn($db, 'business_items', 'is_active');
$hasCategoryId         = pd_hasColumn($db, 'business_items', 'category_id');
$hasNameEn             = pd_hasColumn($db, 'business_items', 'name_en');
$hasBarcode            = pd_hasColumn($db, 'business_items', 'barcode');
$hasSku                = pd_hasColumn($db, 'business_items', 'sku');
$hasCostPrice          = pd_hasColumn($db, 'business_items', 'cost_price');
$hasSalePrice          = pd_hasColumn($db, 'business_items', 'sale_price');
$hasDescription        = pd_hasColumn($db, 'business_items', 'description');
$hasImageUrl           = pd_hasColumn($db, 'business_items', 'image_url');

// ─── Save handler ──────────────────────────────────────────────────────────
$saveError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        // Re-load existing row to confirm scope
        $check = $db->prepare(
            'SELECT id FROM business_items WHERE id = :id AND (line_account_id = :lid OR line_account_id IS NULL) LIMIT 1'
        );
        $check->execute([':id' => $productId, ':lid' => $lineAccountId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('ไม่พบสินค้าหรือสินค้าไม่ได้อยู่ในร้านของคุณ');
        }

        $sets = [];
        $params = [':id' => $productId];

        $assignText = function (string $column, string $postKey) use (&$sets, &$params) {
            $value = trim((string) ($_POST[$postKey] ?? ''));
            $sets[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = $value === '' ? null : $value;
        };
        $assignInt = function (string $column, string $postKey, int $default = 0) use (&$sets, &$params) {
            $value = $_POST[$postKey] ?? '';
            $sets[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = ($value === '' || $value === null) ? $default : (int) $value;
        };
        $assignFloat = function (string $column, string $postKey, ?float $default = 0.0) use (&$sets, &$params) {
            $value = $_POST[$postKey] ?? '';
            $sets[] = "`{$column}` = :{$column}";
            if ($value === '' || $value === null) {
                $params[":{$column}"] = $default;
            } else {
                $params[":{$column}"] = (float) $value;
            }
        };
        $assignBool = function (string $column, string $postKey) use (&$sets, &$params) {
            $sets[] = "`{$column}` = :{$column}";
            $params[":{$column}"] = !empty($_POST[$postKey]) ? 1 : 0;
        };

        // Always-present
        $assignText('name', 'name');
        $assignFloat('price', 'price', 0.0);

        // Optional columns
        if ($hasNameEn)             $assignText('name_en', 'name_en');
        if ($hasGenericName)        $assignText('generic_name', 'generic_name');
        if ($hasManufacturer)       $assignText('manufacturer', 'manufacturer');
        if ($hasDescription)        $assignText('description', 'description');
        if ($hasUsageInstructions)  $assignText('usage_instructions', 'usage_instructions');
        if ($hasImageUrl)           $assignText('image_url', 'image_url');
        if ($hasSku)                $assignText('sku', 'sku');
        if ($hasBarcode)            $assignText('barcode', 'barcode');

        if ($hasCategoryId) {
            $cat = $_POST['category_id'] ?? '';
            $sets[] = "`category_id` = :category_id";
            $params[':category_id'] = ($cat === '' ? null : (int) $cat);
        }

        if ($hasSalePrice) {
            $sp = $_POST['sale_price'] ?? '';
            $sets[] = "`sale_price` = :sale_price";
            $params[':sale_price'] = ($sp === '' ? null : (float) $sp);
        }
        if ($hasCostPrice) {
            $cp = $_POST['cost_price'] ?? '';
            $sets[] = "`cost_price` = :cost_price";
            $params[':cost_price'] = ($cp === '' ? null : (float) $cp);
        }

        $assignInt('stock', 'stock', 0);
        if ($hasMinStock)      $assignInt('min_stock', 'min_stock', 5);
        if ($hasReorderPoint)  $assignInt('reorder_point', 'reorder_point', 5);

        if ($hasDosageForm)         $assignText('dosage_form', 'dosage_form');
        if ($hasDrugCategory)       $assignText('drug_category', 'drug_category');
        if ($hasStrength)           $assignText('strength', 'strength');
        if ($hasDosage)             $assignText('dosage', 'dosage');
        if ($hasWarnings)           $assignText('warnings', 'warnings');
        if ($hasContraindications)  $assignText('contraindications', 'contraindications');
        if ($hasSideEffects)        $assignText('side_effects', 'side_effects');
        if ($hasStorageConditions)  $assignText('storage_conditions', 'storage_conditions');

        if ($hasRequiresPx) $assignBool('requires_prescription', 'requires_prescription');
        if ($hasIsActive)   $assignBool('is_active', 'is_active');
        if ($hasIsFeatured) $assignBool('is_featured', 'is_featured');

        if ($hasImageGallery) {
            $galleryJson = pd_encodeGallery((string) ($_POST['image_gallery'] ?? ''));
            $sets[] = "`image_gallery` = :image_gallery";
            $params[':image_gallery'] = $galleryJson;
        }

        if (empty($sets)) {
            throw new RuntimeException('ไม่มีฟิลด์ที่จะอัปเดต');
        }

        $sql = 'UPDATE business_items SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Redirect post-save to clear POST
        $qs = http_build_query(['id' => $productId, 'success' => 1]);
        header('Location: /inventory/product-detail?' . $qs);
        exit;
    } catch (Throwable $e) {
        $saveError = $e->getMessage();
    }
}

// ─── Fetch product (with optional-column tolerance) ────────────────────────
$selectCols = ['id', 'name', 'price', 'stock', 'created_at', 'updated_at'];
$optionalCols = [
    'category_id', 'name_en', 'generic_name', 'manufacturer', 'description',
    'usage_instructions', 'image_url', 'sku', 'barcode', 'sale_price', 'cost_price',
    'min_stock', 'reorder_point', 'supplier_id', 'dosage_form', 'drug_category',
    'strength', 'warnings', 'contraindications', 'dosage', 'side_effects',
    'storage_conditions', 'requires_prescription', 'is_active', 'is_featured',
    'image_gallery', 'item_type',
];
foreach ($optionalCols as $col) {
    if (pd_hasColumn($db, 'business_items', $col)) {
        $selectCols[] = $col;
    }
}

$quotedCols = array_map(fn($c) => "`{$c}`", $selectCols);
$selectSql = 'SELECT ' . implode(', ', $quotedCols)
    . ' FROM business_items WHERE id = :id AND (line_account_id = :lid OR line_account_id IS NULL) LIMIT 1';

$stmt = $db->prepare($selectSql);
$stmt->execute([':id' => $productId, ':lid' => $lineAccountId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-6">'
        . '<i class="fas fa-exclamation-circle mr-2"></i>'
        . 'ไม่พบสินค้านี้ในร้านของคุณ (Product not found in current account). '
        . '<a href="/inventory" class="underline text-emerald-700">กลับหน้า Inventory</a>'
        . '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Load categories
$categories = [];
try {
    $catStmt = $db->prepare(
        'SELECT id, name FROM business_categories
         WHERE is_active = 1 AND (line_account_id = :lid OR line_account_id IS NULL)
         ORDER BY sort_order ASC, name ASC'
    );
    $catStmt->execute([':lid' => $lineAccountId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categories = [];
}

// Build gallery list and main image
$galleryList = pd_decodeGallery($product['image_gallery'] ?? null);
$galleryTextarea = implode("\n", $galleryList);
$mainImage = trim((string) ($product['image_url'] ?? ''));

$pageTitle = 'รายละเอียดสินค้า · ' . ($product['name'] ?? '');
$showSuccess = !empty($_GET['success']);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.pd-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.pd-section-title { display:flex; align-items:center; gap:8px; font-weight:700; color:#065f46; font-size:15px; }
.pd-section-title i { color:#10b981; }
.pd-label { display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:4px; }
.pd-input, .pd-select, .pd-textarea {
    width:100%; border:1px solid #d1d5db; border-radius:8px;
    padding:8px 10px; font-size:14px; background:#fff; color:#111827;
    transition: all .15s ease;
}
.pd-input:focus, .pd-select:focus, .pd-textarea:focus {
    outline:none; border-color:#10b981; box-shadow:0 0 0 3px rgba(16,185,129,.15);
}
.pd-textarea { font-family:inherit; resize:vertical; }
.pd-help { font-size:11px; color:#6b7280; margin-top:4px; }
.pd-grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
.pd-grid-3 { display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; }
@media (max-width:768px) {
    .pd-grid-2, .pd-grid-3 { grid-template-columns: 1fr; }
}
.pd-thumb {
    width:80px; height:80px; border-radius:8px;
    border:1px solid #d1fae5; background:#f0fdf4 center/cover no-repeat;
    display:inline-block; margin:4px;
}
.pd-thumb-empty {
    display:inline-flex; align-items:center; justify-content:center;
    color:#9ca3af; background:#f3f4f6;
}
.pd-checkbox-row {
    display:inline-flex; align-items:center; gap:8px;
    background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46;
    padding:8px 12px; border-radius:8px; font-size:13px; font-weight:500;
}
.pd-checkbox-row input[type=checkbox] { accent-color:#10b981; width:16px; height:16px; }
.pd-toolbar {
    background:linear-gradient(90deg,#ecfdf5 0%,#f0fdf4 100%);
    border:1px solid #a7f3d0;
}
</style>

<div class="space-y-4">

    <!-- Breadcrumb + actions -->
    <div class="pd-toolbar rounded-xl p-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-emerald-800">
            <a href="/inventory" class="hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>กลับหน้าคลังสินค้า (Back to inventory)
            </a>
            <span class="mx-2 text-emerald-300">·</span>
            <span class="font-semibold">รายละเอียดสินค้า (Product Detail)</span>
            <span class="mx-2 text-emerald-300">·</span>
            <span class="font-mono text-xs bg-white border border-emerald-100 px-2 py-0.5 rounded">
                ID #<?= (int) $product['id'] ?>
            </span>
        </div>
        <div class="flex items-center gap-2">
            <?php if (!empty($product['updated_at'])): ?>
                <span class="text-xs text-emerald-700">
                    <i class="far fa-clock mr-1"></i>
                    อัปเดตล่าสุด: <?= htmlspecialchars((string) $product['updated_at']) ?>
                </span>
            <?php endif; ?>
            <button type="submit" form="pd-form"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold">
                <i class="fas fa-save mr-1"></i>บันทึก (Save)
            </button>
        </div>
    </div>

    <?php if ($showSuccess): ?>
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-3 text-sm">
            <i class="fas fa-check-circle mr-1"></i>
            บันทึกการเปลี่ยนแปลงเรียบร้อย (Changes saved successfully)
        </div>
    <?php endif; ?>

    <?php if (!empty($saveError)): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-xl p-3 text-sm">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?= htmlspecialchars($saveError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasImageGallery): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3 text-xs">
            <i class="fas fa-info-circle mr-1"></i>
            ยังไม่มีคอลัมน์ <code>image_gallery</code> ในตาราง — กรุณารัน Phase A migration เพื่อเปิดใช้งานรูปภาพหลายรูป.
            (image_gallery column missing — run Phase A migration to enable multi-image upload.)
        </div>
    <?php endif; ?>

    <form id="pd-form" method="POST" action="/inventory/product-detail?id=<?= (int) $product['id'] ?>" class="space-y-4">
        <input type="hidden" name="action" value="update">

        <!-- ─── Images / รูปภาพ ──────────────────────────────────────────── -->
        <div class="pd-card p-5">
            <div class="pd-section-title mb-3">
                <i class="fas fa-image"></i>
                <span>ภาพสินค้า (Product Images)</span>
            </div>
            <div class="pd-grid-2">
                <div>
                    <label class="pd-label" for="pd-image_url">URL รูปหลัก (Main image URL)</label>
                    <?php if ($hasImageUrl): ?>
                        <input id="pd-image_url" type="url" name="image_url"
                               value="<?= htmlspecialchars((string) ($product['image_url'] ?? '')) ?>"
                               placeholder="https://..." class="pd-input">
                    <?php else: ?>
                        <input type="text" class="pd-input" disabled placeholder="image_url column not available">
                    <?php endif; ?>
                    <div class="pd-help">ตัวอย่างรูปหลักที่แสดงบนหน้าร้าน (Used as the primary thumbnail on storefront).</div>
                </div>
                <div>
                    <label class="pd-label">ตัวอย่างรูปปัจจุบัน (Current preview)</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($mainImage !== ''): ?>
                            <span class="pd-thumb"
                                  style="background-image:url('<?= htmlspecialchars($mainImage, ENT_QUOTES) ?>')"
                                  title="<?= htmlspecialchars($mainImage) ?>"></span>
                        <?php endif; ?>
                        <?php foreach ($galleryList as $url): ?>
                            <span class="pd-thumb"
                                  style="background-image:url('<?= htmlspecialchars($url, ENT_QUOTES) ?>')"
                                  title="<?= htmlspecialchars($url) ?>"></span>
                        <?php endforeach; ?>
                        <?php if ($mainImage === '' && empty($galleryList)): ?>
                            <span class="pd-thumb pd-thumb-empty"><i class="fas fa-image"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <label class="pd-label" for="pd-image_gallery">
                    คลังภาพ (Image gallery) — 1 URL ต่อบรรทัด (one URL per line)
                </label>
                <textarea id="pd-image_gallery" name="image_gallery" rows="4"
                          class="pd-textarea font-mono text-xs"
                          placeholder="https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg"
                          <?= $hasImageGallery ? '' : 'disabled' ?>><?= htmlspecialchars($galleryTextarea) ?></textarea>
                <div class="pd-help">
                    เก็บเป็น JSON array; รูปหลักด้านบนแสดงเป็นภาพแรกบนหน้าร้านโดยอัตโนมัติ
                    (Stored as JSON array — the main image above is used as the first thumbnail on the storefront.)
                </div>
            </div>
        </div>

        <!-- ─── Basic info / ข้อมูลพื้นฐาน ───────────────────────────────── -->
        <div class="pd-card p-5">
            <div class="pd-section-title mb-3">
                <i class="fas fa-tag"></i>
                <span>ข้อมูลพื้นฐาน (Basic Information)</span>
            </div>

            <div class="pd-grid-2">
                <div>
                    <label class="pd-label" for="pd-name">ชื่อสินค้า (Product name) *</label>
                    <input id="pd-name" type="text" name="name" required
                           value="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>"
                           class="pd-input">
                </div>
                <?php if ($hasNameEn): ?>
                    <div>
                        <label class="pd-label" for="pd-name_en">ชื่อภาษาอังกฤษ (English name)</label>
                        <input id="pd-name_en" type="text" name="name_en"
                               value="<?= htmlspecialchars((string) ($product['name_en'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
            </div>

            <div class="pd-grid-2 mt-3">
                <?php if ($hasGenericName): ?>
                    <div>
                        <label class="pd-label" for="pd-generic_name">ชื่อสามัญ (Generic name)</label>
                        <input id="pd-generic_name" type="text" name="generic_name"
                               value="<?= htmlspecialchars((string) ($product['generic_name'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
                <?php if ($hasManufacturer): ?>
                    <div>
                        <label class="pd-label" for="pd-manufacturer">ผู้ผลิต (Manufacturer)</label>
                        <input id="pd-manufacturer" type="text" name="manufacturer"
                               value="<?= htmlspecialchars((string) ($product['manufacturer'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
            </div>

            <div class="pd-grid-3 mt-3">
                <?php if ($hasCategoryId): ?>
                    <div>
                        <label class="pd-label" for="pd-category_id">หมวดหมู่ (Category)</label>
                        <select id="pd-category_id" name="category_id" class="pd-select">
                            <option value="">— เลือกหมวดหมู่ (none) —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"
                                    <?= (int) ($product['category_id'] ?? 0) === (int) $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if ($hasSku): ?>
                    <div>
                        <label class="pd-label" for="pd-sku">รหัส SKU (SKU)</label>
                        <input id="pd-sku" type="text" name="sku"
                               value="<?= htmlspecialchars((string) ($product['sku'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
                <?php if ($hasBarcode): ?>
                    <div>
                        <label class="pd-label" for="pd-barcode">บาร์โค้ด (Barcode)</label>
                        <input id="pd-barcode" type="text" name="barcode"
                               value="<?= htmlspecialchars((string) ($product['barcode'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($hasIsActive || $hasIsFeatured): ?>
                <div class="flex flex-wrap gap-3 mt-4">
                    <?php if ($hasIsActive): ?>
                        <label class="pd-checkbox-row">
                            <input type="checkbox" name="is_active" value="1"
                                <?= !empty($product['is_active']) ? 'checked' : '' ?>>
                            <span>เปิดขาย (Active)</span>
                        </label>
                    <?php endif; ?>
                    <?php if ($hasIsFeatured): ?>
                        <label class="pd-checkbox-row">
                            <input type="checkbox" name="is_featured" value="1"
                                <?= !empty($product['is_featured']) ? 'checked' : '' ?>>
                            <span>สินค้าแนะนำ (Featured)</span>
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ─── Pricing & stock / ราคาและสต็อก ───────────────────────────── -->
        <div class="pd-card p-5">
            <div class="pd-section-title mb-3">
                <i class="fas fa-tags"></i>
                <span>ราคา และ สต็อก (Pricing & Stock)</span>
            </div>

            <div class="pd-grid-3">
                <div>
                    <label class="pd-label" for="pd-price">ราคาขาย (Price) *</label>
                    <input id="pd-price" type="number" min="0" step="0.01" required name="price"
                           value="<?= htmlspecialchars((string) ($product['price'] ?? '0')) ?>"
                           class="pd-input">
                </div>
                <?php if ($hasSalePrice): ?>
                    <div>
                        <label class="pd-label" for="pd-sale_price">ราคาลด/โปร (Sale price)</label>
                        <input id="pd-sale_price" type="number" min="0" step="0.01" name="sale_price"
                               value="<?= htmlspecialchars((string) ($product['sale_price'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
                <?php if ($hasCostPrice): ?>
                    <div>
                        <label class="pd-label" for="pd-cost_price">ราคาต้นทุน (Cost price)</label>
                        <input id="pd-cost_price" type="number" min="0" step="0.01" name="cost_price"
                               value="<?= htmlspecialchars((string) ($product['cost_price'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
            </div>

            <div class="pd-grid-3 mt-3">
                <div>
                    <label class="pd-label" for="pd-stock">สต็อกคงเหลือ (Stock on hand)</label>
                    <input id="pd-stock" type="number" min="0" step="1" name="stock"
                           value="<?= htmlspecialchars((string) ($product['stock'] ?? '0')) ?>"
                           class="pd-input">
                </div>
                <?php if ($hasMinStock): ?>
                    <div>
                        <label class="pd-label" for="pd-min_stock">สต็อกขั้นต่ำ (Min stock)</label>
                        <input id="pd-min_stock" type="number" min="0" step="1" name="min_stock"
                               value="<?= htmlspecialchars((string) ($product['min_stock'] ?? '5')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
                <?php if ($hasReorderPoint): ?>
                    <div>
                        <label class="pd-label" for="pd-reorder_point">จุดสั่งซื้อใหม่ (Reorder point)</label>
                        <input id="pd-reorder_point" type="number" min="0" step="1" name="reorder_point"
                               value="<?= htmlspecialchars((string) ($product['reorder_point'] ?? '5')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ─── Description / รายละเอียด ─────────────────────────────────── -->
        <?php if ($hasDescription || $hasUsageInstructions): ?>
            <div class="pd-card p-5">
                <div class="pd-section-title mb-3">
                    <i class="fas fa-align-left"></i>
                    <span>รายละเอียด (Description)</span>
                </div>
                <?php if ($hasDescription): ?>
                    <div>
                        <label class="pd-label" for="pd-description">คำอธิบายสินค้า (Description)</label>
                        <textarea id="pd-description" name="description" rows="5"
                                  class="pd-textarea"
                                  placeholder="คำอธิบายสินค้า สรรพคุณ จุดเด่น ฯลฯ"><?= htmlspecialchars((string) ($product['description'] ?? '')) ?></textarea>
                    </div>
                <?php endif; ?>
                <?php if ($hasUsageInstructions): ?>
                    <div class="mt-3">
                        <label class="pd-label" for="pd-usage_instructions">วิธีใช้ (Usage instructions)</label>
                        <textarea id="pd-usage_instructions" name="usage_instructions" rows="5"
                                  class="pd-textarea"
                                  placeholder="วิธีใช้ ขนาดยา ข้อควรปฏิบัติ ฯลฯ"><?= htmlspecialchars((string) ($product['usage_instructions'] ?? '')) ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ─── Drug info / ข้อมูลยา ─────────────────────────────────────── -->
        <?php
        $hasAnyDrugField = $hasDosageForm || $hasDrugCategory || $hasStrength || $hasDosage
            || $hasWarnings || $hasContraindications || $hasSideEffects
            || $hasStorageConditions || $hasRequiresPx;
        if ($hasAnyDrugField):
        ?>
            <div class="pd-card p-5">
                <div class="pd-section-title mb-3">
                    <i class="fas fa-prescription-bottle-alt"></i>
                    <span>ข้อมูลยา (Pharmaceutical Information)</span>
                </div>

                <div class="pd-grid-3">
                    <?php if ($hasDosageForm): ?>
                        <div>
                            <label class="pd-label" for="pd-dosage_form">รูปแบบยา (Dosage form)</label>
                            <input id="pd-dosage_form" type="text" name="dosage_form"
                                   value="<?= htmlspecialchars((string) ($product['dosage_form'] ?? '')) ?>"
                                   placeholder="เม็ด, แคปซูล, น้ำเชื่อม..."
                                   class="pd-input">
                        </div>
                    <?php endif; ?>
                    <?php if ($hasDrugCategory): ?>
                        <div>
                            <label class="pd-label" for="pd-drug_category">ประเภทยา (Drug category)</label>
                            <select id="pd-drug_category" name="drug_category" class="pd-select">
                                <option value="">— ไม่ระบุ (none) —</option>
                                <?php
                                $dcOptions = [
                                    'otc' => 'OTC — ยาสามัญประจำบ้าน',
                                    'dangerous' => 'Dangerous — ยาอันตราย',
                                    'controlled' => 'Controlled — ยาควบคุม',
                                    'supplement' => 'Supplement — อาหารเสริม',
                                    'cosmetic' => 'Cosmetic — เครื่องสำอาง',
                                ];
                                $cur = (string) ($product['drug_category'] ?? '');
                                foreach ($dcOptions as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $cur === $val ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lbl) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasStrength): ?>
                        <div>
                            <label class="pd-label" for="pd-strength">ความแรง (Strength)</label>
                            <input id="pd-strength" type="text" name="strength"
                                   value="<?= htmlspecialchars((string) ($product['strength'] ?? '')) ?>"
                                   placeholder="500 mg, 10 mg/5 mL..."
                                   class="pd-input">
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($hasDosage): ?>
                    <div class="mt-3">
                        <label class="pd-label" for="pd-dosage">ขนาดและวิธีรับประทาน (Dosage)</label>
                        <input id="pd-dosage" type="text" name="dosage"
                               value="<?= htmlspecialchars((string) ($product['dosage'] ?? '')) ?>"
                               class="pd-input">
                    </div>
                <?php endif; ?>

                <?php if ($hasWarnings): ?>
                    <div class="mt-3">
                        <label class="pd-label" for="pd-warnings">คำเตือน (Warnings)</label>
                        <textarea id="pd-warnings" name="warnings" rows="3"
                                  class="pd-textarea"><?= htmlspecialchars((string) ($product['warnings'] ?? '')) ?></textarea>
                    </div>
                <?php endif; ?>

                <?php if ($hasContraindications): ?>
                    <div class="mt-3">
                        <label class="pd-label" for="pd-contraindications">ข้อห้ามใช้ (Contraindications)</label>
                        <textarea id="pd-contraindications" name="contraindications" rows="3"
                                  class="pd-textarea"><?= htmlspecialchars((string) ($product['contraindications'] ?? '')) ?></textarea>
                    </div>
                <?php endif; ?>

                <?php if ($hasSideEffects): ?>
                    <div class="mt-3">
                        <label class="pd-label" for="pd-side_effects">อาการข้างเคียง (Side effects)</label>
                        <textarea id="pd-side_effects" name="side_effects" rows="3"
                                  class="pd-textarea"><?= htmlspecialchars((string) ($product['side_effects'] ?? '')) ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="pd-grid-2 mt-3">
                    <?php if ($hasStorageConditions): ?>
                        <div>
                            <label class="pd-label" for="pd-storage_conditions">การเก็บรักษา (Storage conditions)</label>
                            <input id="pd-storage_conditions" type="text" name="storage_conditions"
                                   value="<?= htmlspecialchars((string) ($product['storage_conditions'] ?? '')) ?>"
                                   placeholder="เก็บที่อุณหภูมิห้อง, หลีกเลี่ยงแสงแดด..."
                                   class="pd-input">
                        </div>
                    <?php endif; ?>
                    <?php if ($hasRequiresPx): ?>
                        <div class="flex items-end">
                            <label class="pd-checkbox-row">
                                <input type="checkbox" name="requires_prescription" value="1"
                                    <?= !empty($product['requires_prescription']) ? 'checked' : '' ?>>
                                <span>ต้องมีใบสั่งแพทย์ (Requires prescription)</span>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ─── Footer save bar ──────────────────────────────────────────── -->
        <div class="flex justify-end gap-2 pb-8">
            <a href="/inventory"
               class="px-4 py-2 border border-gray-300 hover:bg-gray-50 rounded-lg text-sm">
                ยกเลิก (Cancel)
            </a>
            <button type="submit"
                    class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold">
                <i class="fas fa-save mr-1"></i>บันทึก (Save)
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
