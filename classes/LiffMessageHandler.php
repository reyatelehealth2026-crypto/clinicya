<?php
/**
 * LIFF Message Handler
 * Processes LIFF-triggered messages and replies with Flex Messages
 * 
 * Requirements: 20.3, 20.9, 20.12
 * - Process LIFF-triggered messages
 * - Reply with Flex Messages
 * - Reply with order summary Flex Message including items, total, and tracking link
 */

class LiffMessageHandler {
    private $db;
    private $line;
    private $lineAccountId;
    
    // LIFF action message patterns
    // Requirements: 20.4, 20.5, 20.6, 20.7, 20.8
    private $patterns = [
        'order_placed' => '/^สั่งซื้อสำเร็จ\s*#?(\S+)/u',
        'appointment_booked' => '/^นัดหมายสำเร็จ\s*(.+)/u',
        'consult_request' => '/^ขอปรึกษาเภสัชกร/u',
        'points_redeemed' => '/^แลกแต้มสำเร็จ\s*(\d+)\s*แต้ม/u',
        'health_updated' => '/^อัพเดทข้อมูลสุขภาพ/u'
    ];
    
    public function __construct($db, $line, $lineAccountId = null) {
        $this->db = $db;
        $this->line = $line;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Check if message is a LIFF-triggered action
     * @param string $message - Message text
     * @return string|null - Action type or null
     */
    public function detectLiffAction($message) {
        foreach ($this->patterns as $action => $pattern) {
            if (preg_match($pattern, $message)) {
                return $action;
            }
        }
        return null;
    }

    /**
     * Process LIFF-triggered message and return Flex Message reply
     * Requirements: 20.3, 20.9
     * @param string $message - Message text
     * @param int $userId - Database user ID
     * @param string $lineUserId - LINE user ID
     * @return array|null - Flex Message or null
     */
    public function processMessage($message, $userId, $lineUserId) {
        $action = $this->detectLiffAction($message);
        
        if (!$action) {
            return null;
        }
        
        // Log the LIFF action
        $this->logAction($action, $message, $userId, $lineUserId);
        
        switch ($action) {
            case 'order_placed':
                return $this->handleOrderPlaced($message, $userId);
                
            case 'appointment_booked':
                return $this->handleAppointmentBooked($message, $userId);
                
            case 'consult_request':
                return $this->handleConsultRequest($userId, $lineUserId);
                
            case 'points_redeemed':
                return $this->handlePointsRedeemed($message, $userId);
                
            case 'health_updated':
                return $this->handleHealthUpdated($userId);
                
            default:
                return null;
        }
    }
    
    /**
     * Handle order placed message
     * Requirements: 20.4, 20.12
     */
    private function handleOrderPlaced($message, $userId) {
        // Extract order ID from message
        preg_match($this->patterns['order_placed'], $message, $matches);
        $orderId = $matches[1] ?? '';
        
        if (empty($orderId)) {
            return null;
        }
        
        // Get order details from database
        $order = $this->getOrderDetails($orderId, $userId);
        
        if (!$order) {
            // Return simple confirmation if order not found
            return $this->createSimpleOrderConfirmation($orderId);
        }
        
        // Create detailed order confirmation Flex Message
        return $this->createOrderConfirmationFlex($order);
    }

    /**
     * Get order details from database
     */
    private function getOrderDetails($orderId, $userId) {
        try {
            // Try transactions table first
            $stmt = $this->db->prepare("
                SELECT t.*, u.display_name 
                FROM transactions t 
                LEFT JOIN users u ON t.user_id = u.id
                WHERE (t.id = ? OR t.order_number = ?) 
                AND t.user_id = ?
                AND t.transaction_type = 'purchase'
            ");
            $stmt->execute([$orderId, $orderId, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                // Get order items
                $stmt = $this->db->prepare("
                    SELECT ti.*, COALESCE(p.name, ti.product_name) as name,
                           COALESCE(p.image_url, '') as image
                    FROM transaction_items ti
                    LEFT JOIN business_items p ON ti.product_id = p.id
                    WHERE ti.transaction_id = ?
                ");
                $stmt->execute([$order['id']]);
                $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                return $order;
            }
        } catch (Exception $e) {
            error_log("LiffMessageHandler getOrderDetails error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Create simple order confirmation when order details not found
     */
    private function createSimpleOrderConfirmation($orderId) {
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#11B0A6',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => "ออเดอร์ #{$orderId}", 
                     'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'ขอบคุณที่สั่งซื้อค่ะ', 
                     'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'md'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '⏳ รอตรวจสอบการชำระเงิน', 
                     'size' => 'sm', 'color' => '#F59E0B', 'align' => 'center', 'margin' => 'lg']
                ]
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => "✅ สั่งซื้อสำเร็จ #{$orderId}",
            'contents' => $bubble
        ];
    }

    /**
     * Create detailed order confirmation Flex Message
     * Requirements: 20.12
     */
    private function createOrderConfirmationFlex($order) {
        $orderId = $order['order_number'] ?? $order['id'];
        $total = $order['grand_total'] ?? $order['total_amount'] ?? 0;
        $items = $order['items'] ?? [];
        $itemCount = count($items);
        $status = $order['status'] ?? 'pending';
        
        // Get shop name
        $shopName = $this->getShopName();
        
        // Get LIFF URL for tracking
        $liffUrl = $this->getLiffTrackingUrl($orderId);
        
        // Build items preview (max 3 items)
        $itemContents = [];
        $displayItems = array_slice($items, 0, 3);
        foreach ($displayItems as $item) {
            $itemContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => $item['name'] ?? 'สินค้า', 
                     'size' => 'sm', 'color' => '#555555', 'flex' => 3, 'wrap' => true],
                    ['type' => 'text', 'text' => 'x' . ($item['quantity'] ?? 1), 
                     'size' => 'sm', 'color' => '#888888', 'align' => 'end', 'flex' => 1]
                ],
                'margin' => 'sm'
            ];
        }
        
        if ($itemCount > 3) {
            $itemContents[] = [
                'type' => 'text',
                'text' => '... และอีก ' . ($itemCount - 3) . ' รายการ',
                'size' => 'xs',
                'color' => '#888888',
                'margin' => 'sm'
            ];
        }
        
        // Status config
        $statusConfig = [
            'pending' => ['icon' => '⏳', 'text' => 'รอชำระเงิน', 'color' => '#F59E0B'],
            'paid' => ['icon' => '💰', 'text' => 'ชำระแล้ว', 'color' => '#10B981'],
            'confirmed' => ['icon' => '✅', 'text' => 'ยืนยันแล้ว', 'color' => '#10B981'],
            'packing' => ['icon' => '📦', 'text' => 'กำลังแพ็ค', 'color' => '#3B82F6'],
            'shipping' => ['icon' => '🚚', 'text' => 'กำลังจัดส่ง', 'color' => '#8B5CF6'],
            'delivered' => ['icon' => '✅', 'text' => 'จัดส่งแล้ว', 'color' => '#10B981']
        ];
        $statusInfo = $statusConfig[$status] ?? $statusConfig['pending'];
        
        $bodyContents = [
            ['type' => 'text', 'text' => "ออเดอร์ #{$orderId}", 
             'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
            ['type' => 'text', 'text' => $shopName, 
             'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'sm'],
            ['type' => 'separator', 'margin' => 'lg']
        ];
        
        // Add items
        if (!empty($itemContents)) {
            $bodyContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'contents' => $itemContents
            ];
            $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
        }
        
        // Add total
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => 'ยอดรวม', 'size' => 'md', 'color' => '#555555', 'flex' => 1],
                ['type' => 'text', 'text' => '฿' . number_format($total, 2), 
                 'size' => 'lg', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1]
            ]
        ];
        
