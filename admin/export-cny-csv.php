<?php
/**
 * Export CNY Products to CSV for business_items import
 * ดึงข้อมูลจากตาราง cny_products และ export เป็น CSV ที่ import เข้า business_items ได้ 100%
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Check if download requested
$action = $_GET['action'] ?? '';

if ($action === 'download') {
    downloadCsv($db);
    exit;
}

if ($action === 'download_chunk') {
    $offset = (int)($_GET['offset'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 500);
    downloadCsvChunk($db, $offset, $limit);
    exit;
}

$pageTitle = 'Export CNY Products to CSV';

// Get product count from database
$totalProducts = 0;
$tableExists = false;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM cny_products");
    $totalProducts = $stmt->fetchColumn();
    $tableExists = true;
} catch (PDOException $e) {
    // Table doesn't exist
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="mb-6">
        <a href="/sync-dashboard.php" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-2"></i>กลับหน้า Sync Dashboard
        </a>
    </div>

    <?php if (!$tableExists): ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-6 rounded-lg">
        <h2 class="text-xl font-bold text-red-800 mb-2">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            ไม่พบตาราง cny_products
        </h2>
        <p class="text-red-700">
            กรุณา sync ข้อมูลจาก CNY API ก่อนที่หน้า 
            <a href="/admin/setup-cny.php" class="underline">Setup CNY</a>
        </p>
    </div>
    <?php else: ?>

    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">
            <i class="fas fa-file-csv text-green-500 mr-2"></i>
            Export CNY Products to CSV
        </h1>
        
        <p class="text-gray-600 mb-6">
            ดึงข้อมูลสินค้าจากตาราง <code class="bg-gray-100 px-2 py-1 rounded">cny_products</code> 
            และ export เป็นไฟล์ CSV ที่สามารถ import เข้าตาราง <code class="bg-gray-100 px-2 py-1 rounded">business_items</code> ได้โดยตรง
        </p>

        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <div class="flex items-center">
                <i class="fas fa-database text-blue-500 mr-3 text-xl"></i>
                <div>
                    <p class="font-medium text-blue-800">สินค้าในตาราง cny_products</p>
                    <p class="text-3xl font-bold text-blue-600"><?= number_format($totalProducts) ?> รายการ</p>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div class="space-y-4 mb-6">
            <h3 class="font-semibold text-gray-800">เลือกวิธี Export:</h3>
            
            <!-- Option 1: Full Download -->
            <div class="border rounded-lg p-4 hover:bg-gray-50">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-download text-green-600 text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-800">Download ทั้งหมด</h4>
                        <p class="text-sm text-gray-600 mb-3">
                            ดาวน์โหลดสินค้าทั้งหมดเป็นไฟล์ CSV เดียว (จาก Database - เร็วมาก)
                        </p>
                        <a href="?action=download" 
                           class="inline-block px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                            <i class="fas fa-file-csv mr-2"></i>Download CSV (<?= number_format($totalProducts) ?> รายการ)
                        </a>
                    </div>
                </div>
            </div>

            <!-- Option 2: Chunked Download -->
            <?php if ($totalProducts > 500): ?>
            <div class="border rounded-lg p-4 hover:bg-gray-50">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-layer-group text-blue-600 text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-800">Download แบบแบ่งไฟล์</h4>
                        <p class="text-sm text-gray-600 mb-3">
                            แบ่งดาวน์โหลดเป็นไฟล์ละ 500 รายการ
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <?php 
                            $chunks = ceil($totalProducts / 500);
                            for ($i = 0; $i < min($chunks, 20); $i++): 
                                $offset = $i * 500;
                                $end = min($offset + 500, $totalProducts);
                            ?>
                            <a href="?action=download_chunk&offset=<?= $offset ?>&limit=500" 
                               class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 text-sm">
                                <?= $offset + 1 ?>-<?= $end ?>
                            </a>
                            <?php endfor; ?>
                            <?php if ($chunks > 20): ?>
                            <span class="text-gray-500 text-sm">... และอีก <?= $chunks - 20 ?> ไฟล์</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- CSV Format Info -->
        <div class="bg-gray-50 rounded-lg p-4">
            <h3 class="font-semibold text-gray-800 mb-3">
                <i class="fas fa-table mr-2"></i>รูปแบบ CSV
            </h3>
            <p class="text-sm text-gray-600 mb-2">Columns ที่จะ export (ตรงกับ business_items):</p>
            <div class="flex flex-wrap gap-2 text-xs">
                <?php
                $columns = ['id', 'sku', 'barcode', 'name', 'name_en', 'description', 'price', 'stock', 
                           'image_url', 'is_active', 'generic_name', 'usage_instructions',
                           'manufacturer', 'unit', 'base_unit', 'product_price', 'properties_other',
                           'photo_path', 'cny_id', 'cny_category', 'hashtag', 'qty_incoming', 'enable'];
                foreach ($columns as $col):
                ?>
                <span class="px-2 py-1 bg-white border rounded"><?= $col ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Import Instructions -->
        <div class="mt-6 border-t pt-6">
            <h3 class="font-semibold text-gray-800 mb-3">
                <i class="fas fa-upload mr-2"></i>วิธี Import
            </h3>
            <div class="space-y-2 text-sm text-gray-600">
                <p><strong>วิธีที่ 1:</strong> ใช้หน้า <a href="/sync-dashboard.php" class="text-blue-600 underline">Sync Dashboard</a> → Method 1: Import from CSV → Upload ไฟล์</p>
                <p><strong>วิธีที่ 2:</strong> ใช้ phpMyAdmin → Import → เลือกไฟล์ CSV</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php 
require_once __DIR__ . '/../includes/footer.php';

// ==================== FUNCTIONS ====================

function downloadCsv($db) {
    // Get all products from database
    $stmt = $db->query("SELECT * FROM cny_products ORDER BY id");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    outputCsv($products, 'cny_products_export_' . date('Y-m-d_His') . '.csv');
}

function downloadCsvChunk($db, $offset, $limit) {
    $stmt = $db->prepare("SELECT * FROM cny_products ORDER BY id LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $end = $offset + count($products);
    outputCsv($products, "cny_products_{$offset}-{$end}.csv");
}

function outputCsv($products, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    $headers = [
        'id', 'sku', 'barcode', 'name', 'name_en', 'description', 'price', 'stock',
        'image_url', 'is_active', 'generic_name', 'usage_instructions',
        'manufacturer', 'unit', 'base_unit', 'product_price', 'properties_other',
        'photo_path', 'cny_id', 'cny_category', 'hashtag', 'qty_incoming', 'enable'
    ];
    fputcsv($output, $headers);
    
    // Data rows
    foreach ($products as $p) {
        $row = mapProductToRow($p);
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function mapProductToRow($p) {
    // Get price from product_price JSON
    $price = 0;
    $unit = '';
    $baseUnit = '';
    $productPrice = $p['product_price'] ?? '';
    
    if (!empty($productPrice)) {
        $prices = json_decode($productPrice, true);
        
        // Handle double-encoded JSON
        if (is_string($prices)) {
            $prices = json_decode($prices, true);
        }
        
        if (is_array($prices) && !empty($prices)) {
            // Try GEN price first
            foreach ($prices as $pr) {
                $group = $pr['customer_group'] ?? '';
                if (strpos($group, 'GEN') !== false) {
                    $price = floatval($pr['price'] ?? 0);
                    break;
                }
            }
            // Fallback to first price
            if ($price == 0 && isset($prices[0]['price'])) {
                $price = floatval($prices[0]['price']);
            }
            // Get unit
            if (!empty($prices[0]['unit'])) {
                $unit = $prices[0]['unit'];
                if (preg_match('/^([^\[\s]+)/', $unit, $matches)) {
                    $baseUnit = trim($matches[1]);
                }
            }
        }
    }
    
    // Extract manufacturer from name_en
    $manufacturer = '';
    if (!empty($p['name_en']) && preg_match('/\[([^\]]+)\]/', $p['name_en'], $matches)) {
        $manufacturer = $matches[1];
    }
    
    // Clean description - skip full HTML pages
    $description = '';
    $desc = $p['description'] ?? '';
    if (!empty($desc) && strpos($desc, '<!doctype') === false && strpos($desc, '<html') === false) {
        $description = strip_tags($desc);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim(substr($description, 0, 2000));
    }
    
    // Clean usage instructions
    $usageInstructions = '';
    $howToUse = $p['how_to_use'] ?? '';
    if (!empty($howToUse) && strpos($howToUse, '<!doctype') === false && strpos($howToUse, '<html') === false) {
        $usageInstructions = strip_tags($howToUse);
        $usageInstructions = preg_replace('/\s+/', ' ', $usageInstructions);
        $usageInstructions = trim(substr($usageInstructions, 0, 5000));
    }
    
    // Clean properties
    $propertiesOther = '';
    $props = $p['properties_other'] ?? '';
    if (!empty($props) && strpos($props, '<!doctype') === false && strpos($props, '<html') === false) {
        $propertiesOther = strip_tags($props);
        $propertiesOther = preg_replace('/\s+/', ' ', $propertiesOther);
        $propertiesOther = trim(substr($propertiesOther, 0, 5000));
    }
    
    return [
        $p['id'] ?? '',                                    // id
        $p['sku'] ?? '',                                   // sku
        $p['barcode'] ?? '',                               // barcode
        $p['name'] ?? '',                                  // name
        $p['name_en'] ?? '',                               // name_en
        $description,                                       // description
        $price,                                            // price
        intval($p['qty'] ?? 0),                            // stock
        $p['photo_path'] ?? '',                            // image_url
        ($p['enable'] ?? '1') == '1' ? 1 : 0,              // is_active
        $p['spec_name'] ?? '',                             // generic_name
        $usageInstructions,                                // usage_instructions
        $manufacturer,                                     // manufacturer
        $unit,                                             // unit
        $baseUnit,                                         // base_unit
        $productPrice,                                     // product_price (JSON)
        $propertiesOther,                                  // properties_other
        $p['photo_path'] ?? '',                            // photo_path
        $p['id'] ?? '',                                    // cny_id
        $p['category'] ?? '',                              // cny_category
        $p['hashtag'] ?? '',                               // hashtag
        intval($p['qty_incoming'] ?? 0),                   // qty_incoming
        ($p['enable'] ?? '1') == '1' ? 1 : 0               // enable
    ];
}
