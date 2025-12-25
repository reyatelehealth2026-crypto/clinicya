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
    
    require_once __DIR__ . '/../includes/header.php';
    ?>
    
    <div class="max-w-5xl mx-auto">
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-export text-green-600 mr-2"></i>ส่งออกสินค้า (Full Export)
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-blue-800">
                        <i class="fas fa-box mr-2"></i>สินค้าทั้งหมด
                        <strong class="block text-2xl"><?= number_format($totalProducts) ?></strong>
                    </p>
                </div>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <p class="text-green-800">
                        <i class="fas fa-columns mr-2"></i>คอลัมน์ข้อมูล
                        <strong class="block text-2xl"><?= $availableFields ?></strong>
                    </p>
                </div>
                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                    <p class="text-purple-800">
                        <i class="fas fa-tags mr-2"></i>หมวดหมู่
                        <strong class="block text-2xl"><?= count($categories) ?></strong>
                    </p>
                </div>
            </div>
            
            <form method="GET" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                        <select name="category" class="w-full px-3 py-2 border rounded-lg">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['cny_code'] ? $cat['cny_code'] . ' - ' : '') ?><?= htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ตัวกรอง</label>
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center">
                                <input type="checkbox" name="featured" value="1" <?= $featured === '1' ? 'checked' : '' ?> class="mr-2">
                                <span class="text-sm">⭐ เฉพาะสินค้าเด่น</span>
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" name="active" value="1" <?= $activeOnly === '1' ? 'checked' : '' ?> class="mr-2">
                                <span class="text-sm">✅ เฉพาะที่เปิดใช้งาน</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="flex items-end">
                        <div class="flex gap-2 w-full">
                            <button type="submit" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                <i class="fas fa-filter mr-1"></i>กรอง
                            </button>
                            <button type="submit" name="format" value="csv" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                <i class="fas fa-download mr-1"></i>CSV
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Fields Info -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="font-semibold text-gray-800 mb-3">
                <i class="fas fa-list text-blue-500 mr-2"></i>ข้อมูลที่จะส่งออก (34 คอลัมน์)
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
                <div class="p-2 bg-gray-50 rounded">📦 ID, SKU, Barcode</div>
                <div class="p-2 bg-gray-50 rounded">📝 ชื่อ TH/EN</div>
                <div class="p-2 bg-gray-50 rounded">💊 ชื่อสามัญ/ส่วนประกอบ</div>
                <div class="p-2 bg-gray-50 rounded">📋 รายละเอียด</div>
                <div class="p-2 bg-blue-50 rounded">💡 ข้อบ่งใช้/สรรพคุณ</div>
                <div class="p-2 bg-blue-50 rounded">📖 วิธีใช้</div>
                <div class="p-2 bg-yellow-50 rounded">⚠️ ข้อควรระวัง</div>
                <div class="p-2 bg-red-50 rounded">🚫 ข้อห้ามใช้</div>
                <div class="p-2 bg-red-50 rounded">💢 ผลข้างเคียง</div>
                <div class="p-2 bg-green-50 rounded">💰 ราคา/ราคาลด/ทุน</div>
                <div class="p-2 bg-gray-50 rounded">📊 สต็อก/หน่วย</div>
                <div class="p-2 bg-gray-50 rounded">🏭 ผู้ผลิต</div>
                <div class="p-2 bg-gray-50 rounded">🖼️ รูปภาพ</div>
                <div class="p-2 bg-gray-50 rounded">📁 หมวดหมู่</div>
                <div class="p-2 bg-gray-50 rounded">#️⃣ แท็ก/Hashtag</div>
                <div class="p-2 bg-gray-50 rounded">📅 วันที่สร้าง/แก้ไข</div>
            </div>
        </div>
        
        <!-- Preview -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="p-4 border-b bg-gray-50">
                <h3 class="font-semibold text-gray-800">ตัวอย่างข้อมูล (10 รายการแรก)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-3 py-2 text-left">ID</th>
                            <th class="px-3 py-2 text-left">SKU</th>
                            <th class="px-3 py-2 text-left">ชื่อสินค้า</th>
                            <th class="px-3 py-2 text-left">ชื่อสามัญ</th>
                            <th class="px-3 py-2 text-right">ราคา</th>
                            <th class="px-3 py-2 text-center">สต็อก</th>
                            <th class="px-3 py-2 text-left">หมวดหมู่</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($products, 0, 10) as $p): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-3 py-2"><?= $p['id'] ?></td>
                            <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                            <td class="px-3 py-2 max-w-xs truncate" title="<?= htmlspecialchars($p['name']) ?>">
                                <?= htmlspecialchars(mb_substr($p['name'], 0, 40)) ?><?= mb_strlen($p['name']) > 40 ? '...' : '' ?>
                            </td>
                            <td class="px-3 py-2 text-xs max-w-xs truncate" title="<?= htmlspecialchars($p['generic_name'] ?? '') ?>">
                                <?= htmlspecialchars(mb_substr($p['generic_name'] ?? '-', 0, 30)) ?>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <?php if (!empty($p['sale_price'])): ?>
                                <span class="text-red-600">฿<?= number_format($p['sale_price']) ?></span>
                                <?php else: ?>
                                ฿<?= number_format($p['price'] ?? 0) ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-center"><?= number_format($p['stock'] ?? 0) ?></td>
                            <td class="px-3 py-2 text-xs"><?= htmlspecialchars($p['category_code'] ?? $p['category_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalProducts > 10): ?>
            <div class="p-3 bg-gray-50 text-center text-sm text-gray-500">
                ... และอีก <?= number_format($totalProducts - 10) ?> รายการ
            </div>
            <?php endif; ?>
        </div>
        
        <div class="mt-4 text-center">
            <a href="import-products.php" class="text-blue-600 hover:underline">
                <i class="fas fa-upload mr-1"></i>นำเข้าสินค้า
            </a>
        </div>
    </div>
    
    <?php
    require_once __DIR__ . '/../includes/footer.php';
}
