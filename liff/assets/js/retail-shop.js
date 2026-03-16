/**
 * Retail Shop Module for LIFF App
 * จัดการร้านค้าปลีก B2C แยกจาก Wholesale
 * 
 * Features:
 * - Product listing (OTC only)
 * - Cart with stock reservation
 * - Checkout with PromptPay/LINE Pay
 * - Order tracking
 */

class RetailShop {
    constructor(liffApp) {
        this.app = liffApp;
        this.config = liffApp.config;
        this.state = {
            products: [],
            categories: [],
            cart: null,
            currentPage: 1,
            hasMore: false,
            isLoading: false,
            selectedCategory: null,
            searchQuery: '',
            sortBy: 'newest'
        };
    }

    /**
     * Get API base URL for retail
     */
    getApiUrl(endpoint) {
        return `${this.config.BASE_URL}/api/${endpoint}`;
    }

    /**
     * Initialize retail shop
     */
    async init() {
        console.log('🏪 Retail Shop initializing...');
        
        // Register routes
        this.registerRoutes();
        
        // Preload categories
        this.loadCategories();
        
        return this;
    }

    /**
     * Register retail routes with router
     */
    registerRoutes() {
        const routes = {
            'retail-shop': () => this.renderShopPage(),
            'retail-product': (params) => this.renderProductDetail(params),
            'retail-cart': () => this.renderCartPage(),
            'retail-checkout': () => this.renderCheckoutPage(),
            'retail-orders': () => this.renderOrdersPage(),
            'retail-order': (params) => this.renderOrderDetail(params)
        };

        Object.entries(routes).forEach(([route, handler]) => {
            window.router.register(route, handler);
        });
    }

    // ============================================================
    // API METHODS
    // ============================================================

    async fetchRetailProducts(params = {}) {
        const query = new URLSearchParams({
            page: params.page || 1,
            limit: 20,
            sort: this.state.sortBy,
            ...params
        });

        if (this.state.searchQuery) {
            query.append('search', this.state.searchQuery);
        }

        if (this.state.selectedCategory) {
            query.append('category', this.state.selectedCategory);
        }

        const response = await fetch(`${this.getApiUrl('retail-products.php')}?${query}`);
        return response.json();
    }

    async fetchCart() {
        const lineUserId = this.app.profile?.userId;
        if (!lineUserId) return { success: false, error: 'Not logged in' };

        const response = await fetch(`${this.getApiUrl('retail-cart.php')}?line_user_id=${lineUserId}`, {
            headers: { 'X-Line-User-Id': lineUserId }
        });
        return response.json();
    }

    async addToCart(productId, qty = 1) {
        const lineUserId = this.app.profile?.userId;
        if (!lineUserId) {
            this.app.showToast('กรุณาเข้าสู่ระบบก่อน', 'error');
            return { success: false };
        }

        const response = await fetch(this.getApiUrl('retail-cart.php'), {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-Line-User-Id': lineUserId
            },
            body: JSON.stringify({ 
                line_user_id: lineUserId,
                product_id: productId, 
                qty 
            })
        });

        const result = await response.json();
        
        if (result.success) {
            this.app.showToast(`เพิ่ม ${result.product?.name || 'สินค้า'} ในตะกร้าแล้ว`, 'success');
            this.updateCartBadge(result.cart_count?.total_qty || 0);
        } else {
            this.app.showToast(result.error || 'เพิ่มไม่สำเร็จ', 'error');
        }