        // Add status
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'paddingAll' => 'md',
            'backgroundColor' => '#F8FAFC',
            'cornerRadius' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => $statusInfo['icon'] . ' ' . $statusInfo['text'], 
                 'size' => 'sm', 'color' => $statusInfo['color'], 'weight' => 'bold', 'align' => 'center']
            ]
        ];
        
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#11B0A6',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => $bodyContents
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => []
            ]
        ];
        
        // Add tracking button if LIFF URL available
        if (!empty($liffUrl)) {
            $bubble['footer']['contents'][] = [
                'type' => 'button',
                'action' => ['type' => 'uri', 'label' => '📦 ติดตามออเดอร์', 'uri' => $liffUrl],
                'style' => 'primary',
                'color' => '#11B0A6',
                'height' => 'sm'
            ];
        }
        
        return [
            'type' => 'flex',
            'altText' => "✅ สั่งซื้อสำเร็จ #{$orderId} - ฿" . number_format($total, 2),
            'contents' => $bubble
        ];
    }

    /**
     * Handle appointment booked message
     * Requirements: 20.5
     */
    private function handleAppointmentBooked($message, $userId) {
        preg_match($this->patterns['appointment_booked'], $message, $matches);
        $dateTime = $matches[1] ?? '';
        
        // Parse date and time
        $parts = preg_split('/\s+/', trim($dateTime), 2);
        $date = $parts[0] ?? '';
        $time = $parts[1] ?? '';
        
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#3B82F6',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '📅 นัดหมายสำเร็จ', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => 'ปรึกษาเภสัชกร', 
                     'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => '📆 วันที่', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $date ?: '-', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'sm',
                        'contents' => [
                            ['type' => 'text', 'text' => '⏰ เวลา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $time ?: '-', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '🔔 จะแจ้งเตือนก่อนนัด 30 นาที', 
                     'size' => 'xs', 'color' => '#888888', 'align' => 'center', 'margin' => 'lg']
                ]
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => "📅 นัดหมายสำเร็จ {$dateTime}",
            'contents' => $bubble
        ];
    }
    
    /**
     * Handle consultation request message
     * Requirements: 20.6
     */
    private function handleConsultRequest($userId, $lineUserId) {
        // Create consultation queue entry
        $this->createConsultationQueue($userId, $lineUserId);
        
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#9333EA',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '👨‍⚕️ ขอปรึกษาเภสัชกร', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => 'ได้รับคำขอปรึกษาแล้ว', 
                     'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'เภสัชกรจะติดต่อกลับโดยเร็วที่สุด', 
                     'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '⏳ กรุณารอสักครู่...', 
                     'size' => 'sm', 'color' => '#F59E0B', 'align' => 'center', 'margin' => 'lg']
                ]
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => '👨‍⚕️ ขอปรึกษาเภสัชกร - รอเภสัชกรติดต่อกลับ',
            'contents' => $bubble
        ];
    }

    /**
     * Handle points redeemed message
     * Requirements: 20.7
     */
    private function handlePointsRedeemed($message, $userId) {
        preg_match($this->patterns['points_redeemed'], $message, $matches);
        $points = (int)($matches[1] ?? 0);
        
        // Get remaining points
        $remainingPoints = $this->getUserPoints($userId);
        
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#F59E0B',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '🎁 แลกแต้มสำเร็จ', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => 'แลกแต้มเรียบร้อย', 
                     'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'แต้มที่ใช้', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => number_format($points) . ' แต้ม', 
                             'size' => 'sm', 'weight' => 'bold', 'color' => '#EF4444', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'sm',
                        'contents' => [
                            ['type' => 'text', 'text' => 'แต้มคงเหลือ', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => number_format($remainingPoints) . ' แต้ม', 
                             'size' => 'sm', 'weight' => 'bold', 'color' => '#11B0A6', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => "🎁 แลกแต้มสำเร็จ {$points} แต้ม",
            'contents' => $bubble
        ];
    }
    
    /**
     * Handle health profile updated message
     * Requirements: 20.8
     */
    private function handleHealthUpdated($userId) {
        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#10B981',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => '💚 อัพเดทข้อมูลสุขภาพ', 'color' => '#FFFFFF', 
                     'weight' => 'bold', 'size' => 'lg', 'align' => 'center']
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '15px',
                'contents' => [
                    ['type' => 'text', 'text' => 'บันทึกข้อมูลสุขภาพเรียบร้อย', 
                     'weight' => 'bold', 'size' => 'md', 'align' => 'center'],
                    ['type' => 'text', 'text' => 'ข้อมูลนี้จะช่วยให้เภสัชกรให้คำแนะนำได้ดียิ่งขึ้น', 
                     'size' => 'sm', 'color' => '#888888', 'align' => 'center', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '✅ ข้อมูลถูกเข้ารหัสอย่างปลอดภัย', 
                     'size' => 'xs', 'color' => '#10B981', 'align' => 'center', 'margin' => 'lg']
                ]
            ]
        ];
        
        return [
            'type' => 'flex',
            'altText' => '💚 อัพเดทข้อมูลสุขภาพสำเร็จ',
            'contents' => $bubble
        ];
    }

    /**
     * Get shop name for this account
     */
    private function getShopName() {
        try {
            if ($this->lineAccountId) {
                $stmt = $this->db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ?");
                $stmt->execute([$this->lineAccountId]);
                $shop = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($shop && !empty($shop['shop_name'])) {
                    return $shop['shop_name'];
                }
            }
        } catch (Exception $e) {}
        
        return 'ร้านค้า';
    }
    
    /**
     * Get LIFF URL for order tracking
     */
    private function getLiffTrackingUrl($orderId) {
        try {
            if ($this->lineAccountId) {
                $stmt = $this->db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
                $stmt->execute([$this->lineAccountId]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($account && !empty($account['liff_id'])) {
                    // Next.js mini app: /order/{id} (LIFF path URL)
                    return "https://liff.line.me/{$account['liff_id']}/order/{$orderId}";
                }
            }
        } catch (Exception $e) {}
        
        return '';
    }
    
    /**
     * Get user points balance
     */
    private function getUserPoints($userId) {
        try {
            $stmt = $this->db->prepare("SELECT points FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($user['points'] ?? 0);
        } catch (Exception $e) {}
        
        return 0;
    }
    
    /**
     * Create consultation queue entry
     */
    private function createConsultationQueue($userId, $lineUserId) {
        try {
            // Check if video_call_sessions table exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'video_call_sessions'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO video_call_sessions 
                    (user_id, line_user_id, line_account_id, status, created_at) 
                    VALUES (?, ?, ?, 'waiting', NOW())
                ");
                $stmt->execute([$userId, $lineUserId, $this->lineAccountId]);
            }
        } catch (Exception $e) {
            error_log("LiffMessageHandler createConsultationQueue error: " . $e->getMessage());
        }
    }
    
    /**
     * Log LIFF action
     */
    private function logAction($action, $message, $userId, $lineUserId) {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'dev_logs'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    INSERT INTO dev_logs (log_type, source, message, data, created_at) 
                    VALUES ('info', 'liff_message_handler', ?, ?, NOW())
                ");
                $stmt->execute([
                    "LIFF action: {$action}",
                    json_encode([
                        'action' => $action,
                        'message' => $message,
                        'user_id' => $userId,
                        'line_user_id' => $lineUserId,
                        'line_account_id' => $this->lineAccountId
                    ])
                ]);
            }
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
}
