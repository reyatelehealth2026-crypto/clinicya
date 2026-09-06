<?php
/**
 * Pharmacy Ghost Draft Service
 * 
 * Provides AI-powered predictive response drafting for pharmacy consultations.
 * Generates ghost drafts based on customer communication style, health profile,
 * and pharmaceutical context. Learns from pharmacist edits to improve predictions.
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
 */

class PharmacyGhostDraftService
{
    private $db;
    private $lineAccountId;
    private $apiKey;
    private $model;
    
    // API Configuration
    const DEFAULT_MODEL = 'gemini-2.5-flash';
    const API_BASE = GEMINI_API_BASE . '/v1beta/models/';
    
    // Draft generation timeout (seconds) - increased for slower API responses
    const DRAFT_TIMEOUT = 15;
    
    // Prescription drug keywords for disclaimer detection
    private $prescriptionKeywords = [
        // Thai
        'ยาอันตราย', 'ยาควบคุมพิเศษ', 'ยาแพทย์สั่ง', 'ต้องมีใบสั่งยา',
        'ยาปฏิชีวนะ', 'ยาฆ่าเชื้อ', 'ยาสเตียรอยด์', 'ยากดภูมิ',
        'ยานอนหลับ', 'ยาคลายกังวล', 'ยาแก้ปวดกลุ่มโอปิออยด์',
        'ยาลดความดัน', 'ยาเบาหวาน', 'ยาหัวใจ', 'ยาไทรอยด์',
        'ยาต้านไวรัส', 'ยาเคมีบำบัด', 'ยาฮอร์โมน',
        // English
        'prescription', 'controlled', 'antibiotic', 'steroid',
        'sedative', 'opioid', 'antihypertensive', 'antidiabetic',
        'cardiac', 'thyroid', 'antiviral', 'chemotherapy', 'hormone'
    ];
    
