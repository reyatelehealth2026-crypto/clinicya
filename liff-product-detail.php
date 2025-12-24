<?php
/**
 * LIFF Product Detail - หน้ารายละเอียดสินค้าสำหรับลูกค้า
 * แสดงข้อมูลครบถ้วน: ข้อมูลพื้นฐาน, วิธีใช้, รายละเอียด, ข้อมูล CNY
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

$productId = $_GET['id'] ?? 0;
$userId = $_GET['user'] ?? null;
$lineAccountId = $_GET['account'] ?? null;

if (!$productId) {
    header('Location: liff-shop.php');
    exit;
}

// Get product
$stmt = $db->prepare("SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN product_categories c ON p.category_id = c.id 
    WHERE p.id = ?");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: liff-shop.php');
    exit;
}

// Check columns
$columns = [];
try {
    $stmt = $db->query("SHOW COLUMNS FROM products");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }
} catch (Exception $e) {}

// Parse extra_data
$extraData = [];
if (in_array('extra_data', $columns) && !empty($product['extra_data'])) {
    $extraData = json_decode($product['extra_data'], true) ?: [];
}

// Get LIFF ID
require_once 'includes/liff-helper.php';
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];

// Shop settings
$shopSettings = [];
try {
    $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
$shopName = $shopSettings['shop_name'] ?? 'ร้านยา';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($product['name']) ?> - <?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #11B0A6; }
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .info-card { background: white; border-radius: 16px; margin-bottom: 12px; overflow: hidden; }
        .info-header { padding: 12px 16px; background: #F8FAFC; border-bottom: 1px solid #E5E7EB; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .info-body { padding: 16px; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #F3F4F6; }
        .info-row:last-child { border-bottom: none; }
        .info-label { width: 40%; color: #6B7280; font-size: 14px; }
        .info-value { width: 60%; font-size: 14px; color: #1F2937; }
    </style>
</head>
<body class="pb-24">

    <!-- Header -->
    <div class="bg-white px-4 py-3 sticky top-0 z-40 shadow-sm flex items-center gap-3">
        <button onclick="goBack()" class="p-2 -ml-2">
            <i class="fas fa-arrow-left text-gray-600"></i>
        </button>
        <h1 class="font-bold text-gray-800 truncate flex-1"><?= htmlspecialchars($product['name']) ?></h1>
        <button onclick="openCart()" class="relative p-2">
            <i class="fas fa-shopping-cart text-gray-600"></i>
            <span id="cartBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] w-4 h-4 rounded-full flex items-center justify-center hidden">0</span>
        </button>
    </div>

    <div class="px-4 py-4">
        <!-- Product Image -->
        <div class="info-card">
            <div class="aspect-square bg-gray-100 flex items-center justify-center">
                <?php if (!empty($product['image_url'])): ?>
                <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-contain">
                <?php else: ?>
                <i class="fas fa-image text-6xl text-gray-300"></i>
                <?php endif; ?>
            </div>
            
            <!-- Status -->
            <div class="p-4 border-t flex items-center justify-between">
                <span class="text-sm text-gray-600">สถานะ:</span>
                <?php if ($product['is_active']): ?>
                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-medium">
                    <i class="fas fa-check-circle mr-1"></i>พร้อมขาย
                </span>
                <?php else: ?>
                <span class="px-3 py-1 bg-gray-100 text-gray-600 rounded-full text-sm font-medium">
                    <i class="fas fa-pause-circle mr-1"></i>ปิดการขาย
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Price Card -->
        <div class="info-card">
            <div class="info-header">
                <span>💰</span> ราคาขาย
            </div>
            <div class="info-body">
                <?php if (!empty($product['sale_price'])): ?>
                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-red-500">฿<?= number_format($product['sale_price'], 2) ?></span>
                    <span class="text-lg text-gray-400 line-through">฿<?= number_format($product['price'], 2) ?></span>
                </div>
                <div class="mt-2">
                    <span class="text-sm text-red-500 bg-red-50 px-2 py-1 rounded">
                        <i class="fas fa-tag mr-1"></i>ลด <?= round((($product['price'] - $product['sale_price']) / $product['price']) * 100) ?>%
                    </span>
                </div>
                <?php else: ?>
                <div class="text-3xl font-bold text-teal-600">฿<?= number_format($product['price'], 2) ?></div>
                <?php endif; ?>
                <?php if (in_array('unit', $columns) && !empty($product['unit'])): ?>
                <div class="text-sm text-gray-500 mt-1">ต่อ <?= htmlspecialchars($product['unit']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stock Card -->
        <div class="info-card">
            <div class="info-header">
                <span>📦</span> สต็อกสินค้า
            </div>
            <div class="info-body">
                <div class="flex items-center gap-3">
                    <?php if ($product['stock'] <= 0): ?>
                    <span class="text-2xl font-bold text-red-500">0</span>
                    <span class="px-2 py-1 bg-red-100 text-red-600 text-sm rounded">สินค้าหมด</span>
                    <?php elseif ($product['stock'] <= 5): ?>
                    <span class="text-2xl font-bold text-yellow-500"><?= number_format($product['stock']) ?></span>
                    <span class="px-2 py-1 bg-yellow-100 text-yellow-600 text-sm rounded">ใกล้หมด</span>
                    <?php else: ?>
                    <span class="text-2xl font-bold text-green-600"><?= number_format($product['stock']) ?></span>
                    <?php endif; ?>
                    <?php if (in_array('unit', $columns) && !empty($product['unit'])): ?>
                    <span class="text-gray-500"><?= htmlspecialchars($product['unit']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Basic Info -->
        <div class="info-card">
            <div class="info-header">
                <span>ℹ️</span> ข้อมูลพื้นฐาน
            </div>
            <div class="info-body">
                <?php if (in_array('sku', $columns) && !empty($product['sku'])): ?>
                <div class="info-row">
                    <div class="info-label">รหัสสินค้า (SKU)</div>
                    <div class="info-value font-mono"><?= htmlspecialchars($product['sku']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('barcode', $columns) && !empty($product['barcode'])): ?>
                <div class="info-row">
                    <div class="info-label">บาร์โค้ด</div>
                    <div class="info-value font-mono"><?= htmlspecialchars($product['barcode']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-row">
                    <div class="info-label">ชื่อสินค้า</div>
                    <div class="info-value"><?= htmlspecialchars($product['name']) ?></div>
                </div>
                
                <?php if (in_array('generic_name', $columns) && !empty($product['generic_name'])): ?>
                <div class="info-row">
                    <div class="info-label">ชื่อสามัญยา</div>
                    <div class="info-value text-blue-600"><?= htmlspecialchars($product['generic_name']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (in_array('manufacturer', $columns) && !empty($product['manufacturer'])): ?>
                <div class="info-row">
                    <div class="info-label">ผู้ผลิต/จัดจำหน่าย</div>
                    <div class="info-value"><?= htmlspecialchars($product['manufacturer']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-row">
                    <div class="info-label">หมวดหมู่</div>
                    <div class="info-value"><?= htmlspecialchars($product['category_name'] ?? '-') ?></div>
                </div>
                
                <?php if (in_array('unit', $columns) && !empty($product['unit'])): ?>
                <div class="info-row">
                    <div class="info-label">หน่วยนับ</div>
                    <div class="info-value"><?= htmlspecialchars($product['unit']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="info-row">
                    <div class="info-label">ประเภทสินค้า</div>
                    <div class="info-value">
                        <?php
                        $types = [
                            'physical' => '📦 สินค้าจัดส่ง',
                            'digital' => '🎮 สินค้าดิจิทัล',
                            'service' => '💆 บริการ'
                        ];
                        echo $types[$product['item_type'] ?? 'physical'] ?? '📦 สินค้าจัดส่ง';
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Usage Instructions -->
        <?php if (in_array('usage_instructions', $columns) && !empty($product['usage_instructions'])): ?>
        <div class="info-card">
            <div class="info-header">
                <span>📋</span> วิธีใช้ / ขนาดรับประทาน
            </div>
            <div class="info-body">
                <p class="text-gray-700 whitespace-pre-line"><?= nl2br(htmlspecialchars($product['usage_instructions'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if (!empty($product['description'])): ?>
        <div class="info-card">
            <div class="info-header">
                <span>📝</span> รายละเอียดสินค้า
            </div>
            <div class="info-body">
                <p class="text-gray-700 whitespace-pre-line"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- CNY API Data -->
        <?php if (!empty($extraData)): ?>
        <div class="info-card">
            <div class="info-header bg-gradient-to-r from-orange-50 to-yellow-50">
                <span>🏥</span> ข้อมูลจาก CNY Pharmacy API
            </div>
            <div class="info-body">
                <?php if (!empty($extraData['cny_id'])): ?>
                <div class="info-row">
                    <div class="info-label">CNY Product ID</div>
                    <div class="info-value font-mono bg-gray-50 px-2 rounded"><?= htmlspecialchars($extraData['cny_id']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($extraData['name_en'])): ?>
                <div class="info-row">
                    <div class="info-label">ชื่อภาษาอังกฤษ</div>
                    <div class="info-value"><?= htmlspecialchars($extraData['name_en']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($extraData['properties'])): ?>
                <div class="info-row">
                    <div class="info-label">สรรพคุณ</div>
                    <div class="info-value">
                        <div class="bg-green-50 p-2 rounded text-green-700 text-sm"><?= nl2br(htmlspecialchars($extraData['properties'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bottom Add to Cart -->
    <div class="fixed bottom-0 left-0 right-0 bg-white border-t p-4 flex gap-3 z-50">
        <div class="flex items-center border rounded-lg">
            <button onclick="changeQty(-1)" class="px-3 py-2 text-gray-600 hover:bg-gray-100">
                <i class="fas fa-minus"></i>
            </button>
            <input type="number" id="qty" value="1" min="1" max="<?= $product['stock'] ?>" class="w-12 text-center border-0 focus:outline-none">
            <button onclick="changeQty(1)" class="px-3 py-2 text-gray-600 hover:bg-gray-100">
                <i class="fas fa-plus"></i>
            </button>
        </div>
        <button onclick="addToCart(<?= $product['id'] ?>)" class="flex-1 bg-teal-500 text-white py-3 rounded-xl font-bold hover:bg-teal-600 disabled:bg-gray-300 disabled:cursor-not-allowed" <?= $product['stock'] <= 0 ? 'disabled' : '' ?>>
            <i class="fas fa-cart-plus mr-2"></i>
            <?= $product['stock'] <= 0 ? 'สินค้าหมด' : 'เพิ่มลงตะกร้า' ?>
        </button>
    </div>

    <script>
    const userId = '<?= $userId ?>';
    const lineAccountId = '<?= $lineAccountId ?>';
    const liffId = '<?= $liffId ?>';
    const productId = <?= $product['id'] ?>;
    const maxStock = <?= $product['stock'] ?>;
    
    function goBack() {
        window.history.back();
    }
    
    function changeQty(delta) {
        const input = document.getElementById('qty');
        let val = parseInt(input.value) + delta;
        if (val < 1) val = 1;
        if (val > maxStock) val = maxStock;
        input.value = val;
    }
    
    function addToCart(productId) {
        const qty = parseInt(document.getElementById('qty').value);
        let cart = JSON.parse(localStorage.getItem('cart_' + lineAccountId) || '{}');
        cart[productId] = (cart[productId] || 0) + qty;
        localStorage.setItem('cart_' + lineAccountId, JSON.stringify(cart));
        updateCartBadge();
        
        // Show toast
        const toast = document.createElement('div');
        toast.className = 'fixed top-20 left-1/2 -translate-x-1/2 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50';
        toast.innerHTML = '<i class="fas fa-check mr-2"></i>เพิ่มลงตะกร้าแล้ว';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }
    
    function updateCartBadge() {
        const cart = JSON.parse(localStorage.getItem('cart_' + lineAccountId) || '{}');
        const count = Object.values(cart).reduce((a, b) => a + b, 0);
        const badge = document.getElementById('cartBadge');
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
    
    function openCart() {
        window.location.href = 'liff-checkout.php?user=' + userId + '&account=' + lineAccountId;
    }
    
    // Init
    updateCartBadge();
    </script>
</body>
</html>
