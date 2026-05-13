<?php
/**
 * Product Detail - Business Items
 * Display single product details from business_items table
 * UI เหมือน product-detail-cny.php
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Get product ID or SKU from URL
$productId = $_GET['id'] ?? '';
$sku       = $_GET['sku'] ?? '';

if (empty($productId) && empty($sku)) {
    header('Location: products.php');
    exit;
}

// Determine table
$productsTable = 'business_items';
try {
    $db->query("SELECT 1 FROM {$productsTable} LIMIT 1");
} catch (PDOException $e) {
    $productsTable = 'products';
}

// Fetch product from database
if ($productId) {
    $stmt = $db->prepare("SELECT * FROM {$productsTable} WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
} else {
    $stmt = $db->prepare("SELECT * FROM {$productsTable} WHERE sku = :sku LIMIT 1");
    $stmt->execute([':sku' => $sku]);
}
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: products.php');
    exit;
}

// Decode JSON fields
if (!empty($product['product_price']) && is_string($product['product_price'])) {
    $product['product_price'] = json_decode($product['product_price'], true);
}
if (!is_array($product['product_price'] ?? null)) {
    $product['product_price'] = [];
}

$pageTitle = $product['name'] ?? 'รายละเอียดสินค้า';

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/header.php';

$stock    = (int)($product['stock'] ?? 0);
$inStock  = $stock > 0;
$imageUrl = $product['photo_path'] ?? $product['image_url'] ?? '';
?>

<?= getPageHeaderStyles() ?>

<style>
.detail-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    overflow: hidden;
    margin-bottom: var(--space-6);
}
.detail-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-6);
    padding: var(--space-6);
}
.product-img-wrap {
    aspect-ratio: 1/1;
    background: var(--color-slate-100);
    border-radius: var(--radius-md);
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.product-img-wrap img { width:100%; height:100%; object-fit:contain; }
.product-img-placeholder { font-size:96px; color:var(--color-slate-300); }
.product-info-title {
    font-size: var(--text-3xl); font-weight: 700;
    color: var(--color-dark-800); margin: 0 0 var(--space-2); line-height: 1.2;
}
.product-info-name-en { font-size:var(--text-lg); color:var(--color-dark-500); margin:0 0 var(--space-2); }
.product-info-generic  { font-size:var(--text-sm); color:var(--color-primary-600); font-weight:500; }
.product-info-mfr      { font-size:var(--text-sm); color:var(--color-dark-500); margin-top:var(--space-1); }
.stock-pill {
    display: inline-flex; align-items: center; gap: var(--space-2);
    padding: 10px var(--space-4); border-radius: var(--radius-md);
    font-weight: 500; font-size: var(--text-sm); margin-bottom: var(--space-6);
}
.stock-pill-in  { background: var(--color-emerald-50); color: var(--color-emerald-700); }
.stock-pill-low { background: var(--color-amber-50);   color: var(--color-amber-700); }
.stock-pill-out { background: var(--color-rose-50);    color: var(--color-rose-700); }
.price-row { display: flex; align-items: baseline; gap: var(--space-3); margin-bottom: 4px; }
.price-main         { font-size: var(--text-3xl); font-weight: 700; }
.price-sale         { color: var(--color-rose-600); }
.price-normal       { color: var(--color-primary-600); }
.price-strike       { font-size: var(--text-xl); color: var(--color-slate-400); text-decoration: line-through; }
.price-unit         { font-size: var(--text-sm); color: var(--color-dark-500); margin-top: 4px; }
.price-group-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: var(--space-3); background: var(--color-slate-50);
    border-radius: var(--radius-md); margin-bottom: var(--space-2);
}
.price-group-label { font-weight: 500; color: var(--color-dark-800); font-size: var(--text-sm); }
.price-group-unit  { font-size: var(--text-xs); color: var(--color-dark-500); }
.price-group-val   { font-size: var(--text-2xl); font-weight: 700; color: var(--color-primary-600); }
.detail-tab-bar {
    display: flex; border-bottom: 1px solid var(--color-slate-200); overflow-x: auto;
}
.detail-tab-btn {
    padding: var(--space-3) var(--space-5); font-size: var(--text-sm); font-weight: 500;
    color: var(--color-dark-500); border: none; background: transparent;
    cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap;
    transition: all var(--transition-fast);
}
.detail-tab-btn:hover  { color: var(--color-primary-600); }
.detail-tab-btn.active { color: var(--color-primary-600); border-bottom-color: var(--color-primary-600); font-weight: 600; }
.detail-tab-content { padding: var(--space-6); }
.tab-pane.hidden    { display: none; }
.prose { color: var(--color-dark-800); line-height: 1.7; font-size: var(--text-sm); }
@media (max-width: 768px) { .detail-layout { grid-template-columns: 1fr; } }
.dark .detail-card         { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .product-img-wrap    { background: var(--color-dark-700); }
.dark .product-info-title  { color: var(--color-slate-100); }
.dark .product-info-name-en,
.dark .product-info-mfr    { color: var(--color-slate-400); }
.dark .price-group-row     { background: var(--color-dark-700); }
.dark .price-group-label   { color: var(--color-slate-100); }
.dark .detail-tab-bar      { border-color: var(--color-dark-700); }
.dark .detail-tab-btn      { color: var(--color-slate-400); }
.dark .prose               { color: var(--color-slate-200); }
</style>

<?php
echo renderPageHeader(
    $product['name'] ?? 'รายละเอียดสินค้า',
    '',
    [
        'label'   => 'แก้ไขสินค้า',
        'icon'    => 'fas fa-edit',
        'href'    => '/inventory?tab=products&search=' . urlencode($product['sku'] ?? $product['name']),
        'type'    => 'link',
        'variant' => 'primary',
    ],
    [
        ['label' => 'ร้านค้า', 'href' => null],
        ['label' => 'สินค้า',  'href' => 'products.php'],
        ['label' => mb_substr($product['name'] ?? 'รายละเอียด', 0, 30), 'href' => null],
    ]
);
?>

<div class="detail-card">
    <div class="detail-layout">
        <!-- Product Image -->
        <div class="product-img-wrap">
            <?php if (!empty($imageUrl)): ?>
            <img src="<?= htmlspecialchars($imageUrl) ?>"
                 alt="<?= htmlspecialchars($product['name']) ?>"
                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 400%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22400%22 height=%22400%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2230%22%3ENo Image%3C/text%3E%3C/svg%3E'">
            <?php else: ?>
            <div class="product-img-placeholder"><i class="fas fa-pills"></i></div>
            <?php endif; ?>
        </div>

        <!-- Product Info -->
        <div>
            <div style="margin-bottom:var(--space-4);">
                <?php if (!empty($product['sku'])): ?>
                <div style="font-size:var(--text-sm);color:var(--color-dark-500);margin-bottom:var(--space-2);">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                <?php endif; ?>
                <?php if (!empty($product['barcode'])): ?>
                <div style="font-size:var(--text-sm);color:var(--color-dark-500);margin-bottom:var(--space-2);">Barcode: <?= htmlspecialchars($product['barcode']) ?></div>
                <?php endif; ?>
                <h1 class="product-info-title"><?= htmlspecialchars($product['name']) ?></h1>
                <?php if (!empty($product['name_en'])): ?>
                <p class="product-info-name-en"><?= htmlspecialchars($product['name_en']) ?></p>
                <?php endif; ?>
                <?php if (!empty($product['generic_name'])): ?>
                <p class="product-info-generic"><?= htmlspecialchars($product['generic_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($product['manufacturer'])): ?>
                <p class="product-info-mfr">
                    <i class="fas fa-industry" style="margin-right:4px;"></i><?= htmlspecialchars($product['manufacturer']) ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Stock Status -->
            <div>
                <?php if ($inStock): ?>
                    <?php if ($stock <= 5): ?>
                    <div class="stock-pill stock-pill-low">
                        <i class="fas fa-exclamation-triangle"></i>
                        สินค้าใกล้หมด (คงเหลือ <?= number_format($stock) ?> หน่วย)
                    </div>
                    <?php else: ?>
                    <div class="stock-pill stock-pill-in">
                        <i class="fas fa-check-circle"></i>
                        มีสินค้า (คงเหลือ <?= number_format($stock) ?> หน่วย)
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="stock-pill stock-pill-out">
                    <i class="fas fa-times-circle"></i>สินค้าหมด
                </div>
                <?php endif; ?>
            </div>

            <!-- Prices -->
            <?php if (!empty($product['product_price'])): ?>
            <div style="margin-bottom:var(--space-6);">
                <h3 style="font-weight:600;color:var(--color-dark-800);margin:0 0 var(--space-3);font-size:var(--text-base);">ราคาตามกลุ่มลูกค้า</h3>
                <?php foreach ($product['product_price'] as $priceInfo):
                    if (($priceInfo['enable'] ?? '1') !== '1') continue;
                ?>
                <div class="price-group-row">
                    <div>
                        <div class="price-group-label"><?= htmlspecialchars($priceInfo['customer_group'] ?? 'ทั่วไป') ?></div>
                        <div class="price-group-unit"><?= htmlspecialchars($priceInfo['unit'] ?? '') ?></div>
                    </div>
                    <div class="price-group-val">฿<?= number_format((float)($priceInfo['price'] ?? 0), 2) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- Single Price -->
            <div style="margin-bottom:var(--space-6);">
                <?php if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']): ?>
                <div class="price-row">
                    <div class="price-main price-sale">฿<?= number_format($product['sale_price'], 2) ?></div>
                    <div class="price-strike">฿<?= number_format($product['price'], 2) ?></div>
                </div>
                <?php else: ?>
                <div class="price-row">
                    <div class="price-main price-normal">฿<?= number_format($product['price'] ?? 0, 2) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($product['unit'])): ?>
                <div class="price-unit"><?= htmlspecialchars($product['unit']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Detail Tabs -->
    <div style="border-top:1px solid var(--color-slate-200);">
        <div class="detail-tab-bar">
            <button class="detail-tab-btn active" data-tab="description">
                <i class="fas fa-info-circle" style="margin-right:var(--space-2);"></i>รายละเอียด
            </button>
            <button class="detail-tab-btn" data-tab="usage">
                <i class="fas fa-pills" style="margin-right:var(--space-2);"></i>วิธีใช้
            </button>
            <button class="detail-tab-btn" data-tab="properties">
                <i class="fas fa-flask" style="margin-right:var(--space-2);"></i>สรรพคุณ
            </button>
        </div>

        <div class="detail-tab-content">
            <!-- Description Tab -->
            <div class="tab-pane" id="description">
                <?php
                $description = $product['description'] ?? '';
                if (!empty($description)):
                    if (stripos($description, '<!doctype') !== false || stripos($description, '<html') !== false):
                        $iframeId = 'desc-iframe-' . uniqid();
                ?>
                    <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($description) ?>"
                            style="width:100%;min-height:600px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);"
                            sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                <?php else: ?>
                    <div class="prose"><?= nl2br(htmlspecialchars($description)) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <p style="color:var(--color-dark-500);">ไม่มีรายละเอียด</p>
                <?php endif; ?>
            </div>

            <!-- Usage Tab -->
            <div class="tab-pane hidden" id="usage">
                <?php
                $usageInstructions = $product['usage_instructions'] ?? $product['how_to_use'] ?? '';
                if (!empty($usageInstructions)):
                    if (stripos($usageInstructions, '<!doctype') !== false || stripos($usageInstructions, '<html') !== false):
                        $iframeId = 'usage-iframe-' . uniqid();
                ?>
                    <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($usageInstructions) ?>"
                            style="width:100%;min-height:600px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);"
                            sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                <?php else: ?>
                    <div class="prose"><?= nl2br(htmlspecialchars($usageInstructions)) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <p style="color:var(--color-dark-500);">ไม่มีข้อมูลวิธีใช้</p>
                <?php endif; ?>
            </div>

            <!-- Properties Tab -->
            <div class="tab-pane hidden" id="properties">
                <?php
                $propertiesOther = $product['properties_other'] ?? '';
                if (!empty($propertiesOther)):
                    if (stripos($propertiesOther, '<!doctype') !== false || stripos($propertiesOther, '<html') !== false):
                        $iframeId = 'prop-iframe-' . uniqid();
                ?>
                    <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($propertiesOther) ?>"
                            style="width:100%;min-height:600px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md);"
                            sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                <?php else: ?>
                    <div class="prose"><?= nl2br(htmlspecialchars($propertiesOther)) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <p style="color:var(--color-dark-500);">ไม่มีข้อมูลสรรพคุณ</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-resize iframe to fit content
function resizeIframe(iframe) {
    try {
        if (iframe.contentWindow && iframe.contentWindow.document) {
            const height = iframe.contentWindow.document.documentElement.scrollHeight;
            iframe.style.height = (height + 50) + 'px';
        }
    } catch (e) {
        console.log('Cannot resize iframe due to cross-origin policy');
    }
}

// Tab switching
document.querySelectorAll('.detail-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;

        document.querySelectorAll('.detail-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
        document.getElementById(tabId).classList.remove('hidden');

        setTimeout(() => {
            document.getElementById(tabId).querySelectorAll('iframe').forEach(iframe => resizeIframe(iframe));
        }, 100);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
