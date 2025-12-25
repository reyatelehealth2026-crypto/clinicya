<?php
/**
 * UnifiedShop - ระบบร้านค้ารวม V3.0
 * รวม ShopBot + BusinessBot เข้าด้วยกัน
 * รองรับ: Physical, Digital, Service, Booking, Content
 * Auto-detect ตารางที่มีอยู่ (products หรือ business_items)
 */

require_once __DIR__ . '/LineAPI.php';
require_once __DIR__ . '/FlexTemplates.php';

class UnifiedShop
{
    private $db;
    private $line;
    private $lineAccountId;
    private $settings;
    private $tableCache = [];
    
    // Item Types
    const TYPE_PHYSICAL = 'physical';
    const TYPE_DIGITAL = 'digital';
    const TYPE_SERVICE = 'service';
    const TYPE_BOOKING = 'booking';
    const TYPE_CONTENT = 'content';
    
    // Delivery Methods
    const DELIVER_SHIPPING = 'shipping';
    const DELIVER_EMAIL = 'email';
    const DELIVER_LINE = 'line';
    const DELIVER_DOWNLOAD = 'download';
    const DELIVER_ONSITE = 'onsite';

    public function __construct($db, $line = null, $lineAccountId = null)
    {
        $this->db = $db;
        $this->line = $line;
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }
    
    // ==================== TABLE DETECTION ====================
    
    /**
     * ตรวจสอบว่าตารางมีอยู่หรือไม่
     */
    private function tableExists($table)
    {
        $key = "exists_{$table}";
        if (isset($this->tableCache[$key])) return $this->tableCache[$key];

        try {
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            $this->tableCache[$key] = true;
        } catch (Exception $e) {
            $this->tableCache[$key] = false;
        }
        return $this->tableCache[$key];
    }
    