        return result;
    }

    async updateCartItem(cartId, productId, qty) {
        const lineUserId = this.app.profile?.userId;
        
        const response = await fetch(this.getApiUrl('retail-cart.php'), {
            method: 'PUT',
            headers: { 
                'Content-Type': 'application/json',
                'X-Line-User-Id': lineUserId
            },
            body: JSON.stringify({ 
                line_user_id: lineUserId,
                cart_id: cartId,
                product_id: productId,
                qty 
            })
        });

        return response.json();
    }

    async removeFromCart(cartId) {
        const lineUserId = this.app.profile?.userId;
        
        const response = await fetch(`${this.getApiUrl('retail-cart.php')}?line_user_id=${lineUserId}&cart_id=${cartId}`, {
            method: 'DELETE',
            headers: { 'X-Line-User-Id': lineUserId }
        });

        return response.json();
    }

    async checkout(data) {
        const lineUserId = this.app.profile?.userId;
        
        const response = await fetch(this.getApiUrl('retail-checkout.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                line_user_id: lineUserId,
                ...data
            })
        });

        return response.json();
    }

    // ============================================================
    // RENDER METHODS
    // ============================================================

    /**
     * Render Retail Shop Page (Product Listing)
     */
    renderShopPage() {
        // Load initial data
        setTimeout(() => {
            this.loadRetailProducts();
            this.setupShopEventListeners();
        }, 100);

        return `
            <div class="retail-shop-page">
                <!-- Header -->
                <div class="retail-header">
                    <div class="retail-header-top">
                        <h1 class="retail-title">🛒 ร้านค้าปลีก</h1>
                        <button class="retail-cart-btn" onclick="window.router.navigate('/retail-cart')">
                            <i class="fas fa-shopping-cart"></i>
                            <span id="retail-cart-badge" class="cart-badge hidden">0</span>
                        </button>
                    </div>
                    
                    <!-- Search -->
                    <div class="retail-search-container">
                        <i class="fas fa-search retail-search-icon"></i>
                        <input type="text" 
                               id="retail-search-input"
                               class="retail-search-input" 
                               placeholder="ค้นหายา วิตามิน อุปกรณ์การแพทย์..."
                               autocomplete="off">
                    </div>
                    
                    <!-- Categories -->
                    <div id="retail-categories" class="retail-categories">
                        <button class="category-pill active" data-category="">ทั้งหมด</button>
                        ${window.Skeleton ? window.Skeleton.categoryPills(4) : ''}
                    </div>
                </div>

                <!-- Sort Bar -->
                <div class="retail-toolbar">
                    <span id="retail-result-count">กำลังโหลด...</span>
                    <select id="retail-sort" class="retail-sort" onchange="window.retailShop.changeSort(this.value)">
                        <option value="newest">ล่าสุด</option>
                        <option value="price_asc">ราคาต่ำ-สูง</option>
                        <option value="price_desc">ราคาสูง-ต่ำ</option>
                        <option value="bestseller">ขายดี</option>
                    </select>
                </div>

                <!-- Product Grid -->
                <div id="retail-product-grid" class="retail-product-grid">
                    ${this.renderProductSkeletons(6)}
                </div>

                <!-- Load More -->
                <div id="retail-load-more" class="retail-load-more hidden">
                    <button class="btn-load-more" onclick="window.retailShop.loadMore()">
                        โหลดเพิ่ม
                    </button>
                </div>

                <!-- Bottom Cart Bar -->
                <div id="retail-cart-bar" class="retail-cart-bar hidden">
                    <div class="cart-bar-info">
                        <span class="cart-bar-count">0 รายการ</span>
                        <span class="cart-bar-total">฿0</span>
                    </div>
                    <button class="btn-cart-checkout" onclick="window.router.navigate('/retail-cart')">
                        ดูตะกร้า <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Render Product Card
     */
    renderProductCard(product) {
        const hasStock = product.available_qty > 0;
        const isLowStock = product.available_qty <= 5 && product.available_qty > 0;
        
        return `
            <div class="retail-product-card ${!hasStock ? 'out-of-stock' : ''}" data-id="${product.id}">
                <div class="product-image" onclick="window.router.navigate('/retail-product/${product.id}')">
                    <img src="${product.thumbnail_url || '/assets/images/product-placeholder.png'}" 
                         alt="${product.name}" 
                         loading="lazy">
                    ${product.is_bestseller ? '<span class="badge bestseller">ขายดี</span>' : ''}
                    ${product.is_new_arrival ? '<span class="badge new">ใหม่</span>' : ''}
                    ${!hasStock ? '<div class="out-of-stock-overlay">หมดสต็อก</div>' : ''}
                </div>
                <div class="product-info">
                    <div class="product-category">${product.category_name || product.category_code || ''}</div>
                    <h3 class="product-name" onclick="window.router.navigate('/retail-product/${product.id}')">
                        ${product.name}
                    </h3>
                    ${product.brand ? `<div class="product-brand">${product.brand}</div>` : ''}
                    <div class="product-footer">
                        <div class="product-price">
                            <span class="price">฿${parseFloat(product.retail_price).toLocaleString()}</span>
                            ${product.unit_of_measure ? `<span class="unit">/${product.unit_of_measure}</span>` : ''}
                        </div>
                        ${hasStock ? `
                            <button class="btn-add-cart" onclick="window.retailShop.addToCart(${product.id}, 1); event.stopPropagation();">
                                <i class="fas fa-plus"></i>
                            </button>
                        ` : `
                            <button class="btn-add-cart disabled" disabled>
                                <i class="fas fa-times"></i>
                            </button>
                        `}
                    </div>
                    ${isLowStock ? `<div class="low-stock">เหลือ ${product.available_qty} ชิ้น</div>` : ''}
                </div>
            </div>
        `;
    }

    /**
     * Render Product Detail Page
     */
    renderProductDetail(params) {
        const productId = params.id || params;
        
        // Load product data
        setTimeout(() => this.loadProductDetail(productId), 100);
        
        return `
            <div class="retail-product-detail-page">
                <div class="detail-header">
                    <button class="btn-back" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <span class="detail-title">รายละเอียดสินค้า</span>
                    <button class="btn-share" onclick="window.retailShop.shareProduct()">
                        <i class="fas fa-share-alt"></i>
                    </button>
                </div>
                
                <div id="product-detail-content" class="product-detail-content">
                    ${this.renderDetailSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render Cart Page
     */
    renderCartPage() {
        setTimeout(() => this.loadCart(), 100);
        
        return `
            <div class="retail-cart-page">
                <div class="cart-header">
                    <button class="btn-back" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1>ตะกร้าสินค้า</h1>
                </div>
                
                <div id="cart-content" class="cart-content">
                    ${this.renderCartSkeleton()}
                </div>
            </div>
        `;
    }

    /**
     * Render Checkout Page
     */
    renderCheckoutPage() {
        setTimeout(() => this.loadCheckoutData(), 100);
        
        return `
            <div class="retail-checkout-page">
                <div class="checkout-header">
                    <button class="btn-back" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1>ชำระเงิน</h1>
                </div>
                
                <div id="checkout-content" class="checkout-content">
                    <form id="checkout-form" onsubmit="window.retailShop.handleCheckout(event)">
                        <!-- Shipping Address -->
                        <section class="checkout-section">
                            <h3><i class="fas fa-map-marker-alt"></i> ที่อยู่จัดส่ง</h3>
                            <div class="form-group">
                                <label>ชื่อ-นามสกุล *</label>
                                <input type="text" name="shipping_name" required 
                                       value="${this.app.profile?.displayName || ''}">
                            </div>
                            <div class="form-group">
                                <label>เบอร์โทรศัพท์ *</label>
                                <input type="tel" name="shipping_phone" required 
                                       placeholder="081-234-5678">
                            </div>
                            <div class="form-group">
                                <label>ที่อยู่ *</label>
                                <textarea name="shipping_address" rows="2" required
                                          placeholder="บ้านเลขที่ ถนน ซอย"></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>ตำบล/แขวง</label>
                                    <input type="text" name="shipping_district">
                                </div>
                                <div class="form-group">
                                    <label>อำเภอ/เขต</label>
                                    <input type="text" name="shipping_city">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>จังหวัด *</label>
                                    <select name="shipping_province" required>
                                        <option value="">เลือกจังหวัด</option>
                                        ${this.renderProvinceOptions()}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>รหัสไปรษณีย์ *</label>
                                    <input type="text" name="shipping_zip" required 
                                           pattern="[0-9]{5}" maxlength="5">
                                </div>
                            </div>
                        </section>

                        <!-- Shipping Method -->
                        <section class="checkout-section">
                            <h3><i class="fas fa-truck"></i> การจัดส่ง</h3>
                            <div class="shipping-options">
                                <label class="shipping-option">
                                    <input type="radio" name="shipping_method" value="kerry" checked>
                                    <div class="option-content">
                                        <span class="option-name">Kerry Express</span>
                                        <span class="option-price">฿50</span>
                                    </div>
                                </label>
                                <label class="shipping-option">
                                    <input type="radio" name="shipping_method" value="flash">
                                    <div class="option-content">
                                        <span class="option-name">Flash Express</span>
                                        <span class="option-price">฿45</span>
                                    </div>
                                </label>
                                <label class="shipping-option">
                                    <input type="radio" name="shipping_method" value="thaipost">
                                    <div class="option-content">
                                        <span class="option-name">Thailand Post</span>
                                        <span class="option-price">฿40</span>
                                    </div>
                                </label>
                            </div>
                        </section>

                        <!-- Payment Method -->
                        <section class="checkout-section">
                            <h3><i class="fas fa-credit-card"></i> ชำระเงิน</h3>
                            <div class="payment-options">
                                <label class="payment-option">
                                    <input type="radio" name="payment_method" value="promptpay" checked>
                                    <div class="option-content">
                                        <i class="fas fa-qrcode"></i>
                                        <span>PromptPay QR</span>
                                    </div>
                                </label>
                                <label class="payment-option">
                                    <input type="radio" name="payment_method" value="cod">
                                    <div class="option-content">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <span>เก็บเงินปลายทาง (COD)</span>
                                    </div>
                                </label>
                            </div>
                        </section>

                        <!-- Order Summary -->
                        <section class="checkout-section summary">
                            <h3>สรุปคำสั่งซื้อ</h3>
                            <div id="checkout-summary" class="summary-details">
                                ${this.renderCheckoutSummarySkeleton()}
                            </div>
                        </section>

                        <button type="submit" class="btn-checkout-submit">
                            ยืนยันคำสั่งซื้อ
                        </button>
                    </form>
                </div>
            </div>
        `;
    }

    /**
     * Render Orders Page
     */
    renderOrdersPage() {
        setTimeout(() => this.loadOrders(), 100);
        
        return `
            <div class="retail-orders-page">
                <div class="orders-header">
                    <button class="btn-back" onclick="window.router.back()">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <h1>คำสั่งซื้อของฉัน</h1>
                </div>
                
                <div id="orders-list" class="orders-list">
                    ${this.renderOrdersSkeleton()}
                </div>
            </div>
        `;
    }

    // ============================================================
    // DATA LOADING METHODS
    // ============================================================

    async loadCategories() {
        try {
            const response = await fetch(`${this.getApiUrl('retail-products.php')}?action=categories`);
            const data = await response.json();
            
            if (data.success) {
                this.state.categories = data.categories;
                this.renderCategories();
            }
        } catch (error) {
            console.error('Error loading categories:', error);
        }
    }

    async loadRetailProducts(reset = true) {
        if (this.state.isLoading) return;
        
        this.state.isLoading = true;
        
        if (reset) {
            this.state.currentPage = 1;
            this.state.products = [];
        }
        
        try {
            const data = await this.fetchRetailProducts({ page: this.state.currentPage });
            
            if (data.success) {
                if (reset) {
                    this.state.products = data.products;
                } else {
                    this.state.products.push(...data.products);
                }
                
                this.state.hasMore = data.pagination.has_more;
                this.state.total = data.pagination.total;
                
                this.renderProducts(reset);
                this.updateResultCount(data.pagination.total);
            }
        } catch (error) {
            console.error('Error loading products:', error);
        } finally {
            this.state.isLoading = false;
        }
    }

    async loadMore() {
        if (this.state.hasMore && !this.state.isLoading) {
            this.state.currentPage++;
            await this.loadRetailProducts(false);
        }
    }

    async loadProductDetail(productId) {
        try {
            const response = await fetch(`${this.getApiUrl('retail-products.php')}?product_id=${productId}`);
            const data = await response.json();
            
            if (data.success) {
                this.renderProductDetailContent(data.product, data.related_products);
            }
        } catch (error) {
            console.error('Error loading product detail:', error);
        }
    }

    async loadCart() {
        try {
            const data = await this.fetchCart();
            
            if (data.success) {
                this.state.cart = data.cart;
                this.renderCartContent(data.cart);
            } else {
                this.renderEmptyCart();
            }
        } catch (error) {
            console.error('Error loading cart:', error);
            this.renderEmptyCart();
        }
    }

    async loadCheckoutData() {
        // Load cart summary for checkout
        const data = await this.fetchCart();
        if (data.success) {
            this.renderCheckoutSummary(data.cart);
        }
    }

    // ============================================================
    // EVENT HANDLERS
    // ============================================================

    setupShopEventListeners() {
        // Search with debounce
        const searchInput = document.getElementById('retail-search-input');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.state.searchQuery = e.target.value;
                    this.loadRetailProducts(true);
                }, 500);
            });
        }

        // Category filter
        document.querySelectorAll('#retail-categories .category-pill').forEach(pill => {
            pill.addEventListener('click', (e) => {
                document.querySelectorAll('#retail-categories .category-pill').forEach(p => p.classList.remove('active'));
                e.target.classList.add('active');
                
                this.state.selectedCategory = e.target.dataset.category;
                this.loadRetailProducts(true);
            });
        });
    }

    changeSort(sortValue) {
        this.state.sortBy = sortValue;
        this.loadRetailProducts(true);
    }

    async handleCheckout(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        
        const data = {
            shipping: {
                name: formData.get('shipping_name'),
                phone: formData.get('shipping_phone'),
                address: formData.get('shipping_address'),
                district: formData.get('shipping_district'),
                city: formData.get('shipping_city'),
                province: formData.get('shipping_province'),
                zip: formData.get('shipping_zip')
            },
            payment_method: formData.get('payment_method'),
            shipping_method: formData.get('shipping_method')
        };
        
        // Show loading
        const submitBtn = form.querySelector('.btn-checkout-submit');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';
        
        try {
            const result = await this.checkout(data);
            
            if (result.success) {
                // Redirect to order detail or payment page
                window.router.navigate(`/retail-order/${result.order.id}`);
            } else {
                this.app.showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'ยืนยันคำสั่งซื้อ';
            }
        } catch (error) {
            this.app.showToast('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'ยืนยันคำสั่งซื้อ';
        }
    }

    // ============================================================
    // HELPER RENDER METHODS
    // ============================================================

    renderCategories() {
        const container = document.getElementById('retail-categories');
        if (!container || !this.state.categories.length) return;
        
        container.innerHTML = `
            <button class="category-pill active" data-category="">ทั้งหมด</button>
            ${this.state.categories.map(cat => `
                <button class="category-pill" data-category="${cat.id}">
                    ${cat.icon || ''} ${cat.name}
                </button>
            `).join('')}
        `;
    }

    renderProducts(reset) {
        const grid = document.getElementById('retail-product-grid');
        if (!grid) return;
        
        const html = this.state.products.map(p => this.renderProductCard(p)).join('');
        
        if (reset) {
            grid.innerHTML = html || this.renderEmptyProducts();
        } else {
            grid.insertAdjacentHTML('beforeend', html);
        }
        
        // Show/hide load more
        const loadMore = document.getElementById('retail-load-more');
        if (loadMore) {
            loadMore.classList.toggle('hidden', !this.state.hasMore);
        }
    }

    updateResultCount(count) {
        const el = document.getElementById('retail-result-count');
        if (el) {
            el.textContent = `${count} รายการ`;
        }
    }

    updateCartBadge(count) {
        const badge = document.getElementById('retail-cart-badge');
        if (badge) {
            badge.textContent = count;
            badge.classList.toggle('hidden', count <= 0);
        }
    }

    renderProvinceOptions() {
        const provinces = [
            'กรุงเทพมหานคร', 'กระบี่', 'กาญจนบุรี', 'กาฬสินธุ์', 'กำแพงเพชร',
            'ขอนแก่น', 'จันทบุรี', 'ฉะเชิงเทรา', 'ชลบุรี', 'ชัยนาท',
            'ชัยภูมิ', 'ชุมพร', 'เชียงราย', 'เชียงใหม่', 'ตรัง', 'ตราด',
            'ตาก', 'นครนายก', 'นครปฐม', 'นครพนม', 'นครราชสีมา',
            'นครศรีธรรมราช', 'นครสวรรค์', 'นนทบุรี', 'นราธิวาส', 'น่าน',
            'บุรีรัมย์', 'ปทุมธานี', 'ประจวบคีรีขันธ์', 'ปราจีนบุรี', 'ปัตตานี',
            'พระนครศรีอยุธยา', 'พะเยา', 'พังงา', 'พัทลุง', 'พิจิตร',
            'พิษณุโลก', 'เพชรบุรี', 'เพชรบูรณ์', 'แพร่', 'ภูเก็ต',
            'มหาสารคาม', 'มุกดาหาร', 'แม่ฮ่องสอน', 'ยโสธร', 'ยะลา',
            'ร้อยเอ็ด', 'ระนอง', 'ระยอง', 'ราชบุรี', 'ลพบุรี',
            'ลำปาง', 'ลำพูน', 'เลย', 'ศรีสะเกษ', 'สกลนคร',
            'สงขลา', 'สตูล', 'สมุทรปราการ', 'สมุทรสงคราม', 'สมุทรสาคร',
            'สระแก้ว', 'สระบุรี', 'สิงห์บุรี', 'สุโขทัย', 'สุพรรณบุรี',
            'สุราษฎร์ธานี', 'สุรินทร์', 'หนองคาย', 'หนองบัวลำภู', 'อ่างทอง',
            'อำนาจเจริญ', 'อุดรธานี', 'อุตรดิตถ์', 'อุทัยธานี', 'อุบลราชธานี'
        ];
        
        return provinces.map(p => `<option value="${p}">${p}</option>`).join('');
    }

    // Skeleton loaders
    renderProductSkeletons(count) {
        return Array(count).fill(0).map(() => `
            <div class="retail-product-card skeleton">
                <div class="skeleton-image"></div>
                <div class="skeleton-text"></div>
                <div class="skeleton-text short"></div>
            </div>
        `).join('');
    }

    renderDetailSkeleton() {
        return `
            <div class="skeleton-detail">
                <div class="skeleton-detail-image"></div>
                <div class="skeleton-detail-text"></div>
                <div class="skeleton-detail-text short"></div>
            </div>
        `;
    }

    renderCartSkeleton() {
        return `
            <div class="cart-skeleton">
                <div class="skeleton-cart-item"></div>
                <div class="skeleton-cart-item"></div>
                <div class="skeleton-cart-item"></div>
            </div>
        `;
    }

    renderCheckoutSummarySkeleton() {
        return `
            <div class="summary-skeleton">
                <div class="skeleton-line"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line total"></div>
            </div>
        `;
    }

    renderOrdersSkeleton() {
        return `
            <div class="orders-skeleton">
                <div class="skeleton-order"></div>
                <div class="skeleton-order"></div>
            </div>
        `;
    }

    renderEmptyProducts() {
        return `
            <div class="empty-products">
                <i class="fas fa-search"></i>
                <p>ไม่พบสินค้า</p>
            </div>
        `;
    }

    // Placeholder methods for full implementation
    renderProductDetailContent(product, related) {
        // Full implementation needed
        const container = document.getElementById('product-detail-content');
        if (container) {
            container.innerHTML = `
                <div class="product-images">
                    <img src="${product.thumbnail_url}" alt="${product.name}">
                </div>
                <div class="product-info">
                    <h1>${product.name}</h1>
                    <div class="product-price">฿${parseFloat(product.retail_price).toLocaleString()}</div>
                    <div class="product-description">${product.description || product.short_description || ''}</div>
                    <button class="btn-add-cart-large" onclick="window.retailShop.addToCart(${product.id}, 1)">
                        <i class="fas fa-cart-plus"></i> เพิ่มในตะกร้า
                    </button>
                </div>
            `;
        }
    }

    renderCartContent(cart) {
        // Full implementation needed
        const container = document.getElementById('cart-content');
        if (!container) return;

        if (!cart.items || cart.items.length === 0) {
            this.renderEmptyCart();
            return;
        }

        const itemsHtml = cart.items.map(item => `
            <div class="cart-item">
                <img src="${item.thumbnail_url}" alt="${item.name}">
                <div class="cart-item-info">
                    <h4>${item.name}</h4>
                    <div class="cart-item-price">฿${parseFloat(item.unit_price).toLocaleString()}</div>
                </div>
                <div class="cart-item-qty">
                    <button onclick="window.retailShop.updateCartItem(${item.cart_id}, ${item.product_id}, ${item.qty - 1})">-</button>
                    <span>${item.qty}</span>
                    <button onclick="window.retailShop.updateCartItem(${item.cart_id}, ${item.product_id}, ${item.qty + 1})">+</button>
                </div>
            </div>
        `).join('');

        container.innerHTML = `
            <div class="cart-items">${itemsHtml}</div>
            <div class="cart-footer">
                <div class="cart-total">
                    <span>รวม</span>
                    <span class="total-price">${cart.summary.subtotal_formatted}</span>
                </div>
                <button class="btn-checkout" onclick="window.router.navigate('/retail-checkout')">
                    ชำระเงิน
                </button>
            </div>
        `;
    }

    renderEmptyCart() {
        const container = document.getElementById('cart-content');
        if (container) {
            container.innerHTML = `
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <p>ตะกร้าว่างเปล่า</p>
                    <button class="btn-shop-now" onclick="window.router.navigate('/retail-shop')">
                        ช็อปเลย
                    </button>
                </div>
            `;
        }
    }

    renderCheckoutSummary(cart) {
        const container = document.getElementById('checkout-summary');
        if (!container || !cart) return;

        const shipping = 50; // Default
        const total = parseFloat(cart.summary.subtotal) + shipping;

        container.innerHTML = `
            <div class="summary-row">
                <span>สินค้า (${cart.summary.total_items} รายการ)</span>
                <span>${cart.summary.subtotal_formatted}</span>
            </div>
            <div class="summary-row">
                <span>ค่าจัดส่ง</span>
                <span>฿${shipping}</span>
            </div>
            <div class="summary-row total">
                <span>รวมทั้งสิ้น</span>
                <span class="total-amount">฿${total.toLocaleString()}</span>
            </div>
        `;
    }

    async loadOrders() {
        // Placeholder - implement order history loading
        const container = document.getElementById('orders-list');
        if (container) {
            container.innerHTML = `
                <div class="empty-orders">
                    <i class="fas fa-box"></i>
                    <p>ยังไม่มีคำสั่งซื้อ</p>
                </div>
            `;
        }
    }

    shareProduct() {
        if (liff.isInClient()) {
            // Share via LINE
            const product = this.currentProduct; // Need to store current product
            if (product) {
                liff.shareTargetPicker([{
                    type: 'text',
                    text: `สินค้าน่าสนใจ: ${product.name}\nราคา ฿${product.retail_price}\n${window.location.href}`
                }]);
            }
        }
    }
}

// Initialize when LIFF app is ready
window.initRetailShop = function(liffApp) {
    window.retailShop = new RetailShop(liffApp);
    window.retailShop.init();
};
