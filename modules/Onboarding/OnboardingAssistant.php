<?php
/**
 * OnboardingAssistant - Main AI Onboarding Assistant Class
 */

namespace Modules\Onboarding;

require_once __DIR__ . '/SetupStatusChecker.php';
require_once __DIR__ . '/SystemKnowledgeBase.php';
require_once __DIR__ . '/OnboardingPromptBuilder.php';
require_once __DIR__ . '/QuickActionExecutor.php';

class OnboardingAssistant {
    
    private $db;
    private $lineAccountId;
    private $adminUserId;
    private $statusChecker;
    private $knowledgeBase;
    private $promptBuilder;
    private $actionExecutor;
    private $geminiApiKey;
    private $sessionId;
    
    public function __construct($db, $lineAccountId, $adminUserId) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->adminUserId = $adminUserId;
        
        $this->statusChecker = new SetupStatusChecker($db, $lineAccountId);
        $this->knowledgeBase = new SystemKnowledgeBase();
        $this->promptBuilder = new OnboardingPromptBuilder();
        $this->actionExecutor = new QuickActionExecutor($db, $lineAccountId);
        
        $this->loadGeminiApiKey();
        $this->loadOrCreateSession();
    }
    
    /**
     * Load Gemini API Key from settings
     */
    private function loadGeminiApiKey(): void {
        try {
            // Try line account specific key first
            $stmt = $this->db->prepare("
                SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!empty($result['gemini_api_key'])) {
                $this->geminiApiKey = $result['gemini_api_key'];
                return;
            }
            
            // Try global config
            if (defined('GEMINI_API_KEY')) {
                $this->geminiApiKey = GEMINI_API_KEY;
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
    }
    
    /**
     * Load or create session
     */
    private function loadOrCreateSession(): void {
        try {
            $stmt = $this->db->prepare("
                SELECT id, conversation_history, current_topic, business_type 
                FROM onboarding_sessions 
                WHERE line_account_id = ? AND admin_user_id = ?
                ORDER BY last_activity DESC
                LIMIT 1
            ");
            $stmt->execute([$this->lineAccountId, $this->adminUserId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($session) {
                $this->sessionId = $session['id'];
            } else {
                $this->createSession();
            }
        } catch (\Exception $e) {
            // Table might not exist yet
            $this->sessionId = null;
        }
    }
    
    /**
     * Create new session
     */
    private function createSession(): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO onboarding_sessions (line_account_id, admin_user_id, conversation_history, setup_progress)
                VALUES (?, ?, '[]', '{}')
            ");
            $stmt->execute([$this->lineAccountId, $this->adminUserId]);
            $this->sessionId = $this->db->lastInsertId();
        } catch (\Exception $e) {
            $this->sessionId = null;
        }
    }
    
    /**
     * Main chat interface
     */
    public function chat(string $message, array $context = []): array {
        // Get setup status
        $setupStatus = $this->statusChecker->checkAll();
        
        // Extract intent and get relevant knowledge
        $intent = $this->promptBuilder->extractIntent($message);
        $relevantKnowledge = $this->promptBuilder->getRelevantKnowledge($message);
        
        // Build prompts
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($setupStatus, $context);
        $userPrompt = $this->promptBuilder->buildUserPrompt($message, $relevantKnowledge);
        
        // Call Gemini AI
        $aiResponse = $this->callGeminiAI($systemPrompt, $userPrompt);
        
        // Get suggested actions
        $suggestedActions = $this->actionExecutor->getSuggestedActions($setupStatus);
        
        // Save to conversation history
        $this->saveConversation($message, $aiResponse);
        
        return [
            'success' => true,
            'message' => $aiResponse,
            'intent' => $intent,
            'suggested_actions' => $suggestedActions,
            'setup_status' => $setupStatus,
            'completion_percent' => $this->statusChecker->getCompletionPercentage()
        ];
    }
    
    /**
     * Call Gemini AI
     */
    private function callGeminiAI(string $systemPrompt, string $userPrompt): string {
        if (empty($this->geminiApiKey)) {
            return $this->getFallbackResponse($userPrompt);
        }
        
        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $this->geminiApiKey;
            
            $data = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $systemPrompt . "\n\n---\n\nUser: " . $userPrompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1024
                ]
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                return $result['candidates'][0]['content']['parts'][0]['text'] ?? $this->getFallbackResponse($userPrompt);
            }
            
            return $this->getFallbackResponse($userPrompt);
        } catch (\Exception $e) {
            return $this->getFallbackResponse($userPrompt);
        }
    }
    
    /**
     * Get fallback response when AI is not available
     */
    private function getFallbackResponse(string $message): string {
        $intent = $this->promptBuilder->extractIntent($message);
        $primaryIntent = $intent['primary_intent'];
        
        $responses = [
            'greeting' => "สวัสดีครับ! 👋 ผมคือ Kiro Assistant พร้อมช่วยคุณตั้งค่าและใช้งานระบบ LINE CRM ครับ\n\nถามผมได้เลยว่าต้องการทำอะไร หรือดูรายการตั้งค่าที่แนะนำได้ที่ Checklist ด้านข้างครับ",
            'help' => "ผมช่วยคุณได้หลายอย่างครับ:\n\n• ตั้งค่าการเชื่อมต่อ LINE\n• ตั้งค่าร้านค้าและสินค้า\n• ตั้งค่า LIFF Apps\n• สร้าง Rich Menu\n• ตั้งค่า Auto Reply\n• เปิดใช้ AI Chat\n\nบอกผมได้เลยว่าต้องการทำอะไรครับ",
            'setup_line' => "การเชื่อมต่อ LINE OA:\n\n1. ไปที่ LINE Developers Console\n2. คัดลอก Channel Access Token และ Channel Secret\n3. นำมาใส่ในหน้า LINE Accounts\n\n👉 [ไปตั้งค่า LINE Account](/line-accounts.php)",
            'setup_shop' => "การตั้งค่าร้านค้า:\n\n1. ไปที่ Shop Settings\n2. กรอกข้อมูลร้าน: ชื่อ, โลโก้, ข้อมูลติดต่อ\n3. ตั้งค่าการชำระเงินและจัดส่ง\n\n👉 [ไปตั้งค่าร้านค้า](/shop/liff-shop-settings.php)",
            'setup_liff' => "การตั้งค่า LIFF:\n\n1. ไปที่ LINE Developers Console\n2. สร้าง LIFF App ใหม่\n3. คัดลอก LIFF ID มาใส่ในระบบ\n\n👉 [ไปตั้งค่า LIFF](/liff-settings.php)",
            'feature_info' => "ฟีเจอร์หลักของระบบ:\n\n• **Inbox** - จัดการข้อความลูกค้า\n• **Shop** - ร้านค้าออนไลน์\n• **Broadcast** - ส่งข้อความหาลูกค้า\n• **Rich Menu** - เมนูลัดใน LINE\n• **Auto Reply** - ตอบกลับอัตโนมัติ\n• **AI Chat** - AI ตอบแชท\n• **Loyalty** - ระบบแต้มสะสม\n\nสนใจฟีเจอร์ไหนเป็นพิเศษครับ?",
            'status' => "ดูสถานะการตั้งค่าได้ที่ Checklist ด้านข้างครับ หรือกดปุ่ม 'ตรวจสอบสถานะระบบ' เพื่อ Health Check",
            'general' => "ผมเข้าใจครับ ถ้าต้องการความช่วยเหลือเพิ่มเติม ลองถามเรื่องที่ต้องการได้เลยครับ เช่น:\n\n• วิธีเชื่อมต่อ LINE\n• วิธีตั้งค่าร้านค้า\n• วิธีใช้ฟีเจอร์ต่างๆ\n\nหรือดู Checklist ด้านข้างเพื่อดูรายการที่ต้องตั้งค่าครับ"
        ];
        
        return $responses[$primaryIntent] ?? $responses['general'];
    }
    
    /**
     * Save conversation to history
     */
    private function saveConversation(string $userMessage, string $aiResponse): void {
        if (!$this->sessionId) return;
        
        try {
            $stmt = $this->db->prepare("
                SELECT conversation_history FROM onboarding_sessions WHERE id = ?
            ");
            $stmt->execute([$this->sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $history = json_decode($session['conversation_history'] ?? '[]', true);
            $history[] = [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $history[] = [
                'role' => 'assistant',
                'content' => $aiResponse,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // Keep only last 20 messages
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            
            $stmt = $this->db->prepare("
                UPDATE onboarding_sessions 
                SET conversation_history = ?, last_activity = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($history), $this->sessionId]);
        } catch (\Exception $e) {
            // Ignore errors
        }
    }
    
    /**
     * Get current setup status
     */
    public function getSetupStatus(): array {
        return $this->statusChecker->checkAll();
    }
    
    /**
     * Get setup checklist with progress
     */
    public function getChecklist(): array {
        $status = $this->statusChecker->checkAll();
        $completion = $this->statusChecker->getCompletionPercentage();
        $nextAction = $this->statusChecker->getNextRecommendedAction();
        
        return [
            'status' => $status,
            'completion_percent' => $completion,
            'next_action' => $nextAction,
            'checklist_definition' => SetupStatusChecker::SETUP_CHECKLIST
        ];
    }
    
    /**
     * Execute quick action
     */
    public function executeAction(string $action, array $params = []): array {
        return $this->actionExecutor->execute($action, $params);
    }
    
    /**
     * Run health check
     */
    public function runHealthCheck(): array {
        return $this->actionExecutor->execute('run_health_check');
    }
    
    /**
     * Get contextual suggestions
     */
    public function getSuggestions(string $currentPage = null): array {
        $setupStatus = $this->statusChecker->checkAll();
        $suggestions = $this->actionExecutor->getSuggestedActions($setupStatus);
        
        // Add page-specific suggestions
        if ($currentPage) {
            $pageSuggestions = $this->getPageSpecificSuggestions($currentPage);
            $suggestions = array_merge($pageSuggestions, $suggestions);
        }
        
        return array_slice($suggestions, 0, 5);
    }
    
    /**
     * Get page-specific suggestions
     */
    private function getPageSpecificSuggestions(string $currentPage): array {
        $suggestions = [];
        
        $pageMap = [
            'line-accounts' => [
                'tip' => 'ตรวจสอบว่า Channel Access Token และ Channel Secret ถูกต้อง',
                'action' => 'test_line_connection'
            ],
            'shop/products' => [
                'tip' => 'เพิ่มรูปภาพสินค้าที่สวยงามเพื่อดึงดูดลูกค้า',
                'action' => null
            ],
            'rich-menu' => [
                'tip' => 'ใช้รูปภาพขนาด 2500x1686 หรือ 2500x843 pixels',
                'action' => null
            ]
        ];
        
        if (isset($pageMap[$currentPage])) {
            $suggestions['page_tip'] = $pageMap[$currentPage];
        }
        
        return $suggestions;
    }
    
    /**
     * Get welcome message
     */
    public function getWelcomeMessage(string $userName = 'User'): string {
        $completion = $this->statusChecker->getCompletionPercentage();
        $nextAction = $this->statusChecker->getNextRecommendedAction();
        
        return $this->promptBuilder->buildWelcomeMessage($userName, $completion, $nextAction);
    }
    
    /**
     * Get conversation history
     */
    public function getConversationHistory(): array {
        if (!$this->sessionId) return [];
        
        try {
            $stmt = $this->db->prepare("
                SELECT conversation_history FROM onboarding_sessions WHERE id = ?
            ");
            $stmt->execute([$this->sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return json_decode($session['conversation_history'] ?? '[]', true);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Clear conversation history
     */
    public function clearHistory(): bool {
        if (!$this->sessionId) return false;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE onboarding_sessions 
                SET conversation_history = '[]'
                WHERE id = ?
            ");
            return $stmt->execute([$this->sessionId]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
