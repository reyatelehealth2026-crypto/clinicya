<?php
/**
 * POS (Point of Sale) - หน้าขายหน้าร้าน
 * 
 * Flow:
 * 1. ถ้ายังไม่เปิดกะ -> แสดงหน้าเปิดกะ
 * 2. เปิดกะแล้ว -> แสดงหน้าขาย
 * 3. tab=reports -> แสดงรายงาน
 */

require_once 'includes/auth_check.php';
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/POSShiftService.php';

$pageTitle = 'POS - ขายหน้าร้าน';

// Get current user
$userId = $_SESSION['admin_user']['id'] ?? $_SESSION['admin_id'] ?? null;
$userName = $_SESSION['admin_user']['name'] ?? $_SESSION['admin_name'] ?? 'พนักงาน';

// Check for open shift
$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 1;
$currentBotId = $lineAccountId; // For reports
$shiftService = new POSShiftService($db, $lineAccountId);
$currentShift = $userId ? $shiftService->getCurrentShift($userId) : null;

// Get current tab
$currentTab = $_GET['tab'] ?? 'pos';

// Get today's summary if shift exists
$todaySummary = null;
if ($currentShift) {
    try {
        $todaySummary = $shiftService->getShiftSummary($currentShift['id']);
    } catch (Exception $e) {
        $todaySummary = ['total_sales' => 0, 'total_transactions' => 0];
    }
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/pos.css">

<!-- Tab Navigation -->
<div class="bg-white border-b mb-4">
    <div class="container mx-auto px-4">
        <div class="flex gap-4">
            <a href="?tab=pos" class="px-4 py-3 border-b-2 <?= $currentTab === 'pos' ? 'border-green-500 text-green-600 font-semibold' : 'border-transparent text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-cash-register mr-2"></i>ขายหน้าร้าน
            </a>
            <a href="?tab=reports" class="px-4 py-3 border-b-2 <?= $currentTab === 'reports' ? 'border-green-500 text-green-600 font-semibold' : 'border-transparent text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-chart-bar mr-2"></i>รายงาน
            </a>
        </div>
    </div>
</div>

<?php if ($currentTab === 'reports'): ?>
<!-- Reports Tab -->
<div class="container mx-auto px-4 pb-8">
    <?php include 'includes/pos/reports.php'; ?>
</div>

<?php elseif (!$currentShift): ?>
<!-- ==================== หน้าเปิดกะ ==================== -->
<div class="pos-shift-screen">
    <div class="shift-card">
        <div class="shift-icon">
            <i class="fas fa-cash-register"></i>
        </div>
        <h1>เปิดกะขายหน้าร้าน</h1>
        <p class="text-muted">กรุณาเปิดกะก่อนเริ่มขายสินค้า</p>
        
        <div class="shift-info-box">
            <div class="info-row">
                <span><i class="fas fa-user"></i> พนักงาน</span>
                <strong><?= htmlspecialchars($userName) ?></strong>
            </div>
            <div class="info-row">
                <span><i class="fas fa-calendar"></i> วันที่</span>
                <strong><?= date('d/m/Y') ?></strong>
            </div>
            <div class="info-row">
                <span><i class="fas fa-clock"></i> เวลา</span>
                <strong id="currentTime"><?= date('H:i:s') ?></strong>
            </div>
        </div>
        
        <form id="openShiftForm" class="shift-form">
            <div class="form-group">
                <label><i class="fas fa-money-bill-wave"></i> เงินสดเปิดกะ (บาท)</label>
                <input type="number" id="openingCash" name="opening_cash" 
                       class="form-control form-control-lg text-center" 
                       value="1000" min="0" step="0.01" required>
                <small class="text-muted">นับเงินสดในลิ้นชักและกรอกจำนวน</small>
            </div>
            
            <div class="quick-amounts">
                <button type="button" onclick="setOpeningCash(500)">฿500</button>
                <button type="button" onclick="setOpeningCash(1000)">฿1,000</button>
                <button type="button" onclick="setOpeningCash(2000)">฿2,000</button>
                <button type="button" onclick="setOpeningCash(5000)">฿5,000</button>
            </div>
            
            <button type="submit" class="btn-open-shift">
                <i class="fas fa-door-open"></i> เปิดกะเริ่มขาย
            </button>
        </form>
        
        <div class="shift-tips">
            <h4><i class="fas fa-lightbulb"></i> คำแนะนำ</h4>
            <ul>
                <li>นับเงินสดในลิ้นชักให้ถูกต้องก่อนเปิดกะ</li>
                <li>เมื่อเปิดกะแล้วจะสามารถขายสินค้าได้</li>
                <li>ปิดกะเมื่อหมดเวลาทำงานเพื่อสรุปยอด</li>
            </ul>
        </div>
    </div>
</div>

<style>
.pos-shift-screen {
    min-height: calc(100vh - 60px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
}

.shift-card {
    background: white;
    border-radius: 20px;
    padding: 40px;
    max-width: 500px;
    width: 100%;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.shift-icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #4CAF50, #45a049);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
}

.shift-icon i {
    font-size: 48px;
    color: white;
}

.shift-card h1 {
    margin: 0 0 10px;
    color: #333;
    font-size: 28px;
}

.shift-info-box {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin: 25px 0;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.info-row:last-child {
    border-bottom: none;
}

.info-row span {
    color: #666;
}

.info-row i {
    margin-right: 8px;
    color: #4CAF50;
}

.shift-form {
    margin-top: 25px;
}

.shift-form .form-group {
    margin-bottom: 20px;
}

.shift-form label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #333;
}

.shift-form label i {
    color: #4CAF50;
    margin-right: 8px;
}

.shift-form input {
    font-size: 24px;
    padding: 15px;
    border: 2px solid #e0e0e0;
    border-radius: 12px;
}

.shift-form input:focus {
    border-color: #4CAF50;
    outline: none;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
}

.quick-amounts {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 25px;
}

.quick-amounts button {
    padding: 10px 20px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.quick-amounts button:hover {
    border-color: #4CAF50;
    background: #e8f5e9;
}

.btn-open-shift {
    width: 100%;
    padding: 18px;
    background: linear-gradient(135deg, #4CAF50, #45a049);
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 20px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-open-shift:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(76, 175, 80, 0.4);
}

.btn-open-shift i {
    margin-right: 10px;
}

.shift-tips {
    margin-top: 30px;
    text-align: left;
    background: #fff8e1;
    border-radius: 12px;
    padding: 20px;
}

.shift-tips h4 {
    margin: 0 0 15px;
    color: #f57c00;
}

.shift-tips ul {
    margin: 0;
    padding-left: 20px;
    color: #666;
}

.shift-tips li {
    margin-bottom: 8px;
}
</style>

<script>
// Update clock
setInterval(function() {
    const now = new Date();
    document.getElementById('currentTime').textContent = 
        now.toLocaleTimeString('th-TH');
}, 1000);

function setOpeningCash(amount) {
    document.getElementById('openingCash').value = amount;
}

document.getElementById('openShiftForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const openingCash = parseFloat(document.getElementById('openingCash').value) || 0;
    const btn = this.querySelector('button[type="submit"]');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังเปิดกะ...';
    
    try {
        const response = await fetch('/api/pos.php?action=open_shift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                opening_cash: openingCash
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success and reload
            btn.innerHTML = '<i class="fas fa-check"></i> เปิดกะสำเร็จ!';
            btn.style.background = '#4CAF50';
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            throw new Error(result.message || 'ไม่สามารถเปิดกะได้');
        }
    } catch (error) {
        alert('เกิดข้อผิดพลาด: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-door-open"></i> เปิดกะเริ่มขาย';
    }
});
</script>

<?php else: ?>
<!-- ==================== หน้าขาย (มีกะเปิดแล้ว) ==================== -->

<!-- Shift Status Bar -->
<div class="pos-shift-bar">
    <div class="shift-info">
        <span class="shift-badge open">
            <i class="fas fa-circle"></i> กะเปิด
        </span>
        <span class="shift-number">
            <i class="fas fa-hashtag"></i> <?= htmlspecialchars($currentShift['shift_number']) ?>
        </span>
        <span class="shift-time">
            <i class="fas fa-clock"></i> เปิดเมื่อ <?= date('H:i', strtotime($currentShift['opened_at'])) ?>
        </span>
        <span class="shift-sales">
            <i class="fas fa-receipt"></i> <?= number_format($todaySummary['total_transactions'] ?? 0) ?> รายการ
        </span>
        <span class="shift-total">
            <i class="fas fa-coins"></i> ฿<?= number_format($todaySummary['total_sales'] ?? 0, 2) ?>
        </span>
    </div>
    <div class="shift-actions">
        <button class="btn-shift-action" onclick="showHeldTransactions()" title="บิลที่พักไว้">
            <i class="fas fa-clipboard-list"></i>
        </button>
        <button class="btn-shift-action" onclick="showCashMovementModal()" title="เงินเข้า/ออก">
            <i class="fas fa-money-bill-transfer"></i>
        </button>
        <button class="btn-shift-action" onclick="showReprintModal()" title="พิมพ์ใบเสร็จซ้ำ">
            <i class="fas fa-redo"></i>
        </button>
        <button class="btn-shift-action" onclick="showReturnModal()" title="คืนสินค้า">
            <i class="fas fa-undo"></i>
        </button>
        <button class="btn-shift-action" onclick="showShiftSummary()" title="สรุปกะ">
            <i class="fas fa-chart-bar"></i>
        </button>
        <button class="btn-shift-action" onclick="showHistoryModal()" title="ประวัติ">
            <i class="fas fa-history"></i>
        </button>
        <button class="btn-shift-action warning" onclick="showCloseShiftModal()" title="ปิดกะ">
            <i class="fas fa-sign-out-alt"></i> ปิดกะ
        </button>
    </div>
</div>

<!-- Main POS Container -->
<div class="pos-container">
    <!-- Left: Products -->
    <div class="pos-left">
        <div class="pos-search">
            <input type="text" id="productSearch" placeholder="🔍 ค้นหาสินค้า (ชื่อ, SKU, บาร์โค้ด)..." 
                   onkeyup="searchProducts(this.value)" autofocus>
            <div class="search-hint">กด Enter หรือสแกนบาร์โค้ดเพื่อเพิ่มสินค้า</div>
        </div>
        
        <div class="pos-products">
            <div id="productsGrid" class="products-grid">
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>พิมพ์ค้นหา<br>สินค้าหรือ<br>สแกนบาร์โค้ด</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right: Cart -->
    <div class="pos-right">
        <div class="cart-header">
            <span><i class="fas fa-shopping-cart"></i> ตะกร้าสินค้า</span>
            <span id="cartCount">0 รายการ</span>
        </div>
        
        <div class="cart-customer" onclick="showCustomerModal()">
            <div id="customerInfo">
                <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
                <small class="text-muted d-block">คลิกเพื่อเลือกสมาชิก</small>
            </div>
        </div>
        
        <div class="cart-items" id="cartItems">
            <div class="empty-state">
                <i class="fas fa-shopping-basket"></i>
                <p>ยังไม่มีสินค้าในตะกร้า</p>
            </div>
        </div>
        
        <div class="cart-totals">
            <div class="row">
                <span>รวม</span>
                <span id="subtotal">฿0.00</span>
            </div>
            <div class="row" id="discountRow" style="display: none;">
                <span>ส่วนลด</span>
                <span id="discount" class="text-danger">-฿0.00</span>
            </div>
            <div class="row">
                <span>VAT 7%</span>
                <span id="vat">฿0.00</span>
            </div>
            <div class="row total">
                <span>ยอดสุทธิ</span>
                <span id="total">฿0.00</span>
            </div>
        </div>
        
        <div class="cart-actions">
            <button class="btn-action clear" onclick="clearCart()" title="ล้างตะกร้า">
                <i class="fas fa-trash"></i>
            </button>
            <button class="btn-action hold" onclick="showHoldModal()" title="พักบิล">
                <i class="fas fa-pause"></i>
            </button>
            <button class="btn-action discount" onclick="showDiscountModal()" title="ส่วนลด">
                <i class="fas fa-percent"></i>
            </button>
            <button class="btn-pay" id="payBtn" onclick="showPaymentModal()" disabled>
                <i class="fas fa-credit-card"></i> ชำระเงิน
            </button>
        </div>
    </div>
</div>

<!-- Include POS Modals -->
<?php include 'includes/pos/modals.php'; ?>

<!-- POS JavaScript -->
<script src="assets/js/pos.js"></script>

<script>
// Initialize POS
document.addEventListener('DOMContentLoaded', function() {
    POS.init({
        hasShift: true,
        shiftId: <?= $currentShift['id'] ?>,
        cashierId: <?= $userId ?>,
        cashierName: '<?= addslashes($userName) ?>'
    });
});
</script>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
