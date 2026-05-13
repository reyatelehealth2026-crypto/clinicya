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
    $stmt = $db->query("SHOW COLUMNS FROM business_items LIKE 'barcode'");
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
            $rows   = $parsed['rows'];

            // Map columns
            $colMap = [
                'name'               => findColumn(['name', 'ชื่อ', 'ชื่อสินค้า', 'product_name'], $header),
                'description'        => findColumn(['description', 'รายละเอียด', 'desc'], $header),
                'price'              => findColumn(['price', 'ราคา', 'unit_price'], $header),
                'sale_price'         => findColumn(['sale_price', 'ราคาขาย', 'saleprice', 'ราคาลด'], $header),
                'stock'              => findColumn(['stock', 'สต็อก', 'quantity', 'qty', 'จำนวน'], $header),
                'category'           => findColumn(['category', 'หมวดหมู่', 'cat'], $header),
                'image_url'          => findColumn(['image_url', 'รูปภาพ', 'image', 'img'], $header),
                'sku'                => findColumn(['sku', 'รหัสสินค้า', 'product_code', 'code'], $header),
                'barcode'            => findColumn(['barcode', 'บาร์โค้ด'], $header),
                'manufacturer'       => findColumn(['manufacturer', 'ผู้ผลิต', 'brand'], $header),
                'generic_name'       => findColumn(['generic_name', 'ชื่อสามัญยา', 'generic'], $header),
                'usage_instructions' => findColumn(['usage_instructions', 'วิธีใช้', 'how_to_use'], $header),
                'unit'               => findColumn(['unit', 'หน่วย', 'หน่วยนับ'], $header),
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
                    $price    = floatval(preg_replace('/[^0-9.]/', '', $priceRaw));
                    $sku      = $colMap['sku'] !== false ? trim($data[$colMap['sku']] ?? '') : '';

                    // Check if exists (shared products - no line_account_id filter)
                    $exists = false;
                    if ($sku) {
                        $stmt = $db->prepare("SELECT id FROM business_items WHERE sku = ?");
                        $stmt->execute([$sku]);
                        $exists = $stmt->fetch() !== false;
                    }
                    if (!$exists) {
                        $stmt = $db->prepare("SELECT id FROM business_items WHERE name = ?");
                        $stmt->execute([$name]);
                        $exists = $stmt->fetch() !== false;
                    }

                    $previewData[] = [
                        'row'      => $idx + 2,
                        'sku'      => $sku,
                        'barcode'  => $colMap['barcode'] !== false ? trim($data[$colMap['barcode']] ?? '') : '',
                        'name'     => $name,
                        'price'    => $price,
                        'stock'    => $colMap['stock'] !== false ? intval($data[$colMap['stock']] ?? 0) : 0,
                        'category' => $colMap['category'] !== false ? trim($data[$colMap['category']] ?? '') : '',
                        'unit'     => $colMap['unit'] !== false ? trim($data[$colMap['unit']] ?? '') : 'ชิ้น',
                        'exists'   => $exists,
                        'raw'      => $data,
                    ];
                }

                $showPreview = true;
            }
        }
    }
}

