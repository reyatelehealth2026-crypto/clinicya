<?php
/**
 * Import Products from CSV - With Preview
 * นำเข้าสินค้าจากไฟล์ CSV พร้อม Preview ก่อนยืนยัน
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'นำเข้าสินค้า CSV';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

$message = '';
$error = '';
$imported = 0;
$skipped = 0;
$errors = [];
$previewData = [];
$showPreview = false;
$colMap = [];
$header = [];

// Check if new columns exist
$hasNewColumns = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'barcode'");
    $hasNewColumns = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// Helper function to find column index
function findColumn($names, $header) {
    foreach ((array)$names as $name) {
        $idx = array_search(strtolower($name), $header);
        if ($idx !== false) return $idx;
    }
    return false;
}

// Helper function to parse CSV file
function parseCSVFile($filePath) {
    $content = file_get_contents($filePath);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // Remove BOM
    
    $firstLine = strtok($content, "\n");
    $delimiter = (substr_count($firstLine, "\t") > substr_count($firstLine, ",")) ? "\t" : ",";
    
    $handle = fopen($filePath, 'r');
    $header = fgetcsv($handle, 0, $delimiter);
    
    if (!$header) {
        fclose($handle);
        return ['error' => 'ไม่สามารถอ่านไฟล์ได้'];
    }
    
    // Normalize header
    $header = array_map(function($h) {
        return strtolower(trim(str_replace([' ', '-'], '_', $h)));
    }, $header);
    
    $rows = [];
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (!empty(array_filter($data))) {
            $rows[] = $data;
        }
    }
    fclose($handle);
    
    return ['header' => $header, 'rows' => $rows, 'delimiter' => $delimiter];
}

// Step 1: Upload and Preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && !isset($_POST['confirm_import'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'เกิดข้อผิดพลาดในการอัพโหลดไฟล์';
    } elseif (!preg_match('/\.(csv|tsv|txt)$/i', $file['name'])) {
        $error = 'กรุณาอัพโหลดไฟล์ CSV หรือ TSV เท่านั้น';
    } else {
        // Save file temporarily
        $tempFile = sys_get_temp_dir() . '/import_' . session_id() . '.csv';
        move_uploaded_file($file['tmp_name'], $tempFile);
        $_SESSION['import_temp_file'] = $tempFile;
        $_SESSION['import_update_existing'] = isset($_POST['update_existing']);
        
        $parsed = parseCSVFile($tempFile);
        
        if (isset($parsed['error'])) {
            $error = $parsed['error'];
        } else {
            $header = $parsed['header'];
            $rows = $parsed['rows'];
            
            // Map columns
            $colMap = [
                'name' => findColumn(['name', 'ชื่อ', 'ชื่อสินค้า', 'product_name'], $header),
                'description' => findColumn(['description', 'รายละเอียด', 'desc'], $header),
                'price' => findColumn(['price', 'ราคา', 'unit_price'], $header),
                'sale_price' => findColumn(['sale_price', 'ราคาขาย', 'saleprice', 'ราคาลด'], $header),
                'stock' => findColumn(['stock', 'สต็อก', 'quantity', 'qty', 'จำนวน'], $header),
                'category' => findColumn(['category', 'หมวดหมู่', 'cat'], $header),
                'image_url' => findColumn(['image_url', 'รูปภาพ', 'image', 'img'], $header),
                'sku' => findColumn(['sku', 'รหัสสินค้า', 'product_code', 'code'], $header),
                'barcode' => findColumn(['barcode', 'บาร์โค้ด'], $header),
                'manufacturer' => findColumn(['manufacturer', 'ผู้ผลิต', 'brand'], $header),
                'generic_name' => findColumn(['generic_name', 'ชื่อสามัญยา', 'generic'], $header),
                'usage_instructions' => findColumn(['usage_instructions', 'วิธีใช้', 'how_to_use'], $header),
                'unit' => findColumn(['unit', 'หน่วย', 'หน่วยนับ'], $header),
            ];
            
            if ($colMap['name'] === false) {
                $error = 'ไม่พบคอลัมน์ชื่อสินค้า (name/ชื่อ/ชื่อสินค้า) ในไฟล์';
            } else {
                $_SESSION['import_col_map'] = $colMap;
                
                // Prepare preview data
                foreach ($rows as $idx => $data) {
                    $name = trim($data[$colMap['name']] ?? '');
                    if (empty($name)) continue;
                    
                    $priceRaw = $colMap['price'] !== false ? ($data[$colMap['price']] ?? '0') : '0';
                    $price = floatval(preg_replace('/[^0-9.]/', '', $priceRaw));
                    
                    $sku = $colMap['sku'] !== false ? trim($data[$colMap['sku']] ?? '') : '';
                    
                    // Check if exists (shared products - no line_account_id filter)
                    $exists = false;
                    if ($sku) {
                        $stmt = $db->prepare("SELECT id FROM products WHERE sku = ?");
                        $stmt->execute([$sku]);
                        $exists = $stmt->fetch() !== false;
                    }
                    if (!$exists) {
                        $stmt = $db->prepare("SELECT id FROM products WHERE name = ?");
                        $stmt->execute([$name]);
                        $exists = $stmt->fetch() !== false;
                    }
                    
                    $previewData[] = [
                        'row' => $idx + 2,
                        'sku' => $sku,
                        'barcode' => $colMap['barcode'] !== false ? trim($data[$colMap['barcode']] ?? '') : '',
                        'name' => $name,
                        'price' => $price,
                        'stock' => $colMap['stock'] !== false ? intval($data[$colMap['stock']] ?? 0) : 0,
                        'category' => $colMap['category'] !== false ? trim($data[$colMap['category']] ?? '') : '',
                        'unit' => $colMap['unit'] !== false ? trim($data[$colMap['unit']] ?? '') : 'ชิ้น',
                        'exists' => $exists,
                        'raw' => $data
                    ];
                }
                
                $showPreview = true;
            }
        }
    }
}

// Step 2: Confirm Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $tempFile = $_SESSION['import_temp_file'] ?? '';
    $colMap = $_SESSION['import_col_map'] ?? [];
    $updateExisting = $_SESSION['import_update_existing'] ?? false;
    
    if (!$tempFile || !file_exists($tempFile)) {
        $error = 'ไม่พบไฟล์ที่อัพโหลด กรุณาอัพโหลดใหม่';
    } elseif (empty($colMap)) {
        $error = 'ข้อมูล column mapping หายไป กรุณาอัพโหลดใหม่';
    } else {
        $parsed = parseCSVFile($tempFile);
        $rows = $parsed['rows'];
        
        foreach ($rows as $idx => $data) {
            $row = $idx + 2;
            
            $name = trim($data[$colMap['name']] ?? '');
            if (empty($name)) {
                $errors[] = "แถว $row: ไม่มีชื่อสินค้า";
                $skipped++;
                continue;
            }
            
            $priceRaw = $colMap['price'] !== false ? ($data[$colMap['price']] ?? '0') : '0';
            $price = floatval(preg_replace('/[^0-9.]/', '', $priceRaw));
            
            $salePriceRaw = $colMap['sale_price'] !== false ? ($data[$colMap['sale_price']] ?? '') : '';
            $salePrice = !empty($salePriceRaw) ? floatval(preg_replace('/[^0-9.]/', '', $salePriceRaw)) : null;
            
            $stock = $colMap['stock'] !== false ? intval($data[$colMap['stock']] ?? 0) : 0;
            $description = $colMap['description'] !== false ? trim($data[$colMap['description']] ?? '') : '';
            $category = $colMap['category'] !== false ? trim($data[$colMap['category']] ?? '') : '';
            $imageUrl = $colMap['image_url'] !== false ? trim($data[$colMap['image_url']] ?? '') : '';
            $sku = $colMap['sku'] !== false ? trim($data[$colMap['sku']] ?? '') : '';
            $barcode = $colMap['barcode'] !== false ? trim($data[$colMap['barcode']] ?? '') : '';
            $manufacturer = $colMap['manufacturer'] !== false ? trim($data[$colMap['manufacturer']] ?? '') : '';
            $genericName = $colMap['generic_name'] !== false ? trim($data[$colMap['generic_name']] ?? '') : '';
            $usageInstructions = $colMap['usage_instructions'] !== false ? trim($data[$colMap['usage_instructions']] ?? '') : '';
            $unit = $colMap['unit'] !== false ? trim($data[$colMap['unit']] ?? '') : 'ชิ้น';
            
            // Get or create category
            $categoryId = null;
            if ($category) {
                $stmt = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
                $stmt->execute([$category]);
                $cat = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($cat) {
                    $categoryId = $cat['id'];
                } else {
                    $stmt = $db->prepare("INSERT INTO product_categories (name, is_active, created_at) VALUES (?, 1, NOW())");
                    $stmt->execute([$category]);
                    $categoryId = $db->lastInsertId();
                }
            }
            
            try {
                $existing = null;
                if ($sku) {
                    $stmt = $db->prepare("SELECT id FROM products WHERE sku = ?");
                    $stmt->execute([$sku]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existing) {
                    $stmt = $db->prepare("SELECT id FROM products WHERE name = ?");
                    $stmt->execute([$name]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if ($existing && $updateExisting) {
                    if ($hasNewColumns) {
                        $sql = "UPDATE products SET 
                                description = ?, price = ?, sale_price = ?, stock = ?, 
                                category_id = ?, image_url = ?, sku = ?, barcode = ?,
                                manufacturer = ?, generic_name = ?, usage_instructions = ?, unit = ?,
                                updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $barcode, $manufacturer, $genericName, $usageInstructions, $unit, $existing['id']]);
                    } else {
                        $sql = "UPDATE products SET 
                                description = ?, price = ?, sale_price = ?, stock = ?, 
                                category_id = ?, image_url = ?, sku = ?, updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $existing['id']]);
                    }
                    $imported++;
                } elseif (!$existing) {
                    if ($hasNewColumns) {
                        $sql = "INSERT INTO products 
                                (name, description, price, sale_price, stock, category_id, image_url, sku, barcode, manufacturer, generic_name, usage_instructions, unit, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$name, $description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $barcode, $manufacturer, $genericName, $usageInstructions, $unit]);
                    } else {
                        $sql = "INSERT INTO products 
                                (name, description, price, sale_price, stock, category_id, image_url, sku, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$name, $description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku]);
                    }
                    $imported++;
                } else {
                    $skipped++;
                }
            } catch (PDOException $e) {
                $errors[] = "แถว $row: " . $e->getMessage();
                $skipped++;
            }
        }
        
        // Cleanup
        @unlink($tempFile);
        unset($_SESSION['import_temp_file'], $_SESSION['import_col_map'], $_SESSION['import_update_existing']);
        
        $message = "นำเข้าสำเร็จ $imported รายการ" . ($skipped > 0 ? ", ข้าม $skipped รายการ" : "");
    }
}

// Get current products count
$stmt = $db->query("SELECT COUNT(*) FROM products");
$totalProducts = $stmt->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📥 นำเข้าสินค้าจาก CSV</h1>
            <p class="text-gray-500">อัพโหลดไฟล์ CSV เพื่อเพิ่มสินค้าหลายรายการพร้อมกัน</p>
        </div>
        <a href="products.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
            <i class="fas fa-arrow-left mr-2"></i>กลับ
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <div class="mb-4 p-4 bg-yellow-100 text-yellow-700 rounded-lg">
        <p class="font-medium mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>พบข้อผิดพลาดบางรายการ:</p>
        <ul class="list-disc list-inside text-sm">
            <?php foreach (array_slice($errors, 0, 10) as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 10): ?>
            <li>...และอีก <?= count($errors) - 10 ?> รายการ</li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    
    <?php if ($showPreview && !empty($previewData)): ?>
    <!-- Preview Section -->
    <div class="bg-white rounded-xl shadow mb-6">
        <div class="p-4 border-b flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold"><i class="fas fa-eye text-blue-500 mr-2"></i>ตรวจสอบข้อมูลก่อนนำเข้า</h2>
                <p class="text-sm text-gray-500">พบ <?= count($previewData) ?> รายการ | 
                    <span class="text-green-600"><?= count(array_filter($previewData, fn($p) => !$p['exists'])) ?> รายการใหม่</span> | 
                    <span class="text-orange-600"><?= count(array_filter($previewData, fn($p) => $p['exists'])) ?> รายการมีอยู่แล้ว</span>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="import-products.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    <i class="fas fa-times mr-1"></i>ยกเลิก
                </a>
                <form method="POST" class="inline">
                    <input type="hidden" name="confirm_import" value="1">
                    <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                        <i class="fas fa-check mr-2"></i>ยืนยันนำเข้า <?= count($previewData) ?> รายการ
                    </button>
                </form>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left w-12">#</th>
                        <th class="px-3 py-2 text-left">SKU</th>
                        <th class="px-3 py-2 text-left">Barcode</th>
                        <th class="px-3 py-2 text-left">ชื่อสินค้า</th>
                        <th class="px-3 py-2 text-right">ราคา</th>
                        <th class="px-3 py-2 text-right">สต็อก</th>
                        <th class="px-3 py-2 text-left">หน่วย</th>
                        <th class="px-3 py-2 text-left">หมวดหมู่</th>
                        <th class="px-3 py-2 text-center">สถานะ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach (array_slice($previewData, 0, 100) as $item): ?>
                    <tr class="hover:bg-gray-50 <?= $item['exists'] ? 'bg-orange-50' : '' ?>">
                        <td class="px-3 py-2 text-gray-500"><?= $item['row'] ?></td>
                        <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($item['sku'] ?: '-') ?></td>
                        <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($item['barcode'] ?: '-') ?></td>
                        <td class="px-3 py-2">
                            <div class="max-w-xs truncate" title="<?= htmlspecialchars($item['name']) ?>">
                                <?= htmlspecialchars($item['name']) ?>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right font-medium"><?= number_format($item['price'], 2) ?></td>
                        <td class="px-3 py-2 text-right"><?= number_format($item['stock']) ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars($item['unit']) ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars($item['category'] ?: '-') ?></td>
                        <td class="px-3 py-2 text-center">
                            <?php if ($item['exists']): ?>
                                <span class="px-2 py-1 bg-orange-100 text-orange-700 rounded text-xs">
                                    <?= $_SESSION['import_update_existing'] ? 'อัพเดท' : 'ข้าม' ?>
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">เพิ่มใหม่</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (count($previewData) > 100): ?>
            <div class="p-4 bg-gray-50 text-center text-gray-500">
                แสดง 100 รายการแรก จากทั้งหมด <?= count($previewData) ?> รายการ
            </div>
            <?php endif; ?>
        </div>
        
        <div class="p-4 border-t bg-gray-50 flex justify-between items-center">
            <div class="text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1"></i>
                <?php if ($_SESSION['import_update_existing'] ?? false): ?>
                    สินค้าที่มีอยู่แล้วจะถูก<strong class="text-orange-600">อัพเดท</strong>
                <?php else: ?>
                    สินค้าที่มีอยู่แล้วจะถูก<strong>ข้าม</strong>
                <?php endif; ?>
            </div>
            <form method="POST" class="inline">
                <input type="hidden" name="confirm_import" value="1">
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                    <i class="fas fa-check mr-2"></i>ยืนยันนำเข้า
                </button>
            </form>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Upload Form -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow p-6">
            <h2 class="text-lg font-semibold mb-4"><i class="fas fa-upload text-green-500 mr-2"></i>อัพโหลดไฟล์</h2>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-green-500 transition" id="dropZone">
                    <input type="file" name="csv_file" id="csvFile" accept=".csv,.tsv,.txt" class="hidden" required>
                    <i class="fas fa-file-csv text-5xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500 mb-2">ลากไฟล์มาวางที่นี่ หรือ</p>
                    <button type="button" onclick="document.getElementById('csvFile').click()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        เลือกไฟล์ CSV
                    </button>
                    <p id="fileName" class="mt-2 text-sm text-gray-500"></p>
                </div>
                
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="update_existing" id="updateExisting" class="rounded text-green-500">
                    <label for="updateExisting" class="text-sm text-gray-600">อัพเดทสินค้าที่มีอยู่แล้ว (ตรวจสอบจากชื่อหรือ SKU)</label>
                </div>
                
                <button type="submit" class="w-full py-3 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600">
                    <i class="fas fa-eye mr-2"></i>ตรวจสอบข้อมูล (Preview)
                </button>
            </form>
            
            <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-700">
                <i class="fas fa-info-circle mr-1"></i>
                สินค้าปัจจุบัน: <strong><?= number_format($totalProducts) ?></strong> รายการ
            </div>
        </div>
        
        <!-- Instructions -->
        <div class="bg-white rounded-xl shadow p-6">
            <h2 class="text-lg font-semibold mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>รูปแบบไฟล์ CSV</h2>
            
            <div class="space-y-4 text-sm">
                <div>
                    <h3 class="font-medium text-gray-700 mb-2">คอลัมน์ที่รองรับ:</h3>
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-1 text-left">คอลัมน์</th>
                                <th class="px-2 py-1 text-left">คำอธิบาย</th>
                                <th class="px-2 py-1 text-center">จำเป็น</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr><td class="px-2 py-1 font-mono">name / ชื่อ</td><td class="px-2 py-1">ชื่อสินค้า</td><td class="px-2 py-1 text-center text-green-500">✓</td></tr>
                            <tr><td class="px-2 py-1 font-mono">price / ราคา</td><td class="px-2 py-1">ราคาปกติ</td><td class="px-2 py-1 text-center text-green-500">✓</td></tr>
                            <tr><td class="px-2 py-1 font-mono">sku / รหัสสินค้า</td><td class="px-2 py-1">รหัส SKU</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">barcode</td><td class="px-2 py-1">บาร์โค้ด</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">manufacturer</td><td class="px-2 py-1">ผู้ผลิต</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">generic_name</td><td class="px-2 py-1">ชื่อสามัญยา</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">usage_instructions</td><td class="px-2 py-1">วิธีใช้</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">unit / หน่วย</td><td class="px-2 py-1">หน่วยนับ</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">stock / สต็อก</td><td class="px-2 py-1">จำนวน</td><td class="px-2 py-1 text-center">-</td></tr>
                            <tr><td class="px-2 py-1 font-mono">category</td><td class="px-2 py-1">หมวดหมู่</td><td class="px-2 py-1 text-center">-</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="flex gap-2">
                    <a href="sample-products.csv" download class="flex-1 py-2 text-center bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm">
                        <i class="fas fa-download mr-1"></i>ดาวน์โหลดตัวอย่าง
                    </a>
                    <a href="export-products.php" class="flex-1 py-2 text-center bg-gray-500 text-white rounded-lg hover:bg-gray-600 text-sm">
                        <i class="fas fa-file-export mr-1"></i>Export สินค้า
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('csvFile')?.addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name || '';
    document.getElementById('fileName').textContent = fileName ? '📄 ' + fileName : '';
});

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('csvFile');

if (dropZone && fileInput) {
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('border-green-500', 'bg-green-50');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('border-green-500', 'bg-green-50');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-green-500', 'bg-green-50');
        
        const files = e.dataTransfer.files;
        if (files.length && /\.(csv|tsv|txt)$/i.test(files[0].name)) {
            fileInput.files = files;
            document.getElementById('fileName').textContent = '📄 ' + files[0].name;
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