    // Common prescription drug names
    private $prescriptionDrugs = [
        // Antibiotics
        'amoxicillin', 'azithromycin', 'ciprofloxacin', 'doxycycline',
        'อะม็อกซีซิลลิน', 'อะซิโธรมัยซิน',
        // Blood pressure
        'amlodipine', 'losartan', 'enalapril', 'metoprolol',
        'แอมโลดิปีน', 'โลซาร์แทน',
        // Diabetes
        'metformin', 'glipizide', 'insulin',
        'เมทฟอร์มิน', 'อินซูลิน',
        // Pain (controlled)
        'tramadol', 'codeine', 'morphine',
        'ทรามาดอล', 'โคเดอีน',
        // Sedatives
        'diazepam', 'alprazolam', 'lorazepam',
        'ไดอะซีแพม', 'อัลปราโซแลม',
        // Steroids
        'prednisolone', 'dexamethasone',
        'เพรดนิโซโลน', 'เดกซาเมทาโซน'
    ];
    
    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID for multi-tenant support
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->loadApiKey();
    }
    
    /**
     * Load API key from database or config
     */
    private function loadApiKey(): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT gemini_api_key, model 
                FROM ai_settings 
                WHERE line_account_id = ? OR line_account_id IS NULL
                ORDER BY line_account_id DESC
                LIMIT 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['gemini_api_key'])) {
                $this->apiKey = $result['gemini_api_key'];
                $this->model = $result['model'] ?? self::DEFAULT_MODEL;
                return;
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        // Fallback to config constant
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            $this->apiKey = GEMINI_API_KEY;
        }
        
        $this->model = self::DEFAULT_MODEL;
    }
    
    /**
     * Check if API is configured
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }


    /**
     * Generate predictive pharmacy consultation response
     * 
     * Requirements 6.1: Generate ghost draft with appropriate drug recommendations within 2 seconds
     * Requirements 6.2: Display draft as faded text in input field
     * 
     * @param int $userId Customer user ID
     * @param string $lastMessage Last customer message
     * @param array $context Additional context (health profile, stage, etc.)
     * @return array ['draft' => string, 'confidence' => float, 'alternatives' => array, 'disclaimer' => string|null]
     */
    public function generateDraft(int $userId, string $lastMessage, array $context = []): array
    {
        $startTime = microtime(true);
        
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'draft' => null,
                'confidence' => 0.0,
                'alternatives' => [],
                'disclaimer' => null
            ];
        }
        
        try {
            // Get customer health profile if not provided
            $healthProfile = $context['healthProfile'] ?? $this->getCustomerHealthProfile($userId);
            
            // Get communication style
            $communicationType = $healthProfile['communicationType'] ?? 'A';
            $draftStyle = $context['draftStyle'] ?? $this->getDraftStyleForType($communicationType);
            
            // Get conversation history for context
            $conversationHistory = $context['conversationHistory'] ?? $this->getConversationHistory($userId, 5);
            
            // Get previous successful drafts for this customer
            $learningData = $this->getLearningData($userId, 5);
            
            // Build the prompt
            $prompt = $this->buildDraftPrompt($lastMessage, $healthProfile, $draftStyle, $conversationHistory, $learningData, $context);
            
            // Call AI API with timeout
            $response = $this->callAIWithTimeout($prompt, self::DRAFT_TIMEOUT);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Failed to generate draft',
                    'draft' => null,
                    'confidence' => 0.0,
                    'alternatives' => [],
                    'disclaimer' => null,
                    'generationTimeMs' => round((microtime(true) - $startTime) * 1000)
                ];
            }
            
            // Parse the response
            $draftData = $this->parseDraftResponse($response['text']);
            
            // Extract mentioned drugs for disclaimer check
            $mentionedDrugs = $this->extractMentionedDrugs($draftData['draft']);
            
            // Add disclaimer if prescription drugs mentioned
            // Property 12: Prescription Drug Disclaimer
            $disclaimer = $this->addDisclaimer($draftData['draft'], $mentionedDrugs);
            
            // Calculate confidence based on learning history
            $confidence = $this->calculateDraftConfidence($userId, $draftData['confidence'] ?? 0.7);
            
            $generationTimeMs = round((microtime(true) - $startTime) * 1000);
            
            return [
                'success' => true,
                'draft' => $draftData['draft'],
                'confidence' => round($confidence, 2),
                'alternatives' => $draftData['alternatives'] ?? [],
                'disclaimer' => $disclaimer !== $draftData['draft'] ? $this->getDisclaimerText() : null,
                'mentionedDrugs' => $mentionedDrugs,
                'communicationType' => $communicationType,
                'draftStyle' => $draftStyle,
                'generationTimeMs' => $generationTimeMs,
                'withinTimeout' => $generationTimeMs <= (self::DRAFT_TIMEOUT * 1000)
            ];
            
        } catch (Exception $e) {
            error_log("PharmacyGhostDraft generateDraft error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'draft' => null,
                'confidence' => 0.0,
                'alternatives' => [],
                'disclaimer' => null,
                'generationTimeMs' => round((microtime(true) - $startTime) * 1000)
            ];
        }
    }
    
    /**
     * Build prompt for draft generation
     * @param string $lastMessage Customer's last message
     * @param array $healthProfile Customer health profile
     * @param array $draftStyle Draft style settings
     * @param array $conversationHistory Recent conversation
     * @param array $learningData Previous successful drafts
     * @param array $context Additional context
     * @return string Prompt
     */
    private function buildDraftPrompt(
        string $lastMessage,
        array $healthProfile,
        array $draftStyle,
        array $conversationHistory,
        array $learningData,
        array $context
    ): string {
        $parts = [];
        
        // Role definition
        $parts[] = "[บทบาท: เภสัชกรผู้เชี่ยวชาญ]
คุณกำลังช่วยเภสัชกรร่างคำตอบสำหรับลูกค้า กรุณาร่างคำตอบที่เหมาะสมตามสไตล์การสื่อสารของลูกค้า";
        
        // Communication style instructions
        $styleInstructions = $this->getStyleInstructions($draftStyle);
        $parts[] = "[สไตล์การตอบ]\n" . $styleInstructions;
        
        // Customer health profile
        if (!empty($healthProfile)) {
            $profileText = "[ข้อมูลลูกค้า]\n";
            
            if (!empty($healthProfile['allergies'])) {
                $allergyNames = array_map(function($a) { 
                    return is_array($a) ? ($a['name'] ?? '') : $a; 
                }, $healthProfile['allergies']);
                $profileText .= "- แพ้ยา: " . implode(', ', array_filter($allergyNames)) . "\n";
            }
            
            if (!empty($healthProfile['medications'])) {
                $medNames = array_map(function($m) { 
                    return is_array($m) ? ($m['name'] ?? '') : $m; 
                }, $healthProfile['medications']);
                $profileText .= "- ยาที่ใช้อยู่: " . implode(', ', array_filter($medNames)) . "\n";
            }
            
            if (!empty($healthProfile['conditions'])) {
                $conditionNames = is_array($healthProfile['conditions']) 
                    ? implode(', ', $healthProfile['conditions']) 
                    : $healthProfile['conditions'];
                $profileText .= "- โรคประจำตัว: " . $conditionNames . "\n";
            }
            
            $parts[] = $profileText;
        }
        
        // Conversation history
        if (!empty($conversationHistory)) {
            $historyText = "[ประวัติการสนทนา]\n";
            foreach ($conversationHistory as $msg) {
                $role = ($msg['role'] ?? 'user') === 'user' ? 'ลูกค้า' : 'เภสัชกร';
                $historyText .= "- {$role}: " . mb_substr($msg['content'] ?? '', 0, 100) . "\n";
            }
            $parts[] = $historyText;
        }
        
        // Learning from previous successful drafts
        if (!empty($learningData)) {
            $learningText = "[ตัวอย่างคำตอบที่ดี]\n";
            foreach (array_slice($learningData, 0, 3) as $example) {
                if ($example['was_accepted']) {
                    $learningText .= "- คำถาม: " . mb_substr($example['customer_message'], 0, 50) . "\n";
                    $learningText .= "  คำตอบ: " . mb_substr($example['pharmacist_final'], 0, 100) . "\n";
                }
            }
            $parts[] = $learningText;
        }
        
        // Current stage context
        if (!empty($context['stage'])) {
            $parts[] = "[ขั้นตอนปัจจุบัน]: " . $this->getStageLabel($context['stage']);
        }
        
        // Symptoms if detected
        if (!empty($context['symptoms'])) {
            $parts[] = "[อาการที่ตรวจพบ]: " . implode(', ', $context['symptoms']);
        }
        
        // The customer's message
        $parts[] = "[ข้อความลูกค้า]\n" . $lastMessage;
        
        // Output format
        $parts[] = "[คำสั่ง]
กรุณาร่างคำตอบในรูปแบบ JSON ดังนี้:
{
    \"draft\": \"คำตอบที่ร่างไว้\",
    \"confidence\": 0.0-1.0,
    \"alternatives\": [\"ทางเลือกอื่น 1\", \"ทางเลือกอื่น 2\"],
    \"mentionedDrugs\": [\"ชื่อยาที่กล่าวถึง\"]
}

ข้อควรระวัง:
- ตอบตามสไตล์ที่กำหนด (ความยาว, น้ำเสียง)
- หลีกเลี่ยงยาที่ลูกค้าแพ้
- ระวังปฏิกิริยากับยาที่ใช้อยู่
- ถ้าเป็นยาแพทย์สั่ง ให้แนะนำพบแพทย์";
        
        return implode("\n\n", $parts);
    }


    /**
     * Get style instructions based on draft style
     * @param array $draftStyle Draft style settings
     * @return string Instructions
     */
    private function getStyleInstructions(array $draftStyle): string
    {
        $type = $draftStyle['type'] ?? 'A';
        $maxWords = $draftStyle['maxWords'] ?? 50;
        $tone = $draftStyle['toneTh'] ?? 'มืออาชีพ';
        
        switch ($type) {
            case 'A': // Direct
                return "- ตอบสั้น กระชับ ไม่เกิน {$maxWords} คำ
- น้ำเสียง: {$tone}
- บอกชื่อยา ราคา วิธีใช้ ชัดเจน
- ไม่ต้องอธิบายรายละเอียดมาก
- เสนอทางเลือกไม่เกิน 2-3 ตัว";
                
            case 'B': // Concerned
                return "- ตอบอย่างเห็นอกเห็นใจ ไม่เกิน {$maxWords} คำ
- น้ำเสียง: {$tone}
- แสดงความเข้าใจและห่วงใย
- อธิบายความปลอดภัยของยา
- ให้ความมั่นใจว่าอาการจะดีขึ้น
- ใช้ emoji ได้ตามเหมาะสม 🙏😊";
                
            case 'C': // Detail-oriented
                return "- ให้ข้อมูลครบถ้วน ละเอียด ไม่เกิน {$maxWords} คำ
- น้ำเสียง: {$tone}
- เปรียบเทียบยาหลายตัว
- อธิบายกลไกการออกฤทธิ์
- แนบข้อมูลทางวิทยาศาสตร์ถ้ามี";
                
            default:
                return "- ตอบอย่างมืออาชีพ ไม่เกิน {$maxWords} คำ";
        }
    }
    
    /**
     * Get stage label in Thai
     * @param string $stage Stage identifier
     * @return string Label
     */
    private function getStageLabel(string $stage): string
    {
        $labels = [
            'symptom_assessment' => 'ประเมินอาการ',
            'drug_recommendation' => 'แนะนำยา',
            'purchase' => 'ตัดสินใจซื้อ',
            'follow_up' => 'ติดตามผล'
        ];
        return $labels[$stage] ?? $stage;
    }
    
    /**
     * Parse draft response from AI
     * @param string $responseText AI response text
     * @return array Parsed draft data
     */
    private function parseDraftResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($responseText);
        
        if ($json && isset($json['draft'])) {
            return [
                'draft' => $json['draft'],
                'confidence' => (float)($json['confidence'] ?? 0.7),
                'alternatives' => $json['alternatives'] ?? [],
                'mentionedDrugs' => $json['mentionedDrugs'] ?? []
            ];
        }
        
        // Fallback: use the raw text as draft
        return [
            'draft' => trim($responseText),
            'confidence' => 0.5,
            'alternatives' => [],
            'mentionedDrugs' => []
        ];
    }
    
    /**
     * Extract JSON from response text
     * @param string $text Response text
     * @return array|null Parsed JSON or null
     */
    private function extractJson(string $text): ?array
    {
        // Try to find JSON in the response
        if (preg_match('/\{[\s\S]*\}/u', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        
        // Try direct parse
        $json = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }
        
        return null;
    }
    
    /**
     * Learn from pharmacist's edit to improve future predictions
     * 
     * Requirements 6.5: Learn from pharmacist edits to improve future pharmaceutical recommendations
     * Property 11: Ghost Draft Learning Stores Edit Data
     * 
     * @param int $userId Customer user ID
     * @param string $originalDraft AI-generated draft
     * @param string $finalMessage Pharmacist's final message
     * @param array $context Additional context (optional)
     * @return bool Success
     */
    public function learnFromEdit(int $userId, string $originalDraft, string $finalMessage, array $context = []): bool
    {
        try {
            // Calculate edit distance (Levenshtein)
            $editDistance = $this->calculateEditDistance($originalDraft, $finalMessage);
            
            // Determine if draft was accepted (low edit distance = accepted)
            // If edit distance is less than 20% of original length, consider it accepted
            $originalLength = mb_strlen($originalDraft);
            $wasAccepted = $originalLength > 0 && ($editDistance / $originalLength) < 0.2;
            
            // Extract mentioned drugs from final message
            $mentionedDrugs = $this->extractMentionedDrugs($finalMessage);
            
            // Get customer message from context or recent messages
            $customerMessage = $context['customerMessage'] ?? $this->getLastCustomerMessage($userId);
            
            // Prepare context JSON
            $contextJson = json_encode([
                'stage' => $context['stage'] ?? null,
                'healthProfile' => $context['healthProfile'] ?? null,
                'symptoms' => $context['symptoms'] ?? null,
                'communicationType' => $context['communicationType'] ?? null
            ], JSON_UNESCAPED_UNICODE);
            
            // Store in pharmacy_ghost_learning table
            $stmt = $this->db->prepare("
                INSERT INTO pharmacy_ghost_learning 
                (user_id, customer_message, ai_draft, pharmacist_final, edit_distance, was_accepted, context, mentioned_drugs)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $userId,
                $customerMessage,
                $originalDraft,
                $finalMessage,
                $editDistance,
                $wasAccepted ? 1 : 0,
                $contextJson,
                json_encode($mentionedDrugs, JSON_UNESCAPED_UNICODE)
            ]);
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("PharmacyGhostDraft learnFromEdit error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate Levenshtein edit distance between two strings
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return int Edit distance
     */
    private function calculateEditDistance(string $str1, string $str2): int
    {
        // Use PHP's built-in levenshtein for ASCII
        // For Thai/Unicode, we need a custom implementation
        if (mb_strlen($str1) === strlen($str1) && mb_strlen($str2) === strlen($str2)) {
            // ASCII strings - use built-in (limited to 255 chars)
            if (strlen($str1) <= 255 && strlen($str2) <= 255) {
                return levenshtein($str1, $str2);
            }
        }
        
        // Unicode-aware Levenshtein
        return $this->unicodeLevenshtein($str1, $str2);
    }
    
    /**
     * Unicode-aware Levenshtein distance calculation
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return int Edit distance
     */
    private function unicodeLevenshtein(string $str1, string $str2): int
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        // Limit for performance
        if ($len1 > 500 || $len2 > 500) {
            // Approximate for long strings
            return abs($len1 - $len2) + (int)(min($len1, $len2) * 0.3);
        }
        
        if ($len1 === 0) return $len2;
        if ($len2 === 0) return $len1;
        
        $matrix = [];
        
        for ($i = 0; $i <= $len1; $i++) {
            $matrix[$i] = [$i];
        }
        for ($j = 0; $j <= $len2; $j++) {
            $matrix[0][$j] = $j;
        }
        
        for ($i = 1; $i <= $len1; $i++) {
            for ($j = 1; $j <= $len2; $j++) {
                $char1 = mb_substr($str1, $i - 1, 1);
                $char2 = mb_substr($str2, $j - 1, 1);
                
                $cost = ($char1 === $char2) ? 0 : 1;
                
                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,      // deletion
                    $matrix[$i][$j - 1] + 1,      // insertion
                    $matrix[$i - 1][$j - 1] + $cost // substitution
                );
            }
        }
        
        return $matrix[$len1][$len2];
    }


    /**
     * Add appropriate disclaimer for prescription drugs
     * 
     * Requirements 6.6: Add disclaimer about consulting a doctor for prescription drugs
     * Property 12: Prescription Drug Disclaimer
     * 
     * @param string $draft Draft message
     * @param array $mentionedDrugs Drugs mentioned in draft
     * @return string Draft with disclaimer if needed
     */
    public function addDisclaimer(string $draft, array $mentionedDrugs): string
    {
        // Check if any mentioned drug is a prescription drug
        $hasPrescriptionDrug = $this->containsPrescriptionDrug($draft, $mentionedDrugs);
        
        if ($hasPrescriptionDrug) {
            $disclaimer = $this->getDisclaimerText();
            
            // Don't add if disclaimer already exists
            if (mb_strpos($draft, 'ปรึกษาแพทย์') === false && 
                mb_strpos($draft, 'พบแพทย์') === false &&
                mb_strpos($draft, 'ใบสั่งยา') === false) {
                return $draft . "\n\n" . $disclaimer;
            }
        }
        
        return $draft;
    }
    
    /**
     * Check if draft or mentioned drugs contain prescription drugs
     * @param string $draft Draft text
     * @param array $mentionedDrugs List of mentioned drugs
     * @return bool True if prescription drug found
     */
    public function containsPrescriptionDrug(string $draft, array $mentionedDrugs): bool
    {
        $textToCheck = mb_strtolower($draft);
        
        // Check for prescription keywords in draft
        foreach ($this->prescriptionKeywords as $keyword) {
            if (mb_stripos($textToCheck, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }
        
        // Check for known prescription drug names
        foreach ($this->prescriptionDrugs as $drug) {
            if (mb_stripos($textToCheck, mb_strtolower($drug)) !== false) {
                return true;
            }
        }
        
        // Check mentioned drugs list
        foreach ($mentionedDrugs as $drug) {
            $drugLower = mb_strtolower(is_array($drug) ? ($drug['name'] ?? '') : $drug);
            
            foreach ($this->prescriptionDrugs as $prescriptionDrug) {
                if (mb_stripos($drugLower, mb_strtolower($prescriptionDrug)) !== false) {
                    return true;
                }
            }
        }
        
        // Check database for prescription flag
        if (!empty($mentionedDrugs)) {
            try {
                $drugNames = array_map(function($d) {
                    return is_array($d) ? ($d['name'] ?? '') : $d;
                }, $mentionedDrugs);
                
                $placeholders = implode(',', array_fill(0, count($drugNames), '?'));
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count 
                    FROM business_items 
                    WHERE (name IN ({$placeholders}) OR sku IN ({$placeholders}))
                    AND is_prescription = 1
                ");
                $params = array_merge($drugNames, $drugNames);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['count'] > 0) {
                    return true;
                }
            } catch (PDOException $e) {
                // Column might not exist
            }
        }
        
        return false;
    }
    
    /**
     * Get disclaimer text
     * @return string Disclaimer
     */
    private function getDisclaimerText(): string
    {
        return "⚠️ หมายเหตุ: ยานี้เป็นยาที่ต้องใช้ตามคำสั่งแพทย์ กรุณาปรึกษาแพทย์หรือเภสัชกรก่อนใช้ยา";
    }
    
    /**
     * Get prediction confidence based on learning history
     * 
     * @param int $userId Customer user ID
     * @return float Confidence score 0-1
     */
    public function getPredictionConfidence(int $userId): float
    {
        try {
            // Get learning statistics for this customer
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_drafts,
                    SUM(was_accepted) as accepted_drafts,
                    AVG(edit_distance) as avg_edit_distance
                FROM pharmacy_ghost_learning 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stats || $stats['total_drafts'] == 0) {
                // No history - return base confidence
                return 0.5;
            }
            
            $totalDrafts = (int)$stats['total_drafts'];
            $acceptedDrafts = (int)$stats['accepted_drafts'];
            $avgEditDistance = (float)$stats['avg_edit_distance'];
            
            // Calculate acceptance rate
            $acceptanceRate = $acceptedDrafts / $totalDrafts;
            
            // Factor in edit distance (lower is better)
            // Normalize: assume avg message length of 100 chars
            $editDistanceFactor = max(0, 1 - ($avgEditDistance / 100));
            
            // Combine factors
            // Weight: 60% acceptance rate, 40% edit distance factor
            $confidence = ($acceptanceRate * 0.6) + ($editDistanceFactor * 0.4);
            
            // Boost confidence if we have more data
            if ($totalDrafts >= 10) {
                $confidence = min(1.0, $confidence * 1.1);
            }
            
            return round(max(0.0, min(1.0, $confidence)), 2);
            
        } catch (PDOException $e) {
            error_log("PharmacyGhostDraft getPredictionConfidence error: " . $e->getMessage());
            return 0.5;
        }
    }
    
    /**
     * Calculate draft confidence combining AI confidence and learning history
     * @param int $userId User ID
     * @param float $aiConfidence AI-reported confidence
     * @return float Combined confidence
     */
    private function calculateDraftConfidence(int $userId, float $aiConfidence): float
    {
        $learningConfidence = $this->getPredictionConfidence($userId);
        
        // Weight: 50% AI confidence, 50% learning confidence
        return ($aiConfidence * 0.5) + ($learningConfidence * 0.5);
    }
    
    /**
     * Extract mentioned drugs from text
     * @param string $text Text to analyze
     * @return array List of drug names
     */
    private function extractMentionedDrugs(string $text): array
    {
        $drugs = [];
        
        try {
            // Get drug names from database
            $stmt = $this->db->prepare("
                SELECT name, sku 
                FROM business_items 
                WHERE is_active = 1 
                AND (line_account_id = ? OR line_account_id IS NULL)
                LIMIT 500
            ");
            $stmt->execute([$this->lineAccountId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $textLower = mb_strtolower($text);
            
            foreach ($products as $product) {
                $nameLower = mb_strtolower($product['name']);
                if (mb_strpos($textLower, $nameLower) !== false) {
                    $drugs[] = [
                        'name' => $product['name'],
                        'sku' => $product['sku']
                    ];
                }
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        // Also check for common drug patterns
        $commonDrugs = [
            'พาราเซตามอล', 'paracetamol', 'ไทลินอล', 'tylenol',
            'ไอบูโพรเฟน', 'ibuprofen', 'แอสไพริน', 'aspirin',
            'ยาแก้แพ้', 'antihistamine', 'ยาแก้ไอ', 'ยาลดน้ำมูก',
            'ยาธาตุน้ำขาว', 'ยาธาตุน้ำแดง', 'ยาหม่อง', 'ยาดม'
        ];
        
        $textLower = mb_strtolower($text);
        foreach ($commonDrugs as $drug) {
            if (mb_stripos($textLower, mb_strtolower($drug)) !== false) {
                // Check if not already in list
                $exists = false;
                foreach ($drugs as $d) {
                    if (mb_stripos($d['name'], $drug) !== false) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $drugs[] = ['name' => $drug, 'sku' => null];
                }
            }
        }
        
        return $drugs;
    }


    /**
     * Get customer health profile
     * @param int $userId User ID
     * @return array Health profile
     */
    private function getCustomerHealthProfile(int $userId): array
    {
        $profile = [
            'allergies' => [],
            'medications' => [],
            'conditions' => [],
            'communicationType' => 'A'
        ];
        
        try {
            // Get from users table
            $stmt = $this->db->prepare("
                SELECT drug_allergies, current_medications, medical_conditions 
                FROM users 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                if (!empty($user['drug_allergies'])) {
                    $profile['allergies'] = array_map('trim', preg_split('/[,\n]+/', $user['drug_allergies']));
                }
                if (!empty($user['current_medications'])) {
                    $profile['medications'] = array_map('trim', preg_split('/[,\n]+/', $user['current_medications']));
                }
                if (!empty($user['medical_conditions'])) {
                    $profile['conditions'] = array_map('trim', preg_split('/[,\n]+/', $user['medical_conditions']));
                }
            }
            
            // Get communication type from customer_health_profiles
            $stmt = $this->db->prepare("
                SELECT communication_type, confidence 
                FROM customer_health_profiles 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $healthProfile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($healthProfile && !empty($healthProfile['communication_type'])) {
                $profile['communicationType'] = $healthProfile['communication_type'];
                $profile['confidence'] = (float)$healthProfile['confidence'];
            }
            
        } catch (PDOException $e) {
            // Return default profile
        }
        
        return $profile;
    }
    
    /**
     * Get draft style for communication type
     * @param string $type Communication type (A, B, C)
     * @return array Draft style settings
     */
    private function getDraftStyleForType(string $type): array
    {
        switch ($type) {
            case 'A': // Direct
                return [
                    'type' => 'A',
                    'typeName' => 'Direct',
                    'typeNameTh' => 'ตรงประเด็น',
                    'maxWords' => 50,
                    'useEmoji' => false,
                    'includeDetails' => false,
                    'tone' => 'professional',
                    'toneTh' => 'มืออาชีพ'
                ];
                
            case 'B': // Concerned
                return [
                    'type' => 'B',
                    'typeName' => 'Concerned',
                    'typeNameTh' => 'ห่วงใย',
                    'maxWords' => 150,
                    'useEmoji' => true,
                    'includeDetails' => true,
                    'tone' => 'empathetic',
                    'toneTh' => 'เห็นอกเห็นใจ'
                ];
                
            case 'C': // Detail-oriented
                return [
                    'type' => 'C',
                    'typeName' => 'Detail-oriented',
                    'typeNameTh' => 'ใส่ใจรายละเอียด',
                    'maxWords' => 300,
                    'useEmoji' => false,
                    'includeDetails' => true,
                    'tone' => 'informative',
                    'toneTh' => 'ให้ข้อมูล'
                ];
                
            default:
                return $this->getDraftStyleForType('A');
        }
    }
    
    /**
     * Get conversation history for a user
     * @param int $userId User ID
     * @param int $limit Number of messages
     * @return array Conversation history
     */
    private function getConversationHistory(int $userId, int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    CASE WHEN direction = 'incoming' THEN 'user' ELSE 'assistant' END as role,
                    content
                FROM messages 
                WHERE user_id = ? AND message_type = 'text' AND content != ''
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get learning data for a user
     * @param int $userId User ID
     * @param int $limit Number of records
     * @return array Learning data
     */
    private function getLearningData(int $userId, int $limit = 5): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT customer_message, ai_draft, pharmacist_final, was_accepted, edit_distance
                FROM pharmacy_ghost_learning 
                WHERE user_id = ? AND was_accepted = 1
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get last customer message
     * @param int $userId User ID
     * @return string Last message
     */
    private function getLastCustomerMessage(int $userId): string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT content 
                FROM messages 
                WHERE user_id = ? AND direction = 'incoming' AND message_type = 'text'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['content'] ?? '';
        } catch (PDOException $e) {
            return '';
        }
    }
    
    /**
     * Call AI API with timeout
     * @param string $prompt Prompt text
     * @param int $timeout Timeout in seconds
     * @return array Response
     */
    private function callAIWithTimeout(string $prompt, int $timeout): array
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 500,
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
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($curlError || $curlErrno) {
            return [
                'success' => false,
                'error' => "Connection error: [{$curlErrno}] {$curlError}"
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $result['error']['message'] ?? 'Unknown API error';
            return [
                'success' => false,
                'error' => "API Error ($httpCode): $errorMsg"
            ];
        }
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'text' => trim($result['candidates'][0]['content']['parts'][0]['text'])
            ];
        }
        
        return [
            'success' => false,
            'error' => 'No response from API'
        ];
    }
    
    /**
     * Get draft statistics for analytics
     * @param int|null $pharmacistId Pharmacist ID (optional)
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public function getDraftStatistics(?int $pharmacistId = null, int $days = 30): array
    {
        try {
            $whereClause = "created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_drafts,
                    SUM(was_accepted) as accepted_drafts,
                    AVG(edit_distance) as avg_edit_distance,
                    COUNT(DISTINCT user_id) as unique_customers
                FROM pharmacy_ghost_learning 
                WHERE {$whereClause}
            ");
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $totalDrafts = (int)($stats['total_drafts'] ?? 0);
            $acceptedDrafts = (int)($stats['accepted_drafts'] ?? 0);
            
            return [
                'totalDrafts' => $totalDrafts,
                'acceptedDrafts' => $acceptedDrafts,
                'acceptanceRate' => $totalDrafts > 0 ? round(($acceptedDrafts / $totalDrafts) * 100, 1) : 0,
                'avgEditDistance' => round((float)($stats['avg_edit_distance'] ?? 0), 1),
                'uniqueCustomers' => (int)($stats['unique_customers'] ?? 0),
                'period' => $days . ' days'
            ];
            
        } catch (PDOException $e) {
            return [
                'totalDrafts' => 0,
                'acceptedDrafts' => 0,
                'acceptanceRate' => 0,
                'avgEditDistance' => 0,
                'uniqueCustomers' => 0,
                'period' => $days . ' days',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Clear learning data for a user (for privacy/GDPR)
     * @param int $userId User ID
     * @return bool Success
     */
    public function clearLearningData(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM pharmacy_ghost_learning WHERE user_id = ?");
            return $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("PharmacyGhostDraft clearLearningData error: " . $e->getMessage());
            return false;
        }
    }
}
