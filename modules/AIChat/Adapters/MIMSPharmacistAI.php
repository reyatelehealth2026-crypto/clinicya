<?php
/**
 * MIMS Pharmacist AI - AI เภสัชกรออนไลน์
 * 
 * ใช้ข้อมูลจาก MIMS Pharmacy Thailand 2023 เป็นฐานความรู้
 * ทำงานตาม Protocol: Symptom Triage → Red Flag Check → Non-drug Advice → Pharmacotherapy
 */

namespace Modules\AIChat\Adapters;

// Load dependencies only if not already loaded
if (!function_exists('loadAIChatModule')) {
    require_once __DIR__ . '/../Autoloader.php';
}
if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

use Modules\AIChat\Services\MIMSKnowledgeBase;
use Modules\AIChat\Services\PharmacyRAG;

class MIMSPharmacistAI
{
    private $db;
    private ?int $lineAccountId;
    private ?int $userId = null;
    private ?string $apiKey = null;
    private string $model = 'gemini-2.5-flash';
    
    private MIMSKnowledgeBase $mimsKB;
    private PharmacyRAG $rag;
    
    private const API_BASE = GEMINI_API_BASE . '/v1beta/models/';
    
    // Conversation state
    private array $conversationState = [
        'step' => 'initial', // initial, triage, assessment, recommendation
        'symptoms' => [],
        'patient_info' => [],
        'red_flags' => [],
        'disease_matches' => []
    ];
    
