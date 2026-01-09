<?php
/**
 * Admin Featured Products Management - Landing Page Settings
 * จัดการสินค้าแนะนำบน Landing Page
 */

$featuredProducts = $featuredProductService->getAllForAdmin();
?>

<div class="card">
    <div class="card-header flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold">🛍️ สินค้าแนะนำ</h3>
            <p class="text-sm text-gray-500">เลือกสินค้าที่จะแสดงบนหน้า Landing Page</p>
        </div>
        <button type="button" onclick="openProductSearchModal()" class="btn btn-primary">
            <i class="fas fa-plus mr-2"></i>เพิ่มสินค้า
        </button>
    </div>
    
    <div class="card-body">
        <?php if (empty($featuredProducts)): ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-box-open text-4xl mb-4 opacity-50"></i>
            <p>ยังไม่ได้เลือกสินค้าแนะนำ</p>
            <p class="text-sm">ระบบจะแสดงสินค้าที่ตั้งค่าเป็น Featured/Bestseller อัตโนมัติ</p>
        </div>
        <?php else: ?>
        <div class="featured-list" id="featuredList">
            <?php foreach ($featuredProducts as $item): ?>
            <div class="featured-item" data-id="<?= $item['id'] ?>">
                <div class="featured-drag-handle">
                    <i class="fas fa-grip-vertical"></i>
                </div>
                
                <div class="featured-preview">
                    <?php if (!empty($item['product_image'])): ?>
                    <img src="<?= htmlspecialchars($item['product_image']) ?>" alt="">
                    <?php else: ?>
                    <div class="featured-placeholder">
                        <i class="fas fa-box"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="featured-info">
                    <div class="featured-name"><?= htmlspecialchars($item['product_name'] ?? 'สินค้าถูกลบ') ?></div>
                    <div class="featured-price">
                        <?php if (!empty($item['price'])): ?>
                        ฿<?= number_format($item['price'], 0) ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="featured-status">
                    <?php if ($item['is_active'] && ($item['product_active'] ?? false)): ?>
                    <span class="badge badge-success">แสดง</span>
                    <?php elseif (!($item['product_active'] ?? true)): ?>
                    <span class="badge badge-warning">สินค้าปิดใช้งาน</span>
                    <?php else: ?>
                    <span class="badge badge-secondary">ซ่อน</span>
                    <?php endif; ?>
                </div>
                
                <div class="featured-actions">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle_featured">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" title="<?= $item['is_active'] ? 'ซ่อน' : 'แสดง' ?>">
                            <i class="fas fa-<?= $item['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                        </button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('ต้องการลบสินค้านี้ออกจากรายการแนะนำ?')">
                        <input type="hidden" name="action" value="remove_featured">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline text-red-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Product Search Modal -->
<div id="productSearchModal" class="modal hidden">
    <div class="modal-backdrop" onclick="closeProductSearchModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>เลือกสินค้าแนะนำ</h3>
            <button type="button" onclick="closeProductSearchModal()" class="modal-close">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="productSearchInput" class="form-control" 
                       placeholder="ค้นหาชื่อสินค้าหรือ SKU..." 
                       oninput="searchProducts(this.value)">
            </div>
            
            <div id="productSearchResults" class="product-search-results">
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-search text-2xl mb-2 opacity-50"></i>
                    <p>พิมพ์เพื่อค้นหาสินค้า</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.featured-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.featured-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    transition: all 0.2s;
}

.featured-item:hover {
    border-color: #06C755;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.featured-drag-handle {
    cursor: grab;
    color: #9ca3af;
    padding: 8px;
}

.featured-preview {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    overflow: hidden;
    background: #e5e7eb;
    flex-shrink: 0;
}

.featured-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.featured-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
}

.featured-info {
    flex: 1;
    min-width: 0;
}

.featured-name {
    font-weight: 600;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.featured-price {
    font-size: 14px;
    color: #06C755;
    font-weight: 500;
    margin-top: 2px;
}

.featured-status {
    flex-shrink: 0;
}

.featured-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

/* Product Search Results */
.product-search-results {
    max-height: 400px;
    overflow-y: auto;
}

.product-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s;
}

.product-result-item:hover {
    background: #f3f4f6;
}

.product-result-image {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    overflow: hidden;
    background: #e5e7eb;
    flex-shrink: 0;
}

.product-result-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-result-info {
    flex: 1;
    min-width: 0;
}

.product-result-name {
    font-weight: 500;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.product-result-sku {
    font-size: 12px;
    color: #6b7280;
}

.product-result-price {
    font-weight: 600;
    color: #06C755;
    flex-shrink: 0;
}

.badge-warning { background: #fef3c7; color: #d97706; }
</style>

<script>
let searchTimeout;

function openProductSearchModal() {
    document.getElementById('productSearchModal').classList.remove('hidden');
    document.getElementById('productSearchInput').focus();
}

function closeProductSearchModal() {
    document.getElementById('productSearchModal').classList.add('hidden');
    document.getElementById('productSearchInput').value = '';
    document.getElementById('productSearchResults').innerHTML = `
        <div class="text-center py-8 text-gray-500">
            <i class="fas fa-search text-2xl mb-2 opacity-50"></i>
            <p>พิมพ์เพื่อค้นหาสินค้า</p>
        </div>
    `;
}

function searchProducts(query) {
    clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        document.getElementById('productSearchResults').innerHTML = `
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-search text-2xl mb-2 opacity-50"></i>
                <p>พิมพ์อย่างน้อย 2 ตัวอักษร</p>
            </div>
        `;
        return;
    }
    
    document.getElementById('productSearchResults').innerHTML = `
        <div class="text-center py-8 text-gray-500">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p>กำลังค้นหา...</p>
        </div>
    `;
    
    searchTimeout = setTimeout(() => {
        fetch(`/api/landing-products.php?action=search&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data.products && data.products.length > 0) {
                    let html = '';
                    data.products.forEach(p => {
                        const source = p.source || 'products';
                        html += `
                            <div class="product-result-item" onclick="addFeaturedProduct(${p.id}, '${source}')">
                                <div class="product-result-image">
                                    ${p.image_url ? `<img src="${p.image_url}" alt="">` : '<i class="fas fa-box" style="margin:12px;color:#9ca3af;"></i>'}
                                </div>
                                <div class="product-result-info">
                                    <div class="product-result-name">${p.name}</div>
                                    <div class="product-result-sku">${p.sku || ''} <span style="font-size:10px;color:#9ca3af;">(${source})</span></div>
                                </div>
                                <div class="product-result-price">฿${Number(p.price || 0).toLocaleString()}</div>
                            </div>
                        `;
                    });
                    document.getElementById('productSearchResults').innerHTML = html;
                } else {
                    document.getElementById('productSearchResults').innerHTML = `
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-box-open text-2xl mb-2 opacity-50"></i>
                            <p>ไม่พบสินค้า</p>
                        </div>
                    `;
                }
            })
            .catch(err => {
                document.getElementById('productSearchResults').innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                        <p>เกิดข้อผิดพลาด</p>
                    </div>
                `;
            });
    }, 300);
}

function addFeaturedProduct(productId, source) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="add_featured">
        <input type="hidden" name="product_id" value="${productId}">
        <input type="hidden" name="product_source" value="${source}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>
