<?php
/**
 * Shop Products - CNY Pharmacy API Integration
 * Display products from CNY Pharmacy external API
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สินค้า - CNY Pharmacy';

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

// Get filters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

// Fetch products from CNY API
$allProducts = callCNYAPI('get_product_all');

if (!$allProducts) {
    $allProducts = [];
}

// Filter products
$filteredProducts = array_filter($allProducts, function($product) use ($search, $category) {
    // Search filter
    if ($search && stripos($product['name'], $search) === false && 
        stripos($product['name_en'], $search) === false &&
        stripos($product['sku'], $search) === false) {
        return false;
    }
    
    // Only show enabled products
    if ($product['enable'] !== '1') {
        return false;
    }
    
    return true;
});

// Pagination
$totalProducts = count($filteredProducts);
$totalPages = ceil($totalProducts / $perPage);
$offset = ($page - 1) * $perPage;
$products = array_slice($filteredProducts, $offset, $perPage);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">
            <i class="fas fa-pills text-blue-500 mr-2"></i>
            สินค้า CNY Pharmacy
        </h1>
        <p class="text-gray-600">ข้อมูลจาก CNY Pharmacy API - ทั้งหมด <?= number_format($totalProducts) ?> รายการ</p>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ค้นหาชื่อยา, SKU..." 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-search mr-2"></i>ค้นหา
            </button>
            <?php if ($search): ?>
            <a href="?" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                <i class="fas fa-times mr-2"></i>ล้าง
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Products Grid -->
    <?php if (empty($products)): ?>
    <div class="bg-white rounded-xl shadow p-12 text-center">
        <i class="fas fa-box-open text-gray-300 text-6xl mb-4"></i>
        <p class="text-gray-500 text-lg">ไม่พบสินค้า</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-6">
        <?php foreach ($products as $product): 
            $price = $product['product_price'][0]['price'] ?? 0;
            $unit = $product['product_price'][0]['unit'] ?? '';
            $stock = (float)($product['qty'] ?? 0);
            $inStock = $stock > 0;
        ?>
        <div class="bg-white rounded-xl shadow hover:shadow-lg transition overflow-hidden">
            <!-- Product Image -->
            <div class="relative aspect-square bg-gray-100">
                <?php if (!empty($product['photo_path'])): ?>
                <img src="<?= htmlspecialchars($product['photo_path']) ?>" 
                     alt="<?= htmlspecialchars($product['name']) ?>"
                     class="w-full h-full object-cover"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2220%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-pills text-gray-300 text-6xl"></i>
                </div>
                <?php endif; ?>
                
                <!-- Stock Badge -->
                <div class="absolute top-2 right-2">
                    <?php if ($inStock): ?>
                    <span class="px-2 py-1 bg-green-500 text-white text-xs rounded-full">
                        <i class="fas fa-check mr-1"></i>มีสินค้า
                    </span>
                    <?php else: ?>
                    <span class="px-2 py-1 bg-red-500 text-white text-xs rounded-full">
                        <i class="fas fa-times mr-1"></i>หมด
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Product Info -->
            <div class="p-4">
                <div class="text-xs text-gray-500 mb-1">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                <h3 class="font-semibold text-gray-800 mb-2 line-clamp-2 min-h-[3rem]">
                    <?= htmlspecialchars($product['name']) ?>
                </h3>
                
                <?php if (!empty($product['spec_name'])): ?>
                <p class="text-xs text-gray-500 mb-2 line-clamp-1">
                    <?= htmlspecialchars($product['spec_name']) ?>
                </p>
                <?php endif; ?>

                <div class="flex items-center justify-between mb-3">
                    <div>
                        <div class="text-2xl font-bold text-blue-600">฿<?= number_format($price, 2) ?></div>
                        <?php if ($unit): ?>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($unit) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-600">คงเหลือ</div>
                        <div class="font-bold <?= $inStock ? 'text-green-600' : 'text-red-600' ?>">
                            <?= number_format($stock, 0) ?>
                        </div>
                    </div>
                </div>

                <a href="product-detail-cny.php?sku=<?= urlencode($product['sku']) ?>" 
                   class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-eye mr-2"></i>ดูรายละเอียด
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>

        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
           class="px-4 py-2 rounded-lg <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white border hover:bg-gray-50' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
