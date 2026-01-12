<?php
/**
 * PharmacyAI Adapter v4.0
 * AI เภสัชกรออนไลน์ - แนะนำยาแบบละเอียด (สรรพคุณ, ตัวยา, ข้อบ่งใช้)
 * 
 * ใช้ Gemini API + RAG + Function Calling
 */

namespace Modules\AIChat\Adapters;

require_once __DIR__ . '/../Autoloader.php';
loadAIChatModule();

use Modules\AIChat\Services\RedFlagDetector;
use Modules\AIChat\Services\PharmacyRAG;
use Modules\AIChat\Templates\ProductFlexTemplates;
use Modules\Core\Database;

class PharmacyAIAdapter
{
    private $db;
    private ?int $lineAccountId;
    private ?int $userId = null;
    private ?string $apiKey = null;
    private string $model = 'gemini-2.0-flash';
    private RedFlagDetector $redFlagDetector;
    private PharmacyRAG $rag;
    private ProductFlexTemplates $flexTemplates;
    private array $lastFoundProducts = [];
    private array $conversationHistory = [];
    private ?string $sessionId = null;
    private ?string $triageState = null;
    
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct($db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->redFlagDetector = new RedFlagDetector();
        $this->rag = new PharmacyRAG($db, $lineAccountId);
        $this->flexTemplates = new ProductFlexTemplates();
        $this->loadApiKey();
    }
    
