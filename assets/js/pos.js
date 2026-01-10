/**
 * POS JavaScript
 * 
 * Handles all POS frontend operations:
 * - Cart state management
 * - API calls
 * - Barcode scanner integration
 * - Keyboard shortcuts
 */

const POS = {
    // API Base URL
    apiUrl: '/api/pos.php',
    
    // State
    config: {
        hasShift: false,
        shiftId: null,
        cashierId: null,
        cashierName: ''
    },
    transaction: null,
    cart: [],
    customer: null,
    searchTimeout: null,
    
    /**
     * Initialize POS
     */
    init: function(config) {
        this.config = { ...this.config, ...config };
        this.setupKeyboardShortcuts();
        this.setupBarcodeScanner();
        
        if (this.config.hasShift) {
            const searchInput = document.getElementById('productSearch');
            if (searchInput) searchInput.focus();
        }
        
        console.log('POS initialized', this.config);
    },
    
    /**
     * Setup keyboard shortcuts
     */
    setupKeyboardShortcuts: function() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'F2') {
                e.preventDefault();
                document.getElementById('productSearch')?.focus();
            }
            if (e.key === 'F4' && this.cart.length > 0) {
                e.preventDefault();
                showPaymentModal();
            }
            if (e.key === 'F8') {
                e.preventDefault();
                clearCart();
            }
            if (e.key === 'Escape') {
                document.querySelectorAll('.pos-modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    },
    
    /**
     * Setup barcode scanner
     */
    setupBarcodeScanner: function() {
        let barcodeBuffer = '';
        let lastKeyTime = 0;
        
        document.addEventListener('keypress', (e) => {
            const currentTime = Date.now();
            if (currentTime - lastKeyTime < 50) {
                barcodeBuffer += e.key;
            } else {
                barcodeBuffer = e.key;
            }
            lastKeyTime = currentTime;
            
            if (e.key === 'Enter' && barcodeBuffer.length > 3) {
                e.preventDefault();
                this.handleBarcodeScan(barcodeBuffer.slice(0, -1));
                barcodeBuffer = '';
            }
        });
    },
    
    /**
     * Handle barcode scan
     */
    handleBarcodeScan: function(barcode) {
        if (!this.config.hasShift) {
            showToast('กรุณาเปิดกะก่อน', 'warning');
            return;
        }
        this.searchAndAddProduct(barcode);
    },
    
    /**
     * Search and add product by barcode
     */
    searchAndAddProduct: async function(barcode) {
        try {
            const response = await fetch(`${this.apiUrl}?action=search_products&q=${encodeURIComponent(barcode)}`);
            if (!response.ok) throw new Error('Network error');
            const text = await response.text();
            if (!text) {
                showToast('ไม่พบสินค้า', 'warning');
                return;
            }
            const data = JSON.parse(text);
            if (data.success && data.data && data.data.length > 0) {
                await this.addToCart(data.data[0].id);
            } else {
                showToast('ไม่พบสินค้า', 'warning');
            }
        } catch (error) {
            console.error('Search error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },

    /**
     * Create new transaction
     */
    createTransaction: async function() {
        try {
            const response = await fetch(this.apiUrl + '?action=create_transaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ customer_id: this.customer?.id || null })
            });
            const text = await response.text();
            if (!text) throw new Error('Empty response');
            const data = JSON.parse(text);
            if (data.success) {
                this.transaction = data.data;
                return this.transaction;
            } else {
                throw new Error(data.message || data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Create transaction error:', error);
            showToast(error.message || 'ไม่สามารถสร้างรายการได้', 'error');
            return null;
        }
    },
    
    /**
     * Add product to cart
     */
    addToCart: async function(productId, quantity = 1) {
        if (!this.config.hasShift) {
            showToast('กรุณาเปิดกะก่อน', 'warning');
            return;
        }
        
        if (!this.transaction) {
            await this.createTransaction();
            if (!this.transaction) return;
        }
        
        try {
            const response = await fetch(this.apiUrl + '?action=add_to_cart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: this.transaction.id,
                    product_id: productId,
                    quantity: quantity
                })
            });
            const text = await response.text();
            if (!text) throw new Error('Empty response');
            const data = JSON.parse(text);
            if (data.success) {
                this.transaction = data.data.transaction;
                this.cart = this.transaction.items || [];
                this.updateCartDisplay();
                showToast('เพิ่มสินค้าแล้ว', 'success');
            } else {
                showToast(data.message || data.error || 'เกิดข้อผิดพลาด', 'error');
            }
        } catch (error) {
            console.error('Add to cart error:', error);
            showToast('เกิดข้อผิดพลาด: ' + error.message, 'error');
        }
    },

    /**
     * Update cart item quantity
     */
    updateCartItem: async function(itemId, quantity) {
        if (quantity < 1) return this.removeFromCart(itemId);
        
        try {
            const response = await fetch(this.apiUrl + '?action=update_cart_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: itemId, quantity: quantity })
            });
            const data = await response.json();
            if (data.success) {
                await this.refreshTransaction();
            } else {
                showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Update cart error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Remove item from cart
     */
    removeFromCart: async function(itemId) {
        try {
            const response = await fetch(this.apiUrl + '?action=remove_cart_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: itemId })
            });
            const data = await response.json();
            if (data.success) {
                await this.refreshTransaction();
                showToast('ลบสินค้าแล้ว', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Remove from cart error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Refresh transaction data
     */
    refreshTransaction: async function() {
        if (!this.transaction) return;
        try {
            const response = await fetch(`${this.apiUrl}?action=get_transaction&id=${this.transaction.id}`);
            const data = await response.json();
            if (data.success) {
                this.transaction = data.data;
                this.cart = this.transaction.items || [];
                this.updateCartDisplay();
            }
        } catch (error) {
            console.error('Refresh error:', error);
        }
    },

    /**
     * Update cart display
     */
    updateCartDisplay: function() {
        const items = this.transaction?.items || [];
        this.cart = items;
        
        const cartItemsEl = document.getElementById('cartItems');
        const cartCountEl = document.getElementById('cartCount');
        const subtotalEl = document.getElementById('subtotal');
        const discountEl = document.getElementById('discount');
        const discountRowEl = document.getElementById('discountRow');
        const vatEl = document.getElementById('vat');
        const totalEl = document.getElementById('total');
        const payBtn = document.getElementById('payBtn');
        
        if (cartCountEl) cartCountEl.textContent = `${items.length} รายการ`;
        
        if (cartItemsEl) {
            if (items.length === 0) {
                cartItemsEl.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-shopping-basket"></i>
                        <p>ยังไม่มีสินค้าในตะกร้า</p>
                    </div>
                `;
                if (payBtn) payBtn.disabled = true;
            } else {
                cartItemsEl.innerHTML = items.map(item => `
                    <div class="cart-item">
                        <div class="info">
                            <div class="name">${this.escapeHtml(item.product_name)}</div>
                            <div class="price">฿${this.formatNumber(item.unit_price)} 
                                ${item.discount_amount > 0 ? `<span class="text-danger">-฿${this.formatNumber(item.discount_amount)}</span>` : ''}
                            </div>
                        </div>
                        <div class="qty-controls">
                            <button onclick="POS.updateCartItem(${item.id}, ${item.quantity - 1})">-</button>
                            <span class="qty">${item.quantity}</span>
                            <button onclick="POS.updateCartItem(${item.id}, ${item.quantity + 1})">+</button>
                        </div>
                        <div class="line-total">฿${this.formatNumber(item.line_total)}</div>
                        <div class="remove-btn" onclick="POS.removeFromCart(${item.id})">
                            <i class="fas fa-times"></i>
                        </div>
                    </div>
                `).join('');
                if (payBtn) payBtn.disabled = false;
            }
        }
        
        const t = this.transaction || {};
        if (subtotalEl) subtotalEl.textContent = `฿${this.formatNumber(t.subtotal || 0)}`;
        if (discountRowEl) discountRowEl.style.display = t.discount_amount > 0 ? 'flex' : 'none';
        if (discountEl) discountEl.textContent = `-฿${this.formatNumber(t.discount_amount || 0)}`;
        if (vatEl) vatEl.textContent = `฿${this.formatNumber(t.vat_amount || 0)}`;
        if (totalEl) totalEl.textContent = `฿${this.formatNumber(t.total_amount || 0)}`;
    },

    /**
     * Set customer
     */
    setCustomer: async function(customer) {
        this.customer = customer;
        const customerInfoEl = document.getElementById('customerInfo');
        
        if (customerInfoEl) {
            if (customer) {
                customerInfoEl.innerHTML = `
                    <i class="fas fa-user-check text-success"></i> ${this.escapeHtml(customer.display_name || customer.name)}
                    <small class="text-muted d-block">${customer.phone || ''} | แต้ม: ${customer.available_points || customer.points || 0}</small>
                `;
                const pointsMethod = document.getElementById('pointsMethod');
                if (pointsMethod) pointsMethod.style.display = 'block';
                const availablePoints = document.getElementById('availablePoints');
                if (availablePoints) availablePoints.textContent = customer.available_points || customer.points || 0;
            } else {
                customerInfoEl.innerHTML = `
                    <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
                    <small class="text-muted d-block">คลิกเพื่อเลือกสมาชิก</small>
                `;
                const pointsMethod = document.getElementById('pointsMethod');
                if (pointsMethod) pointsMethod.style.display = 'none';
            }
        }
        
        if (this.transaction && customer) {
            try {
                await fetch(this.apiUrl + '?action=set_customer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        transaction_id: this.transaction.id,
                        customer_id: customer.id
                    })
                });
            } catch (error) {
                console.error('Set customer error:', error);
            }
        }
    },
    
    /**
     * Clear cart
     */
    clearCart: function() {
        this.transaction = null;
        this.cart = [];
        this.customer = null;
        this.updateCartDisplay();
        this.setCustomer(null);
    },

    /**
     * Complete transaction
     */
    completeTransaction: async function(payments) {
        if (!this.transaction) return null;
        
        try {
            const response = await fetch(this.apiUrl + '?action=complete_transaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: this.transaction.id,
                    payments: Array.isArray(payments) ? payments : [payments]
                })
            });
            const text = await response.text();
            if (!text) throw new Error('Empty response');
            const data = JSON.parse(text);
            if (data.success) {
                return data.data;
            } else {
                throw new Error(data.message || data.error || 'Unknown error');
            }
        } catch (error) {
            console.error('Complete transaction error:', error);
            showToast(error.message || 'เกิดข้อผิดพลาด', 'error');
            return null;
        }
    },
    
    // Utility functions
    formatNumber: function(num) {
        return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    
    escapeHtml: function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// =========================================
// Global Functions
// =========================================

let searchTimeout = null;

function searchProducts(query) {
    if (!POS.config.hasShift) {
        showToast('กรุณาเปิดกะก่อน', 'warning');
        return;
    }
    
    clearTimeout(searchTimeout);
    const grid = document.getElementById('productsGrid');
    
    if (query.length < 1) {
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <p>พิมพ์ค้นหาสินค้า<br>หรือสแกนบาร์โค้ด</p>
            </div>
        `;
        return;
    }
    
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(POS.apiUrl + '?action=search_products&q=' + encodeURIComponent(query));
            if (!response.ok) throw new Error('Network error');
            const text = await response.text();
            if (!text) {
                grid.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><p>ไม่พบสินค้า</p></div>';
                return;
            }
            const data = JSON.parse(text);
            if (data.success && data.data && data.data.length > 0) {
                displayProducts(data.data);
            } else {
                grid.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><p>ไม่พบสินค้า</p></div>';
            }
        } catch (error) {
            console.error('Search error:', error);
            grid.innerHTML = '<div class="empty-state text-danger"><i class="fas fa-exclamation-triangle"></i><p>เกิดข้อผิดพลาด</p></div>';
        }
    }, 300);
}

function displayProducts(products) {
    const grid = document.getElementById('productsGrid');
    grid.innerHTML = products.map(p => {
        const outOfStock = p.stock <= 0;
        const isExpired = p.is_expired;
        let classes = 'product-card';
        if (outOfStock) classes += ' out-of-stock';
        if (isExpired) classes += ' expired';
        
        return `
            <div class="${classes}" onclick="${!outOfStock && !isExpired ? `POS.addToCart(${p.id})` : ''}">
                <img src="${p.image_url || p.image || '/assets/images/no-image.png'}" alt="${POS.escapeHtml(p.name)}" 
                     onerror="this.src='/assets/images/no-image.png'">
                <div class="name" title="${POS.escapeHtml(p.name)}">${POS.escapeHtml(p.name)}</div>
                <div class="price">฿${POS.formatNumber(p.price)}</div>
                <div class="stock">
                    ${isExpired ? '<span class="text-danger">หมดอายุ</span>' : 
                      outOfStock ? '<span class="text-danger">หมด</span>' : 
                      `คงเหลือ: ${p.stock}`}
                </div>
            </div>
        `;
    }).join('');
}

// Customer functions
let customerSearchTimeout = null;

function searchCustomers(query) {
    clearTimeout(customerSearchTimeout);
    const container = document.getElementById('customerResults');
    
    if (query.length < 2) {
        container.innerHTML = '<div class="text-center text-muted py-3">พิมพ์อย่างน้อย 2 ตัวอักษร</div>';
        return;
    }
    
    customerSearchTimeout = setTimeout(async () => {
        container.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></div>';
        try {
            const response = await fetch(POS.apiUrl + '?action=search_customers&q=' + encodeURIComponent(query));
            const data = await response.json();
            if (data.success && data.data && data.data.length > 0) {
                container.innerHTML = data.data.map(c => `
                    <div class="customer-item" onclick='selectCustomer(${JSON.stringify(c).replace(/'/g, "\\'")})'>
                        <img src="${c.picture_url || '/assets/images/default-avatar.png'}" onerror="this.src='/assets/images/default-avatar.png'">
                        <div class="info">
                            <div class="name">${POS.escapeHtml(c.display_name || c.name)}</div>
                            <div class="phone">${c.phone || '-'}</div>
                        </div>
                        <div class="points">
                            <div class="value">${c.available_points || c.points || 0}</div>
                            <div class="label">แต้ม</div>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div class="text-center text-muted py-3">ไม่พบลูกค้า</div>';
            }
        } catch (error) {
            console.error('Search customers error:', error);
            container.innerHTML = '<div class="text-center text-danger py-3">เกิดข้อผิดพลาด</div>';
        }
    }, 300);
}

function selectCustomer(customer) {
    POS.setCustomer(customer);
    closeModal('customerModal');
}

function selectWalkIn() {
    POS.setCustomer(null);
    closeModal('customerModal');
}

function clearCart() {
    if (POS.cart.length === 0) return;
    if (confirm('ต้องการล้างตะกร้าทั้งหมด?')) {
        POS.clearCart();
        showToast('ล้างตะกร้าแล้ว', 'success');
    }
}

// =========================================
// Modal Functions
// =========================================

function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

function showCustomerModal() {
    if (!POS.config.hasShift) {
        showToast('กรุณาเปิดกะก่อน', 'warning');
        return;
    }
    showModal('customerModal');
    document.getElementById('customerSearch')?.focus();
}

function showDiscountModal() {
    if (!POS.transaction || POS.cart.length === 0) {
        showToast('กรุณาเพิ่มสินค้าก่อน', 'warning');
        return;
    }
    showModal('discountModal');
}

function showPaymentModal() {
    if (!POS.transaction || POS.cart.length === 0) {
        showToast('กรุณาเพิ่มสินค้าก่อน', 'warning');
        return;
    }
    
    const paymentTotal = document.getElementById('paymentTotal');
    const cashReceived = document.getElementById('cashReceived');
    
    if (paymentTotal) paymentTotal.textContent = `฿${POS.formatNumber(POS.transaction.total_amount)}`;
    if (cashReceived) {
        cashReceived.value = Math.ceil(POS.transaction.total_amount);
        calculateChange();
    }
    
    showModal('paymentModal');
    selectPaymentMethod('cash');
}

// =========================================
// Shift Functions
// =========================================

function showOpenShiftModal() {
    document.getElementById('openingCash').value = '0';
    showModal('openShiftModal');
}

function showCloseShiftModal() {
    document.getElementById('closingCash').value = '';
    const varianceInfo = document.getElementById('varianceInfo');
    if (varianceInfo) varianceInfo.style.display = 'none';
    showModal('closeShiftModal');
}

async function openShift() {
    const openingCash = parseFloat(document.getElementById('openingCash').value) || 0;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=open_shift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ opening_cash: openingCash })
        });
        const data = await response.json();
        if (data.success) {
            showToast('เปิดกะสำเร็จ', 'success');
            location.reload();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Open shift error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function closeShift() {
    const closingCash = parseFloat(document.getElementById('closingCash').value) || 0;
    if (!confirm('ยืนยันปิดกะ?')) return;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=close_shift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shift_id: POS.config.shiftId,
                closing_cash: closingCash
            })
        });
        const data = await response.json();
        if (data.success) {
            showToast('ปิดกะสำเร็จ', 'success');
            location.reload();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Close shift error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function calculateVariance() {
    const closingCash = parseFloat(document.getElementById('closingCash').value) || 0;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=calculate_variance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shift_id: POS.config.shiftId,
                actual_cash: closingCash
            })
        });
        const data = await response.json();
        if (data.success) {
            const v = data.data;
            document.getElementById('varOpeningCash').textContent = `฿${POS.formatNumber(v.opening_cash)}`;
            document.getElementById('varCashSales').textContent = `฿${POS.formatNumber(v.cash_sales)}`;
            document.getElementById('varCashRefunds').textContent = `-฿${POS.formatNumber(v.cash_refunds)}`;
            document.getElementById('varExpected').textContent = `฿${POS.formatNumber(v.expected_cash)}`;
            
            const varianceEl = document.getElementById('varVariance');
            varianceEl.textContent = `฿${POS.formatNumber(v.variance)}`;
            varianceEl.className = v.variance >= 0 ? 'fw-bold text-success' : 'fw-bold text-danger';
            
            document.getElementById('varianceInfo').style.display = 'block';
        }
    } catch (error) {
        console.error('Calculate variance error:', error);
    }
}

async function showShiftSummary() {
    showModal('shiftSummaryModal');
    const container = document.getElementById('shiftSummaryContent');
    
    if (!POS.config.shiftId) {
        container.innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-info-circle fa-2x mb-2"></i><p>ไม่มีกะที่เปิดอยู่</p></div>';
        return;
    }
    
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">กำลังโหลด...</p></div>';
    
    try {
        const response = await fetch(`${POS.apiUrl}?action=shift_summary&id=${POS.config.shiftId}`);
        const data = await response.json();
        if (data.success) {
            displayShiftSummary(data.data);
        } else {
            container.innerHTML = '<div class="text-center py-4 text-danger">ไม่สามารถโหลดข้อมูลได้</div>';
        }
    } catch (error) {
        console.error('Shift summary error:', error);
        container.innerHTML = '<div class="text-center py-4 text-danger">เกิดข้อผิดพลาด</div>';
    }
}

function displayShiftSummary(data) {
    const s = data.summary || data;
    const c = data.cash_summary || {};
    const payments = data.payment_breakdown || [];
    const returns = data.returns_summary || { count: 0, total: 0 };
    
    document.getElementById('shiftSummaryContent').innerHTML = `
        <div class="mb-4">
            <h6><i class="fas fa-info-circle text-primary"></i> ข้อมูลกะ</h6>
            <p class="mb-1">กะ: ${data.shift?.shift_number || s.shift_number || '-'}</p>
            <p class="mb-1">เปิดเมื่อ: ${data.shift?.opened_at ? formatThaiDateTime(data.shift.opened_at) : '-'}</p>
        </div>
        
        <div class="mb-4">
            <h6><i class="fas fa-chart-line text-success"></i> สรุปยอดขาย</h6>
            <table class="table table-sm">
                <tr><td>จำนวนรายการ</td><td class="text-end">${s.transaction_count || 0}</td></tr>
                <tr><td>ยอดขายรวม</td><td class="text-end">฿${POS.formatNumber(s.total_sales || 0)}</td></tr>
                <tr><td>ยกเลิก</td><td class="text-end">${s.voided_count || 0} รายการ (฿${POS.formatNumber(s.voided_amount || 0)})</td></tr>
                <tr class="table-warning"><td>คืนสินค้า</td><td class="text-end">${returns.count || s.return_count || 0} รายการ (฿${POS.formatNumber(returns.total || s.total_refunds || 0)})</td></tr>
                <tr class="table-success"><td><strong>ยอดสุทธิ</strong></td><td class="text-end"><strong>฿${POS.formatNumber(s.net_sales || 0)}</strong></td></tr>
            </table>
        </div>
        
        ${payments.length > 0 ? `
        <div class="mb-4">
            <h6><i class="fas fa-credit-card text-info"></i> แยกตามวิธีชำระ</h6>
            <table class="table table-sm">
                ${payments.map(p => `<tr><td>${getPaymentMethodLabel(p.payment_method)}</td><td class="text-end">฿${POS.formatNumber(p.total)}</td></tr>`).join('')}
            </table>
        </div>
        ` : ''}
        
        <div class="mb-4">
            <h6><i class="fas fa-money-bill-wave text-success"></i> สรุปเงินสด</h6>
            <table class="table table-sm">
                <tr><td>เงินเปิดกะ</td><td class="text-end">฿${POS.formatNumber(c.opening_cash || 0)}</td></tr>
                <tr><td>รับเงินสด</td><td class="text-end text-success">+฿${POS.formatNumber(c.cash_sales || 0)}</td></tr>
                <tr><td>คืนเงินสด</td><td class="text-end text-danger">-฿${POS.formatNumber(c.cash_refunds || 0)}</td></tr>
                <tr><td>เงินเข้า/ออก</td><td class="text-end ${(c.cash_adjustments || 0) >= 0 ? 'text-success' : 'text-danger'}">${(c.cash_adjustments || 0) >= 0 ? '+' : ''}฿${POS.formatNumber(c.cash_adjustments || 0)}</td></tr>
                <tr class="table-info"><td><strong>เงินที่ควรมี</strong></td><td class="text-end"><strong>฿${POS.formatNumber(c.expected_cash || 0)}</strong></td></tr>
            </table>
        </div>
        
        ${returns.count > 0 || (s.return_count && s.return_count > 0) ? `
        <div class="mb-4">
            <h6><i class="fas fa-undo text-warning"></i> สรุปการคืนสินค้า</h6>
            <div class="alert alert-warning mb-0">
                <div class="d-flex justify-content-between">
                    <span>จำนวนรายการคืน:</span>
                    <strong>${returns.count || s.return_count || 0} รายการ</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>ยอดคืนเงินรวม:</span>
                    <strong>฿${POS.formatNumber(returns.total || s.total_refunds || 0)}</strong>
                </div>
            </div>
        </div>
        ` : ''}
    `;
}

function getPaymentMethodLabel(method) {
    const labels = { 'cash': 'เงินสด', 'transfer': 'โอน/QR', 'card': 'บัตร', 'points': 'แต้ม', 'credit': 'เครดิต' };
    return labels[method] || method;
}

function printShiftSummary() {
    const content = document.getElementById('shiftSummaryContent');
    if (!content) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html><head><title>สรุปกะ</title>
        <style>body{font-family:'Sarabun',sans-serif;padding:20px}table{width:100%;border-collapse:collapse}th,td{padding:8px;text-align:left;border-bottom:1px solid #ddd}.text-end{text-align:right}</style>
        </head><body>${content.innerHTML}</body></html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// =========================================
// History Functions
// =========================================

function showHistoryModal() {
    showModal('historyModal');
    loadTransactionHistory();
}

async function loadTransactionHistory() {
    const container = document.getElementById('historyContent');
    if (!container) return;
    
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">กำลังโหลด...</p></div>';
    
    try {
        const response = await fetch(POS.apiUrl + '?action=transaction_history&shift_id=' + POS.config.shiftId + '&limit=50');
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            const statusLabels = { 
                completed: '<span class="badge bg-success">สำเร็จ</span>', 
                voided: '<span class="badge bg-danger">ยกเลิก</span>',
                refunded: '<span class="badge bg-warning">คืนเงิน</span>',
                draft: '<span class="badge bg-secondary">ร่าง</span>',
                hold: '<span class="badge bg-info">พักไว้</span>'
            };
            
            container.innerHTML = '<div class="history-list">' + data.data.map(tx => `
                <div class="history-item">
                    <div class="history-info">
                        <strong>${tx.transaction_number}</strong>
                        <small class="d-block">${formatThaiDateTime(tx.created_at)}</small>
                        <small class="text-muted">${tx.customer_name || 'Walk-in'}</small>
                    </div>
                    <div class="history-amount">
                        ${statusLabels[tx.status] || tx.status}
                        <strong class="d-block">฿${POS.formatNumber(tx.total_amount)}</strong>
                    </div>
                    <div class="history-actions">
                        ${tx.status === 'completed' ? `
                            <button class="btn btn-sm btn-outline-primary" onclick="reprintFromHistory(${tx.id}, '${tx.transaction_number}')" title="พิมพ์ซ้ำ">
                                <i class="fas fa-print"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning" onclick="returnFromHistory(${tx.id}, '${tx.transaction_number}')" title="คืนสินค้า">
                                <i class="fas fa-undo"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            `).join('') + '</div>';
        } else {
            container.innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-receipt fa-2x mb-2"></i><p>ไม่มีรายการในกะนี้</p></div>';
        }
    } catch (error) {
        console.error('Load history error:', error);
        container.innerHTML = '<div class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>เกิดข้อผิดพลาด</p></div>';
    }
}

function formatThaiDateTime(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + 
           d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

async function reprintFromHistory(transactionId, transactionNumber) {
    try {
        const response = await fetch(POS.apiUrl + '?action=reprint_receipt', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: transactionId })
        });
        const data = await response.json();
        
        if (data.success) {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html><head><title>ใบเสร็จ ${transactionNumber}</title>
                <style>body{font-family:monospace;width:80mm;margin:0 auto;padding:10px}.text-center{text-align:center}.text-right{text-align:right}hr{border:none;border-top:1px dashed #000}</style>
                </head><body>${data.data.html}</body></html>
            `);
            printWindow.document.close();
            printWindow.print();
            showToast('พิมพ์ใบเสร็จซ้ำสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Reprint error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function returnFromHistory(transactionId, transactionNumber) {
    closeModal('historyModal');
    document.getElementById('returnReceiptSearch').value = transactionNumber;
    showModal('returnModal');
    await searchReceiptForReturn();
}

function viewTransaction(id) {
    showToast('กำลังโหลดรายละเอียด...', 'info');
}

// =========================================
// Payment Functions
// =========================================

function selectPaymentMethod(method) {
    document.querySelectorAll('.payment-method').forEach(el => {
        el.classList.toggle('active', el.dataset.method === method);
    });
    
    document.querySelectorAll('.payment-section').forEach(el => {
        el.style.display = 'none';
    });
    
    const section = document.getElementById(method + 'Payment');
    if (section) section.style.display = 'block';
    
    if (method === 'cash') {
        document.getElementById('cashReceived')?.focus();
    }
}

function setCashAmount(amount) {
    const input = document.getElementById('cashReceived');
    const current = parseFloat(input?.value) || 0;
    if (input) input.value = current + amount;
    calculateChange();
}

function setExactAmount() {
    const input = document.getElementById('cashReceived');
    if (input) input.value = Math.ceil(POS.transaction?.total_amount || 0);
    calculateChange();
}

function calculateChange() {
    const total = POS.transaction?.total_amount || 0;
    const received = parseFloat(document.getElementById('cashReceived')?.value) || 0;
    const change = Math.max(0, received - total);
    
    const changeEl = document.getElementById('changeAmount');
    if (changeEl) {
        changeEl.textContent = `฿${POS.formatNumber(change)}`;
        changeEl.style.color = received >= total ? '#4CAF50' : '#f44336';
    }
}

function calculatePointsValue() {
    const points = parseInt(document.getElementById('pointsToUse')?.value) || 0;
    const value = points * 0.1;
    const el = document.getElementById('pointsValue');
    if (el) el.textContent = `฿${POS.formatNumber(value)}`;
}

async function processPayment() {
    const activeMethod = document.querySelector('.payment-method.active')?.dataset.method || 'cash';
    const total = POS.transaction?.total_amount || 0;
    
    let payments = [];
    
    if (activeMethod === 'cash') {
        const received = parseFloat(document.getElementById('cashReceived')?.value) || 0;
        if (received < total) {
            showToast('จำนวนเงินไม่เพียงพอ', 'error');
            return;
        }
        payments.push({
            method: 'cash',
            amount: total,
            cash_received: received,
            change_amount: received - total
        });
    } else if (activeMethod === 'transfer') {
        payments.push({
            method: 'transfer',
            amount: total,
            reference_number: document.getElementById('transferRef')?.value || ''
        });
    } else if (activeMethod === 'card') {
        payments.push({
            method: 'card',
            amount: total,
            reference_number: document.getElementById('cardRef')?.value || ''
        });
    } else if (activeMethod === 'points') {
        const points = parseInt(document.getElementById('pointsToUse')?.value) || 0;
        const pointsValue = points * 0.1;
        if (pointsValue < total) {
            showToast('แต้มไม่เพียงพอ กรุณาชำระส่วนต่างด้วยวิธีอื่น', 'warning');
            return;
        }
        payments.push({
            method: 'points',
            amount: total,
            points_used: Math.ceil(total / 0.1)
        });
    }
    
    const result = await POS.completeTransaction(payments);
    if (result) {
        closeModal('paymentModal');
        showReceipt(result);
    }
}

async function showReceipt(transaction) {
    try {
        const response = await fetch(`${POS.apiUrl}?action=receipt_html&id=${transaction.id}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('receiptPreview').innerHTML = data.data.html;
            const sendLineBtn = document.getElementById('sendLineBtn');
            if (sendLineBtn) sendLineBtn.style.display = transaction.customer_id ? 'inline-block' : 'none';
            showModal('receiptModal');
        }
    } catch (error) {
        console.error('Receipt error:', error);
    }
}

function closeReceiptAndNewSale() {
    closeModal('receiptModal');
    POS.clearCart();
    document.getElementById('productSearch')?.focus();
}

function printReceipt() {
    const content = document.getElementById('receiptPreview');
    if (!content) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html><head><title>ใบเสร็จ</title>
        <style>body{font-family:monospace;width:80mm;margin:0 auto;padding:10px}.text-center{text-align:center}.text-right{text-align:right}hr{border:none;border-top:1px dashed #000}</style>
        </head><body>${content.innerHTML}</body></html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// =========================================
// Discount Functions
// =========================================

async function applyBillDiscount() {
    const type = document.querySelector('input[name="discountType"]:checked')?.value || 'percent';
    const value = parseFloat(document.getElementById('discountValue')?.value) || 0;
    
    if (value <= 0) {
        showToast('กรุณาระบุจำนวนส่วนลด', 'warning');
        return;
    }
    
    try {
        const response = await fetch(POS.apiUrl + '?action=apply_bill_discount', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transaction_id: POS.transaction.id,
                type: type,
                value: value
            })
        });
        const data = await response.json();
        if (data.success) {
            POS.transaction = data.data;
            POS.updateCartDisplay();
            closeModal('discountModal');
            showToast('ใช้ส่วนลดแล้ว', 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Apply discount error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Toast Notification
// =========================================

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;
    
    const colors = { success: '#4CAF50', error: '#f44336', warning: '#ff9800', info: '#2196F3' };
    
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 12px 24px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 9999;
        background: ${colors[type] || colors.info};
        animation: fadeInUp 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'fadeOutDown 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add animation styles
(function() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateX(-50%) translateY(20px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @keyframes fadeOutDown {
            from { opacity: 1; transform: translateX(-50%) translateY(0); }
            to { opacity: 0; transform: translateX(-50%) translateY(20px); }
        }
    `;
    document.head.appendChild(style);
})();


// =========================================
// Hold/Park Transaction Functions
// =========================================

function showHoldModal() {
    if (!POS.transaction || POS.cart.length === 0) {
        showToast('ไม่มีสินค้าในตะกร้า', 'warning');
        return;
    }
    document.getElementById('holdNote').value = '';
    showModal('holdModal');
}

async function confirmHoldTransaction() {
    const note = document.getElementById('holdNote').value;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=hold_transaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transaction_id: POS.transaction.id,
                note: note
            })
        });
        const data = await response.json();
        if (data.success) {
            closeModal('holdModal');
            POS.clearCart();
            showToast('พักบิลสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Hold transaction error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function showHeldTransactions() {
    showModal('heldTransactionsModal');
    const container = document.getElementById('heldTransactionsContent');
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    
    try {
        const response = await fetch(POS.apiUrl + '?action=held_transactions&shift_id=' + POS.config.shiftId);
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            container.innerHTML = data.data.map(tx => `
                <div class="held-item p-3 border rounded mb-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>${tx.transaction_number}</strong>
                            <div class="text-muted small">${tx.customer_name || 'Walk-in'}</div>
                            <div class="text-muted small">${tx.item_count} รายการ | ฿${POS.formatNumber(tx.total_amount)}</div>
                            ${tx.hold_note ? `<div class="text-info small"><i class="fas fa-sticky-note"></i> ${tx.hold_note}</div>` : ''}
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-success" onclick="recallTransaction(${tx.id})">
                                <i class="fas fa-play"></i> เรียกกลับ
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteHeldTransaction(${tx.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="text-center py-4 text-muted"><i class="fas fa-inbox fa-2x mb-2"></i><p>ไม่มีบิลที่พักไว้</p></div>';
        }
    } catch (error) {
        console.error('Load held transactions error:', error);
        container.innerHTML = '<div class="text-center py-4 text-danger">เกิดข้อผิดพลาด</div>';
    }
}

async function recallTransaction(transactionId) {
    if (POS.transaction && POS.cart.length > 0) {
        if (!confirm('มีบิลอยู่แล้ว ต้องการพักบิลปัจจุบันและเรียกบิลนี้กลับมา?')) {
            return;
        }
        // Hold current transaction first
        await fetch(POS.apiUrl + '?action=hold_transaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: POS.transaction.id, note: 'Auto-held' })
        });
    }
    
    try {
        const response = await fetch(POS.apiUrl + '?action=recall_transaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: transactionId })
        });
        const data = await response.json();
        if (data.success) {
            POS.transaction = data.data;
            POS.cart = data.data.items || [];
            POS.updateCartDisplay();
            closeModal('heldTransactionsModal');
            showToast('เรียกบิลกลับสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Recall transaction error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function deleteHeldTransaction(transactionId) {
    if (!confirm('ต้องการลบบิลที่พักไว้นี้?')) return;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=delete_held_transaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: transactionId })
        });
        const data = await response.json();
        if (data.success) {
            showHeldTransactions(); // Refresh list
            showToast('ลบบิลสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Delete held transaction error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Price Override Functions
// =========================================

function showPriceOverrideModal(itemId, itemName, currentPrice) {
    document.getElementById('overrideItemId').value = itemId;
    document.getElementById('overrideItemName').textContent = itemName;
    document.getElementById('overrideOriginalPrice').textContent = '฿' + POS.formatNumber(currentPrice);
    document.getElementById('overrideNewPrice').value = currentPrice;
    document.getElementById('overrideReason').value = '';
    showModal('priceOverrideModal');
}

async function confirmPriceOverride() {
    const itemId = document.getElementById('overrideItemId').value;
    const newPrice = parseFloat(document.getElementById('overrideNewPrice').value) || 0;
    const reason = document.getElementById('overrideReason').value.trim();
    
    if (!reason) {
        showToast('กรุณาระบุเหตุผล', 'warning');
        return;
    }
    
    try {
        const response = await fetch(POS.apiUrl + '?action=override_price', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                item_id: itemId,
                new_price: newPrice,
                reason: reason
            })
        });
        const data = await response.json();
        if (data.success) {
            await POS.refreshTransaction();
            closeModal('priceOverrideModal');
            showToast('แก้ไขราคาสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Price override error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Cash Movement Functions
// =========================================

function showCashMovementModal() {
    document.getElementById('cashMovementAmount').value = '';
    document.getElementById('cashMovementReason').value = '';
    document.getElementById('cashMoveIn').checked = true;
    setCashMovementType('in');
    showModal('cashMovementModal');
}

function setCashMovementType(type) {
    document.getElementById('cashMovementType').value = type;
    document.getElementById('cashMovementTitle').textContent = type === 'in' ? 'เงินเข้าลิ้นชัก' : 'เงินออกจากลิ้นชัก';
}

async function confirmCashMovement() {
    const type = document.getElementById('cashMovementType').value;
    const amount = parseFloat(document.getElementById('cashMovementAmount').value) || 0;
    const reason = document.getElementById('cashMovementReason').value.trim();
    
    if (amount <= 0) {
        showToast('กรุณาระบุจำนวนเงิน', 'warning');
        return;
    }
    
    if (!reason) {
        showToast('กรุณาระบุเหตุผล', 'warning');
        return;
    }
    
    try {
        const action = type === 'in' ? 'cash_in' : 'cash_out';
        const response = await fetch(POS.apiUrl + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shift_id: POS.config.shiftId,
                amount: amount,
                reason: reason
            })
        });
        const data = await response.json();
        if (data.success) {
            closeModal('cashMovementModal');
            showToast(data.message || 'บันทึกสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Cash movement error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Reprint Receipt Functions
// =========================================

let reprintTransactionId = null;

function showReprintModal() {
    document.getElementById('reprintReceiptNumber').value = '';
    document.getElementById('reprintReceiptPreview').style.display = 'none';
    document.getElementById('reprintBtn').disabled = true;
    reprintTransactionId = null;
    showModal('reprintModal');
    loadRecentTransactionsForReprint();
}

async function loadRecentTransactionsForReprint() {
    const container = document.getElementById('recentTransactionsList');
    if (!container) return;
    
    container.innerHTML = '<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i></div>';
    
    try {
        const response = await fetch(POS.apiUrl + '?action=transaction_history&status=completed&limit=10');
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            container.innerHTML = data.data.map(tx => `
                <div class="recent-tx-item" onclick="selectTransactionForReprint(${tx.id}, '${tx.transaction_number}')">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${tx.transaction_number}</strong>
                            <small class="d-block text-muted">${formatThaiDateTime(tx.created_at)}</small>
                        </div>
                        <div class="text-end">
                            <strong>฿${POS.formatNumber(tx.total_amount)}</strong>
                            ${tx.reprint_count > 0 ? `<small class="d-block text-warning">พิมพ์ซ้ำ ${tx.reprint_count} ครั้ง</small>` : ''}
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="text-center py-2 text-muted">ไม่มีรายการ</div>';
        }
    } catch (error) {
        console.error('Load recent transactions error:', error);
        container.innerHTML = '<div class="text-center py-2 text-danger">เกิดข้อผิดพลาด</div>';
    }
}

function selectTransactionForReprint(transactionId, transactionNumber) {
    reprintTransactionId = transactionId;
    document.getElementById('reprintReceiptNumber').value = transactionNumber;
    searchReceiptForReprint();
}

async function searchReceiptForReprint() {
    const number = document.getElementById('reprintReceiptNumber').value.trim();
    if (!number) {
        showToast('กรุณาระบุเลขที่บิล', 'warning');
        return;
    }
    
    try {
        const response = await fetch(POS.apiUrl + '?action=find_transaction&number=' + encodeURIComponent(number));
        const data = await response.json();
        
        if (data.success && data.data) {
            const tx = data.data;
            reprintTransactionId = tx.id;
            
            document.getElementById('reprintTxNumber').textContent = tx.transaction_number;
            document.getElementById('reprintTxDate').textContent = formatThaiDateTime(tx.created_at);
            document.getElementById('reprintTxTotal').textContent = '฿' + POS.formatNumber(tx.total_amount);
            
            const statusLabels = { completed: 'สำเร็จ', voided: 'ยกเลิก', refunded: 'คืนเงิน' };
            const statusColors = { completed: 'text-success', voided: 'text-danger', refunded: 'text-warning' };
            const statusEl = document.getElementById('reprintTxStatus');
            statusEl.textContent = statusLabels[tx.status] || tx.status;
            statusEl.className = statusColors[tx.status] || '';
            
            document.getElementById('reprintReceiptPreview').style.display = 'block';
            document.getElementById('reprintBtn').disabled = false;
        } else {
            showToast(data.message || 'ไม่พบบิล', 'warning');
            document.getElementById('reprintReceiptPreview').style.display = 'none';
            document.getElementById('reprintBtn').disabled = true;
        }
    } catch (error) {
        console.error('Search receipt error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function confirmReprint() {
    if (!reprintTransactionId) return;
    
    try {
        const response = await fetch(POS.apiUrl + '?action=reprint_receipt', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: reprintTransactionId })
        });
        const data = await response.json();
        
        if (data.success) {
            // Open print window
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html><head><title>ใบเสร็จ</title>
                <style>body{font-family:monospace;width:80mm;margin:0 auto;padding:10px}.text-center{text-align:center}.text-right{text-align:right}hr{border:none;border-top:1px dashed #000}</style>
                </head><body>${data.data.html}</body></html>
            `);
            printWindow.document.close();
            printWindow.print();
            
            closeModal('reprintModal');
            showToast('พิมพ์ใบเสร็จซ้ำสำเร็จ', 'success');
        } else {
            showToast(data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Reprint error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Return Functions
// =========================================

let returnTransactionData = null;

function showReturnModal() {
    document.getElementById('returnReceiptSearch').value = '';
    document.getElementById('returnReceiptInfo').style.display = 'none';
    document.getElementById('processReturnBtn').disabled = true;
    returnTransactionData = null;
    showModal('returnModal');
}

async function searchReceiptForReturn() {
    const number = document.getElementById('returnReceiptSearch').value.trim();
    if (!number) {
        showToast('กรุณาระบุเลขที่ใบเสร็จ', 'warning');
        return;
    }
    
    try {
        const response = await fetch(POS.apiUrl + '?action=find_receipt&receipt=' + encodeURIComponent(number));
        const data = await response.json();
        
        if (data.success && data.data) {
            returnTransactionData = data.data;
            
            document.getElementById('returnTxNumber').textContent = data.data.transaction_number;
            document.getElementById('returnTxDate').textContent = new Date(data.data.created_at).toLocaleString('th-TH');
            document.getElementById('returnTxCustomer').textContent = data.data.customer_name || 'Walk-in';
            
            // Display returnable items
            const itemsHtml = data.data.items.map(item => `
                <div class="return-item d-flex align-items-center p-2 border rounded mb-2">
                    <input type="checkbox" class="form-check-input me-3" 
                           id="return_item_${item.id}" 
                           data-item-id="${item.id}"
                           data-price="${item.unit_price}"
                           data-max="${item.returnable_quantity}"
                           onchange="updateReturnTotal()">
                    <div class="flex-grow-1">
                        <div>${item.product_name}</div>
                        <small class="text-muted">฿${POS.formatNumber(item.unit_price)} x คืนได้ ${item.returnable_quantity} ชิ้น</small>
                    </div>
                    <input type="number" class="form-control form-control-sm" style="width: 70px;"
                           id="return_qty_${item.id}" 
                           value="${item.returnable_quantity}" 
                           min="1" max="${item.returnable_quantity}"
                           onchange="updateReturnTotal()">
                </div>
            `).join('');
            
            document.getElementById('returnItemsList').innerHTML = itemsHtml || '<p class="text-muted">ไม่มีสินค้าที่สามารถคืนได้</p>';
            document.getElementById('returnReceiptInfo').style.display = 'block';
            document.getElementById('processReturnBtn').disabled = false;
            
            updateReturnTotal();
        } else {
            showToast(data.message || 'ไม่พบใบเสร็จ', 'warning');
        }
    } catch (error) {
        console.error('Search receipt error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function updateReturnTotal() {
    let total = 0;
    document.querySelectorAll('#returnItemsList input[type="checkbox"]:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const price = parseFloat(cb.dataset.price) || 0;
        const qty = parseInt(document.getElementById('return_qty_' + itemId).value) || 0;
        total += price * qty;
    });
    document.getElementById('returnTotalAmount').textContent = '฿' + POS.formatNumber(total);
}

async function processReturn() {
    if (!returnTransactionData) return;
    
    const reason = document.getElementById('returnReason').value.trim();
    if (!reason) {
        showToast('กรุณาระบุเหตุผลในการคืน', 'warning');
        return;
    }
    
    // Collect selected items
    const items = [];
    document.querySelectorAll('#returnItemsList input[type="checkbox"]:checked').forEach(cb => {
        const itemId = cb.dataset.itemId;
        const qty = parseInt(document.getElementById('return_qty_' + itemId).value) || 0;
        if (qty > 0) {
            items.push({ item_id: parseInt(itemId), quantity: qty });
        }
    });
    
    if (items.length === 0) {
        showToast('กรุณาเลือกสินค้าที่ต้องการคืน', 'warning');
        return;
    }
    
    if (!confirm('ยืนยันการคืนสินค้า?')) return;
    
    try {
        // Create return
        const createResponse = await fetch(POS.apiUrl + '?action=create_return', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transaction_id: returnTransactionData.id,
                items: items,
                reason: reason
            })
        });
        const createData = await createResponse.json();
        
        if (createData.success) {
            // Process return
            const processResponse = await fetch(POS.apiUrl + '?action=process_return', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ return_id: createData.data.id })
            });
            const processData = await processResponse.json();
            
            if (processData.success) {
                closeModal('returnModal');
                showToast('คืนสินค้าสำเร็จ', 'success');
            } else {
                showToast(processData.message || 'เกิดข้อผิดพลาด', 'error');
            }
        } else {
            showToast(createData.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Process return error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}