    public function __construct($db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->mimsKB = new MIMSKnowledgeBase();
        $this->rag = new PharmacyRAG($db, $lineAccountId);
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
                error_log("MIMSPharmacistAI loadApiKey error: " . $e->getMessage());
            }
        }
        
        // Fallback to any available API key
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
        $this->loadConversationState();
        return $this;
    }
    
    public function isEnabled(): bool
    {
        return !empty($this->apiKey);
    }
    
    /**
     * Main entry point - Process user message
     */
    public function processMessage(string $message): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'AI is not enabled'];
        }
        
        try {
            // Step 1: Check for Red Flags first (Safety First!)
            $redFlags = $this->mimsKB->checkRedFlags($message);
            if (!empty($redFlags)) {
                $criticalFlags = array_filter($redFlags, fn($f) => $f['flag']['urgency'] === 'emergency');
                if (!empty($criticalFlags)) {
                    return $this->handleEmergencyRedFlag($criticalFlags);
                }
                $this->conversationState['red_flags'] = array_merge($this->conversationState['red_flags'], $redFlags);
            }
            
            // Step 2: Search MIMS Knowledge Base for matching diseases
            $diseaseMatches = $this->mimsKB->searchBySymptom($message);
            if (!empty($diseaseMatches)) {
                $this->conversationState['disease_matches'] = $diseaseMatches;
            }
            
            // Step 3: Search products from pharmacy inventory
            $ragResults = $this->rag->search($message, 10);
            
            // Step 4: Build context and call AI
            $context = $this->buildMIMSContext($message, $diseaseMatches, $ragResults);
            $result = $this->callGeminiWithMIMS($message, $context);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'response' => 'ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง',
                    'error' => $result['error']
                ];
            }
            
            // Add warning if there are non-critical red flags
            $responseText = $result['text'] ?? '';
            
            // Debug: Log the response text
            error_log("MIMSPharmacistAI responseText: " . substr($responseText, 0, 200));
            
            // ถ้าไม่มี response text ให้ใช้ default message
            if (empty(trim($responseText))) {
                $responseText = "ขออภัยค่ะ ไม่สามารถประมวลผลได้ในขณะนี้ กรุณาลองใหม่อีกครั้งค่ะ";
                error_log("MIMSPharmacistAI: Empty response, using default message");
            }
            
            if (!empty($this->conversationState['red_flags'])) {
                $warnings = array_map(fn($f) => $f['flag']['message'], $this->conversationState['red_flags']);
                $warningText = implode("\n", array_unique($warnings));
                $responseText = $warningText . "\n\n" . $responseText;
            }
            
            // Save conversation
            $this->saveConversation($message, $responseText);
            $this->saveConversationState();
            
            return [
                'success' => true,
                'response' => $responseText,
                'message' => $this->buildLINEMessage($responseText),
                'mims_matches' => $diseaseMatches,
                'products' => $ragResults['products'] ?? [],
                'state' => $this->conversationState
            ];
            
        } catch (\Exception $e) {
            error_log("MIMSPharmacistAI error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle emergency red flags
     */
    private function handleEmergencyRedFlag(array $criticalFlags): array
    {
        $messages = array_map(fn($f) => $f['flag']['message'], $criticalFlags);
        $text = implode("\n", array_unique($messages));
        
        $text .= "\n\n📞 เบอร์ฉุกเฉิน:\n";
        $text .= "• สายด่วนฉุกเฉิน: 1669\n";
        $text .= "• สายด่วนสุขภาพจิต: 1323\n";
        $text .= "• ศูนย์พิษวิทยา: 1367\n";
        
        $text .= "\n⚠️ กรุณาโทรเรียกรถพยาบาลหรือไปโรงพยาบาลที่ใกล้ที่สุดทันที";
        
        return [
            'success' => true,
            'response' => $text,
            'message' => $this->buildLINEMessage($text),
            'is_emergency' => true,
            'red_flags' => $criticalFlags
        ];
    }

    
    /**
     * Build MIMS context for AI
     */
    private function buildMIMSContext(string $message, array $diseaseMatches, array $ragResults): string
    {
        $context = "";
        
        // Add MIMS disease information with source label
        if (!empty($diseaseMatches)) {
            $context .= "## 📚 ข้อมูลจาก MIMS Pharmacy Thailand 2023 (ใช้อ้างอิงด้วย: 📚 [MIMS 2023: ชื่อโรค]):\n\n";
            
            // Limit to top 2 matches to avoid context overflow
            $topMatches = array_slice($diseaseMatches, 0, 2);
            foreach ($topMatches as $match) {
                $context .= $this->mimsKB->formatDiseaseForAI($match);
                $context .= "\n---\n\n";
            }
        }
        
        // Add pharmacy products with source label
        if (!empty($ragResults['products'])) {
            $context .= "## 🛒 สินค้ายาในร้านที่เกี่ยวข้อง (ใช้อ้างอิงด้วย: 🛒 [สินค้าในร้าน]):\n";
            foreach (array_slice($ragResults['products'], 0, 8) as $p) {
                $sku = $p['sku'] ?? $p['barcode'] ?? '';
                $context .= "- {$p['name']}";
                if ($sku) $context .= " [SKU:{$sku}]";
                $context .= " ราคา {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $context .= " (ตัวยา: {$p['generic_name']})";
                }
                if (!empty($p['stock']) && $p['stock'] > 0) {
                    $context .= " มีสต็อก";
                }
                $context .= "\n";
            }
        }
        
        // Add patient info if available with source label
        $customerInfo = $this->getCustomerInfo();
        if ($customerInfo) {
            $context .= "\n## 👤 ข้อมูลผู้ป่วย (ใช้อ้างอิงด้วย: 👤 [ข้อมูลผู้ป่วย]):\n";
            if (!empty($customerInfo['display_name'])) {
                $context .= "- ชื่อ: {$customerInfo['display_name']}\n";
            }
            if (!empty($customerInfo['drug_allergies'])) {
                $context .= "- ⚠️ แพ้ยา: {$customerInfo['drug_allergies']} (ห้ามแนะนำยานี้!)\n";
            }
            if (!empty($customerInfo['medical_conditions'])) {
                $context .= "- โรคประจำตัว: {$customerInfo['medical_conditions']}\n";
            }
        }
        
        return $context;
    }
    
    /**
     * Call Gemini API with MIMS system prompt
     */
    private function callGeminiWithMIMS(string $userMessage, string $context): array
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        // Build system prompt
        $systemPrompt = $this->mimsKB->buildMIMSSystemPrompt();
        
        // Add context
        if (!empty($context)) {
            $systemPrompt .= "\n\n" . $context;
        }
        
        // Get conversation history
        $history = $this->getConversationHistory();
        
        // Build contents
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'เข้าใจแล้วค่ะ พร้อมให้บริการเป็น MIMS Online Pharmacist AI ค่ะ 😊 จะปฏิบัติตาม Protocol อย่างเคร่งครัด โดยเน้นความปลอดภัยของผู้ป่วยเป็นอันดับแรกค่ะ']]];
        
        // Add history
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'user' : 'model';
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }
        
        // Add current message
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
        
        // Define function calling for MIMS
        $tools = [[
            'functionDeclarations' => [
                [
                    'name' => 'searchMIMSDisease',
                    'description' => 'ค้นหาข้อมูลโรคและการรักษาจาก MIMS Knowledge Base',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symptom' => ['type' => 'string', 'description' => 'อาการที่ต้องการค้นหา'],
                            'category' => ['type' => 'string', 'description' => 'หมวดหมู่โรค (optional)']
                        ],
                        'required' => ['symptom']
                    ]
                ],
                [
                    'name' => 'checkRedFlags',
                    'description' => 'ตรวจสอบอาการอันตรายที่ต้องส่งต่อแพทย์',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symptoms' => ['type' => 'string', 'description' => 'อาการที่ต้องการตรวจสอบ']
                        ],
                        'required' => ['symptoms']
                    ]
                ],
                [
                    'name' => 'searchProducts',
                    'description' => 'ค้นหาสินค้ายาในร้าน',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'คำค้นหา'],
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนผลลัพธ์']
                        ],
                        'required' => ['query']
                    ]
                ],
                [
                    'name' => 'getCorticosteroidRecommendation',
                    'description' => 'แนะนำยาทาสเตียรอยด์ตามบริเวณที่ใช้',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'bodyArea' => ['type' => 'string', 'description' => 'บริเวณที่จะใช้ยา เช่น หน้า แขน ขา']
                        ],
                        'required' => ['bodyArea']
                    ]
                ]
            ]
        ]];
        
        $data = [
            'contents' => $contents,
            'tools' => $tools,
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 1000,
                'topP' => 0.9
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH']
            ]
        ];
        
        // Allow multiple turns for function calling
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
            
            if ($error) {
                return ['success' => false, 'error' => $error];
            }
            
            $result = json_decode($response, true);
            if ($httpCode !== 200) {
                return ['success' => false, 'error' => $result['error']['message'] ?? 'HTTP ' . $httpCode];
            }
            
            $parts = $result['candidates'][0]['content']['parts'] ?? [];
            $functionCall = null;
            $textResponse = null;
            
            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $functionCall = $part['functionCall'];
                } elseif (isset($part['text'])) {
                    $textResponse = $part['text'];
                }
            }
            
            // Handle function call
            if ($functionCall) {
                $functionResult = $this->handleFunctionCall($functionCall);
                
                // Add function call and result to conversation
                $data['contents'][] = ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]];
                $data['contents'][] = ['role' => 'user', 'parts' => [['functionResponse' => [
                    'name' => $functionCall['name'],
                    'response' => $functionResult
                ]]]];
                
                continue;
            }
            
            // Return text response
            if ($textResponse) {
                // Clean up response
                $textResponse = trim($textResponse);
                $textResponse = preg_replace('/\*\*([^*]+)\*\*/', '$1', $textResponse);
                
                return ['success' => true, 'text' => $textResponse];
            }
        }
        
        return ['success' => false, 'error' => 'No response from AI'];
    }
    
    /**
     * Handle function calls from AI
     */
    private function handleFunctionCall(array $functionCall): array
    {
        $name = $functionCall['name'] ?? '';
        $args = $functionCall['args'] ?? [];
        
        switch ($name) {
            case 'searchMIMSDisease':
                $symptom = $args['symptom'] ?? '';
                $matches = $this->mimsKB->searchBySymptom($symptom);
                return [
                    'success' => true,
                    'count' => count($matches),
                    'diseases' => array_map(fn($m) => [
                        'name_th' => $m['name_th'],
                        'name_en' => $m['name_en'],
                        'category' => $m['category'],
                        'assessment_questions' => $m['assessment_questions'] ?? [],
                        'non_drug_advice' => $m['non_drug_advice'] ?? [],
                        'referral_criteria' => $m['referral_criteria'] ?? []
                    ], array_slice($matches, 0, 3))
                ];
                
            case 'checkRedFlags':
                $symptoms = $args['symptoms'] ?? '';
                $flags = $this->mimsKB->checkRedFlags($symptoms);
                return [
                    'success' => true,
                    'has_red_flags' => !empty($flags),
                    'flags' => array_map(fn($f) => [
                        'condition' => $f['flag']['condition'],
                        'urgency' => $f['flag']['urgency'],
                        'message' => $f['flag']['message']
                    ], $flags)
                ];
                
            case 'searchProducts':
                $query = $args['query'] ?? '';
                $limit = intval($args['limit'] ?? 5);
                $products = $this->rag->searchProductsForAI($query, null, $limit);
                return [
                    'success' => true,
                    'count' => count($products),
                    'products' => $products
                ];
                
            case 'getCorticosteroidRecommendation':
                $bodyArea = $args['bodyArea'] ?? '';
                $recommendation = $this->mimsKB->recommendCorticosteroid($bodyArea);
                return [
                    'success' => true,
                    'area' => $bodyArea,
                    'recommendation' => $recommendation
                ];
                
            default:
                return ['success' => false, 'error' => "Unknown function: {$name}"];
        }
    }

    
    /**
     * Build LINE message with quick replies
     */
    private function buildLINEMessage(string $text): array
    {
        $message = [
            'type' => 'text',
            'text' => $text,
            'sender' => [
                'name' => '💊 MIMS AI',
                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png'
            ]
        ];
        
        // Add quick replies based on conversation state
        $quickReplyItems = [];
        
        if ($this->conversationState['step'] === 'initial') {
            $quickReplyItems = [
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🩺 ปรึกษาอาการ', 'text' => 'ปรึกษาอาการ']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '💊 ถามเรื่องยา', 'text' => 'ถามเรื่องยา']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🛒 ดูร้านค้า', 'text' => 'ร้านค้า']]
            ];
        } else {
            $quickReplyItems = [
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '✅ ใช่', 'text' => 'ใช่']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '❌ ไม่ใช่', 'text' => 'ไม่']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🔄 เริ่มใหม่', 'text' => 'เริ่มใหม่']],
                ['type' => 'action', 'action' => ['type' => 'message', 'label' => '👨‍⚕️ พบเภสัชกร', 'text' => 'ขอพบเภสัชกร']]
            ];
        }
        
        $message['quickReply'] = ['items' => $quickReplyItems];
        
        return $message;
    }
    
    /**
     * Get customer info
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
     * Get conversation history
     */
    private function getConversationHistory(): array
    {
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
     * Save conversation
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
            error_log("MIMSPharmacistAI saveConversation error: " . $e->getMessage());
        }
    }
    
    /**
     * Load conversation state from database
     */
    private function loadConversationState(): void
    {
        if (!$this->userId) return;
        
        try {
            $stmt = $this->db->prepare(
                "SELECT state_data FROM mims_conversation_state WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([$this->userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($row && !empty($row['state_data'])) {
                $state = json_decode($row['state_data'], true);
                if ($state) {
                    $this->conversationState = array_merge($this->conversationState, $state);
                }
            }
        } catch (\Exception $e) {
            // Table might not exist, ignore
        }
    }
    
    /**
     * Save conversation state to database
     */
    private function saveConversationState(): void
    {
        if (!$this->userId) return;
        
        try {
            // Check if table exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'mims_conversation_state'");
            if ($stmt->rowCount() === 0) {
                // Create table if not exists
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS mims_conversation_state (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        state_data JSON,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_user_id (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Upsert state
            $stmt = $this->db->prepare(
                "INSERT INTO mims_conversation_state (user_id, state_data) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE state_data = VALUES(state_data), updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$this->userId, json_encode($this->conversationState)]);
        } catch (\Exception $e) {
            error_log("MIMSPharmacistAI saveConversationState error: " . $e->getMessage());
        }
    }
    
    /**
     * Reset conversation state
     */
    public function resetConversation(): void
    {
        $this->conversationState = [
            'step' => 'initial',
            'symptoms' => [],
            'patient_info' => [],
            'red_flags' => [],
            'disease_matches' => []
        ];
        $this->saveConversationState();
    }
    
    /**
     * Get MIMS Knowledge Base instance
     */
    public function getMIMSKnowledgeBase(): MIMSKnowledgeBase
    {
        return $this->mimsKB;
    }
    
    /**
     * Search MIMS directly
     */
    public function searchMIMS(string $symptom): array
    {
        return $this->mimsKB->searchBySymptom($symptom);
    }
    
    /**
     * Get disease info from MIMS
     */
    public function getDiseaseInfo(string $category, string $diseaseKey): ?array
    {
        return $this->mimsKB->getDisease($category, $diseaseKey);
    }
}
