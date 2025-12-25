<?php
/**
 * LIFF Order Detail - รายละเอียดออเดอร์
 * Updated: Full details + Reorder buttons
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/liff-helper.php';

$db = Database::getInstance()->getConnection();
$orderId = $_GET['order'] ?? '';
$lineAccountId = $_GET['account'] ?? 1;

$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
$lineAccountId = $liffData['line_account_id'];

$shopSettings = getShopSettings($db, $lineAccountId);
$companyName = $shopSettings['shop_name'] ?? 'ร้านค้า';
$baseUrl = rtrim(BASE_URL, '/');

// Get order data
$order = null;
$orderItems = [];
$paymentSlip = null;

if ($orderId) {
    try {
        $stmt = $db->prepare("SELECT * FROM transactions WHERE order_number = ? OR id = ?");
        $stmt->execute([$orderId, $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            // Get items
            $stmt = $db->prepare("
                SELECT ti.*, COALESCE(p.name, ti.product_name) as name, 
                       p.image_url as image, p.id as product_id, p.is_active
                FROM transaction_items ti
                LEFT JOIN business_items p ON ti.product_id = p.id
                WHERE ti.transaction_id = ?
            ");
            $stmt->execute([$order['id']]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get payment slip
            $stmt = $db->prepare("SELECT * FROM payment_slips WHERE order_id = ? OR transaction_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$order['id'], $order['id']]);
            $paymentSlip = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Order detail error: " . $e->getMessage());
    }
}

$deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
$deliveryType = $deliveryInfo['type'] ?? 'shipping';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>รายละเอียดออเดอร์ - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #11B0A6; }
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-paid { background: #DBEAFE; color: #2563EB; }
        .status-confirmed { background: #DBEAFE; color: #2563EB; }
        .status-processing { background: #E0E7FF; color: #4F46E5; }
        .status-shipping { background: #E0E7FF; color: #4F46E5; }
        .status-shipped { background: #E0E7FF; color: #4F46E5; }
        .status-delivered { background: #D1FAE5; color: #059669; }
        .status-completed { background: #D1FAE5; color: #059669; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        .btn-primary { background: linear-gradient(135deg, #11B0A6, #0D9488); }
        .timeline-dot { width: 12px; height: 12px; border-radius: 50%; }
        .timeline-line { width: 2px; height: 24px; }
    </style>
</head>
<body class="min-h-screen pb-24">
    <!-- Header -->
    <div class="bg-gradient-to-r from-teal-500 to-teal-600 text-white sticky top-0 z-20">
        <div class="flex items-center justify-between p-4">
            <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-white/20">
                <i class="fas fa-arrow-left text-xl"></i>
            </button>
            <h1 class="font-bold text-lg">รายละเอียดออเดอร์</h1>
            <div class="w-10"></div>
        </div>
    </div>

    <?php if ($order): ?>
    <div class="p-4 space-y-4">
        <!-- Order Status Card -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="p-4">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <p class="text-xs text-gray-400 mb-1"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                        <p class="font-bold text-xl text-gray-800">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></p>
                    </div>
                    <?php
                    $statusClass = 'status-' . ($order['status'] ?? 'pending');
                    $statusLabels = [
                        'pending' => 'รอชำระเงิน', 'paid' => 'ชำระแล้ว', 'confirmed' => 'ยืนยันแล้ว',
                        'processing' => 'กำลังเตรียม', 'shipping' => 'กำลังจัดส่ง', 'shipped' => 'จัดส่งแล้ว',
                        'delivered' => 'ได้รับแล้ว', 'completed' => 'สำเร็จ', 'cancelled' => 'ยกเลิก'
                    ];
                    $statusText = $statusLabels[$order['status']] ?? $order['status'];
                    ?>
                    <span class="px-4 py-1.5 rounded-full text-sm font-bold <?= $statusClass ?>"><?= $statusText ?></span>
                </div>
                
                <!-- Order Timeline -->
                <div class="flex items-center justify-between text-xs text-gray-400 mt-4">
                    <?php
                    $steps = ['pending' => 'สั่งซื้อ', 'paid' => 'ชำระเงิน', 'processing' => 'เตรียมสินค้า', 'shipping' => 'จัดส่ง', 'delivered' => 'ได้รับ'];
                    $currentStep = array_search($order['status'], array_keys($steps));
                    if ($currentStep === false) $currentStep = 0;
                    $i = 0;
                    foreach ($steps as $key => $label):
                        $isActive = $i <= $currentStep;
                        $dotColor = $isActive ? 'bg-teal-500' : 'bg-gray-200';
                    ?>
                    <div class="flex flex-col items-center">
                        <div class="timeline-dot <?= $dotColor ?>"></div>
                        <span class="mt-1 <?= $isActive ? 'text-teal-600 font-medium' : '' ?>"><?= $label ?></span>
                    </div>
                    <?php if ($i < count($steps) - 1): ?>
                    <div class="flex-1 h-0.5 <?= $i < $currentStep ? 'bg-teal-500' : 'bg-gray-200' ?> mx-1"></div>
                    <?php endif; $i++; endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Order Items -->
        <div class="bg-white rounded-2xl shadow-sm p-4">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-box text-teal-500 mr-2"></i>รายการสินค้า (<?= count($orderItems) ?> รายการ)
            </h3>
            <div class="space-y-3">
                <?php foreach ($orderItems as $item): ?>
                <div class="flex gap-3 pb-3 border-b border-gray-100 last:border-0 last:pb-0">
                    <div class="w-20 h-20 bg-gray-100 rounded-xl overflow-hidden flex-shrink-0">
                        <?php if (!empty($item['image'])): ?>
                        <img src="<?= htmlspecialchars($item['image']) ?>" class="w-full h-full object-cover" 
                             onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center\'><i class=\'fas fa-image text-gray-300 text-2xl\'></i></div>'">
                        <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-gray-800 line-clamp-2"><?= htmlspecialchars($item['name'] ?? $item['product_name']) ?></p>
                        <p class="text-sm text-gray-500 mt-1">฿<?= number_format($item['product_price'], 2) ?> x <?= $item['quantity'] ?></p>
                        <div class="flex items-center justify-between mt-2">
                            <p class="font-bold text-teal-600">฿<?= number_format($item['subtotal'], 2) ?></p>
                            <?php if ($item['product_id'] && $item['is_active']): ?>
                            <button onclick="reorderItem(<?= $item['product_id'] ?>, <?= $item['quantity'] ?>, '<?= htmlspecialchars(addslashes($item['name'])) ?>')" 
                                    class="text-xs px-3 py-1 border border-teal-500 text-teal-600 rounded-full hover:bg-teal-50">
                                <i class="fas fa-redo mr-1"></i>ซื้อซ้ำ
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Delivery Info -->
        <div class="bg-white rounded-2xl shadow-sm p-4">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center">
                <?php
                $deliveryIcon = $deliveryType === 'pickup' ? 'fa-store' : ($deliveryType === 'call_rider' ? 'fa-motorcycle' : 'fa-truck');
                $deliveryTitle = $deliveryType === 'pickup' ? 'รับที่ร้าน' : ($deliveryType === 'call_rider' ? 'เรียก Rider มารับ' : 'ข้อมูลจัดส่ง');
                ?>
                <i class="fas <?= $deliveryIcon ?> text-teal-500 mr-2"></i><?= $deliveryTitle ?>
            </h3>
            <div class="bg-gray-50 rounded-xl p-3 text-sm space-y-2">
                <?php if (!empty($deliveryInfo['name'])): ?>
                <p><span class="text-gray-500">ชื่อ:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['name']) ?></span></p>
                <?php endif; ?>
                <?php if (!empty($deliveryInfo['phone'])): ?>
                <p><span class="text-gray-500">เบอร์โทร:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['phone']) ?></span></p>
                <?php endif; ?>
                <?php if (!empty($deliveryInfo['address'])): ?>
                <p><span class="text-gray-500">ที่อยู่:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['address']) ?></span></p>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($order['shipping_tracking'])): ?>
            <div class="mt-3 p-3 bg-blue-50 rounded-xl flex items-center justify-between">
                <div>
                    <p class="text-xs text-blue-600">เลขพัสดุ</p>
                    <p class="font-bold text-blue-800"><?= htmlspecialchars($order['shipping_tracking']) ?></p>
                </div>
                <button onclick="copyTracking('<?= htmlspecialchars($order['shipping_tracking']) ?>')" class="px-3 py-1 bg-blue-500 text-white rounded-lg text-sm">
                    <i class="fas fa-copy mr-1"></i>คัดลอก
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment Summary -->
        <div class="bg-white rounded-2xl shadow-sm p-4">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-receipt text-teal-500 mr-2"></i>สรุปการชำระเงิน
            </h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">ยอดสินค้า</span>
                    <span>฿<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">ค่าจัดส่ง</span>
                    <span><?= $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 2) : '<span class="text-green-600">ฟรี</span>' ?></span>
                </div>
                <?php if (($order['discount_amount'] ?? 0) > 0): ?>
                <div class="flex justify-between text-green-600">
                    <span>ส่วนลด</span>
                    <span>-฿<?= number_format($order['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between text-lg font-bold pt-3 border-t border-gray-200 mt-2">
                    <span>รวมทั้งหมด</span>
                    <span class="text-teal-600">฿<?= number_format($order['grand_total'], 2) ?></span>
                </div>
            </div>
            
            <div class="mt-4 pt-3 border-t border-gray-100">
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-credit-card mr-2 text-gray-400"></i>
                    <?= $order['payment_method'] === 'transfer' ? 'โอนเงิน / พร้อมเพย์' : ($order['payment_method'] === 'cod' ? 'เก็บเงินปลายทาง (COD)' : ($order['payment_method'] ?? '-')) ?>
                </div>
            </div>
        </div>

        <!-- Payment Slip -->
        <?php if ($paymentSlip): ?>
        <div class="bg-white rounded-2xl shadow-sm p-4">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-file-image text-teal-500 mr-2"></i>หลักฐานการชำระเงิน
            </h3>
            <div class="rounded-xl overflow-hidden border border-gray-200">
                <img src="<?= htmlspecialchars($paymentSlip['image_url']) ?>" class="w-full" onclick="viewSlip('<?= htmlspecialchars($paymentSlip['image_url']) ?>')">
            </div>
            <p class="text-xs text-gray-400 mt-2 text-center">
                อัพโหลดเมื่อ <?= date('d/m/Y H:i', strtotime($paymentSlip['created_at'])) ?>
                <?php if ($paymentSlip['status'] === 'approved'): ?>
                <span class="text-green-600 ml-2"><i class="fas fa-check-circle"></i> อนุมัติแล้ว</span>
                <?php elseif ($paymentSlip['status'] === 'rejected'): ?>
                <span class="text-red-600 ml-2"><i class="fas fa-times-circle"></i> ไม่อนุมัติ</span>
                <?php else: ?>
                <span class="text-yellow-600 ml-2"><i class="fas fa-clock"></i> รอตรวจสอบ</span>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if ($order['status'] === 'pending' && $order['payment_method'] === 'transfer'): ?>
        <div class="bg-white rounded-2xl shadow-sm p-4">
            <a href="liff-checkout.php?order=<?= $order['id'] ?>&action=slip&user=<?= urlencode($_GET['user'] ?? '') ?>&account=<?= $lineAccountId ?>" 
               class="block w-full py-3.5 btn-primary text-white text-center rounded-xl font-bold shadow-lg">
                <i class="fas fa-upload mr-2"></i>แจ้งชำระเงิน / อัพโหลดสลิป
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bottom Reorder All Button -->
    <div class="fixed bottom-0 left-0 right-0 bg-white border-t p-4 z-30" style="padding-bottom: max(16px, env(safe-area-inset-bottom));">
        <button onclick="reorderAll()" class="w-full py-3.5 btn-primary text-white rounded-xl font-bold shadow-lg">
            <i class="fas fa-redo mr-2"></i>สั่งซื้อทั้งหมดอีกครั้ง
        </button>
    </div>

    <?php else: ?>
    <div class="p-4">
        <div class="bg-white rounded-2xl p-8 text-center shadow-sm">
            <div class="w-20 h-20 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                <i class="fas fa-search text-3xl text-gray-300"></i>
            </div>
            <p class="text-gray-500 mb-4">ไม่พบออเดอร์นี้</p>
            <button onclick="goBack()" class="px-6 py-2 bg-gray-500 text-white rounded-xl font-medium">กลับ</button>
        </div>
    </div>
    <?php endif; ?>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    const LIFF_ID = '<?= $liffId ?>';
    const ORDER_ITEMS = <?= json_encode($orderItems) ?>;
    
    let userId = null;
    
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            await liff.init({ liffId: LIFF_ID });
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                userId = profile.userId;
            }
        } catch (e) {
            console.error('LIFF error:', e);
        }
    });
    
    async function reorderItem(productId, quantity, name) {
        if (!userId) {
            Swal.fire({ icon: 'warning', title: 'กรุณาเข้าสู่ระบบ', confirmButtonColor: '#11B0A6' });
            return;
        }
        
        Swal.fire({ title: 'กำลังเพิ่มลงตะกร้า...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        try {
            const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_to_cart',
                    line_user_id: userId,
                    product_id: productId,
                    quantity: quantity
                })
            });
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'เพิ่มลงตะกร้าแล้ว!',
                    text: name,
                    showCancelButton: true,
                    confirmButtonText: 'ไปที่ตะกร้า',
                    cancelButtonText: 'ช้อปต่อ',
                    confirmButtonColor: '#11B0A6'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `${BASE_URL}/liff-checkout.php?user=${userId}&account=${ACCOUNT_ID}`;
                    }
                });
            } else {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message, confirmButtonColor: '#11B0A6' });
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', confirmButtonColor: '#11B0A6' });
        }
    }
    
    async function reorderAll() {
        if (!userId) {
            Swal.fire({ icon: 'warning', title: 'กรุณาเข้าสู่ระบบ', confirmButtonColor: '#11B0A6' });
            return;
        }
        
        if (ORDER_ITEMS.length === 0) {
            Swal.fire({ icon: 'error', title: 'ไม่พบรายการสินค้า', confirmButtonColor: '#11B0A6' });
            return;
        }
        
        const result = await Swal.fire({
            title: 'สั่งซื้อซ้ำทั้งหมด?',
            html: `เพิ่มสินค้า ${ORDER_ITEMS.length} รายการลงตะกร้า`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ใช่, เพิ่มลงตะกร้า',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#11B0A6'
        });
        
        if (!result.isConfirmed) return;
        
        Swal.fire({ title: 'กำลังเพิ่มสินค้า...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        let addedCount = 0;
        for (const item of ORDER_ITEMS) {
            if (!item.product_id) continue;
            try {
                const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_to_cart',
                        line_user_id: userId,
                        product_id: item.product_id,
                        quantity: item.quantity
                    })
                });
                const data = await response.json();
                if (data.success) addedCount++;
            } catch (e) {
                console.error('Add error:', e);
            }
        }
        
        Swal.fire({
            icon: 'success',
            title: 'เพิ่มลงตะกร้าแล้ว!',
            text: `${addedCount} รายการ`,
            showCancelButton: true,
            confirmButtonText: 'ไปที่ตะกร้า',
            cancelButtonText: 'ช้อปต่อ',
            confirmButtonColor: '#11B0A6'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `${BASE_URL}/liff-checkout.php?user=${userId}&account=${ACCOUNT_ID}`;
            }
        });
    }
    
    function copyTracking(tracking) {
        navigator.clipboard.writeText(tracking).then(() => {
            Swal.fire({ icon: 'success', title: 'คัดลอกแล้ว!', timer: 1500, showConfirmButton: false });
        });
    }
    
    function viewSlip(url) {
        Swal.fire({ imageUrl: url, imageAlt: 'Payment Slip', showConfirmButton: false, showCloseButton: true });
    }
    
    function goBack() {
        window.location.href = `${BASE_URL}/liff-my-orders.php?account=${ACCOUNT_ID}`;
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
