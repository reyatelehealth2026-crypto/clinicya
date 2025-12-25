<?php
/**
 * LIFF Checkout Page
 * หน้ากรอกที่อยู่และอัพโหลดสลิปผ่าน LIFF
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Debug mode
$debugMode = isset($_GET['debug']);

// Get order info from URL
$orderId = $_GET['order'] ?? null;
$action = $_GET['action'] ?? 'address'; // address, payment, slip
$userId = $_GET['user'] ?? null; // LINE User ID (Uxxxx)
$lineAccountId = $_GET['account'] ?? null;

// Get line_account_id from user if not provided in URL
if (!$lineAccountId && $userId && strpos($userId, 'U') === 0) {
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$userId]);
        $lineAccountId = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Include LIFF helper
require_once 'includes/liff-helper.php';

// Get Unified LIFF ID (ใช้ liff_id เดียวสำหรับทุกหน้า)
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
if (!$lineAccountId) $lineAccountId = $liffData['line_account_id'];

// Get shop settings based on line_account_id
$shopSettings = [];
try {
    if ($lineAccountId) {
        $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (empty($shopSettings)) {
        $stmt = $db->query("SELECT * FROM shop_settings LIMIT 1");
        $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}

$shopName = $shopSettings['shop_name'] ?? 'LINE Shop';
$promptpay = $shopSettings['promptpay_number'] ?? '';
$bankAccounts = json_decode($shopSettings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];

// Debug output
if ($debugMode) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h2>🔍 LIFF Checkout Debug</h2>";
    echo "<p><strong>User ID (LINE):</strong> " . ($userId ?: 'null') . "</p>";
    echo "<p><strong>Line Account ID:</strong> " . ($lineAccountId ?: 'null') . "</p>";
    echo "<p><strong>LIFF ID:</strong> " . ($liffId ?: 'null') . "</p>";
    echo "<p><strong>Order ID:</strong> " . ($orderId ?: 'null') . "</p>";
    echo "<p><strong>Action:</strong> {$action}</p>";
    
    // Get cart from API
    if ($userId) {
        $dbUserId = null;
        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
            $stmt->execute([$userId]);
            $dbUserId = $stmt->fetchColumn();
            echo "<p><strong>DB User ID:</strong> " . ($dbUserId ?: 'ไม่พบ') . "</p>";
            
            if ($dbUserId) {
                $stmt = $db->prepare("SELECT c.*, p.name, p.price, p.sale_price FROM cart_items c JOIN business_items p ON c.product_id = p.id WHERE c.user_id = ?");
                $stmt->execute([$dbUserId]);
                $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo "<p><strong>Cart Items:</strong> " . count($cartItems) . " รายการ</p>";
                if ($cartItems) {
                    echo "<ul>";
                    foreach ($cartItems as $item) {
                        $price = $item['sale_price'] ?? $item['price'];
                        echo "<li>{$item['name']} x{$item['quantity']} = ฿" . number_format($price * $item['quantity']) . "</li>";
                    }
                    echo "</ul>";
                }
            }
        } catch (Exception $e) {
            echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
        }
    }
    echo "<hr>";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Checkout - <?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #11B0A6;
            --secondary-color: #FF5A5F;
        }
        body { 
            font-family: 'Sarabun', -apple-system, sans-serif; 
            background: #F8FAFC;
            -webkit-tap-highlight-color: transparent;
        }
        .loading { display: none; }
        .loading.active { display: flex; }
        
        /* Delivery Option Card */
        .delivery-option {
            border: 2px solid #E5E7EB;
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .delivery-option:active { transform: scale(0.98); }
        .delivery-option.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, rgba(17, 176, 166, 0.05), rgba(17, 176, 166, 0.1));
        }
        .delivery-option input[type="radio"] {
            accent-color: var(--primary-color);
        }
        
        /* Input Styles */
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #E5E7EB;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(17, 176, 166, 0.1);
        }
        
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 max(10px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
            z-index: 40;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #94A3B8;
            font-size: 10px;
            cursor: pointer;
        }
        .nav-item.active { color: var(--primary-color); }
        .nav-item i { font-size: 20px; margin-bottom: 2px; }
    </style>