    private function loadApiKey(): void
    {
        if ($this->lineAccountId) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT gemini_api_key FROM ai_chat_settings WHERE line_account_id = ? AND is_enabled = 1"
                );
                $stmt->execute([$this->lineAccountId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && !empty($row['gemini_api_key'])) {
                    $this->apiKey = $row['gemini_api_key'];
                    return;
                }
            } catch (\Exception $e) {
                error_log("loadApiKey error: " . $e->getMessage());
            }
        }
        
        try {
            $stmt = $this->db->prepare(
                "SELECT gemini_api_key FROM ai_chat_settings WHERE is_enabled = 1 AND gemini_api_key IS NOT NULL LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['gemini_api_key'])) {
                $this->apiKey = $row['gemini_api_key'];
                return;
            }
        } catch (\Exception $e) {}
        
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            $this->apiKey = GEMINI_API_KEY;
        }
    }
    
    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }
    
    /**
     * Set conversation history for context
     */
    public function setConversationHistory(array $history): self
    {
        $this->conversationHistory = $history;
        return $this;
    }
    
    /**
     * Set session ID for state tracking
     */
    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }
    
    /**
     * Set current triage state
     */
    public function setTriageState(string $state): self
    {
        $this->triageState = $state;
        return $this;
    }
    
    /**
     * Get current triage state
     */
    public function getTriageState(): ?string
    {
        return $this->triageState;
    }
    
    /**
     * Check if session is active (not greeting state)
     */
    public function isSessionActive(): bool
    {
        return $this->triageState !== null && 
               $this->triageState !== 'greeting' && 
               $this->triageState !== 'complete' && 
               $this->triageState !== 'escalate';
    }

    public function isEnabled(): bool
    {
        return !empty($this->apiKey);
    }
    
    public function getModel(): string
    {
        return $this->model;
    }
    
    /**
     * ประมวลผลข้อความ - Entry Point หลัก
     */
    public function processMessage(string $message): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'AI is not enabled'];
        }
        
        // ===== ใช้ Gemini AI สำหรับทุกข้อความ (มี conversation history) =====
        $redFlags = $this->redFlagDetector->detect($message);
        
        if ($this->redFlagDetector->isCritical($redFlags)) {
            return $this->handleCriticalRedFlag($redFlags);
        }
        
        return $this->processWithGemini($message, $redFlags);
    }
    
    /**
     * ตรวจสอบว่ามี active triage session หรือไม่
     */
    private function hasActiveTriageSession(): bool
    {
        if (!$this->userId) return false;
        
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM triage_sessions WHERE user_id = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$this->userId]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * ประมวลผลด้วย TriageEngine (ซักประวัติเป็นขั้นตอน)
     */
    private function processWithTriage(string $message): array
    {
        try {
            require_once __DIR__ . '/../Services/TriageEngine.php';
            
            $triage = new \Modules\AIChat\Services\TriageEngine($this->lineAccountId, $this->userId);
            $result = $triage->process($message);
            
            $responseText = $result['text'] ?? $result['message'] ?? 'ขออภัยค่ะ เกิดข้อผิดพลาด';
            
            // ดึง sender settings จาก ai_settings
            $senderName = '🤖 AI Assistant';
            $senderIcon = 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png';
            
            try {
                $stmt = $this->db->prepare("SELECT sender_name, sender_icon, ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$this->lineAccountId]);
                $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($settings) {
                    if (!empty($settings['sender_name'])) {
                        $senderName = $settings['sender_name'];
                    } else {
                        $mode = $settings['ai_mode'] ?? 'sales';
                        switch ($mode) {
                            case 'pharmacist':
                            case 'pharmacy':
                                $senderName = '💊 เภสัชกร AI';
                                break;
                            case 'support':
                                $senderName = '💬 ซัพพอร์ต AI';
                                break;
                            case 'sales':
                            default:
                                $senderName = '🛒 พนักงานขาย AI';
                                break;
                        }
                    }
                    if (!empty($settings['sender_icon'])) {
                        $senderIcon = $settings['sender_icon'];
                    }
                }
            } catch (\Exception $e) {}
            
            // สร้าง LINE Message
            $lineMessage = [
                'type' => 'text',
                'text' => $responseText,
                'sender' => [
                    'name' => $senderName,
                    'iconUrl' => $senderIcon
                ]
            ];
            
            // เพิ่ม Quick Reply ถ้ามี
            if (!empty($result['quickReplies'])) {
                $lineMessage['quickReply'] = ['items' => $result['quickReplies']];
            }
            
            return [
                'success' => true,
                'response' => $responseText,
                'message' => $lineMessage,
                'state' => $result['state'] ?? 'triage',
                'mode' => 'triage'
            ];
            
        } catch (\Exception $e) {
            error_log("processWithTriage error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => 'ขออภัยค่ะ ระบบซักประวัติขัดข้อง กรุณาลองใหม่',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * ประมวลผลด้วย Gemini API + RAG + Function Calling
     */
    private function processWithGemini(string $message, array $redFlags): array
    {
        try {
            $customerInfo = $this->getCustomerInfo();
            $history = $this->getConversationHistory();
            
            $ragResults = $this->rag->search($message, 15);
            $ragContext = $this->rag->formatForAI($ragResults);
            $this->lastFoundProducts = $ragResults['products'] ?? [];
            
            // Check if session is active to prevent greeting
            $isActiveSession = $this->isSessionActive();
            
            // Analyze conversation to extract collected triage info
            $collectedInfo = $this->analyzeCollectedTriageInfo($history, $message);
            
            $systemPrompt = $this->buildPharmacySystemPrompt($customerInfo, $ragContext, $redFlags, $isActiveSession, $collectedInfo);
            $result = $this->callGeminiAPI($systemPrompt, $history, $message);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'response' => 'ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง',
                    'error' => $result['error']
                ];
            }
            
            $responseText = $result['text'];
            if (!empty($redFlags)) {
                $warningText = $this->redFlagDetector->buildWarningMessage($redFlags);
                $responseText = $warningText . "\n\n" . $responseText;
            }
            
            $this->saveConversation($message, $responseText);
            
            return [
                'success' => true,
                'response' => $responseText,
                'message' => $this->buildLINEMessage($responseText),
                'products' => $this->lastFoundProducts,
                'state' => $this->triageState ?? 'chat'
            ];
            
        } catch (\Exception $e) {
            error_log("PharmacyAI error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * วิเคราะห์ข้อมูล triage ที่เก็บได้จากประวัติการสนทนา
     */
    private function analyzeCollectedTriageInfo(array $history, string $currentMessage): array
    {
        $collected = [
            'symptoms' => null,
            'duration' => null,
            'severity' => null,
            'associated_symptoms' => null,
            'allergies' => null,
            'medical_conditions' => null
        ];
        
        // Combine all messages for analysis
        $allText = '';
        foreach ($history as $msg) {
            $allText .= $msg['content'] . "\n";
        }
        $allText .= $currentMessage;
        
        // Simple pattern matching for Thai text
        // Severity (1-10)
        if (preg_match('/(?:ความรุนแรง|รุนแรง|ระดับ).*?(\d+)/u', $allText, $m) || 
            preg_match('/^(\d+)$/m', $currentMessage, $m)) {
            $num = (int)$m[1];
            if ($num >= 1 && $num <= 10) {
                $collected['severity'] = $num;
            }
        }
        
        // Duration patterns
        if (preg_match('/(\d+)\s*(วัน|สัปดาห์|เดือน|ชั่วโมง)/u', $allText, $m)) {
            $collected['duration'] = $m[1] . ' ' . $m[2];
        }
        
        // Allergies
        if (preg_match('/แพ้.*?(ยา\S+|ไม่แพ้|ไม่มี)/u', $allText, $m)) {
            $collected['allergies'] = $m[1];
        }
        
        // Count how many items collected
        $count = 0;
        foreach ($collected as $v) {
            if ($v !== null) $count++;
        }
        $collected['count'] = $count;
        
        return $collected;
    }

    
    /**
     * สร้าง System Prompt - Sales Mode (พนักงานขาย AI)
     */
    private function buildPharmacySystemPrompt(?array $customerInfo, ?string $ragContext, array $redFlags, bool $isActiveSession = false, array $collectedInfo = []): string
    {
        $totalProducts = $this->getTotalProductCount();
        
        $prompt = <<<PROMPT
คุณคือ "พนักงานขาย AI" ผู้ช่วยขายสินค้าออนไลน์ของร้าน CNY Pharmacy

## บทบาทของคุณ:
- คุณเป็น **พนักงานขาย** ไม่ใช่เภสัชกร
- ช่วยลูกค้าหาสินค้าที่ต้องการ
- แนะนำสินค้าตามความต้องการ
- ตอบคำถามเกี่ยวกับสินค้า ราคา โปรโมชั่น
- ไม่ต้องซักประวัติอาการ ไม่ต้องถามเรื่องโรคประจำตัว

## กฎสำคัญ:
- ตอบสั้นๆ กระชับ ไม่เยิ่นเย้อ
- ถ้าลูกค้าถามหาสินค้า → ค้นหาและแนะนำทันที
- ถ้าลูกค้าถามราคา → บอกราคาทันที
- ถ้าลูกค้าถามเรื่องสุขภาพ/อาการป่วย → แนะนำให้ปรึกษาเภสัชกร หรือพิมพ์ /ai เพื่อคุยกับเภสัชกร AI
- ใช้ภาษาไทย สุภาพ เป็นกันเอง
- ใช้ emoji บ้าง 😊🛒

## Functions ที่ใช้ได้:
1. searchProducts(query) - ค้นหาสินค้าในร้าน

## ตัวอย่างการตอบ:
- "มียาพาราไหม" → ค้นหาและแสดงสินค้า
- "ราคาเท่าไหร่" → บอกราคา
- "มีโปรโมชั่นอะไรบ้าง" → แนะนำโปรโมชั่น
- "ปวดหัวมาก" → "แนะนำให้ปรึกษาเภสัชกรค่ะ พิมพ์ /ai เพื่อคุยกับเภสัชกร AI ได้เลยค่ะ 😊"

PROMPT;

        if ($customerInfo) {
            $prompt .= "\n## ข้อมูลลูกค้า:\n";
            if (!empty($customerInfo['display_name'])) {
                $prompt .= "- ชื่อ: {$customerInfo['display_name']}\n";
            }
            if (!empty($customerInfo['drug_allergies'])) {
                $prompt .= "- ⚠️ แพ้ยา: {$customerInfo['drug_allergies']} (ห้ามแนะนำยานี้!)\n";
            }
            if (!empty($customerInfo['medical_conditions'])) {
                $prompt .= "- โรคประจำตัว: {$customerInfo['medical_conditions']}\n";
            }
        }
        
        if (!empty($redFlags)) {
            $prompt .= "\n## ⚠️ พบอาการที่ต้องระวัง:\n";
            foreach ($redFlags as $flag) {
                $prompt .= "- {$flag['message']}\n";
            }
        }
        
        if ($ragContext) {
            $prompt .= "\n" . $ragContext;
        }
        
        return $prompt;
    }
    
    /**
     * เรียก Gemini API พร้อม Function Calling
     */
    private function callGeminiAPI(string $systemPrompt, array $history, string $userMessage): array
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'เข้าใจแล้วค่ะ พร้อมให้บริการเป็นพนักงานขาย AI ค่ะ 😊']]];
        
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'user' : 'model';
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }
        
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
        
        $tools = [[
            'functionDeclarations' => [
                [
                    'name' => 'searchProducts',
                    'description' => 'ค้นหาสินค้ายาในฐานข้อมูลร้าน',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'คำค้นหา เช่น ชื่อยา อาการ หรือตัวยา'],
                            'category' => ['type' => 'string', 'description' => 'หมวดหมู่ (optional)'],
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนผลลัพธ์ (default: 5)']
                        ],
                        'required' => ['query']
                    ]
                ],
                [
                    'name' => 'getProductDetails',
                    'description' => 'ดึงรายละเอียดสินค้าด้วย SKU',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string', 'description' => 'รหัส SKU']
                        ],
                        'required' => ['sku']
                    ]
                ],
                [
                    'name' => 'getProductsByCategory',
                    'description' => 'ดึงสินค้าตามหมวดหมู่',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'หมวดหมู่'],
                            'limit' => ['type' => 'integer', 'description' => 'จำนวน (default: 5)']
                        ],
                        'required' => ['category']
                    ]
                ],
                [
                    'name' => 'saveTriageAssessment',
                    'description' => 'บันทึกผลการซักประวัติอาการ เรียกเมื่อได้ข้อมูลอาการครบถ้วน (อย่างน้อย 4 ข้อ)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symptoms' => ['type' => 'string', 'description' => 'อาการหลักที่ลูกค้าบอก'],
                            'duration' => ['type' => 'string', 'description' => 'ระยะเวลาที่เป็น เช่น 2 วัน, 1 สัปดาห์'],
                            'severity' => ['type' => 'integer', 'description' => 'ความรุนแรง 1-10'],
                            'severity_level' => ['type' => 'string', 'description' => 'ระดับความรุนแรง: low, medium, high, critical'],
                            'associated_symptoms' => ['type' => 'string', 'description' => 'อาการร่วมอื่นๆ'],
                            'allergies' => ['type' => 'string', 'description' => 'ยาที่แพ้'],
                            'medical_conditions' => ['type' => 'string', 'description' => 'โรคประจำตัว'],
                            'current_medications' => ['type' => 'string', 'description' => 'ยาที่ทานอยู่'],
                            'ai_assessment' => ['type' => 'string', 'description' => 'การวินิจฉัยเบื้องต้นของ AI'],
                            'recommended_action' => ['type' => 'string', 'description' => 'คำแนะนำ: self_care, consult_pharmacist, see_doctor, emergency']
                        ],
                        'required' => ['symptoms', 'severity_level', 'ai_assessment', 'recommended_action']
                    ]
                ]
            ]
        ]];
        
        $data = [
            'contents' => $contents,
            'tools' => $tools,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 800,
                'topP' => 0.9
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH']
            ]
        ];
        
        $maxTurns = 3;
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) return ['success' => false, 'error' => $error];
            
            $result = json_decode($response, true);
            if ($httpCode !== 200) {
                return ['success' => false, 'error' => $result['error']['message'] ?? 'HTTP ' . $httpCode];
            }
            
            $parts = $result['candidates'][0]['content']['parts'] ?? [];
            $functionCall = null;
            $textResponse = null;
            
            foreach ($parts as $part) {
                if (isset($part['functionCall'])) $functionCall = $part['functionCall'];
                elseif (isset($part['text'])) $textResponse = $part['text'];
            }
            
            if ($functionCall) {
                $functionResult = $this->handleFunctionCall($functionCall);
                $data['contents'][] = ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]];
                $data['contents'][] = ['role' => 'user', 'parts' => [['functionResponse' => ['name' => $functionCall['name'], 'response' => $functionResult]]]];
                continue;
            }
            
            if ($textResponse) {
                $textResponse = trim($textResponse);
                $textResponse = preg_replace('/\*\*([^*]+)\*\*/', '$1', $textResponse);
                return ['success' => true, 'text' => $textResponse];
            }
        }
        
        return ['success' => false, 'error' => 'No response'];
    }

    
    /**
     * ประมวลผล Function Call จาก AI
     */
    private function handleFunctionCall(array $functionCall): array
    {
        $name = $functionCall['name'] ?? '';
        $args = $functionCall['args'] ?? [];
        
        switch ($name) {
            case 'searchProducts':
                $query = $args['query'] ?? '';
                $category = $args['category'] ?? null;
                $limit = intval($args['limit'] ?? 5);
                $products = $this->rag->searchProductsForAI($query, $category, $limit);
                if (!empty($products)) {
                    $this->lastFoundProducts = array_merge($this->lastFoundProducts, $products);
                }
                return ['success' => true, 'count' => count($products), 'products' => $products];
                
            case 'getProductDetails':
                $sku = $args['sku'] ?? '';
                $product = $this->rag->getProductDetailsBySku($sku);
                if ($product) {
                    $this->lastFoundProducts[] = $product;
                    return ['success' => true, 'product' => $product];
                }
                return ['success' => false, 'message' => "ไม่พบสินค้า SKU: {$sku}"];
                
            case 'getProductsByCategory':
                $category = $args['category'] ?? '';
                $limit = intval($args['limit'] ?? 5);
                $products = $this->rag->getProductsByCategory($category, $limit);
                if (!empty($products)) {
                    $this->lastFoundProducts = array_merge($this->lastFoundProducts, $products);
                }
                return ['success' => true, 'category' => $category, 'count' => count($products), 'products' => $products];
            
            case 'saveTriageAssessment':
                return $this->saveTriageAssessment($args);
                
            default:
                return ['success' => false, 'error' => "Unknown function: {$name}"];
        }
    }
    
    /**
     * บันทึกผลการซักประวัติและแจ้งเตือนถ้าจำเป็น
     */
    private function saveTriageAssessment(array $data): array
    {
        if (!$this->userId) {
            return ['success' => false, 'error' => 'No user ID'];
        }
        
        try {
            // Create table if not exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_triage_assessments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    line_account_id INT NULL,
                    symptoms TEXT,
                    duration VARCHAR(100),
                    severity INT,
                    severity_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
                    associated_symptoms TEXT,
                    allergies TEXT,
                    medical_conditions TEXT,
                    current_medications TEXT,
                    ai_assessment TEXT,
                    recommended_action ENUM('self_care', 'consult_pharmacist', 'see_doctor', 'emergency') DEFAULT 'self_care',
                    pharmacist_notified TINYINT(1) DEFAULT 0,
                    pharmacist_response TEXT,
                    status ENUM('pending', 'reviewed', 'completed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_severity (severity_level),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insert assessment
            $stmt = $this->db->prepare("
                INSERT INTO ai_triage_assessments 
                (user_id, line_account_id, symptoms, duration, severity, severity_level, 
                 associated_symptoms, allergies, medical_conditions, current_medications,
                 ai_assessment, recommended_action)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $this->userId,
                $this->lineAccountId,
                $data['symptoms'] ?? '',
                $data['duration'] ?? '',
                intval($data['severity'] ?? 5),
                $data['severity_level'] ?? 'low',
                $data['associated_symptoms'] ?? '',
                $data['allergies'] ?? '',
                $data['medical_conditions'] ?? '',
                $data['current_medications'] ?? '',
                $data['ai_assessment'] ?? '',
                $data['recommended_action'] ?? 'self_care'
            ]);
            
            $assessmentId = $this->db->lastInsertId();
            
            // Also update triage_sessions.triage_data if session exists
            if ($this->sessionId) {
                $triageData = [
                    'symptoms' => $data['symptoms'] ?? '',
                    'duration' => $data['duration'] ?? '',
                    'severity' => intval($data['severity'] ?? 5),
                    'severity_level' => $data['severity_level'] ?? 'low',
                    'associated_symptoms' => $data['associated_symptoms'] ?? '',
                    'allergies' => $data['allergies'] ?? '',
                    'medical_conditions' => $data['medical_conditions'] ?? '',
                    'current_medications' => $data['current_medications'] ?? '',
                    'ai_assessment' => $data['ai_assessment'] ?? '',
                    'recommended_action' => $data['recommended_action'] ?? 'self_care',
                    'assessment_id' => $assessmentId
                ];
                
                $stmt = $this->db->prepare("
                    UPDATE triage_sessions 
                    SET triage_data = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([json_encode($triageData, JSON_UNESCAPED_UNICODE), $this->sessionId]);
                error_log("saveTriageAssessment: Updated triage_sessions #{$this->sessionId} with triage_data");
            }
            
            // Notify pharmacist if severity is high or critical
            $severityLevel = $data['severity_level'] ?? 'low';
            $notified = false;
            
            if (in_array($severityLevel, ['high', 'critical'])) {
                $notified = $this->notifyPharmacist($assessmentId, $data);
            }
            
            error_log("saveTriageAssessment: Saved assessment #{$assessmentId}, severity={$severityLevel}, notified={$notified}");
            
            return [
                'success' => true,
                'assessment_id' => $assessmentId,
                'severity_level' => $severityLevel,
                'pharmacist_notified' => $notified,
                'message' => $notified ? 'บันทึกแล้ว และแจ้งเภสัชกรเรียบร้อย' : 'บันทึกการประเมินเรียบร้อย'
            ];
            
        } catch (\Exception $e) {
            error_log("saveTriageAssessment error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * แจ้งเตือนเภสัชกร
     * Requirements: 4.1, 4.2 - Create notification when severity is high/critical
     */
    private function notifyPharmacist(int $assessmentId, array $data): bool
    {
        try {
            // Get user info
            $stmt = $this->db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Get active triage session for linking
            $triageSessionId = null;
            try {
                $stmt = $this->db->prepare("SELECT id FROM triage_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$this->userId]);
                $session = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($session) {
                    $triageSessionId = $session['id'];
                }
            } catch (\Exception $e) {
                // Table might not exist
            }
            
            // Ensure pharmacist_notifications table exists with all required columns
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL,
                    type VARCHAR(50) DEFAULT 'triage_alert',
                    title VARCHAR(255),
                    message TEXT,
                    notification_data JSON,
                    reference_id INT,
                    reference_type VARCHAR(50),
                    user_id INT,
                    triage_session_id INT NULL,
                    priority ENUM('normal', 'urgent') DEFAULT 'normal',
                    status ENUM('pending', 'handled', 'dismissed') DEFAULT 'pending',
                    is_read TINYINT(1) DEFAULT 0,
                    handled_by INT NULL,
                    handled_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id),
                    INDEX idx_status (status),
                    INDEX idx_priority (priority),
                    INDEX idx_is_read (is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $severityLevel = $data['severity_level'] ?? 'high';
            $severityText = $severityLevel === 'critical' ? '🚨 ฉุกเฉิน' : '⚠️ รุนแรง';
            $priority = $severityLevel === 'critical' ? 'urgent' : 'normal';
            
            $stmt = $this->db->prepare("
                INSERT INTO pharmacist_notifications 
                (line_account_id, type, title, message, notification_data, reference_id, reference_type, user_id, triage_session_id, priority, status)
                VALUES (?, 'triage_alert', ?, ?, ?, ?, 'triage_assessment', ?, ?, ?, 'pending')
            ");
            
            $title = "{$severityText} - ลูกค้าต้องการความช่วยเหลือ";
            $message = "ลูกค้า: " . ($user['display_name'] ?? 'ไม่ระบุชื่อ') . "\n";
            $message .= "อาการ: " . ($data['symptoms'] ?? '-') . "\n";
            $message .= "ระยะเวลา: " . ($data['duration'] ?? '-') . "\n";
            $message .= "ความรุนแรง: " . ($data['severity'] ?? '-') . "/10\n";
            $message .= "การประเมิน: " . ($data['ai_assessment'] ?? '-');
            
            // Include full triage data in notification_data for dashboard display
            $notificationData = json_encode([
                'symptoms' => $data['symptoms'] ?? '',
                'duration' => $data['duration'] ?? '',
                'severity' => $data['severity'] ?? 5,
                'severity_level' => $severityLevel,
                'associated_symptoms' => $data['associated_symptoms'] ?? '',
                'allergies' => $data['allergies'] ?? '',
                'medical_conditions' => $data['medical_conditions'] ?? '',
                'current_medications' => $data['current_medications'] ?? '',
                'ai_assessment' => $data['ai_assessment'] ?? '',
                'recommended_action' => $data['recommended_action'] ?? 'consult_pharmacist'
            ], JSON_UNESCAPED_UNICODE);
            
            $stmt->execute([
                $this->lineAccountId,
                $title,
                $message,
                $notificationData,
                $assessmentId,
                $this->userId,
                $triageSessionId,
                $priority
            ]);
            
            // Update assessment as notified
            $stmt = $this->db->prepare("UPDATE ai_triage_assessments SET pharmacist_notified = 1 WHERE id = ?");
            $stmt->execute([$assessmentId]);
            
            error_log("notifyPharmacist: Created notification for assessment #{$assessmentId}, severity={$severityLevel}, priority={$priority}");
            
            return true;
            
        } catch (\Exception $e) {
            error_log("notifyPharmacist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create notification when user explicitly requests pharmacist consultation
     * Requirements: 4.1 - Create notification when user requests pharmacist
     * 
     * @param string $reason Reason for requesting pharmacist (e.g., 'user_request', 'escalation')
     * @return bool Success status
     */
    public function createPharmacistRequestNotification(string $reason = 'user_request'): bool
    {
        if (!$this->userId) {
            return false;
        }
        
        try {
            // Get user info
            $stmt = $this->db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Get active triage session for linking
            $triageSessionId = null;
            $triageData = [];
            try {
                $stmt = $this->db->prepare("SELECT id, triage_data FROM triage_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$this->userId]);
                $session = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($session) {
                    $triageSessionId = $session['id'];
                    $triageData = json_decode($session['triage_data'] ?? '{}', true) ?: [];
                }
            } catch (\Exception $e) {
                // Table might not exist
            }
            
            // Ensure pharmacist_notifications table exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL,
                    type VARCHAR(50) DEFAULT 'triage_alert',
                    title VARCHAR(255),
                    message TEXT,
                    notification_data JSON,
                    reference_id INT,
                    reference_type VARCHAR(50),
                    user_id INT,
                    triage_session_id INT NULL,
                    priority ENUM('normal', 'urgent') DEFAULT 'normal',
                    status ENUM('pending', 'handled', 'dismissed') DEFAULT 'pending',
                    is_read TINYINT(1) DEFAULT 0,
                    handled_by INT NULL,
                    handled_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id),
                    INDEX idx_status (status),
                    INDEX idx_priority (priority),
                    INDEX idx_is_read (is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $title = "👨‍⚕️ ลูกค้าขอปรึกษาเภสัชกร";
            $message = "ลูกค้า: " . ($user['display_name'] ?? 'ไม่ระบุชื่อ') . "\n";
            
            if (!empty($triageData['symptoms'])) {
                $symptoms = is_array($triageData['symptoms']) ? implode(', ', $triageData['symptoms']) : $triageData['symptoms'];
                $message .= "อาการ: {$symptoms}\n";
            }
            if (!empty($triageData['duration'])) {
                $message .= "ระยะเวลา: {$triageData['duration']}\n";
            }
            if (!empty($triageData['severity'])) {
                $message .= "ความรุนแรง: {$triageData['severity']}/10\n";
            }
            $message .= "เหตุผล: " . ($reason === 'user_request' ? 'ลูกค้าขอปรึกษาเภสัชกรโดยตรง' : $reason);
            
            // Include full triage data in notification_data
            $notificationData = json_encode([
                'reason' => $reason,
                'symptoms' => $triageData['symptoms'] ?? [],
                'duration' => $triageData['duration'] ?? '',
                'severity' => $triageData['severity'] ?? null,
                'associated_symptoms' => $triageData['associated_symptoms'] ?? [],
                'allergies' => $triageData['allergies'] ?? [],
                'medical_history' => $triageData['medical_history'] ?? [],
                'current_medications' => $triageData['current_medications'] ?? [],
                'red_flags' => $triageData['red_flags'] ?? []
            ], JSON_UNESCAPED_UNICODE);
            
            $stmt = $this->db->prepare("
                INSERT INTO pharmacist_notifications 
                (line_account_id, type, title, message, notification_data, user_id, triage_session_id, priority, status)
                VALUES (?, 'escalation', ?, ?, ?, ?, ?, 'normal', 'pending')
            ");
            
            $stmt->execute([
                $this->lineAccountId,
                $title,
                $message,
                $notificationData,
                $this->userId,
                $triageSessionId
            ]);
            
            $notificationId = $this->db->lastInsertId();
            error_log("createPharmacistRequestNotification: Created notification #{$notificationId} for user #{$this->userId}, reason={$reason}");
            
            return true;
            
        } catch (\Exception $e) {
            error_log("createPharmacistRequestNotification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle Critical Red Flag
     */
    private function handleCriticalRedFlag(array $redFlags): array
    {
        $warningText = $this->redFlagDetector->buildWarningMessage($redFlags);
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        
        $text = $warningText;
        $text .= "\n\n📞 เบอร์ฉุกเฉิน:\n";
        foreach (array_slice($contacts, 0, 2) as $contact) {
            $text .= "• {$contact['name']}: {$contact['number']}\n";
        }
        
        return [
            'success' => true,
            'response' => $text,
            'message' => $this->buildLINEMessage($text),
            'is_critical' => true,
            'red_flags' => $redFlags,
            'state' => 'emergency'
        ];
    }
    
    /**
     * Build LINE Message - ใช้ sender จาก ai_settings
     */
    private function buildLINEMessage(string $text): array
    {
        // ดึง sender settings จาก ai_settings
        $senderName = '🤖 AI Assistant';
        $senderIcon = 'https://cdn-icons-png.flaticon.com/512/4712/4712109.png';
        
        try {
            $stmt = $this->db->prepare("SELECT sender_name, sender_icon, ai_mode FROM ai_settings WHERE line_account_id = ? LIMIT 1");
            $stmt->execute([$this->lineAccountId]);
            $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($settings) {
                if (!empty($settings['sender_name'])) {
                    $senderName = $settings['sender_name'];
                } else {
                    // Default sender name ตาม ai_mode
                    $mode = $settings['ai_mode'] ?? 'sales';
                    switch ($mode) {
                        case 'pharmacist':
                        case 'pharmacy':
                            $senderName = '💊 เภสัชกร AI';
                            break;
                        case 'support':
                            $senderName = '💬 ซัพพอร์ต AI';
                            break;
                        case 'sales':
                        default:
                            $senderName = '🛒 พนักงานขาย AI';
                            break;
                    }
                }
                if (!empty($settings['sender_icon'])) {
                    $senderIcon = $settings['sender_icon'];
                }
            }
        } catch (\Exception $e) {
            // Use default
        }
        
        $message = [
            'type' => 'text',
            'text' => $text,
            'sender' => [
                'name' => $senderName,
                'iconUrl' => $senderIcon
            ]
        ];
        
        $quickReplyItems = [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '💊 แนะนำยา', 'text' => 'แนะนำยา']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🩺 ปรึกษาอาการ', 'text' => 'ปรึกษาอาการ']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🛒 ดูร้านค้า', 'text' => 'ร้านค้า']]
        ];
        
        $message['quickReply'] = ['items' => $quickReplyItems];
        
        return $message;
    }
    
    /**
     * ดึงข้อมูลลูกค้า
     */
    private function getCustomerInfo(): ?array
    {
        if (!$this->userId) return null;
        
        try {
            $stmt = $this->db->prepare(
                "SELECT u.display_name, m.drug_allergies, m.medical_conditions, m.current_medications
                 FROM users u
                 LEFT JOIN user_medical_history m ON u.id = m.user_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$this->userId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * ดึงประวัติการสนทนา
     */
    private function getConversationHistory(): array
    {
        // Use externally set history if available
        if (!empty($this->conversationHistory)) {
            return $this->conversationHistory;
        }
        
        if (!$this->userId) return [];
        
        try {
            $stmt = $this->db->prepare(
                "SELECT role, content FROM ai_conversation_history 
                 WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
            );
            $stmt->execute([$this->userId]);
            return array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * บันทึกประวัติการสนทนา
     */
    private function saveConversation(string $userMessage, string $aiResponse): void
    {
        if (!$this->userId) return;
        
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ai_conversation_history (user_id, line_account_id, role, content) VALUES (?, ?, 'user', ?)"
            );
            $stmt->execute([$this->userId, $this->lineAccountId, $userMessage]);
            
            $stmt = $this->db->prepare(
                "INSERT INTO ai_conversation_history (user_id, line_account_id, role, content) VALUES (?, ?, 'assistant', ?)"
            );
            $stmt->execute([$this->userId, $this->lineAccountId, $aiResponse]);
        } catch (\Exception $e) {
            error_log("saveConversation error: " . $e->getMessage());
        }
    }
    
    /**
     * นับจำนวนสินค้าทั้งหมด
     */
    public function getTotalProductCount(): int
    {
        try {
            // ใช้ business_items table
            $stmt = $this->db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1");
            return intval($stmt->fetchColumn() ?: 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get last found products
     */
    public function getLastFoundProducts(): array
    {
        return $this->lastFoundProducts;
    }
    
    /**
     * Search products (public method)
     */
    public function searchProducts(string $query, int $limit = 10): array
    {
        return $this->rag->findByName($query, $limit);
    }
    
    /**
     * Find product by SKU (public method)
     */
    public function findProductBySku(string $sku): ?array
    {
        return $this->rag->findBySku($sku);
    }
}
