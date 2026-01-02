<?php
/**
 * Broadcast Products V3.0 - ส่ง Broadcast สินค้าพร้อม Auto Tag
 * รองรับ UnifiedShop + Flex Message สวยงาม + Visual Preview
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/LineAPI.php';
require_once __DIR__ . '/classes/LineAccountManager.php';
if (file_exists(__DIR__ . '/classes/UnifiedShop.php')) {
    require_once __DIR__ . '/classes/UnifiedShop.php';
}
require_once __DIR__ . '/classes/FlexTemplates.php';

// Load FlexPreview after header
$loadFlexPreview = true;

$db = Database::getInstance()->getConnection();
$pageTitle = 'Broadcast Products';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Initialize UnifiedShop
$shop = new UnifiedShop($db, null, $currentBotId);

// Ensure tables exist
try {
    $db->query("SELECT 1 FROM broadcast_campaigns LIMIT 1");
} catch (Exception $e) {
    // Create tables
    $db->exec("
        CREATE TABLE IF NOT EXISTS broadcast_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            message_type VARCHAR(50) DEFAULT 'product_carousel',
            auto_tag_enabled TINYINT(1) DEFAULT 1,
            tag_prefix VARCHAR(50) DEFAULT 'สนใจ_',
            status ENUM('draft', 'sent', 'scheduled') DEFAULT 'draft',
            sent_at TIMESTAMP NULL,
            scheduled_at TIMESTAMP NULL,
            total_sent INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_account (line_account_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS broadcast_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            broadcast_id INT NOT NULL,
            product_id INT,
            item_name VARCHAR(255) NOT NULL,
            item_image VARCHAR(500),
            item_price DECIMAL(10,2),
            item_description TEXT,
            postback_data VARCHAR(255),
            tag_id INT,
            click_count INT DEFAULT 0,
            sort_order INT DEFAULT 0,
            INDEX idx_broadcast (broadcast_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        CREATE TABLE IF NOT EXISTS broadcast_clicks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            broadcast_id INT NOT NULL,
            item_id INT NOT NULL,
            user_id INT NOT NULL,
            clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_broadcast (broadcast_id),
            INDEX idx_item (item_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// Get products from UnifiedShop
$products = $shop->getItems(['in_stock' => true], 100);

// Get categories
$categories = $shop->getCategories(50);

// Get tags
$tags = [];
try {
    $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
    $stmt->execute([$currentBotId]);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get campaigns
$campaigns = [];
try {
    $stmt = $db->prepare("SELECT * FROM broadcast_campaigns WHERE (line_account_id = ? OR line_account_id IS NULL) ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$currentBotId]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get stats
$stats = [
    'total_campaigns' => count($campaigns),
    'sent_campaigns' => count(array_filter($campaigns, fn($c) => $c['status'] === 'sent')),
    'total_clicks' => 0,
    'total_tags_assigned' => 0
];

try {
    $stmt = $db->query("SELECT COUNT(*) FROM broadcast_clicks");
    $stats['total_clicks'] = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

// Handle POST
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_broadcast') {
        $name = trim($_POST['name'] ?? '');
        $tagPrefix = trim($_POST['tag_prefix'] ?? 'สนใจ_');
        $autoTagEnabled = isset($_POST['auto_tag_enabled']) ? 1 : 0;
        $selectedProducts = $_POST['products'] ?? [];
        $buttonText = trim($_POST['button_text'] ?? 'สนใจสินค้านี้');
        $buttonColor = $_POST['button_color'] ?? '#06C755';
        
        if (empty($name)) {
            $error = 'กรุณากรอกชื่อ Broadcast';
        } elseif (empty($selectedProducts)) {
            $error = 'กรุณาเลือกสินค้าอย่างน้อย 1 รายการ';
        } elseif (count($selectedProducts) > 10) {
            $error = 'เลือกสินค้าได้สูงสุด 10 รายการ';
        } else {
            try {
                $db->beginTransaction();
                
                // Create campaign
                $stmt = $db->prepare("INSERT INTO broadcast_campaigns (line_account_id, name, message_type, auto_tag_enabled, tag_prefix) VALUES (?, ?, 'product_carousel', ?, ?)");
                $stmt->execute([$currentBotId, $name, $autoTagEnabled, $tagPrefix]);
                $campaignId = $db->lastInsertId();
                
                // Add items
                $sortOrder = 0;
                foreach ($selectedProducts as $productId) {
                    $product = $shop->getItem($productId);
                    
                    if ($product) {
                        $tagId = null;
                        
                        // Create tag if auto tag enabled
                        if ($autoTagEnabled) {
                            $tagName = $tagPrefix . $product['name'];
                            $tagColor = '#' . substr(md5($product['name']), 0, 6);
                            
                            // Check if tag exists
                            $stmt = $db->prepare("SELECT id FROM user_tags WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1");
                            $stmt->execute([$tagName, $currentBotId]);
                            $tagId = $stmt->fetchColumn();
                            
                            if (!$tagId) {
                                $stmt = $db->prepare("INSERT INTO user_tags (line_account_id, name, color, description) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$currentBotId, $tagName, $tagColor, 'สนใจสินค้า: ' . $product['name']]);
                                $tagId = $db->lastInsertId();
                            }
                        }
                        
                        // ใช้รูปแบบ broadcast_click_{campaignId}_{productId} ที่ webhook คาดหวัง
                        $postbackData = "broadcast_click_{$campaignId}_{$productId}";
                        
                        $stmt = $db->prepare("INSERT INTO broadcast_items (broadcast_id, product_id, item_name, item_image, item_price, postback_data, tag_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $campaignId,
                            $productId,
                            $product['name'],
                            $product['image_url'],
                            $product['sale_price'] ?: $product['price'],
                            $postbackData,
                            $tagId,
                            $sortOrder++
                        ]);
                    }
                }
                
                $db->commit();
                header("Location: broadcast-products.php?success=created&id={$campaignId}");
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'send_broadcast') {
        $campaignId = (int)$_POST['campaign_id'];
        $targetType = $_POST['target_type'] ?? 'all';
        $targetTags = $_POST['target_tags'] ?? [];
        
        try {
            // Get campaign
            $stmt = $db->prepare("SELECT * FROM broadcast_campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$campaign) {
                throw new Exception('ไม่พบ Campaign');
            }
            
            // Get items
            $stmt = $db->prepare("SELECT * FROM broadcast_items WHERE broadcast_id = ? ORDER BY sort_order");
            $stmt->execute([$campaignId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($items)) {
                throw new Exception('ไม่มีสินค้าใน Campaign');
            }
            
            // Build Flex Carousel
            $bubbles = [];
            foreach ($items as $item) {
                $bubble = [
                    'type' => 'bubble',
                    'size' => 'kilo'
                ];
                
                // Hero image
                if ($item['item_image']) {
                    $bubble['hero'] = [
                        'type' => 'image',
                        'url' => $item['item_image'],
                        'size' => 'full',
                        'aspectRatio' => '1:1',
                        'aspectMode' => 'cover',
                        'action' => [
                            'type' => 'postback',
                            'data' => $item['postback_data'],
                            'displayText' => 'ดู ' . $item['item_name']
                        ]
                    ];
                }
                
                // Body
                $bubble['body'] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $item['item_name'], 'weight' => 'bold', 'size' => 'md', 'wrap' => true, 'maxLines' => 2],
                        ['type' => 'text', 'text' => '฿' . number_format($item['item_price']), 'size' => 'xl', 'color' => '#06C755', 'weight' => 'bold', 'margin' => 'md']
                    ],
                    'paddingAll' => 'lg'
                ];
                
                // Footer
                $bubble['footer'] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#06C755',
                            'action' => [
                                'type' => 'postback',
                                'label' => '❤️ สนใจสินค้านี้',
                                'data' => $item['postback_data'],
                                'displayText' => 'สนใจ ' . $item['item_name']
                            ]
                        ],
                        [
                            'type' => 'button',
                            'style' => 'secondary',
                            'action' => [
                                'type' => 'message',
                                'label' => '🛒 เพิ่มลงตะกร้า',
                                'text' => 'add ' . $item['product_id']
                            ],
                            'margin' => 'sm'
                        ]
                    ],
                    'paddingAll' => 'lg'
                ];
                
                $bubbles[] = $bubble;
            }
            
            $flexMessage = [
                'type' => 'flex',
                'altText' => '📦 ' . $campaign['name'],
                'contents' => [
                    'type' => 'carousel',
                    'contents' => $bubbles
                ]
            ];
            
            // Send broadcast
            $lineManager = new LineAccountManager($db);
            $line = $lineManager->getLineAPI($currentBotId);
            
            $sentCount = 0;
            
            if ($targetType === 'all') {
                // Broadcast to all
                $result = $line->broadcastMessage([$flexMessage]);
                if ($result['code'] === 200) {
                    $sentCount = -1; // Unknown count for broadcast
                }
            } else {
                // Send to specific users with tags
                $userIds = [];
                if (!empty($targetTags)) {
                    $placeholders = implode(',', array_fill(0, count($targetTags), '?'));
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.line_user_id 
                        FROM users u 
                        JOIN user_tag_assignments uta ON u.id = uta.user_id 
                        WHERE uta.tag_id IN ({$placeholders})
                        AND (u.line_account_id = ? OR u.line_account_id IS NULL)
                    ");
                    $params = array_merge($targetTags, [$currentBotId]);
                    $stmt->execute($params);
                    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                
                // Send multicast
                if (!empty($userIds)) {
                    foreach (array_chunk($userIds, 500) as $chunk) {
                        $result = $line->multicastMessage($chunk, [$flexMessage]);
                        if ($result['code'] === 200) {
                            $sentCount += count($chunk);
                        }
                    }
                }
            }
            
            // Update campaign
            $stmt = $db->prepare("UPDATE broadcast_campaigns SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $stmt->execute([$campaignId]);
            
            header("Location: broadcast-products.php?success=sent&count={$sentCount}");
            exit;
            
        } catch (Exception $e) {
            $error = 'ส่ง Broadcast ไม่สำเร็จ: ' . $e->getMessage();
        }
    }
    
    if ($action === 'delete_campaign') {
        $campaignId = (int)$_POST['campaign_id'];
        
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM broadcast_clicks WHERE broadcast_id = ?")->execute([$campaignId]);
            $db->prepare("DELETE FROM broadcast_items WHERE broadcast_id = ?")->execute([$campaignId]);
            $db->prepare("DELETE FROM broadcast_campaigns WHERE id = ?")->execute([$campaignId]);
            $db->commit();
            
            header("Location: broadcast-products.php?success=deleted");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'ลบไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

require_once 'includes/header.php';
?>

<!-- Load FlexPreview Component -->
<script src="assets/js/flex-preview.js"></script>

<?php if (isset($_GET['success'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
    <i class="fas fa-check-circle mr-2"></i>
    <?php 
    $msg = match($_GET['success']) {
        'created' => 'สร้าง Broadcast สำเร็จ!',
        'sent' => 'ส่ง Broadcast สำเร็จ!' . (isset($_GET['count']) ? " ({$_GET['count']} คน)" : ''),
        'deleted' => 'ลบ Broadcast สำเร็จ!',
        default => 'ดำเนินการสำเร็จ!'
    };
    echo $msg;
    ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-bullhorn text-blue-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Campaigns</p>
                <p class="text-2xl font-bold"><?= $stats['total_campaigns'] ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-paper-plane text-green-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">ส่งแล้ว</p>
                <p class="text-2xl font-bold"><?= $stats['sent_campaigns'] ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-mouse-pointer text-purple-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Total Clicks</p>
                <p class="text-2xl font-bold"><?= number_format($stats['total_clicks']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-box text-yellow-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">สินค้า</p>
                <p class="text-2xl font-bold"><?= count($products) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Create New Broadcast -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b">
                <h3 class="font-semibold flex items-center">
                    <i class="fas fa-plus-circle text-green-500 mr-2"></i>
                    สร้าง Broadcast ใหม่
                </h3>
            </div>
            
            <form method="POST" class="p-4">
                <input type="hidden" name="action" value="create_broadcast">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ชื่อ Broadcast *</label>
                    <input type="text" name="name" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="เช่น โปรโมชั่นสินค้าใหม่">
                </div>
                
                <div class="mb-4 p-3 bg-green-50 rounded-lg">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="auto_tag_enabled" value="1" checked class="w-4 h-4 text-green-500 rounded mr-3">
                        <div>
                            <span class="font-medium text-green-800">🏷️ Auto Tag</span>
                            <p class="text-xs text-green-600">ติด Tag อัตโนมัติเมื่อลูกค้ากดสนใจ</p>
                        </div>
                    </label>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Prefix ของ Tag</label>
                    <input type="text" name="tag_prefix" value="สนใจ_" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">เช่น สนใจ_เสื้อยืด, สนใจ_กางเกง</p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">
                        เลือกสินค้า <span class="text-gray-400">(สูงสุด 10 รายการ)</span>
                    </label>
                    
                    <?php if (!empty($categories)): ?>
                    <select id="categoryFilter" onchange="filterProducts()" class="w-full px-3 py-2 border rounded-lg mb-2 text-sm">
                        <option value="">ทุกหมวดหมู่</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    
                    <div id="productList" class="max-h-64 overflow-y-auto border rounded-lg">
                        <?php if (empty($products)): ?>
                        <div class="p-8 text-center text-gray-400">
                            <i class="fas fa-box-open text-3xl mb-2"></i>
                            <p>ยังไม่มีสินค้า</p>
                            <a href="shop/products.php" class="text-green-500 text-sm hover:underline">เพิ่มสินค้า</a>
                        </div>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <label class="product-item flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b last:border-b-0" data-category="<?= $product['category_id'] ?? '' ?>">
                            <input type="checkbox" name="products[]" value="<?= $product['id'] ?>" class="product-checkbox w-4 h-4 text-green-500 rounded mr-3">
                            <?php if ($product['image_url']): ?>
                            <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-12 h-12 rounded-lg object-cover mr-3" onerror="this.src='https://via.placeholder.com/48'">
                            <?php else: ?>
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mr-3">
                                <i class="fas fa-image text-gray-300"></i>
                            </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium truncate"><?= htmlspecialchars($product['name']) ?></p>
                                <p class="text-sm text-green-600 font-bold">฿<?= number_format($product['sale_price'] ?: $product['price']) ?></p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-gray-500">เลือกแล้ว: <span id="selectedCount" class="font-bold text-green-600">0</span>/10</span>
                        <button type="button" onclick="clearSelection()" class="text-red-500 hover:text-red-600">ล้างทั้งหมด</button>
                    </div>
                </div>
                
                <button type="submit" id="createBtn" class="w-full px-4 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    <i class="fas fa-plus mr-2"></i>สร้าง Broadcast
                </button>
            </form>
        </div>
    </div>

    <!-- Campaigns List -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold flex items-center">
                    <i class="fas fa-list text-blue-500 mr-2"></i>
                    Broadcast Campaigns
                </h3>
                <span class="text-sm text-gray-500"><?= count($campaigns) ?> รายการ</span>
            </div>
            
            <div class="divide-y">
                <?php if (empty($campaigns)): ?>
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-bullhorn text-4xl mb-3"></i>
                    <p>ยังไม่มี Broadcast</p>
                    <p class="text-sm">สร้าง Broadcast แรกของคุณเลย!</p>
                </div>
                <?php else: ?>
                <?php foreach ($campaigns as $campaign): ?>
                <?php
                // Get items
                $stmt = $db->prepare("SELECT * FROM broadcast_items WHERE broadcast_id = ? ORDER BY sort_order");
                $stmt->execute([$campaign['id']]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get total clicks
                $totalClicks = 0;
                try {
                    $stmt = $db->prepare("SELECT SUM(click_count) FROM broadcast_items WHERE broadcast_id = ?");
                    $stmt->execute([$campaign['id']]);
                    $totalClicks = $stmt->fetchColumn() ?: 0;
                } catch (Exception $e) {}
                
                $statusColors = [
                    'draft' => 'yellow',
                    'sent' => 'green',
                    'scheduled' => 'blue'
                ];
                $statusLabels = [
                    'draft' => 'รอส่ง',
                    'sent' => 'ส่งแล้ว',
                    'scheduled' => 'ตั้งเวลา'
                ];
                $color = $statusColors[$campaign['status']] ?? 'gray';
                $label = $statusLabels[$campaign['status']] ?? $campaign['status'];
                ?>
                <div class="p-4 hover:bg-gray-50">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h4 class="font-semibold"><?= htmlspecialchars($campaign['name']) ?></h4>
                            <p class="text-xs text-gray-500">
                                <i class="fas fa-calendar mr-1"></i>
                                <?= date('d/m/Y H:i', strtotime($campaign['created_at'])) ?>
                                <?php if ($campaign['sent_at']): ?>
                                <span class="ml-2"><i class="fas fa-paper-plane mr-1"></i>ส่งเมื่อ <?= date('d/m/Y H:i', strtotime($campaign['sent_at'])) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="px-3 py-1 text-xs font-medium rounded-full bg-<?= $color ?>-100 text-<?= $color ?>-700">
                            <?= $label ?>
                        </span>
                    </div>
                    
                    <!-- Product thumbnails -->
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php foreach (array_slice($items, 0, 5) as $item): ?>
                        <div class="relative group">
                            <?php if ($item['item_image']): ?>
                            <img src="<?= htmlspecialchars($item['item_image']) ?>" class="w-14 h-14 rounded-lg object-cover border" onerror="this.src='https://via.placeholder.com/56'">
                            <?php else: ?>
                            <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center border">
                                <i class="fas fa-image text-gray-300"></i>
                            </div>
                            <?php endif; ?>
                            <?php if ($item['click_count'] > 0): ?>
                            <span class="absolute -top-1 -right-1 w-5 h-5 bg-green-500 text-white text-xs rounded-full flex items-center justify-center"><?= $item['click_count'] ?></span>
                            <?php endif; ?>
                            <div class="absolute inset-0 bg-black/50 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                <span class="text-white text-xs text-center px-1"><?= htmlspecialchars(mb_substr($item['item_name'], 0, 10)) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($items) > 5): ?>
                        <div class="w-14 h-14 bg-gray-100 rounded-lg flex items-center justify-center border text-gray-500 text-sm">
                            +<?= count($items) - 5 ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Stats & Actions -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4 text-sm text-gray-500">
                            <span><i class="fas fa-box mr-1"></i><?= count($items) ?> สินค้า</span>
                            <?php if ($campaign['auto_tag_enabled']): ?>
                            <span class="text-green-600"><i class="fas fa-tag mr-1"></i>Auto Tag</span>
                            <?php endif; ?>
                            <?php if ($totalClicks > 0): ?>
                            <span class="text-purple-600"><i class="fas fa-mouse-pointer mr-1"></i><?= number_format($totalClicks) ?> clicks</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <?php if ($campaign['status'] !== 'sent'): ?>
                            <button onclick="openSendModal(<?= $campaign['id'] ?>, '<?= htmlspecialchars($campaign['name'], ENT_QUOTES) ?>')" class="px-3 py-1.5 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600">
                                <i class="fas fa-paper-plane mr-1"></i>ส่ง
                            </button>
                            <?php endif; ?>
                            
                            <a href="broadcast-stats.php?id=<?= $campaign['id'] ?>" class="px-3 py-1.5 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600">
                                <i class="fas fa-chart-bar mr-1"></i>สถิติ
                            </a>
                            
                            <form method="POST" class="inline" onsubmit="return confirm('ลบ Broadcast นี้?')">
                                <input type="hidden" name="action" value="delete_campaign">
                                <input type="hidden" name="campaign_id" value="<?= $campaign['id'] ?>">
                                <button type="submit" class="px-3 py-1.5 border border-red-300 text-red-500 text-sm rounded-lg hover:bg-red-50">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Send Modal -->
<div id="sendModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-md mx-4 shadow-xl">
        <form method="POST">
            <input type="hidden" name="action" value="send_broadcast">
            <input type="hidden" name="campaign_id" id="sendCampaignId">
            
            <div class="p-4 border-b">
                <h3 class="font-semibold">📤 ส่ง Broadcast</h3>
                <p class="text-sm text-gray-500" id="sendCampaignName"></p>
            </div>
            
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">ส่งถึง</label>
                    <div class="space-y-2">
                        <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="target_type" value="all" checked class="mr-3">
                            <div>
                                <span class="font-medium">ผู้ติดตามทั้งหมด</span>
                                <p class="text-xs text-gray-500">ส่งถึงทุกคนที่ติดตาม LINE OA</p>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                            <input type="radio" name="target_type" value="tags" class="mr-3" onchange="toggleTagSelect()">
                            <div>
                                <span class="font-medium">เฉพาะ Tag ที่เลือก</span>
                                <p class="text-xs text-gray-500">ส่งเฉพาะลูกค้าที่มี Tag</p>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div id="tagSelectDiv" class="hidden">
                    <label class="block text-sm font-medium mb-2">เลือก Tags</label>
                    <div class="max-h-40 overflow-y-auto border rounded-lg p-2 space-y-1">
                        <?php foreach ($tags as $tag): ?>
                        <label class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                            <input type="checkbox" name="target_tags[]" value="<?= $tag['id'] ?>" class="mr-2">
                            <span class="w-3 h-3 rounded-full mr-2" style="background-color: <?= $tag['color'] ?>"></span>
                            <span class="text-sm"><?= htmlspecialchars($tag['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($tags)): ?>
                        <p class="text-gray-400 text-center py-2 text-sm">ยังไม่มี Tags</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t flex justify-end gap-2">
                <button type="button" onclick="closeSendModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-paper-plane mr-2"></i>ส่ง Broadcast
                </button>
            </div>
        </form>
    </div>
</div>

<!-- How it works -->
<div class="mt-6 bg-gradient-to-r from-blue-50 to-purple-50 border border-blue-200 rounded-xl p-6">
    <h4 class="font-semibold text-blue-800 mb-4 flex items-center">
        <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
        วิธีการทำงาน
    </h4>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="text-center">
            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <span class="text-blue-600 font-bold">1</span>
            </div>
            <p class="text-sm text-blue-700">เลือกสินค้าที่ต้องการโปรโมท</p>
        </div>
        <div class="text-center">
            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <span class="text-purple-600 font-bold">2</span>
            </div>
            <p class="text-sm text-purple-700">ระบบสร้าง Flex Carousel สวยงาม</p>
        </div>
        <div class="text-center">
            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <span class="text-green-600 font-bold">3</span>
            </div>
            <p class="text-sm text-green-700">ลูกค้ากด "สนใจ" → ติด Tag อัตโนมัติ</p>
        </div>
        <div class="text-center">
            <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-2">
                <span class="text-yellow-600 font-bold">4</span>
            </div>
            <p class="text-sm text-yellow-700">ใช้ Tag ส่ง Broadcast เฉพาะกลุ่ม</p>
        </div>
    </div>
</div>

<script>
// Product selection
const checkboxes = document.querySelectorAll('.product-checkbox');
const createBtn = document.getElementById('createBtn');
const selectedCount = document.getElementById('selectedCount');

function updateSelection() {
    const checked = document.querySelectorAll('.product-checkbox:checked').length;
    selectedCount.textContent = checked;
    createBtn.disabled = checked === 0 || checked > 10;
    
    if (checked > 10) {
        selectedCount.classList.add('text-red-600');
        selectedCount.classList.remove('text-green-600');
    } else {
        selectedCount.classList.remove('text-red-600');
        selectedCount.classList.add('text-green-600');
    }
}

checkboxes.forEach(cb => cb.addEventListener('change', updateSelection));

function clearSelection() {
    checkboxes.forEach(cb => cb.checked = false);
    updateSelection();
}

function filterProducts() {
    const categoryId = document.getElementById('categoryFilter')?.value || '';
    document.querySelectorAll('.product-item').forEach(item => {
        if (!categoryId || item.dataset.category === categoryId) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Send modal
function openSendModal(campaignId, campaignName) {
    document.getElementById('sendCampaignId').value = campaignId;
    document.getElementById('sendCampaignName').textContent = campaignName;
    document.getElementById('sendModal').classList.remove('hidden');
    document.getElementById('sendModal').classList.add('flex');
}

function closeSendModal() {
    document.getElementById('sendModal').classList.add('hidden');
    document.getElementById('sendModal').classList.remove('flex');
}

function toggleTagSelect() {
    const tagDiv = document.getElementById('tagSelectDiv');
    const isTagSelected = document.querySelector('input[name="target_type"][value="tags"]').checked;
    tagDiv.classList.toggle('hidden', !isTagSelected);
}

// Close modal on escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSendModal();
});

document.getElementById('sendModal').addEventListener('click', e => {
    if (e.target === document.getElementById('sendModal')) closeSendModal();
});
</script>

<?php require_once 'includes/footer.php'; ?>