</head>
<body class="min-h-screen pb-40">
    <!-- Loading Overlay -->
    <div id="loading" class="loading fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-50">
        <div class="bg-white rounded-2xl p-6 text-center shadow-xl">
            <i class="fas fa-spinner fa-spin text-3xl text-teal-500 mb-3"></i>
            <p class="text-gray-600">กำลังโหลด...</p>
        </div>
    </div>

    <!-- Header -->
    <div class="bg-gradient-to-r from-teal-500 to-teal-600 text-white px-4 py-4 sticky top-0 z-40">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-white/20 transition-colors">
                    <i class="fas fa-arrow-left text-xl"></i>
                </button>
                <div>
                    <h1 class="font-bold text-lg" id="pageTitle">วิธีรับสินค้า</h1>
                    <p class="text-sm text-teal-100" id="pageSubtitle"><?= htmlspecialchars($shopName) ?></p>
                </div>
            </div>
            <div id="userProfile" class="w-10 h-10 rounded-full bg-white/20 overflow-hidden flex items-center justify-center">
                <img id="userPicture" src="" class="w-full h-full object-cover hidden">
                <i class="fas fa-user text-white/60 hidden" id="userIcon"></i>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="p-4">
        <!-- Delivery Method Selection -->
        <div id="addressSection" class="<?= $action !== 'address' ? 'hidden' : '' ?>">
            <!-- Delivery Method -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="font-bold text-lg mb-4 flex items-center text-gray-800">
                    <i class="fas fa-truck text-teal-500 mr-2"></i>วิธีรับสินค้า
                </h2>
                
                <div class="space-y-3">
                    <!-- รับที่ร้าน -->
                    <label class="delivery-option flex items-start gap-3 cursor-pointer" data-method="pickup">
                        <input type="radio" name="deliveryMethod" value="pickup" class="w-5 h-5 mt-1 flex-shrink-0">
                        <div class="flex-1">
                            <p class="font-bold text-gray-800 flex items-center gap-2">
                                <span class="text-xl">🏪</span> รับที่ร้าน
                            </p>
                            <p class="text-sm text-gray-500 mt-1">มารับสินค้าด้วยตัวเองที่ร้าน</p>
                            <p class="text-xs text-teal-600 mt-1 font-medium">ไม่มีค่าใช้จ่ายเพิ่มเติม</p>
                        </div>
                    </label>
                    
                    <!-- ฝากส่ง -->
                    <label class="delivery-option selected flex items-start gap-3 cursor-pointer" data-method="request_delivery">
                        <input type="radio" name="deliveryMethod" value="request_delivery" class="w-5 h-5 mt-1 flex-shrink-0" checked>
                        <div class="flex-1">
                            <p class="font-bold text-gray-800 flex items-center gap-2">
                                <span class="text-xl">📦</span> ฝากส่ง
                            </p>
                            <p class="text-sm text-gray-500 mt-1">ขอให้ร้านฝากส่งสินค้าให้</p>
                            <p class="text-xs text-orange-600 mt-1 font-medium">* ผู้ป่วยเป็นผู้ขอให้ฝากส่ง</p>
                        </div>
                    </label>
                    
                    <!-- เรียก Rider มารับ -->
                    <label class="delivery-option flex items-start gap-3 cursor-pointer" data-method="call_rider">
                        <input type="radio" name="deliveryMethod" value="call_rider" class="w-5 h-5 mt-1 flex-shrink-0">
                        <div class="flex-1">
                            <p class="font-bold text-gray-800 flex items-center gap-2">
                                <span class="text-xl">🏍️</span> เรียก Rider มารับ
                            </p>
                            <p class="text-sm text-gray-500 mt-1">ผู้ป่วยเรียก Rider มารับสินค้าที่ร้านเอง</p>
                            <p class="text-xs text-orange-600 mt-1 font-medium">เหมาะสำหรับพื้นที่ กทม. และปริมณฑล</p>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Shop Location (for pickup) -->
            <div id="pickupInfo" class="bg-white rounded-2xl shadow-sm p-5 mb-4 hidden">
                <h3 class="font-bold mb-3 flex items-center text-gray-800">
                    <i class="fas fa-store text-teal-500 mr-2"></i>ที่อยู่ร้าน
                </h3>
                <div class="bg-teal-50 rounded-xl p-4">
                    <p class="font-bold text-gray-800"><?= htmlspecialchars($shopSettings['shop_name'] ?? $shopName) ?></p>
                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($shopSettings['shop_address'] ?? 'กรุณาติดต่อร้านเพื่อสอบถามที่อยู่') ?></p>
                    <?php if (!empty($shopSettings['contact_phone'])): ?>
                    <p class="text-sm text-gray-600 mt-1"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($shopSettings['contact_phone']) ?></p>
                    <?php endif; ?>
                    
                    <!-- Map -->
                    <div id="shopMap" class="mt-3 rounded-xl overflow-hidden border-2 border-teal-200" style="height: 180px; background: #e5e7eb;">
                        <div class="h-full flex items-center justify-center text-gray-400">
                            <i class="fas fa-map-marker-alt text-3xl"></i>
                        </div>
                    </div>
                    
                    <?php 
                    $shopLat = $shopSettings['shop_lat'] ?? '';
                    $shopLng = $shopSettings['shop_lng'] ?? '';
                    if ($shopLat && $shopLng): 
                    ?>
                    <a href="https://www.google.com/maps?q=<?= $shopLat ?>,<?= $shopLng ?>" target="_blank" 
                       class="mt-3 flex items-center justify-center gap-2 py-3 bg-teal-500 text-white rounded-xl font-bold hover:bg-teal-600 transition-colors">
                        <i class="fas fa-directions"></i> นำทางไปร้าน
                    </a>
                    <?php else: ?>
                    <button onclick="openShopLocation()" class="mt-3 w-full flex items-center justify-center gap-2 py-3 bg-teal-500 text-white rounded-xl font-bold hover:bg-teal-600 transition-colors">
                        <i class="fas fa-map-marker-alt"></i> ดูตำแหน่งร้าน
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Pickup Contact -->
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อผู้มารับ *</label>
                        <input type="text" id="pickupName" class="form-input" placeholder="ชื่อ-นามสกุล">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทรติดต่อ *</label>
                        <input type="tel" id="pickupPhone" class="form-input" placeholder="0812345678" maxlength="10">
                    </div>
                </div>
            </div>
            
            <!-- Rider Info (for call_rider) -->
            <div id="riderInfo" class="bg-white rounded-2xl shadow-sm p-5 mb-4 hidden">
                <div class="bg-orange-50 rounded-xl p-4">
                    <h3 class="font-bold text-orange-700 mb-2 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>วิธีเรียก Rider
                    </h3>
                    <ol class="text-sm text-gray-600 space-y-2 list-decimal list-inside">
                        <li>ยืนยันคำสั่งซื้อและชำระเงิน</li>
                        <li>รอร้านเตรียมสินค้า (จะแจ้งเตือนเมื่อพร้อม)</li>
                        <li>เรียก Rider ผ่านแอป Grab/Lalamove/LINE MAN</li>
                        <li>ให้ Rider มารับสินค้าที่ร้าน</li>
                    </ol>
                    
                    <div class="mt-4 p-3 bg-white rounded-xl">
                        <p class="text-sm font-bold text-gray-700">📍 ที่อยู่ร้านสำหรับ Rider:</p>
                        <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($shopSettings['shop_address'] ?? 'กรุณาติดต่อร้านเพื่อสอบถามที่อยู่') ?></p>
                        <?php if ($shopLat && $shopLng): ?>
                        <button onclick="copyShopAddress()" class="mt-2 text-sm text-teal-600 hover:underline font-medium">
                            <i class="fas fa-copy mr-1"></i>คัดลอกที่อยู่
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Rider Contact -->
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อผู้รับสินค้า *</label>
                        <input type="text" id="riderReceiverName" class="form-input" placeholder="ชื่อ-นามสกุล">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทรติดต่อ *</label>
                        <input type="tel" id="riderReceiverPhone" class="form-input" placeholder="0812345678" maxlength="10">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่ปลายทาง (สำหรับ Rider) *</label>
                        <textarea id="riderDeliveryAddress" rows="2" class="form-input" placeholder="ที่อยู่ที่ต้องการให้ Rider ส่ง"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Shipping Address Form (for request_delivery) -->
            <div id="shippingForm" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="font-bold text-lg mb-4 flex items-center text-gray-800">
                    <i class="fas fa-map-marker-alt text-teal-500 mr-2"></i>ที่อยู่สำหรับฝากส่ง
                </h2>
                
                <div class="bg-blue-50 rounded-xl p-3 mb-4">
                    <p class="text-sm text-blue-700 flex items-start gap-2">
                        <i class="fas fa-info-circle mt-0.5 flex-shrink-0"></i>
                        <span>ข้าพเจ้าขอให้ร้านฝากส่งสินค้าตามที่อยู่ด้านล่าง</span>
                    </p>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ชื่อ-นามสกุล *</label>
                        <input type="text" id="shippingName" class="form-input" placeholder="ชื่อผู้รับสินค้า">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">เบอร์โทรศัพท์ *</label>
                        <input type="tel" id="shippingPhone" class="form-input" placeholder="0812345678" maxlength="10">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">ที่อยู่ *</label>
                        <textarea id="shippingAddress" rows="3" class="form-input" placeholder="บ้านเลขที่ ซอย ถนน"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">แขวง/ตำบล</label>
                            <input type="text" id="shippingSubdistrict" class="form-input">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">เขต/อำเภอ</label>
                            <input type="text" id="shippingDistrict" class="form-input">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">จังหวัด</label>
                            <input type="text" id="shippingProvince" class="form-input">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">รหัสไปรษณีย์</label>
                            <input type="text" id="shippingPostcode" class="form-input" maxlength="5">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Save Address Checkbox -->
            <div id="saveAddressSection" class="bg-white rounded-2xl shadow-sm p-4 mb-4">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" id="saveAddress" class="w-5 h-5 rounded accent-teal-500">
                    <span class="text-sm text-gray-700">บันทึกที่อยู่นี้สำหรับครั้งถัดไป</span>
                </label>
            </div>
        </div>

        <!-- Payment Section -->
        <div id="paymentSection" class="<?= $action !== 'payment' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="font-bold text-lg mb-4 flex items-center text-gray-800">
                    <i class="fas fa-credit-card text-teal-500 mr-2"></i>วิธีชำระเงิน
                </h2>
                
                <div class="space-y-3">
                    <label class="delivery-option selected flex items-center gap-3 cursor-pointer" data-method="transfer">
                        <input type="radio" name="paymentMethod" value="transfer" class="w-5 h-5 flex-shrink-0" checked>
                        <div class="flex-1">
                            <p class="font-bold text-gray-800">💳 โอนเงิน / พร้อมเพย์</p>
                            <p class="text-sm text-gray-500">โอนเงินแล้วส่งสลิป</p>
                        </div>
                    </label>
                    
                    <label class="delivery-option flex items-center gap-3 cursor-pointer" data-method="cod">
                        <input type="radio" name="paymentMethod" value="cod" class="w-5 h-5 flex-shrink-0">
                        <div class="flex-1">
                            <p class="font-bold text-gray-800">📦 เก็บเงินปลายทาง (COD)</p>
                            <p class="text-sm text-gray-500">ชำระเงินเมื่อรับสินค้า</p>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Bank Info (for transfer) -->
            <div id="bankInfo" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h3 class="font-bold mb-3 flex items-center text-gray-800">
                    <i class="fas fa-university text-blue-500 mr-2"></i>ช่องทางชำระเงิน
                </h3>
                
                <?php if ($promptpay): ?>
                <div class="p-4 bg-teal-50 rounded-xl mb-3">
                    <p class="font-medium text-teal-700">💚 พร้อมเพย์</p>
                    <p class="text-xl font-bold text-gray-800 mt-1"><?= htmlspecialchars($promptpay) ?></p>
                </div>
                <?php endif; ?>
                
                <?php foreach ($bankAccounts as $bank): ?>
                <div class="p-4 bg-gray-50 rounded-xl mb-2">
                    <p class="font-medium text-gray-700">🏦 <?= htmlspecialchars($bank['name']) ?></p>
                    <p class="text-xl font-bold text-gray-800 mt-1"><?= htmlspecialchars($bank['account']) ?></p>
                    <p class="text-sm text-gray-500 mt-1">ชื่อบัญชี: <?= htmlspecialchars($bank['holder']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Order Summary for Payment Section -->
            <div id="paymentOrderSummary" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h3 class="font-bold mb-3 flex items-center text-gray-800">
                    <i class="fas fa-shopping-cart text-teal-500 mr-2"></i>สรุปคำสั่งซื้อ
                </h3>
                <div id="paymentOrderItems"></div>
                <div class="border-t pt-3 mt-3">
                    <div class="flex justify-between text-lg font-bold">
                        <span>รวมทั้งหมด</span>
                        <span class="text-teal-600" id="paymentOrderTotal">฿0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Slip Upload Section -->
        <div id="slipSection" class="<?= $action !== 'slip' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="font-bold text-lg mb-4 flex items-center text-gray-800">
                    <i class="fas fa-receipt text-teal-500 mr-2"></i>อัพโหลดสลิป
                </h2>
                
                <div id="slipPreview" class="hidden mb-4">
                    <img id="slipImage" src="" class="w-full rounded-xl border-2 border-teal-200">
                </div>
                
                <div id="slipUploadArea" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-teal-500 hover:bg-teal-50 transition-all">
                    <div class="w-16 h-16 mx-auto mb-3 bg-teal-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-cloud-upload-alt text-3xl text-teal-500"></i>
                    </div>
                    <p class="text-gray-700 font-medium">แตะเพื่อเลือกรูปสลิป</p>
                    <p class="text-sm text-gray-400 mt-1">หรือถ่ายรูปใหม่</p>
                    <input type="file" id="slipFile" accept="image/*" class="hidden" capture="environment">
                </div>
                
                <div id="slipActions" class="hidden mt-4">
                    <button onclick="changeSlip()" class="w-full py-3 border-2 border-teal-500 text-teal-600 rounded-xl font-medium hover:bg-teal-50 transition-colors">
                        <i class="fas fa-redo mr-2"></i>เปลี่ยนรูป
                    </button>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div id="orderSummary" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h3 class="font-bold mb-3 flex items-center text-gray-800">
                    <i class="fas fa-shopping-cart text-teal-500 mr-2"></i>สรุปคำสั่งซื้อ
                </h3>
                <div id="orderItems"></div>
                <div class="border-t pt-3 mt-3">
                    <div class="flex justify-between text-lg font-bold">
                        <span>รวมทั้งหมด</span>
                        <span class="text-teal-600" id="orderTotal">฿0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Action Button (above bottom nav) -->
    <div class="fixed bottom-16 left-0 right-0 bg-white border-t p-4 z-40" style="padding-bottom: max(16px, env(safe-area-inset-bottom));">
        <button id="actionBtn" onclick="handleAction()" class="w-full py-4 bg-gradient-to-r from-teal-500 to-teal-600 text-white rounded-xl font-bold text-lg shadow-lg hover:shadow-xl transition-all active:scale-[0.98]">
            <span id="actionBtnText">ถัดไป</span>
        </button>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="liff-app.php?account=<?= $lineAccountId ?>" class="nav-item">
            <i class="fas fa-home"></i>
            <span>หน้าหลัก</span>
        </a>
        <a href="liff-shop.php?account=<?= $lineAccountId ?>" class="nav-item">
            <i class="fas fa-store"></i>
            <span>ร้านค้า</span>
        </a>
        <a href="liff-my-orders.php?account=<?= $lineAccountId ?>" class="nav-item">
            <i class="fas fa-box"></i>
            <span>คำสั่งซื้อ</span>
        </a>
        <a href="liff-member-card.php?account=<?= $lineAccountId ?>" class="nav-item">
            <i class="fas fa-user"></i>
            <span>บัญชี</span>
        </a>
    </div>

    <script>
    // liff is loaded from SDK - don't override it!
    let userProfile = null;
    let currentAction = '<?= $action ?>';
    let orderId = <?= $orderId ? "'{$orderId}'" : 'null' ?>;
    let userId = '<?= $userId ?>';
    let slipFile = null;
    let cartData = null;
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    const BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';

    // Initialize LIFF
    document.addEventListener('DOMContentLoaded', async function() {
        showLoading(true);
        
        // Debug orderId
        console.log('=== CHECKOUT DEBUG ===');
        console.log('orderId:', orderId, typeof orderId);
        console.log('userId:', userId);
        console.log('currentAction:', currentAction);
        
        let liffId = '<?= $liffId ?>';
        const urlUserId = '<?= $userId ?>';
        
        // Debug info
        console.log('=== LIFF DEBUG ===');
        console.log('LIFF ID from PHP:', liffId);
        console.log('URL User ID:', urlUserId);
        console.log('Referrer:', document.referrer);
        console.log('Current URL:', window.location.href);
        
        // Show debug on page temporarily
        if (!liffId) {
            console.log('LIFF ID is empty from PHP!');
        }
        
        // Try to get LIFF ID from referrer or URL
        if (!liffId) {
            // Check referrer (when redirected from liff.line.me)
            const refMatch = document.referrer.match(/liff\.line\.me\/([^/?]+)/);
            if (refMatch) {
                liffId = refMatch[1];
                console.log('LIFF ID from referrer:', liffId);
            }
            // Check current URL
            if (!liffId) {
                const urlMatch = window.location.href.match(/liff\.line\.me\/([^/?]+)/);
                if (urlMatch) {
                    liffId = urlMatch[1];
                    console.log('LIFF ID from URL:', liffId);
                }
            }
            // Check URL params (liff.state might contain it)
            if (!liffId) {
                const params = new URLSearchParams(window.location.search);
                const liffState = params.get('liff.state');
                if (liffState) {
                    console.log('LIFF state:', liffState);
                }
            }
        }
        
        // Priority 1: If user_id passed from URL (from BusinessBot), use it directly
        if (urlUserId && urlUserId.startsWith('U')) {
            userId = urlUserId;
            console.log('Using URL userId:', userId);
            console.log('orderId from URL:', orderId);
            
            // Load order data first (important for dispense orders)
            await loadOrderData();
            
            // Load saved address
            loadSavedAddress();
            
            showLoading(false);
            updateUI();
            
            // Don't return - let LIFF init continue for profile picture etc.
            // But skip the login redirect
            if (!liffId) {
                console.log('No LIFF ID, but have userId from URL - continuing without LIFF');
                return;
            }
        }
        
        // Priority 2: Try to init LIFF - LIFF SDK can work even without explicit liffId if opened via LIFF URL
        try {
            // If no liffId from DB, try to get from LIFF context
            if (!liffId && typeof liff !== 'undefined') {
                // Check if we're in LIFF browser by trying to init with a placeholder
                // LIFF SDK will use the correct ID from the context
                console.log('Attempting LIFF auto-detection...');
            }
            
            if (liffId) {
                await liff.init({ liffId: liffId });
            } else {
                // Cannot init without LIFF ID
                throw new Error('LIFF ID not configured');
            }
            
            console.log('LIFF initialized, isLoggedIn:', liff.isLoggedIn());
            console.log('LIFF isInClient:', liff.isInClient());
            console.log('LIFF ID after init:', liff.id);
            
            if (liff.isLoggedIn()) {
                userProfile = await liff.getProfile();
                if (userProfile.pictureUrl) {
                    document.getElementById('userPicture').src = userProfile.pictureUrl;
                    document.getElementById('userPicture').classList.remove('hidden');
                }
                userId = userProfile.userId;
                
                console.log('LIFF Profile:', userProfile);
                console.log('LINE User ID:', userId);
                
                // Pre-fill name from LINE profile
                if (userProfile.displayName && !document.getElementById('shippingName').value) {
                    document.getElementById('shippingName').value = userProfile.displayName;
                }
                
                loadSavedAddress();
                await loadOrderData();
                showLoading(false);
                updateUI();
                return;
            } else {
                // Not logged in - redirect to LINE Login (supports external browser)
                console.log('Not logged in, redirecting to LINE Login...');
                liff.login();
                return;
            }
        } catch (error) {
            console.error('LIFF init error:', error);
            // Show error - prompt to open in LINE App
            Swal.fire({
                icon: 'info',
                title: 'กรุณาเปิดผ่าน LINE App',
                html: `
                    <p class="mb-4">เพื่อประสบการณ์ที่ดีที่สุด กรุณาเปิดผ่านแอป LINE</p>
                    <div class="flex justify-center gap-4">
                        <a href="https://line.me/R/ti/p/@${encodeURIComponent('อร่อยซอยสาม')}" class="inline-block px-4 py-2 bg-teal-500 text-white rounded-lg">
                            <i class="fab fa-line mr-2"></i>เปิด LINE
                        </a>
                    </div>
                `,
                showConfirmButton: false,
                allowOutsideClick: false
            });
        }
        
        // Fallback: Show page anyway for testing (without user data)
        showLoading(false);
        updateUI();
    });

    function showLoading(show) {
        document.getElementById('loading').classList.toggle('active', show);
    }

    function updateUI() {
        const titles = {
            'address': 'วิธีรับสินค้า',
            'payment': 'ชำระเงิน',
            'slip': 'ส่งสลิป'
        };
        const btnTexts = {
            'address': 'ถัดไป →',
            'payment': 'ยืนยันสั่งซื้อ',
            'slip': 'ส่งสลิป'
        };
        
        document.getElementById('pageTitle').textContent = titles[currentAction] || 'Checkout';
        document.getElementById('actionBtnText').textContent = btnTexts[currentAction] || 'ถัดไป';
        
        // Show/hide sections
        document.getElementById('addressSection').classList.toggle('hidden', currentAction !== 'address');
        document.getElementById('paymentSection').classList.toggle('hidden', currentAction !== 'payment');
        document.getElementById('slipSection').classList.toggle('hidden', currentAction !== 'slip');
        
        // Show/hide bank info based on payment method
        const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked')?.value;
        document.getElementById('bankInfo')?.classList.toggle('hidden', paymentMethod === 'cod');
    }

    async function loadOrderData() {
        console.log('=== loadOrderData START ===');
        console.log('userId:', userId);
        console.log('orderId:', orderId);
        
        if (!userId && !orderId) {
            console.log('No userId or orderId, skipping load');
            return;
        }
        
        try {
            let url;
            // ถ้ามี orderId (จากหมอจ่ายยา) ให้ดึงจาก transaction แทน cart
            if (orderId) {
                url = `${BASE_URL}/api/checkout.php?action=order&order_id=${orderId}`;
                console.log('Loading from ORDER:', orderId);
            } else {
                url = `${BASE_URL}/api/checkout.php?action=cart&line_user_id=${userId}`;
                console.log('Loading from CART for user:', userId);
            }
            
            console.log('Fetch URL:', url);
            const response = await fetch(url);
            const text = await response.text();
            console.log('Raw API Response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                console.error('Response was:', text);
                return;
            }
            
            console.log('Parsed API Response:', data);
            
            if (data.success) {
                cartData = data;
                renderOrderSummary(data);
                console.log('Order summary rendered, items count:', data.items?.length);
                
                // ถ้าเป็น order จากหมอ (dispense) ให้ไปหน้า payment เลย (ข้าม address)
                if (orderId && data.is_dispense) {
                    console.log('Dispense order detected, switching to payment');
                    currentAction = 'payment';
                    updateUI();
                    
                    // Hide address section completely for dispense
                    document.getElementById('addressSection')?.classList.add('hidden');
                }
            } else {
                console.error('API Error:', data.message);
                // Show error to user
                Swal.fire({
                    icon: 'error',
                    title: 'ไม่พบข้อมูลออเดอร์',
                    text: data.message || 'กรุณาลองใหม่อีกครั้ง',
                    confirmButtonColor: '#11B0A6'
                });
            }
        } catch (error) {
            console.error('Load order error:', error);
        }
        console.log('=== loadOrderData END ===');
    }

    function renderOrderSummary(data) {
        console.log('=== renderOrderSummary ===');
        console.log('data:', data);
        console.log('items:', data.items);
        
        if (!data.items || data.items.length === 0) {
            console.log('No items to render');
            const emptyHtml = '<p class="text-gray-500 text-center py-4">ไม่มีสินค้า</p>';
            document.getElementById('orderItems').innerHTML = emptyHtml;
            document.getElementById('orderTotal').textContent = '฿0';
            // Also update payment section
            if (document.getElementById('paymentOrderItems')) {
                document.getElementById('paymentOrderItems').innerHTML = emptyHtml;
                document.getElementById('paymentOrderTotal').textContent = '฿0';
            }
            return;
        }
        
        const itemsHtml = data.items.map(item => {
            console.log('Rendering item:', item);
            return `
            <div class="flex justify-between py-2 border-b">
                <div class="flex-1">
                    <span class="font-medium">${item.name || 'ไม่ระบุชื่อ'}</span>
                    <span class="text-gray-500 text-sm"> x${item.quantity || 1}</span>
                </div>
                <span class="text-teal-600 font-medium">฿${Number(item.subtotal || 0).toLocaleString()}</span>
            </div>
        `}).join('');
        
        // Show subtotal and shipping if available
        let summaryHtml = '';
        if (data.subtotal !== undefined && data.shipping_fee !== undefined) {
            summaryHtml = `
                <div class="flex justify-between py-1 text-sm text-gray-600">
                    <span>ยอดสินค้า</span>
                    <span>฿${Number(data.subtotal).toLocaleString()}</span>
                </div>
                <div class="flex justify-between py-1 text-sm text-gray-600">
                    <span>ค่าจัดส่ง</span>
                    <span>${data.shipping_fee > 0 ? '฿' + Number(data.shipping_fee).toLocaleString() : 'ฟรี'}</span>
                </div>
            `;
        }
        
        // Order number if available
        if (data.order_number) {
            summaryHtml = `
                <div class="flex justify-between py-1 text-sm text-gray-500 mb-2">
                    <span>เลขที่ออเดอร์</span>
                    <span>#${data.order_number}</span>
                </div>
            ` + summaryHtml;
        }
        
        const fullItemsHtml = itemsHtml + (summaryHtml ? `<div class="mt-2">${summaryHtml}</div>` : '');
        const totalText = '฿' + Number(data.total || 0).toLocaleString();
        
        // Update slip section order summary
        document.getElementById('orderItems').innerHTML = fullItemsHtml;
        document.getElementById('orderTotal').textContent = totalText;
        
        // Also update payment section order summary
        if (document.getElementById('paymentOrderItems')) {
            document.getElementById('paymentOrderItems').innerHTML = fullItemsHtml;
            document.getElementById('paymentOrderTotal').textContent = totalText;
        }
        
        console.log('Order summary rendered successfully');
    }

    function loadSavedAddress() {
        const saved = localStorage.getItem('savedAddress');
        if (saved) {
            const addr = JSON.parse(saved);
            document.getElementById('shippingName').value = addr.name || '';
            document.getElementById('shippingPhone').value = addr.phone || '';
            document.getElementById('shippingAddress').value = addr.address || '';
            document.getElementById('shippingSubdistrict').value = addr.subdistrict || '';
            document.getElementById('shippingDistrict').value = addr.district || '';
            document.getElementById('shippingProvince').value = addr.province || '';
            document.getElementById('shippingPostcode').value = addr.postcode || '';
            document.getElementById('saveAddress').checked = true;
        }
    }

    async function handleAction() {
        showLoading(true);
        
        try {
            if (currentAction === 'address') {
                if (!validateAddress()) {
                    showLoading(false);
                    return;
                }
                
                // Save address if checked
                if (document.getElementById('saveAddress').checked) {
                    localStorage.setItem('savedAddress', JSON.stringify(getAddressData()));
                }
                
                currentAction = 'payment';
                updateUI();
                
            } else if (currentAction === 'payment') {
                const paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
                const addressData = getAddressData();
                
                // Check if userId is available
                if (!userId) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'ไม่พบข้อมูลผู้ใช้', 
                        text: 'กรุณาเปิดผ่าน LINE App',
                        confirmButtonColor: '#11B0A6'
                    });
                    showLoading(false);
                    return;
                }
                
                // ถ้าเป็น dispense order (มี orderId อยู่แล้ว) ไม่ต้องสร้าง order ใหม่
                if (orderId && cartData?.is_dispense) {
                    console.log('Dispense order - skipping order creation, orderId:', orderId);
                    
                    if (paymentMethod === 'transfer') {
                        currentAction = 'slip';
                        updateUI();
                    } else {
                        // COD/Cash - update payment method and show success
                        try {
                            const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'update_payment_method',
                                    order_id: orderId,
                                    payment_method: paymentMethod
                                })
                            });
                            const result = await response.json();
                            console.log('Update payment method result:', result);
                        } catch (e) {
                            console.log('Update payment method error (non-critical):', e);
                        }
                        showSuccess('ยืนยันการจ่ายยาสำเร็จ!', 'ขอบคุณที่ใช้บริการ');
                    }
                    showLoading(false);
                    return;
                }
                
                const requestData = {
                    action: 'create_order',
                    line_user_id: userId,
                    line_account_id: ACCOUNT_ID,
                    display_name: userProfile?.displayName || addressData.name,
                    address: addressData,
                    payment_method: paymentMethod
                };
                console.log('Create order request:', requestData);
                
                // Create order via API
                const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestData)
                });
                
                const result = await response.json();
                console.log('Create order response:', result);
                
                if (result.success) {
                    orderId = result.order_id;
                    
                    if (paymentMethod === 'transfer') {
                        currentAction = 'slip';
                        updateUI();
                    } else {
                        // COD - show success based on delivery method
                        const deliveryMsg = selectedDeliveryMethod === 'pickup' 
                            ? 'กรุณามารับสินค้าที่ร้าน' 
                            : selectedDeliveryMethod === 'call_rider'
                            ? 'รอร้านเตรียมสินค้า แล้วเรียก Rider มารับ'
                            : 'รอการฝากส่ง 1-3 วันทำการ';
                        showSuccess('สั่งซื้อสำเร็จ!', deliveryMsg);
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: result.message || 'กรุณาลองใหม่', confirmButtonColor: '#11B0A6' });
                }
                
            } else if (currentAction === 'slip') {
                if (!slipFile) {
                    Swal.fire({ icon: 'warning', title: 'กรุณาเลือกรูปสลิป', confirmButtonColor: '#11B0A6' });
                    showLoading(false);
                    return;
                }
                
                // Debug: Check orderId before upload
                console.log('=== SLIP UPLOAD DEBUG ===');
                console.log('orderId:', orderId, typeof orderId);
                console.log('userId:', userId);
                console.log('slipFile:', slipFile?.name, slipFile?.size);
                
                if (!orderId) {
                    Swal.fire({ icon: 'error', title: 'ไม่พบ Order ID', text: 'กรุณาสร้างคำสั่งซื้อก่อน', confirmButtonColor: '#11B0A6' });
                    showLoading(false);
                    return;
                }
                
                // Upload slip
                const formData = new FormData();
                formData.append('action', 'upload_slip');
                formData.append('order_id', orderId);
                formData.append('user_id', userId);
                formData.append('slip', slipFile);
                
                console.log('Uploading to:', `${BASE_URL}/api/checkout.php`);
                
                const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                console.log('Upload result:', result);
                
                if (result.success) {
                    showSuccess('ส่งสลิปสำเร็จ!', 'รอตรวจสอบการชำระเงิน');
                } else {
                    console.error('Upload failed:', result);
                    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: result.message || 'กรุณาลองใหม่', confirmButtonColor: '#11B0A6' });
                }
            }
        } catch (error) {
            console.error('Action error:', error);
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: 'กรุณาลองใหม่', confirmButtonColor: '#11B0A6' });
        }
        
        showLoading(false);
    }

    function showSuccess(title, message) {
        // Clear local cart data
        localStorage.removeItem('liff_cart_' + userId);
        localStorage.removeItem('cart_' + userId);
        
        // Send message to LINE chat
        if (typeof liff !== 'undefined' && liff.isInClient()) {
            liff.sendMessages([{
                type: 'text',
                text: `✅ ${title}\n${message}`
            }]).then(() => {
                liff.closeWindow();
            }).catch(err => {
                console.error('Send message error:', err);
                liff.closeWindow();
            });
        } else {
            Swal.fire({
                icon: 'success',
                title: title,
                text: message,
                confirmButtonColor: '#11B0A6'
            }).then(() => {
                window.close();
            });
        }
    }

    function goBack() {
        if (currentAction === 'payment') {
            currentAction = 'address';
            updateUI();
        } else if (currentAction === 'slip') {
            currentAction = 'payment';
            updateUI();
        } else {
            if (liff.isInClient()) {
                liff.closeWindow();
            } else {
                window.history.back();
            }
        }
    }

    // Slip upload handling
    document.getElementById('slipUploadArea').addEventListener('click', function() {
        document.getElementById('slipFile').click();
    });

    document.getElementById('slipFile').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            slipFile = file;
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('slipImage').src = e.target.result;
                document.getElementById('slipPreview').classList.remove('hidden');
                document.getElementById('slipUploadArea').classList.add('hidden');
                document.getElementById('slipActions').classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    function changeSlip() {
        slipFile = null;
        document.getElementById('slipFile').value = '';
        document.getElementById('slipPreview').classList.add('hidden');
        document.getElementById('slipUploadArea').classList.remove('hidden');
        document.getElementById('slipActions').classList.add('hidden');
    }

    // Payment method change
    document.querySelectorAll('input[name="paymentMethod"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('bankInfo')?.classList.toggle('hidden', this.value === 'cod');
        });
    });
    
    // ===== Delivery Method Handling =====
    let selectedDeliveryMethod = 'request_delivery'; // default
    const shopAddress = '<?= addslashes($shopSettings['shop_address'] ?? '') ?>';
    const shopLat = '<?= $shopSettings['shop_lat'] ?? '' ?>';
    const shopLng = '<?= $shopSettings['shop_lng'] ?? '' ?>';
    
    // Initialize delivery method UI
    document.addEventListener('DOMContentLoaded', function() {
        // Set up delivery method listeners
        document.querySelectorAll('input[name="deliveryMethod"]').forEach(radio => {
            radio.addEventListener('change', handleDeliveryMethodChange);
        });
        
        // Initialize with default selection
        handleDeliveryMethodChange();
        
        // Style selected option
        updateDeliveryOptionStyles();
    });
    
    function handleDeliveryMethodChange() {
        const selected = document.querySelector('input[name="deliveryMethod"]:checked');
        if (!selected) return;
        
        selectedDeliveryMethod = selected.value;
        
        // Show/hide relevant sections
        const pickupInfo = document.getElementById('pickupInfo');
        const riderInfo = document.getElementById('riderInfo');
        const shippingForm = document.getElementById('shippingForm');
        const saveAddressSection = document.getElementById('saveAddressSection');
        
        // Hide all first
        pickupInfo?.classList.add('hidden');
        riderInfo?.classList.add('hidden');
        shippingForm?.classList.add('hidden');
        saveAddressSection?.classList.add('hidden');
        
        // Show relevant section
        switch (selectedDeliveryMethod) {
            case 'pickup':
                pickupInfo?.classList.remove('hidden');
                initShopMap();
                break;
            case 'request_delivery':
                shippingForm?.classList.remove('hidden');
                saveAddressSection?.classList.remove('hidden');
                break;
            case 'call_rider':
                riderInfo?.classList.remove('hidden');
                break;
        }
        
        updateDeliveryOptionStyles();
    }
    
    function updateDeliveryOptionStyles() {
        document.querySelectorAll('.delivery-option').forEach(option => {
            const radio = option.querySelector('input[type="radio"]');
            if (radio.checked) {
                option.classList.add('border-teal-500', 'bg-teal-50');
                option.classList.remove('border-gray-200');
            } else {
                option.classList.remove('border-teal-500', 'bg-teal-50');
                option.classList.add('border-gray-200');
            }
        });
    }
    
    // Initialize Google Map for shop location
    let shopMap = null;
    function initShopMap() {
        if (!shopLat || !shopLng) {
            // No coordinates - show placeholder
            document.getElementById('shopMap').innerHTML = `
                <div class="h-full flex flex-col items-center justify-center text-gray-500 p-4">
                    <i class="fas fa-map-marker-alt text-3xl mb-2 text-teal-500"></i>
                    <p class="text-sm text-center">กรุณาติดต่อร้านเพื่อสอบถามที่อยู่</p>
                </div>
            `;
            return;
        }
        
        // Show static map image (no API key needed)
        const mapUrl = `https://maps.googleapis.com/maps/api/staticmap?center=${shopLat},${shopLng}&zoom=16&size=400x200&markers=color:green%7C${shopLat},${shopLng}&key=`;
        
        // Use OpenStreetMap embed instead (free, no API key)
        document.getElementById('shopMap').innerHTML = `
            <iframe 
                width="100%" 
                height="200" 
                frameborder="0" 
                scrolling="no" 
                marginheight="0" 
                marginwidth="0" 
                src="https://www.openstreetmap.org/export/embed.html?bbox=${parseFloat(shopLng)-0.005}%2C${parseFloat(shopLat)-0.003}%2C${parseFloat(shopLng)+0.005}%2C${parseFloat(shopLat)+0.003}&layer=mapnik&marker=${shopLat}%2C${shopLng}"
                style="border-radius: 12px;">
            </iframe>
        `;
    }
    
    function openShopLocation() {
        if (shopLat && shopLng) {
            window.open(`https://www.google.com/maps?q=${shopLat},${shopLng}`, '_blank');
        } else if (shopAddress) {
            window.open(`https://www.google.com/maps/search/${encodeURIComponent(shopAddress)}`, '_blank');
        } else {
            Swal.fire({
                icon: 'info',
                title: 'ที่อยู่ร้าน',
                text: 'กรุณาติดต่อร้านเพื่อสอบถามที่อยู่',
                confirmButtonColor: '#11B0A6'
            });
        }
    }
    
    function copyShopAddress() {
        const address = shopAddress || 'ไม่พบที่อยู่';
        navigator.clipboard.writeText(address).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'คัดลอกแล้ว!',
                text: 'คัดลอกที่อยู่ร้านเรียบร้อย',
                timer: 1500,
                showConfirmButton: false
            });
        }).catch(() => {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = address;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            Swal.fire({
                icon: 'success',
                title: 'คัดลอกแล้ว!',
                timer: 1500,
                showConfirmButton: false
            });
        });
    }
    
    // Override validateAddress to handle different delivery methods
    function validateAddress() {
        if (selectedDeliveryMethod === 'pickup') {
            const name = document.getElementById('pickupName')?.value.trim();
            const phone = document.getElementById('pickupPhone')?.value.trim();
            
            if (!name) {
                Swal.fire({ icon: 'warning', title: 'กรุณากรอกชื่อผู้มารับ', confirmButtonColor: '#11B0A6' });
                return false;
            }
            if (!phone || phone.length < 9) {
                Swal.fire({ icon: 'warning', title: 'กรุณากรอกเบอร์โทรติดต่อ', confirmButtonColor: '#11B0A6' });
                return false;
            }
            return true;
        }
        
        if (selectedDeliveryMethod === 'call_rider') {
            const name = document.getElementById('riderReceiverName')?.value.trim();
            const phone = document.getElementById('riderReceiverPhone')?.value.trim();
            const address = document.getElementById('riderDeliveryAddress')?.value.trim();
            
            if (!name) {
                Swal.fire({ icon: 'warning', title: 'กรุณากรอกชื่อผู้รับสินค้า', confirmButtonColor: '#11B0A6' });
                return false;
            }
            if (!phone || phone.length < 9) {
                Swal.fire({ icon: 'warning', title: 'กรุณากรอกเบอร์โทรติดต่อ', confirmButtonColor: '#11B0A6' });
                return false;
            }
            if (!address) {
                Swal.fire({ icon: 'warning', title: 'กรุณากรอกที่อยู่ปลายทาง', confirmButtonColor: '#11B0A6' });
                return false;
            }
            return true;
        }
        
        // request_delivery - validate shipping form
        const addr = getAddressData();
        if (!addr.name) { 
            Swal.fire({ icon: 'warning', title: 'กรุณากรอกชื่อ-นามสกุล', confirmButtonColor: '#11B0A6' }); 
            return false; 
        }
        if (!addr.phone || addr.phone.length < 9) { 
            Swal.fire({ icon: 'warning', title: 'กรุณากรอกเบอร์โทรศัพท์', confirmButtonColor: '#11B0A6' }); 
            return false; 
        }
        if (!addr.address) { 
            Swal.fire({ icon: 'warning', title: 'กรุณากรอกที่อยู่', confirmButtonColor: '#11B0A6' }); 
            return false; 
        }
        return true;
    }
    
    // Override getAddressData to include delivery method
    function getAddressData() {
        const deliveryMethod = selectedDeliveryMethod;
        
        if (deliveryMethod === 'pickup') {
            return {
                delivery_method: 'pickup',
                name: document.getElementById('pickupName')?.value.trim() || '',
                phone: document.getElementById('pickupPhone')?.value.trim() || '',
                address: 'รับที่ร้าน',
                subdistrict: '',
                district: '',
                province: '',
                postcode: ''
            };
        }
        
        if (deliveryMethod === 'call_rider') {
            return {
                delivery_method: 'call_rider',
                name: document.getElementById('riderReceiverName')?.value.trim() || '',
                phone: document.getElementById('riderReceiverPhone')?.value.trim() || '',
                address: document.getElementById('riderDeliveryAddress')?.value.trim() || '',
                subdistrict: '',
                district: '',
                province: '',
                postcode: '',
                note: 'ผู้ป่วยจะเรียก Rider มารับสินค้าที่ร้านเอง'
            };
        }
        
        // request_delivery
        return {
            delivery_method: 'request_delivery',
            name: document.getElementById('shippingName')?.value.trim() || '',
            phone: document.getElementById('shippingPhone')?.value.trim() || '',
            address: document.getElementById('shippingAddress')?.value.trim() || '',
            subdistrict: document.getElementById('shippingSubdistrict')?.value.trim() || '',
            district: document.getElementById('shippingDistrict')?.value.trim() || '',
            province: document.getElementById('shippingProvince')?.value.trim() || '',
            postcode: document.getElementById('shippingPostcode')?.value.trim() || '',
            note: 'ผู้ป่วยขอให้ร้านฝากส่งสินค้า'
        };
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
