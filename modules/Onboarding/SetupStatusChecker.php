<?php
/**
 * SetupStatusChecker - ตรวจสอบสถานะการตั้งค่าระบบ
 */

namespace Modules\Onboarding;

class SetupStatusChecker {
    private $db;
    private $lineAccountId;
    
    // Setup Checklist Definition
    const SETUP_CHECKLIST = [
        'essential' => [
            'line_connection' => [
                'label' => 'เชื่อมต่อ LINE Official Account',
                'description' => 'เชื่อมต่อบัญชี LINE OA เพื่อรับ-ส่งข้อความ',
                'url' => '/line-accounts.php',
                'icon' => 'fab fa-line'
            ],
            'webhook' => [
                'label' => 'ตั้งค่า Webhook',
                'description' => 'ตั้งค่า Webhook URL ใน LINE Console',
                'url' => '/line-accounts.php',
                'icon' => 'fas fa-link'
            ],
            'shop_info' => [
                'label' => 'ข้อมูลร้านค้า',
                'description' => 'ตั้งค่าชื่อร้าน โลโก้ และข้อมูลติดต่อ',
                'url' => '/shop/liff-shop-settings.php',
                'icon' => 'fas fa-store'
            ],
            'products' => [
                'label' => 'เพิ่มสินค้า',
                'description' => 'เพิ่มสินค้าอย่างน้อย 1 รายการ',
                'url' => '/shop/products.php',
                'icon' => 'fas fa-box'
            ]
        ],
        'recommended' => [
            'liff_shop' => [
                'label' => 'ตั้งค่า LIFF Shop',
                'description' => 'เปิดใช้งานร้านค้าใน LINE',
                'url' => '/liff-settings.php',
                'icon' => 'fas fa-shopping-bag'
            ],
            'payment' => [
                'label' => 'ตั้งค่าการชำระเงิน',
                'description' => 'เพิ่มบัญชีธนาคารหรือ PromptPay',
                'url' => '/shop/liff-shop-settings.php',
                'icon' => 'fas fa-credit-card'
            ],
            'rich_menu' => [
                'label' => 'สร้าง Rich Menu',
                'description' => 'สร้างเมนูลัดสำหรับลูกค้า',
                'url' => '/rich-menu.php',
                'icon' => 'fas fa-th-large'
            ],
            'auto_reply' => [
                'label' => 'ตั้งค่าตอบอัตโนมัติ',
                'description' => 'ตั้งค่าข้อความตอบกลับอัตโนมัติ',
                'url' => '/auto-reply.php',
                'icon' => 'fas fa-robot'
            ]
        ],
        'advanced' => [
            'ai_chat' => [
                'label' => 'เปิดใช้ AI ตอบแชท',
                'description' => 'ใช้ AI ตอบคำถามลูกค้าอัตโนมัติ',
                'url' => '/ai-chat-settings.php',
                'icon' => 'fas fa-brain'
            ],
            'broadcast' => [
                'label' => 'ส่ง Broadcast แรก',
                'description' => 'ส่งข้อความถึงลูกค้าทั้งหมด',
                'url' => '/broadcast.php',
                'icon' => 'fas fa-bullhorn'
            ],
            'loyalty' => [
                'label' => 'จัดการรางวัลแลกแต้ม',
                'description' => 'เพิ่มรางวัลและจัดการระบบแต้มสะสม',
                'url' => '/loyalty-rewards.php',
                'icon' => 'fas fa-gift'
            ],
            'member_card' => [
                'label' => 'ตั้งค่าบัตรสมาชิก',
                'description' => 'สร้างบัตรสมาชิกดิจิทัล',
                'url' => '/members.php',
                'icon' => 'fas fa-id-card'
            ]
        ]
    ];
    
    public function __construct($db, $lineAccountId) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Check all setup items
     */
    public function checkAll(): array {
        $results = [];
        
        foreach (self::SETUP_CHECKLIST as $category => $items) {
            $results[$category] = [];
            foreach ($items as $key => $item) {
                $checkMethod = 'check' . str_replace('_', '', ucwords($key, '_'));
                $status = method_exists($this, $checkMethod) ? $this->$checkMethod() : $this->checkGeneric($key);
                
                $results[$category][$key] = array_merge($item, [
                    'key' => $key,
                    'status' => $status['completed'] ? 'completed' : 'pending',
                    'completed' => $status['completed'],
                    'details' => $status['details'] ?? null
                ]);
            }
        }
        
        return $results;
    }
    
