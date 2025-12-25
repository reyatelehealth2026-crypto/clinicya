<?php
/**
 * LINE Shop Bot - จัดการคำสั่งซื้อผ่าน LINE
 * V2.5 - รองรับ Multi-bot
 */

class ShopBot
{
    private $db;
    private $line;
    private $lineAccountId;
    private $columnCache = []; // Cache สำหรับเก็บผลการตรวจสอบ column

    public function __construct($db, $line, $lineAccountId = null)
    {
        $this->db = $db;
        $this->line = $line;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * ตรวจสอบว่าตารางมี column line_account_id หรือไม่ (พร้อม cache)
     */
    private function hasLineAccountColumn($table)
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'line_account_id'");
            $this->columnCache[$table] = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->columnCache[$table] = false;
        }
        
        return $this->columnCache[$table];
    }
    
    /**
     * ตรวจสอบว่าตารางมีอยู่หรือไม่
     */
    private function tableExists($table)
    {
        $cacheKey = "table_exists_{$table}";
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }
        
        try {
            $this->db->query("SELECT 1 FROM {$table} LIMIT 1");
            $this->columnCache[$cacheKey] = true;
        } catch (Exception $e) {
            $this->columnCache[$cacheKey] = false;
        }
        
        return $this->columnCache[$cacheKey];
    }
    
    /**
     * ตรวจสอบว่า Shop module พร้อมใช้งานหรือไม่
     */
    private function isShopReady()
    {
        return $this->tableExists('product_categories') && $this->tableExists('business_items');
    }

    /**
     * Handle shop commands
     */
    public function handleCommand($text, $userId, $dbUserId, $replyToken)
    {
        $text = trim($text);
        $textLower = mb_strtolower($text);

        // Shop commands - ตรวจสอบว่า Shop พร้อมใช้งานก่อน
        if (in_array($textLower, ['shop', 'ร้าน', 'สินค้า', 'ซื้อ'])) {
            return $this->showMainMenu($replyToken);
        }

        if (in_array($textLower, ['cart', 'ตะกร้า', 'รถเข็น'])) {
            if (!$this->isShopReady()) {
                return $this->line->replyMessage($replyToken, "🛒 ระบบร้านค้ายังไม่พร้อมใช้งาน");
            }
            return $this->showCart($dbUserId, $replyToken);
        }

        // Show orders list
        if (in_array($text, ['order', 'คำสั่งซื้อ', 'ออเดอร์', 'Order', 'ORDER'])) {
            if (!$this->tableExists('orders')) {
                return $this->line->replyMessage($replyToken, "📋 ยังไม่มีระบบคำสั่งซื้อ");
            }
            return $this->showOrders($dbUserId, $replyToken);
        }

        // View order detail: "ออเดอร์ 123" or "order 123" or "ออเดอร์ ORD202512040842" or "#123"
        if (preg_match('/^(order|ออเดอร์|คำสั่งซื้อ)\s+#?(ORD)?([0-9]+)$/iu', $text, $matches)) {
            return $this->showOrderDetail($dbUserId, $matches[3], $replyToken);
        }
        // Also match just "#123" or "ORD123"
        if (preg_match('/^#?(ORD)?([0-9]+)$/i', $text, $matches) && strlen($matches[2]) >= 6) {
            return $this->showOrderDetail($dbUserId, $matches[2], $replyToken);
        }

        // Add to cart: "add 1" or "เพิ่ม 1"
        if (preg_match('/^(add|เพิ่ม)\s+(\d+)(?:\s+(\d+))?$/iu', $text, $matches)) {
            $productId = $matches[2];
            $qty = $matches[3] ?? 1;
            return $this->addToCart($dbUserId, $productId, $qty, $replyToken);
        }

        // Remove from cart: "ลบ 1" or "remove 1" or "🗑️ ลบสินค้า 1"
        if (preg_match('/^(remove|ลบ|ลบสินค้า|🗑️\s*ลบสินค้า)\s+(\d+)$/iu', $text, $matches)) {
            return $this->removeFromCart($dbUserId, $matches[2], $replyToken);
        }

        // View product: "view 1" or "ดู 1"
        if (preg_match('/^(view|ดู|รายละเอียด)\s+(\d+)$/iu', $text, $matches)) {
            return $this->showProduct($matches[2], $replyToken);
        }

        // Checkout - รองรับทั้งมี emoji และไม่มี
        if (in_array($text, ['checkout', 'สั่งซื้อ', 'ชำระเงิน', 'จ่ายเงิน', 'ยืนยันสั่งซื้อ', '✅ ยืนยันสั่งซื้อ', 'Checkout']) || mb_strpos($text, 'ยืนยันสั่งซื้อ') !== false) {
            return $this->checkout($dbUserId, $replyToken);
        }

        // Clear cart - รองรับทั้งมี emoji และไม่มี
        if (in_array($text, ['clear', 'ล้างตะกร้า', 'ลบตะกร้า', 'เคลียร์ตะกร้าทั้งหมด', '🗑️ เคลียร์ตะกร้าทั้งหมด', 'Clear']) || mb_strpos($text, 'เคลียร์ตะกร้า') !== false) {
            return $this->clearCart($dbUserId, $replyToken);
        }

        // View category: "หมวด xxx" or "category xxx"
        if (preg_match('/^(หมวด|หมวดหมู่|category|cat)\s+(.+)$/iu', $text, $matches)) {
            return $this->showCategory($matches[2], $replyToken);
        }

        // Search products: "ค้นหา xxx" or "search xxx"
        if (preg_match('/^(ค้นหา|หา|search|find)\s+(.+)$/iu', $text, $matches)) {
            return $this->searchProducts($matches[2], $replyToken);
        }

        return false; // Not a shop command
    }

    /**
     * Get shop settings (รองรับ Multi-bot)
     */
    private function getShopSettings()
    {
        try {
            if ($this->lineAccountId) {
                $stmt = $this->db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
                $stmt->execute([$this->lineAccountId]);
                $settings = $stmt->fetch();
                if ($settings) return $settings;
            }
            
            // Fallback to default
            $stmt = $this->db->query("SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1");
            return $stmt->fetch() ?: [];
        } catch (Exception $e) {
            // Table doesn't exist, return defaults
            return [
                'shop_name' => 'LINE Shop',
                'welcome_message' => 'ยินดีต้อนรับ!'
            ];
        }
    }
    
    /**
     * Get categories (รองรับ Multi-bot)
     */
    private function getCategories($limit = 10)
    {
        $limit = (int)$limit;
        $sql = "SELECT * FROM product_categories WHERE is_active = 1";
        
        if ($this->lineAccountId && $this->hasLineAccountColumn('product_categories')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $sql .= " ORDER BY sort_order LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->lineAccountId]);
        } else {
            $sql .= " ORDER BY sort_order LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }
    
    /**
     * Get products by category (รองรับ Multi-bot)
     */
    private function getProductsByCategory($categoryId, $limit = 10)
    {
        $limit = (int)$limit;
        $sql = "SELECT * FROM business_items WHERE category_id = ? AND is_active = 1";
        
        if ($this->lineAccountId && $this->hasLineAccountColumn('business_items')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $sql .= " ORDER BY id DESC LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$categoryId, $this->lineAccountId]);
        } else {
            $sql .= " ORDER BY id DESC LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$categoryId]);
        }
        return $stmt->fetchAll();
    }
    
    /**
     * Get product by ID (รองรับ Multi-bot)
     */
    private function getProduct($productId)
    {
        $sql = "SELECT * FROM business_items WHERE id = ? AND is_active = 1";
        
        if ($this->lineAccountId && $this->hasLineAccountColumn('business_items')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId, $this->lineAccountId]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);
        }
        return $stmt->fetch();
    }

    /**
     * Format Thai date
     */
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

    /**
     * Get status info
     */
    private function getStatusInfo($status)
    {
        $statusMap = [
            'pending' => ['text' => 'รอชำระเงิน', 'color' => '#FF6B6B', 'emoji' => '⏳'],
            'confirmed' => ['text' => 'ยืนยันแล้ว', 'color' => '#4ECDC4', 'emoji' => '✅'],
            'paid' => ['text' => 'ชำระเงินแล้ว', 'color' => '#45B7D1', 'emoji' => '💰'],
            'shipping' => ['text' => 'กำลังจัดส่ง', 'color' => '#96CEB4', 'emoji' => '🚚'],
            'delivered' => ['text' => 'จัดส่งแล้ว', 'color' => '#4CAF50', 'emoji' => '📦'],
            'cancelled' => ['text' => 'ยกเลิก', 'color' => '#9E9E9E', 'emoji' => '❌']
        ];
        return $statusMap[$status] ?? ['text' => $status, 'color' => '#666666', 'emoji' => '📋'];
    }


    /**
     * Show user's orders - Flex Carousel
     */
    public function showOrders($dbUserId, $replyToken)
    {
        $sql = "SELECT * FROM orders WHERE user_id = ?";
        if ($this->lineAccountId && $this->hasLineAccountColumn('orders')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $sql .= " ORDER BY created_at DESC LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dbUserId, $this->lineAccountId]);
        } else {
            $sql .= " ORDER BY created_at DESC LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dbUserId]);
        }
        $orders = $stmt->fetchAll();

        if (empty($orders)) {
            return $this->line->replyMessage($replyToken, "📋 ยังไม่มีคำสั่งซื้อ\n\nพิมพ์ 'shop' เพื่อเริ่มช้อปปิ้ง!");
        }

        $bubbles = [];
        foreach ($orders as $order) {
            // Get order items count
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM order_items WHERE order_id = ?");
            $stmt->execute([$order['id']]);
            $itemCount = $stmt->fetch()['count'];

            $statusInfo = $this->getStatusInfo($order['status']);
            $orderNum = str_replace('ORD', '', $order['order_number']);

            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => "#{$orderNum}", 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                                ['type' => 'text', 'text' => "{$statusInfo['emoji']} {$statusInfo['text']}", 'size' => 'sm', 'color' => $statusInfo['color'], 'align' => 'end', 'weight' => 'bold']
                            ]
                        ],
                        ['type' => 'separator', 'margin' => 'md'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'วันที่สั่ง:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                        ['type' => 'text', 'text' => date('d/m/Y', strtotime($order['created_at'])), 'size' => 'xs', 'align' => 'end', 'flex' => 1]
                                    ]
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'รายการ:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                        ['type' => 'text', 'text' => "{$itemCount} รายการ", 'size' => 'xs', 'align' => 'end', 'flex' => 1]
                                    ]
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'ยอดรวม:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                                        ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'size' => 'md', 'color' => '#06C755', 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูรายละเอียด', 'text' => "ออเดอร์ {$orderNum}"], 'style' => 'link', 'height' => 'sm', 'color' => '#06C755']
                    ]
                ]
            ];
        }

        $flex = ['type' => 'carousel', 'contents' => array_slice($bubbles, 0, 10)];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'คำสั่งซื้อของคุณ', 'contents' => $flex]
        ]);
    }

    /**
     * Show order detail - Full Flex Message
     */
    public function showOrderDetail($dbUserId, $orderNum, $replyToken)
    {
        // Find order - รองรับหลายรูปแบบ
        $sql = "SELECT * FROM orders WHERE user_id = ? AND (
            order_number = ? OR 
            order_number = ? OR 
            order_number LIKE ? OR
            order_number LIKE ?
        )";
        if ($this->lineAccountId && $this->hasLineAccountColumn('orders')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dbUserId, $orderNum, "ORD{$orderNum}", "%{$orderNum}%", "{$orderNum}%", $this->lineAccountId]);
        } else {
            $sql .= " ORDER BY created_at DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$dbUserId, $orderNum, "ORD{$orderNum}", "%{$orderNum}%", "{$orderNum}%"]);
        }
        $order = $stmt->fetch();

        if (!$order) {
            return $this->line->replyMessage($replyToken, "❌ ไม่พบคำสั่งซื้อ #{$orderNum}");
        }

        // Get order items
        $stmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll();

        $statusInfo = $this->getStatusInfo($order['status']);
        $settings = $this->getShopSettings();
        $orderNum = str_replace('ORD', '', $order['order_number']);

        // Build items content
        $itemsContent = [];
        foreach ($items as $item) {
            $itemsContent[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => "{$item['product_name']} x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => '฿' . number_format($item['subtotal']), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                ]
            ];
        }

        // Build footer buttons based on status
        $footerContents = [];
        if ($order['status'] === 'pending' && $order['payment_status'] === 'pending') {
            $footerContents[] = ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📤 อัพโหลดสลิป', 'text' => 'โอนแล้ว'], 'style' => 'primary', 'color' => '#06C755'];
        }
        $footerContents[] = ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '📞 ติดต่อเรา', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link'];

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    // Header
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderNum}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => "{$statusInfo['emoji']} {$statusInfo['text']}", 'size' => 'sm', 'color' => $statusInfo['color'], 'weight' => 'bold']
                        ]
                    ],
                    // Order date
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'วันที่สั่งซื้อ:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $this->formatThaiDate($order['created_at']), 'size' => 'xs', 'align' => 'end', 'flex' => 2]
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    // Items header
                    ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
                    // Items
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $itemsContent
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    // Total
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => $footerContents
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => "ออเดอร์ #{$orderNum}", 'contents' => $bubble]
        ]);
    }


    /**
     * Show shopping cart - Flex Carousel with summary
     */
    public function showCart($dbUserId, $replyToken)
    {
        $stmt = $this->db->prepare("SELECT c.*, p.name, p.price, p.sale_price, p.image_url 
                                    FROM cart_items c 
                                    JOIN business_items p ON c.product_id = p.id 
                                    WHERE c.user_id = ?");
        $stmt->execute([$dbUserId]);
        $items = $stmt->fetchAll();

        if (empty($items)) {
            return $this->line->replyMessage($replyToken, "🛒 ตะกร้าว่างเปล่า\n\nพิมพ์ 'shop' เพื่อดูสินค้า");
        }

        $bubbles = [];
        $total = 0;
        $itemCount = 0;

        // Product bubbles
        foreach ($items as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $subtotal = $price * $item['quantity'];
            $total += $subtotal;
            $itemCount += $item['quantity'];

            $bubble = [
                'type' => 'bubble',
                'size' => 'kilo',
                'hero' => $item['image_url'] ? [
                    'type' => 'image',
                    'url' => $item['image_url'],
                    'size' => 'full',
                    'aspectRatio' => '1:1',
                    'aspectMode' => 'cover'
                ] : null,
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $item['name'], 'weight' => 'bold', 'size' => 'sm', 'wrap' => true, 'maxLines' => 2],
                        ['type' => 'text', 'text' => '฿' . number_format($price) . ' x ' . $item['quantity'], 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm'],
                        ['type' => 'text', 'text' => 'รวม: ฿' . number_format($subtotal), 'size' => 'md', 'color' => '#06C755', 'weight' => 'bold', 'margin' => 'sm']
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🗑️ ลบสินค้า', 'text' => "ลบ {$item['product_id']}"], 'style' => 'secondary', 'height' => 'sm']
                    ]
                ]
            ];

            if (!$bubble['hero']) unset($bubble['hero']);
            $bubbles[] = $bubble;
        }

        // Summary bubble
        $summaryBubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => 'สรุปรายการ', 'weight' => 'bold', 'size' => 'lg', 'color' => '#06C755'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'จำนวนรายการ', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => count($items) . ' รายการ', 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($total), 'weight' => 'bold', 'size' => 'lg', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '✅ ยืนยันสั่งซื้อ', 'text' => 'ยืนยันสั่งซื้อ'], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🗑️ เคลียร์ตะกร้าทั้งหมด', 'text' => 'เคลียร์ตะกร้าทั้งหมด'], 'style' => 'secondary']
                ]
            ]
        ];
        $bubbles[] = $summaryBubble;

        $flex = ['type' => 'carousel', 'contents' => array_slice($bubbles, 0, 10)];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'ตะกร้าสินค้า', 'contents' => $flex]
        ]);
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart($dbUserId, $productId, $replyToken)
    {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$dbUserId, $productId]);

        if ($stmt->rowCount() > 0) {
            return $this->line->replyMessage($replyToken, "✅ ลบสินค้าออกจากตะกร้าแล้ว\n\nพิมพ์ 'ตะกร้า' เพื่อดูตะกร้า");
        }
        return $this->line->replyMessage($replyToken, "❌ ไม่พบสินค้านี้ในตะกร้า");
    }

    /**
     * Checkout - create order with Flex Message
     */
    public function checkout($dbUserId, $replyToken)
    {
        try {
            // Get cart items
            $stmt = $this->db->prepare("SELECT c.*, p.name, p.price, p.sale_price, p.image_url FROM cart_items c JOIN business_items p ON c.product_id = p.id WHERE c.user_id = ?");
            $stmt->execute([$dbUserId]);
            $items = $stmt->fetchAll();

            if (empty($items)) {
                return $this->line->replyMessage($replyToken, "❌ ตะกร้าว่างเปล่า\n\nพิมพ์ 'shop' เพื่อดูสินค้า");
            }

        // Calculate total
        $total = 0;
        foreach ($items as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $total += $price * $item['quantity'];
        }

        // Get shipping fee
        $settings = $this->getShopSettings();
        $shippingFee = $settings['shipping_fee'] ?? 0;
        $freeShippingMin = $settings['free_shipping_min'] ?? 0;

        if ($freeShippingMin > 0 && $total >= $freeShippingMin) {
            $shippingFee = 0;
        }

        $grandTotal = $total + $shippingFee;

        // Create order
        $orderNumber = date('ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        if ($this->lineAccountId && $this->hasLineAccountColumn('orders')) {
            $stmt = $this->db->prepare("INSERT INTO orders (line_account_id, order_number, user_id, total_amount, shipping_fee, grand_total, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$this->lineAccountId, $orderNumber, $dbUserId, $total, $shippingFee, $grandTotal]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO orders (order_number, user_id, total_amount, shipping_fee, grand_total, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$orderNumber, $dbUserId, $total, $shippingFee, $grandTotal]);
        }
        $orderId = $this->db->lastInsertId();

        // Add order items
        $itemsContent = [];
        foreach ($items as $item) {
            $price = $item['sale_price'] ?: $item['price'];
            $subtotal = $price * $item['quantity'];

            $stmt = $this->db->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orderId, $item['product_id'], $item['name'], $price, $item['quantity'], $subtotal]);

            $itemsContent[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => "{$item['name']}  x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => '฿' . number_format($subtotal), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                ]
            ];
        }

        // Clear cart
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$dbUserId]);

        // Get payment info
        $bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
        $paymentContents = [];
        if (!empty($settings['promptpay_number'])) {
            $paymentContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => '💚', 'size' => 'sm', 'flex' => 0],
                    ['type' => 'text', 'text' => 'พร้อมเพย์: ' . $settings['promptpay_number'], 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
                ]
            ];
        }
        foreach ($bankAccounts as $bank) {
            $paymentContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            ['type' => 'text', 'text' => '🏦', 'size' => 'sm', 'flex' => 0],
                            ['type' => 'text', 'text' => "{$bank['name']}: {$bank['account']}", 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
                        ]
                    ],
                    ['type' => 'text', 'text' => "   ชื่อ: {$bank['holder']}", 'size' => 'xs', 'color' => '#888888']
                ]
            ];
        }
        // ถ้าไม่มีช่องทางชำระเงิน ให้แสดงข้อความ
        if (empty($paymentContents)) {
            $paymentContents[] = ['type' => 'text', 'text' => 'กรุณาติดต่อร้านค้า', 'size' => 'sm', 'color' => '#888888'];
        }

        // Build Flex Message
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    // Header
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderNumber}", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => '⏳ รอชำระเงิน', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold']
                        ]
                    ],
                    // Order date
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'วันที่สั่งซื้อ:', 'size' => 'xs', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $this->formatThaiDate(date('Y-m-d H:i:s')), 'size' => 'xs', 'align' => 'end', 'flex' => 2]
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    // Items
                    ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $itemsContent
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    // Total
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดรวมทั้งหมด', 'weight' => 'bold', 'size' => 'sm', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($grandTotal), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    // Payment info
                    ['type' => 'text', 'text' => '📌 ช่องทางชำระเงิน:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $paymentContents
                    ],
                    // Note
                    ['type' => 'text', 'text' => '📸 กรุณาส่งรูปสลิปมาเลย', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true],
                    ['type' => 'text', 'text' => '(ภายใน 10 นาที)', 'size' => 'xs', 'color' => '#888888']
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📤 อัพโหลดสลิป', 'text' => 'โอนแล้ว'], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'uri', 'label' => '📞 ติดต่อเรา', 'uri' => 'tel:' . ($settings['contact_phone'] ?? '0000000000')], 'style' => 'link']
                ]
            ]
        ];

            return $this->line->replyMessage($replyToken, [
                ['type' => 'flex', 'altText' => "ออเดอร์ #{$orderNumber}", 'contents' => $bubble]
            ]);
        } catch (Exception $e) {
            error_log("Checkout error: " . $e->getMessage());
            return $this->line->replyMessage($replyToken, "❌ เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง");
        }
    }


    /**
     * Show products in category
     */
    public function showCategory($categoryName, $replyToken)
    {
        // Find category
        $sql = "SELECT * FROM product_categories WHERE name LIKE ? AND is_active = 1";
        if ($this->lineAccountId && $this->hasLineAccountColumn('product_categories')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['%' . $categoryName . '%', $this->lineAccountId]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['%' . $categoryName . '%']);
        }
        $category = $stmt->fetch();

        if (!$category) {
            return $this->line->replyMessage($replyToken, "❌ ไม่พบหมวดหมู่ '{$categoryName}'\n\nพิมพ์ 'shop' เพื่อดูหมวดหมู่ทั้งหมด");
        }

        $products = $this->getProductsByCategory($category['id']);

        if (empty($products)) {
            return $this->line->replyMessage($replyToken, "📦 หมวด '{$category['name']}' ยังไม่มีสินค้า");
        }

        return $this->showProductCarousel($products, $category['name'], $replyToken);
    }

    /**
     * Search products
     */
    public function searchProducts($keyword, $replyToken)
    {
        $searchTerm = '%' . $keyword . '%';
        $sql = "SELECT * FROM business_items WHERE (name LIKE ? OR description LIKE ?) AND is_active = 1";
        if ($this->lineAccountId && $this->hasLineAccountColumn('business_items')) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $sql .= " ORDER BY id DESC LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $this->lineAccountId]);
        } else {
            $sql .= " ORDER BY id DESC LIMIT 10";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm]);
        }
        $products = $stmt->fetchAll();

        if (empty($products)) {
            return $this->line->replyMessage($replyToken, "🔍 ไม่พบสินค้าที่ค้นหา '{$keyword}'\n\nพิมพ์ 'shop' เพื่อดูสินค้าทั้งหมด");
        }

        return $this->showProductCarousel($products, "ผลการค้นหา: {$keyword}", $replyToken);
    }

    /**
     * Show product carousel
     */
    private function showProductCarousel($products, $title, $replyToken)
    {
        $bubbles = [];

        foreach ($products as $product) {
            $price = $product['sale_price'] ?: $product['price'];

            $bubble = [
                'type' => 'bubble',
                'size' => 'kilo',
                'hero' => $product['image_url'] ? [
                    'type' => 'image',
                    'url' => $product['image_url'],
                    'size' => 'full',
                    'aspectRatio' => '1:1',
                    'aspectMode' => 'cover'
                ] : null,
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $product['name'], 'weight' => 'bold', 'size' => 'md', 'wrap' => true, 'maxLines' => 2],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'margin' => 'md',
                            'contents' => $product['sale_price'] ? [
                                ['type' => 'text', 'text' => '฿' . number_format($product['price']), 'size' => 'sm', 'color' => '#999999', 'decoration' => 'line-through', 'flex' => 0],
                                ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xl', 'color' => '#06C755', 'weight' => 'bold', 'margin' => 'sm', 'flex' => 0]
                            ] : [
                                ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xl', 'color' => '#06C755', 'weight' => 'bold', 'flex' => 0]
                            ]
                        ],
                        ['type' => 'text', 'text' => "คงเหลือ {$product['stock']} ชิ้น", 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm']
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "เพิ่ม {$product['id']}"], 'style' => 'primary', 'color' => '#06C755', 'height' => 'sm']
                    ]
                ]
            ];

            if (!$bubble['hero']) unset($bubble['hero']);
            $bubbles[] = $bubble;
        }

        $flex = ['type' => 'carousel', 'contents' => $bubbles];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'text', 'text' => "📦 {$title} (" . count($products) . " รายการ)"],
            ['type' => 'flex', 'altText' => $title, 'contents' => $flex]
        ]);
    }

    /**
     * Show main shop menu with categories
     */
    public function showMainMenu($replyToken)
    {
        try {
            // ตรวจสอบว่า Shop module พร้อมใช้งานหรือไม่
            if (!$this->isShopReady()) {
                return $this->line->replyMessage($replyToken, "🛒 ระบบร้านค้ายังไม่พร้อมใช้งาน\n\nกรุณาติดต่อผู้ดูแลระบบเพื่อตั้งค่าร้านค้า");
            }
            
            $categories = $this->getCategories();
            $settings = $this->getShopSettings();
        } catch (Exception $e) {
            error_log("ShopBot showMainMenu error: " . $e->getMessage());
            return $this->line->replyMessage($replyToken, "🛒 ระบบร้านค้ายังไม่พร้อมใช้งาน\n\nกรุณาติดต่อผู้ดูแลระบบ");
        }

        $bubbles = [];

        // Welcome bubble
        $bubbles[] = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '🛍️ ' . ($settings['shop_name'] ?? 'LINE Shop'), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => $settings['welcome_message'] ?? 'ยินดีต้อนรับ!', 'size' => 'sm', 'color' => '#666666', 'margin' => 'md', 'wrap' => true]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ดูตะกร้า', 'text' => 'ตะกร้า'], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 คำสั่งซื้อของฉัน', 'text' => 'คำสั่งซื้อ'], 'style' => 'secondary']
                ]
            ]
        ];

        // Category bubbles
        foreach ($categories as $cat) {
            $products = $this->getProductsByCategory($cat['id'], 3);

            $productContents = [];
            foreach ($products as $p) {
                $price = $p['sale_price'] ?: $p['price'];
                $productContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => $p['name'], 'size' => 'sm', 'flex' => 3, 'wrap' => true, 'maxLines' => 1],
                        ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'sm', 'color' => '#06C755', 'align' => 'end', 'flex' => 1, 'weight' => 'bold']
                    ]
                ];
            }

            if (empty($productContents)) continue;

            $bubbles[] = [
                'type' => 'bubble',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => array_merge(
                        [['type' => 'text', 'text' => $cat['name'], 'weight' => 'bold', 'size' => 'lg', 'color' => '#06C755']],
                        [['type' => 'separator', 'margin' => 'md']],
                        [['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => $productContents]]
                    )
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูทั้งหมด', 'text' => 'หมวด ' . $cat['name']], 'style' => 'primary', 'color' => '#06C755', 'height' => 'sm']
                    ]
                ]
            ];
        }

        $flex = ['type' => 'carousel', 'contents' => array_slice($bubbles, 0, 10)];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'เมนูร้านค้า', 'contents' => $flex]
        ]);
    }

    /**
     * Add product to cart
     */
    public function addToCart($dbUserId, $productId, $qty, $replyToken)
    {
        $product = $this->getProduct($productId);

        if (!$product) {
            return $this->line->replyMessage($replyToken, "❌ ไม่พบสินค้านี้");
        }

        if ($product['stock'] < $qty) {
            return $this->line->replyMessage($replyToken, "❌ สินค้าคงเหลือไม่พอ (เหลือ {$product['stock']} ชิ้น)");
        }

        $stmt = $this->db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?) 
                                    ON DUPLICATE KEY UPDATE quantity = quantity + ?");
        $stmt->execute([$dbUserId, $productId, $qty, $qty]);

        $price = $product['sale_price'] ?: $product['price'];

        // Flex confirmation
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ เพิ่มลงตะกร้าแล้ว!', 'weight' => 'bold', 'size' => 'lg', 'color' => '#06C755'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => $product['name'], 'size' => 'sm', 'flex' => 2, 'wrap' => true],
                            ['type' => 'text', 'text' => "x{$qty}", 'size' => 'sm', 'color' => '#888888', 'flex' => 0],
                            ['type' => 'text', 'text' => '฿' . number_format($price * $qty), 'size' => 'sm', 'color' => '#06C755', 'weight' => 'bold', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'horizontal',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ดูตะกร้า', 'text' => 'ตะกร้า'], 'style' => 'primary', 'color' => '#06C755', 'flex' => 1],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛍️ ช้อปต่อ', 'text' => 'shop'], 'style' => 'secondary', 'flex' => 1]
                ]
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'เพิ่มลงตะกร้าแล้ว', 'contents' => $bubble]
        ]);
    }

    /**
     * Clear cart
     */
    public function clearCart($dbUserId, $replyToken)
    {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$dbUserId]);

        return $this->line->replyMessage($replyToken, "🗑️ ล้างตะกร้าแล้ว\n\nพิมพ์ 'shop' เพื่อดูสินค้า");
    }

    /**
     * Show single product
     */
    public function showProduct($productId, $replyToken)
    {
        $sql = "SELECT p.*, c.name as category_name FROM business_items p LEFT JOIN product_categories c ON p.category_id = c.id WHERE p.id = ? AND p.is_active = 1";
        if ($this->lineAccountId && $this->hasLineAccountColumn('business_items')) {
            $sql .= " AND (p.line_account_id = ? OR p.line_account_id IS NULL)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId, $this->lineAccountId]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$productId]);
        }
        $product = $stmt->fetch();

        if (!$product) {
            return $this->line->replyMessage($replyToken, "❌ ไม่พบสินค้านี้");
        }

        $price = $product['sale_price'] ?: $product['price'];

        $priceContents = $product['sale_price'] ? [
            ['type' => 'text', 'text' => '฿' . number_format($product['price']), 'size' => 'md', 'color' => '#999999', 'decoration' => 'line-through', 'flex' => 0],
            ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xxl', 'color' => '#06C755', 'weight' => 'bold', 'margin' => 'sm', 'flex' => 0]
        ] : [
            ['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'xxl', 'color' => '#06C755', 'weight' => 'bold', 'flex' => 0]
        ];

        $bubble = [
            'type' => 'bubble',
            'hero' => $product['image_url'] ? [
                'type' => 'image',
                'url' => $product['image_url'],
                'size' => 'full',
                'aspectRatio' => '1:1',
                'aspectMode' => 'cover'
            ] : null,
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $product['name'], 'weight' => 'bold', 'size' => 'xl', 'wrap' => true],
                    ['type' => 'text', 'text' => $product['category_name'] ?? '', 'size' => 'sm', 'color' => '#999999', 'margin' => 'sm'],
                    ['type' => 'box', 'layout' => 'baseline', 'margin' => 'lg', 'contents' => $priceContents],
                    ['type' => 'text', 'text' => $product['description'] ?? 'ไม่มีรายละเอียด', 'size' => 'sm', 'color' => '#666666', 'margin' => 'lg', 'wrap' => true],
                    ['type' => 'text', 'text' => "📦 คงเหลือ: {$product['stock']} ชิ้น", 'size' => 'sm', 'color' => $product['stock'] > 0 ? '#06C755' : '#FF6B6B', 'margin' => 'lg', 'weight' => 'bold']
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "เพิ่ม {$product['id']}"], 'style' => 'primary', 'color' => '#06C755']
                ]
            ]
        ];

        if (!$bubble['hero']) unset($bubble['hero']);

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => $product['name'], 'contents' => $bubble]
        ]);
    }
}