// Step 2: Confirm Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $tempFile     = $_SESSION['import_temp_file'] ?? '';
    $colMap       = $_SESSION['import_col_map'] ?? [];
    $updateExisting = $_SESSION['import_update_existing'] ?? false;

    if (!$tempFile || !file_exists($tempFile)) {
        $error = 'ไม่พบไฟล์ที่อัพโหลด กรุณาอัพโหลดใหม่';
    } elseif (empty($colMap)) {
        $error = 'ข้อมูล column mapping หายไป กรุณาอัพโหลดใหม่';
    } else {
        $parsed = parseCSVFile($tempFile);
        $rows   = $parsed['rows'];

        foreach ($rows as $idx => $data) {
            $row  = $idx + 2;
            $name = trim($data[$colMap['name']] ?? '');
            if (empty($name)) {
                $errors[] = "แถว $row: ไม่มีชื่อสินค้า";
                $skipped++;
                continue;
            }

            $priceRaw    = $colMap['price'] !== false ? ($data[$colMap['price']] ?? '0') : '0';
            $price       = floatval(preg_replace('/[^0-9.]/', '', $priceRaw));
            $salePriceRaw = $colMap['sale_price'] !== false ? ($data[$colMap['sale_price']] ?? '') : '';
            $salePrice   = !empty($salePriceRaw) ? floatval(preg_replace('/[^0-9.]/', '', $salePriceRaw)) : null;

            $stock             = $colMap['stock'] !== false ? intval($data[$colMap['stock']] ?? 0) : 0;
            $description       = $colMap['description'] !== false ? trim($data[$colMap['description']] ?? '') : '';
            $category          = $colMap['category'] !== false ? trim($data[$colMap['category']] ?? '') : '';
            $imageUrl          = $colMap['image_url'] !== false ? trim($data[$colMap['image_url']] ?? '') : '';
            $sku               = $colMap['sku'] !== false ? trim($data[$colMap['sku']] ?? '') : '';
            $barcode           = $colMap['barcode'] !== false ? trim($data[$colMap['barcode']] ?? '') : '';
            $manufacturer      = $colMap['manufacturer'] !== false ? trim($data[$colMap['manufacturer']] ?? '') : '';
            $genericName       = $colMap['generic_name'] !== false ? trim($data[$colMap['generic_name']] ?? '') : '';
            $usageInstructions = $colMap['usage_instructions'] !== false ? trim($data[$colMap['usage_instructions']] ?? '') : '';
            $unit              = $colMap['unit'] !== false ? trim($data[$colMap['unit']] ?? '') : 'ชิ้น';

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
                    $stmt = $db->prepare("SELECT id FROM business_items WHERE sku = ?");
                    $stmt->execute([$sku]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existing) {
                    $stmt = $db->prepare("SELECT id FROM business_items WHERE name = ?");
                    $stmt->execute([$name]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if ($existing && $updateExisting) {
                    if ($hasNewColumns) {
                        $sql = "UPDATE business_items SET
                                description = ?, price = ?, sale_price = ?, stock = ?,
                                category_id = ?, image_url = ?, sku = ?, barcode = ?,
                                manufacturer = ?, generic_name = ?, usage_instructions = ?, unit = ?,
                                updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $barcode, $manufacturer, $genericName, $usageInstructions, $unit, $existing['id']]);
                    } else {
                        $sql = "UPDATE business_items SET
                                description = ?, price = ?, sale_price = ?, stock = ?,
                                category_id = ?, image_url = ?, sku = ?, updated_at = NOW() WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $existing['id']]);
                    }
                    $imported++;
                } elseif (!$existing) {
                    if ($hasNewColumns) {
                        $sql = "INSERT INTO business_items
                                (name, description, price, sale_price, stock, category_id, image_url, sku, barcode, manufacturer, generic_name, usage_instructions, unit, is_active, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                        $stmt = $db->prepare($sql);
                        $stmt->execute([$name, $description, $price, $salePrice, $stock, $categoryId, $imageUrl, $sku, $barcode, $manufacturer, $genericName, $usageInstructions, $unit]);
                    } else {
                        $sql = "INSERT INTO business_items
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
$stmt = $db->query("SELECT COUNT(*) FROM business_items");
$totalProducts = $stmt->fetchColumn();

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/data-table.php';
require_once __DIR__ . '/../includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getDataTableStyles() ?>

<style>
.import-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    margin-bottom: var(--space-6);
}
.import-card h2 { margin: 0 0 var(--space-4); font-size: var(--text-lg); font-weight: 600; color: var(--color-dark-800); }
.import-card h3 { margin: 0 0 var(--space-3); font-size: var(--text-sm); font-weight: 500; color: var(--color-dark-700); }
.import-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-6); }
.drop-zone {
    border: 2px dashed var(--color-slate-300);
    border-radius: var(--radius-lg);
    padding: var(--space-8);
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
}
.drop-zone:hover, .drop-zone.drag-over {
    border-color: var(--color-emerald-500);
    background: var(--color-emerald-50);
}
.form-check { display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--color-dark-700); }
.btn-preview {
    width: 100%; padding: 12px;
    border: none; border-radius: var(--radius-md);
    background: var(--color-primary-600); color: #fff;
    font-size: var(--text-sm); font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: var(--space-2);
    margin-top: var(--space-4);
}
.btn-preview:hover { background: var(--color-primary-700); }
.btn-confirm {
    padding: 10px var(--space-5); border: none; border-radius: var(--radius-md);
    background: var(--color-emerald-500); color: #fff;
    font-size: var(--text-sm); font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: var(--space-2);
}
.btn-confirm:hover { background: var(--color-emerald-600); }
.btn-cancel {
    padding: 10px var(--space-4); border: 1px solid var(--color-slate-200); border-radius: var(--radius-md);
    background: var(--color-slate-100); color: var(--color-dark-800);
    font-size: var(--text-sm); font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: var(--space-2); text-decoration: none;
}
.info-box {
    padding: var(--space-3); background: var(--color-primary-50);
    border-radius: var(--radius-md); font-size: var(--text-sm);
    color: var(--color-primary-700); margin-top: var(--space-4);
}
.alert-success { padding: var(--space-4); background: var(--color-emerald-50); color: var(--color-emerald-700); border-radius: var(--radius-md); margin-bottom: var(--space-4); font-size: var(--text-sm); }
.alert-error   { padding: var(--space-4); background: var(--color-rose-50);    color: var(--color-rose-700);    border-radius: var(--radius-md); margin-bottom: var(--space-4); font-size: var(--text-sm); }
.alert-warning { padding: var(--space-4); background: var(--color-amber-50);   color: var(--color-amber-700);   border-radius: var(--radius-md); margin-bottom: var(--space-4); font-size: var(--text-sm); }
.preview-bar {
    padding: var(--space-4); display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: var(--space-3);
}
.badge-new    { padding: 2px 8px; background: var(--color-emerald-100); color: var(--color-emerald-700); border-radius: var(--radius-full); font-size: var(--text-xs); }
.badge-exists { padding: 2px 8px; background: var(--color-amber-100);   color: var(--color-amber-700);   border-radius: var(--radius-full); font-size: var(--text-xs); }
.row-exists { background: rgba(245,158,11,0.05) !important; }
@media (max-width: 768px) { .import-grid { grid-template-columns: 1fr; } }
.dark .import-card { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .import-card h2, .dark .import-card h3 { color: var(--color-slate-100); }
.dark .drop-zone { border-color: var(--color-dark-600); }
.dark .drop-zone:hover, .dark .drop-zone.drag-over { border-color: var(--color-emerald-500); background: rgba(16,185,129,0.08); }
.dark .form-check { color: var(--color-slate-300); }
.dark .info-box { background: rgba(99,102,241,0.12); color: var(--color-primary-300); }
.dark .btn-cancel { background: var(--color-dark-700); border-color: var(--color-dark-600); color: var(--color-slate-200); }
.dark .row-exists { background: rgba(245,158,11,0.08) !important; }
</style>

<?php
echo renderPageHeader(
    'นำเข้าสินค้าจาก CSV',
    'อัพโหลดไฟล์ CSV เพื่อเพิ่มสินค้าหลายรายการพร้อมกัน',
    ['label' => 'กลับ', 'icon' => 'fas fa-arrow-left', 'href' => 'products.php', 'type' => 'link', 'variant' => 'primary'],
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'นำเข้าสินค้า', 'href' => null]]
);
?>

