<?php
/**
 * QuickActionExecutor - จัดการ Quick Actions
 */

namespace Modules\Onboarding;

class QuickActionExecutor {
    
    private $db;
    private $lineAccountId;
    
    // Available Quick Actions
    const ACTIONS = [
        'go_line_accounts' => [
            'label' => 'ไปตั้งค่า LINE Account',
            'type' => 'navigate',
            'url' => '/line-accounts.php',
            'icon' => 'fab fa-line'
        ],
        'go_shop_settings' => [
            'label' => 'ไปตั้งค่าร้านค้า',
            'type' => 'navigate',
            'url' => '/shop/liff-shop-settings.php',
            'icon' => 'fas fa-store'
        ],
        'go_products' => [
            'label' => 'ไปจัดการสินค้า',
            'type' => 'navigate',
            'url' => '/shop/products.php',
            'icon' => 'fas fa-box'
        ],
        'go_liff_settings' => [
            'label' => 'ไปตั้งค่า LIFF',
            'type' => 'navigate',
            'url' => '/liff-settings.php',
            'icon' => 'fas fa-mobile-alt'
        ],
        'go_rich_menu' => [
            'label' => 'ไปสร้าง Rich Menu',
            'type' => 'navigate',
            'url' => '/rich-menu.php',
            'icon' => 'fas fa-th-large'
        ],
        'go_auto_reply' => [
            'label' => 'ไปตั้งค่า Auto Reply',
            'type' => 'navigate',
            'url' => '/auto-reply.php',
            'icon' => 'fas fa-robot'
        ],
        'go_ai_settings' => [
            'label' => 'ไปตั้งค่า AI',
            'type' => 'navigate',
            'url' => '/ai-settings.php',
            'icon' => 'fas fa-brain'
        ],
        'go_broadcast' => [
            'label' => 'ไปส่ง Broadcast',
            'type' => 'navigate',
            'url' => '/broadcast.php',
            'icon' => 'fas fa-bullhorn'
        ],
        'go_loyalty' => [
            'label' => 'ไปตั้งค่าแต้มสะสม',
            'type' => 'navigate',
            'url' => '/loyalty-points.php',
            'icon' => 'fas fa-coins'
        ],
        'go_inbox' => [
            'label' => 'ไปดูข้อความ',
            'type' => 'navigate',
            'url' => '/inbox.php',
            'icon' => 'fas fa-inbox'
        ],
        'go_analytics' => [
            'label' => 'ไปดูสถิติ',
            'type' => 'navigate',
            'url' => '/analytics.php',
            'icon' => 'fas fa-chart-line'
        ],
        'test_line_connection' => [
            'label' => 'ทดสอบการเชื่อมต่อ LINE',
            'type' => 'action',
            'icon' => 'fas fa-plug'
        ],
        'run_health_check' => [
            'label' => 'ตรวจสอบสถานะระบบ',
            'type' => 'action',
            'icon' => 'fas fa-heartbeat'
        ],
        'refresh_status' => [
            'label' => 'รีเฟรชสถานะ',
            'type' => 'action',
            'icon' => 'fas fa-sync'
        ]
    ];
    