    /**
     * Check LINE connection status
     */
    public function checkLineConnection(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT channel_access_token, channel_secret 
                FROM line_accounts 
                WHERE id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $hasToken = !empty($account['channel_access_token']);
            $hasSecret = !empty($account['channel_secret']);
            
            return [
                'completed' => $hasToken && $hasSecret,
                'details' => [
                    'has_token' => $hasToken,
                    'has_secret' => $hasSecret
                ]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Webhook status
     */
    public function checkWebhook(): array {
        try {
            // Check if webhook_verified column exists
            $stmt = $this->db->prepare("SHOW COLUMNS FROM line_accounts LIKE 'webhook_verified'");
            $stmt->execute();
            $columnExists = $stmt->rowCount() > 0;
            
            if ($columnExists) {
                $stmt = $this->db->prepare("SELECT webhook_verified FROM line_accounts WHERE id = ?");
                $stmt->execute([$this->lineAccountId]);
                $account = $stmt->fetch(\PDO::FETCH_ASSOC);
                return [
                    'completed' => !empty($account['webhook_verified']),
                    'details' => ['verified' => !empty($account['webhook_verified'])]
                ];
            }
            
            // If column doesn't exist, check if LINE connection works (has token)
            $lineCheck = $this->checkLineConnection();
            return [
                'completed' => $lineCheck['completed'],
                'details' => ['verified' => $lineCheck['completed']]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Shop info status
     */
    public function checkShopInfo(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT shop_name, shop_logo 
                FROM shop_settings 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $shop = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $hasName = !empty($shop['shop_name']);
            $hasLogo = !empty($shop['shop_logo']);
            
            return [
                'completed' => $hasName,
                'details' => [
                    'has_name' => $hasName,
                    'has_logo' => $hasLogo
                ]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Products status
     */
    public function checkProducts(): array {
        try {
            // Try business_items first (new system)
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM business_items 
                WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => ($result['count'] ?? 0) > 0,
                'details' => ['product_count' => $result['count'] ?? 0]
            ];
        } catch (\Exception $e) {
            // Fallback to products table (old system)
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count 
                    FROM products 
                    WHERE line_account_id = ? AND status = 'active'
                ");
                $stmt->execute([$this->lineAccountId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                return [
                    'completed' => ($result['count'] ?? 0) > 0,
                    'details' => ['product_count' => $result['count'] ?? 0]
                ];
            } catch (\Exception $e2) {
                return ['completed' => false, 'details' => ['error' => $e2->getMessage()]];
            }
        }
    }
    
    /**
     * Check LIFF Shop status
     */
    public function checkLiffShop(): array {
        try {
            // Check line_accounts table for liff_id (new system)
            $stmt = $this->db->prepare("
                SELECT liff_id 
                FROM line_accounts 
                WHERE id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!empty($account['liff_id'])) {
                return [
                    'completed' => true,
                    'details' => ['has_liff_shop' => true]
                ];
            }
            
            // Fallback to liff_settings table (old system)
            $stmt = $this->db->prepare("
                SELECT liff_shop_id 
                FROM liff_settings 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $liff = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => !empty($liff['liff_shop_id']),
                'details' => ['has_liff_shop' => !empty($liff['liff_shop_id'])]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Payment setup status
     */
    public function checkPayment(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT bank_accounts, promptpay_number 
                FROM shop_settings 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $shop = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $hasBankAccounts = !empty($shop['bank_accounts']);
            $hasPromptPay = !empty($shop['promptpay_number']);
            
            return [
                'completed' => $hasBankAccounts || $hasPromptPay,
                'details' => [
                    'has_bank_accounts' => $hasBankAccounts,
                    'has_promptpay' => $hasPromptPay
                ]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Rich Menu status
     */
    public function checkRichMenu(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM rich_menus 
                WHERE line_account_id = ? AND is_active = 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => ($result['count'] ?? 0) > 0,
                'details' => ['rich_menu_count' => $result['count'] ?? 0]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Auto Reply status
     */
    public function checkAutoReply(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM auto_replies 
                WHERE line_account_id = ? AND is_active = 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => ($result['count'] ?? 0) > 0,
                'details' => ['auto_reply_count' => $result['count'] ?? 0]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check AI Chat status
     */
    public function checkAiChat(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT is_enabled, gemini_api_key 
                FROM ai_settings 
                WHERE line_account_id = ? OR line_account_id IS NULL
                ORDER BY line_account_id DESC
                LIMIT 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $ai = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $enabled = !empty($ai['is_enabled']);
            $hasKey = !empty($ai['gemini_api_key']);
            
            return [
                'completed' => $enabled && $hasKey,
                'details' => [
                    'ai_enabled' => $enabled,
                    'has_api_key' => $hasKey
                ]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Broadcast status
     */
    public function checkBroadcast(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM broadcasts 
                WHERE line_account_id = ? AND status = 'sent'
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => ($result['count'] ?? 0) > 0,
                'details' => ['broadcast_count' => $result['count'] ?? 0]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    /**
     * Check Loyalty status
     */
    public function checkLoyalty(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT loyalty_enabled 
                FROM shop_settings 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $shop = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => !empty($shop['loyalty_enabled']),
                'details' => ['loyalty_enabled' => !empty($shop['loyalty_enabled'])]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Check Member Card status
     */
    public function checkMemberCard(): array {
        try {
            $stmt = $this->db->prepare("
                SELECT liff_member_id 
                FROM liff_settings 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $liff = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return [
                'completed' => !empty($liff['liff_member_id']),
                'details' => ['has_liff_member' => !empty($liff['liff_member_id'])]
            ];
        } catch (\Exception $e) {
            return ['completed' => false, 'details' => ['error' => $e->getMessage()]];
        }
    }
    
    /**
     * Generic check for items without specific method
     */
    private function checkGeneric($key): array {
        return ['completed' => false, 'details' => null];
    }
    
    /**
     * Get completion percentage
     */
    public function getCompletionPercentage(): int {
        $status = $this->checkAll();
        $total = 0;
        $completed = 0;
        
        foreach ($status as $category => $items) {
            foreach ($items as $item) {
                $total++;
                if ($item['completed']) {
                    $completed++;
                }
            }
        }
        
        return $total > 0 ? round(($completed / $total) * 100) : 0;
    }
    
    /**
     * Get next recommended action
     */
    public function getNextRecommendedAction(): ?array {
        $status = $this->checkAll();
        
        // Priority: essential > recommended > advanced
        foreach (['essential', 'recommended', 'advanced'] as $category) {
            foreach ($status[$category] as $key => $item) {
                if (!$item['completed']) {
                    return [
                        'key' => $key,
                        'category' => $category,
                        'label' => $item['label'],
                        'description' => $item['description'],
                        'url' => $item['url'],
                        'icon' => $item['icon']
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get checklist for display
     */
    public function getChecklist(): array {
        return self::SETUP_CHECKLIST;
    }
}
