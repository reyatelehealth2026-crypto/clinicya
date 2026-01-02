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
        $aiResult = $this->callGeminiAI($systemPrompt, $userPrompt);
        
        // Get suggested actions
        $suggestedActions = $this->actionExecutor->getSuggestedActions($setupStatus);
        
        // Save to conversation history
        $this->saveConversation($message, $aiResult['message']);
        
        return [
            'success' => true,
            'message' => $aiResult['message'],
            'ai_source' => $aiResult['source'], // 'gemini' or 'fallback'
            'intent' => $intent,
            'suggested_actions' => $suggestedActions,
            'setup_status' => $setupStatus,
            'completion_percent' => $this->statusChecker->getCompletionPercentage()
        ];
    }
    
    /**
     * Call Gemini AI
     */
    private function callGeminiAI(string $systemPrompt, string $userPrompt): array {
        if (empty($this->geminiApiKey)) {
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        }
        
        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $this->geminiApiKey;
            
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
                $aiMessage = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                
                if ($aiMessage) {
                    return [
                        'message' => $aiMessage,
                        'source' => 'gemini'
                    ];
                }
            }
            
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        } catch (\Exception $e) {
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        }
    }
    
    /**
     * Get fallback response when AI is not available
     */
    private function getFallbackResponse(string $message): string {
        $message = mb_strtolower($message);
        
        // Check for specific topics in message - SHORT responses with links
        $topicResponses = [
            'member_card' => "**บัตรสมาชิกดิจิทัล** 🎫\n\nตั้งค่าบัตรสมาชิกและ LIFF ได้ที่หน้าตั้งค่าครับ\n\n👉 [ตั้งค่า LIFF](/liff-settings.php)\n👉 [จัดการสมาชิก](/members.php)",
            
            'shop_info' => "**ข้อมูลร้านค้า** 🏪\n\nตั้งค่าข้อมูลร้าน ค่าส่ง บัญชีธนาคาร ได้ที่หน้าตั้งค่าร้านค้าครับ\n\n👉 [ตั้งค่าร้านค้า](/shop/settings.php)\n👉 [ตั้งค่า LIFF Shop](/shop/liff-shop-settings.php)",
            
            'products' => "**จัดการสินค้า** 📦\n\nเพิ่ม แก้ไข ลบสินค้าได้ที่หน้าสินค้าครับ\n\n👉 [จัดการสินค้า](/shop/products.php)\n👉 [หมวดหมู่](/shop/categories.php)",
            
            'webhook' => "**Webhook** 🔗\n\nตั้งค่า Webhook URL ได้ที่หน้า LINE Account ครับ\n\n👉 [ตั้งค่า LINE Account](/line-accounts.php)",
            
            'liff_shop' => "**LIFF Shop** 🛒\n\nตั้งค่า LIFF ID สำหรับร้านค้าออนไลน์ได้ที่หน้า LIFF Settings ครับ\n\n👉 [ตั้งค่า LIFF](/liff-settings.php)",
            
            'payment' => "**การชำระเงิน** 💳\n\nตั้งค่าบัญชีธนาคาร PromptPay ได้ที่หน้าตั้งค่าร้านค้าครับ\n\n👉 [ตั้งค่าร้านค้า](/shop/settings.php)",
            
            'rich_menu' => "**Rich Menu** 📱\n\nสร้างและจัดการ Rich Menu ได้ที่หน้า Rich Menu ครับ\n\n👉 [จัดการ Rich Menu](/rich-menu.php)\n👉 [Dynamic Rich Menu](/dynamic-rich-menu.php)",
            
            'auto_reply' => "**ตอบกลับอัตโนมัติ** 🤖\n\nตั้งค่า Keyword และข้อความตอบกลับได้ที่หน้า Auto Reply ครับ\n\n👉 [ตั้งค่า Auto Reply](/auto-reply.php)",
            
            'ai_chat' => "**AI Chat** 🧠\n\nตั้งค่า API Key และเปิดใช้ AI ได้ที่หน้า AI Settings ครับ\n\n👉 [ตั้งค่า AI](/ai-settings.php)\n👉 [AI Chat Settings](/ai-chat-settings.php)",
            
            'broadcast' => "**Broadcast** 📢\n\nส่งข้อความหาลูกค้าได้ที่หน้า Broadcast ครับ\n\n👉 [ส่ง Broadcast](/broadcast.php)\n👉 [ดูสถิติ](/broadcast-stats.php)",
            
            'loyalty' => "**ระบบแต้มสะสม** 🪙\n\nจัดการรางวัลและตั้งค่าระบบแต้มได้ที่หน้ารางวัลแลกแต้มครับ\n\n👉 [จัดการรางวัล](/admin-rewards.php)\n👉 [ตั้งค่าแต้ม](/admin-points-settings.php)",
            
            'line_connection' => "**เชื่อมต่อ LINE OA** 💚\n\nใส่ Channel Access Token และ Channel Secret ได้ที่หน้า LINE Account ครับ\n\n👉 [ตั้งค่า LINE Account](/line-accounts.php)",
            
            // === Advanced Marketing Features ===
            
            'drip_campaign' => "**Drip Campaign** 💧\n\nสร้างแคมเปญส่งข้อความอัตโนมัติได้ที่หน้า Drip Campaigns ครับ\n\n👉 [สร้าง Drip Campaign](/drip-campaigns.php)",
            
            'user_tags' => "**แท็กลูกค้า** 🏷️\n\nจัดการ Tags และตั้งกฎ Auto Tag ได้ที่หน้า Tags ครับ\n\n👉 [จัดการ Tags](/user-tags.php)\n👉 [Auto Tag Rules](/auto-tag-rules.php)",
            
            'scheduled_broadcast' => "**ตั้งเวลาส่ง** ⏰\n\nตั้งเวลาส่ง Broadcast ล่วงหน้าได้ที่หน้า Broadcast ครับ\n\n👉 [ตั้งเวลา Broadcast](/broadcast.php)\n👉 [ดูรายการตั้งเวลา](/scheduled.php)",
            
            'customer_segments' => "**กลุ่มลูกค้า** 👥\n\nสร้างกลุ่มลูกค้าตามเงื่อนไขได้ที่หน้า Segments ครับ\n\n👉 [จัดการ Segments](/customer-segments.php)",
            
            'link_tracking' => "**ติดตามลิงก์** 🔗\n\nสร้าง Tracking Link และดูสถิติคลิกได้ที่หน้า Link Tracking ครับ\n\n👉 [Link Tracking](/link-tracking.php)",
            
            'broadcast_analytics' => "**Broadcast Analytics (วิเคราะห์ผล)** 📊\n\nดูผลลัพธ์การส่ง Broadcast\n\n**Metrics สำคัญ:**\n• **Sent** - ส่งสำเร็จกี่คน\n• **Delivered** - ส่งถึงกี่คน\n• **Read** - อ่านกี่คน (Open Rate)\n• **Clicked** - คลิกลิงก์กี่คน (CTR)\n\n**วิธีดู:**\n1. ไปที่ Broadcast Stats\n2. เลือก Broadcast ที่ต้องการ\n3. ดูรายละเอียด\n\n**Tips:**\n• Open Rate ดี = 60%+\n• CTR ดี = 5%+\n• ทดสอบ A/B เพื่อปรับปรุง\n\n👉 [ดู Broadcast Stats](/broadcast-stats.php)",
            
            'flex_builder' => "**Flex Message Builder** 🎨\n\nสร้างข้อความ Flex สวยๆ แบบ Drag & Drop\n\n**ประเภท Flex:**\n• **Bubble** - การ์ดเดี่ยว\n• **Carousel** - การ์ดหลายใบเลื่อนได้\n\n**วิธีใช้:**\n1. ไปที่ Flex Builder\n2. เลือก Template หรือสร้างใหม่\n3. ลาก Components มาวาง\n4. ปรับแต่งสี ขนาด ข้อความ\n5. Preview และบันทึก\n\n**ใช้งาน:**\n• ใช้ใน Broadcast\n• ใช้ใน Auto Reply\n• ใช้ใน Drip Campaign\n\n👉 [ไป Flex Builder](/flex-builder.php)",
            
            'scheduled_reports' => "**Scheduled Reports (รายงานอัตโนมัติ)** 📈\n\nตั้งเวลาส่งรายงานอัตโนมัติ\n\n**ประเภทรายงาน:**\n• Daily Summary - สรุปรายวัน\n• Weekly Report - สรุปรายสัปดาห์\n• Monthly Report - สรุปรายเดือน\n\n**ข้อมูลในรายงาน:**\n• ยอดขาย\n• จำนวนออเดอร์\n• สมาชิกใหม่\n• ข้อความที่ได้รับ\n\n**วิธีตั้งค่า:**\n1. ไปที่ Scheduled Reports\n2. เลือกประเภทรายงาน\n3. ตั้งเวลาส่ง\n4. เลือกช่องทาง (Email/LINE)\n5. เปิดใช้งาน\n\n👉 [ตั้งค่า Reports](/scheduled-reports.php)",
            
            'promotions' => "**โปรโมชั่นและคูปอง** 🎁\n\nสร้างโปรโมชั่นดึงดูดลูกค้า\n\n**ประเภทโปรโมชั่น:**\n• **ส่วนลดเปอร์เซ็นต์** - ลด 10%, 20%\n• **ส่วนลดบาท** - ลด 100, 200 บาท\n• **ส่งฟรี** - ฟรีค่าส่ง\n• **ซื้อ X แถม Y** - ซื้อ 2 แถม 1\n\n**วิธีสร้าง:**\n1. ไปที่ Promotions\n2. กด 'สร้างโปรโมชั่น'\n3. เลือกประเภท\n4. ตั้งเงื่อนไข (ขั้นต่ำ, วันหมดอายุ)\n5. สร้างโค้ดคูปอง\n6. เปิดใช้งาน\n\n👉 [จัดการโปรโมชั่น](/shop/promotions.php)",
            
            'broadcast_analytics' => "**สถิติ Broadcast** 📊\n\nดูผลลัพธ์การส่ง Broadcast ได้ที่หน้า Broadcast Stats ครับ\n\n👉 [ดูสถิติ Broadcast](/broadcast-stats.php)",
            
            'flex_builder' => "**Flex Builder** 🎨\n\nสร้างข้อความ Flex สวยๆ ได้ที่หน้า Flex Builder ครับ\n\n👉 [Flex Builder](/flex-builder.php)",
            
            'scheduled_reports' => "**รายงานอัตโนมัติ** 📈\n\nตั้งเวลาส่งรายงานอัตโนมัติได้ที่หน้า Scheduled Reports ครับ\n\n👉 [ตั้งค่ารายงาน](/scheduled-reports.php)",
            
            'promotions' => "**โปรโมชั่น** 🎁\n\nสร้างโปรโมชั่นและคูปองได้ที่หน้า Promotions ครับ\n\n👉 [จัดการโปรโมชั่น](/shop/promotions.php)",
            
            'crm_analytics' => "**CRM Analytics** 📊\n\nดูสถิติและวิเคราะห์ข้อมูลลูกค้าได้ที่หน้า Analytics ครับ\n\n👉 [CRM Analytics](/analytics.php?tab=crm)\n👉 [Executive Dashboard](/dashboard.php?tab=executive)",
            
            'bug_report' => "**รายงานปัญหา** 🐛\n\nกรุณาบอกรายละเอียด: หน้าที่เกิดปัญหา, อาการ, Error message (ถ้ามี) ครับ"
        ];
        
        // Check for bug report keywords
        if (mb_strpos($message, 'บัค') !== false || mb_strpos($message, 'bug') !== false || 
            mb_strpos($message, 'error') !== false || mb_strpos($message, 'ผิดพลาด') !== false ||
            mb_strpos($message, '500') !== false || mb_strpos($message, 'ไม่ทำงาน') !== false ||
            mb_strpos($message, 'พัง') !== false || mb_strpos($message, 'ปัญหา') !== false ||
            mb_strpos($message, 'หน้าว่าง') !== false || mb_strpos($message, 'ไม่แสดง') !== false) {
            return $this->analyzeBugReport($message);
        }
        
        // Check each topic
        foreach ($topicResponses as $topic => $response) {
            $keywords = explode('_', $topic);
            foreach ($keywords as $keyword) {
                if (mb_strpos($message, $keyword) !== false) {
                    return $response;
                }
            }
        }
        
        // Additional keyword checks
        if (mb_strpos($message, 'สมาชิก') !== false || mb_strpos($message, 'member') !== false) {
            return $topicResponses['member_card'];
        }
        if (mb_strpos($message, 'ร้าน') !== false || mb_strpos($message, 'shop') !== false) {
            return $topicResponses['shop_info'];
        }
        if (mb_strpos($message, 'สินค้า') !== false || mb_strpos($message, 'product') !== false) {
            return $topicResponses['products'];
        }
        if (mb_strpos($message, 'แต้ม') !== false || mb_strpos($message, 'point') !== false) {
            return $topicResponses['loyalty'];
        }
        if (mb_strpos($message, 'เมนู') !== false || mb_strpos($message, 'menu') !== false) {
            return $topicResponses['rich_menu'];
        }
        if (mb_strpos($message, 'ตอบ') !== false || mb_strpos($message, 'reply') !== false) {
            return $topicResponses['auto_reply'];
        }
        if (mb_strpos($message, 'ai') !== false || mb_strpos($message, 'เอไอ') !== false) {
            return $topicResponses['ai_chat'];
        }
        if (mb_strpos($message, 'จ่าย') !== false || mb_strpos($message, 'ชำระ') !== false) {
            return $topicResponses['payment'];
        }
        
        // Advanced Marketing keyword checks
        if (mb_strpos($message, 'drip') !== false || mb_strpos($message, 'ดริป') !== false || 
            mb_strpos($message, 'แคมเปญ') !== false || mb_strpos($message, 'campaign') !== false ||
            mb_strpos($message, 'อัตโนมัติ') !== false || mb_strpos($message, 'automation') !== false) {
            return $topicResponses['drip_campaign'];
        }
        if (mb_strpos($message, 'tag') !== false || mb_strpos($message, 'แท็ก') !== false || 
            mb_strpos($message, 'ติดแท็ก') !== false || mb_strpos($message, 'จัดกลุ่ม') !== false) {
            return $topicResponses['user_tags'];
        }
        if (mb_strpos($message, 'ตั้งเวลา') !== false || mb_strpos($message, 'schedule') !== false ||
            mb_strpos($message, 'ล่วงหน้า') !== false) {
            return $topicResponses['scheduled_broadcast'];
        }
        if (mb_strpos($message, 'segment') !== false || mb_strpos($message, 'เซกเมนต์') !== false ||
            mb_strpos($message, 'กลุ่มลูกค้า') !== false) {
            return $topicResponses['customer_segments'];
        }
        if (mb_strpos($message, 'tracking') !== false || mb_strpos($message, 'ติดตาม') !== false ||
            mb_strpos($message, 'วัดผล') !== false || mb_strpos($message, 'คลิก') !== false) {
            return $topicResponses['link_tracking'];
        }
        if (mb_strpos($message, 'analytics') !== false || mb_strpos($message, 'วิเคราะห์') !== false ||
            mb_strpos($message, 'สถิติ') !== false || mb_strpos($message, 'stats') !== false) {
            return $topicResponses['broadcast_analytics'];
        }
        if (mb_strpos($message, 'flex') !== false || mb_strpos($message, 'builder') !== false ||
            mb_strpos($message, 'สร้างข้อความ') !== false || mb_strpos($message, 'การ์ด') !== false) {
            return $topicResponses['flex_builder'];
        }
        if (mb_strpos($message, 'report') !== false || mb_strpos($message, 'รายงาน') !== false) {
            return $topicResponses['scheduled_reports'];
        }
        if (mb_strpos($message, 'โปรโมชั่น') !== false || mb_strpos($message, 'promotion') !== false ||
            mb_strpos($message, 'คูปอง') !== false || mb_strpos($message, 'coupon') !== false ||
            mb_strpos($message, 'ส่วนลด') !== false || mb_strpos($message, 'discount') !== false) {
            return $topicResponses['promotions'];
        }
        if (mb_strpos($message, 'crm') !== false || mb_strpos($message, 'dashboard') !== false ||
            mb_strpos($message, 'executive') !== false) {
            return $topicResponses['crm_analytics'];
        }
        
        // Check for casual/unclear messages - provide helpful menu with links
        $casualKeywords = ['โหลด', 'ลอง', 'ทดสอบ', 'test', 'ดู', 'อะไร', 'ยังไง', 'อย่างไร', 'ทำไง', 'ช่วย', 'help'];
        foreach ($casualKeywords as $keyword) {
            if (mb_strpos($message, $keyword) !== false) {
                return "ผมช่วยคุณได้ครับ! 🚀 บอกได้เลยว่าต้องการทำอะไร\n\n**หน้าหลักๆ:**\n👉 [ตั้งค่า LINE](/line-accounts.php)\n👉 [ตั้งค่าร้านค้า](/shop/settings.php)\n👉 [จัดการสินค้า](/shop/products.php)\n👉 [Rich Menu](/rich-menu.php)\n👉 [Broadcast](/broadcast.php)\n👉 [AI Settings](/ai-settings.php)";
            }
        }
        
        // Use intent-based fallback
        $intent = $this->promptBuilder->extractIntent($message);
        $primaryIntent = $intent['primary_intent'];
        
        $responses = [
            'greeting' => "สวัสดีครับ! 👋 ผมพร้อมช่วยคุณตั้งค่าระบบครับ บอกได้เลยว่าต้องการทำอะไร หรือดู Checklist ด้านข้างได้เลย",
            'help' => "ผมช่วยคุณได้ครับ! บอกได้เลยว่าต้องการตั้งค่าอะไร\n\n👉 [LINE Account](/line-accounts.php)\n👉 [ร้านค้า](/shop/settings.php)\n👉 [สินค้า](/shop/products.php)\n👉 [Rich Menu](/rich-menu.php)\n👉 [AI](/ai-settings.php)",
            'feature_info' => "ฟีเจอร์หลัก: Inbox, Shop, Broadcast, Rich Menu, Auto Reply, AI Chat, Loyalty\n\nบอกได้เลยว่าสนใจฟีเจอร์ไหนครับ",
            'status' => "ดูสถานะการตั้งค่าได้ที่ Checklist ด้านข้างครับ 👉",
            'general' => "ผมช่วยคุณได้ครับ! 🚀 บอกได้เลยว่าต้องการทำอะไร\n\n**หน้าหลักๆ:**\n👉 [ตั้งค่า LINE](/line-accounts.php)\n👉 [ตั้งค่าร้านค้า](/shop/settings.php)\n👉 [จัดการสินค้า](/shop/products.php)\n👉 [Rich Menu](/rich-menu.php)\n👉 [Broadcast](/broadcast.php)"
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
     * Check if Gemini AI is available
     */
    public function isAiAvailable(): bool {
        return !empty($this->geminiApiKey);
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
