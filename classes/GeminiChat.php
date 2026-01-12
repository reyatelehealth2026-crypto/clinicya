<?php
/**
 * GeminiChat - AI Chat Response using Google Gemini
 * Version 4.0 - Multi-Mode AI (Pharmacist / Sales / Support)
 * รองรับ: เภสัชกร, พนักงานขาย, ซัพพอร์ต
 */

class GeminiChat
{
    private $db;
    private $apiKey;
    private $model;
    private $settings;
    private $lineAccountId;
    
    const DEFAULT_MODEL = 'gemini-2.0-flash';
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }
    
    /**
     * โหลดการตั้งค่าจากฐานข้อมูล
     */
    private function loadSettings()
    {
        $this->settings = [
            'is_enabled' => false,
            'model' => self::DEFAULT_MODEL,
            'system_prompt' => '',
            'temperature' => 0.7,
            'max_tokens' => 500,
            'response_style' => 'professional',
            'fallback_message' => 'ขออภัยค่ะ ไม่เข้าใจคำถาม กรุณาติดต่อเจ้าหน้าที่',
            'business_info' => '',
            'product_knowledge' => '',
            'ai_mode' => 'sales', // Default to sales mode
            'sales_prompt' => '',
            'auto_load_products' => 1,
            'product_load_limit' => 50
        ];
        
        try {
            // Load from ai_settings table
            $stmt = $this->db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ? LIMIT 1");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $this->settings = array_merge($this->settings, $result);
                $this->apiKey = $result['gemini_api_key'] ?? '';
                $this->model = $result['model'] ?? self::DEFAULT_MODEL;
                $this->settings['is_enabled'] = ($result['is_enabled'] == 1);
            }
            
        } catch (Exception $e) {
            error_log("GeminiChat loadSettings error: " . $e->getMessage());
        }
    }
    
    public function isEnabled()
    {
        return $this->settings['is_enabled'] && !empty($this->apiKey);
    }
    
    public function getMode()
    {
        return $this->settings['ai_mode'] ?? 'sales';
    }
    
    /**
     * สร้างคำตอบโดยใช้การวิเคราะห์ประวัติการสนทนา
     */
    public function generateResponse($userMessage, $userId = null, $conversationHistory = [])
    {
        if (!$this->isEnabled()) {
            return null;
        }
        
        try {
            $startTime = microtime(true);
            
            // สร้าง Full Prompt ตามโหมด
            $prompt = $this->buildPrompt($userMessage, $userId, $conversationHistory);
            
            // ส่งประวัติการคุยเพื่อให้ AI ทราบบริบทต่อเนื่อง
            $fullHistory = $conversationHistory;
            $fullHistory[] = ['role' => 'user', 'content' => $userMessage];
            
            $response = $this->callGeminiAPI($prompt, $fullHistory);
            
            $responseTimeMs = round((microtime(true) - $startTime) * 1000);
            
            if ($response['success']) {
                $this->logResponse($userId, $userMessage, $response['text'], $responseTimeMs);
                return $response['text'];
            } else {
                return $this->settings['fallback_message'];
            }
            
        } catch (Exception $e) {
            error_log("GeminiChat Error: " . $e->getMessage());
            return $this->settings['fallback_message'];
        }
    }
    
    /**
     * สร้าง Prompt ตามโหมด AI
     */
    private function buildPrompt($userMessage, $userId = null, $conversationHistory = [])
    {
        $mode = $this->getMode();
        
        switch ($mode) {
            case 'sales':
                return $this->buildSalesPrompt($userMessage, $userId, $conversationHistory);
            case 'support':
                return $this->buildSupportPrompt($userMessage, $userId, $conversationHistory);
            case 'pharmacist':
            default:
                return $this->buildPharmacistPrompt($userMessage, $userId, $conversationHistory);
        }
    }
    
    /**
     * Prompt สำหรับโหมดพนักงานขาย
     */
    private function buildSalesPrompt($userMessage, $userId = null, $conversationHistory = [])
    {
        $parts = [];
        
        // 1. บทบาทพนักงานขาย
        $salesRole = "
[บทบาท: พนักงานขายมืออาชีพ]
- บุคลิก: เป็นมิตร กระตือรือร้น พร้อมช่วยเหลือ ใช้ภาษาสุภาพ
- เป้าหมาย: ช่วยลูกค้าหาสินค้าที่ต้องการ แนะนำสินค้าที่เหมาะสม ปิดการขาย
- การตอบ: สั้น กระชับ ตรงประเด็น 1-3 ประโยค
- เทคนิค: ถามความต้องการ -> แนะนำสินค้า -> บอกราคา -> ชวนสั่งซื้อ
- สำคัญ: ตอบเฉพาะเรื่องสินค้าและบริการของร้าน ถ้าไม่รู้ให้บอกว่าจะให้เจ้าหน้าที่ติดต่อกลับ
";
        $parts[] = $salesRole;
        
        // 2. Custom Sales Prompt (ถ้ามี)
        if (!empty($this->settings['sales_prompt'])) {
            $parts[] = "คำแนะนำเพิ่มเติม:\n" . $this->settings['sales_prompt'];
        }
        
        // 3. System Prompt
        $systemPrompt = $this->settings['system_prompt'];
        if (!empty($systemPrompt)) {
            $parts[] = "บทบาทหลัก:\n" . $systemPrompt;
        }
        
        // 4. ข้อมูลธุรกิจ
        if (!empty($this->settings['business_info'])) {
            $parts[] = "ข้อมูลร้าน:\n" . $this->settings['business_info'];
        }
        
        // 5. โหลดสินค้า
        $products = $this->getProductsForAI();
        if ($products) {
            $parts[] = "รายการสินค้าที่มี:\n" . $products;
        }
        
        // 6. ข้อมูลลูกค้า
        if ($userId) {
            $customerInfo = $this->getCustomerInfo($userId);
            if ($customerInfo) {
                $parts[] = "ข้อมูลลูกค้า:\n" . $customerInfo;
            }
        }
        
        // 7. คำสั่งสุดท้าย
        $parts[] = "\n[คำสั่ง]: ตอบคำถามลูกค้าอย่างเป็นมิตร แนะนำสินค้าที่เหมาะสม บอกราคา และชวนสั่งซื้อ";
        
        return implode("\n\n", $parts);
    }
    
    /**
     * Prompt สำหรับโหมดซัพพอร์ต
     */
    private function buildSupportPrompt($userMessage, $userId = null, $conversationHistory = [])
    {
        $parts = [];
        
        $supportRole = "
[บทบาท: เจ้าหน้าที่ดูแลลูกค้า]
- บุคลิก: สุภาพ อดทน พร้อมช่วยเหลือ
- เป้าหมาย: แก้ไขปัญหาให้ลูกค้า ตอบคำถามเกี่ยวกับบริการ
- การตอบ: ชัดเจน เป็นขั้นตอน
";
        $parts[] = $supportRole;
        
        if (!empty($this->settings['system_prompt'])) {
            $parts[] = "บทบาทหลัก:\n" . $this->settings['system_prompt'];
        }
        
        if (!empty($this->settings['business_info'])) {
            $parts[] = "ข้อมูลร้าน:\n" . $this->settings['business_info'];
        }
        
        return implode("\n\n", $parts);
    }
    
    /**
     * Prompt สำหรับโหมดเภสัชกร (เดิม)
     */
    private function buildPharmacistPrompt($userMessage, $userId = null, $conversationHistory = [])
    {
        $parts = [];
        
        $fullHistory = $conversationHistory;
        $fullHistory[] = ['role' => 'user', 'content' => $userMessage];
        $extractedInfo = $this->extractInfoFromHistory($fullHistory);
        
        $proRules = "
[โปรโตคอล: เภสัชกรวิชาชีพ]
- บุคลิก: มีความน่าเชื่อถือ สุภาพ เป็นทางการ
- การตอบ: สั้น กระชับ 1-2 ประโยค
- Flow: แสดงความเห็นอกเห็นใจ -> ถามสิ่งที่ขาดทีละอย่าง -> สรุปและแนะนำยา
- สำคัญ: ห้ามถามซ้ำข้อมูลที่ทราบแล้ว
";
        $parts[] = $proRules;
        
        if (!empty($extractedInfo)) {
            $infoSection = "\n[ข้อมูลที่ทราบแล้ว]\n";
            foreach ($extractedInfo as $key => $value) {
                $infoSection .= "- {$key}: {$value}\n";
            }
            $parts[] = $infoSection;
        }
        
        $systemPrompt = $this->settings['system_prompt'] ?: 'คุณคือเภสัชกรวิชาชีพ ให้คำปรึกษาด้านสุขภาพเบื้องต้น';
        $parts[] = "บทบาทหลัก:\n" . $systemPrompt;
        
        if (!empty($this->settings['business_info'])) {
            $parts[] = "ข้อมูลร้านยา:\n" . $this->settings['business_info'];
        }
        
        $products = $this->getProductsForAI();
        if ($products) {
            $parts[] = "ยาที่มีในคลัง:\n" . $products;
        }
        
        if ($userId) {
            $medicalInfo = $this->getUserMedicalInfo($userId);
            if ($medicalInfo) {
                $parts[] = "ระเบียนคนไข้:\n- แพ้ยา: {$medicalInfo['drug_allergies']}\n- โรคประจำตัว: {$medicalInfo['medical_conditions']}";
            }
        }
        
        return implode("\n\n", $parts);
    }
    
    /**
     * โหลดสินค้าสำหรับ AI
     */
    private function getProductsForAI()
    {
        // ใช้ product_knowledge ถ้ามี
        if (!empty($this->settings['product_knowledge'])) {
            return $this->settings['product_knowledge'];
        }
        
        // Auto load จาก database
        if (!$this->settings['auto_load_products']) {
            return null;
        }
        
        try {
            $limit = intval($this->settings['product_load_limit'] ?? 50);
            $stmt = $this->db->prepare("
                SELECT name, sku, price, description 
                FROM business_items 
                WHERE is_active = 1 
                AND (line_account_id = ? OR line_account_id IS NULL)
                ORDER BY name ASC
                LIMIT ?
            ");
            $stmt->execute([$this->lineAccountId, $limit]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($products)) return null;
            
            $text = "";
            foreach ($products as $p) {
                $price = number_format($p['price'] ?? 0);
                $text .= "- {$p['name']}";
                if (!empty($p['sku'])) $text .= " (รหัส: {$p['sku']})";
                $text .= " - ฿{$price}";
                if (!empty($p['description'])) {
                    $desc = mb_substr($p['description'], 0, 50);
                    $text .= " | {$desc}";
                }
                $text .= "\n";
            }
            return $text;
            
        } catch (Exception $e) {
            error_log("getProductsForAI error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * ดึงข้อมูลลูกค้า
     */
    private function getCustomerInfo($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT display_name, phone, email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) return null;
            
            $info = "- ชื่อ: " . ($user['display_name'] ?: 'ไม่ระบุ');
            if (!empty($user['phone'])) $info .= "\n- โทร: {$user['phone']}";
            
            return $info;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * ระบบสกัดข้อมูลจากประวัติการคุย (สำหรับโหมดเภสัชกร)
     */
    private function extractInfoFromHistory($conversationHistory)
    {
        $info = [];
        $fullText = '';
        foreach ($conversationHistory as $msg) { $fullText .= $msg['content'] . ' '; }
        $fullText = mb_strtolower($fullText);
        
        // ค้นหาระยะเวลา
        if (preg_match('/(\d+)\s*วัน|(\d+)วัน|อาทิตย์|สัปดาห์/u', $fullText, $m)) {
            $info['ระยะเวลา'] = $m[0];
        }
        
        // ค้นหาอาการ
        $patterns = ['ปวดหัว', 'ปวดคอ', 'ปวดหลัง', 'ไข้', 'ไอ', 'เจ็บคอ', 'น้ำมูก', 'ท้องเสีย', 'ชา', 'หายใจไม่สะดวก'];
        $foundSymptoms = [];
        foreach ($patterns as $p) {
            if (mb_strpos($fullText, $p) !== false) $foundSymptoms[] = $p;
        }
        if (!empty($foundSymptoms)) $info['อาการ'] = implode(', ', $foundSymptoms);
        
        // วิเคราะห์ QA Pairs
        $lastQuestion = '';
        foreach ($conversationHistory as $msg) {
            if ($msg['role'] === 'assistant') {
                $lastQuestion = mb_strtolower($msg['content']);
            } else {
                $answer = mb_strtolower(trim($msg['content']));
                $isNegative = preg_match('/^(ไม่มี|ไม่|เปล่า|ไม่ครับ|ไม่ค่ะ|ไม่แพ้)/u', $answer);
                
                if ($isNegative && $lastQuestion) {
                    if (mb_strpos($lastQuestion, 'แพ้ยา') !== false) $info['แพ้ยา'] = 'ไม่มี';
                    if (mb_strpos($lastQuestion, 'โรคประจำตัว') !== false) $info['โรคประจำตัว'] = 'ไม่มี';
                    if (mb_strpos($lastQuestion, 'อาการอื่น') !== false || mb_strpos($lastQuestion, 'ร่วม') !== false) $info['อาการร่วม'] = 'ไม่มี';
                }
            }
        }
        
        if (!isset($info['แพ้ยา']) && mb_strpos($fullText, 'ไม่แพ้ยา') !== false) $info['แพ้ยา'] = 'ไม่มี';
        if (!isset($info['โรคประจำตัว']) && mb_strpos($fullText, 'ไม่มีโรค') !== false) $info['โรคประจำตัว'] = 'ไม่มี';
        
        return $info;
    }
    
    /**
     * เรียกใช้งาน Gemini API
     */
    private function callGeminiAPI($systemPrompt, $conversationHistory = [])
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'รับทราบค่ะ พร้อมให้บริการแล้ว']]];
        
        foreach ($conversationHistory as $msg) {
            $contents[] = [
                'role' => ($msg['role'] === 'user' ? 'user' : 'model'),
                'parts' => [['text' => $msg['content']]]
            ];
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => floatval($this->settings['temperature'] ?? 0.7),
                'maxOutputTokens' => intval($this->settings['max_tokens'] ?? 500),
                'topP' => 0.9,
                'topK' => 30
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim($result['candidates'][0]['content']['parts'][0]['text']);
            $text = preg_replace('/^\*\*|\*\*$/m', '', $text);
            return ['success' => true, 'text' => $text];
        }
        
        return ['success' => false];
    }

    private function logResponse($userId, $userMessage, $aiResponse, $responseTimeMs)
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO ai_chat_logs (line_account_id, user_id, user_message, ai_response, response_time_ms, model_used) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$this->lineAccountId, $userId, $userMessage, $aiResponse, $responseTimeMs, $this->model]);
        } catch (Exception $e) {
            // Create table if not exists
            try {
                $this->db->exec("CREATE TABLE IF NOT EXISTS ai_chat_logs (id INT AUTO_INCREMENT PRIMARY KEY, line_account_id INT, user_id INT, user_message TEXT, ai_response TEXT, response_time_ms INT, model_used VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
                $stmt = $this->db->prepare("INSERT INTO ai_chat_logs (line_account_id, user_id, user_message, ai_response, response_time_ms, model_used) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$this->lineAccountId, $userId, $userMessage, $aiResponse, $responseTimeMs, $this->model]);
            } catch (Exception $e2) {}
        }
    }
    
    public function getConversationHistory($userId, $limit = 10)
    {
        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN direction = 'incoming' THEN 'user' ELSE 'assistant' END as role, content FROM messages WHERE user_id = ? AND message_type = 'text' AND content != '' ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$userId, $limit]);
            return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { return []; }
    }
    
    public function getUserMedicalInfo($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT medical_conditions, drug_allergies FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return null; }
    }
    
    /**
     * Get settings for display
     */
    public function getSettings()
    {
        return $this->settings;
    }
}