    public function __construct($db = null, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get available actions
     */
    public function getAvailableActions(): array {
        return self::ACTIONS;
    }
    
    /**
     * Get actions by type
     */
    public function getActionsByType(string $type): array {
        return array_filter(self::ACTIONS, function($action) use ($type) {
            return $action['type'] === $type;
        });
    }
    
    /**
     * Validate action
     */
    public function validateAction(string $action): bool {
        return isset(self::ACTIONS[$action]);
    }
    
    /**
     * Execute action
     */
    public function execute(string $action, array $params = []): array {
        if (!$this->validateAction($action)) {
            return [
                'success' => false,
                'error' => 'Invalid action: ' . $action
            ];
        }
        
        $actionConfig = self::ACTIONS[$action];
        
        if ($actionConfig['type'] === 'navigate') {
            return [
                'success' => true,
                'type' => 'navigate',
                'url' => $actionConfig['url'],
                'label' => $actionConfig['label']
            ];
        }
        
        // Execute action methods
        $method = 'execute' . str_replace('_', '', ucwords($action, '_'));
        if (method_exists($this, $method)) {
            return $this->$method($params);
        }
        
        return [
            'success' => false,
            'error' => 'Action not implemented: ' . $action
        ];
    }
    
    /**
     * Test LINE connection
     */
    private function executeTestLineConnection(array $params = []): array {
        if (!$this->db || !$this->lineAccountId) {
            return [
                'success' => false,
                'error' => 'Database or LINE Account ID not set'
            ];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT channel_access_token 
                FROM line_accounts 
                WHERE id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (empty($account['channel_access_token'])) {
                return [
                    'success' => false,
                    'type' => 'result',
                    'message' => '❌ ยังไม่ได้ตั้งค่า Channel Access Token',
                    'suggestion' => 'กรุณาไปตั้งค่า LINE Account ก่อน',
                    'action' => 'go_line_accounts'
                ];
            }
            
            // Test LINE API
            $ch = curl_init('https://api.line.me/v2/bot/info');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $account['channel_access_token']
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $botInfo = json_decode($response, true);
                return [
                    'success' => true,
                    'type' => 'result',
                    'message' => '✅ เชื่อมต่อ LINE สำเร็จ!',
                    'details' => [
                        'bot_name' => $botInfo['displayName'] ?? 'Unknown',
                        'bot_id' => $botInfo['userId'] ?? 'Unknown'
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'type' => 'result',
                    'message' => '❌ ไม่สามารถเชื่อมต่อ LINE ได้',
                    'error' => 'HTTP ' . $httpCode,
                    'suggestion' => 'ตรวจสอบ Channel Access Token อีกครั้ง'
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'type' => 'result',
                'message' => '❌ เกิดข้อผิดพลาด',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Run health check
     */
    private function executeRunHealthCheck(array $params = []): array {
        $checks = [];
        
        // Database check
        try {
            if ($this->db) {
                $this->db->query("SELECT 1");
                $checks['database'] = ['status' => 'ok', 'message' => 'Database เชื่อมต่อปกติ'];
            } else {
                $checks['database'] = ['status' => 'error', 'message' => 'ไม่ได้เชื่อมต่อ Database'];
            }
        } catch (\Exception $e) {
            $checks['database'] = ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        }
        
        // LINE API check
        if ($this->lineAccountId) {
            $lineResult = $this->executeTestLineConnection();
            $checks['line_api'] = [
                'status' => $lineResult['success'] ? 'ok' : 'error',
                'message' => $lineResult['message'] ?? 'Unknown'
            ];
        } else {
            $checks['line_api'] = ['status' => 'warning', 'message' => 'ยังไม่ได้เลือก LINE Account'];
        }
        
        // PHP version check
        $phpVersion = phpversion();
        $checks['php'] = [
            'status' => version_compare($phpVersion, '7.4', '>=') ? 'ok' : 'warning',
            'message' => 'PHP ' . $phpVersion
        ];
        
        // Extensions check
        $requiredExtensions = ['curl', 'json', 'pdo', 'pdo_mysql', 'mbstring'];
        $missingExtensions = [];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missingExtensions[] = $ext;
            }
        }
        $checks['extensions'] = [
            'status' => empty($missingExtensions) ? 'ok' : 'error',
            'message' => empty($missingExtensions) ? 'Extensions ครบถ้วน' : 'ขาด: ' . implode(', ', $missingExtensions)
        ];
        
        // Overall status
        $hasError = false;
        $hasWarning = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'error') $hasError = true;
            if ($check['status'] === 'warning') $hasWarning = true;
        }
        
        return [
            'success' => !$hasError,
            'type' => 'health_check',
            'overall' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok'),
            'checks' => $checks,
            'message' => $hasError ? '❌ พบปัญหาบางอย่าง' : ($hasWarning ? '⚠️ มีคำเตือนบางอย่าง' : '✅ ระบบทำงานปกติ')
        ];
    }
    
    /**
     * Refresh status
     */
    private function executeRefreshStatus(array $params = []): array {
        return [
            'success' => true,
            'type' => 'refresh',
            'message' => '🔄 รีเฟรชสถานะเรียบร้อย'
        ];
    }
    
    /**
     * Get suggested actions based on setup status
     */
    public function getSuggestedActions(array $setupStatus): array {
        $suggestions = [];
        
        foreach ($setupStatus as $category => $items) {
            foreach ($items as $key => $item) {
                if (!($item['completed'] ?? false)) {
                    $actionKey = 'go_' . str_replace('_', '_', $key);
                    
                    // Map item keys to action keys
                    $actionMap = [
                        'line_connection' => 'go_line_accounts',
                        'webhook' => 'go_line_accounts',
                        'shop_info' => 'go_shop_settings',
                        'products' => 'go_products',
                        'liff_shop' => 'go_liff_settings',
                        'payment' => 'go_shop_settings',
                        'rich_menu' => 'go_rich_menu',
                        'auto_reply' => 'go_auto_reply',
                        'ai_chat' => 'go_ai_settings',
                        'broadcast' => 'go_broadcast',
                        'loyalty' => 'go_loyalty',
                        'member_card' => 'go_liff_settings'
                    ];
                    
                    $mappedAction = $actionMap[$key] ?? null;
                    if ($mappedAction && isset(self::ACTIONS[$mappedAction])) {
                        $suggestions[$mappedAction] = array_merge(
                            self::ACTIONS[$mappedAction],
                            ['reason' => $item['label'] . ' ยังไม่ได้ตั้งค่า']
                        );
                    }
                }
            }
        }
        
        return array_slice($suggestions, 0, 5); // Return max 5 suggestions
    }
}
