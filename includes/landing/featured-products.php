<?php
/**
 * Featured Products Component - Landing Page
 * แสดงสินค้าแนะนำที่เลือกจากหลังบ้าน
 */

$featuredProducts = $featuredProductService->getFeaturedProducts(8);

// 2026-05-26: ถ้า admin ลบ featured หมด → ซ่อน section ทั้งบล็อค
// (เดิมยังขึ้น title "สินค้าแนะนำจากร้านยา" ค้างอยู่)
if (empty($featuredProducts)) {
    return;
}
?>

<!-- Featured Products Section -->
<section class="featured-products-section" id="featured-products">
    <div class="container">
        <div class="section-title">
            <span class="section-eyebrow">เลือกซื้อได้ทันทีใน LINE</span>
            <h2>สินค้าแนะนำจากร้านยา</h2>
            <p>สินค้าคุณภาพที่คัดไว้ให้เริ่มต้นดูแลสุขภาพได้ง่ายขึ้น</p>
        </div>

        <?php if (!empty($featuredProducts)): ?>
        <div class="products-grid">
            <?php foreach ($featuredProducts as $product): ?>
            <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/product/' . $product['id'] : '#' ?>" 
               class="product-card">
                <div class="product-image">
                    <?php if (!empty($product['image_url'])): ?>
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" 
                         alt="<?= htmlspecialchars($product['name']) ?>"
                         loading="lazy">
                    <?php else: ?>
                    <div class="product-placeholder">
                        <i class="fas fa-box"></i>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']): ?>
                    <span class="product-badge sale">ลดราคา</span>
                    <?php endif; ?>
                </div>
                
                <div class="product-info">
                    <h3 class="product-name"><?= htmlspecialchars($product['name']) ?></h3>
                    
                    <div class="product-price">
                        <?php if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']): ?>
                        <span class="price-sale">฿<?= number_format($product['sale_price'], 0) ?></span>
                        <span class="price-original">฿<?= number_format($product['price'], 0) ?></span>
                        <?php else: ?>
                        <span class="price-current">฿<?= number_format($product['price'], 0) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="product-action">
                        ดูรายละเอียด <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        
        <?php if ($liffUrl): ?>
        <div class="view-all-products">
            <a href="<?= htmlspecialchars($liffUrl) ?>#/shop" class="btn btn-outline-primary">
                <i class="fas fa-th-large"></i>
                ดูสินค้าทั้งหมด
            </a>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="no-products-message">
            <i class="fas fa-box-open"></i>
            <p>กำลังเตรียมสินค้าแนะนำ</p>
            <?php if ($liffUrl): ?>
            <a href="<?= htmlspecialchars($liffUrl) ?>#/shop" class="btn btn-outline-primary">
                <i class="fas fa-shopping-bag"></i>
                เข้าชมร้านค้า
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
/* Featured Products Section */
.featured-products-section {
    padding: 48px 0;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.section-eyebrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(var(--primary-rgb, 17, 176, 166), 0.1);
    color: var(--primary, #11B0A6);
    font-size: 0.8rem;
    font-weight: 700;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

@media (min-width: 640px) {
    .products-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }
}

@media (min-width: 1024px) {
    .products-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
    }
    .featured-products-section {
        padding: 64px 0;
    }
}

/* Product Card */
.product-card {
    background: #fff;
    border-radius: 18px;
    overflow: hidden;
    transition: all 0.3s ease;
    text-decoration: none;
    display: block;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06);
}

.product-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.1);
    border-color: var(--primary);
}

.product-image {
    aspect-ratio: 1;
    background: #f3f4f6;
    position: relative;
    overflow: hidden;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.product-card:hover .product-image img {
    transform: scale(1.05);
}

.product-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    font-size: 32px;
}

.product-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.product-badge.sale {
    background: #ef4444;
    color: white;
}

.product-info {
    padding: 14px;
}

.product-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.4;
}

.product-price {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.product-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    color: var(--primary);
    font-size: 0.85rem;
    font-weight: 700;
}

.price-current,
.price-sale {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--primary);
}

.price-original {
    font-size: 0.85rem;
    color: #9ca3af;
    text-decoration: line-through;
}

/* View All Button */
.view-all-products {
    text-align: center;
    margin-top: 32px;
}

.btn-outline-primary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 28px;
    border: 2px solid var(--primary);
    color: var(--primary);
    background: transparent;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-outline-primary:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
}

/* No Products Message */
.no-products-message {
    text-align: center;
    padding: 48px 20px;
    color: #6b7280;
}

.no-products-message i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.no-products-message p {
    font-size: 1.1rem;
    margin-bottom: 24px;
}
</style>
