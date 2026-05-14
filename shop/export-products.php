<?php
/**
 * Export Products - ส่งออกสินค้าจากตาราง products
 * รองรับ CSV format พร้อมข้อมูลครบถ้วนจาก CNY API
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Get all columns from business_items table
$allColumns = [];
try {
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $allColumns = $cols;
} catch (Exception $e) {}

// Get export format
$format = $_GET['format'] ?? 'csv';
$categoryId = $_GET['category'] ?? '';
$featured = $_GET['featured'] ?? '';
$activeOnly = $_GET['active'] ?? '';

// Build query - shared products across all LINE accounts
$where = ["1=1"];
$params = [];

if ($categoryId) {
    $where[] = "p.category_id = ?";
    $params[] = $categoryId;
}

if ($featured === '1' && in_array('is_featured', $allColumns)) {
    $where[] = "COALESCE(p.is_featured, 0) = 1";
}

if ($activeOnly === '1') {
    $where[] = "p.is_active = 1";
}

$whereClause = implode(' AND ', $where);

// Get products with ALL columns + category info
$sql = "SELECT
    p.*,
    pc.name as category_name,
    pc.cny_code as category_code
FROM business_items p
LEFT JOIN product_categories pc ON p.category_id = pc.id
WHERE $whereClause
ORDER BY p.id ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate filename
$filename = 'products_full_' . date('Y-m-d_His');

if ($format === 'csv') {
    // CSV Export with ALL fields
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // Header row - all columns
    $headers = [
        'ID',
        'SKU',
        'Barcode',
        'ชื่อสินค้า (TH)',
        'ชื่อสินค้า (EN)',
        'ชื่อสามัญ/ส่วนประกอบ',
        'รายละเอียด',
        'ข้อบ่งใช้/สรรพคุณ',
        'วิธีใช้',
        'ข้อควรระวัง',
        'ข้อห้ามใช้',
        'ผลข้างเคียง',
        'ราคาปกติ',
        'ราคาลด',
        'ราคาทุน',
        'คงเหลือ',
        'สต็อกขั้นต่ำ',
        'หน่วย',
        'น้ำหนัก',
        'ผู้ผลิต',
        'รูปภาพ',
        'หมวดหมู่',
        'รหัสหมวด',
        'ประเภทสินค้า',
        'วิธีจัดส่ง',
        'เปิดใช้งาน',
        'สินค้าเด่น',
        'ยอดขาย',
        'ยอดดู',
        'แท็ก/Hashtag',
        'ข้อมูลเพิ่มเติม',
        'วันที่สร้าง',
        'วันที่แก้ไข'
    ];
    fputcsv($output, $headers);

    // Data rows
    foreach ($products as $p) {
        // Parse extra_data JSON if exists
        $extraData = '';
        if (!empty($p['extra_data'])) {
            $extra = json_decode($p['extra_data'], true);
            if ($extra) {
                $extraData = json_encode($extra, JSON_UNESCAPED_UNICODE);
            }
        }

        fputcsv($output, [
            $p['id'] ?? '',
            $p['sku'] ?? '',
            $p['barcode'] ?? '',
            $p['name'] ?? '',
            $p['name_en'] ?? '',
            $p['generic_name'] ?? $p['spec_name'] ?? '',
            $p['description'] ?? '',
            $p['properties_other'] ?? $p['indications'] ?? '',
            $p['usage_instructions'] ?? $p['how_to_use'] ?? '',
            $p['caution'] ?? $p['warnings'] ?? '',
            $p['contraindications'] ?? '',
            $p['side_effects'] ?? '',
            $p['price'] ?? 0,
            $p['sale_price'] ?? '',
            $p['cost'] ?? '',
            $p['stock'] ?? 0,
            $p['min_stock'] ?? 0,
            $p['unit'] ?? '',
            $p['weight'] ?? '',
            $p['manufacturer'] ?? '',
            $p['image_url'] ?? $p['photo_path'] ?? '',
            $p['category_name'] ?? '',
            $p['category_code'] ?? '',
            $p['item_type'] ?? 'physical',
            $p['delivery_method'] ?? 'shipping',
            ($p['is_active'] ?? 1) ? 'Yes' : 'No',
            ($p['is_featured'] ?? 0) ? 'Yes' : 'No',
            $p['sold_count'] ?? 0,
            $p['view_count'] ?? 0,
            $p['hashtag'] ?? $p['tags'] ?? '',
            $extraData,
            $p['created_at'] ?? '',
            $p['updated_at'] ?? ''
        ]);
    }

    fclose($output);
    exit;

} else {
    // Show export page with options
    $pageTitle = 'ส่งออกสินค้า';

    // Get categories for filter
    $categories = [];
    try {
        $stmt = $db->query("SELECT id, name, cny_code FROM product_categories WHERE is_active = 1 ORDER BY cny_code, name");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Count products
    $totalProducts = count($products);

    // Count columns available
    $availableFields = count($allColumns);

    require_once __DIR__ . '/../includes/components/page-header.php';
    require_once __DIR__ . '/../includes/components/data-table.php';
    require_once __DIR__ . '/../includes/header.php';
    ?>

<?= getPageHeaderStyles() ?>
<?= getDataTableStyles() ?>

<style>
.export-stat-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
}
.export-stat-card p { margin: 0; font-size: var(--text-sm); }
.export-stat-value {
    display: block;
    font-size: var(--text-2xl);
    font-weight: 700;
    margin-top: var(--space-1);
    font-family: var(--font-mono);
}
.export-form-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    margin-bottom: var(--space-6);
}
.export-form-card h2 {
    margin: 0 0 var(--space-4);
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--color-dark-800);
}
.export-form-card h3 {
    margin: 0 0 var(--space-3);
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--color-dark-800);
}
.form-label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-dark-700);
    margin-bottom: var(--space-1);
}
.form-select {
    width: 100%; height: 40px; padding: 0 12px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md);
    background: var(--color-slate-50);
    font-size: var(--text-sm);
    color: var(--color-dark-800);
    box-sizing: border-box;
}
.form-check {
    display: flex; align-items: center; gap: var(--space-2);
    font-size: var(--text-sm); color: var(--color-dark-700);
}
.field-badge {
    padding: var(--space-2);
    background: var(--color-slate-50);
    border-radius: var(--radius-sm);
    font-size: var(--text-xs);
    color: var(--color-dark-700);
}
.stats-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: var(--space-4); margin-bottom: var(--space-6); }
.fields-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: var(--space-2); }
.form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: var(--space-4); }
.btn-filter {
    padding: 10px var(--space-4);
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md);
    background: var(--color-slate-100);
    color: var(--color-dark-800);
    font-size: var(--text-sm); font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: var(--space-2);
    text-decoration: none;
}
.btn-export {
    padding: 10px var(--space-4);
    border: none;
    border-radius: var(--radius-md);
    background: var(--color-emerald-600);
    color: #fff;
    font-size: var(--text-sm); font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: var(--space-2);
}
.btn-export:hover { background: var(--color-emerald-700); }
@media (max-width: 768px) {
    .stats-grid, .form-row { grid-template-columns: 1fr; }
    .fields-grid { grid-template-columns: repeat(2,1fr); }
}
.dark .export-stat-card,
.dark .export-form-card {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
}
.dark .export-stat-card p,
.dark .export-form-card h2,
.dark .export-form-card h3 { color: var(--color-slate-100); }
.dark .form-label, .dark .form-check { color: var(--color-slate-300); }
.dark .form-select {
    background: var(--color-dark-900);
    border-color: var(--color-dark-700);
    color: var(--color-slate-100);
}
.dark .field-badge { background: var(--color-dark-700); color: var(--color-slate-300); }
.dark .btn-filter {
    background: var(--color-dark-700);
    border-color: var(--color-dark-600);
    color: var(--color-slate-200);
}
</style>

<?php
echo renderPageHeader(
    'ส่งออกสินค้า',
    'ส่งออกข้อมูลสินค้าเป็น CSV',
    null,
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'ส่งออกสินค้า', 'href' => null]]
);
?>

<div style="max-width:900px;">

    <!-- Stats -->
    <div class="stats-grid">
        <div class="export-stat-card">
            <p style="color:var(--color-primary-700);">
                <i class="fas fa-box" style="margin-right:var(--space-2);"></i>สินค้าทั้งหมด
                <strong class="export-stat-value" style="color:var(--color-primary-600);"><?= number_format($totalProducts) ?></strong>
            </p>
        </div>
        <div class="export-stat-card">
            <p style="color:var(--color-emerald-700);">
                <i class="fas fa-columns" style="margin-right:var(--space-2);"></i>คอลัมน์ข้อมูล
                <strong class="export-stat-value" style="color:var(--color-emerald-600);"><?= $availableFields ?></strong>
            </p>
        </div>
        <div class="export-stat-card">
            <p style="color:var(--color-violet-600);">
                <i class="fas fa-tags" style="margin-right:var(--space-2);"></i>หมวดหมู่
                <strong class="export-stat-value" style="color:var(--color-violet-600);"><?= count($categories) ?></strong>
            </p>
        </div>
    </div>

    <!-- Export Form -->
    <div class="export-form-card">
        <h2><i class="fas fa-file-export" style="color:var(--color-emerald-600);margin-right:var(--space-2);"></i>ส่งออกสินค้า (Full Export)</h2>

        <form method="GET">
            <div class="form-row">
                <div>
                    <label class="form-label">หมวดหมู่</label>
                    <select name="category" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['cny_code'] ? $cat['cny_code'] . ' - ' : '') ?><?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label">ตัวกรอง</label>
                    <div style="display:flex;flex-direction:column;gap:var(--space-2);">
                        <label class="form-check">
                            <input type="checkbox" name="featured" value="1" <?= $featured === '1' ? 'checked' : '' ?>>
                            <span>⭐ เฉพาะสินค้าเด่น</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="active" value="1" <?= $activeOnly === '1' ? 'checked' : '' ?>>
                            <span>✅ เฉพาะที่เปิดใช้งาน</span>
                        </label>
                    </div>
                </div>

                <div style="display:flex;align-items:flex-end;">
                    <div style="display:flex;gap:var(--space-2);width:100%;">
                        <button type="submit" class="btn-filter" style="flex:1;">
                            <i class="fas fa-filter"></i>กรอง
                        </button>
                        <button type="submit" name="format" value="csv" class="btn-export" style="flex:1;">
                            <i class="fas fa-download"></i>CSV
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Fields Info -->
    <div class="export-form-card">
        <h3><i class="fas fa-list" style="color:var(--color-primary-500);margin-right:var(--space-2);"></i>ข้อมูลที่จะส่งออก (34 คอลัมน์)</h3>
        <div class="fields-grid">
            <div class="field-badge">📦 ID, SKU, Barcode</div>
            <div class="field-badge">📝 ชื่อ TH/EN</div>
            <div class="field-badge">💊 ชื่อสามัญ/ส่วนประกอบ</div>
            <div class="field-badge">📋 รายละเอียด</div>
            <div class="field-badge">💡 ข้อบ่งใช้/สรรพคุณ</div>
            <div class="field-badge">📖 วิธีใช้</div>
            <div class="field-badge">⚠️ ข้อควรระวัง</div>
            <div class="field-badge">🚫 ข้อห้ามใช้</div>
            <div class="field-badge">💢 ผลข้างเคียง</div>
            <div class="field-badge">💰 ราคา/ราคาลด/ทุน</div>
            <div class="field-badge">📊 สต็อก/หน่วย</div>
            <div class="field-badge">🏭 ผู้ผลิต</div>
            <div class="field-badge">🖼️ รูปภาพ</div>
            <div class="field-badge">📁 หมวดหมู่</div>
            <div class="field-badge">#️⃣ แท็ก/Hashtag</div>
            <div class="field-badge">📅 วันที่สร้าง/แก้ไข</div>
        </div>
    </div>

    <!-- Preview -->
    <div class="data-table-card" style="margin-bottom:var(--space-4);">
        <div style="padding:var(--space-4);border-bottom:1px solid var(--color-slate-200);background:var(--color-slate-50);">
            <h3 style="margin:0;font-weight:600;color:var(--color-dark-800);font-size:var(--text-base);">ตัวอย่างข้อมูล (10 รายการแรก)</h3>
        </div>
        <?php
        $previewColumns = [
            ['key' => 'id',           'label' => 'ID',        'align' => 'left',   'render' => fn($p) => (int)$p['id']],
            ['key' => 'sku',          'label' => 'SKU',       'align' => 'left',   'render' => fn($p) => '<span style="font-family:var(--font-mono);font-size:var(--text-xs);">' . htmlspecialchars($p['sku'] ?? '-') . '</span>'],
            ['key' => 'name',         'label' => 'ชื่อสินค้า', 'align' => 'left',   'render' => fn($p) => '<span title="' . htmlspecialchars($p['name']) . '">' . htmlspecialchars(mb_substr($p['name'], 0, 40)) . (mb_strlen($p['name']) > 40 ? '…' : '') . '</span>'],
            ['key' => 'generic_name', 'label' => 'ชื่อสามัญ',  'align' => 'left',   'render' => fn($p) => '<span style="font-size:var(--text-xs);">' . htmlspecialchars(mb_substr($p['generic_name'] ?? '-', 0, 30)) . '</span>'],
            ['key' => 'price',        'label' => 'ราคา',      'align' => 'right',  'render' => fn($p) => !empty($p['sale_price'])
                ? '<span style="color:var(--color-rose-600);font-weight:600;">฿' . number_format($p['sale_price']) . '</span>'
                : '฿' . number_format($p['price'] ?? 0)],
            ['key' => 'stock',        'label' => 'สต็อก',     'align' => 'center', 'render' => fn($p) => number_format($p['stock'] ?? 0)],
            ['key' => 'category',     'label' => 'หมวดหมู่',   'align' => 'left',   'render' => fn($p) => '<span style="font-size:var(--text-xs);">' . htmlspecialchars($p['category_code'] ?? $p['category_name'] ?? '-') . '</span>'],
        ];
        echo renderDataTable($previewColumns, array_slice($products, 0, 10));
        ?>
        <?php if ($totalProducts > 10): ?>
        <div style="padding:var(--space-3);background:var(--color-slate-50);text-align:center;font-size:var(--text-sm);color:var(--color-dark-500);border-top:1px solid var(--color-slate-200);">
            … และอีก <?= number_format($totalProducts - 10) ?> รายการ
        </div>
        <?php endif; ?>
    </div>

    <div style="text-align:center;">
        <a href="import-products.php" style="color:var(--color-primary-600);text-decoration:none;font-size:var(--text-sm);">
            <i class="fas fa-upload" style="margin-right:var(--space-1);"></i>นำเข้าสินค้า
        </a>
    </div>

</div>

    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
