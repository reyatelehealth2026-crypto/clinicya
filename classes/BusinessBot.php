<?php
/**
 * BusinessBot - Universal Business Platform Bot
 * รองรับ: Physical, Digital, Service, Booking, Content
 * 
 * V2.5 Upgrade from ShopBot
 */

require_once __DIR__ . '/LineAPI.php';
require_once __DIR__ . '/FlexTemplates.php';

class BusinessBot
{
    private $db;
    private $line;
    private $lineAccountId;
    private $settings;
    private $botMode = 'shop'; // shop, general, auto_reply_only

    // Bot Modes
    const MODE_SHOP = 'shop';
    const MODE_GENERAL = 'general';
    const MODE_AUTO_REPLY_ONLY = 'auto_reply_only';

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

    public function __construct($db, $line, $lineAccountId = null)
    {
        $this->db = $db;
        $this->line = $line;
        $this->lineAccountId = $lineAccountId;
        $this->loadBotMode();
        $this->loadSettings();
    }

    /**
     * Load bot mode from line_accounts table
     */
    private function loadBotMode()
    {
        $this->botMode = self::MODE_SHOP; // default

        if (!$this->lineAccountId)
            return;

        try {
            // ตรวจสอบว่ามี column bot_mode หรือไม่
            $stmt = $this->db->query("SHOW COLUMNS FROM line_accounts LIKE 'bot_mode'");
            if ($stmt->rowCount() == 0)
                return;

            $stmt = $this->db->prepare("SELECT bot_mode FROM line_accounts WHERE id = ?");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && !empty($result['bot_mode'])) {
                $this->botMode = $result['bot_mode'];
            }

            $this->logDebug('loadBotMode', 'Bot mode loaded', [
                'line_account_id' => $this->lineAccountId,
                'bot_mode' => $this->botMode
            ]);
        } catch (Exception $e) {
            // Column doesn't exist, use default
            $this->logDebug('loadBotMode', 'Error loading bot mode: ' . $e->getMessage());
        }
    }

    /**
     * Get current bot mode
     */
    public function getBotMode()
    {
        return $this->botMode;
    }

    /**
     * Check if shop features are enabled
     */
    public function isShopModeEnabled()
    {
        return $this->botMode === self::MODE_SHOP;
    }

    /**
     * Helper: Reply with text message (properly formatted as array)
     */
    private function replyText($replyToken, $text)
    {
        return $this->line->replyMessage($replyToken, [['type' => 'text', 'text' => $text]]);
    }

    /**
     * Helper: Reply with flex message (properly formatted as array)
     */
    private function replyFlex($replyToken, $flex, $altText = 'ข้อความ')
    {
        return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, $altText)]);
    }

    private function loadSettings()
    {
        $this->settings = [];
        $foundForAccount = false;

        // Try shop_settings first (main settings table)
        try {
            if ($this->lineAccountId) {
                // หา settings ที่ตรงกับ line_account_id เท่านั้น
                $stmt = $this->db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
                $stmt->execute([$this->lineAccountId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $this->settings = $result;
                    $foundForAccount = true;
                }
            }

            // ถ้าไม่พบ settings สำหรับ account นี้ ให้ใช้ค่า default (ร้านปิด)
            if (!$foundForAccount) {
                $this->settings = [
                    'is_open' => 0,  // ปิดร้านเป็น default
                    'shop_name' => 'LINE Shop',
                    'shipping_fee' => 0,
                    'free_shipping_min' => 0
                ];
            }
        } catch (Exception $e) {
            // shop_settings table doesn't exist
            $this->settings = ['is_open' => 0];
        }

        // Log settings loaded for debugging
        $this->logDebug('loadSettings', 'Settings loaded', [
            'line_account_id' => $this->lineAccountId,
            'found_for_account' => $foundForAccount,
            'is_open' => $this->settings['is_open'] ?? 'not set',
            'shop_name' => $this->settings['shop_name'] ?? 'not set'
        ]);
    }

    /**
     * Check if shop is open
     */
    public function isShopOpen()
    {
        $isOpen = ($this->settings['is_open'] ?? 1) == 1;
        $this->logDebug('isShopOpen', 'Checking shop status', [
            'is_open_value' => $this->settings['is_open'] ?? 'not set',
            'result' => $isOpen ? 'OPEN' : 'CLOSED'
        ]);
        return $isOpen;
    }

    /**
     * Process incoming message
     */
    public function processMessage($userId, $userDbId, $message, $replyToken)
    {
        $text = mb_strtolower(trim($message));

        $this->logDebug('processMessage', 'Processing message', [
            'userId' => $userId,
            'userDbId' => $userDbId,
            'message' => mb_substr($message, 0, 50),
            'bot_mode' => $this->botMode
        ]);

        // Reset command - ให้ลูกค้า clear state ได้เอง
        if (in_array($text, ['reset', 'รีเซ็ต', 'เริ่มใหม่', 'clear', 'ล้าง'])) {
            $this->clearUserState($userDbId);
            return $this->replyText($replyToken, "✅ รีเซ็ตเรียบร้อยแล้ว\n\nพิมพ์ 'menu' เพื่อดูเมนู หรือ 'shop' เพื่อดูสินค้า");
        }

        // ถ้าเป็นโหมด auto_reply_only ให้ return null เพื่อให้ AutoReply handler จัดการ
        if ($this->botMode === self::MODE_AUTO_REPLY_ONLY) {
            $this->logDebug('processMessage', 'Auto reply only mode - skipping BusinessBot');
            return null; // Let AutoReply handler process
        }

        // ถ้าเป็นโหมด general ให้ return null ทันที - ไม่ตอบกลับอะไรเลย แค่บันทึกข้อมูล
        if ($this->botMode === self::MODE_GENERAL) {
            $this->logDebug('processMessage', 'General mode - no reply, just record data');
            return null; // ไม่ตอบกลับ - ให้ webhook จัดการ
        }

        // Check user state first (ยกเว้นโหมด auto_reply_only)
        $state = $this->getUserState($userDbId);

        $this->logDebug('processMessage', 'State check result', [
            'userDbId' => $userDbId,
            'hasState' => $state ? true : false,
            'stateName' => $state['state'] ?? 'none'
        ]);

        if ($state) {
            // Check state timeout (30 minutes)
            $stateTime = strtotime($state['updated_at'] ?? $state['created_at'] ?? 'now');
            $timeout = 30 * 60; // 30 minutes
            if (time() - $stateTime > $timeout) {
                $this->clearUserState($userDbId);
                $this->logDebug('processMessage', 'State timeout - cleared', ['userDbId' => $userDbId]);
                // Continue processing as normal
            } else {
                // ถ้าเป็นโหมด general และ state เกี่ยวกับ shop ให้ clear state
                if ($this->botMode === self::MODE_GENERAL && $this->isShopRelatedState($state['state'])) {
                    $this->clearUserState($userDbId);
                } else {
                    return $this->handleStatefulMessage($userId, $userDbId, $message, $replyToken, $state);
                }
            }
        }



        // Pattern matching - Removed legacy text commands
        // All shop interactions should go through LIFF


        // ล้างตะกร้า - ต้องเป็นโหมด shop และร้านเปิด
        if (in_array($text, ['clear', 'ล้างตะกร้า', 'เคลียร์ตะกร้า'])) {
            if (!$this->isShopModeEnabled()) {
                $this->replyText($replyToken, "ℹ️ บัญชีนี้ไม่ได้เปิดใช้งานระบบร้านค้า");
                return true;
            }
            if (!$this->isShopOpen()) {
                $this->replyText($replyToken, "🚫 ขออภัย ร้านค้าปิดให้บริการชั่วคราว");
                return true;
            }
            $this->clearCart($userId, $userDbId, $replyToken);
            return true;
        }

        // Track behavior for unknown messages
        $this->trackBehavior($userDbId, 'message', ['text' => $message]);

        return null; // Let other handlers process
    }

    /**
     * Log error to dev_logs table
     */
    private function logError($source, $message, $data = null)
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('error', ?, ?, ?, NOW())");
            $stmt->execute([
                'BusinessBot::' . $source,
                $message,
                $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (Exception $e) {
            // Table might not exist
            error_log("[BusinessBot] {$source}: {$message}");
        }
    }

    /**
     * Log debug info to dev_logs table
     */
    private function logDebug($source, $message, $data = null)
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO dev_logs (log_type, source, message, data, created_at) VALUES ('debug', ?, ?, ?, NOW())");
            $stmt->execute([
                'BusinessBot::' . $source,
                $message,
                $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Get item by ID with type-specific data
     */
    public function getItem($itemId)
    {
        try {
            $table = $this->getItemsTable();
            $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ? AND is_active = 1");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item && isset($item['action_data'])) {
                $item['action_data'] = json_decode($item['action_data'], true);
            }

            return $item;
        } catch (Exception $e) {
            $this->logError('getItem', $e->getMessage(), ['item_id' => $itemId]);
            return null;
        }
    }

    /**
     * Get items table name (for backward compatibility)
     */
    private function getItemsTable()
    {
        return 'products';
    }

    /**
     * Get transactions table name
     */
    private function getTransactionsTable()
    {
        try {
            $this->db->query("SELECT 1 FROM transactions LIMIT 1");
            return 'transactions';
        } catch (Exception $e) {
            try {
                $this->db->query("SELECT 1 FROM orders LIMIT 1");
                return 'orders';
            } catch (Exception $e2) {
                return null; // No table exists
            }
        }
    }

    /**
     * Show main menu based on bot mode, business type and shop status
     */
    public function showMainMenu($userId, $userDbId, $replyToken)
    {
        $businessType = $this->settings['business_type'] ?? 'hybrid';
        $shopName = $this->settings['shop_name'] ?? 'LINE Business';

        // ถ้าเป็นโหมด general แสดงเมนูแบบไม่มีร้านค้า
        if ($this->botMode === self::MODE_GENERAL) {
            return $this->showGeneralModeMenu($userId, $userDbId, $replyToken, $shopName);
        }

        // โหมด shop - ตรวจสอบว่าร้านเปิดหรือปิด
        $isOpen = $this->isShopOpen();

        // ถ้าร้านปิด แสดงเมนูแบบจำกัด
        if (!$isOpen) {
            return $this->showClosedShopMenu($userId, $userDbId, $replyToken, $shopName);
        }

        $menuItems = $this->getMenuItemsByBusinessType($businessType);
        $flex = FlexTemplates::mainMenu($shopName, $menuItems);
        $message = FlexTemplates::toMessage($flex, 'เมนูหลัก', 'shop', 'main');

        return $this->line->replyMessage($replyToken, [$message]);
    }

    /**
     * Show menu for general mode (no shop features)
     */
    private function showGeneralModeMenu($userId, $userDbId, $replyToken, $shopName)
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'xl', 'color' => '#1e293b'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => '💬 ยินดีต้อนรับ', 'size' => 'sm', 'color' => '#3B82F6', 'weight' => 'bold']
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => 'สวัสดีค่ะ! มีอะไรให้ช่วยเหลือไหมคะ?', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'lg', 'wrap' => true],
                    ['type' => 'text', 'text' => 'พิมพ์ข้อความเพื่อสอบถามได้เลยค่ะ', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true],
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📞 ติดต่อเรา', 'text' => 'ติดต่อ'], 'style' => 'primary', 'color' => '#3B82F6']
                ],
                'paddingAll' => 'lg'
            ]
        ];

        $message = ['type' => 'flex', 'altText' => 'เมนูหลัก', 'contents' => $bubble];
        return $this->line->replyMessage($replyToken, [$message]);
    }

    /**
     * Show menu when shop is closed
     */
    private function showClosedShopMenu($userId, $userDbId, $replyToken, $shopName)
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'xl', 'color' => '#1e293b'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => '🚫 ปิดให้บริการชั่วคราว', 'size' => 'sm', 'color' => '#EF4444', 'weight' => 'bold']
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => 'ขออภัย ร้านค้าปิดให้บริการชั่วคราว', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'lg', 'wrap' => true],
                    ['type' => 'text', 'text' => 'คุณยังสามารถ:', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'md'],
                    ['type' => 'text', 'text' => '• ดูคำสั่งซื้อเดิม', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'sm'],
                    ['type' => 'text', 'text' => '• ติดต่อเรา', 'size' => 'sm', 'color' => '#64748b', 'margin' => 'sm'],
                ],
                'paddingAll' => 'xl'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูคำสั่งซื้อ', 'text' => 'ออเดอร์'], 'style' => 'primary', 'color' => '#3B82F6'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📞 ติดต่อเรา', 'text' => 'ติดต่อ'], 'style' => 'secondary']
                ],
                'paddingAll' => 'lg'
            ]
        ];

        $message = ['type' => 'flex', 'altText' => 'ร้านค้าปิดให้บริการชั่วคราว', 'contents' => $bubble];
        return $this->line->replyMessage($replyToken, [$message]);
    }

    private function getMenuItemsByBusinessType($type)
    {
        $baseItems = [
            ['icon' => '🛒', 'label' => 'ดูสินค้า/บริการ', 'text' => 'shop', 'color' => '#06C755'],
            ['icon' => '🛍️', 'label' => 'ตะกร้า', 'text' => 'cart', 'color' => '#3B82F6'],
            ['icon' => '📋', 'label' => 'รายการของฉัน', 'text' => 'orders', 'color' => '#8B5CF6'],
        ];

        switch ($type) {
            case 'digital':
                $baseItems[0] = ['icon' => '🎮', 'label' => 'สินค้าดิจิทัล', 'text' => 'shop', 'color' => '#06C755'];
                break;
            case 'service':
                $baseItems[0] = ['icon' => '📅', 'label' => 'จองบริการ', 'text' => 'shop', 'color' => '#06C755'];
                $baseItems[2] = ['icon' => '📋', 'label' => 'การจองของฉัน', 'text' => 'orders', 'color' => '#8B5CF6'];
                break;
        }

        $baseItems[] = ['icon' => '📞', 'label' => 'ติดต่อเรา', 'text' => 'contact', 'color' => '#EF4444'];

        return $baseItems;
    }

    /**
     * Show categories / LIFF Shop
     * ถ้ามี LIFF ID จะส่ง LIFF Shop URL แทน carousel
     */
    public function showCategories($userId, $userDbId, $replyToken)
    {
        // ตรวจสอบว่าร้านเปิดหรือไม่
        if (!$this->isShopOpen()) {
            return $this->replyText($replyToken, "🚫 ขออภัย ร้านค้าปิดให้บริการชั่วคราว\n\nกรุณาติดต่อเราภายหลัง");
        }

        try {
            // ตรวจสอบว่ามี LIFF ID หรือไม่ - ถ้ามีจะส่ง LIFF Shop URL
            $liffId = $this->getLiffId();

            if ($liffId) {
                // ส่ง LIFF Shop Flex Message
                return $this->sendLiffShopMessage($userId, $userDbId, $replyToken, $liffId);
            }

            // Fallback: ใช้ carousel เดิมถ้าไม่มี LIFF
            return $this->showCategoriesCarousel($userId, $userDbId, $replyToken);

        } catch (Exception $e) {
            error_log("BusinessBot showCategories error: " . $e->getMessage());
            return $this->replyText($replyToken, "🛒 ระบบร้านค้ายังไม่พร้อมใช้งาน\n\nกรุณาติดต่อผู้ดูแลระบบ");
        }
    }

    /**
     * Get LIFF ID for this line account
     */
    private function getLiffId()
    {
        if (!$this->lineAccountId)
            return null;

        try {
            $stmt = $this->db->prepare("SELECT liff_id FROM line_accounts WHERE id = ? AND liff_id IS NOT NULL AND liff_id != ''");
            $stmt->execute([$this->lineAccountId]);
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Send LIFF Shop Flex Message
     */
    private function sendLiffShopMessage($userId, $userDbId, $replyToken, $liffId)
    {
        $shopName = $this->settings['shop_name'] ?? 'LINE Shop';
        $liffUrl = "https://liff.line.me/{$liffId}";

        // Get base URL from settings or construct from request
        $baseUrl = $this->settings['base_url'] ?? '';
        if (empty($baseUrl)) {
            // Try to get from config
            if (defined('BASE_URL')) {
                $baseUrl = BASE_URL;
            } else {
                $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
        }

        // LIFF Shop URL - ใช้ LIFF URL ถ้ามี liff_id
        if ($liffId) {
            $shopUrl = "https://liff.line.me/{$liffId}/shop";
        } else {
            // Fallback to direct URL if no LIFF ID
            $shopUrl = rtrim($baseUrl, '/') . "/liff-shop.php?user={$userId}";
        }

        // Get product count
        $productCount = 0;
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$this->lineAccountId]);
            $productCount = $stmt->fetchColumn() ?: 0;
        } catch (Exception $e) {
        }

        // Get cart count
        $cartCount = 0;
        try {
            $stmt = $this->db->prepare("SELECT SUM(quantity) FROM cart_items WHERE user_id = ?");
            $stmt->execute([$userDbId]);
            $cartCount = $stmt->fetchColumn() ?: 0;
        } catch (Exception $e) {
        }

        // Build Flex Message
        $bubble = [
            'type' => 'bubble',
            'hero' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '🛍️', 'size' => '3xl', 'align' => 'center'],
                            ['type' => 'text', 'text' => $shopName, 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'color' => '#ffffff', 'margin' => 'md'],
                            ['type' => 'text', 'text' => 'เลือกซื้อสินค้าได้เลย!', 'size' => 'sm', 'align' => 'center', 'color' => '#ffffff', 'margin' => 'sm']
                        ],
                        'paddingAll' => 'xl',
                        'backgroundColor' => '#06C755',
                        'cornerRadius' => 'none'
                    ]
                ],
                'paddingAll' => 'none'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '📦', 'size' => 'xl', 'align' => 'center'],
                                    ['type' => 'text', 'text' => "{$productCount} สินค้า", 'size' => 'sm', 'align' => 'center', 'color' => '#888888', 'margin' => 'sm']
                                ],
                                'flex' => 1
                            ],
                            ['type' => 'separator'],
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => '🛒', 'size' => 'xl', 'align' => 'center'],
                                    ['type' => 'text', 'text' => $cartCount > 0 ? "{$cartCount} ในตะกร้า" : 'ตะกร้าว่าง', 'size' => 'sm', 'align' => 'center', 'color' => $cartCount > 0 ? '#06C755' : '#888888', 'margin' => 'sm']
                                ],
                                'flex' => 1
                            ]
                        ],
                        'paddingAll' => 'lg'
                    ],
                    ['type' => 'separator', 'margin' => 'md'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '✨ ส่งฟรีเมื่อซื้อครบ ฿' . number_format($this->settings['free_shipping_min'] ?? 500), 'size' => 'xs', 'color' => '#06C755', 'align' => 'center']
                        ],
                        'margin' => 'lg'
                    ]
                ],
                'paddingAll' => 'lg'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => [
                            'type' => 'uri',
                            'label' => '🛒 เปิดร้านค้า',
                            'uri' => $shopUrl
                        ],
                        'style' => 'primary',
                        'color' => '#06C755',
                        'height' => 'md'
                    ]
                ],
                'paddingAll' => 'lg'
            ]
        ];

        // Add cart button if has items
        if ($cartCount > 0) {
            // ใช้ LIFF URL ถ้ามี liff_id
            if ($liffId) {
                $checkoutUrl = "https://liff.line.me/{$liffId}/checkout";
            } else {
                $checkoutUrl = rtrim($baseUrl, '/') . "/liff-checkout.php?user={$userId}&action=address";
            }
            $bubble['footer']['contents'][] = [
                'type' => 'button',
                'action' => [
                    'type' => 'uri',
                    'label' => "🛍️ ดำเนินการสั่งซื้อ ({$cartCount})",
                    'uri' => $checkoutUrl
                ],
                'style' => 'secondary',
                'height' => 'sm',
                'margin' => 'sm'
            ];
        }

        $message = ['type' => 'flex', 'altText' => "🛍️ {$shopName} - เลือกซื้อสินค้า", 'contents' => $bubble];

        $this->trackBehavior($userDbId, 'view_shop', ['via' => 'liff']);

        return $this->line->replyMessage($replyToken, [$message]);
    }

    /**
     * Show categories carousel (fallback when no LIFF)
     */
    private function showCategoriesCarousel($userId, $userDbId, $replyToken)
    {
        // ตรวจสอบว่าตารางมีอยู่หรือไม่
        $table = 'item_categories';
        $tableExists = false;

        try {
            $this->db->query("SELECT 1 FROM item_categories LIMIT 1");
            $tableExists = true;
        } catch (Exception $e) {
            try {
                $this->db->query("SELECT 1 FROM product_categories LIMIT 1");
                $table = 'product_categories';
                $tableExists = true;
            } catch (Exception $e2) {
                $tableExists = false;
            }
        }

        if (!$tableExists) {
            return $this->replyText($replyToken, "🛒 ระบบร้านค้ายังไม่พร้อมใช้งาน\n\nกรุณาติดต่อผู้ดูแลระบบ");
        }

        // ตรวจสอบว่ามี column line_account_id หรือไม่
        $hasAccountCol = false;
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM {$table} LIKE 'line_account_id'");
            $hasAccountCol = $stmt->rowCount() > 0;
        } catch (Exception $e) {
        }

        $sql = "SELECT * FROM {$table} WHERE is_active = 1";
        if ($this->lineAccountId && $hasAccountCol) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $stmt = $this->db->prepare($sql . " ORDER BY sort_order ASC");
            $stmt->execute([$this->lineAccountId]);
        } else {
            $stmt = $this->db->query($sql . " ORDER BY sort_order ASC");
        }
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($categories)) {
            return $this->replyText($replyToken, "📦 ยังไม่มีหมวดหมู่สินค้า\n\nกรุณาเพิ่มหมวดหมู่ในระบบหลังบ้าน");
        }

        $flex = $this->buildCategoriesCarousel($categories);
        $message = FlexTemplates::toMessage($flex, 'หมวดหมู่', 'shop');

        $this->trackBehavior($userDbId, 'view_categories', []);

        return $this->line->replyMessage($replyToken, [$message]);
    }

    private function buildCategoriesCarousel($categories)
    {
        $bubbles = [];
        foreach (array_slice($categories, 0, 10) as $cat) {
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

            // เพิ่ม hero image ถ้ามี
            if (!empty($cat['image_url'])) {
                $bubble['hero'] = [
                    'type' => 'image',
                    'url' => $cat['image_url'],
                    'size' => 'full',
                    'aspectRatio' => '1:1',
                    'aspectMode' => 'cover',
                    'action' => ['type' => 'message', 'text' => "หมวด {$cat['id']}"]
                ];
            }

            $bubbles[] = $bubble;
        }

        if (empty($bubbles)) {
            return [
                'type' => 'bubble',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ยังไม่มีหมวดหมู่', 'align' => 'center']
                    ]
                ]
            ];
        }

        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * Show items in category
     */
    public function showCategoryItems($userId, $userDbId, $categoryId, $replyToken)
    {
        $table = $this->getItemsTable();

        $sql = "SELECT * FROM {$table} WHERE category_id = ? AND is_active = 1 AND stock > 0";
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $stmt = $this->db->prepare($sql . " ORDER BY id DESC LIMIT 10");
            $stmt->execute([$categoryId, $this->lineAccountId]);
        } else {
            $stmt = $this->db->prepare($sql . " ORDER BY id DESC LIMIT 10");
            $stmt->execute([$categoryId]);
        }
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            return $this->replyText($replyToken, 'ไม่มีสินค้าในหมวดนี้');
        }

        $flex = $this->buildItemsCarousel($items);
        $message = FlexTemplates::toMessage($flex, 'สินค้า', 'shop');

        $this->trackBehavior($userDbId, 'view_category', ['category_id' => $categoryId]);

        return $this->line->replyMessage($replyToken, [$message]);
    }

    /**
     * Build items carousel based on item type
     */
    private function buildItemsCarousel($items)
    {
        $bubbles = [];
        foreach ($items as $item) {
            $bubbles[] = $this->buildItemCard($item);
        }
        return ['type' => 'carousel', 'contents' => $bubbles];
    }

    /**
     * Build single item card based on type
     */
    /**
     * Build single item card based on type
     * Restored for backward compatibility with showItemDetail
     */
    private function buildItemCard($item)
    {
        $itemType = $item['item_type'] ?? 'physical';
        $price = $item['sale_price'] ?? $item['price'];

        $icons = [
            'digital' => '🎮',
            'service' => '💆',
            'booking' => '📅',
            'content' => '📚'
        ];
        $typeIcon = $icons[$itemType] ?? '📦';

        $priceContents = [['type' => 'text', 'text' => '฿' . number_format($price), 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755']];
        if (!empty($item['sale_price']) && $item['sale_price'] < $item['price']) {
            $priceContents[] = ['type' => 'text', 'text' => '฿' . number_format($item['price']), 'size' => 'xs', 'color' => '#AAAAAA', 'decoration' => 'line-through', 'margin' => 'sm'];
        }

        $stockText = $item['stock'] > 0 ? "📦 เหลือ {$item['stock']} ชิ้น" : "❌ สินค้าหมด";
        if ($itemType === 'digital')
            $stockText = $item['stock'] > 0 ? "✅ พร้อมส่งทันที" : "❌ หมด";

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => "{$typeIcon} {$item['name']}", 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                    ['type' => 'box', 'layout' => 'horizontal', 'contents' => $priceContents, 'margin' => 'md'],
                    ['type' => 'text', 'text' => $stockText, 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 เพิ่มลงตะกร้า', 'text' => "add {$item['id']}"], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 รายละเอียด', 'text' => "item {$item['id']}"], 'style' => 'secondary', 'margin' => 'sm']
                ],
                'paddingAll' => 'lg'
            ]
        ];

        if (!empty($item['image_url'])) {
            $bubble['hero'] = [
                'type' => 'image',
                'url' => $item['image_url'],
                'size' => 'full',
                'aspectRatio' => '1:1',
                'aspectMode' => 'cover',
                'action' => ['type' => 'message', 'text' => "item {$item['id']}"]
            ];
        }

        return $bubble;
    }

    private function getStockText($item)
    {
        $itemType = $item['item_type'] ?? self::TYPE_PHYSICAL;

        switch ($itemType) {
            case self::TYPE_DIGITAL:
                return $item['stock'] > 0 ? "✅ พร้อมส่งทันที" : "❌ หมด";
            case self::TYPE_SERVICE:
            case self::TYPE_BOOKING:
                return "📅 เปิดจอง";
            default:
                return $item['stock'] > 0 ? "📦 เหลือ {$item['stock']} ชิ้น" : "❌ สินค้าหมด";
        }
    }

    /**
     * Add item to cart
     */
    /**
     * Legacy method - removed
     */
    public function addToCart($userId, $userDbId, $itemId, $qty, $replyToken)
    {
        return $this->showCategories($userId, $userDbId, $replyToken);
    }

    /**
     * Show cart
     */
    /**
     * Show cart - Redirect to LIFF
     */
    public function showCart($userId, $userDbId, $replyToken)
    {
        // ตรวจสอบว่าร้านเปิดหรือไม่
        if (!$this->isShopOpen()) {
            return $this->replyText($replyToken, "🚫 ขออภัย ร้านค้าปิดให้บริการชั่วคราว");
        }

        $liffId = $this->getLiffId();
        if ($liffId) {
            return $this->sendLiffShopMessage($userId, $userDbId, $replyToken, $liffId);
        }

        return $this->replyText($replyToken, "🛒 กรุณาใช้เมนูร้านค้าใน LINE เพื่อดูตะกร้าสินค้า");
    }

    /**
     * Helper to show LIFF checkout link
     */
    public function showLiffCheckoutLink($userId, $userDbId, $replyToken)
    {
        // ตรวจสอบว่าร้านเปิดหรือไม่
        if (!$this->isShopOpen()) {
            return $this->replyText($replyToken, "🚫 ขออภัย ร้านค้าปิดให้บริการชั่วคราว");
        }

        $liffId = $this->getLiffId();
        if ($liffId) {
            return $this->sendLiffShopMessage($userId, $userDbId, $replyToken, $liffId);
        }

        return $this->replyText($replyToken, "🛒 กรุณาใช้เมนูร้านค้าใน LINE เพื่อสั่งซื้อสินค้า");
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart($userId, $userDbId, $productId, $replyToken)
    {
        return $this->showCart($userId, $userDbId, $replyToken);
    }

    /**
     * Clear cart
     */
    public function clearCart($userId, $userDbId, $replyToken)
    {
        return $this->showCart($userId, $userDbId, $replyToken);
    }

    /**
     * Search items
     */
    /**
     * Legacy method - removed
     */
    public function searchItems($userId, $userDbId, $keyword, $replyToken)
    {
        // ตรวจสอบว่าร้านเปิดหรือไม่
        if (!$this->isShopOpen()) {
            return $this->replyText($replyToken, "🚫 ขออภัย ร้านค้าปิดให้บริการชั่วคราว");
        }

        $liffId = $this->getLiffId();
        if ($liffId) {
            return $this->sendLiffShopMessage($userId, $userDbId, $replyToken, $liffId);
        }

        return $this->replyText($replyToken, "🛒 กรุณาใช้เมนูร้านค้าใน LINE เพื่อค้นหาสินค้า");
    }

    /**
     * Start checkout process - different flow based on item types
     */
    /**
     * Legacy method - removed
     */
    public function startCheckout($userId, $userDbId, $replyToken)
    {
        return $this->showLiffCheckoutLink($userId, $userDbId, $replyToken);
    }

    /**
     * Show delivery options - Flex Message
     */
    private function showDeliveryOptions($userId, $userDbId, $replyToken, $subtotal, $itemCount)
    {
        $shippingFee = $this->settings['shipping_fee'] ?? 50;
        $freeShippingMin = $this->settings['free_shipping_min'] ?? 500;
        $isFreeShipping = $subtotal >= $freeShippingMin;

        // Get LIFF URL - pass LINE User ID for LIFF authentication
        $liffUrl = $this->getLiffCheckoutUrl($userDbId, $userId);

        // ถ้ามี LIFF - แสดง LIFF เป็นหลัก
        if ($liffUrl) {
            return $this->showLiffCheckout($userId, $userDbId, $replyToken, $subtotal, $itemCount, $liffUrl, $shippingFee, $isFreeShipping);
        }

        // ถ้าไม่มี LIFF - แสดงแบบเดิม
        return $this->showTraditionalCheckout($userId, $userDbId, $replyToken, $subtotal, $itemCount, $shippingFee, $isFreeShipping);
    }

    /**
     * Show LIFF checkout (primary method)
     */
    private function showLiffCheckout($userId, $userDbId, $replyToken, $subtotal, $itemCount, $liffUrl, $shippingFee, $isFreeShipping)
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '🛒 สั่งซื้อสินค้า', 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'จำนวนสินค้า', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => "{$itemCount} ชิ้น", 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดสินค้า', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($subtotal), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $isFreeShipping ? 'ฟรี!' : '฿' . number_format($shippingFee), 'size' => 'sm', 'align' => 'end', 'flex' => 1, 'color' => $isFreeShipping ? '#06C755' : '#333333']
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($subtotal + ($isFreeShipping ? 0 : $shippingFee)), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    ['type' => 'text', 'text' => '📱 กดปุ่มกรอกที่อยู่และชำระเงิน', 'size' => 'xs', 'color' => '#888888', 'margin' => 'lg', 'wrap' => true]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'action' => ['type' => 'uri', 'label' => '📱 ดำเนินการสั่งซื้อ', 'uri' => $liffUrl],
                        'style' => 'primary',
                        'color' => '#06C755',
                        'height' => 'md'
                    ],
                    [
                        'type' => 'button',
                        'action' => ['type' => 'message', 'label' => '💬 พิมพ์ที่อยู่เอง', 'text' => 'จัดส่ง'],
                        'style' => 'secondary',
                        'height' => 'sm'
                    ],
                    [
                        'type' => 'button',
                        'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                        'style' => 'secondary',
                        'color' => '#AAAAAA',
                        'height' => 'sm'
                    ]
                ]
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'สั่งซื้อสินค้า - กดเพื่อดำเนินการ', 'contents' => $bubble]
        ]);
    }

    /**
     * Show traditional checkout (fallback when no LIFF)
     */
    private function showTraditionalCheckout($userId, $userDbId, $replyToken, $subtotal, $itemCount, $shippingFee, $isFreeShipping)
    {
        $buttons = [
            [
                'type' => 'button',
                'action' => ['type' => 'message', 'label' => '📦 จัดส่ง' . ($isFreeShipping ? ' (ฟรี!)' : " (+฿{$shippingFee})"), 'text' => 'จัดส่ง'],
                'style' => 'primary',
                'color' => '#06C755'
            ],
            [
                'type' => 'button',
                'action' => ['type' => 'message', 'label' => '🏪 รับที่ร้าน (ฟรี)', 'text' => 'รับที่ร้าน'],
                'style' => 'secondary'
            ],
            [
                'type' => 'button',
                'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
                'style' => 'secondary',
                'color' => '#AAAAAA'
            ]
        ];

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '🚚 เลือกวิธีรับสินค้า', 'weight' => 'bold', 'size' => 'lg'],
                    ['type' => 'text', 'text' => "{$itemCount} ชิ้น | ฿" . number_format($subtotal), 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg']
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => $buttons
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'เลือกวิธีรับสินค้า', 'contents' => $bubble]
        ]);
    }

    /**
     * Get LIFF checkout URL
     */
    private function getLiffCheckoutUrl($userDbId, $lineUserId = null)
    {
        try {
            // Get LIFF ID from line_accounts
            $stmt = $this->db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
            $stmt->execute([$this->lineAccountId ?: 1]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $liffId = $row['liff_id'] ?? '';

            if (!$liffId)
                return null;

            // Use LINE User ID if provided, otherwise use DB ID
            $userParam = $lineUserId ?: $userDbId;

            return "https://liff.line.me/{$liffId}/checkout";
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Quick checkout - สร้าง order ทันที (สำหรับ products table แบบเดิม)
     */
    private function quickCheckout($userId, $userDbId, $items, $replyToken)
    {
        try {
            // Calculate total
            $total = 0;
            foreach ($items as $item) {
                $price = $item['sale_price'] ?? $item['price'];
                $total += $price * $item['quantity'];
            }

            // Get shipping fee
            $shippingFee = $this->settings['shipping_fee'] ?? 0;
            $freeShippingMin = $this->settings['free_shipping_min'] ?? 0;
            if ($freeShippingMin > 0 && $total >= $freeShippingMin) {
                $shippingFee = 0;
            }
            $grandTotal = $total + $shippingFee;

            // Create order
            $orderNumber = 'ORD' . date('ymdHis') . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);

            // Check if orders table has line_account_id
            $hasAccountCol = false;
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM orders LIKE 'line_account_id'");
                $hasAccountCol = $stmt->rowCount() > 0;
            } catch (Exception $e) {
            }

            if ($hasAccountCol && $this->lineAccountId) {
                $stmt = $this->db->prepare("INSERT INTO orders (line_account_id, order_number, user_id, total_amount, shipping_fee, grand_total, status, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending')");
                $stmt->execute([$this->lineAccountId, $orderNumber, $userDbId, $total, $shippingFee, $grandTotal]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO orders (order_number, user_id, total_amount, shipping_fee, grand_total, status, payment_status) VALUES (?, ?, ?, ?, ?, 'pending', 'pending')");
                $stmt->execute([$orderNumber, $userDbId, $total, $shippingFee, $grandTotal]);
            }
            $orderId = $this->db->lastInsertId();

            // Add order items
            $itemsContent = [];
            foreach ($items as $item) {
                $price = $item['sale_price'] ?? $item['price'];
                $subtotal = $price * $item['quantity'];

                $stmt = $this->db->prepare("INSERT INTO order_items (order_id, product_id, product_name, product_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$orderId, $item['product_id'], $item['name'], $price, $item['quantity'], $subtotal]);

                $itemsContent[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => "{$item['name']} x{$item['quantity']}", 'size' => 'sm', 'flex' => 3, 'wrap' => true],
                        ['type' => 'text', 'text' => '฿' . number_format($subtotal), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                    ]
                ];
            }

            // Clear cart
            $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $stmt->execute([$userDbId]);

            // Get payment info
            $bankAccounts = json_decode($this->settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
            $promptpay = $this->settings['promptpay_number'] ?? '';

            $paymentContents = [];
            if ($promptpay) {
                $paymentContents[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '💚', 'size' => 'sm', 'flex' => 0],
                        ['type' => 'text', 'text' => 'พร้อมเพย์: ' . $promptpay, 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
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
                        ['type' => 'text', 'text' => "✅ สั่งซื้อสำเร็จ!", 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                        ['type' => 'text', 'text' => "ออเดอร์ #{$orderNumber}", 'size' => 'md', 'color' => '#888888', 'margin' => 'sm'],
                        ['type' => 'separator', 'margin' => 'lg'],
                        ['type' => 'text', 'text' => 'รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $itemsContent
                        ],
                        ['type' => 'separator', 'margin' => 'lg'],
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
                        ['type' => 'text', 'text' => '📌 ช่องทางชำระเงิน:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $paymentContents
                        ],
                        ['type' => 'text', 'text' => '📸 กรุณาส่งรูปสลิปมาเลย', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true]
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📤 ส่งสลิป', 'text' => 'โอนแล้ว'], 'style' => 'primary', 'color' => '#06C755'],
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูคำสั่งซื้อ', 'text' => 'orders'], 'style' => 'secondary']
                    ]
                ]
            ];

            // Set state to await slip
            $this->setUserState($userDbId, 'awaiting_slip', ['order_id' => $orderId]);

            return $this->line->replyMessage($replyToken, [
                ['type' => 'flex', 'altText' => "ออเดอร์ #{$orderNumber}", 'contents' => $bubble]
            ]);

        } catch (Exception $e) {
            $this->logError('quickCheckout', $e->getMessage(), ['user_id' => $userDbId]);
            return $this->replyText($replyToken, "❌ เกิดข้อผิดพลาด: " . $e->getMessage());
        }
    }

    /**
     * Handle stateful messages (checkout flow, booking, etc.)
     */
    private function handleStatefulMessage($userId, $userDbId, $message, $replyToken, $state)
    {
        // Legacy state machine removed for checkout
        // Only keep valid states if any (e.g. awaiting_slip)
        $stateName = $state['state'];

        // Clear legacy checkout states
        if (strpos($stateName, 'checkout_') === 0) {
            $this->clearUserState($userDbId);
            // Prompt to use LIFF instead
            return $this->showLiffCheckoutLink($userId, $userDbId, $replyToken);
        }

        if ($stateName === 'awaiting_slip') {
            $stateData = json_decode($state['state_data'] ?? '{}', true);
            return $this->handleSlipUpload($userId, $userDbId, $message, $replyToken, $stateData);
        }

        // unknown state, clear
        $this->clearUserState($userDbId);
        return null;
    }

    /**
     * Handle delivery choice (จัดส่ง/รับที่ร้าน)
     */
    private function handleDeliveryChoice($userId, $userDbId, $message, $replyToken, $stateData)
    {
        $text = mb_strtolower(trim($message));
        $subtotal = $stateData['subtotal'] ?? 0;

        // ยกเลิก
        if (in_array($text, ['ยกเลิก', 'cancel', 'ไม่', 'no', 'ออก', 'exit', 'quit'])) {
            $this->clearUserState($userDbId);
            return $this->replyText($replyToken, "❌ ยกเลิกการสั่งซื้อแล้ว\n\nพิมพ์ 'cart' เพื่อดูตะกร้า หรือ 'menu' เพื่อดูเมนู");
        }

        // กลับไปเมนู
        if (in_array($text, ['menu', 'เมนู', 'help', '?'])) {
            $this->clearUserState($userDbId);
            return false; // Let main handler process menu command
        }

        // จัดส่ง - รองรับหลายรูปแบบ
        if (in_array($text, ['จัดส่ง', 'shipping', 'ส่ง', 'delivery', 'ship', '1', 'ส่งของ', 'ส่งถึงบ้าน', 'ส่งบ้าน'])) {
            // Need address
            $stateData['delivery_type'] = 'shipping';
            $this->setUserState($userDbId, 'checkout_address', $stateData);
            return $this->replyText($replyToken, "📦 กรุณาส่งที่อยู่จัดส่ง\n\nรูปแบบ:\nชื่อ-นามสกุล\nเบอร์โทร\nที่อยู่\n\nตัวอย่าง:\nสมชาย ใจดี\n0812345678\n123 ถ.สุขุมวิท แขวงคลองเตย เขตคลองเตย กทม 10110\n\n💡 พิมพ์ 'ยกเลิก' เพื่อยกเลิก");
        }

        // รับที่ร้าน - รองรับหลายรูปแบบ
        if (in_array($text, ['รับที่ร้าน', 'pickup', 'รับเอง', '2', 'รับ', 'มารับ', 'มารับเอง', 'รับของ', 'ไปรับ'])) {
            // Skip to payment
            $stateData['delivery_type'] = 'pickup';
            $stateData['delivery_info'] = ['type' => 'pickup'];
            $this->setUserState($userDbId, 'checkout_payment', $stateData);
            return $this->showPaymentOptions($userId, $userDbId, $replyToken, $subtotal, 0);
        }

        // ถ้าพิมพ์อะไรมาก็ไม่รู้จัก - แสดงตัวเลือกใหม่พร้อมคำแนะนำ
        $helpText = "🚚 กรุณาเลือกวิธีรับสินค้า:\n\n";
        $helpText .= "📦 พิมพ์ 'จัดส่ง' - ส่งถึงบ้าน\n";
        $helpText .= "🏪 พิมพ์ 'รับที่ร้าน' - มารับเอง\n";
        $helpText .= "❌ พิมพ์ 'ยกเลิก' - ยกเลิกการสั่งซื้อ\n\n";
        $helpText .= "หรือกดปุ่มด้านบนได้เลยค่ะ";

        return $this->replyText($replyToken, $helpText);
    }

    /**
     * Show payment options - Flex Message
     */
    private function showPaymentOptions($userId, $userDbId, $replyToken, $subtotal, $shippingFee)
    {
        $total = $subtotal + $shippingFee;
        $promptpay = $this->settings['promptpay_number'] ?? '';
        $codEnabled = $this->settings['cod_enabled'] ?? true;

        $buttons = [];

        // Transfer option
        $buttons[] = [
            'type' => 'button',
            'action' => ['type' => 'message', 'label' => '💳 โอนเงิน/พร้อมเพย์', 'text' => 'โอนเงิน'],
            'style' => 'primary',
            'color' => '#06C755'
        ];

        // COD option (if enabled)
        if ($codEnabled) {
            $buttons[] = [
                'type' => 'button',
                'action' => ['type' => 'message', 'label' => '📦 เก็บเงินปลายทาง (COD)', 'text' => 'COD'],
                'style' => 'secondary'
            ];
        }

        $buttons[] = [
            'type' => 'button',
            'action' => ['type' => 'message', 'label' => '❌ ยกเลิก', 'text' => 'ยกเลิก'],
            'style' => 'secondary',
            'color' => '#AAAAAA'
        ];

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '💰 เลือกวิธีชำระเงิน', 'weight' => 'bold', 'size' => 'lg'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดสินค้า', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($subtotal), 'size' => 'sm', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                            ['type' => 'text', 'text' => $shippingFee > 0 ? '฿' . number_format($shippingFee) : 'ฟรี', 'size' => 'sm', 'align' => 'end', 'flex' => 1, 'color' => $shippingFee > 0 ? '#333333' : '#06C755']
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'md'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'md',
                        'contents' => [
                            ['type' => 'text', 'text' => 'รวมทั้งหมด', 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($total), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => $buttons
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => 'เลือกวิธีชำระเงิน', 'contents' => $bubble]
        ]);
    }

    /**
     * Handle payment choice
     */
    private function handlePaymentChoice($userId, $userDbId, $message, $replyToken, $stateData)
    {
        $text = mb_strtolower(trim($message));

        // โอนเงิน - รองรับหลายรูปแบบ
        if (in_array($text, ['โอนเงิน', 'โอน', 'transfer', 'พร้อมเพย์', 'promptpay', '1', 'โอนก่อน', 'จ่ายก่อน'])) {
            $stateData['payment_method'] = 'transfer';
            return $this->createOrderAndShowPayment($userId, $userDbId, $replyToken, $stateData);
        }

        // COD - รองรับหลายรูปแบบ
        if (in_array($text, ['cod', 'เก็บเงินปลายทาง', 'ปลายทาง', '2', 'จ่ายปลายทาง', 'เก็บปลายทาง'])) {
            $stateData['payment_method'] = 'cod';
            return $this->createOrderAndShowPayment($userId, $userDbId, $replyToken, $stateData);
        }

        // ถ้าพิมพ์อะไรมาก็ไม่รู้จัก - แสดงตัวเลือกใหม่พร้อมคำแนะนำ
        $helpText = "💳 กรุณาเลือกวิธีชำระเงิน:\n\n";
        $helpText .= "💰 พิมพ์ 'โอนเงิน' - โอนเงินก่อน\n";
        $helpText .= "📦 พิมพ์ 'COD' - เก็บเงินปลายทาง\n";
        $helpText .= "❌ พิมพ์ 'ยกเลิก' - ยกเลิกการสั่งซื้อ\n\n";
        $helpText .= "หรือกดปุ่มด้านบนได้เลยค่ะ";

        return $this->replyText($replyToken, $helpText);
    }

    /**
     * Create order and show payment info
     */
    private function createOrderAndShowPayment($userId, $userDbId, $replyToken, $stateData)
    {
        $deliveryType = $stateData['delivery_type'] ?? 'shipping';
        $deliveryInfo = $stateData['delivery_info'] ?? [];
        $paymentMethod = $stateData['payment_method'] ?? 'transfer';

        // Create transaction
        $transactionId = $this->createTransaction($userDbId, $stateData);

        if (!$transactionId) {
            return $this->replyText($replyToken, '❌ เกิดข้อผิดพลาด กรุณาลองใหม่');
        }

        // Get transaction details
        $transTable = $this->getTransactionsTable();
        $stmt = $this->db->prepare("SELECT * FROM {$transTable} WHERE id = ?");
        $stmt->execute([$transactionId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        // Clear cart
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userDbId]);

        // Clear state
        $this->clearUserState($userDbId);

        if ($paymentMethod === 'cod') {
            return $this->showCODConfirmation($userId, $userDbId, $replyToken, $order, $deliveryInfo);
        } else {
            // Set state to await slip
            $this->setUserState($userDbId, 'awaiting_slip', ['order_id' => $transactionId]);
            return $this->showTransferPaymentInfo($userId, $userDbId, $replyToken, $order, $deliveryInfo);
        }
    }

    /**
     * Show COD confirmation
     */
    private function showCODConfirmation($userId, $userDbId, $replyToken, $order, $deliveryInfo)
    {
        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ!', 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "ออเดอร์ #{$order['order_number']}", 'size' => 'md', 'color' => '#888888', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '📦 เก็บเงินปลายทาง (COD)', 'weight' => 'bold', 'size' => 'md', 'margin' => 'lg', 'color' => '#FF6B00'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดชำระ', 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($deliveryInfo['name'])) {
            $bubble['body']['contents'][] = ['type' => 'separator', 'margin' => 'lg'];
            $bubble['body']['contents'][] = ['type' => 'text', 'text' => '📍 ที่อยู่จัดส่ง', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
            $bubble['body']['contents'][] = ['type' => 'text', 'text' => $deliveryInfo['name'], 'size' => 'sm', 'margin' => 'sm'];
            $bubble['body']['contents'][] = ['type' => 'text', 'text' => $deliveryInfo['phone'] ?? '', 'size' => 'sm'];
            $bubble['body']['contents'][] = ['type' => 'text', 'text' => $deliveryInfo['address'] ?? '', 'size' => 'sm', 'wrap' => true];
        }

        $bubble['body']['contents'][] = ['type' => 'text', 'text' => '🚚 รอการจัดส่ง 1-3 วันทำการ', 'size' => 'sm', 'color' => '#888888', 'margin' => 'lg', 'wrap' => true];

        $bubble['footer'] = [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'contents' => [
                ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูคำสั่งซื้อ', 'text' => 'orders'], 'style' => 'primary', 'color' => '#06C755'],
                ['type' => 'button', 'action' => ['type' => 'message', 'label' => '🛒 ช้อปต่อ', 'text' => 'shop'], 'style' => 'secondary']
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => "ออเดอร์ #{$order['order_number']} - COD", 'contents' => $bubble]
        ]);
    }

    /**
     * Show transfer payment info
     */
    private function showTransferPaymentInfo($userId, $userDbId, $replyToken, $order, $deliveryInfo)
    {
        $bankAccounts = json_decode($this->settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
        $promptpay = $this->settings['promptpay_number'] ?? '';

        $paymentContents = [];
        if ($promptpay) {
            $paymentContents[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    ['type' => 'text', 'text' => '💚', 'size' => 'sm', 'flex' => 0],
                    ['type' => 'text', 'text' => 'พร้อมเพย์: ' . $promptpay, 'size' => 'sm', 'margin' => 'sm', 'flex' => 1]
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
        if (empty($paymentContents)) {
            $paymentContents[] = ['type' => 'text', 'text' => 'กรุณาติดต่อร้านค้า', 'size' => 'sm', 'color' => '#888888'];
        }

        $bubble = [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => '✅ สั่งซื้อสำเร็จ!', 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755'],
                    ['type' => 'text', 'text' => "ออเดอร์ #{$order['order_number']}", 'size' => 'md', 'color' => '#888888', 'margin' => 'sm'],
                    ['type' => 'separator', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'ยอดชำระ', 'weight' => 'bold', 'size' => 'md', 'flex' => 1],
                            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total']), 'weight' => 'bold', 'size' => 'xl', 'color' => '#06C755', 'align' => 'end', 'flex' => 1]
                        ]
                    ],
                    ['type' => 'separator', 'margin' => 'lg'],
                    ['type' => 'text', 'text' => '📌 ช่องทางชำระเงิน:', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $paymentContents
                    ],
                    ['type' => 'text', 'text' => '📸 กรุณาส่งรูปสลิปมาเลย', 'size' => 'sm', 'color' => '#FF6B6B', 'weight' => 'bold', 'margin' => 'lg', 'wrap' => true]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📤 ส่งสลิป', 'text' => 'โอนแล้ว'], 'style' => 'primary', 'color' => '#06C755'],
                    ['type' => 'button', 'action' => ['type' => 'message', 'label' => '📋 ดูคำสั่งซื้อ', 'text' => 'orders'], 'style' => 'secondary']
                ]
            ]
        ];

        return $this->line->replyMessage($replyToken, [
            ['type' => 'flex', 'altText' => "ออเดอร์ #{$order['order_number']}", 'contents' => $bubble]
        ]);
    }

    private function handleAddressInput($userId, $userDbId, $message, $replyToken, $stateData)
    {
        // Check for cancel - handled by global handler in handleStatefulMessage
        $text = mb_strtolower(trim($message));

        // ถ้าพิมพ์คำสั่งพิเศษ
        if (in_array($text, ['กลับ', 'back', 'ย้อน'])) {
            // กลับไปเลือกวิธีจัดส่ง
            $this->setUserState($userDbId, 'checkout_delivery', $stateData);
            $subtotal = $stateData['subtotal'] ?? 0;
            return $this->showDeliveryOptions($userId, $userDbId, $replyToken, $subtotal, 0);
        }

        // Parse address (simple format: name\nphone\naddress)
        $lines = explode("\n", trim($message));
        if (count($lines) < 3) {
            $helpText = "❌ รูปแบบไม่ถูกต้อง\n\n";
            $helpText .= "📦 กรุณาส่งที่อยู่ในรูปแบบ:\n";
            $helpText .= "บรรทัด 1: ชื่อ-นามสกุล\n";
            $helpText .= "บรรทัด 2: เบอร์โทร\n";
            $helpText .= "บรรทัด 3: ที่อยู่\n\n";
            $helpText .= "ตัวอย่าง:\n";
            $helpText .= "สมชาย ใจดี\n";
            $helpText .= "0812345678\n";
            $helpText .= "123 ถ.สุขุมวิท กทม 10110\n\n";
            $helpText .= "💡 พิมพ์ 'ยกเลิก' เพื่อยกเลิก หรือ 'กลับ' เพื่อเลือกวิธีจัดส่งใหม่";
            return $this->replyText($replyToken, $helpText);
        }

        $deliveryInfo = [
            'type' => 'shipping',
            'name' => trim($lines[0]),
            'phone' => trim($lines[1]),
            'address' => trim(implode(' ', array_slice($lines, 2)))
        ];

        // Calculate shipping fee
        $subtotal = $stateData['subtotal'] ?? 0;
        $shippingFee = $this->settings['shipping_fee'] ?? 50;
        $freeShippingMin = $this->settings['free_shipping_min'] ?? 500;
        if ($subtotal >= $freeShippingMin) {
            $shippingFee = 0;
        }

        $stateData['delivery_info'] = $deliveryInfo;
        $stateData['shipping_fee'] = $shippingFee;
        $this->setUserState($userDbId, 'checkout_payment', $stateData);

        return $this->showPaymentOptions($userId, $userDbId, $replyToken, $subtotal, $shippingFee);
    }

    /**
     * Show checkout summary
     */
    private function showCheckoutSummary($userId, $userDbId, $replyToken, $deliveryType, $deliveryInfo = null)
    {
        $table = $this->getItemsTable();
        $stmt = $this->db->prepare("SELECT c.*, p.name, p.price, p.sale_price 
                                    FROM cart_items c 
                                    JOIN {$table} p ON c.product_id = p.id 
                                    WHERE c.user_id = ?");
        $stmt->execute([$userDbId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $subtotal = 0;
        foreach ($items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $subtotal += $price * $item['quantity'];
        }

        $shippingFee = 0;
        if ($deliveryType === 'shipping') {
            $shippingFee = $this->settings['shipping_fee'] ?? 50;
            $freeShippingMin = $this->settings['free_shipping_min'] ?? 500;
            if ($subtotal >= $freeShippingMin) {
                $shippingFee = 0;
            }
        }

        $total = $subtotal + $shippingFee;

        // Build summary message
        $summaryText = "📋 สรุปคำสั่งซื้อ\n\n";
        foreach ($items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $summaryText .= "• {$item['name']} x{$item['quantity']} = ฿" . number_format($price * $item['quantity']) . "\n";
        }
        $summaryText .= "\n💰 รวมสินค้า: ฿" . number_format($subtotal);
        if ($shippingFee > 0) {
            $summaryText .= "\n🚚 ค่าส่ง: ฿" . number_format($shippingFee);
        }
        $summaryText .= "\n\n💵 รวมทั้งหมด: ฿" . number_format($total);

        if ($deliveryInfo) {
            $summaryText .= "\n\n📦 ส่งถึง:\n{$deliveryInfo['name']}\n{$deliveryInfo['phone']}\n{$deliveryInfo['address']}";
        } elseif ($deliveryType === 'digital') {
            $summaryText .= "\n\n📧 ส่งทาง LINE ทันทีหลังชำระเงิน";
        }

        $summaryText .= "\n\n✅ พิมพ์ 'ยืนยัน' เพื่อสร้างออเดอร์";

        return $this->replyText($replyToken, $summaryText);
    }

    private function handleCheckoutConfirm($userId, $userDbId, $message, $replyToken, $stateData)
    {
        $text = strtolower(trim($message));

        if (!in_array($text, ['ยืนยัน', 'confirm', 'yes', 'ok'])) {
            return $this->replyText($replyToken, 'พิมพ์ "ยืนยัน" เพื่อสร้างออเดอร์ หรือ "ยกเลิก" เพื่อยกเลิก');
        }

        // Create transaction
        $transactionId = $this->createTransaction($userDbId, $stateData);

        if (!$transactionId) {
            return $this->replyText($replyToken, '❌ เกิดข้อผิดพลาด กรุณาลองใหม่');
        }

        $this->clearUserState($userDbId);

        // Show payment info
        return $this->showPaymentInfo($userId, $userDbId, $transactionId, $replyToken);
    }

    /**
     * Create transaction from cart
     */
    private function createTransaction($userDbId, $stateData)
    {
        try {
            $this->db->beginTransaction();

            $table = $this->getItemsTable();
            $transTable = $this->getTransactionsTable();

            // Get cart items
            $stmt = $this->db->prepare("SELECT c.*, p.name, p.price, p.sale_price, p.item_type, p.delivery_method, p.action_data 
                                        FROM cart_items c 
                                        JOIN {$table} p ON c.product_id = p.id 
                                        WHERE c.user_id = ?");
            $stmt->execute([$userDbId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                $this->db->rollBack();
                return null;
            }

            // Calculate totals
            $subtotal = 0;
            foreach ($items as $item) {
                $price = $item['sale_price'] ?? $item['price'];
                $subtotal += $price * $item['quantity'];
            }

            $deliveryType = $stateData['delivery_type'] ?? 'shipping';
            $shippingFee = 0;
            if ($deliveryType === 'shipping') {
                $shippingFee = $this->settings['shipping_fee'] ?? 50;
                $freeShippingMin = $this->settings['free_shipping_min'] ?? 500;
                if ($subtotal >= $freeShippingMin) {
                    $shippingFee = 0;
                }
            }

            $total = $subtotal + $shippingFee;
            $orderNumber = 'TXN' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Determine transaction type
            $hasBooking = false;
            foreach ($items as $item) {
                if (in_array($item['item_type'] ?? '', [self::TYPE_BOOKING, self::TYPE_SERVICE])) {
                    $hasBooking = true;
                    break;
                }
            }
            $transactionType = $hasBooking ? 'booking' : 'purchase';

            // Insert transaction
            $deliveryInfo = $stateData['delivery_info'] ?? null;
            $paymentMethod = $stateData['payment_method'] ?? 'transfer';

            // กำหนดสถานะตาม payment method
            // COD: ข้ามขั้นตอนรอชำระเงิน ไปยืนยันออเดอร์เลย
            $orderStatus = ($paymentMethod === 'cod') ? 'confirmed' : 'pending';
            $paymentStatus = ($paymentMethod === 'cod') ? 'cod_pending' : 'pending';

            if ($transTable === 'transactions') {
                $stmt = $this->db->prepare("INSERT INTO transactions 
                    (line_account_id, transaction_type, order_number, user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status, payment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $this->lineAccountId,
                    $transactionType,
                    $orderNumber,
                    $userDbId,
                    $subtotal,
                    $shippingFee,
                    $total,
                    $deliveryInfo ? json_encode($deliveryInfo) : null,
                    $paymentMethod,
                    $orderStatus,
                    $paymentStatus
                ]);
            } else {
                // Legacy orders table
                $stmt = $this->db->prepare("INSERT INTO orders 
                    (line_account_id, order_number, user_id, total_amount, shipping_fee, grand_total, shipping_name, shipping_phone, shipping_address, status, payment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $this->lineAccountId,
                    $orderNumber,
                    $userDbId,
                    $subtotal,
                    $shippingFee,
                    $total,
                    $deliveryInfo['name'] ?? null,
                    $deliveryInfo['phone'] ?? null,
                    $deliveryInfo['address'] ?? null,
                    $orderStatus,
                    $paymentStatus
                ]);
            }

            $transactionId = $this->db->lastInsertId();

            // Insert transaction items
            $itemsTable = $transTable === 'transactions' ? 'transaction_items' : 'order_items';
            $fkColumn = $transTable === 'transactions' ? 'transaction_id' : 'order_id';

            foreach ($items as $item) {
                $price = $item['sale_price'] ?? $item['price'];
                $itemSubtotal = $price * $item['quantity'];

                $stmt = $this->db->prepare("INSERT INTO {$itemsTable} 
                    ({$fkColumn}, product_id, product_name, product_price, quantity, subtotal" .
                    ($itemsTable === 'transaction_items' ? ", item_type" : "") . ") 
                    VALUES (?, ?, ?, ?, ?, ?" . ($itemsTable === 'transaction_items' ? ", ?" : "") . ")");

                $params = [$transactionId, $item['product_id'], $item['name'], $price, $item['quantity'], $itemSubtotal];
                if ($itemsTable === 'transaction_items') {
                    $params[] = $item['item_type'] ?? self::TYPE_PHYSICAL;
                }
                $stmt->execute($params);

                // Reduce stock
                $stmt = $this->db->prepare("UPDATE {$table} SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }

            // Clear cart
            $stmt = $this->db->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $stmt->execute([$userDbId]);

            // WMS Integration: Set wms_status to pending_pick for COD orders (already confirmed)
            if ($orderStatus === 'confirmed' && $transTable === 'transactions') {
                try {
                    $stmt = $this->db->prepare("UPDATE transactions SET wms_status = 'pending_pick' WHERE id = ?");
                    $stmt->execute([$transactionId]);
                } catch (Exception $e) {
                    // wms_status column may not exist, ignore
                }
            }

            $this->db->commit();

            $this->trackBehavior($userDbId, 'purchase', ['transaction_id' => $transactionId, 'amount' => $total]);

            return $transactionId;

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Create transaction error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Show payment info
     */
    private function showPaymentInfo($userId, $userDbId, $transactionId, $replyToken)
    {
        $transTable = $this->getTransactionsTable();
        $stmt = $this->db->prepare("SELECT * FROM {$transTable} WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        $orderNumber = $transaction['order_number'];
        $total = $transaction['grand_total'];

        // Get bank accounts
        $bankAccounts = json_decode($this->settings['bank_accounts'] ?? '{"banks":[]}', true);
        $promptpay = $this->settings['promptpay_number'] ?? '';

        $paymentText = "💳 ชำระเงิน\n\n";
        $paymentText .= "📋 เลขที่: {$orderNumber}\n";
        $paymentText .= "💰 ยอดชำระ: ฿" . number_format($total, 2) . "\n\n";

        if ($promptpay) {
            $paymentText .= "📱 PromptPay: {$promptpay}\n\n";
        }

        if (!empty($bankAccounts['banks'])) {
            $paymentText .= "🏦 โอนเงิน:\n";
            foreach ($bankAccounts['banks'] as $bank) {
                $paymentText .= "• {$bank['name']}: {$bank['account']}\n  ชื่อ: {$bank['holder']}\n";
            }
        }

        $paymentText .= "\n📸 หลังโอนเงิน กรุณาส่งสลิปมาที่นี่";

        // Set state to await slip
        $this->setUserState($userDbId, 'awaiting_slip', ['transaction_id' => $transactionId]);

        return $this->replyText($replyToken, $paymentText);
    }

    /**
     * Handle slip upload
     * Note: This is called when user sends a TEXT message while in awaiting_slip state
     * The actual IMAGE handling is done in webhook.php (handlePaymentSlipForOrder)
     */
    private function handleSlipUpload($userId, $userDbId, $message, $replyToken, $stateData)
    {
        // รองรับทั้ง order_id และ transaction_id
        $orderId = $stateData['order_id'] ?? $stateData['transaction_id'] ?? null;

        if (!$orderId) {
            $this->clearUserState($userDbId);
            return null;
        }

        // ถ้าผู้ใช้พิมพ์ข้อความแทนที่จะส่งรูป ให้แนะนำให้ส่งรูป
        $textLower = mb_strtolower(trim($message));
        if (!in_array($textLower, ['ยกเลิก', 'cancel', 'ออก', 'exit'])) {
            return $this->replyText($replyToken, "📸 กรุณาส่งรูปสลิปการโอนเงินมาเลยค่ะ\n\n(หรือพิมพ์ 'ยกเลิก' เพื่อยกเลิก)");
        }

        // ผู้ใช้ต้องการยกเลิก
        $this->clearUserState($userDbId);
        return $this->replyText($replyToken, "❌ ยกเลิกการส่งสลิปแล้ว\n\nพิมพ์ 'orders' เพื่อดูรายการของคุณ\nหรือพิมพ์ 'สลิป' เพื่อส่งสลิปใหม่");
    }

    /**
     * Show user's orders/transactions
     */
    public function showOrders($userId, $userDbId, $replyToken)
    {
        $orders = [];
        try {
            $transTable = $this->getTransactionsTable();

            // ถ้าไม่มีตาราง
            if (!$transTable) {
                $flex = FlexTemplates::info('ยังไม่มีรายการ', 'ระบบคำสั่งซื้อยังไม่พร้อมใช้งาน', [['label' => '🛒 ไปช้อป', 'text' => 'shop']]);
                return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'รายการของฉัน')]);
            }

            // ตรวจสอบว่ามี column line_account_id หรือไม่
            $hasAccountCol = false;
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$transTable} LIKE 'line_account_id'");
                $hasAccountCol = $stmt->rowCount() > 0;
            } catch (Exception $e) {
            }

            $sql = "SELECT * FROM {$transTable} WHERE user_id = ?";
            if ($this->lineAccountId && $hasAccountCol) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $sql .= " ORDER BY created_at DESC LIMIT 5";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$userDbId, $this->lineAccountId]);
            } else {
                $sql .= " ORDER BY created_at DESC LIMIT 5";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$userDbId]);
            }
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // ตารางไม่มี
            $this->logError('showOrders', $e->getMessage());
            $orders = [];
        }

        if (empty($orders)) {
            $flex = FlexTemplates::info('ยังไม่มีรายการ', 'คุณยังไม่มีรายการสั่งซื้อ', [['label' => '🛒 ไปช้อป', 'text' => 'shop']]);
            return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'รายการของฉัน')]);
        }

        $bubbles = [];
        foreach ($orders as $order) {
            $statusConfig = $this->getStatusConfig($order['status']);

            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => "#{$order['order_number']}", 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => "฿" . number_format($order['grand_total'], 2), 'size' => 'xl', 'weight' => 'bold', 'color' => '#06C755', 'margin' => 'md'],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                ['type' => 'text', 'text' => $statusConfig['icon'] . ' ' . $statusConfig['text'], 'size' => 'sm', 'color' => $statusConfig['color']]
                            ],
                            'margin' => 'md'
                        ],
                        ['type' => 'text', 'text' => date('d/m/Y H:i', strtotime($order['created_at'])), 'size' => 'xs', 'color' => '#888888', 'margin' => 'md']
                    ],
                    'paddingAll' => 'lg'
                ]
            ];
        }

        $flex = ['type' => 'carousel', 'contents' => $bubbles];
        return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'รายการของฉัน')]);
    }

    private function getStatusConfig($status)
    {
        $configs = [
            'pending' => ['icon' => '⏳', 'text' => 'รอดำเนินการ', 'color' => '#F59E0B'],
            'confirmed' => ['icon' => '✅', 'text' => 'ยืนยันแล้ว', 'color' => '#06C755'],
            'paid' => ['icon' => '💰', 'text' => 'ชำระแล้ว', 'color' => '#06C755'],
            'shipping' => ['icon' => '🚚', 'text' => 'กำลังจัดส่ง', 'color' => '#3B82F6'],
            'delivered' => ['icon' => '📦', 'text' => 'จัดส่งแล้ว', 'color' => '#10B981'],
            'cancelled' => ['icon' => '❌', 'text' => 'ยกเลิก', 'color' => '#EF4444']
        ];
        return $configs[$status] ?? ['icon' => '📋', 'text' => $status, 'color' => '#888888'];
    }

    /**
     * Show user's loyalty points - Member Card Style
     */
    public function showPoints($userId, $userDbId, $replyToken)
    {
        try {
            // Get user info
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userDbId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->replyText($replyToken, "ไม่พบข้อมูลสมาชิก");
            }

            // Get points
            require_once __DIR__ . '/LoyaltyPoints.php';
            $loyalty = new \LoyaltyPoints($this->db, $this->lineAccountId);
            $userPoints = $loyalty->getUserPoints($userDbId);
            $history = $loyalty->getPointsHistory($userDbId, 5);

            // Get member tier
            $tier = $this->getMemberTier($userPoints['total_points']);

            // Get shop name
            $shopName = 'LINE Shop';
            try {
                $stmt = $this->db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
                $settings = $stmt->fetch();
                if ($settings)
                    $shopName = $settings['shop_name'];
            } catch (Exception $e) {
            }

            // Build history contents
            $historyContents = [];
            if (!empty($history)) {
                $historyContents[] = ['type' => 'text', 'text' => 'ประวัติล่าสุด', 'size' => 'sm', 'weight' => 'bold', 'margin' => 'lg', 'color' => '#555555'];
                foreach (array_slice($history, 0, 3) as $h) {
                    $sign = $h['type'] === 'earn' ? '+' : '-';
                    $color = $h['type'] === 'earn' ? '#06C755' : '#EF4444';
                    $historyContents[] = [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'sm',
                        'contents' => [
                            ['type' => 'text', 'text' => mb_substr($h['description'], 0, 18), 'size' => 'xs', 'color' => '#888888', 'flex' => 3],
                            ['type' => 'text', 'text' => $sign . number_format(abs($h['points'])), 'size' => 'xs', 'color' => $color, 'align' => 'end', 'flex' => 1]
                        ]
                    ];
                }
            }

            // Build Points Card Flex (Member Card Style)
            $pointsCard = [
                'type' => 'bubble',
                'size' => 'mega',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $shopName, 'color' => '#FFFFFF', 'size' => 'sm'],
                        ['type' => 'text', 'text' => '💎 POINTS CARD', 'color' => '#FFFFFF', 'size' => 'xl', 'weight' => 'bold']
                    ],
                    'backgroundColor' => $tier['color'],
                    'paddingAll' => 'lg'
                ],
                'hero' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'image',
                                            'url' => $user['picture_url'] ?: 'https://via.placeholder.com/100',
                                            'size' => 'full',
                                            'aspectMode' => 'cover',
                                            'aspectRatio' => '1:1'
                                        ]
                                    ],
                                    'width' => '80px',
                                    'height' => '80px',
                                    'cornerRadius' => '40px',
                                    'borderWidth' => '3px',
                                    'borderColor' => $tier['color']
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => $user['display_name'] ?: 'Member', 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                                        ['type' => 'text', 'text' => $tier['icon'] . ' ' . $tier['name'], 'size' => 'sm', 'color' => $tier['color'], 'weight' => 'bold'],
                                        ['type' => 'text', 'text' => 'ID: ' . str_pad($userDbId, 6, '0', STR_PAD_LEFT), 'size' => 'xs', 'color' => '#888888']
                                    ],
                                    'margin' => 'lg',
                                    'flex' => 1
                                ]
                            ],
                            'alignItems' => 'center'
                        ]
                    ],
                    'paddingAll' => 'lg',
                    'backgroundColor' => '#FAFAFA'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => array_merge([
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'แต้มคงเหลือ', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                        ['type' => 'text', 'text' => number_format($userPoints['available_points']), 'size' => 'xxl', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'center']
                                    ],
                                    'flex' => 1
                                ],
                                ['type' => 'separator'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'สะสมทั้งหมด', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                        ['type' => 'text', 'text' => number_format($userPoints['total_points']), 'size' => 'xxl', 'weight' => 'bold', 'align' => 'center']
                                    ],
                                    'flex' => 1
                                ],
                                ['type' => 'separator'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'ใช้ไปแล้ว', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                        ['type' => 'text', 'text' => number_format($userPoints['used_points']), 'size' => 'xxl', 'weight' => 'bold', 'color' => '#EF4444', 'align' => 'center']
                                    ],
                                    'flex' => 1
                                ]
                            ],
                            'paddingAll' => 'md',
                            'backgroundColor' => '#F8F8F8',
                            'cornerRadius' => 'md'
                        ]
                    ], $historyContents),
                    'paddingAll' => 'lg'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'บัตรสมาชิก', 'text' => 'สมาชิก'], 'style' => 'secondary', 'height' => 'sm', 'flex' => 1],
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'แลกของรางวัล', 'text' => 'ของรางวัล'], 'style' => 'primary', 'color' => '#06C755', 'height' => 'sm', 'flex' => 1, 'margin' => 'sm']
                    ],
                    'paddingAll' => 'md'
                ]
            ];

            return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($pointsCard, 'แต้มสะสม')]);
        } catch (Exception $e) {
            $this->logError('showPoints', $e->getMessage());
            return $this->replyText($replyToken, "ระบบแต้มสะสมยังไม่พร้อมใช้งาน");
        }
    }

    /**
     * Show available rewards for redemption
     */
    public function showRewards($userId, $userDbId, $replyToken)
    {
        try {
            require_once __DIR__ . '/LoyaltyPoints.php';
            $loyalty = new \LoyaltyPoints($this->db, $this->lineAccountId);
            $rewards = $loyalty->getRewards(true);
            $userPoints = $loyalty->getUserPoints($userDbId);

            if (empty($rewards)) {
                $flex = FlexTemplates::info('ยังไม่มีของรางวัล', 'ร้านค้ายังไม่มีของรางวัลให้แลก', [['label' => 'ดูแต้ม', 'text' => 'แต้ม']]);
                return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'ของรางวัล')]);
            }

            $bubbles = [];
            foreach (array_slice($rewards, 0, 10) as $reward) {
                $canRedeem = $userPoints['available_points'] >= $reward['points_required'];
                $bubble = [
                    'type' => 'bubble',
                    'size' => 'kilo',
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => $reward['name'], 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                            ['type' => 'text', 'text' => number_format($reward['points_required']) . ' แต้ม', 'size' => 'lg', 'weight' => 'bold', 'color' => '#06C755', 'margin' => 'md'],
                            ['type' => 'text', 'text' => $reward['stock'] < 0 ? 'ไม่จำกัดจำนวน' : "เหลือ {$reward['stock']} ชิ้น", 'size' => 'xs', 'color' => '#888888', 'margin' => 'sm']
                        ],
                        'paddingAll' => 'lg'
                    ],
                    'footer' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'button', 'action' => ['type' => 'message', 'label' => $canRedeem ? 'แลกเลย' : 'แต้มไม่พอ', 'text' => "redeem {$reward['id']}"], 'style' => $canRedeem ? 'primary' : 'secondary', 'color' => $canRedeem ? '#06C755' : '#CCCCCC', 'height' => 'sm']
                        ],
                        'paddingAll' => 'md'
                    ]
                ];
                if (!empty($reward['image_url'])) {
                    $bubble['hero'] = ['type' => 'image', 'url' => $reward['image_url'], 'size' => 'full', 'aspectRatio' => '4:3', 'aspectMode' => 'cover'];
                }
                $bubbles[] = $bubble;
            }

            $flex = ['type' => 'carousel', 'contents' => $bubbles];
            return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'ของรางวัล')]);
        } catch (Exception $e) {
            $this->logError('showRewards', $e->getMessage());
            return $this->replyText($replyToken, "ระบบของรางวัลยังไม่พร้อมใช้งาน");
        }
    }

    /**
     * Show member card - บัตรสมาชิก
     */
    public function showMemberCard($userId, $userDbId, $replyToken)
    {
        try {
            // Get user info
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userDbId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->replyText($replyToken, "ไม่พบข้อมูลสมาชิก");
            }

            // Get points
            $points = ['available_points' => 0, 'total_points' => 0];
            try {
                require_once __DIR__ . '/LoyaltyPoints.php';
                $loyalty = new \LoyaltyPoints($this->db, $this->lineAccountId);
                $points = $loyalty->getUserPoints($userDbId);
            } catch (Exception $e) {
            }

            // Get member tier
            $tier = $this->getMemberTier($points['total_points']);

            // Get shop name
            $shopName = 'LINE Shop';
            try {
                $stmt = $this->db->query("SELECT shop_name FROM shop_settings WHERE id = 1");
                $settings = $stmt->fetch();
                if ($settings)
                    $shopName = $settings['shop_name'];
            } catch (Exception $e) {
            }

            // Build member card Flex
            $memberCard = [
                'type' => 'bubble',
                'size' => 'mega',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $shopName, 'color' => '#FFFFFF', 'size' => 'sm'],
                        ['type' => 'text', 'text' => 'MEMBER CARD', 'color' => '#FFFFFF', 'size' => 'xxl', 'weight' => 'bold']
                    ],
                    'backgroundColor' => $tier['color'],
                    'paddingAll' => 'lg'
                ],
                'hero' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'image',
                                            'url' => $user['picture_url'] ?: 'https://via.placeholder.com/100',
                                            'size' => 'full',
                                            'aspectMode' => 'cover',
                                            'aspectRatio' => '1:1'
                                        ]
                                    ],
                                    'width' => '80px',
                                    'height' => '80px',
                                    'cornerRadius' => '40px',
                                    'borderWidth' => '3px',
                                    'borderColor' => $tier['color']
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => $user['display_name'] ?: 'Member', 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
                                        ['type' => 'text', 'text' => $tier['icon'] . ' ' . $tier['name'], 'size' => 'sm', 'color' => $tier['color'], 'weight' => 'bold'],
                                        ['type' => 'text', 'text' => 'ID: ' . str_pad($userDbId, 6, '0', STR_PAD_LEFT), 'size' => 'xs', 'color' => '#888888']
                                    ],
                                    'margin' => 'lg',
                                    'flex' => 1
                                ]
                            ],
                            'alignItems' => 'center'
                        ]
                    ],
                    'paddingAll' => 'lg',
                    'backgroundColor' => '#FAFAFA'
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'แต้มสะสม', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                        ['type' => 'text', 'text' => number_format($points['available_points']), 'size' => 'xl', 'weight' => 'bold', 'color' => '#06C755', 'align' => 'center']
                                    ],
                                    'flex' => 1
                                ],
                                ['type' => 'separator'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        ['type' => 'text', 'text' => 'สะสมทั้งหมด', 'size' => 'xs', 'color' => '#888888', 'align' => 'center'],
                                        ['type' => 'text', 'text' => number_format($points['total_points']), 'size' => 'xl', 'weight' => 'bold', 'align' => 'center']
                                    ],
                                    'flex' => 1
                                ]
                            ],
                            'paddingAll' => 'md',
                            'backgroundColor' => '#F8F8F8',
                            'cornerRadius' => 'md'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => 'สมาชิกตั้งแต่: ' . date('d/m/Y', strtotime($user['created_at'])), 'size' => 'xs', 'color' => '#AAAAAA', 'align' => 'center']
                            ],
                            'margin' => 'md'
                        ]
                    ],
                    'paddingAll' => 'lg'
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'ดูแต้ม', 'text' => 'แต้ม'], 'style' => 'primary', 'color' => '#06C755', 'height' => 'sm', 'flex' => 1],
                        ['type' => 'button', 'action' => ['type' => 'message', 'label' => 'แลกของรางวัล', 'text' => 'ของรางวัล'], 'style' => 'secondary', 'height' => 'sm', 'flex' => 1, 'margin' => 'sm']
                    ],
                    'paddingAll' => 'md'
                ]
            ];

            return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($memberCard, 'บัตรสมาชิก')]);
        } catch (Exception $e) {
            $this->logError('showMemberCard', $e->getMessage());
            return $this->replyText($replyToken, "เกิดข้อผิดพลาด กรุณาลองใหม่");
        }
    }

    /**
     * Get member tier based on total points
     */
    private function getMemberTier($totalPoints)
    {
        if ($totalPoints >= 10000) {
            return ['name' => 'Diamond', 'icon' => '💎', 'color' => '#00CED1'];
        } elseif ($totalPoints >= 5000) {
            return ['name' => 'Platinum', 'icon' => '🏆', 'color' => '#8B008B'];
        } elseif ($totalPoints >= 2000) {
            return ['name' => 'Gold', 'icon' => '🥇', 'color' => '#FFD700'];
        } elseif ($totalPoints >= 500) {
            return ['name' => 'Silver', 'icon' => '🥈', 'color' => '#C0C0C0'];
        } else {
            return ['name' => 'Bronze', 'icon' => '🥉', 'color' => '#CD7F32'];
        }
    }

    /**
     * Redeem a reward
     */
    public function redeemReward($userId, $userDbId, $rewardId, $replyToken)
    {
        try {
            require_once __DIR__ . '/LoyaltyPoints.php';
            $loyalty = new \LoyaltyPoints($this->db, $this->lineAccountId);
            $result = $loyalty->redeemReward($userDbId, $rewardId);

            if ($result['success']) {
                $flex = FlexTemplates::success(
                    'แลกของรางวัลสำเร็จ!',
                    "รหัส: {$result['redemption_code']}\n{$result['reward']['name']}",
                    [['label' => 'ดูแต้มคงเหลือ', 'text' => 'แต้ม']]
                );
                return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'แลกของรางวัล')]);
            } else {
                return $this->replyText($replyToken, "ไม่สามารถแลกได้: {$result['message']}");
            }
        } catch (Exception $e) {
            $this->logError('redeemReward', $e->getMessage());
            return $this->replyText($replyToken, "เกิดข้อผิดพลาด กรุณาลองใหม่");
        }
    }

    /**
     * Start booking process for service/booking items
     */
    public function startBooking($userId, $userDbId, $itemId, $replyToken)
    {
        $item = $this->getItem($itemId);
        if (!$item) {
            return $this->replyText($replyToken, 'ไม่พบบริการนี้');
        }

        $itemType = $item['item_type'] ?? self::TYPE_PHYSICAL;
        if (!in_array($itemType, [self::TYPE_BOOKING, self::TYPE_SERVICE])) {
            // Not a bookable item, add to cart instead
            return $this->addToCart($userId, $userDbId, $itemId, 1, $replyToken);
        }

        // Set state for booking flow
        $this->setUserState($userDbId, 'booking_select_date', ['item_id' => $itemId, 'item_name' => $item['name']]);

        // Show date picker (simplified - just ask for date)
        $message = "📅 จอง: {$item['name']}\n\n";
        $message .= "กรุณาพิมพ์วันที่ต้องการจอง\n";
        $message .= "รูปแบบ: DD/MM/YYYY\n";
        $message .= "ตัวอย่าง: " . date('d/m/Y', strtotime('+1 day'));

        return $this->replyText($replyToken, $message);
    }

    private function handleBookingDate($userId, $userDbId, $message, $replyToken, $stateData)
    {
        // Parse date
        $date = null;
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $message, $matches)) {
            $date = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        } elseif (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $message, $matches)) {
            $date = $message;
        }

        if (!$date || strtotime($date) < strtotime('today')) {
            return $this->replyText($replyToken, "❌ วันที่ไม่ถูกต้อง กรุณาระบุวันที่ในอนาคต\nรูปแบบ: DD/MM/YYYY");
        }

        $stateData['booking_date'] = $date;
        $this->setUserState($userDbId, 'booking_select_time', $stateData);

        $message = "⏰ เลือกเวลา\n\n";
        $message .= "วันที่: " . date('d/m/Y', strtotime($date)) . "\n\n";
        $message .= "กรุณาพิมพ์เวลาที่ต้องการ\n";
        $message .= "รูปแบบ: HH:MM\n";
        $message .= "ตัวอย่าง: 10:00, 14:30";

        return $this->replyText($replyToken, $message);
    }

    private function handleBookingTime($userId, $userDbId, $message, $replyToken, $stateData)
    {
        // Parse time
        if (!preg_match('/(\d{1,2}):(\d{2})/', $message, $matches)) {
            return $this->replyText($replyToken, "❌ เวลาไม่ถูกต้อง\nรูปแบบ: HH:MM");
        }

        $time = sprintf('%02d:%02d', $matches[1], $matches[2]);
        $stateData['booking_time'] = $time;

        // Create booking
        $bookingId = $this->createBooking($userDbId, $stateData);

        $this->clearUserState($userDbId);

        if (!$bookingId) {
            return $this->replyText($replyToken, '❌ เกิดข้อผิดพลาด กรุณาลองใหม่');
        }

        $flex = FlexTemplates::success(
            'จองสำเร็จ!',
            "{$stateData['item_name']}\n📅 " . date('d/m/Y', strtotime($stateData['booking_date'])) . " ⏰ {$time}",
            [['label' => '📋 ดูการจอง', 'text' => 'orders']]
        );

        return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, 'จองสำเร็จ')]);
    }

    private function createBooking($userDbId, $stateData)
    {
        try {
            $item = $this->getItem($stateData['item_id']);
            if (!$item)
                return null;

            $price = $item['sale_price'] ?? $item['price'];
            $orderNumber = 'BK' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

            $transTable = $this->getTransactionsTable();

            $deliveryInfo = [
                'type' => 'booking',
                'date' => $stateData['booking_date'],
                'time' => $stateData['booking_time']
            ];

            if ($transTable === 'transactions') {
                $stmt = $this->db->prepare("INSERT INTO transactions 
                    (line_account_id, transaction_type, order_number, user_id, total_amount, grand_total, delivery_info, status) 
                    VALUES (?, 'booking', ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $this->lineAccountId,
                    $orderNumber,
                    $userDbId,
                    $price,
                    $price,
                    json_encode($deliveryInfo)
                ]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO orders 
                    (line_account_id, order_number, user_id, total_amount, grand_total, note, status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $this->lineAccountId,
                    $orderNumber,
                    $userDbId,
                    $price,
                    $price,
                    "Booking: {$stateData['booking_date']} {$stateData['booking_time']}"
                ]);
            }

            $transactionId = $this->db->lastInsertId();

            // Insert item
            $itemsTable = $transTable === 'transactions' ? 'transaction_items' : 'order_items';
            $fkColumn = $transTable === 'transactions' ? 'transaction_id' : 'order_id';

            $stmt = $this->db->prepare("INSERT INTO {$itemsTable} ({$fkColumn}, product_id, product_name, product_price, quantity, subtotal) VALUES (?, ?, ?, ?, 1, ?)");
            $stmt->execute([$transactionId, $item['id'], $item['name'], $price, $price]);

            $this->trackBehavior($userDbId, 'booking', ['transaction_id' => $transactionId, 'item_id' => $item['id']]);

            return $transactionId;

        } catch (Exception $e) {
            error_log("Create booking error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fulfill digital item - send code/link via LINE
     */
    public function fulfillDigitalItem($transactionId, $itemId)
    {
        try {
            $item = $this->getItem($itemId);
            if (!$item)
                return false;

            $actionData = $item['action_data'] ?? [];
            $deliveryMethod = $item['delivery_method'] ?? self::DELIVER_LINE;

            // Get transaction and user
            $transTable = $this->getTransactionsTable();
            $stmt = $this->db->prepare("SELECT t.*, u.line_user_id FROM {$transTable} t JOIN users u ON t.user_id = u.id WHERE t.id = ?");
            $stmt->execute([$transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction)
                return false;

            $lineUserId = $transaction['line_user_id'];
            $fulfillmentData = [];

            switch ($deliveryMethod) {
                case self::DELIVER_LINE:
                    // Send via LINE message
                    if (!empty($actionData['game_code'])) {
                        $message = "🎮 โค้ดเกมของคุณ\n\n";
                        $message .= "📋 รายการ: {$item['name']}\n";
                        $message .= "🔑 โค้ด: {$actionData['game_code']}\n\n";
                        $message .= "⚠️ กรุณาเก็บโค้ดนี้ไว้ ใช้ได้ครั้งเดียว";

                        $this->line->pushMessage($lineUserId, $message);
                        $fulfillmentData['code_sent'] = $actionData['game_code'];
                    }
                    break;

                case self::DELIVER_DOWNLOAD:
                    // Send download link
                    if (!empty($actionData['download_url'])) {
                        $message = "📥 ลิงก์ดาวน์โหลด\n\n";
                        $message .= "📋 รายการ: {$item['name']}\n";
                        $message .= "🔗 {$actionData['download_url']}\n\n";
                        if (!empty($item['validity_days'])) {
                            $message .= "⏰ ลิงก์หมดอายุใน {$item['validity_days']} วัน";
                        }

                        $this->line->pushMessage($lineUserId, $message);
                        $fulfillmentData['download_url'] = $actionData['download_url'];
                    }
                    break;

                case self::DELIVER_EMAIL:
                    // Mark for email delivery (handled separately)
                    $fulfillmentData['email_pending'] = true;
                    break;
            }

            // Update transaction item as delivered
            $itemsTable = $transTable === 'transactions' ? 'transaction_items' : 'order_items';
            $fkColumn = $transTable === 'transactions' ? 'transaction_id' : 'order_id';

            if ($transTable === 'transactions') {
                $stmt = $this->db->prepare("UPDATE transaction_items SET item_data = ?, delivered_at = NOW() WHERE {$fkColumn} = ? AND product_id = ?");
                $stmt->execute([json_encode($fulfillmentData), $transactionId, $itemId]);
            }

            return true;

        } catch (Exception $e) {
            error_log("Fulfill digital item error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-fulfill all digital items in a transaction
     */
    public function autoFulfillDigitalItems($transactionId)
    {
        $transTable = $this->getTransactionsTable();
        $itemsTable = $transTable === 'transactions' ? 'transaction_items' : 'order_items';
        $fkColumn = $transTable === 'transactions' ? 'transaction_id' : 'order_id';
        $table = $this->getItemsTable();

        $stmt = $this->db->prepare("SELECT ti.*, p.item_type, p.delivery_method 
                                    FROM {$itemsTable} ti 
                                    JOIN {$table} p ON ti.product_id = p.id 
                                    WHERE ti.{$fkColumn} = ?");
        $stmt->execute([$transactionId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $allFulfilled = true;
        foreach ($items as $item) {
            $itemType = $item['item_type'] ?? self::TYPE_PHYSICAL;
            $deliveryMethod = $item['delivery_method'] ?? self::DELIVER_SHIPPING;

            // Auto-fulfill digital items
            if (
                in_array($itemType, [self::TYPE_DIGITAL, self::TYPE_CONTENT]) ||
                in_array($deliveryMethod, [self::DELIVER_LINE, self::DELIVER_DOWNLOAD, self::DELIVER_EMAIL])
            ) {

                if (!$this->fulfillDigitalItem($transactionId, $item['product_id'])) {
                    $allFulfilled = false;
                }
            }
        }

        // Update transaction fulfillment status
        if ($transTable === 'transactions') {
            $status = $allFulfilled ? 'fulfilled' : 'processing';
            $stmt = $this->db->prepare("UPDATE transactions SET fulfillment_status = ?, fulfilled_at = IF(? = 'fulfilled', NOW(), NULL) WHERE id = ?");
            $stmt->execute([$status, $status, $transactionId]);
        }

        return $allFulfilled;
    }

    // =============================================
    // User State Management
    // =============================================

    public function getUserState($userDbId)
    {
        try {
            // ตรวจสอบว่าตารางมีอยู่ก่อน
            $this->ensureUserStatesTable();

            // ลองดึงข้อมูลโดยไม่ตรวจสอบ expires_at ก่อน
            $stmt = $this->db->prepare("SELECT * FROM user_states WHERE user_id = ?");
            $stmt->execute([$userDbId]);
            $rawState = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($rawState) {
                // ตรวจสอบว่า expires_at หมดอายุหรือยัง
                $expired = $rawState['expires_at'] && strtotime($rawState['expires_at']) < time();

                $this->logDebug('getUserState', "Raw state found", [
                    'user_id' => $userDbId,
                    'state' => $rawState['state'],
                    'expires_at' => $rawState['expires_at'],
                    'expired' => $expired,
                    'now' => date('Y-m-d H:i:s')
                ]);

                if (!$expired) {
                    return $rawState;
                } else {
                    // State หมดอายุ - ลบทิ้ง
                    $this->clearUserState($userDbId);
                    return null;
                }
            }

            return null;
        } catch (Exception $e) {
            $this->logError('getUserState', $e->getMessage(), ['user_id' => $userDbId]);
            return null;
        }
    }

    public function setUserState($userDbId, $state, $data = [], $expiresMinutes = 30)
    {
        try {
            // ตรวจสอบและสร้างตาราง user_states ถ้าไม่มี
            $this->ensureUserStatesTable();

            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));
            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);

            // ใช้ REPLACE INTO เพื่อให้ทำงานได้กับทุก schema
            $stmt = $this->db->prepare("REPLACE INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userDbId, $state, $dataJson, $expiresAt]);

            $this->logDebug('setUserState', "State set: {$state}", [
                'user_id' => $userDbId,
                'state' => $state,
                'expires_at' => $expiresAt,
                'rowCount' => $stmt->rowCount()
            ]);

            return true;
        } catch (Exception $e) {
            $this->logError('setUserState', $e->getMessage(), ['user_id' => $userDbId, 'state' => $state]);
            return false;
        }
    }

    /**
     * ตรวจสอบและสร้างตาราง user_states ถ้าไม่มี
     */
    private function ensureUserStatesTable()
    {
        static $checked = false;
        if ($checked)
            return;

        try {
            $this->db->query("SELECT 1 FROM user_states LIMIT 1");
            // ตารางมีอยู่แล้ว - ใช้ได้เลย
        } catch (Exception $e) {
            // ตารางไม่มี - สร้างใหม่
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS user_states (
                    user_id INT PRIMARY KEY,
                    state VARCHAR(50) NOT NULL,
                    state_data JSON,
                    expires_at TIMESTAMP NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $checked = true;
    }

    public function clearUserState($userDbId)
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_states WHERE user_id = ?");
            $stmt->execute([$userDbId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if state is related to shop features
     */
    private function isShopRelatedState($stateName)
    {
        $shopStates = [
            'checkout',
            'awaiting_address',
            'awaiting_phone',
            'awaiting_payment',
            'awaiting_slip',
            'booking',
            'awaiting_booking_date',
            'awaiting_booking_time',
            'cart',
            'order',
            'payment'
        ];

        foreach ($shopStates as $shopState) {
            if (stripos($stateName, $shopState) !== false) {
                return true;
            }
        }

        return false;
    }

    // =============================================
    // User Behavior Tracking & Tagging
    // =============================================

    public function trackBehavior($userDbId, $type, $data = [])
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO user_behaviors (line_account_id, user_id, behavior_type, behavior_data) VALUES (?, ?, ?, ?)");
            $stmt->execute([$this->lineAccountId, $userDbId, $type, json_encode($data)]);

            // Check auto-tagging rules
            $this->checkAutoTagging($userDbId, $type, $data);

            return true;
        } catch (Exception $e) {
            // Table might not exist yet
            return false;
        }
    }

    private function checkAutoTagging($userDbId, $behaviorType, $data)
    {
        try {
            $stmt = $this->db->query("SELECT * FROM user_tags WHERE auto_assign_rules IS NOT NULL");
            $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tags as $tag) {
                $rules = json_decode($tag['auto_assign_rules'], true);
                if (!$rules)
                    continue;

                $shouldAssign = false;

                switch ($rules['trigger'] ?? '') {
                    case 'behavior':
                        if (($rules['action'] ?? '') === $behaviorType) {
                            $shouldAssign = true;
                        }
                        break;

                    case 'keyword':
                        $keywords = $rules['keywords'] ?? [];
                        $text = $data['text'] ?? '';
                        foreach ($keywords as $kw) {
                            if (stripos($text, $kw) !== false) {
                                $shouldAssign = true;
                                break;
                            }
                        }
                        break;

                    case 'purchase_count':
                        if ($behaviorType === 'purchase') {
                            $minCount = $rules['min_count'] ?? 5;
                            $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_behaviors WHERE user_id = ? AND behavior_type = 'purchase'");
                            $stmt->execute([$userDbId]);
                            if ($stmt->fetchColumn() >= $minCount) {
                                $shouldAssign = true;
                            }
                        }
                        break;
                }

                if ($shouldAssign) {
                    $this->assignTag($userDbId, $tag['id'], 'auto');
                }
            }
        } catch (Exception $e) {
            // Tags table might not exist
        }
    }

    public function assignTag($userDbId, $tagId, $assignedBy = 'manual')
    {
        try {
            $stmt = $this->db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$userDbId, $tagId, $assignedBy]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function removeTag($userDbId, $tagId)
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userDbId, $tagId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getUserTags($userDbId)
    {
        try {
            $stmt = $this->db->prepare("SELECT t.* FROM user_tags t 
                                        JOIN user_tag_assignments a ON t.id = a.tag_id 
                                        WHERE a.user_id = ?");
            $stmt->execute([$userDbId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    // =============================================
    // Rich Menu Personalization
    // =============================================

    /**
     * Assign personalized Rich Menu to user based on tags/status
     */
    public function assignPersonalizedRichMenu($userDbId, $lineUserId)
    {
        try {
            // Get user tags
            $tags = $this->getUserTags($userDbId);
            $tagNames = array_column($tags, 'name');

            // Determine which Rich Menu to assign
            $richMenuId = $this->determineRichMenu($userDbId, $tagNames);

            if (!$richMenuId)
                return false;

            // Get LINE Rich Menu ID
            $stmt = $this->db->prepare("SELECT line_rich_menu_id FROM rich_menus WHERE id = ?");
            $stmt->execute([$richMenuId]);
            $richMenu = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$richMenu || !$richMenu['line_rich_menu_id'])
                return false;

            // Assign via LINE API
            $result = $this->line->linkRichMenuToUser($lineUserId, $richMenu['line_rich_menu_id']);

            if ($result['code'] === 200) {
                // Save assignment
                $stmt = $this->db->prepare("INSERT INTO user_rich_menus (line_account_id, user_id, rich_menu_id, line_rich_menu_id, assigned_reason) 
                                            VALUES (?, ?, ?, ?, ?) 
                                            ON DUPLICATE KEY UPDATE rich_menu_id = ?, line_rich_menu_id = ?, assigned_reason = ?, assigned_at = NOW()");
                $reason = implode(',', $tagNames) ?: 'default';
                $stmt->execute([
                    $this->lineAccountId,
                    $userDbId,
                    $richMenuId,
                    $richMenu['line_rich_menu_id'],
                    $reason,
                    $richMenuId,
                    $richMenu['line_rich_menu_id'],
                    $reason
                ]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Assign Rich Menu error: " . $e->getMessage());
            return false;
        }
    }

    private function determineRichMenu($userDbId, $tagNames)
    {
        // Priority-based menu selection
        // 1. VIP customers
        if (in_array('VIP', $tagNames)) {
            $menu = $this->getRichMenuByName('VIP Menu');
            if ($menu)
                return $menu['id'];
        }

        // 2. New customers
        if (in_array('New Customer', $tagNames)) {
            $menu = $this->getRichMenuByName('Welcome Menu');
            if ($menu)
                return $menu['id'];
        }

        // 3. Inactive customers
        if (in_array('Inactive', $tagNames)) {
            $menu = $this->getRichMenuByName('Re-engagement Menu');
            if ($menu)
                return $menu['id'];
        }

        // 4. Default menu
        $stmt = $this->db->prepare("SELECT id FROM rich_menus WHERE is_default = 1 AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1");
        $stmt->execute([$this->lineAccountId]);
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);

        return $menu ? $menu['id'] : null;
    }

    private function getRichMenuByName($name)
    {
        $stmt = $this->db->prepare("SELECT * FROM rich_menus WHERE name LIKE ? AND (line_account_id = ? OR line_account_id IS NULL) LIMIT 1");
        $stmt->execute(["%{$name}%", $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // =============================================
    // Helper Methods
    // =============================================

    public function showItemDetail($userId, $userDbId, $itemId, $replyToken)
    {
        $item = $this->getItem($itemId);
        if (!$item) {
            return $this->line->replyMessage($replyToken, ['type' => 'text', 'text' => 'ไม่พบสินค้านี้']);
        }

        $this->trackBehavior($userDbId, 'view_item', ['item_id' => $itemId]);

        $flex = $this->buildItemCard($item);
        return $this->line->replyMessage($replyToken, [FlexTemplates::toMessage($flex, $item['name'], 'shop')]);
    }

    public function showHelp($userId, $userDbId, $replyToken)
    {
        $helpText = "📖 วิธีใช้งาน\n\n";
        $helpText .= "🛒 shop - ดูสินค้า/บริการ\n";
        $helpText .= "🛍️ cart - ดูตะกร้า\n";
        $helpText .= "📋 orders - ดูรายการของฉัน\n";
        $helpText .= "📞 contact - ติดต่อเรา\n\n";
        $helpText .= "💡 พิมพ์ 'add [เลข]' เพื่อเพิ่มสินค้า\n";
        $helpText .= "💡 พิมพ์ 'book [เลข]' เพื่อจองบริการ";

        return $this->line->replyMessage($replyToken, ['type' => 'text', 'text' => $helpText]);
    }

    public function showContact($userId, $userDbId, $replyToken)
    {
        $shopName = $this->settings['shop_name'] ?? 'LINE Business';
        $phone = $this->settings['contact_phone'] ?? '';

        $contactText = "📞 ติดต่อ {$shopName}\n\n";
        if ($phone) {
            $contactText .= "📱 โทร: {$phone}\n";
        }
        $contactText .= "💬 แชทกับเราได้ที่นี่เลย!";

        return $this->line->replyMessage($replyToken, ['type' => 'text', 'text' => $contactText]);
    }

    public function showSlipInfo($userId, $userDbId, $replyToken)
    {
        // หาออเดอร์ที่รอชำระเงิน
        try {
            $transTable = $this->getTransactionsTable();
            $stmt = $this->db->prepare("SELECT * FROM {$transTable} WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$userDbId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                // มีออเดอร์รอชำระ - ขอให้ส่งสลิป
                $this->setUserState($userDbId, 'awaiting_slip', ['order_id' => $order['id']]);

                $text = "💳 ส่งสลิปชำระเงิน\n\n";
                $text .= "📋 ออเดอร์: #{$order['order_number']}\n";
                $text .= "💰 ยอดชำระ: ฿" . number_format($order['grand_total'], 2) . "\n\n";
                $text .= "📸 กรุณาส่งรูปสลิปการโอนเงินมาเลยค่ะ";

                return $this->line->replyMessage($replyToken, ['type' => 'text', 'text' => $text]);
            }
        } catch (Exception $e) {
            // ตารางไม่มี
        }

        // ไม่มีออเดอร์รอชำระ
        $text = "📋 ยังไม่มีรายการที่รอชำระเงิน\n\n";
        $text .= "พิมพ์ 'shop' เพื่อดูสินค้า\n";
        $text .= "หรือพิมพ์ 'orders' เพื่อดูรายการของคุณ";

        return $this->line->replyMessage($replyToken, ['type' => 'text', 'text' => $text]);
    }
}