    /**
     * ตรวจสอบว่าตารางมี column หรือไม่
     */
    public function hasColumn($table, $column)
    {
        $key = "col_{$table}_{$column}";
        if (isset($this->tableCache[$key])) return $this->tableCache[$key];
        
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
            $this->tableCache[$key] = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->tableCache[$key] = false;
        }
        return $this->tableCache[$key];
    }
    
    /**
     * Get items table (auto-detect)
     * Priority: business_items only
     */
    public function getItemsTable()
    {
        if ($this->tableExists('business_items')) return 'business_items';
        return null;
    }
    
    /**
     * Get categories table (auto-detect)
     * Priority: product_categories > item_categories
     */
    public function getCategoriesTable()
    {
        if ($this->tableExists('product_categories')) return 'product_categories';
        if ($this->tableExists('item_categories')) return 'item_categories';
        return null;
    }
    
    /**
     * Get orders table (auto-detect)
     */
    public function getOrdersTable()
    {
        if ($this->tableExists('transactions')) return 'transactions';
        if ($this->tableExists('orders')) return 'orders';
        return null;
    }
    
    /**
     * Get order items table (auto-detect)
     */
    public function getOrderItemsTable()
    {
        if ($this->tableExists('transaction_items')) return 'transaction_items';
        if ($this->tableExists('order_items')) return 'order_items';
        return null;
    }
    
    /**
     * Check if shop is ready
     */
    public function isReady()
    {
        return $this->getItemsTable() !== null;
    }
    
    /**
     * Check if using V2.5 (deprecated - always false now)
     */
    public function isV25()
    {
        return false;
    }

    // ==================== SETTINGS ====================
    
    private function loadSettings()
    {
        try {
            // Try business_settings first (V2.5)
            if ($this->tableExists('business_settings')) {
                $stmt = $this->db->query("SELECT * FROM business_settings LIMIT 1");
                $this->settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                return;
            }
            
            // Fallback to shop_settings
            if ($this->tableExists('shop_settings')) {
                if ($this->lineAccountId && $this->hasColumn('shop_settings', 'line_account_id')) {
                    $stmt = $this->db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
                    $stmt->execute([$this->lineAccountId]);
                    $this->settings = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if (!$this->settings) {
                    $stmt = $this->db->query("SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1");
                    $this->settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                }
            }
        } catch (Exception $e) {
            $this->settings = [];
        }
        
        // Defaults
        if (empty($this->settings)) {
            $this->settings = [
                'shop_name' => 'LINE Shop',
                'welcome_message' => 'ยินดีต้อนรับ!',
                'shipping_fee' => 50,
                'free_shipping_min' => 500,
                'is_open' => 1
            ];
        }
    }
    
    public function getSettings()
    {
        return $this->settings;
    }
    
    public function getSetting($key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }
    
    // ==================== CATEGORIES ====================
    
    /**
     * Get all categories
     */
    public function getCategories($limit = 20)
    {
        $table = $this->getCategoriesTable();
        if (!$table) return [];
        
        $sql = "SELECT * FROM {$table} WHERE is_active = 1";
        $params = [];
        
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY sort_order ASC LIMIT " . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get category by ID
     */
    public function getCategory($id)
    {
        $table = $this->getCategoriesTable();
        if (!$table) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ==================== ITEMS/PRODUCTS ====================
    
    /**
     * Get all items
     */
    public function getItems($filters = [], $limit = 20)
    {
        $table = $this->getItemsTable();
        if (!$table) return [];
        
        $sql = "SELECT * FROM {$table} WHERE is_active = 1";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by category
        if (!empty($filters['category_id'])) {
            $sql .= " AND category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        // Filter by item_type (V2.5)
        if (!empty($filters['item_type']) && $this->hasColumn($table, 'item_type')) {
            $sql .= " AND item_type = ?";
            $params[] = $filters['item_type'];
        }
        
        // Filter by stock
        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $sql .= " AND stock > 0";
        }
        
        // Search
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY " . ($filters['order'] ?? 'id DESC');
        $sql .= " LIMIT " . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get item by ID
     */
    public function getItem($id)
    {
        $table = $this->getItemsTable();
        if (!$table) return null;
        
        $sql = "SELECT * FROM {$table} WHERE id = ? AND is_active = 1";
        $params = [$id];
        
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Parse action_data if exists
        if ($item && isset($item['action_data']) && is_string($item['action_data'])) {
            $item['action_data'] = json_decode($item['action_data'], true);
        }
        
        return $item;
    }
    
    /**
     * Get items by category
     */
    public function getItemsByCategory($categoryId, $limit = 20)
    {
        return $this->getItems(['category_id' => $categoryId, 'in_stock' => true], $limit);
    }
    
    /**
     * Search items
     */
    public function searchItems($keyword, $limit = 20)
    {
        return $this->getItems(['search' => $keyword], $limit);
    }
    
    /**
     * Get featured items
     */
    public function getFeaturedItems($limit = 10)
    {
        $table = $this->getItemsTable();
        if (!$table) return [];
        
        if ($this->hasColumn($table, 'is_featured')) {
            $sql = "SELECT * FROM {$table} WHERE is_active = 1 AND is_featured = 1";
        } else {
            $sql = "SELECT * FROM {$table} WHERE is_active = 1";
        }
        
        $params = [];
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==================== CART ====================
    
    /**
     * Get cart items
     */
    public function getCart($userId)
    {
        if (!$this->tableExists('cart_items')) return [];
        
        $itemsTable = $this->getItemsTable();
        if (!$itemsTable) return [];
        
        $stmt = $this->db->prepare("
            SELECT c.*, p.name, p.price, p.sale_price, p.image_url, p.stock,
                   " . ($this->hasColumn($itemsTable, 'item_type') ? "p.item_type, p.delivery_method" : "'physical' as item_type, 'shipping' as delivery_method") . "
            FROM cart_items c 
            JOIN {$itemsTable} p ON c.product_id = p.id 
            WHERE c.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get cart total
     */
    public function getCartTotal($userId)
    {
        $items = $this->getCart($userId);
        $total = 0;
        foreach ($items as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $total += $price * $item['quantity'];
        }
        return $total;
    }
    
    /**
     * Add to cart
     */
    public function addToCart($userId, $productId, $quantity = 1)
    {
        // Ensure cart_items table exists
        $this->ensureCartTable();
        
        $item = $this->getItem($productId);
        if (!$item) {
            return ['success' => false, 'error' => 'ไม่พบสินค้านี้'];
        }
        
        if ($item['stock'] < $quantity) {
            return ['success' => false, 'error' => "สินค้าเหลือไม่พอ (เหลือ {$item['stock']} ชิ้น)"];
        }
        
        // Check max quantity
        if (!empty($item['max_quantity']) && $quantity > $item['max_quantity']) {
            return ['success' => false, 'error' => "สั่งได้สูงสุด {$item['max_quantity']} ชิ้นต่อครั้ง"];
        }
        
        // Upsert
        $stmt = $this->db->prepare("
            INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ");
        $stmt->execute([$userId, $productId, $quantity, $quantity]);
        
        return ['success' => true, 'item' => $item];
    }
    
    /**
     * Update cart quantity
     */
    public function updateCartQuantity($userId, $productId, $quantity)
    {
        if ($quantity <= 0) {
            return $this->removeFromCart($userId, $productId);
        }
        
        $item = $this->getItem($productId);
        if (!$item) {
            return ['success' => false, 'error' => 'ไม่พบสินค้านี้'];
        }
        
        if ($item['stock'] < $quantity) {
            return ['success' => false, 'error' => "สินค้าเหลือไม่พอ (เหลือ {$item['stock']} ชิ้น)"];
        }
        
        $stmt = $this->db->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$quantity, $userId, $productId]);
        
        return ['success' => true];
    }
    
    /**
     * Remove from cart
     */
    public function removeFromCart($userId, $productId)
    {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$userId, $productId]);
        return ['success' => $stmt->rowCount() > 0];
    }
    
    /**
     * Clear cart
     */
    public function clearCart($userId)
    {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);
        return ['success' => true];
    }
    
    /**
     * Ensure cart_items table exists
     */
    private function ensureCartTable()
    {
        if ($this->tableExists('cart_items')) return;
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS cart_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_cart_item (user_id, product_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $this->tableCache['exists_cart_items'] = true;
    }

    // ==================== ORDERS ====================
    
    /**
     * Create order from cart
     */
    public function createOrder($userId, $shippingInfo = [])
    {
        $cartItems = $this->getCart($userId);
        if (empty($cartItems)) {
            return ['success' => false, 'error' => 'ตะกร้าว่างเปล่า'];
        }
        
        // Calculate totals
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $subtotal += $price * $item['quantity'];
        }
        
        // Shipping fee
        $shippingFee = $this->getSetting('shipping_fee', 50);
        $freeShippingMin = $this->getSetting('free_shipping_min', 500);
        if ($freeShippingMin > 0 && $subtotal >= $freeShippingMin) {
            $shippingFee = 0;
        }
        
        // Check if all items are digital (no shipping)
        $allDigital = true;
        foreach ($cartItems as $item) {
            $type = $item['item_type'] ?? 'physical';
            if ($type === 'physical') {
                $allDigital = false;
                break;
            }
        }
        if ($allDigital) $shippingFee = 0;
        
        $grandTotal = $subtotal + $shippingFee;
        
        // Generate order number
        $orderNumber = 'ORD' . date('ymdHis') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Determine which table to use
        $ordersTable = $this->getOrdersTable();
        $orderItemsTable = $this->getOrderItemsTable();
        
        // Create tables if not exist
        if (!$ordersTable) {
            $this->ensureOrderTables();
            $ordersTable = 'orders';
            $orderItemsTable = 'order_items';
        }
        
        try {
            $this->db->beginTransaction();
            
            // Insert order
            if ($ordersTable === 'transactions') {
                $stmt = $this->db->prepare("
                    INSERT INTO transactions 
                    (line_account_id, order_number, user_id, total_amount, shipping_fee, grand_total, 
                     shipping_name, shipping_phone, shipping_address, status, payment_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
                ");
                $stmt->execute([
                    $this->lineAccountId,
                    $orderNumber,
                    $userId,
                    $subtotal,
                    $shippingFee,
                    $grandTotal,
                    $shippingInfo['name'] ?? null,
                    $shippingInfo['phone'] ?? null,
                    $shippingInfo['address'] ?? null
                ]);
            } else {
                $cols = "order_number, user_id, subtotal, shipping_fee, grand_total, customer_name, customer_phone, shipping_address, status, payment_status";
                $vals = "?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending'";
                $params = [
                    $orderNumber, $userId, $subtotal, $shippingFee, $grandTotal,
                    $shippingInfo['name'] ?? null,
                    $shippingInfo['phone'] ?? null,
                    $shippingInfo['address'] ?? null
                ];
                
                if ($this->hasColumn('orders', 'line_account_id')) {
                    $cols = "line_account_id, " . $cols;
                    $vals = "?, " . $vals;
                    array_unshift($params, $this->lineAccountId);
                }
                
                $stmt = $this->db->prepare("INSERT INTO orders ({$cols}) VALUES ({$vals})");
                $stmt->execute($params);
            }
            
            $orderId = $this->db->lastInsertId();
            
            // Insert order items
            $itemsTable = $this->getItemsTable();
            foreach ($cartItems as $item) {
                $price = $item['sale_price'] ?: $item['price'];
                $itemSubtotal = $price * $item['quantity'];
                
                if ($orderItemsTable === 'transaction_items') {
                    $stmt = $this->db->prepare("
                        INSERT INTO transaction_items 
                        (transaction_id, product_id, product_name, item_type, product_price, quantity, subtotal)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $orderId, $item['product_id'], $item['name'],
                        $item['item_type'] ?? 'physical', $price, $item['quantity'], $itemSubtotal
                    ]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO order_items 
                        (order_id, product_id, product_name, price, quantity, subtotal)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $orderId, $item['product_id'], $item['name'],
                        $price, $item['quantity'], $itemSubtotal
                    ]);
                }
                
                // Reduce stock
                $stmt = $this->db->prepare("UPDATE {$itemsTable} SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            // Clear cart
            $this->clearCart($userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'grand_total' => $grandTotal,
                'items' => $cartItems
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get user orders
     */
    public function getOrders($userId, $limit = 20)
    {
        $table = $this->getOrdersTable();
        if (!$table) return [];
        
        $sql = "SELECT * FROM {$table} WHERE user_id = ?";
        $params = [$userId];
        
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order by ID or order_number
     */
    public function getOrder($identifier, $userId = null)
    {
        $table = $this->getOrdersTable();
        if (!$table) return null;
        
        $sql = "SELECT * FROM {$table} WHERE (id = ? OR order_number = ? OR order_number LIKE ?)";
        $params = [$identifier, $identifier, "%{$identifier}"];
        
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        if ($this->lineAccountId && $this->hasColumn($table, 'line_account_id')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order items
     */
    public function getOrderItems($orderId)
    {
        $table = $this->getOrderItemsTable();
        if (!$table) return [];
        
        $idCol = $table === 'transaction_items' ? 'transaction_id' : 'order_id';
        
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE {$idCol} = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update order status
     */
    public function updateOrderStatus($orderId, $status, $additionalData = [])
    {
        $table = $this->getOrdersTable();
        if (!$table) return false;
        
        $sets = ['status = ?'];
        $params = [$status];
        
        // Handle additional fields
        foreach ($additionalData as $key => $value) {
            if ($this->hasColumn($table, $key)) {
                $sets[] = "{$key} = ?";
                $params[] = $value;
            }
        }
        
        // Auto-set timestamps
        $timestampMap = [
            'paid' => 'paid_at',
            'shipping' => 'shipped_at',
            'delivered' => 'delivered_at',
            'cancelled' => 'cancelled_at'
        ];
        
        if (isset($timestampMap[$status]) && $this->hasColumn($table, $timestampMap[$status])) {
            $sets[] = "{$timestampMap[$status]} = NOW()";
        }
        
        $params[] = $orderId;
        
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Update payment status
     */
    public function updatePaymentStatus($orderId, $status)
    {
        $table = $this->getOrdersTable();
        if (!$table) return false;
        
        $stmt = $this->db->prepare("UPDATE {$table} SET payment_status = ? WHERE id = ?");
        return $stmt->execute([$status, $orderId]);
    }
    
    /**
     * Ensure order tables exist
     */
    private function ensureOrderTables()
    {
        if ($this->tableExists('orders')) return;
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            order_number VARCHAR(50) NOT NULL,
            status ENUM('pending', 'confirmed', 'paid', 'processing', 'shipping', 'delivered', 'completed', 'cancelled', 'refunded') DEFAULT 'pending',
            payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
            payment_method VARCHAR(50),
            subtotal DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            shipping_fee DECIMAL(10,2) DEFAULT 0,
            grand_total DECIMAL(10,2) DEFAULT 0,
            customer_name VARCHAR(255),
            customer_phone VARCHAR(20),
            shipping_address TEXT,
            tracking_number VARCHAR(100),
            slip_image VARCHAR(500),
            notes TEXT,
            paid_at TIMESTAMP NULL,
            shipped_at TIMESTAMP NULL,
            delivered_at TIMESTAMP NULL,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_order_number (order_number),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT,
            product_name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            subtotal DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $this->tableCache['exists_orders'] = true;
        $this->tableCache['exists_order_items'] = true;
    }

    // ==================== USER STATE (for checkout flow) ====================
    
    /**
     * Get user state
     */
    public function getUserState($userId)
    {
        if (!$this->tableExists('user_states')) return null;
        
        $stmt = $this->db->prepare("SELECT * FROM user_states WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$userId]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($state && isset($state['state_data'])) {
            $state['state_data'] = json_decode($state['state_data'], true);
        }
        
        return $state;
    }
    
    /**
     * Set user state
     */
    public function setUserState($userId, $state, $data = [], $expiresMinutes = 30)
    {
        $this->ensureUserStateTable();
        
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));
        
        $stmt = $this->db->prepare("
            INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE state = ?, state_data = ?, expires_at = ?, updated_at = NOW()
        ");
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt->execute([$userId, $state, $dataJson, $expiresAt, $state, $dataJson, $expiresAt]);
        
        return true;
    }
    
    /**
     * Clear user state
     */
    public function clearUserState($userId)
    {
        if (!$this->tableExists('user_states')) return true;
        
        $stmt = $this->db->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->execute([$userId]);
        return true;
    }
    
    /**
     * Ensure user_states table exists
     */
    private function ensureUserStateTable()
    {
        if ($this->tableExists('user_states')) return;
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS user_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            state VARCHAR(50) NOT NULL,
            state_data JSON,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_state (user_id),
            INDEX idx_state (state),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $this->tableCache['exists_user_states'] = true;
    }
    
    // ==================== BEHAVIOR TRACKING ====================
    
    /**
     * Track user behavior
     */
    public function trackBehavior($userId, $type, $data = [])
    {
        if (!$this->tableExists('user_behaviors')) return;
        
        try {
            $stmt = $this->db->prepare("INSERT INTO user_behaviors (user_id, behavior_type, behavior_data) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $type, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        } catch (Exception $e) {
            // Ignore errors
        }
    }
    
    // ==================== PAYMENT SLIPS ====================
    
    /**
     * Save payment slip (use transaction_id - unified with LIFF)
     */
    public function savePaymentSlip($orderId, $userId, $imageUrl, $amount = null)
    {
        $this->ensurePaymentSlipTable();
        
        $stmt = $this->db->prepare("
            INSERT INTO payment_slips (transaction_id, user_id, image_url, amount, status) 
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$orderId, $userId, $imageUrl, $amount]);
        
        // Update order slip_image if column exists
        $ordersTable = $this->getOrdersTable();
        if ($ordersTable && $this->hasColumn($ordersTable, 'slip_image')) {
            $stmt = $this->db->prepare("UPDATE {$ordersTable} SET slip_image = ? WHERE id = ?");
            $stmt->execute([$imageUrl, $orderId]);
        }
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Ensure payment_slips table exists
     */
    private function ensurePaymentSlipTable()
    {
        if ($this->tableExists('payment_slips')) return;
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS payment_slips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,
            transaction_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            image_url VARCHAR(500) NOT NULL,
            amount DECIMAL(10,2),
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            verified_by INT,
            verified_at TIMESTAMP NULL,
            reject_reason TEXT,
            admin_note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            INDEX idx_transaction (transaction_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $this->tableCache['exists_payment_slips'] = true;
    }

    // ==================== LINE BOT INTEGRATION ====================
    
    /**
     * Process LINE message
     */
    public function processMessage($userId, $userDbId, $message, $replyToken)
    {
        if (!$this->line) return null;
        
        $text = mb_strtolower(trim($message));
        
        // Check user state first
        $state = $this->getUserState($userDbId);
        if ($state) {
            return $this->handleStatefulMessage($userId, $userDbId, $message, $replyToken, $state);
        }
        
        // Command routing
        $commands = [
            'menu' => 'showMainMenu', 'เมนู' => 'showMainMenu', 'help' => 'showMainMenu', '?' => 'showMainMenu',
            'shop' => 'showCategories', 'ร้าน' => 'showCategories', 'สินค้า' => 'showCategories', 'ซื้อ' => 'showCategories',
            'cart' => 'showCart', 'ตะกร้า' => 'showCart',
            'checkout' => 'startCheckout', 'สั่งซื้อ' => 'startCheckout', 'ชำระเงิน' => 'startCheckout', 'ยืนยันสั่งซื้อ' => 'startCheckout',
            'orders' => 'showOrders', 'order' => 'showOrders', 'คำสั่งซื้อ' => 'showOrders', 'ออเดอร์' => 'showOrders',
            'contact' => 'showContact', 'ติดต่อ' => 'showContact',
            'clear' => 'clearCartCommand', 'ล้างตะกร้า' => 'clearCartCommand',
        ];
        
        foreach ($commands as $cmd => $method) {
            if ($text === $cmd || mb_strpos($text, $cmd) !== false) {
                return $this->$method($userId, $userDbId, $replyToken);
            }
        }
        
        // Pattern matching
        if (preg_match('/^(add|เพิ่ม)\s*(\d+)(?:\s+(\d+))?$/iu', $text, $matches)) {
            return $this->addToCartCommand($userId, $userDbId, (int)$matches[2], (int)($matches[3] ?? 1), $replyToken);
        }
        
        if (preg_match('/^(remove|ลบ|ลบสินค้า)\s*(\d+)$/iu', $text, $matches)) {
            return $this->removeFromCartCommand($userId, $userDbId, (int)$matches[2], $replyToken);
        }
        
        if (preg_match('/^(item|product|สินค้า|ดู)\s*(\d+)$/iu', $text, $matches)) {
            return $this->showItemDetail($userId, $userDbId, (int)$matches[2], $replyToken);
        }
        
        if (preg_match('/^(cat|category|หมวด|หมวดหมู่)\s*(\d+)$/iu', $text, $matches)) {
            return $this->showCategoryItems($userId, $userDbId, (int)$matches[2], $replyToken);
        }
        
        if (preg_match('/^(search|ค้นหา|หา)\s+(.+)$/iu', $text, $matches)) {
            return $this->searchItemsCommand($userId, $userDbId, $matches[2], $replyToken);
        }
        
        if (preg_match('/^(order|ออเดอร์|คำสั่งซื้อ)\s+#?(ORD)?([0-9]+)$/iu', $text, $matches)) {
            return $this->showOrderDetail($userId, $userDbId, $matches[3], $replyToken);
        }
        
        return null; // Not handled
    }
    
    /**
     * Handle stateful message (checkout flow)
     */
    private function handleStatefulMessage($userId, $userDbId, $message, $replyToken, $state)
    {
        $stateName = $state['state'];
        $stateData = $state['state_data'] ?? [];
        
        switch ($stateName) {
            case 'checkout_name':
                $stateData['name'] = $message;
                $this->setUserState($userDbId, 'checkout_phone', $stateData);
                return $this->replyText($replyToken, "📱 กรุณาพิมพ์เบอร์โทรศัพท์:");
                
            case 'checkout_phone':
                $stateData['phone'] = $message;
                $this->setUserState($userDbId, 'checkout_address', $stateData);
                return $this->replyText($replyToken, "📍 กรุณาพิมพ์ที่อยู่จัดส่ง:");
                
            case 'checkout_address':
                $stateData['address'] = $message;
                $this->clearUserState($userDbId);
                return $this->completeCheckout($userId, $userDbId, $stateData, $replyToken);
                
            case 'awaiting_slip':
                // Handle slip upload (image message)
                return $this->replyText($replyToken, "📤 กรุณาส่งรูปสลิปการโอนเงิน");
                
            default:
                $this->clearUserState($userDbId);
                return null;
        }
    }
    
    /**
     * Reply with text
     */
    private function replyText($replyToken, $text)
    {
        return $this->line->replyMessage($replyToken, [['type' => 'text', 'text' => $text]]);
    }
    
    /**
     * Reply with flex
     */
    private function replyFlex($replyToken, $flex, $altText = 'ข้อความ')
    {
        return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, $altText)]);
    }

    // ==================== BOT COMMANDS ====================
    
    public function showMainMenu($userId, $userDbId, $replyToken)
    {
        $shopName = $this->getSetting('shop_name', 'LINE Shop');
        $flex = FlexTemplates::mainMenu($shopName);
        return $this->replyFlex($replyToken, $flex, 'เมนูหลัก');
    }
    
    public function showCategories($userId, $userDbId, $replyToken)
    {
        $categories = $this->getCategories(10);
        
        if (empty($categories)) {
            return $this->replyText($replyToken, "📦 ยังไม่มีหมวดหมู่สินค้า");
        }
        
        $bubbles = [];
        foreach ($categories as $cat) {
            $bubble = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $cat['name'], 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                        ['type' => 'text', 'text' => $cat['description'] ?? 'ดูสินค้าในหมวดนี้', 'size' => 'sm', 'color' => '#888888', 'margin' => 'md', 'wrap' => true]
                    ],
                    'paddingAll' => 'xl'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูสินค้า', 'text' => "หมวด {$cat['id']}"], 'style' => 'primary', 'color' => '#06C755']
                    ]
                ]
            ];
            
            if (!empty($cat['image_url'])) {
                $bubble['hero'] = [
                    'type' => 'image', 'url' => $cat['image_url'], 'size' => 'full',
                    'aspectRatio' => '1:1', 'aspectMode' => 'cover',
                    'action' => ['type' => 'message', 'text' => "หมวด {$cat['id']}"]
                ];
            }
            
            $bubbles[] = $bubble;
        }
        
        $flex = ['type' => 'carousel', 'contents' => $bubbles];
        return $this->replyFlex($replyToken, $flex, 'หมวดหมู่สินค้า');
    }
    
    public function showCategoryItems($userId, $userDbId, $categoryId, $replyToken)
    {
        $items = $this->getItemsByCategory($categoryId, 10);
        
        if (empty($items)) {
            return $this->replyText($replyToken, "📦 ไม่มีสินค้าในหมวดนี้");
        }
        
        $flex = $this->buildItemsCarousel($items);
        return $this->replyFlex($replyToken, $flex, 'สินค้า');
    }
    
    public function showItemDetail($userId, $userDbId, $itemId, $replyToken)
    {
        $item = $this->getItem($itemId);
        
        if (!$item) {
            return $this->replyText($replyToken, "❌ ไม่พบสินค้านี้");
        }
        
        $flex = FlexTemplates::productCard($item);
        $this->trackBehavior($userDbId, 'view_item', ['item_id' => $itemId]);
        return $this->replyFlex($replyToken, $flex, $item['name']);
    }
    
    public function searchItemsCommand($userId, $userDbId, $keyword, $replyToken)
    {
        $items = $this->searchItems($keyword, 10);
        
        if (empty($items)) {
            return $this->replyText($replyToken, "🔍 ไม่พบสินค้าที่ค้นหา '{$keyword}'");
        }
        
        $flex = $this->buildItemsCarousel($items);
        return $this->replyFlex($replyToken, $flex, "ผลการค้นหา: {$keyword}");
    }
    
    public function showCart($userId, $userDbId, $replyToken)
    {
        $items = $this->getCart($userDbId);
        
        if (empty($items)) {
            $flex = FlexTemplates::info('ตะกร้าว่าง', 'ยังไม่มีสินค้าในตะกร้า', [['label' => '🛒 ไปช้อป', 'text' => 'shop']]);
            return $this->replyFlex($replyToken, $flex, 'ตะกร้าว่าง');
        }
        
        $total = 0;
        $cartItems = [];
        foreach ($items as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $subtotal = $price * $item['quantity'];
            $total += $subtotal;
            $cartItems[] = ['name' => $item['name'], 'quantity' => $item['quantity'], 'subtotal' => $subtotal];
        }
        
        $flex = FlexTemplates::cartSummary($cartItems, $total, count($items));
        return $this->replyFlex($replyToken, $flex, 'ตะกร้าสินค้า');
    }
    
    public function addToCartCommand($userId, $userDbId, $productId, $qty, $replyToken)
    {
        $result = $this->addToCart($userDbId, $productId, $qty);
        
        if (!$result['success']) {
            return $this->replyText($replyToken, "❌ " . $result['error']);
        }
        
        $item = $result['item'];
        $flex = FlexTemplates::success(
            'เพิ่มลงตะกร้าแล้ว!',
            "{$item['name']} x{$qty}",
            [['label' => '🛍️ ดูตะกร้า', 'text' => 'cart'], ['label' => '🛒 ช้อปต่อ', 'text' => 'shop']]
        );
        
        $this->trackBehavior($userDbId, 'add_to_cart', ['item_id' => $productId, 'quantity' => $qty]);
        return $this->replyFlex($replyToken, $flex, 'เพิ่มลงตะกร้า');
    }
    
    public function removeFromCartCommand($userId, $userDbId, $productId, $replyToken)
    {
        $result = $this->removeFromCart($userDbId, $productId);
        
        if ($result['success']) {
            return $this->replyText($replyToken, "✅ ลบสินค้าออกจากตะกร้าแล้ว\n\nพิมพ์ 'ตะกร้า' เพื่อดูตะกร้า");
        }
        return $this->replyText($replyToken, "❌ ไม่พบสินค้านี้ในตะกร้า");
    }
    
    public function clearCartCommand($userId, $userDbId, $replyToken)
    {
        $this->clearCart($userDbId);
        return $this->replyText($replyToken, "🗑️ ล้างตะกร้าแล้ว\n\nพิมพ์ 'shop' เพื่อดูสินค้า");
    }

    public function startCheckout($userId, $userDbId, $replyToken)
    {
        $items = $this->getCart($userDbId);
        
        if (empty($items)) {
            return $this->replyText($replyToken, "❌ ตะกร้าว่างเปล่า\n\nพิมพ์ 'shop' เพื่อดูสินค้า");
        }
        
        // Check if all items are digital (skip shipping info)
        $allDigital = true;
        foreach ($items as $item) {
            $type = $item['item_type'] ?? 'physical';
            if ($type === 'physical') {
                $allDigital = false;
                break;
            }
        }
        
        if ($allDigital) {
            // Skip shipping info for digital items
            return $this->completeCheckout($userId, $userDbId, [], $replyToken);
        }
        
        // Start checkout flow - ask for name
        $this->setUserState($userDbId, 'checkout_name', []);
        return $this->replyText($replyToken, "📝 กรุณาพิมพ์ชื่อ-นามสกุล ผู้รับสินค้า:");
    }
    
    private function completeCheckout($userId, $userDbId, $shippingInfo, $replyToken)
    {
        $result = $this->createOrder($userDbId, $shippingInfo);
        
        if (!$result['success']) {
            return $this->replyText($replyToken, "❌ " . $result['error']);
        }
        
        // Build order confirmation flex
        $settings = $this->getSettings();
        $orderNum = str_replace('ORD', '', $result['order_number']);
        
        // Build items list
        $itemsContent = [];
        foreach ($result['items'] as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $subtotal = $price * $item['quantity'];
            $itemsContent[] = [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => "{$item['name']} x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => '฿' . number_format($subtotal), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                ]
            ];
        }
        
        // Payment info
        $paymentContents = [];
        if (!empty($settings['promptpay_number'])) {
            $paymentContents[] = [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => '💚 พร้อมเพย์:', 'size' => 'sm', 'flex' => 1],
                    ['type' => 'text', 'text' => $settings['promptpay_number'], 'size' => 'sm', 'flex' => 2, 'weight' => 'bold']
                ]
            ];
        }
        
        $bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
        foreach ($bankAccounts as $bank) {
            $paymentContents[] = [
                'type' => 'box', 'layout' => 'vertical', 'margin' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => "🏦 {$bank['name']}", 'size' => 'sm', 'weight' => 'bold'],
                    ['type' => 'text', 'text' => "{$bank['account']} ({$bank['holder']})", 'size' => 'xs', 'color' => '#888888']
                ]
            ];
        }
        
        $bubble = [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ!', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'xl'],
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderNum}", 'color' => '#FFFFFF', 'size' => 'sm', 'margin' => 'sm']
                ],
                'backgroundColor' => '#06C755', 'paddingAll' => 'xl'
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '📦 รายการสินค้า', 'weight' => 'bold', 'size' => 'md', 'color' => '#06C755'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $itemsContent, 'margin' => 'md', 'spacing' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'lg', 'contents' => [
                        ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#888888'],
                        ['type' => 'text', 'text' => $result['shipping_fee'] > 0 ? '฿' . number_format($result['shipping_fee']) : 'ฟรี!', 'size' => 'sm', 'align' => 'end', 'color' => $result['shipping_fee'] > 0 ? '#333333' : '#06C755']
                    ]],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                        ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => '฿' . number_format($result['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end']
                    ]],
                    ['type' => 'separator', 'margin' => 'xl'],
                    ['type' => 'text', 'text' => '💳 ช่องทางชำระเงิน', 'weight' => 'bold', 'size' => 'md', 'color' => '#06C755', 'margin' => 'xl'],
                    ['type' => 'box', 'layout' => 'vertical', 'contents' => $paymentContents, 'margin' => 'md', 'spacing' => 'sm']
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => '📤 ชำระเงินแล้ว ส่งสลิปมาได้เลย!', 'size' => 'sm', 'align' => 'center', 'color' => '#F59E0B', 'weight' => 'bold'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูออเดอร์ของฉัน', 'text' => 'orders'], 'style' => 'secondary', 'height' => 'sm', 'margin' => 'md']
                ],
                'paddingAll' => 'lg'
            ]
        ];
        
        $this->trackBehavior($userDbId, 'checkout', ['order_id' => $result['order_id'], 'total' => $result['grand_total']]);
        return $this->replyFlex($replyToken, $bubble, "ออเดอร์ #{$orderNum}");
    }

    public function showOrders($userId, $userDbId, $replyToken)
    {
        $orders = $this->getOrders($userDbId, 10);
        
        if (empty($orders)) {
            return $this->replyText($replyToken, "📋 ยังไม่มีคำสั่งซื้อ\n\nพิมพ์ 'shop' เพื่อเริ่มช้อปปิ้ง!");
        }
        
        $bubbles = [];
        foreach ($orders as $order) {
            $orderItems = $this->getOrderItems($order['id']);
            $statusInfo = $this->getStatusInfo($order['status']);
            $orderNum = str_replace('ORD', '', $order['order_number']);
            
            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box', 'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                            ['type' => 'text', 'text' => "#{$orderNum}", 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                            ['type' => 'text', 'text' => "{$statusInfo['emoji']} {$statusInfo['text']}", 'size' => 'sm', 'color' => $statusInfo['color'], 'align' => 'end', 'weight' => 'bold']
                        ]],
                        ['type' => 'separator', 'margin' => 'md'],
                        ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => [
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'วันที่สั่ง:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                ['type' => 'text', 'text' => date('d/m/Y', strtotime($order['created_at'])), 'size' => 'xs', 'align' => 'end', 'flex' => 1]
                            ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'รายการ:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                ['type' => 'text', 'text' => count($orderItems) . ' รายการ', 'size' => 'xs', 'align' => 'end', 'flex' => 1]
                            ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                                ['type' => 'text', 'text' => 'ยอดรวม:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'size' => 'md', 'color' => '#06C755', 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
                            ]]
                        ]]
                    ]
                ],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูรายละเอียด', 'text' => "ออเดอร์ {$orderNum}"], 'style' => 'link', 'height' => 'sm', 'color' => '#06C755']
                    ]
                ]
            ];
        }
        
        $flex = ['type' => 'carousel', 'contents' => array_slice($bubbles, 0, 10)];
        return $this->replyFlex($replyToken, $flex, 'คำสั่งซื้อของคุณ');
    }
    
    public function showOrderDetail($userId, $userDbId, $orderNum, $replyToken)
    {
        $order = $this->getOrder($orderNum, $userDbId);
        
        if (!$order) {
            return $this->replyText($replyToken, "❌ ไม่พบคำสั่งซื้อ #{$orderNum}");
        }
        
        $items = $this->getOrderItems($order['id']);
        $statusInfo = $this->getStatusInfo($order['status']);
        $settings = $this->getSettings();
        $orderNum = str_replace('ORD', '', $order['order_number']);
        
        // Build items content
        $itemsContent = [];
        foreach ($items as $item) {
            $itemsContent[] = [
                'type' => 'box', 'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => "{$item['product_name']} x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                ]
            ];
        }
        
        // Footer buttons
        $footerContents = [];
        if ($order['status'] === 'pending' && ($order['payment_status'] ?? 'pending') === 'pending') {
            $footerContents[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📤 อัพโหลดสลิป', 'text' => 'โอนแล้ว'], 'style' => 'primary', 'color' => '#06C755'];
        }
        $footerContents[] = ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '📞 ติดต่อเรา', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link'];
        
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderNum}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'contents' => [
                        ['type' => 'text', 'text' => "{$statusInfo['emoji']} {$statusInfo['text']}", 'size' => 'sm', 'color' => $statusInfo['color'], 'weight' => 'bold']
                    ]],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'lg', 'contents' => [
                        ['type' => 'text', 'text' => 'วันที่สั่งซื้อ:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                        ['type' => 'text', 'text' => $this->formatThaiDate($order['created_at']), 'size' => 'xs', 'align' => 'end', 'flex' => 2]
                    ]],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => $itemsContent],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'lg', 'contents' => [
                        ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                        ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                    ]]
                ]
            ],
            'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => $footerContents, 'paddingAll' => 'lg']
        ];
        
        return $this->replyFlex($replyToken, $bubble, "ออเดอร์ #{$orderNum}");
    }
    
    public function showContact($userId, $userDbId, $replyToken)
    {
        $settings = $this->getSettings();
        $shopName = $settings['shop_name'] ?? 'LINE Shop';
        $phone = $settings['contact_phone'] ?? '';
        
        $text = "📞 ติดต่อ {$shopName}\n\n";
        if ($phone) $text .= "📱 โทร: {$phone}\n";
        $text .= "\n💬 หรือพิมพ์ข้อความมาได้เลย!";
        
        return $this->replyText($replyToken, $text);
    }
    
    // ==================== HELPERS ====================
    
    private function buildItemsCarousel($items)
    {
        $bubbles = [];
        foreach ($items as $item) {
            $bubbles[] = $this->buildItemCard($item);
        }
        return ['type' => 'carousel', 'contents' => $bubbles];
    }
    
    private function buildItemCard($item)
    {
        $itemType = $item['item_type'] ?? 'physical';
        $price = $item['sale_price'] ?? $item['price'];
        
        $typeIcon = match($itemType) {
            'digital' => '🎮', 'service' => '💆', 'booking' => '📅', 'content' => '📚', default => '📦'
        };
        
        $priceContents = [['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755']];
        if (!empty($item['sale_price']) && $item['sale_price'] < $item['price']) {
            $priceContents[] = ['type' => 'text', 'text' => '฿' . number_format($item['price']), 'size' => 'xs', 'color' => '#AAAAAA', 'decoration' => 'line-through', 'margin' => 'sm'];
        }
        
        $stockText = $item['stock'] > 0 ? "📦 เหลือ {$item['stock']} ชิ้น" : "❌ สินค้าหมด";
        if ($itemType === 'digital') $stockText = $item['stock'] > 0 ? "✅ พร้อมส่งทันที" : "❌ หมด";
        
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => "{$typeIcon} {$item['name']}", 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => $priceContents, 'margin' => 'md'],
                    ['type' => 'text', 'text' => $stockText, 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "add {$item['id']}"], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 รายละเอียด', 'text' => "item {$item['id']}"], 'style' => 'secondary', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ]
        ];
        
        if (!empty($item['image_url'])) {
            $bubble['hero'] = [
                'type' => 'image', 'url' => $item['image_url'], 'size' => 'full',
                'aspectRatio' => '1:1', 'aspectMode' => 'cover',
                'action' => ['type' => 'message', 'text' => "item {$item['id']}"]
            ];
        }
        
        return $bubble;
    }
    
    private function getStatusInfo($status)
    {
        $map = [
            'pending' => ['text' => 'รอชำระเงิน', 'color' => '#FF6B6B', 'emoji' => '⏳'],
            'confirmed' => ['text' => 'ยืนยันแล้ว', 'color' => '#4ECDC4', 'emoji' => '✅'],
            'paid' => ['text' => 'ชำระเงินแล้ว', 'color' => '#45B7D1', 'emoji' => '💰'],
            'processing' => ['text' => 'กำลังเตรียมสินค้า', 'color' => '#F59E0B', 'emoji' => '📦'],
            'shipping' => ['text' => 'กำลังจัดส่ง', 'color' => '#96CEB4', 'emoji' => '🚚'],
            'delivered' => ['text' => 'จัดส่งแล้ว', 'color' => '#4CAF50', 'emoji' => '📦'],
            'completed' => ['text' => 'เสร็จสิ้น', 'color' => '#4CAF50', 'emoji' => '✅'],
            'cancelled' => ['text' => 'ยกเลิก', 'color' => '#9E9E9E', 'emoji' => '❌'],
            'refunded' => ['text' => 'คืนเงินแล้ว', 'color' => '#9E9E9E', 'emoji' => '💸']
        ];
        return $map[$status] ?? ['text' => $status, 'color' => '#666666', 'emoji' => '📋'];
    }
    
    private function formatThaiDate($datetime)
    {
        $timestamp = strtotime($datetime);
        $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $day = date('j', $timestamp);
        $month = $thaiMonths[(int)date('n', $timestamp)];
        $year = date('Y', $timestamp) + 543;
        $time = date('H:i', $timestamp);
        return "{$day} {$month} {$year} เวลา {$time}";
    }
}
