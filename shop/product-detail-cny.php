<?php
/**
 * Product Detail - CNY Pharmacy API
 * Display single product details from CNY API
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// CNY API Configuration
define('CNY_API_BASE', 'https://manager.cnypharmacy.com/api/');
define('CNY_API_TOKEN', '90xcKekelCqCAjmgkpI1saJF6N55eiNexcI4hdcYM2M');

/**
 * Call CNY Pharmacy API
 */
function callCNYAPI($endpoint, $method = 'GET', $data = null) {
    $url = CNY_API_BASE . ltrim($endpoint, '/');
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . CNY_API_TOKEN,
        'Content-Type: application/json'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("CNY API Error: HTTP {$httpCode}, Response: {$response}");
        return null;
    }
    
    return json_decode($response, true);
}

// Get SKU from URL
$sku = $_GET['sku'] ?? '';

if (empty($sku)) {
    header('Location: products-cny.php');
    exit;
}

// Fetch product from CNY API
$product = callCNYAPI("get_product_sku/{$sku}");

if (!$product) {
    header('Location: products-cny.php');
    exit;
}

$pageTitle = $product['name'] ?? 'รายละเอียดสินค้า';

require_once __DIR__ . '/../includes/header.php';

$stock = (float)($product['qty'] ?? 0);
$inStock = $stock > 0;
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <!-- Breadcrumb -->
    <div class="mb-6">
        <a href="products-cny.php" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าสินค้า
        </a>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6">
            <!-- Product Image -->
            <div class="aspect-square bg-gray-100 rounded-lg overflow-hidden">
                <?php if (!empty($product['photo_path'])): ?>
                <img src="<?= htmlspecialchars($product['photo_path']) ?>" 
                     alt="<?= htmlspecialchars($product['name']) ?>"
                     class="w-full h-full object-contain"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 400%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22400%22 height=%22400%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2230%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-pills text-gray-300 text-9xl"></i>
                </div>
                <?php endif; ?>
            </div>

            <!-- Product Info -->
            <div>
                <div class="mb-4">
                    <div class="text-sm text-gray-500 mb-2">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($product['name']) ?></h1>
                    <?php if (!empty($product['name_en'])): ?>
                    <p class="text-lg text-gray-600 mb-2"><?= htmlspecialchars($product['name_en']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($product['spec_name'])): ?>
                    <p class="text-sm text-blue-600 font-medium"><?= htmlspecialchars($product['spec_name']) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Stock Status -->
                <div class="mb-6">
                    <?php if ($inStock): ?>
                    <div class="inline-flex items-center px-4 py-2 bg-green-100 text-green-700 rounded-lg">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="font-medium">มีสินค้า (คงเหลือ <?= number_format($stock, 0) ?> หน่วย)</span>
                    </div>
                    <?php else: ?>
                    <div class="inline-flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg">
                        <i class="fas fa-times-circle mr-2"></i>
                        <span class="font-medium">สินค้าหมด</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Prices -->
                <?php if (!empty($product['product_price'])): ?>
                <div class="mb-6">
                    <h3 class="font-semibold text-gray-800 mb-3">ราคาตามกลุ่มลูกค้า</h3>
                    <div class="space-y-2">
                        <?php foreach ($product['product_price'] as $priceInfo): 
                            if ($priceInfo['enable'] !== '1') continue;
                        ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div>
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($priceInfo['customer_group']) ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($priceInfo['unit']) ?></div>
                            </div>
                            <div class="text-2xl font-bold text-blue-600">
                                ฿<?= number_format((float)$priceInfo['price'], 2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product Details Tabs -->
        <div class="border-t">
            <div class="flex border-b">
                <button class="tab-btn active px-6 py-3 font-medium border-b-2 border-blue-600 text-blue-600" data-tab="description">
                    <i class="fas fa-info-circle mr-2"></i>รายละเอียด
                </button>
                <button class="tab-btn px-6 py-3 font-medium text-gray-600 hover:text-blue-600" data-tab="usage">
                    <i class="fas fa-pills mr-2"></i>วิธีใช้
                </button>
                <button class="tab-btn px-6 py-3 font-medium text-gray-600 hover:text-blue-600" data-tab="properties">
                    <i class="fas fa-flask mr-2"></i>สรรพคุณ
                </button>
            </div>

            <div class="p-6">
                <!-- Description Tab -->
                <div class="tab-content" id="description">
                    <?php if (!empty($product['description'])): ?>
                    <div class="prose max-w-none">
                        <?= nl2br(htmlspecialchars($product['description'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีรายละเอียด</p>
                    <?php endif; ?>
                </div>

                <!-- Usage Tab -->
                <div class="tab-content hidden" id="usage">
                    <?php if (!empty($product['how_to_use'])): ?>
                    <div class="prose max-w-none">
                        <?= nl2br(htmlspecialchars($product['how_to_use'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีข้อมูลวิธีใช้</p>
                    <?php endif; ?>
                </div>

                <!-- Properties Tab -->
                <div class="tab-content hidden" id="properties">
                    <?php if (!empty($product['properties_other'])): ?>
                    <div class="prose max-w-none">
                        <?= nl2br(htmlspecialchars($product['properties_other'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีข้อมูลสรรพคุณ</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active', 'border-blue-600', 'text-blue-600');
            b.classList.add('text-gray-600');
        });
        btn.classList.add('active', 'border-blue-600', 'text-blue-600');
        btn.classList.remove('text-gray-600');
        
        // Update content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });
        document.getElementById(tabId).classList.remove('hidden');
    });
});
</script>

<style>
.tab-btn.active {
    border-bottom-width: 2px;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
