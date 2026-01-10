<?php
/**
 * POS Modals
 * 
 * Contains all modal dialogs for POS system:
 * - Open/Close Shift
 * - Customer Selection
 * - Payment
 * - Discount
 * - Receipt Preview
 */
?>

<style>
/* Modal Base Styles */
.pos-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.pos-modal.active {
    display: flex !important;
}

.pos-modal-content {
    background: white;
    border-radius: 12px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.pos-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pos-modal-header h5 {
    margin: 0;
    font-size: 18px;
}

.pos-modal-body {
    padding: 20px;
}

.pos-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.btn-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #999;
    padding: 0;
    line-height: 1;
}

.btn-close:before {
    content: '×';
}

/* Payment Methods */
.payment-methods {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.payment-method {
    padding: 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}

.payment-method:hover {
    border-color: #4CAF50;
}

.payment-method.active {
    border-color: #4CAF50;
    background: #e8f5e9;
}

.payment-method i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
}

/* Payment Input */
.payment-input {
    margin-bottom: 15px;
}

.payment-input label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.payment-input input {
    width: 100%;
    padding: 12px;
    font-size: 18px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    text-align: right;
}

.payment-input input:focus {
    border-color: #4CAF50;
    outline: none;
}

/* Quick Cash Buttons */
.quick-cash {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.quick-cash button {
    padding: 8px 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f5f5f5;
    cursor: pointer;
    font-weight: 500;
}

.quick-cash button:hover {
    background: #e0e0e0;
}

/* Customer List */
.customer-list {
    max-height: 300px;
    overflow-y: auto;
}

.customer-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
}

.customer-item:hover {
    background: #f5f5f5;
}

/* Row helper */
.row {
    display: flex;
    justify-content: space-between;
}