<?php if ($message): ?>
<div class="alert-success"><i class="fas fa-check-circle" style="margin-right:var(--space-2);"></i><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert-error"><i class="fas fa-exclamation-circle" style="margin-right:var(--space-2);"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert-warning">
    <p style="font-weight:600;margin:0 0 var(--space-2);"><i class="fas fa-exclamation-triangle" style="margin-right:var(--space-2);"></i>พบข้อผิดพลาดบางรายการ:</p>
    <ul style="margin:0;padding-left:var(--space-5);">
        <?php foreach (array_slice($errors, 0, 10) as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
        <?php if (count($errors) > 10): ?><li>...และอีก <?= count($errors) - 10 ?> รายการ</li><?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($showPreview && !empty($previewData)): ?>
<!-- Preview Section -->
<div class="data-table-card" style="margin-bottom:var(--space-6);">
    <div class="preview-bar" style="border-bottom:1px solid var(--color-slate-200);">
        <div>
            <h2 style="margin:0 0 4px;font-size:var(--text-lg);font-weight:600;color:var(--color-dark-800);">
                <i class="fas fa-eye" style="color:var(--color-primary-500);margin-right:var(--space-2);"></i>ตรวจสอบข้อมูลก่อนนำเข้า
            </h2>
            <p style="margin:0;font-size:var(--text-sm);color:var(--color-dark-500);">
                พบ <?= count($previewData) ?> รายการ |
                <span style="color:var(--color-emerald-600);"><?= count(array_filter($previewData, fn($p) => !$p['exists'])) ?> รายการใหม่</span> |
                <span style="color:var(--color-amber-600);"><?= count(array_filter($previewData, fn($p) => $p['exists'])) ?> รายการมีอยู่แล้ว</span>
            </p>
        </div>
        <div style="display:flex;gap:var(--space-2);">
            <a href="import-products.php" class="btn-cancel"><i class="fas fa-times"></i>ยกเลิก</a>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="confirm_import" value="1">
                <button type="submit" class="btn-confirm"><i class="fas fa-check"></i>ยืนยันนำเข้า <?= count($previewData) ?> รายการ</button>
            </form>
        </div>
    </div>

    <?php
    $previewCols = [
        ['key' => 'row',      'label' => '#',         'align' => 'left',   'render' => fn($p) => '<span style="color:var(--color-dark-500);">' . $p['row'] . '</span>'],
        ['key' => 'sku',      'label' => 'SKU',       'align' => 'left',   'render' => fn($p) => '<span style="font-family:var(--font-mono);font-size:var(--text-xs);">' . htmlspecialchars($p['sku'] ?: '-') . '</span>'],
        ['key' => 'barcode',  'label' => 'Barcode',   'align' => 'left',   'render' => fn($p) => '<span style="font-family:var(--font-mono);font-size:var(--text-xs);">' . htmlspecialchars($p['barcode'] ?: '-') . '</span>'],
        ['key' => 'name',     'label' => 'ชื่อสินค้า', 'align' => 'left',   'render' => fn($p) => '<div style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . htmlspecialchars($p['name']) . '">' . htmlspecialchars($p['name']) . '</div>'],
        ['key' => 'price',    'label' => 'ราคา',      'align' => 'right',  'render' => fn($p) => '<span style="font-weight:600;">' . number_format($p['price'], 2) . '</span>'],
        ['key' => 'stock',    'label' => 'สต็อก',     'align' => 'right',  'render' => fn($p) => number_format($p['stock'])],
        ['key' => 'unit',     'label' => 'หน่วย',     'align' => 'left',   'render' => fn($p) => htmlspecialchars($p['unit'])],
        ['key' => 'category', 'label' => 'หมวดหมู่',  'align' => 'left',   'render' => fn($p) => htmlspecialchars($p['category'] ?: '-')],
        ['key' => 'exists',   'label' => 'สถานะ',     'align' => 'center', 'render' => function ($p) {
            if ($p['exists']) {
                $label = ($_SESSION['import_update_existing'] ?? false) ? 'อัพเดท' : 'ข้าม';
                return '<span class="badge-exists">' . $label . '</span>';
            }
            return '<span class="badge-new">เพิ่มใหม่</span>';
        }],
    ];
    echo renderDataTable($previewCols, array_slice($previewData, 0, 100), [
        'rowClass' => fn($p) => $p['exists'] ? 'row-exists' : '',
    ]);
    ?>

    <?php if (count($previewData) > 100): ?>
    <div style="padding:var(--space-4);background:var(--color-slate-50);text-align:center;color:var(--color-dark-500);font-size:var(--text-sm);border-top:1px solid var(--color-slate-200);">
        แสดง 100 รายการแรก จากทั้งหมด <?= count($previewData) ?> รายการ
    </div>
    <?php endif; ?>

    <div class="preview-bar" style="border-top:1px solid var(--color-slate-200);background:var(--color-slate-50);">
        <div style="font-size:var(--text-sm);color:var(--color-dark-500);">
            <i class="fas fa-info-circle" style="margin-right:var(--space-1);"></i>
            <?php if ($_SESSION['import_update_existing'] ?? false): ?>
                สินค้าที่มีอยู่แล้วจะถูก<strong style="color:var(--color-amber-600);">อัพเดท</strong>
            <?php else: ?>
                สินค้าที่มีอยู่แล้วจะถูก<strong>ข้าม</strong>
            <?php endif; ?>
        </div>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="confirm_import" value="1">
            <button type="submit" class="btn-confirm"><i class="fas fa-check"></i>ยืนยันนำเข้า</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Upload Form -->
<div class="import-grid">
    <div class="import-card">
        <h2><i class="fas fa-upload" style="color:var(--color-emerald-500);margin-right:var(--space-2);"></i>อัพโหลดไฟล์</h2>

        <form method="POST" enctype="multipart/form-data">
            <div class="drop-zone" id="dropZone">
                <input type="file" name="csv_file" id="csvFile" accept=".csv,.tsv,.txt" style="display:none;" required>
                <div style="font-size:48px;color:var(--color-slate-300);margin-bottom:var(--space-4);"><i class="fas fa-file-csv"></i></div>
                <p style="color:var(--color-dark-500);font-size:var(--text-sm);margin:0 0 var(--space-2);">ลากไฟล์มาวางที่นี่ หรือ</p>
                <button type="button" onclick="document.getElementById('csvFile').click()"
                        style="padding:8px var(--space-4);border:none;border-radius:var(--radius-md);background:var(--color-emerald-500);color:#fff;font-size:var(--text-sm);font-weight:600;cursor:pointer;">
                    เลือกไฟล์ CSV
                </button>
                <p id="fileName" style="margin-top:var(--space-2);font-size:var(--text-sm);color:var(--color-dark-500);"></p>
            </div>

            <div style="margin-top:var(--space-4);">
                <label class="form-check">
                    <input type="checkbox" name="update_existing" id="updateExisting" style="accent-color:var(--color-emerald-500);">
                    <span>อัพเดทสินค้าที่มีอยู่แล้ว (ตรวจสอบจากชื่อหรือ SKU)</span>
                </label>
            </div>

            <button type="submit" class="btn-preview">
                <i class="fas fa-eye"></i>ตรวจสอบข้อมูล (Preview)
            </button>
        </form>

        <div class="info-box">
            <i class="fas fa-info-circle" style="margin-right:var(--space-1);"></i>
            สินค้าปัจจุบัน: <strong><?= number_format($totalProducts) ?></strong> รายการ
        </div>
    </div>

    <!-- Instructions -->
    <div class="import-card">
        <h2><i class="fas fa-info-circle" style="color:var(--color-primary-500);margin-right:var(--space-2);"></i>รูปแบบไฟล์ CSV</h2>

        <h3>คอลัมน์ที่รองรับ:</h3>
        <?php
        $csvCols = [
            ['key' => 'col',      'label' => 'คอลัมน์',  'align' => 'left',   'render' => fn($r) => '<span style="font-family:var(--font-mono);font-size:var(--text-xs);">' . $r['col'] . '</span>'],
            ['key' => 'desc',     'label' => 'คำอธิบาย', 'align' => 'left',   'render' => fn($r) => $r['desc']],
            ['key' => 'required', 'label' => 'จำเป็น',   'align' => 'center', 'render' => fn($r) => $r['required'] ? '<span style="color:var(--color-emerald-600);font-weight:600;">✓</span>' : '-'],
        ];
        $csvRows = [
            ['col' => 'name / ชื่อ',       'desc' => 'ชื่อสินค้า',   'required' => true],
            ['col' => 'price / ราคา',       'desc' => 'ราคาปกติ',     'required' => true],
            ['col' => 'sku / รหัสสินค้า',   'desc' => 'รหัส SKU',     'required' => false],
            ['col' => 'barcode',            'desc' => 'บาร์โค้ด',     'required' => false],
            ['col' => 'manufacturer',       'desc' => 'ผู้ผลิต',      'required' => false],
            ['col' => 'generic_name',       'desc' => 'ชื่อสามัญยา', 'required' => false],
            ['col' => 'usage_instructions', 'desc' => 'วิธีใช้',      'required' => false],
            ['col' => 'unit / หน่วย',       'desc' => 'หน่วยนับ',     'required' => false],
            ['col' => 'stock / สต็อก',      'desc' => 'จำนวน',        'required' => false],
            ['col' => 'category',           'desc' => 'หมวดหมู่',     'required' => false],
        ];
        echo renderDataTable($csvCols, $csvRows);
        ?>

        <div style="display:flex;gap:var(--space-2);margin-top:var(--space-4);">
            <a href="sample-products.csv" download
               style="flex:1;padding:10px;text-align:center;background:var(--color-primary-600);color:#fff;border-radius:var(--radius-md);font-size:var(--text-sm);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:var(--space-2);">
                <i class="fas fa-download"></i>ดาวน์โหลดตัวอย่าง
            </a>
            <a href="export-products.php"
               style="flex:1;padding:10px;text-align:center;background:var(--color-slate-500);color:#fff;border-radius:var(--radius-md);font-size:var(--text-sm);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:var(--space-2);">
                <i class="fas fa-file-export"></i>Export สินค้า
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

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
        dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('drag-over');
    });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length && /\.(csv|tsv|txt)$/i.test(files[0].name)) {
            fileInput.files = files;
            document.getElementById('fileName').textContent = '📄 ' + files[0].name;
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
