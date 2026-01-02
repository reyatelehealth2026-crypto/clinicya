<?php
/**
 * Broadcast Catalog Tab - Drag & Drop Builder
 * ลากสินค้าเข้า Bubble และปรับแต่งได้
 * 
 * @package FileConsolidation
 */

if (file_exists(__DIR__ . '/../../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../../classes/UnifiedShop.php';
}

$shop = new UnifiedShop($db, null, $currentBotId);
$products = $shop->getItems(['in_stock' => true], 200);
$categories = $shop->getCategories(50);

$productsJson = json_encode(array_map(fn($p) => [
    'id' => $p['id'], 
    'name' => $p['name'], 
    'price' => $p['sale_price'] ?: $p['price'],
    'image' => $p['image_url'] ?: 'https://via.placeholder.com/100',
    'cat' => $p['category_id'] ?? null
], $products), JSON_UNESCAPED_UNICODE);

// Get segments for targeting
$segments = [];
try {
    $stmt = $db->query("SELECT id, name, description, user_count FROM customer_segments ORDER BY name");
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get user tags for targeting
$userTags = [];
try {
    $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<!-- SortableJS for drag & drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="assets/js/flex-preview.js"></script>

<style>
.product-item { cursor: grab; transition: all 0.2s; user-select: none; }
.product-item:active { cursor: grabbing; }
.product-item.sortable-ghost { opacity: 0.4; }
.bubble-zone { min-height: 120px; border: 2px dashed #ddd; border-radius: 8px; transition: all 0.2s; }
.bubble-zone.sortable-ghost-class { background: #f0fdf4; border-color: #06C755; }
.bubble-zone:empty::before { content: 'ลากสินค้ามาวางที่นี่'; display: flex; align-items: center; justify-content: center; height: 100px; color: #aaa; font-size: 14px; }
.bubble-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
.bubble-header { background: linear-gradient(135deg, #06C755, #04a648); color: white; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
.bubble-product { display: flex; align-items: center; padding: 8px; background: #f9fafb; border-radius: 6px; margin: 4px; }
.bubble-product img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; }
.bubble-product .remove-btn { opacity: 0; transition: opacity 0.2s; }
.bubble-product:hover .remove-btn { opacity: 1; }
</style>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-4">
    <!-- Left: Products Panel -->
    <div class="xl:col-span-3 bg-white rounded-xl shadow">
        <div class="p-3 border-b">
            <div class="flex items-center justify-between mb-2">
                <span class="font-semibold"><i class="fas fa-box text-green-500 mr-1"></i>สินค้า</span>
                <span class="text-xs text-gray-500" id="productCount"><?= count($products) ?> รายการ</span>
            </div>
            <input type="text" id="searchProduct" placeholder="ค้นหาสินค้า..." class="w-full px-3 py-2 border rounded-lg text-sm" oninput="filterProducts()">
            <select id="catFilter" onchange="filterProducts()" class="w-full px-3 py-2 border rounded-lg text-sm mt-2">
                <option value="">ทุกหมวดหมู่</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="p-2 max-h-[65vh] overflow-y-auto" id="productList">
            <?php foreach ($products as $p): ?>
            <div class="product-item flex items-center p-2 mb-1 bg-gray-50 rounded-lg hover:bg-green-50" 
                 data-id="<?= $p['id'] ?>" 
                 data-name="<?= htmlspecialchars($p['name']) ?>"
                 data-price="<?= $p['sale_price'] ?: $p['price'] ?>"
                 data-image="<?= htmlspecialchars($p['image_url'] ?: 'https://via.placeholder.com/100') ?>"
                 data-cat="<?= $p['category_id'] ?? '' ?>">
                <img src="<?= htmlspecialchars($p['image_url'] ?: 'https://via.placeholder.com/40') ?>" class="w-10 h-10 object-cover rounded" onerror="this.src='https://via.placeholder.com/40'">
                <div class="ml-2 flex-1 min-w-0">
                    <div class="text-sm truncate"><?= htmlspecialchars($p['name']) ?></div>
                    <div class="text-xs text-green-600 font-bold">฿<?= number_format($p['sale_price'] ?: $p['price']) ?></div>
                </div>
                <i class="fas fa-grip-vertical text-gray-300"></i>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Center: Bubble Builder -->
    <div class="xl:col-span-5 space-y-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold"><i class="fas fa-layer-group text-purple-500 mr-2"></i>Bubble Builder</h2>
                <button onclick="addBubble()" class="px-3 py-1 bg-green-500 text-white rounded-lg text-sm hover:bg-green-600">
                    <i class="fas fa-plus mr-1"></i>เพิ่ม Bubble
                </button>
            </div>
            <div id="bubblesContainer" class="space-y-4"></div>
        </div>
    </div>
    
    <!-- Right: Preview & Settings -->
    <div class="xl:col-span-4 space-y-4">
        <!-- Settings -->
        <div class="bg-white rounded-xl shadow p-4">
            <h3 class="font-semibold mb-3"><i class="fas fa-cog text-gray-500 mr-2"></i>ตั้งค่า</h3>
            <div class="space-y-3">
                <div>
                    <label class="text-xs font-medium text-gray-600">Layout แต่ละ Bubble</label>
                    <div class="grid grid-cols-4 gap-2 mt-1">
                        <button onclick="setLayout('2x2')" class="layout-btn px-2 py-2 border rounded text-xs hover:bg-gray-50" data-layout="2x2">2x2</button>
                        <button onclick="setLayout('2x3')" class="layout-btn px-2 py-2 border rounded text-xs hover:bg-gray-50" data-layout="2x3">2x3</button>
                        <button onclick="setLayout('3x3')" class="layout-btn px-2 py-2 border rounded text-xs hover:bg-gray-50 bg-green-500 text-white" data-layout="3x3">3x3</button>
                        <button onclick="setLayout('3x4')" class="layout-btn px-2 py-2 border rounded text-xs hover:bg-gray-50" data-layout="3x4">3x4</button>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">สีธีม</label>
                    <div class="flex gap-2 mt-1">
                        <button onclick="setTheme('#06C755')" class="w-8 h-8 rounded-full bg-[#06C755] border-2 border-white shadow"></button>
                        <button onclick="setTheme('#FF6B6B')" class="w-8 h-8 rounded-full bg-[#FF6B6B] border-2 border-white shadow"></button>
                        <button onclick="setTheme('#4ECDC4')" class="w-8 h-8 rounded-full bg-[#4ECDC4] border-2 border-white shadow"></button>
                        <input type="color" id="customColor" value="#06C755" onchange="setTheme(this.value)" class="w-8 h-8 rounded cursor-pointer">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Preview -->
        <div class="bg-white rounded-xl shadow">
            <div class="p-3 border-b flex items-center justify-between">
                <span class="font-semibold"><i class="fas fa-mobile-alt text-purple-500 mr-2"></i>Preview</span>
                <span class="text-xs text-gray-500" id="previewInfo">0 bubbles</span>
            </div>
            <div class="p-3 bg-gray-100 max-h-[50vh] overflow-y-auto" id="previewBox">
                <div class="text-center text-gray-400 py-8">
                    <i class="fas fa-hand-pointer text-4xl mb-2"></i>
                    <p>ลากสินค้าเข้า Bubble เพื่อดู Preview</p>
                </div>
            </div>
        </div>
        
        <!-- Send Button -->
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm font-medium">พร้อมส่ง</div>
                    <div class="text-xs text-gray-500" id="sendInfo">0 สินค้า, 0 bubbles</div>
                </div>
                <button onclick="sendBroadcast()" id="sendBtn" disabled class="px-6 py-3 bg-green-500 text-white rounded-lg font-medium hover:bg-green-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bubble Template -->
<template id="bubbleTemplate">
    <div class="bubble-card" data-bubble-id="">
        <div class="bubble-header">
            <div class="flex items-center gap-2">
                <i class="fas fa-grip-vertical cursor-move bubble-handle"></i>
                <input type="text" class="bubble-title bg-transparent border-none text-white placeholder-white/70 text-sm font-medium w-32" placeholder="หัวข้อ Bubble" value="สินค้าแนะนำ" oninput="updatePreview()">
            </div>
            <div class="flex items-center gap-2">
                <button onclick="removeBubble(this)" class="text-white/80 hover:text-white text-sm"><i class="fas fa-trash"></i></button>
            </div>
        </div>
        <div class="bubble-zone p-2" data-bubble-zone=""></div>
        <div class="p-2 border-t bg-gray-50 text-xs text-gray-500 flex justify-between">
            <span class="product-count">0 สินค้า</span>
            <span class="layout-info">Layout: 3x3</span>
        </div>
    </div>
</template>

<!-- Product in Bubble Template -->
<template id="bubbleProductTemplate">
    <div class="bubble-product" data-product-id="">
        <img src="" class="product-img">
        <div class="ml-2 flex-1 min-w-0">
            <div class="text-xs truncate product-name"></div>
            <div class="text-xs text-green-600 font-bold product-price"></div>
        </div>
        <button class="remove-btn text-red-400 hover:text-red-600 px-2" onclick="removeProduct(this)"><i class="fas fa-times"></i></button>
    </div>
</template>

<script>
const allProducts = <?= $productsJson ?>;
let currentLayout = '3x3';
let currentTheme = '#06C755';
let bubbleIdCounter = 0;

const layoutConfig = {
    '2x2': { cols: 2, rows: 2, max: 4 },
    '2x3': { cols: 2, rows: 3, max: 6 },
    '3x3': { cols: 3, rows: 3, max: 9 },
    '3x4': { cols: 3, rows: 4, max: 12 }
};

document.addEventListener('DOMContentLoaded', () => {
    new Sortable(document.getElementById('productList'), {
        group: { name: 'products', pull: 'clone', put: false },
        sort: false,
        animation: 150
    });
    addBubble();
});

function addBubble() {
    const template = document.getElementById('bubbleTemplate');
    const clone = template.content.cloneNode(true);
    const bubbleEl = clone.querySelector('.bubble-card');
    const bubbleId = ++bubbleIdCounter;
    
    bubbleEl.dataset.bubbleId = bubbleId;
    bubbleEl.querySelector('[data-bubble-zone]').dataset.bubbleZone = bubbleId;
    bubbleEl.querySelector('.bubble-header').style.background = `linear-gradient(135deg, ${currentTheme}, ${adjustColor(currentTheme, -20)})`;
    
    document.getElementById('bubblesContainer').appendChild(clone);
    
    const zone = document.querySelector(`[data-bubble-zone="${bubbleId}"]`);
    new Sortable(zone, {
        group: 'products',
        animation: 150,
        onAdd: function(evt) {
            const item = evt.item;
            const productData = { id: item.dataset.id, name: item.dataset.name, price: item.dataset.price, image: item.dataset.image };
            const productEl = createBubbleProduct(productData);
            item.replaceWith(productEl);
            updateBubbleInfo(bubbleId);
            updatePreview();
        },
        onSort: function() { updatePreview(); }
    });
    
    updatePreview();
}

function createBubbleProduct(product) {
    const template = document.getElementById('bubbleProductTemplate');
    const clone = template.content.cloneNode(true);
    const el = clone.querySelector('.bubble-product');
    el.dataset.productId = product.id;
    el.querySelector('.product-img').src = product.image;
    el.querySelector('.product-name').textContent = product.name;
    el.querySelector('.product-price').textContent = '฿' + Number(product.price).toLocaleString();
    return el;
}

function removeProduct(btn) {
    const productEl = btn.closest('.bubble-product');
    const zone = productEl.closest('[data-bubble-zone]');
    const bubbleId = zone.dataset.bubbleZone;
    productEl.remove();
    updateBubbleInfo(bubbleId);
    updatePreview();
}

function removeBubble(btn) {
    const bubbleEl = btn.closest('.bubble-card');
    bubbleEl.remove();
    updatePreview();
}

function updateBubbleInfo(bubbleId) {
    const zone = document.querySelector(`[data-bubble-zone="${bubbleId}"]`);
    const card = zone.closest('.bubble-card');
    const count = zone.querySelectorAll('.bubble-product').length;
    const cfg = layoutConfig[currentLayout];
    card.querySelector('.product-count').textContent = `${count}/${cfg.max} สินค้า`;
}

function setLayout(layout) {
    currentLayout = layout;
    document.querySelectorAll('.layout-btn').forEach(btn => {
        btn.classList.toggle('bg-green-500', btn.dataset.layout === layout);
        btn.classList.toggle('text-white', btn.dataset.layout === layout);
    });
    document.querySelectorAll('[data-bubble-zone]').forEach(zone => updateBubbleInfo(zone.dataset.bubbleZone));
    updatePreview();
}

function setTheme(color) {
    currentTheme = color;
    document.querySelectorAll('.bubble-header').forEach(header => {
        header.style.background = `linear-gradient(135deg, ${color}, ${adjustColor(color, -20)})`;
    });
    updatePreview();
}

function adjustColor(color, amount) {
    const hex = color.replace('#', '');
    const r = Math.max(0, Math.min(255, parseInt(hex.substr(0, 2), 16) + amount));
    const g = Math.max(0, Math.min(255, parseInt(hex.substr(2, 2), 16) + amount));
    const b = Math.max(0, Math.min(255, parseInt(hex.substr(4, 2), 16) + amount));
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

function filterProducts() {
    const search = document.getElementById('searchProduct').value.toLowerCase();
    const cat = document.getElementById('catFilter').value;
    let visible = 0;
    document.querySelectorAll('#productList .product-item').forEach(item => {
        const name = item.dataset.name.toLowerCase();
        const itemCat = item.dataset.cat;
        const show = name.includes(search) && (!cat || itemCat === cat);
        item.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('productCount').textContent = visible + ' รายการ';
}

function getBubblesData() {
    const data = [];
    document.querySelectorAll('.bubble-card').forEach(card => {
        const zone = card.querySelector('[data-bubble-zone]');
        const products = [];
        zone.querySelectorAll('.bubble-product').forEach(p => {
            const id = p.dataset.productId;
            const product = allProducts.find(pr => pr.id == id);
            if (product) products.push(product);
        });
        if (products.length > 0) {
            data.push({ title: card.querySelector('.bubble-title').value || 'สินค้าแนะนำ', products: products, layout: currentLayout, theme: currentTheme });
        }
    });
    return data;
}

function updatePreview() {
    const data = getBubblesData();
    const totalProducts = data.reduce((sum, b) => sum + b.products.length, 0);
    document.getElementById('previewInfo').textContent = `${data.length} bubbles`;
    document.getElementById('sendInfo').textContent = `${totalProducts} สินค้า, ${data.length} bubbles`;
    document.getElementById('sendBtn').disabled = totalProducts === 0;
    
    if (data.length === 0) {
        document.getElementById('previewBox').innerHTML = `<div class="text-center text-gray-400 py-8"><i class="fas fa-hand-pointer text-4xl mb-2"></i><p>ลากสินค้าเข้า Bubble เพื่อดู Preview</p></div>`;
        return;
    }
    
    const flex = buildFlexFromData(data);
    if (typeof FlexPreview !== 'undefined') {
        FlexPreview.render('previewBox', flex);
    }
}

function buildFlexFromData(bubblesData) {
    const cfg = layoutConfig[currentLayout];
    const flexBubbles = bubblesData.map((bubble, idx) => {
        const products = bubble.products.slice(0, cfg.max);
        const rows = [];
        for (let i = 0; i < products.length; i += cfg.cols) {
            const rowItems = products.slice(i, i + cfg.cols);
            const rowContents = rowItems.map(p => ({
                type: 'box', layout: 'vertical', flex: 1, spacing: 'xs', paddingAll: 'xs',
                contents: [
                    { type: 'image', url: p.image || 'https://via.placeholder.com/100', size: 'full', aspectRatio: '1:1', aspectMode: 'cover' },
                    { type: 'text', text: p.name.length > 10 ? p.name.slice(0,10)+'..' : p.name, size: 'xxs', color: '#333', wrap: false },
                    { type: 'text', text: '฿'+Number(p.price).toLocaleString(), size: 'xs', color: bubble.theme, weight: 'bold' }
                ]
            }));
            while (rowContents.length < cfg.cols) rowContents.push({ type: 'box', layout: 'vertical', contents: [], flex: 1 });
            rows.push({ type: 'box', layout: 'horizontal', contents: rowContents, spacing: 'sm' });
        }
        return {
            type: 'bubble',
            header: { type: 'box', layout: 'horizontal', paddingAll: 'lg', backgroundColor: bubble.theme + '15', contents: [
                { type: 'text', text: bubble.title, weight: 'bold', size: 'md', color: bubble.theme, flex: 1 },
                { type: 'text', text: products.length + ' รายการ', size: 'xs', color: '#888', align: 'end' }
            ]},
            body: { type: 'box', layout: 'vertical', contents: rows, spacing: 'sm', paddingAll: 'md' },
            footer: { type: 'box', layout: 'horizontal', paddingAll: 'md', contents: [
                { type: 'button', action: { type: 'message', label: '🛒 ดูทั้งหมด', text: 'shop' }, style: 'primary', color: bubble.theme, height: 'sm' }
            ]}
        };
    });
    return flexBubbles.length === 1 ? flexBubbles[0] : { type: 'carousel', contents: flexBubbles };
}

async function sendBroadcast() {
    const data = getBubblesData();
    if (data.length === 0) { alert('กรุณาเพิ่มสินค้าใน Bubble'); return; }
    if (!confirm(`ส่ง ${data.length} bubbles?`)) return;
    
    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังส่ง...';
    
    try {
        const flex = buildFlexFromData(data);
        const response = await fetch('api/broadcast.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_flex', flex: flex, altText: data[0].title })
        });
        const result = await response.json();
        if (result.success) {
            alert(result.sent ? `✅ ส่งสำเร็จ! (${result.sent} คน)` : '✅ ส่ง Broadcast สำเร็จ!');
        } else {
            alert('❌ Error: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('❌ Error: ' + e.message);
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast';
}
</script>