/* Payment Sections */
.payment-section {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<!-- Open Shift Modal -->
<div class="pos-modal" id="openShiftModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-sign-in-alt"></i> เปิดกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('openShiftModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="payment-input">
                <label>เงินสดเปิดกะ (บาท)</label>
                <input type="number" id="openingCash" value="0" min="0" step="0.01">
            </div>
            <p class="text-muted small">
                <i class="fas fa-info-circle"></i> 
                กรุณานับเงินสดในลิ้นชักและกรอกจำนวนเงินเปิดกะ
            </p>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('openShiftModal')">ยกเลิก</button>
            <button class="btn btn-success" onclick="openShift()">
                <i class="fas fa-check"></i> เปิดกะ
            </button>
        </div>
    </div>
</div>

<!-- Close Shift Modal -->
<div class="pos-modal" id="closeShiftModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-sign-out-alt"></i> ปิดกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('closeShiftModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="payment-input">
                <label>เงินสดปิดกะ (บาท)</label>
                <input type="number" id="closingCash" value="0" min="0" step="0.01" onchange="calculateVariance()">
            </div>
            
            <div id="varianceInfo" class="mt-3 p-3 bg-light rounded" style="display: none;">
                <div class="row mb-2">
                    <span>เงินเปิดกะ:</span>
                    <span id="varOpeningCash">฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>ยอดขายเงินสด:</span>
                    <span id="varCashSales">฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>คืนเงินสด:</span>
                    <span id="varCashRefunds">-฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>เงินที่ควรมี:</span>
                    <span id="varExpected">฿0.00</span>
                </div>
                <hr>
                <div class="row">
                    <span><strong>ส่วนต่าง:</strong></span>
                    <span id="varVariance" class="fw-bold">฿0.00</span>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('closeShiftModal')">ยกเลิก</button>
            <button class="btn btn-warning" onclick="closeShift()">
                <i class="fas fa-check"></i> ปิดกะ
            </button>
        </div>
    </div>
</div>

<!-- Shift Summary Modal -->
<div class="pos-modal" id="shiftSummaryModal">
    <div class="pos-modal-content" style="max-width: 600px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-chart-bar"></i> สรุปกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('shiftSummaryModal')"></button>
        </div>
        <div class="pos-modal-body" id="shiftSummaryContent">
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">กำลังโหลด...</p>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('shiftSummaryModal')">ปิด</button>
            <button class="btn btn-primary" onclick="printShiftSummary()">
                <i class="fas fa-print"></i> พิมพ์
            </button>
        </div>
    </div>
</div>

<!-- Customer Selection Modal -->
<div class="pos-modal" id="customerModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-user"></i> เลือกลูกค้า</h5>
            <button type="button" class="btn-close" onclick="closeModal('customerModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="mb-3">
                <input type="text" class="form-control" id="customerSearch" 
                       placeholder="ค้นหาด้วยเบอร์โทรหรือชื่อ..." onkeyup="searchCustomers(this.value)">
            </div>
            
            <div id="customerResults" class="customer-list">
                <div class="text-center text-muted py-3">
                    พิมพ์เพื่อค้นหาลูกค้า
                </div>
            </div>
            
            <hr>
            
            <button class="btn btn-outline-secondary w-100" onclick="selectWalkIn()">
                <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
            </button>
        </div>
    </div>
</div>

<!-- Discount Modal -->
<div class="pos-modal" id="discountModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-percent"></i> ส่วนลดบิล</h5>
            <button type="button" class="btn-close" onclick="closeModal('discountModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="mb-3">
                <label class="form-label">ประเภทส่วนลด</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="discountType" id="discountPercent" value="percent" checked>
                    <label class="btn btn-outline-primary" for="discountPercent">เปอร์เซ็นต์ (%)</label>
                    
                    <input type="radio" class="btn-check" name="discountType" id="discountFixed" value="fixed">
                    <label class="btn btn-outline-primary" for="discountFixed">จำนวนเงิน (฿)</label>
                </div>
            </div>
            
            <div class="payment-input">
                <label>จำนวนส่วนลด</label>
                <input type="number" id="discountValue" value="0" min="0" step="0.01">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('discountModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="applyBillDiscount()">
                <i class="fas fa-check"></i> ใช้ส่วนลด
            </button>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="pos-modal" id="paymentModal">
    <div class="pos-modal-content" style="max-width: 550px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-credit-card"></i> ชำระเงิน</h5>
            <button type="button" class="btn-close" onclick="closeModal('paymentModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="text-center mb-4">
                <h3 class="text-success mb-0" id="paymentTotal">฿0.00</h3>
                <small class="text-muted">ยอดที่ต้องชำระ</small>
            </div>
            
            <div class="payment-methods">
                <div class="payment-method active" data-method="cash" onclick="selectPaymentMethod('cash')">
                    <i class="fas fa-money-bill-wave text-success"></i>
                    <div>เงินสด</div>
                </div>
                <div class="payment-method" data-method="transfer" onclick="selectPaymentMethod('transfer')">
                    <i class="fas fa-qrcode text-primary"></i>
                    <div>โอน/QR</div>
                </div>
                <div class="payment-method" data-method="card" onclick="selectPaymentMethod('card')">
                    <i class="fas fa-credit-card text-info"></i>
                    <div>บัตร</div>
                </div>
                <div class="payment-method" data-method="points" onclick="selectPaymentMethod('points')" id="pointsMethod" style="display: none;">
                    <i class="fas fa-star text-warning"></i>
                    <div>แต้ม</div>
                </div>
            </div>
            
            <!-- Cash Payment -->
            <div id="cashPayment" class="payment-section">
                <div class="payment-input">
                    <label>รับเงิน (บาท)</label>
                    <input type="number" id="cashReceived" value="0" min="0" step="0.01" onchange="calculateChange()">
                </div>
                
                <div class="quick-cash">
                    <button onclick="setCashAmount(20)">฿20</button>
                    <button onclick="setCashAmount(50)">฿50</button>
                    <button onclick="setCashAmount(100)">฿100</button>
                    <button onclick="setCashAmount(500)">฿500</button>
                    <button onclick="setCashAmount(1000)">฿1000</button>
                    <button onclick="setExactAmount()">พอดี</button>
                </div>
                
                <div class="mt-3 p-3 bg-light rounded text-center">
                    <small class="text-muted">เงินทอน</small>
                    <h2 id="changeAmount" class="text-primary mb-0">฿0.00</h2>
                </div>
            </div>
            
            <!-- Transfer Payment -->
            <div id="transferPayment" class="payment-section" style="display: none;">
                <div class="text-center mb-3">
                    <div id="qrCodeDisplay" class="mb-2">
                        <!-- QR Code will be displayed here -->
                        <div class="border rounded p-4">
                            <i class="fas fa-qrcode fa-5x text-muted"></i>
                            <p class="mt-2 mb-0 text-muted">QR Code สำหรับชำระเงิน</p>
                        </div>
                    </div>
                </div>
                <div class="payment-input">
                    <label>เลขอ้างอิง (ถ้ามี)</label>
                    <input type="text" id="transferRef" placeholder="เลขอ้างอิงการโอน">
                </div>
            </div>
            
            <!-- Card Payment -->
            <div id="cardPayment" class="payment-section" style="display: none;">
                <div class="payment-input">
                    <label>เลขอ้างอิง/Approval Code</label>
                    <input type="text" id="cardRef" placeholder="เลขอ้างอิงจากเครื่อง EDC">
                </div>
            </div>
            
            <!-- Points Payment -->
            <div id="pointsPayment" class="payment-section" style="display: none;">
                <div class="text-center mb-3">
                    <p>แต้มที่มี: <strong id="availablePoints">0</strong> แต้ม</p>
                    <p class="text-muted small">10 แต้ม = 1 บาท</p>
                </div>
                <div class="payment-input">
                    <label>จำนวนแต้มที่ใช้</label>
                    <input type="number" id="pointsToUse" value="0" min="0" onchange="calculatePointsValue()">
                </div>
                <div class="text-center">
                    <p>มูลค่า: <strong id="pointsValue">฿0.00</strong></p>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('paymentModal')">ยกเลิก</button>
            <button class="btn btn-success btn-lg" onclick="processPayment()" id="confirmPayBtn">
                <i class="fas fa-check"></i> ยืนยันชำระเงิน
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="pos-modal" id="receiptModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-receipt"></i> ใบเสร็จ</h5>
            <button type="button" class="btn-close" onclick="closeModal('receiptModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div id="receiptPreview" class="text-center">
                <!-- Receipt will be loaded here -->
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeReceiptAndNewSale()">
                <i class="fas fa-plus"></i> ขายรายการใหม่
            </button>
            <button class="btn btn-primary" onclick="printReceipt()">
                <i class="fas fa-print"></i> พิมพ์
            </button>
            <button class="btn btn-success" onclick="sendLineReceipt()" id="sendLineBtn" style="display: none;">
                <i class="fab fa-line"></i> ส่ง LINE
            </button>
        </div>
    </div>
</div>

<!-- Item Discount Modal -->
<div class="pos-modal" id="itemDiscountModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-percent"></i> ส่วนลดสินค้า</h5>
            <button type="button" class="btn-close" onclick="closeModal('itemDiscountModal')"></button>
        </div>
        <div class="pos-modal-body">
            <input type="hidden" id="discountItemId">
            
            <div class="mb-3">
                <strong id="discountItemName">สินค้า</strong>
                <p class="text-muted mb-0" id="discountItemPrice">฿0.00</p>
            </div>
            
            <div class="mb-3">
                <label class="form-label">ประเภทส่วนลด</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="itemDiscountType" id="itemDiscountPercent" value="percent" checked>
                    <label class="btn btn-outline-primary" for="itemDiscountPercent">%</label>
                    
                    <input type="radio" class="btn-check" name="itemDiscountType" id="itemDiscountFixed" value="fixed">
                    <label class="btn btn-outline-primary" for="itemDiscountFixed">฿</label>
                </div>
            </div>
            
            <div class="payment-input">
                <label>จำนวนส่วนลด</label>
                <input type="number" id="itemDiscountValue" value="0" min="0" step="0.01">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('itemDiscountModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="applyItemDiscount()">
                <i class="fas fa-check"></i> ใช้ส่วนลด
            </button>
        </div>
    </div>
</div>



<!-- History Modal -->
<div class="pos-modal" id="historyModal">
    <div class="pos-modal-content" style="max-width: 600px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-history"></i> ประวัติการขาย</h5>
            <button type="button" class="btn-close" onclick="closeModal('historyModal')"></button>
        </div>
        <div class="pos-modal-body" id="historyContent">
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">กำลังโหลด...</p>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('historyModal')">ปิด</button>
        </div>
    </div>
</div>

<!-- Hold Transaction Modal -->
<div class="pos-modal" id="holdModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-pause-circle"></i> พักบิล</h5>
            <button type="button" class="btn-close" onclick="closeModal('holdModal')"></button>
        </div>
        <div class="pos-modal-body">
            <p>ต้องการพักบิลนี้ไว้ก่อน?</p>
            <div class="payment-input">
                <label>หมายเหตุ (ไม่บังคับ)</label>
                <input type="text" id="holdNote" placeholder="เช่น ลูกค้ารอเลือกสินค้าเพิ่ม">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('holdModal')">ยกเลิก</button>
            <button class="btn btn-warning" onclick="confirmHoldTransaction()">
                <i class="fas fa-pause"></i> พักบิล
            </button>
        </div>
    </div>
</div>

<!-- Held Transactions Modal -->
<div class="pos-modal" id="heldTransactionsModal">
    <div class="pos-modal-content" style="max-width: 600px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-clipboard-list"></i> บิลที่พักไว้</h5>
            <button type="button" class="btn-close" onclick="closeModal('heldTransactionsModal')"></button>
        </div>
        <div class="pos-modal-body" id="heldTransactionsContent">
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">กำลังโหลด...</p>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('heldTransactionsModal')">ปิด</button>
        </div>
    </div>
</div>

<!-- Price Override Modal -->
<div class="pos-modal" id="priceOverrideModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-edit"></i> แก้ไขราคา</h5>
            <button type="button" class="btn-close" onclick="closeModal('priceOverrideModal')"></button>
        </div>
        <div class="pos-modal-body">
            <input type="hidden" id="overrideItemId">
            
            <div class="mb-3">
                <strong id="overrideItemName">สินค้า</strong>
                <p class="text-muted mb-0">ราคาเดิม: <span id="overrideOriginalPrice">฿0.00</span></p>
            </div>
            
            <div class="payment-input">
                <label>ราคาใหม่ (บาท)</label>
                <input type="number" id="overrideNewPrice" value="0" min="0" step="0.01">
            </div>
            
            <div class="payment-input">
                <label>เหตุผล <span class="text-danger">*</span></label>
                <input type="text" id="overrideReason" placeholder="เช่น สินค้าตำหนิ, โปรโมชั่นพิเศษ" required>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('priceOverrideModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="confirmPriceOverride()">
                <i class="fas fa-check"></i> ยืนยัน
            </button>
        </div>
    </div>
</div>

<!-- Cash In/Out Modal -->
<div class="pos-modal" id="cashMovementModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-cash-register"></i> <span id="cashMovementTitle">เงินเข้า/ออก</span></h5>
            <button type="button" class="btn-close" onclick="closeModal('cashMovementModal')"></button>
        </div>
        <div class="pos-modal-body">
            <input type="hidden" id="cashMovementType" value="in">
            
            <div class="mb-3">
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="cashMoveType" id="cashMoveIn" value="in" checked onchange="setCashMovementType('in')">
                    <label class="btn btn-outline-success" for="cashMoveIn">
                        <i class="fas fa-arrow-down"></i> เงินเข้า
                    </label>
                    
                    <input type="radio" class="btn-check" name="cashMoveType" id="cashMoveOut" value="out" onchange="setCashMovementType('out')">
                    <label class="btn btn-outline-danger" for="cashMoveOut">
                        <i class="fas fa-arrow-up"></i> เงินออก
                    </label>
                </div>
            </div>
            
            <div class="payment-input">
                <label>จำนวนเงิน (บาท) <span class="text-danger">*</span></label>
                <input type="number" id="cashMovementAmount" value="0" min="0" step="0.01">
            </div>
            
            <div class="payment-input">
                <label>เหตุผล <span class="text-danger">*</span></label>
                <input type="text" id="cashMovementReason" placeholder="เช่น เติมเงินทอน, นำเงินไปฝากธนาคาร">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('cashMovementModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="confirmCashMovement()">
                <i class="fas fa-check"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<!-- Reprint Receipt Modal -->
<div class="pos-modal" id="reprintModal">
    <div class="pos-modal-content" style="max-width: 550px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-redo"></i> พิมพ์ใบเสร็จซ้ำ</h5>
            <button type="button" class="btn-close" onclick="closeModal('reprintModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="payment-input mb-3">
                <label>ค้นหาเลขที่บิล</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="reprintReceiptNumber" placeholder="เช่น POS-20260110-0001" onkeyup="if(event.key==='Enter')searchReceiptForReprint()">
                    <button class="btn btn-outline-primary" onclick="searchReceiptForReprint()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-muted small">หรือเลือกจากรายการล่าสุด:</label>
                <div id="recentTransactionsList" class="recent-transactions-list">
                    <div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
            
            <div id="reprintReceiptPreview" style="display: none;">
                <hr>
                <div class="p-3 bg-light rounded">
                    <div class="row mb-2">
                        <span>เลขที่:</span>
                        <strong id="reprintTxNumber">-</strong>
                    </div>
                    <div class="row mb-2">
                        <span>วันที่:</span>
                        <span id="reprintTxDate">-</span>
                    </div>
                    <div class="row mb-2">
                        <span>ยอดรวม:</span>
                        <strong id="reprintTxTotal">฿0.00</strong>
                    </div>
                    <div class="row">
                        <span>สถานะ:</span>
                        <span id="reprintTxStatus">-</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('reprintModal')">ปิด</button>
            <button class="btn btn-primary" onclick="confirmReprint()" id="reprintBtn" disabled>
                <i class="fas fa-print"></i> พิมพ์ใบเสร็จ
            </button>
        </div>
    </div>
</div>

<!-- Return Modal -->
<div class="pos-modal" id="returnModal">
    <div class="pos-modal-content" style="max-width: 700px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-undo"></i> คืนสินค้า</h5>
            <button type="button" class="btn-close" onclick="closeModal('returnModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="mb-3">
                <label class="form-label">ค้นหาใบเสร็จ</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="returnReceiptSearch" placeholder="เลขที่ใบเสร็จ">
                    <button class="btn btn-outline-primary" onclick="searchReceiptForReturn()">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div id="returnReceiptInfo" style="display: none;">
                <div class="p-3 bg-light rounded mb-3">
                    <div class="row mb-2">
                        <span>ใบเสร็จ:</span>
                        <strong id="returnTxNumber">-</strong>
                    </div>
                    <div class="row mb-2">
                        <span>วันที่:</span>
                        <span id="returnTxDate">-</span>
                    </div>
                    <div class="row">
                        <span>ลูกค้า:</span>
                        <span id="returnTxCustomer">-</span>
                    </div>
                </div>
                
                <h6>เลือกสินค้าที่ต้องการคืน:</h6>
                <div id="returnItemsList" class="mb-3">
                    <!-- Items will be loaded here -->
                </div>
                
                <div class="payment-input">
                    <label>เหตุผลในการคืน <span class="text-danger">*</span></label>
                    <input type="text" id="returnReason" placeholder="เช่น สินค้าชำรุด, ลูกค้าเปลี่ยนใจ">
                </div>
                
                <div class="p-3 bg-warning-subtle rounded">
                    <div class="row">
                        <span>ยอดคืนเงิน:</span>
                        <strong id="returnTotalAmount">฿0.00</strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('returnModal')">ยกเลิก</button>
            <button class="btn btn-danger" onclick="processReturn()" id="processReturnBtn" disabled>
                <i class="fas fa-undo"></i> ดำเนินการคืนสินค้า
            </button>
        </div>
    </div>
</div>

<style>
/* History List Styles */
.history-list {
    max-height: 400px;
    overflow-y: auto;
}

.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: background 0.2s;
}

.history-item:hover {
    background: #f5f5f5;
}

.history-info strong {
    display: block;
    margin-bottom: 4px;
}

.history-info small {
    color: #666;
}

.history-amount {
    text-align: right;
}

.history-amount .badge {
    display: block;
    margin-bottom: 4px;
}

/* Customer Item Styles */
.customer-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: background 0.2s;
}

.customer-item:hover {
    background: #f5f5f5;
}

.customer-item img {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
}

.customer-item .info {
    flex: 1;
}

.customer-item .name {
    font-weight: 500;
    margin-bottom: 2px;
}

.customer-item .phone {
    font-size: 13px;
    color: #666;
}

.customer-item .points {
    text-align: center;
}

.customer-item .points .value {
    font-size: 18px;
    font-weight: bold;
    color: #ff9800;
}

.customer-item .points .label {
    font-size: 11px;
    color: #999;
}

/* History List Styles */
.history-list {
    max-height: 400px;
    overflow-y: auto;
}

.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: background 0.2s;
    gap: 10px;
}

.history-item:hover {
    background: #f5f5f5;
}

.history-info {
    flex: 1;
    min-width: 0;
}

.history-info strong {
    display: block;
    margin-bottom: 2px;
    font-size: 14px;
}

.history-info small {
    color: #666;
    font-size: 12px;
}

.history-amount {
    text-align: right;
    min-width: 100px;
}

.history-amount .badge {
    display: inline-block;
    margin-bottom: 4px;
    font-size: 11px;
}

.history-amount strong {
    display: block;
    font-size: 15px;
    color: #333;
}

.history-actions {
    display: flex;
    gap: 5px;
}

.history-actions .btn {
    padding: 6px 10px;
}

/* Recent Transactions List for Reprint */
.recent-transactions-list {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #eee;
    border-radius: 8px;
}

.recent-tx-item {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
}

.recent-tx-item:last-child {
    border-bottom: none;
}

.recent-tx-item:hover {
    background: #f0f7ff;
}

.recent-tx-item strong {
    font-size: 13px;
}

.recent-tx-item small {
    font-size: 11px;
}

/* Held Item Styles */
.held-item {
    transition: all 0.2s;
}

.held-item:hover {
    background: #f5f5f5;
    border-color: #4CAF50 !important;
}

/* Return Item Styles */
.return-item {
    transition: all 0.2s;
}

.return-item:hover {
    background: #fff8e1;
}

.return-item input[type="checkbox"]:checked + div {
    color: #e65100;
}

/* Form Controls in Modals */
.pos-modal .form-control {
    border-radius: 6px;
    border: 1px solid #ddd;
    padding: 10px 12px;
}

.pos-modal .form-control:focus {
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
}

.pos-modal .input-group .btn {
    border-radius: 0 6px 6px 0;
}

/* Badge Colors */
.badge.bg-success { background-color: #4CAF50 !important; }
.badge.bg-danger { background-color: #f44336 !important; }
.badge.bg-warning { background-color: #ff9800 !important; color: #000 !important; }
.badge.bg-info { background-color: #2196F3 !important; }
.badge.bg-secondary { background-color: #9e9e9e !important; }

/* Text Colors */
.text-success { color: #4CAF50 !important; }
.text-danger { color: #f44336 !important; }
.text-warning { color: #ff9800 !important; }
.text-info { color: #2196F3 !important; }
.text-muted { color: #999 !important; }

/* Button Styles */
.btn-outline-primary {
    color: #2196F3;
    border-color: #2196F3;
}
.btn-outline-primary:hover {
    background: #2196F3;
    color: white;
}

.btn-outline-warning {
    color: #ff9800;
    border-color: #ff9800;
}
.btn-outline-warning:hover {
    background: #ff9800;
    color: white;
}

.btn-outline-danger {
    color: #f44336;
    border-color: #f44336;
}
.btn-outline-danger:hover {
    background: #f44336;
    color: white;
}

/* Responsive adjustments */
@media (max-width: 576px) {
    .history-item {
        flex-wrap: wrap;
    }
    
    .history-actions {
        width: 100%;
        justify-content: flex-end;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #eee;
    }
}
</style>
