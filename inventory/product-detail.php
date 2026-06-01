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
require_once __DIR__ . '/../includes/components/ux-helpers.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 1);

// Super-admin / admin can access products across line_accounts. Others scoped to current bot only.
$pd_userRole = (string) ($_SESSION['admin_user']['role'] ?? '');
$pd_unrestricted = in_array($pd_userRole, ['super_admin', 'admin'], true);

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
        if ($pd_unrestricted) {
            $lookup = $db->prepare(
                'SELECT id FROM business_items WHERE sku = :sku
                  ORDER BY (line_account_id = :lid) DESC, id ASC LIMIT 1'
            );
            $lookup->execute([':sku' => $skuParam, ':lid' => $lineAccountId]);
        } else {
            $lookup = $db->prepare(
                'SELECT id FROM business_items
                 WHERE sku = :sku AND (line_account_id = :lid OR line_account_id IS NULL)
                 ORDER BY (line_account_id = :lid2) DESC, id ASC LIMIT 1'
            );
            $lookup->execute([':sku' => $skuParam, ':lid' => $lineAccountId, ':lid2' => $lineAccountId]);
        }
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
        if ($pd_unrestricted) {
            $check = $db->prepare('SELECT id FROM business_items WHERE id = :id LIMIT 1');
            $check->execute([':id' => $productId]);
        } else {
            $check = $db->prepare(
                'SELECT id FROM business_items WHERE id = :id AND (line_account_id = :lid OR line_account_id IS NULL) LIMIT 1'
            );
            $check->execute([':id' => $productId, ':lid' => $lineAccountId]);
        }
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
if ($pd_unrestricted) {
    $selectSql = 'SELECT ' . implode(', ', $quotedCols) . ' FROM business_items WHERE id = :id LIMIT 1';
    $stmt = $db->prepare($selectSql);
    $stmt->execute([':id' => $productId]);
} else {
    $selectSql = 'SELECT ' . implode(', ', $quotedCols)
        . ' FROM business_items WHERE id = :id AND (line_account_id = :lid OR line_account_id IS NULL) LIMIT 1';
    $stmt = $db->prepare($selectSql);
    $stmt->execute([':id' => $productId, ':lid' => $lineAccountId]);
}
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

/* ─── Hero (shop-style display) ─── */
.pd-hero { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,.04); padding:20px; }
.pd-hero-grid { display:grid; grid-template-columns:280px 1fr; gap:24px; }
@media (max-width:768px) { .pd-hero-grid { grid-template-columns:1fr; } }
.pd-hero-img { aspect-ratio:1/1; background:#f8fafc; border-radius:10px; overflow:hidden; display:flex; align-items:center; justify-content:center; }
.pd-hero-img img { width:100%; height:100%; object-fit:contain; }
.pd-hero-img-placeholder { font-size:80px; color:#cbd5e1; }
.pd-hero-meta { font-size:11px; color:#6b7280; margin-bottom:10px; display:flex; flex-wrap:wrap; gap:14px; }
.pd-hero-meta b { font-weight:600; color:#475569; }
.pd-hero-name { font-size:24px; font-weight:700; color:#0f172a; line-height:1.25; margin:0 0 6px; }
.pd-hero-generic { font-size:14px; color:#10b981; font-weight:500; margin-bottom:4px; }
.pd-hero-mfr { font-size:13px; color:#64748b; margin-bottom:14px; }
.pd-stock-pill { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:8px; font-weight:600; font-size:13px; margin-bottom:14px; }
.pd-stock-pill.in  { background:#ecfdf5; color:#047857; }
.pd-stock-pill.low { background:#fffbeb; color:#b45309; }
.pd-stock-pill.out { background:#fef2f2; color:#b91c1c; }
.pd-hero-price-row { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; }
.pd-hero-price-main { font-size:28px; font-weight:700; color:#10b981; }
.pd-hero-price-sale { color:#dc2626; }
.pd-hero-price-strike { font-size:18px; color:#94a3b8; text-decoration:line-through; }
.pd-hero-price-unit { font-size:13px; color:#94a3b8; }
.pd-hero-units-summary { margin-top:10px; padding-top:10px; border-top:1px dashed #e5e7eb; display:flex; gap:10px; flex-wrap:wrap; font-size:12px; }
.pd-hero-unit-chip { background:#f1f5f9; color:#475569; padding:4px 10px; border-radius:999px; font-weight:500; }
.pd-hero-unit-chip b { color:#0f172a; font-weight:600; }

/* ─── Tabs ─── */
.pd-tab-nav { display:flex; gap:4px; flex-wrap:wrap; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:6px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.pd-tab-btn { padding:8px 14px; font-size:13px; font-weight:500; color:#64748b; background:transparent; border:none; border-radius:8px; cursor:pointer; transition:all .15s; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.pd-tab-btn:hover { background:#f1f5f9; color:#10b981; }
.pd-tab-btn.active { background:#10b981; color:#fff; }
.pd-tab-btn.active i { color:#fff !important; }
.pd-tab-btn i { color:#10b981; font-size:12px; }
.pd-card[data-tab].hidden { display:none; }

/* ─── Upload zone + gallery ─── */
.pd-upload-zone { border:2px dashed #cbd5e1; border-radius:12px; padding:16px; background:#f8fafc; }
.pd-upload-preview { position:relative; aspect-ratio:1/1; max-width:280px; background:#fff; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center; margin-bottom:12px; border:1px solid #e5e7eb; }
.pd-upload-preview.is-uploading::after { content:''; position:absolute; inset:0; background:rgba(255,255,255,0.85); z-index:1; }
.pd-upload-preview.is-uploading::before { content:''; position:absolute; top:50%; left:50%; width:42px; height:42px; margin:-21px 0 0 -21px; border:4px solid #d1fae5; border-top-color:#10b981; border-radius:50%; z-index:2; animation:pdSpin .8s linear infinite; }
.pd-gallery-item.is-uploading::after { content:''; position:absolute; inset:0; background:rgba(255,255,255,0.85); z-index:1; }
.pd-gallery-item.is-uploading::before { content:''; position:absolute; top:50%; left:50%; width:28px; height:28px; margin:-14px 0 0 -14px; border:3px solid #d1fae5; border-top-color:#10b981; border-radius:50%; z-index:2; animation:pdSpin .8s linear infinite; }
@keyframes pdSpin { to { transform:rotate(360deg); } }
.pd-upload-preview img { width:100%; height:100%; object-fit:contain; }
.pd-upload-empty { background:#f1f5f9; }
.pd-upload-placeholder { color:#94a3b8; display:flex; flex-direction:column; align-items:center; gap:6px; font-size:13px; }
.pd-upload-placeholder i { font-size:48px; }
.pd-upload-actions { display:flex; gap:8px; flex-wrap:wrap; }
.pd-gallery-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:10px; padding:10px; background:#f8fafc; border-radius:10px; border:1px solid #e5e7eb; }
.pd-gallery-item { position:relative; aspect-ratio:1/1; background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
.pd-gallery-item img { width:100%; height:100%; object-fit:cover; }
.pd-gallery-remove { position:absolute; top:4px; right:4px; width:22px; height:22px; background:rgba(239,68,68,0.95); color:#fff; border:none; border-radius:50%; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.pd-gallery-remove:hover { background:#dc2626; }
.pd-gallery-add { aspect-ratio:1/1; border:2px dashed #cbd5e1; background:#fff; border-radius:8px; cursor:pointer; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; color:#64748b; font-size:11px; transition:all .15s; }
.pd-gallery-add:hover { border-color:#10b981; color:#10b981; background:#ecfdf5; }
.pd-gallery-uploading { opacity:0.5; pointer-events:none; }
.pd-advanced-details summary { user-select:none; }
.pd-advanced-details summary::-webkit-details-marker { display:none; }
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

    <?php
    // ─── Hero precompute (shop-style display) ────────────────────────────────
    $heroStock     = (int)   ($product['stock']      ?? 0);
    $heroPrice     = (float) ($product['price']      ?? 0);
    $heroSaleRaw   = $product['sale_price']          ?? null;
    $heroSale      = ($heroSaleRaw !== null && $heroSaleRaw !== '' && (float) $heroSaleRaw > 0) ? (float) $heroSaleRaw : null;
    $heroHasDisc   = $heroSale !== null && $heroSale > 0 && $heroPrice > 0 && $heroSale < $heroPrice;
    $heroEffPrice  = $heroHasDisc ? $heroSale : (($heroPrice > 0) ? $heroPrice : ($heroSale ?: 0));
    $heroStockCls  = $heroStock <= 0 ? 'out' : ($heroStock <= 5 ? 'low' : 'in');
    $heroStockIcon = $heroStock <= 0 ? 'times-circle' : ($heroStock <= 5 ? 'exclamation-triangle' : 'check-circle');
    $heroStockTxt  = $heroStock <= 0 ? 'สินค้าหมด' : ($heroStock <= 5 ? 'สินค้าใกล้หมด' : 'มีสินค้า');
    $heroBaseUnit  = trim((string)($product['unit'] ?? '')) ?: 'หน่วย';
    $heroUnits = [];
    try {
        $heroUnitsStmt = $db->prepare(
            'SELECT unit_name, factor, sale_price, is_base_unit
               FROM product_units
              WHERE product_id = ? AND is_active = 1
              ORDER BY is_base_unit DESC, factor ASC'
        );
        $heroUnitsStmt->execute([(int) $product['id']]);
        $heroUnits = $heroUnitsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $heroUnits = []; }
    ?>
    <div class="pd-hero">
        <div class="pd-hero-grid">
            <div class="pd-hero-img">
                <?php if ($mainImage !== ''): ?>
                    <img src="<?= htmlspecialchars($mainImage) ?>"
                         alt="<?= htmlspecialchars((string) ($product['name'] ?? '')) ?>">
                <?php else: ?>
                    <i class="fas fa-pills pd-hero-img-placeholder"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="pd-hero-meta">
                    <?php if (!empty($product['sku'])): ?>
                        <span>SKU <b><?= htmlspecialchars($product['sku']) ?></b></span>
                    <?php endif; ?>
                    <?php if (!empty($product['barcode'])): ?>
                        <span>Barcode <b><?= htmlspecialchars($product['barcode']) ?></b></span>
                    <?php endif; ?>
                    <span>ID <b>#<?= (int) $product['id'] ?></b></span>
                </div>
                <h1 class="pd-hero-name"><?= htmlspecialchars((string) ($product['name'] ?? '-')) ?></h1>
                <?php if (!empty($product['generic_name'])): ?>
                    <div class="pd-hero-generic"><?= htmlspecialchars($product['generic_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($product['manufacturer'])): ?>
                    <div class="pd-hero-mfr"><i class="fas fa-industry mr-1"></i><?= htmlspecialchars($product['manufacturer']) ?></div>
                <?php endif; ?>

                <div class="pd-stock-pill <?= $heroStockCls ?>">
                    <i class="fas fa-<?= $heroStockIcon ?>"></i>
                    <?= $heroStockTxt ?> · คงเหลือ <?= number_format($heroStock) ?> <?= htmlspecialchars($heroBaseUnit) ?>
                </div>

                <div class="pd-hero-price-row">
                    <?php if ($heroEffPrice > 0): ?>
                        <?php if ($heroHasDisc): ?>
                            <div class="pd-hero-price-main pd-hero-price-sale">฿<?= number_format($heroSale, 2) ?></div>
                            <div class="pd-hero-price-strike">฿<?= number_format($heroPrice, 2) ?></div>
                        <?php else: ?>
                            <div class="pd-hero-price-main">฿<?= number_format($heroEffPrice, 2) ?></div>
                        <?php endif; ?>
                        <div class="pd-hero-price-unit">/ <?= htmlspecialchars($heroBaseUnit) ?></div>
                    <?php else: ?>
                        <div class="pd-hero-price-main" style="color:#dc2626;font-size:18px;">ยังไม่ตั้งราคา</div>
                    <?php endif; ?>
                </div>

                <?php if (count($heroUnits) > 1): ?>
                    <div class="pd-hero-units-summary">
                        <?php foreach ($heroUnits as $hu):
                            $hf = (float) $hu['factor'];
                            $hp = ($hu['sale_price'] !== null && $hu['sale_price'] !== '') ? (float) $hu['sale_price'] : null;
                            $isBase = ((int) $hu['is_base_unit']) === 1;
                        ?>
                            <span class="pd-hero-unit-chip">
                                <b><?= htmlspecialchars($hu['unit_name']) ?></b>
                                <?php if (!$isBase): ?>
                                    · ×<?= rtrim(rtrim(number_format($hf, 4, '.', ''), '0'), '.') ?>
                                <?php endif; ?>
                                <?php if ($hp !== null): ?>
                                    · ฿<?= number_format($hp, 2) ?>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tab nav (outside form — type=button buttons don't submit) -->
    <div class="pd-tab-nav">
        <button type="button" class="pd-tab-btn active" data-tab-target="basic"><i class="fas fa-tag"></i>ข้อมูลพื้นฐาน</button>
        <button type="button" class="pd-tab-btn"        data-tab-target="pricing"><i class="fas fa-tags"></i>ราคา & สต็อก</button>
        <button type="button" class="pd-tab-btn"        data-tab-target="units"><i class="fas fa-boxes"></i>หน่วยขาย</button>
        <button type="button" class="pd-tab-btn"        data-tab-target="description"><i class="fas fa-align-left"></i>รายละเอียด</button>
        <button type="button" class="pd-tab-btn"        data-tab-target="drug"><i class="fas fa-prescription-bottle-alt"></i>ตัวยา</button>
        <button type="button" class="pd-tab-btn"        data-tab-target="images"><i class="fas fa-image"></i>รูปภาพ</button>
    </div>

    <form id="pd-form" method="POST" action="/inventory/product-detail?id=<?= (int) $product['id'] ?>" class="space-y-4">
        <input type="hidden" name="action" value="update">

        <!-- ─── Images / รูปภาพ (Upload-first UI) ─────────────────────────── -->
        <div class="pd-card p-5 hidden" data-tab="images"
             data-product-id="<?= (int) $product['id'] ?>">
            <div class="pd-section-title mb-3">
                <i class="fas fa-image"></i>
                <span>ภาพสินค้า (Product Images)</span>
            </div>

            <!-- Hidden form fields — JS keeps them in sync with the upload UI -->
            <?php if ($hasImageUrl): ?>
                <input type="hidden" id="pd-image_url" name="image_url"
                       value="<?= htmlspecialchars((string) ($product['image_url'] ?? '')) ?>">
            <?php endif; ?>
            <textarea id="pd-image_gallery" name="image_gallery"
                      style="display:none"
                      <?= $hasImageGallery ? '' : 'disabled' ?>><?= htmlspecialchars($galleryTextarea) ?></textarea>

            <!-- Main image -->
            <div class="mb-5">
                <label class="pd-label">รูปหลัก (Main Image)</label>
                <div class="pd-upload-zone">
                    <div id="pd-main-preview-wrap" class="pd-upload-preview <?= $mainImage === '' ? 'pd-upload-empty' : '' ?>">
                        <img id="pd-main-preview-img"
                             src="<?= htmlspecialchars($mainImage) ?>"
                             alt="" <?= $mainImage === '' ? 'style="display:none"' : '' ?>>
                        <div id="pd-main-placeholder" class="pd-upload-placeholder" <?= $mainImage !== '' ? 'style="display:none"' : '' ?>>
                            <i class="fas fa-image"></i>
                            <span>ยังไม่มีรูปหลัก</span>
                        </div>
                    </div>
                    <div class="pd-upload-actions">
                        <button type="button" onclick="document.getElementById('pd-main-file').click()"
                                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-semibold">
                            <i class="fas fa-upload mr-1"></i>อัพโหลดรูปหลัก
                        </button>
                        <button type="button" onclick="pdClearMainImage()"
                                id="pd-main-clear-btn"
                                class="px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-lg text-sm <?= $mainImage === '' ? 'hidden' : '' ?>">
                            <i class="fas fa-trash mr-1"></i>ลบรูปหลัก
                        </button>
                        <input type="file" id="pd-main-file" hidden
                               accept="image/jpeg,image/png,image/webp,image/gif"
                               onchange="pdUploadMainImage(this)">
                    </div>
                    <div class="pd-help mt-1">JPG / PNG / WebP / GIF · สูงสุด 5MB · จะถูกบันทึกที่ <code>/uploads/products/<?= (int) $product['id'] ?>/</code></div>
                </div>
            </div>

            <!-- Gallery -->
            <div>
                <label class="pd-label">คลังภาพเพิ่มเติม (Gallery)</label>
                <div id="pd-gallery-grid" class="pd-gallery-grid">
                    <?php foreach ($galleryList as $url): ?>
                        <div class="pd-gallery-item" data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>">
                            <img src="<?= htmlspecialchars($url) ?>" alt="">
                            <button type="button" class="pd-gallery-remove"
                                    onclick="pdRemoveGalleryImage(this)" title="ลบ">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <button type="button" class="pd-gallery-add"
                            onclick="document.getElementById('pd-gallery-file').click()" title="เพิ่มรูป">
                        <i class="fas fa-plus text-2xl"></i>
                        <span class="text-xs">เพิ่มรูป</span>
                    </button>
                    <input type="file" id="pd-gallery-file" hidden multiple
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="pdUploadGalleryImages(this)">
                </div>
                <div class="pd-help mt-1">อัพโหลดได้หลายไฟล์พร้อมกัน · กด ❌ เพื่อลบ · กดบันทึก (Save) ที่หัวหน้าหลังแก้</div>
            </div>

            <!-- Advanced: edit raw URLs -->
            <details class="mt-5 pd-advanced-details">
                <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-700">
                    <i class="fas fa-code mr-1"></i>แอดวานซ์ — แก้ URL ดิบโดยตรง (ถ้ามีรูปจาก URL ภายนอก)
                </summary>
                <div class="mt-3 space-y-2">
                    <label class="pd-label text-xs">image_url (main)</label>
                    <input type="url" id="pd-image_url_visible" class="pd-input font-mono text-xs"
                           value="<?= htmlspecialchars((string) ($product['image_url'] ?? '')) ?>"
                           placeholder="https://example.com/main.jpg"
                           oninput="pdSyncMainFromText(this.value)">
                    <label class="pd-label text-xs mt-2">image_gallery (1 URL ต่อบรรทัด)</label>
                    <textarea id="pd-image_gallery_visible" rows="4"
                              class="pd-textarea font-mono text-xs"
                              placeholder="https://example.com/photo1.jpg&#10;https://example.com/photo2.jpg"
                              oninput="pdSyncGalleryFromText(this.value)"><?= htmlspecialchars($galleryTextarea) ?></textarea>
                </div>
            </details>
        </div>

        <!-- ─── Basic info / ข้อมูลพื้นฐาน ───────────────────────────────── -->
        <div class="pd-card p-5" data-tab="basic">
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
        <div class="pd-card p-5 hidden" data-tab="pricing">
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

        <!-- ─── Multi-Unit Manager (หน่วยขาย) — placed next to stock for proximity ─── -->
        <div class="pd-card p-5 hidden" data-tab="units" id="pu-panel"
             data-item-id="<?= (int) $product['id'] ?>"
             data-stock="<?= (float) ($product['stock'] ?? 0) ?>">
            <div class="pd-section-title mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-boxes"></i>
                    <span>หน่วยขาย (Multi-Unit)</span>
                </div>
                <button type="button" onclick="puShowForm()"
                        class="ml-auto px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">
                    + เพิ่มหน่วย
                </button>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4 text-sm">
                <div>
                    <div class="text-[11px] text-gray-500">หน่วยหลัก (Main UOM)</div>
                    <div id="pu-main-uom" class="font-medium text-gray-800">—</div>
                </div>
                <div>
                    <div class="text-[11px] text-gray-500">รวม (หน่วยหลัก)</div>
                    <div id="pu-total-stock" class="font-semibold text-emerald-700">
                        <?= number_format((float)($product['stock'] ?? 0), 2) ?>
                    </div>
                </div>
                <div class="col-span-2 md:col-span-1">
                    <div class="text-[11px] text-gray-500">วิธีการ</div>
                    <div class="text-[11px] text-gray-600 leading-snug">
                        ขายหน่วยใหญ่ ระบบหักสต็อกหน่วยฐานตาม factor อัตโนมัติ
                    </div>
                </div>
            </div>

            <div id="pu-form" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4 grid grid-cols-1 md:grid-cols-6 gap-2"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();puCreate();}">
                <input type="text"   id="pu-name"    placeholder="ชื่อหน่วย (เช่น กล่อง)" class="md:col-span-2 px-2 py-1.5 border rounded text-sm">
                <input type="number" id="pu-factor"  step="0.0001" min="0.0001" placeholder="factor (เช่น 20)" class="px-2 py-1.5 border rounded text-sm" title="กี่หน่วยฐานต่อ 1 หน่วยนี้">
                <input type="number" id="pu-price"   step="0.01" placeholder="ราคา/หน่วย" class="px-2 py-1.5 border rounded text-sm">
                <input type="text"   id="pu-barcode" placeholder="บาร์โค้ด (optional)" class="px-2 py-1.5 border rounded text-sm">
                <div class="flex gap-1">
                    <button type="button" onclick="puCreate()" class="flex-1 px-3 py-1.5 bg-emerald-600 text-white rounded text-sm">บันทึก</button>
                    <button type="button" onclick="puHideForm()" class="px-2 py-1.5 bg-gray-200 rounded text-sm">×</button>
                </div>
            </div>

            <div id="pu-list" class="text-sm text-gray-400">กำลังโหลด…</div>
        </div>

        <!-- ─── Description / รายละเอียด ─────────────────────────────────── -->
        <?php if ($hasDescription || $hasUsageInstructions): ?>
            <div class="pd-card p-5 hidden" data-tab="description">
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
            <div class="pd-card p-5 hidden" data-tab="drug">
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

<script>
(function () {
    const panel = document.getElementById('pu-panel');
    if (!panel) return;
    const itemId = parseInt(panel.dataset.itemId, 10);
    const stock  = parseFloat(panel.dataset.stock) || 0;
    let unitsCache = [];

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }
    function fmtFactor(n) { const f = parseFloat(n) || 0; return f.toFixed(4).replace(/\.?0+$/, ''); }
    function fmtQty(n)    { return (parseFloat(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }

    async function puCall(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data || {}).forEach(([k, v]) => { if (v !== null && v !== undefined) fd.append(k, v); });
        const res = await fetch('/api/admin/product-units.php?action=' + action, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        return res.json();
    }

    function typeBadge(isBase, factor) {
        if (isBase)         return ['หน่วยฐาน', 'bg-amber-100 text-amber-800'];
        if (factor > 1)     return ['หน่วยใหญ่ ×' + fmtFactor(factor), 'bg-blue-100 text-blue-700'];
        if (factor === 1)   return ['เท่ากับหน่วยฐาน', 'bg-gray-100 text-gray-700'];
        return ['หน่วยย่อย ÷' + fmtFactor(1/factor), 'bg-purple-100 text-purple-700'];
    }

    function rowReadOnly(u) {
        const isBase = parseInt(u.is_base_unit, 10) === 1;
        const factor = parseFloat(u.unit_size) || 0;
        const saleable = factor > 0 ? (stock / factor) : 0;
        const [tLabel, tBadge] = typeBadge(isBase, factor);
        const price = u.sale_price
            ? '฿' + parseFloat(u.sale_price).toLocaleString(undefined, {maximumFractionDigits:2})
            : '<span class="text-gray-400 text-xs italic">auto</span>';
        return `<tr data-uid="${u.id}" class="border-b last:border-0 hover:bg-gray-50">
          <td class="py-2 px-2 font-medium text-gray-800">${escapeHtml(u.unit_name)}${u.unit_code ? ` <span class="text-[10px] text-gray-400 font-mono">${escapeHtml(u.unit_code)}</span>` : ''}</td>
          <td class="py-2 px-2"><span class="inline-block px-2 py-0.5 rounded text-[11px] font-medium ${tBadge}">${tLabel}</span></td>
          <td class="py-2 px-2 text-right font-mono">${fmtFactor(factor)}</td>
          <td class="py-2 px-2 text-right">
            <span class="font-semibold ${saleable > 0 ? 'text-emerald-700' : 'text-gray-400'}">${fmtQty(saleable)}</span>
          </td>
          <td class="py-2 px-2 text-right">${price}</td>
          <td class="py-2 px-2 text-xs text-center">${u.barcode ? escapeHtml(u.barcode) : '<span class="text-gray-300">—</span>'}</td>
          <td class="py-2 px-2 text-right whitespace-nowrap">
            <button type="button" onclick="puEditRow(${u.id})" class="px-2 py-0.5 text-[11px] bg-blue-100 hover:bg-blue-200 text-blue-700 rounded">แก้ไข</button>
            ${isBase ? '' : `<button type="button" onclick="puSetBase(${u.id})" class="ml-1 px-2 py-0.5 text-[11px] bg-amber-100 hover:bg-amber-200 text-amber-700 rounded">ตั้ง base</button>`}
            ${isBase
                ? '<button type="button" disabled title="ลบไม่ได้ — ต้องเปลี่ยนหน่วยฐานก่อน" class="ml-1 px-2 py-0.5 text-[11px] bg-gray-100 text-gray-400 rounded cursor-not-allowed">ลบ</button>'
                : `<button type="button" onclick="puDelete(${u.id})" class="ml-1 px-2 py-0.5 text-[11px] bg-rose-100 hover:bg-rose-200 text-rose-700 rounded">ลบ</button>`}
          </td>
        </tr>`;
    }

    function rowEditing(u) {
        const isBase = parseInt(u.is_base_unit, 10) === 1;
        const factor = parseFloat(u.unit_size) || 0;
        return `<tr data-uid="${u.id}" class="border-b bg-blue-50"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();puSaveRow(${u.id});}else if(event.key==='Escape'){puList();}">
          <td class="py-2 px-2"><input id="e-name-${u.id}" value="${escapeHtml(u.unit_name)}" class="w-full px-1.5 py-1 border rounded text-sm"></td>
          <td class="py-2 px-2 text-xs text-gray-500"><em>${isBase ? 'หน่วยฐาน — factor คงที่ที่ 1' : 'หน่วยรอง'}</em></td>
          <td class="py-2 px-2 text-right">
            ${isBase
                ? '<span class="font-mono text-gray-400">1.0</span>'
                : `<input id="e-factor-${u.id}" type="number" step="0.0001" min="0.0001" value="${fmtFactor(factor)}" class="w-20 px-1.5 py-1 border rounded text-sm text-right">`}
          </td>
          <td class="py-2 px-2 text-right text-gray-400 text-[11px] italic">คำนวณใหม่หลังบันทึก</td>
          <td class="py-2 px-2 text-right"><input id="e-price-${u.id}" type="number" step="0.01" value="${u.sale_price ?? ''}" placeholder="auto" class="w-24 px-1.5 py-1 border rounded text-sm text-right"></td>
          <td class="py-2 px-2 text-center"><input id="e-barcode-${u.id}" value="${escapeHtml(u.barcode || '')}" placeholder="—" class="w-full px-1.5 py-1 border rounded text-xs"></td>
          <td class="py-2 px-2 text-right whitespace-nowrap">
            <button type="button" onclick="puSaveRow(${u.id})" class="px-2 py-0.5 text-[11px] bg-emerald-600 hover:bg-emerald-700 text-white rounded">✓ บันทึก</button>
            <button type="button" onclick="puList()" class="ml-1 px-2 py-0.5 text-[11px] bg-gray-200 hover:bg-gray-300 rounded">✗ ยกเลิก</button>
          </td>
        </tr>`;
    }

    async function puList() {
        const r = await fetch('/api/admin/product-units.php?action=list&item_id=' + itemId, {
            credentials: 'same-origin',
        }).then(r => r.json());
        const el = document.getElementById('pu-list');
        if (!r.success) { el.innerHTML = '<div class="text-rose-600">' + (r.error || 'load failed') + '</div>'; return; }
        unitsCache = r.units || [];
        if (!unitsCache.length) {
            el.innerHTML = '<div class="text-gray-500 italic">ยังไม่มีหน่วยใดๆ — กดปุ่ม "+ เพิ่มหน่วย" เพื่อสร้างหน่วยฐานก่อน</div>';
            document.getElementById('pu-main-uom').textContent = '—';
            return;
        }
        const base = unitsCache.find(u => parseInt(u.is_base_unit, 10) === 1);
        document.getElementById('pu-main-uom').textContent = base ? base.unit_name : '—';

        el.innerHTML = `<div class="overflow-x-auto -mx-2"><table class="w-full text-sm">
          <thead class="text-[11px] text-gray-500 uppercase tracking-wide border-b bg-gray-50">
            <tr>
              <th class="text-left  py-2 px-2">หน่วย</th>
              <th class="text-left  py-2 px-2">ประเภท</th>
              <th class="text-right py-2 px-2">Factor</th>
              <th class="text-right py-2 px-2">ขายได้ (จาก stock)</th>
              <th class="text-right py-2 px-2">ราคา/หน่วย</th>
              <th class="text-center py-2 px-2">บาร์โค้ด</th>
              <th class="text-right py-2 px-2">จัดการ</th>
            </tr>
          </thead><tbody>` + unitsCache.map(rowReadOnly).join('') + '</tbody></table></div>';
    }

    window.puShowForm = () => document.getElementById('pu-form').classList.remove('hidden');
    window.puHideForm = () => {
        document.getElementById('pu-form').classList.add('hidden');
        ['pu-name','pu-factor','pu-price','pu-barcode'].forEach(id => document.getElementById(id).value = '');
    };

    window.puCreate = async () => {
        const name    = document.getElementById('pu-name').value.trim();
        const factor  = parseFloat(document.getElementById('pu-factor').value);
        const price   = document.getElementById('pu-price').value;
        const barcode = document.getElementById('pu-barcode').value.trim();
        if (!name || !factor || factor <= 0) { rxToast('กรอกชื่อหน่วย + factor > 0', 'error'); return; }
        const r = await puCall('create', { item_id: itemId, unit_name: name, unit_size: factor, sale_price: price, barcode });
        if (!r.success) { rxToast('ผิดพลาด: ' + r.error, 'error'); return; }
        rxToast('เพิ่มหน่วยสำเร็จ', 'success');
        puHideForm(); puList();
    };

    window.puEditRow = (id) => {
        const u = unitsCache.find(x => parseInt(x.id) === id);
        if (!u) return;
        const tr = document.querySelector(`tr[data-uid="${id}"]`);
        if (!tr) return;
        tr.outerHTML = rowEditing(u);
        const inp = document.getElementById('e-name-' + id);
        if (inp) { inp.focus(); inp.select(); }
    };

    window.puSaveRow = async (id) => {
        const u = unitsCache.find(x => parseInt(x.id) === id);
        if (!u) return;
        const isBase = parseInt(u.is_base_unit, 10) === 1;
        const payload = { id };
        const name = document.getElementById('e-name-' + id)?.value.trim();
        if (name) payload.unit_name = name;
        if (!isBase) {
            const f = parseFloat(document.getElementById('e-factor-' + id)?.value);
            if (!(f > 0)) { rxToast('factor ต้องมากกว่า 0', 'error'); return; }
            payload.factor = f;
        }
        payload.sale_price = document.getElementById('e-price-' + id)?.value ?? '';
        payload.barcode    = document.getElementById('e-barcode-' + id)?.value.trim() ?? '';
        const r = await puCall('update', payload);
        if (!r.success) { rxToast('ผิดพลาด: ' + r.error, 'error'); return; }
        rxToast('บันทึกการแก้ไขสำเร็จ', 'success');
        puList();
    };

    window.puDelete = async (id) => {
        if (!confirm('ลบหน่วยนี้?')) return;
        const r = await puCall('delete', { id });
        if (!r.success) { rxToast(r.error, 'error'); return; }
        rxToast('ลบหน่วยแล้ว', 'success');
        puList();
    };

    window.puSetBase = async (id) => {
        if (!confirm('ตั้งเป็นหน่วยฐาน? ระบบจะ rescale factor + stock ทุกหน่วยอัตโนมัติ')) return;
        const r = await puCall('set_base', { id });
        if (!r.success) { rxToast(r.error, 'error'); return; }
        rxToast('ตั้ง base unit สำเร็จ — กำลังโหลดใหม่...', 'success');
        setTimeout(() => location.reload(), 800);
    };

    puList();
})();
</script>

<script>
// ─── Tab switching (Phase 2: hero + tabs) ─────────────────────────────────
(function () {
    const tabBtns = document.querySelectorAll('.pd-tab-btn');
    if (!tabBtns.length) return;
    const cards = document.querySelectorAll('.pd-card[data-tab]');

    function show(target) {
        if (!target) return;
        let found = false;
        tabBtns.forEach(b => {
            const m = b.dataset.tabTarget === target;
            b.classList.toggle('active', m);
            if (m) found = true;
        });
        if (!found) return;
        cards.forEach(c => c.classList.toggle('hidden', c.dataset.tab !== target));
        try { localStorage.setItem('inv_pd_tab', target); } catch (e) {}
    }

    tabBtns.forEach(b => b.addEventListener('click', () => show(b.dataset.tabTarget)));

    let saved = null;
    try { saved = localStorage.getItem('inv_pd_tab'); } catch (e) {}
    if (saved) show(saved);
})();

// ─── Image upload (Phase 3: replace URL paste with upload buttons) ────────
function pdGetProductId() {
    const panel = document.querySelector('.pd-card[data-tab="images"]');
    return panel ? parseInt(panel.dataset.productId, 10) : 0;
}

async function pdUploadFile(file) {
    const fd = new FormData();
    fd.append('image', file);
    fd.append('product_id', String(pdGetProductId()));
    const res = await fetch('/api/admin/upload-product-image.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await res.json().catch(() => ({ success: false, error: 'invalid json' }));
    if (!data.success) throw new Error(data.error || 'upload failed');
    return data.url;
}

async function pdUploadMainImage(fileInput) {
    const f = fileInput.files && fileInput.files[0];
    if (!f) return;
    fileInput.disabled = true;
    const wrap = document.getElementById('pd-main-preview-wrap');
    wrap?.classList.add('is-uploading');
    try {
        const url = await pdUploadFile(f);
        document.getElementById('pd-image_url').value = url;
        const visEl = document.getElementById('pd-image_url_visible');
        if (visEl) visEl.value = url;
        const img = document.getElementById('pd-main-preview-img');
        const ph  = document.getElementById('pd-main-placeholder');
        const clr = document.getElementById('pd-main-clear-btn');
        img.src = url;
        img.style.display = '';
        if (ph)  ph.style.display = 'none';
        if (wrap) wrap.classList.remove('pd-upload-empty');
        if (clr) clr.classList.remove('hidden');
        rxToast('อัพโหลดรูปหลักสำเร็จ', 'success');
    } catch (e) {
        rxToast('อัพโหลดรูปหลักไม่สำเร็จ: ' + e.message, 'error');
    } finally {
        wrap?.classList.remove('is-uploading');
        fileInput.value = '';
        fileInput.disabled = false;
    }
}

function pdClearMainImage() {
    if (!confirm('ลบรูปหลัก? (ไฟล์เก่ายังอยู่บนเซิร์ฟเวอร์ — แค่ยกเลิกการอ้างอิง)')) return;
    document.getElementById('pd-image_url').value = '';
    const visEl = document.getElementById('pd-image_url_visible');
    if (visEl) visEl.value = '';
    const img = document.getElementById('pd-main-preview-img');
    const ph  = document.getElementById('pd-main-placeholder');
    const wrap= document.getElementById('pd-main-preview-wrap');
    const clr = document.getElementById('pd-main-clear-btn');
    img.src = '';
    img.style.display = 'none';
    if (ph)  ph.style.display = '';
    if (wrap) wrap.classList.add('pd-upload-empty');
    if (clr) clr.classList.add('hidden');
}

async function pdUploadGalleryImages(fileInput) {
    const files = Array.from(fileInput.files || []);
    if (!files.length) return;
    const grid = document.getElementById('pd-gallery-grid');
    const addBtn = grid.querySelector('.pd-gallery-add');
    addBtn.classList.add('pd-gallery-uploading');
    let okCount = 0;
    let failCount = 0;
    for (const f of files) {
        // Insert a placeholder tile with spinner so user sees progress per file
        const placeholder = document.createElement('div');
        placeholder.className = 'pd-gallery-item is-uploading';
        placeholder.dataset.url = '';
        placeholder.innerHTML = '<img src="" alt="" style="opacity:0">';
        grid.insertBefore(placeholder, addBtn);
        try {
            const url = await pdUploadFile(f);
            placeholder.classList.remove('is-uploading');
            placeholder.dataset.url = url;
            placeholder.innerHTML =
                '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">' +
                '<button type="button" class="pd-gallery-remove" onclick="pdRemoveGalleryImage(this)" title="ลบ"><i class="fas fa-times"></i></button>';
            okCount++;
        } catch (e) {
            placeholder.remove();
            rxToast('อัพโหลด ' + f.name + ' ไม่สำเร็จ: ' + e.message, 'error');
            failCount++;
        }
    }
    pdRebuildGalleryHidden();
    addBtn.classList.remove('pd-gallery-uploading');
    fileInput.value = '';
    if (okCount > 0) {
        rxToast('อัพโหลด ' + okCount + ' รูปสำเร็จ' + (failCount > 0 ? ' (ล้มเหลว ' + failCount + ')' : ''), 'success');
    }
}

function pdAppendGalleryItem(url) {
    const grid = document.getElementById('pd-gallery-grid');
    const addBtn = grid.querySelector('.pd-gallery-add');
    const item = document.createElement('div');
    item.className = 'pd-gallery-item';
    item.dataset.url = url;
    item.innerHTML =
        '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">' +
        '<button type="button" class="pd-gallery-remove" onclick="pdRemoveGalleryImage(this)" title="ลบ"><i class="fas fa-times"></i></button>';
    grid.insertBefore(item, addBtn);
}

function pdRemoveGalleryImage(btn) {
    const item = btn.closest('.pd-gallery-item');
    if (!item) return;
    if (!confirm('ลบรูปนี้ออกจากคลัง?')) return;
    item.remove();
    pdRebuildGalleryHidden();
}

function pdRebuildGalleryHidden() {
    const items = document.querySelectorAll('#pd-gallery-grid .pd-gallery-item');
    const urls = Array.from(items).map(el => el.dataset.url).filter(Boolean);
    document.getElementById('pd-image_gallery').value = urls.join('\n');
    const visEl = document.getElementById('pd-image_gallery_visible');
    if (visEl) visEl.value = urls.join('\n');
}

function pdSyncMainFromText(url) {
    const cleanUrl = String(url || '').trim();
    document.getElementById('pd-image_url').value = cleanUrl;
    const img = document.getElementById('pd-main-preview-img');
    const ph  = document.getElementById('pd-main-placeholder');
    const wrap= document.getElementById('pd-main-preview-wrap');
    const clr = document.getElementById('pd-main-clear-btn');
    if (cleanUrl) {
        img.src = cleanUrl;
        img.style.display = '';
        if (ph)  ph.style.display = 'none';
        if (wrap) wrap.classList.remove('pd-upload-empty');
        if (clr) clr.classList.remove('hidden');
    } else {
        img.src = '';
        img.style.display = 'none';
        if (ph)  ph.style.display = '';
        if (wrap) wrap.classList.add('pd-upload-empty');
        if (clr) clr.classList.add('hidden');
    }
}

function pdSyncGalleryFromText(text) {
    const urls = String(text || '').split('\n').map(s => s.trim()).filter(Boolean);
    document.getElementById('pd-image_gallery').value = urls.join('\n');
    // Re-render grid
    const grid = document.getElementById('pd-gallery-grid');
    const addBtn = grid.querySelector('.pd-gallery-add');
    grid.querySelectorAll('.pd-gallery-item').forEach(el => el.remove());
    urls.forEach(u => {
        const item = document.createElement('div');
        item.className = 'pd-gallery-item';
        item.dataset.url = u;
        item.innerHTML =
            '<img src="' + u.replace(/"/g, '&quot;') + '" alt="">' +
            '<button type="button" class="pd-gallery-remove" onclick="pdRemoveGalleryImage(this)" title="ลบ"><i class="fas fa-times"></i></button>';
        grid.insertBefore(item, addBtn);
    });
}
</script>

<?= getUxHelpersScript() ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
