<?php
/**
 * Consultation Analyzer Service
 * 
 * Provides consultation stage detection, context-aware widgets,
 * quick actions, and urgency detection for pharmacy consultations.
 * 
 * Requirements: 9.1, 9.2, 9.3, 9.4, 9.5
 */

class ConsultationAnalyzerService
{
    private $db;
    private $lineAccountId;
    
    // Consultation stages
    const STAGE_SYMPTOM = 'symptom_assessment';
    const STAGE_RECOMMENDATION = 'drug_recommendation';
    const STAGE_PURCHASE = 'purchase';
    const STAGE_FOLLOWUP = 'follow_up';
    
    // Stage detection keywords
    private $stageKeywords = [
        'symptom_assessment' => [
            'positive' => [
                // Thai
                'ปวด', 'เจ็บ', 'ไข้', 'ไอ', 'คัน', 'ผื่น', 'บวม', 'อักเสบ',
                'ท้องเสีย', 'ท้องผูก', 'คลื่นไส้', 'อาเจียน', 'เวียนหัว',
                'อ่อนเพลีย', 'นอนไม่หลับ', 'แพ้', 'ระคายเคือง',
                'เป็นอะไร', 'อาการ', 'รู้สึก', 'ไม่สบาย',
                // English
                'pain', 'hurt', 'fever', 'cough', 'itch', 'rash', 'swelling',
                'diarrhea', 'constipation', 'nausea', 'vomit', 'dizzy',
                'tired', 'insomnia', 'allergy', 'symptom', 'feel sick'
            ],
            'negative' => ['ซื้อ', 'สั่ง', 'ราคา', 'จ่าย', 'ส่ง', 'buy', 'order', 'price']
        ],
        'drug_recommendation' => [
            'positive' => [
                // Thai
                'ยา', 'แนะนำ', 'ตัวไหน', 'อะไรดี', 'ใช้อะไร', 'กินอะไร',
                'เปรียบเทียบ', 'ต่างกัน', 'ดีกว่า', 'ผลข้างเคียง',
                'วิธีใช้', 'ขนาด', 'ปริมาณ', 'กี่เม็ด', 'กี่ครั้ง',
                // English
                'drug', 'medicine', 'recommend', 'which one', 'what should',
                'compare', 'difference', 'better', 'side effect',
                'dosage', 'how to use', 'how many'
            ],
            'negative' => ['ซื้อ', 'สั่ง', 'จ่าย', 'buy', 'order', 'pay']
        ],
        'purchase' => [
            'positive' => [
                // Thai
                'ซื้อ', 'สั่ง', 'เอา', 'ต้องการ', 'ราคา', 'เท่าไหร่',
                'จ่าย', 'โอน', 'ชำระ', 'ส่ง', 'จัดส่ง', 'รับ',
                'ลด', 'ส่วนลด', 'โปรโมชั่น', 'ตะกร้า', 'ออเดอร์',
                // English
                'buy', 'order', 'want', 'price', 'how much',
                'pay', 'transfer', 'delivery', 'ship', 'receive',
                'discount', 'promotion', 'cart', 'checkout'
            ],
            'negative' => []
        ],
        'follow_up' => [
            'positive' => [
                // Thai
                'ดีขึ้น', 'หาย', 'ไม่หาย', 'ยังไม่ดี', 'เหมือนเดิม',
                'กินหมด', 'ใช้หมด', 'เติม', 'ซื้อเพิ่ม', 'ต่อ',
                'ผลเป็นอย่างไร', 'รายงาน', 'อัพเดท',
                // English
                'better', 'recovered', 'not better', 'same', 'still',
                'finished', 'refill', 'more', 'continue',
                'result', 'update', 'follow up'
            ],
            'negative' => []
        ]
    ];
    
    // Urgent symptom keywords
    private $urgentKeywords = [
        // Thai
        'หายใจลำบาก', 'หายใจไม่ออก', 'แน่นหน้าอก', 'เจ็บหน้าอก',
        'ชัก', 'หมดสติ', 'เลือดออก', 'เลือดไหล', 'อาเจียนเป็นเลือด',
        'ไข้สูงมาก', 'ปวดรุนแรง', 'บวมมาก', 'แพ้รุนแรง',
        'ผื่นทั้งตัว', 'ปากบวม', 'ลิ้นบวม', 'กลืนลำบาก',
        'ตาพร่า', 'มองไม่เห็น', 'อัมพาต', 'ชา', 'อ่อนแรง',
        // English
        'difficulty breathing', 'cant breathe', 'chest pain', 'chest tight',
        'seizure', 'unconscious', 'bleeding', 'vomiting blood',
        'high fever', 'severe pain', 'severe swelling', 'severe allergy',
        'rash all over', 'swollen lips', 'swollen tongue', 'difficulty swallowing',
        'blurred vision', 'cant see', 'paralysis', 'numbness', 'weakness',
        'emergency', 'urgent', 'critical'
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
    }


    /**
     * Detect current consultation stage
     * 
     * Requirements 9.1, 9.2, 9.3: Different actions based on consultation stage
     * 
     * @param int $userId Customer user ID
     * @return array ['stage' => string, 'confidence' => float, 'signals' => array]
     */
    public function detectStage(int $userId): array
    {
        // Get recent messages for analysis
        $messages = $this->getRecentMessages($userId, 10);
        
        if (empty($messages)) {
            return $this->createStageResult(self::STAGE_SYMPTOM, 0.3, ['no_messages']);
        }
        
        // Analyze messages to determine stage
        $scores = $this->analyzeStagePatterns($messages);
        
        // Determine stage based on highest score
        $stage = $this->determineStage($scores);
        
        // Calculate confidence
        $confidence = $this->calculateStageConfidence($scores, $stage);
        
        // Collect signals that led to this determination
        $signals = $this->collectSignals($messages, $stage);
        
        // Check for urgent symptoms
        $hasUrgentSymptoms = $this->hasUrgentSymptoms($messages);
        
        // Save/update stage in database
        $this->saveStage($userId, $stage, $confidence, $signals, $hasUrgentSymptoms);
        
        return [
            'stage' => $stage,
            'stageLabel' => $this->getStageLabel($stage),
            'stageLabelTh' => $this->getStageLabelTh($stage),
            'confidence' => round($confidence, 2),
            'signals' => $signals,
            'hasUrgentSymptoms' => $hasUrgentSymptoms,
            'scores' => $scores,
            'messageCount' => count($messages)
        ];
    }
    
    /**
     * Get recent messages for a user
     * @param int $userId User ID
     * @param int $limit Number of messages to retrieve
     * @return array Messages
     */
    private function getRecentMessages(int $userId, int $limit = 10): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT content, message_type, direction, created_at
                FROM messages 
                WHERE user_id = ? AND message_type = 'text'
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getRecentMessages error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Analyze message patterns to score each stage
     * @param array $messages Messages to analyze
     * @return array Scores for each stage
     */
    private function analyzeStagePatterns(array $messages): array
    {
        $scores = [
            self::STAGE_SYMPTOM => 0.0,
            self::STAGE_RECOMMENDATION => 0.0,
            self::STAGE_PURCHASE => 0.0,
            self::STAGE_FOLLOWUP => 0.0
        ];
        
        if (empty($messages)) {
            return $scores;
        }
        
        // Weight recent messages more heavily
        $totalMessages = count($messages);
        
        foreach ($messages as $index => $message) {
            $content = $message['content'] ?? '';
            $isIncoming = ($message['direction'] ?? 'incoming') === 'incoming';
            
            // Weight: most recent = 1.0, oldest = 0.5
            $weight = 1.0 - ($index / ($totalMessages * 2));
            
            // Only analyze incoming (customer) messages for stage detection
            if (!$isIncoming) {
                continue;
            }
            
            // Score based on keywords
            foreach ($this->stageKeywords as $stage => $keywords) {
                foreach ($keywords['positive'] as $keyword) {
                    if (mb_stripos($content, $keyword) !== false) {
                        $scores[$stage] += $weight;
                    }
                }
                foreach ($keywords['negative'] as $keyword) {
                    if (mb_stripos($content, $keyword) !== false) {
                        $scores[$stage] -= $weight * 0.5;
                    }
                }
            }
        }
        
        // Normalize scores
        $maxScore = max($scores);
        if ($maxScore > 0) {
            foreach ($scores as $stage => $score) {
                $scores[$stage] = max(0, round($score / $maxScore, 2));
            }
        }
        
        return $scores;
    }
    
    /**
     * Determine consultation stage based on scores
     * @param array $scores Scores for each stage
     * @return string Stage identifier
     */
    private function determineStage(array $scores): string
    {
        $maxScore = 0;
        $stage = self::STAGE_SYMPTOM; // Default
        
        foreach ($scores as $s => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $stage = $s;
            }
        }
        
        return $stage;
    }
    
    /**
     * Calculate confidence score based on score distribution
     * @param array $scores Scores for each stage
     * @param string $selectedStage Selected stage
     * @return float Confidence (0-1)
     */
    private function calculateStageConfidence(array $scores, string $selectedStage): float
    {
        $selectedScore = $scores[$selectedStage] ?? 0;
        $otherScores = array_filter($scores, function($key) use ($selectedStage) {
            return $key !== $selectedStage;
        }, ARRAY_FILTER_USE_KEY);
        
        $maxOther = !empty($otherScores) ? max($otherScores) : 0;
        
        if ($selectedScore == 0) {
            return 0.3; // Base confidence when no clear signals
        }
        
        // Confidence based on difference from next highest
        $diff = $selectedScore - $maxOther;
        
        if ($diff >= 0.5) {
            return min(1.0, 0.8 + ($diff - 0.5) * 0.4);
        } elseif ($diff >= 0.2) {
            return 0.5 + ($diff - 0.2) * 1.0;
        } else {
            return 0.3 + $diff * 1.0;
        }
    }
    
    /**
     * Collect signals that led to stage determination
     * @param array $messages Messages analyzed
     * @param string $stage Determined stage
     * @return array Signals
     */
    private function collectSignals(array $messages, string $stage): array
    {
        $signals = [];
        $keywords = $this->stageKeywords[$stage]['positive'] ?? [];
        
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            
            foreach ($keywords as $keyword) {
                if (mb_stripos($content, $keyword) !== false) {
                    $signals[] = $keyword;
                }
            }
        }
        
        return array_unique(array_slice($signals, 0, 5));
    }
    
    /**
     * Check if messages contain urgent symptoms
     * @param array $messages Messages to check
     * @return bool True if urgent symptoms detected
     */
    private function hasUrgentSymptoms(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = mb_strtolower($message['content'] ?? '');
            
            foreach ($this->urgentKeywords as $keyword) {
                if (mb_stripos($content, mb_strtolower($keyword)) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Save stage to database
     * @param int $userId User ID
     * @param string $stage Stage
     * @param float $confidence Confidence
     * @param array $signals Signals
     * @param bool $hasUrgentSymptoms Has urgent symptoms
     */
    private function saveStage(int $userId, string $stage, float $confidence, array $signals, bool $hasUrgentSymptoms): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO consultation_stages (user_id, stage, confidence, signals, has_urgent_symptoms, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    stage = VALUES(stage),
                    confidence = VALUES(confidence),
                    signals = VALUES(signals),
                    has_urgent_symptoms = VALUES(has_urgent_symptoms),
                    updated_at = NOW()
            ");
            $stmt->execute([
                $userId,
                $stage,
                $confidence,
                json_encode($signals, JSON_UNESCAPED_UNICODE),
                $hasUrgentSymptoms ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer saveStage error: " . $e->getMessage());
        }
    }
    
    /**
     * Create stage result array
     * @param string $stage Stage
     * @param float $confidence Confidence
     * @param array $signals Signals
     * @return array Result
     */
    private function createStageResult(string $stage, float $confidence, array $signals): array
    {
        return [
            'stage' => $stage,
            'stageLabel' => $this->getStageLabel($stage),
            'stageLabelTh' => $this->getStageLabelTh($stage),
            'confidence' => $confidence,
            'signals' => $signals,
            'hasUrgentSymptoms' => false,
            'scores' => [],
            'messageCount' => 0
        ];
    }
    
    /**
     * Get stage label in English
     * @param string $stage Stage identifier
     * @return string Label
     */
    private function getStageLabel(string $stage): string
    {
        $labels = [
            self::STAGE_SYMPTOM => 'Symptom Assessment',
            self::STAGE_RECOMMENDATION => 'Drug Recommendation',
            self::STAGE_PURCHASE => 'Purchase Decision',
            self::STAGE_FOLLOWUP => 'Follow Up'
        ];
        return $labels[$stage] ?? 'Unknown';
    }
    
    /**
     * Get stage label in Thai
     * @param string $stage Stage identifier
     * @return string Label
     */
    private function getStageLabelTh(string $stage): string
    {
        $labels = [
            self::STAGE_SYMPTOM => 'ประเมินอาการ',
            self::STAGE_RECOMMENDATION => 'แนะนำยา',
            self::STAGE_PURCHASE => 'ตัดสินใจซื้อ',
            self::STAGE_FOLLOWUP => 'ติดตามผล'
        ];
        return $labels[$stage] ?? 'ไม่ระบุ';
    }


    /**
     * Get context-aware widgets based on message keywords
     * 
     * Requirements 4.1: Symptom keyword triggers drug widget
     * Requirements 4.2: Drug name triggers info widget
     * Property 6: Symptom Keyword Triggers Drug Widget
     * Property 7: Drug Name Triggers Info Widget
     * 
     * @param string $message Customer message
     * @param int $userId Customer user ID
     * @return array Widgets to display with their data
     */
    public function getContextWidgets(string $message, int $userId): array
    {
        $widgets = [];
        $messageLower = mb_strtolower($message);
        
        // Get keywords from database
        $keywords = $this->getActiveKeywords();
        
        // Check message against keywords
        $matchedKeywords = [];
        foreach ($keywords as $keyword) {
            if (mb_stripos($messageLower, mb_strtolower($keyword['keyword'])) !== false) {
                $matchedKeywords[] = $keyword;
            }
        }
        
        // Sort by priority (higher first)
        usort($matchedKeywords, function($a, $b) {
            return ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
        });
        
        // Build widgets based on matched keywords
        foreach ($matchedKeywords as $keyword) {
            $widgetType = $keyword['widget_type'];
            $relatedData = json_decode($keyword['related_data'] ?? '{}', true);
            
            // Avoid duplicate widget types
            $existingTypes = array_column($widgets, 'type');
            if (in_array($widgetType, $existingTypes)) {
                continue;
            }
            
            $widget = $this->buildWidget($widgetType, $keyword, $relatedData, $userId, $message);
            if ($widget) {
                $widgets[] = $widget;
            }
        }
        
        // Check for drug names in message
        $drugWidgets = $this->checkForDrugNames($message, $userId);
        foreach ($drugWidgets as $drugWidget) {
            $existingTypes = array_column($widgets, 'type');
            if (!in_array($drugWidget['type'], $existingTypes)) {
                $widgets[] = $drugWidget;
            }
        }
        
        // Check for allergy warnings
        $allergyWidget = $this->checkAllergyWarnings($userId);
        if ($allergyWidget) {
            // Always show allergy warning prominently
            array_unshift($widgets, $allergyWidget);
        }
        
        // Limit to 4 widgets max
        return array_slice($widgets, 0, 4);
    }
    
    /**
     * Get active keywords from database
     * @return array Keywords
     */
    private function getActiveKeywords(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT keyword, keyword_type, widget_type, related_data, priority
                FROM pharmacy_context_keywords
                WHERE is_active = 1
                ORDER BY priority DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getActiveKeywords error: " . $e->getMessage());
            return $this->getDefaultKeywords();
        }
    }
    
    /**
     * Get default keywords when database is unavailable
     * @return array Default keywords
     */
    private function getDefaultKeywords(): array
    {
        return [
            ['keyword' => 'ปวดหัว', 'keyword_type' => 'symptom', 'widget_type' => 'symptom', 'related_data' => '{"category":"pain"}', 'priority' => 10],
            ['keyword' => 'ไข้', 'keyword_type' => 'symptom', 'widget_type' => 'symptom', 'related_data' => '{"category":"fever"}', 'priority' => 15],
            ['keyword' => 'ไอ', 'keyword_type' => 'symptom', 'widget_type' => 'symptom', 'related_data' => '{"category":"respiratory"}', 'priority' => 10],
            ['keyword' => 'แพ้ยา', 'keyword_type' => 'condition', 'widget_type' => 'allergy', 'related_data' => '{"alert":true}', 'priority' => 20],
            ['keyword' => 'ตั้งครรภ์', 'keyword_type' => 'condition', 'widget_type' => 'pregnancy', 'related_data' => '{"alert":true}', 'priority' => 25],
            ['keyword' => 'ยาตีกัน', 'keyword_type' => 'action', 'widget_type' => 'interaction', 'related_data' => '{"check_required":true}', 'priority' => 20]
        ];
    }
    
    /**
     * Build widget based on type
     * @param string $widgetType Widget type
     * @param array $keyword Matched keyword data
     * @param array $relatedData Related data
     * @param int $userId User ID
     * @param string $message Original message
     * @return array|null Widget data or null
     */
    private function buildWidget(string $widgetType, array $keyword, array $relatedData, int $userId, string $message): ?array
    {
        switch ($widgetType) {
            case 'symptom':
                return $this->buildSymptomWidget($keyword, $relatedData, $message);
                
            case 'drug_info':
                return $this->buildDrugInfoWidget($keyword, $relatedData);
                
            case 'interaction':
                return $this->buildInteractionWidget($userId);
                
            case 'allergy':
                return $this->buildAllergyWidget($userId);
                
            case 'pricing':
                return $this->buildPricingWidget($relatedData);
                
            case 'pregnancy':
                return $this->buildPregnancyWidget();
                
            default:
                return null;
        }
    }
    
    /**
     * Build symptom widget
     * Property 6: Symptom Keyword Triggers Drug Widget
     * @param array $keyword Keyword data
     * @param array $relatedData Related data
     * @param string $message Original message
     * @return array Widget
     */
    private function buildSymptomWidget(array $keyword, array $relatedData, string $message): array
    {
        $category = $relatedData['category'] ?? 'general';
        $severity = $relatedData['severity'] ?? 'mild';
        
        // Get recommended drugs for this symptom category
        $recommendations = $this->getSymptomRecommendations($category);
        
        return [
            'type' => 'symptom',
            'title' => 'แนะนำยาสำหรับอาการ',
            'titleEn' => 'Drug Recommendations',
            'icon' => '💊',
            'keyword' => $keyword['keyword'],
            'category' => $category,
            'severity' => $severity,
            'recommendations' => $recommendations,
            'actions' => [
                ['label' => 'ดูรายละเอียด', 'action' => 'view_recommendations'],
                ['label' => 'ตรวจสอบยาตีกัน', 'action' => 'check_interactions']
            ]
        ];
    }
    
    /**
     * Get symptom recommendations
     * @param string $category Symptom category
     * @return array Recommendations with full drug data from business_items
     */
    private function getSymptomRecommendations(string $category): array
    {
        // Map category to search keywords
        $categoryKeywords = [
            'pain' => ['paracetamol', 'ibuprofen', 'พาราเซตามอล', 'ไอบูโพรเฟน', 'แก้ปวด'],
            'fever' => ['paracetamol', 'พาราเซตามอล', 'ลดไข้'],
            'respiratory' => ['แก้ไอ', 'ลดน้ำมูก', 'cough', 'cold'],
            'digestive' => ['ธาตุน้ำขาว', 'แก้ท้องเสีย', 'antacid'],
            'skin' => ['แก้แพ้', 'antihistamine', 'calamine'],
            'general' => ['paracetamol', 'vitamin', 'พาราเซตามอล', 'วิตามิน']
        ];
        
        $keywords = $categoryKeywords[$category] ?? $categoryKeywords['general'];
        
        try {
            // Build search conditions
            $conditions = [];
            $params = [];
            foreach ($keywords as $keyword) {
                $conditions[] = "(bi.name LIKE ? OR bi.description LIKE ?)";
                $params[] = "%{$keyword}%";
                $params[] = "%{$keyword}%";
            }
            
            $sql = "
                SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, 
                       bi.stock, bi.description, bi.image_url,
                       ic.name as category
                FROM business_items bi
                LEFT JOIN item_categories ic ON bi.category_id = ic.id
                WHERE bi.is_active = 1 
                AND bi.stock > 0
                AND (" . implode(' OR ', $conditions) . ")
            ";
            
            if ($this->lineAccountId) {
                $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY bi.stock DESC LIMIT 5";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            foreach ($drugs as $drug) {
                $recommendations[] = [
                    'id' => (int)$drug['id'],
                    'drugId' => (int)$drug['id'],
                    'name' => $drug['name'],
                    'sku' => $drug['sku'],
                    'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0),
                    'originalPrice' => (float)($drug['price'] ?? 0),
                    'stock' => (int)($drug['stock'] ?? 0),
                    'category' => $drug['category'] ?? 'ยาทั่วไป',
                    'dosage' => $drug['description'] ?? ''
                ];
            }
            
            // If no drugs found, return fallback with popular items
            if (empty($recommendations)) {
                return $this->getPopularDrugs(3);
            }
            
            return $recommendations;
            
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getSymptomRecommendations error: " . $e->getMessage());
            return $this->getPopularDrugs(3);
        }
    }
    
    /**
     * Get popular drugs as fallback
     * @param int $limit Number of drugs to return
     * @return array Popular drugs
     */
    private function getPopularDrugs(int $limit = 5): array
    {
        try {
            $sql = "
                SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, 
                       bi.stock, bi.description, bi.image_url,
                       ic.name as category
                FROM business_items bi
                LEFT JOIN item_categories ic ON bi.category_id = ic.id
                WHERE bi.is_active = 1 
                AND bi.stock > 0
            ";
            
            if ($this->lineAccountId) {
                $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)";
            }
            
            $sql .= " ORDER BY bi.stock DESC, bi.name ASC LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            if ($this->lineAccountId) {
                $stmt->execute([$this->lineAccountId, $limit]);
            } else {
                $stmt->execute([$limit]);
            }
            
            $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            foreach ($drugs as $drug) {
                $recommendations[] = [
                    'id' => (int)$drug['id'],
                    'drugId' => (int)$drug['id'],
                    'name' => $drug['name'],
                    'sku' => $drug['sku'],
                    'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0),
                    'originalPrice' => (float)($drug['price'] ?? 0),
                    'stock' => (int)($drug['stock'] ?? 0),
                    'category' => $drug['category'] ?? 'ยาทั่วไป',
                    'dosage' => $drug['description'] ?? ''
                ];
            }
            
            return $recommendations;
            
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getPopularDrugs error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Build drug info widget
     * Property 7: Drug Name Triggers Info Widget
     * @param array $keyword Keyword data
     * @param array $relatedData Related data
     * @return array Widget
     */
    private function buildDrugInfoWidget(array $keyword, array $relatedData): array
    {
        return [
            'type' => 'drug_info',
            'title' => 'ข้อมูลยา',
            'titleEn' => 'Drug Information',
            'icon' => '💊',
            'drugName' => $keyword['keyword'],
            'relatedData' => $relatedData,
            'actions' => [
                ['label' => 'ดูรายละเอียด', 'action' => 'view_drug_info'],
                ['label' => 'ตรวจสอบยาตีกัน', 'action' => 'check_interactions'],
                ['label' => 'ดูราคา', 'action' => 'view_pricing']
            ]
        ];
    }
    
    /**
     * Build interaction checker widget
     * @param int $userId User ID
     * @return array Widget
     */
    private function buildInteractionWidget(int $userId): array
    {
        // Get user's current medications
        $medications = $this->getUserMedications($userId);
        
        return [
            'type' => 'interaction',
            'title' => 'ตรวจสอบยาตีกัน',
            'titleEn' => 'Drug Interaction Checker',
            'icon' => '⚠️',
            'currentMedications' => $medications,
            'medicationCount' => count($medications),
            'actions' => [
                ['label' => 'ตรวจสอบ', 'action' => 'check_interactions'],
                ['label' => 'เพิ่มยา', 'action' => 'add_medication']
            ]
        ];
    }
    
    /**
     * Build allergy warning widget
     * @param int $userId User ID
     * @return array|null Widget or null if no allergies
     */
    private function buildAllergyWidget(int $userId): ?array
    {
        $allergies = $this->getUserAllergies($userId);
        
        if (empty($allergies)) {
            return null;
        }
        
        return [
            'type' => 'allergy',
            'title' => '⚠️ แพ้ยา',
            'titleEn' => 'Drug Allergies',
            'icon' => '🚨',
            'allergies' => $allergies,
            'allergyCount' => count($allergies),
            'isAlert' => true,
            'actions' => [
                ['label' => 'ดูรายละเอียด', 'action' => 'view_allergies'],
                ['label' => 'แก้ไข', 'action' => 'edit_allergies']
            ]
        ];
    }
    
    /**
     * Build pricing widget
     * @param array $relatedData Related data
     * @return array Widget
     */
    private function buildPricingWidget(array $relatedData): array
    {
        return [
            'type' => 'pricing',
            'title' => 'ราคาและส่วนลด',
            'titleEn' => 'Pricing & Discounts',
            'icon' => '💰',
            'relatedData' => $relatedData,
            'actions' => [
                ['label' => 'คำนวณส่วนลด', 'action' => 'calculate_discount'],
                ['label' => 'ดูกำไร', 'action' => 'view_margin']
            ]
        ];
    }
    
    /**
     * Build pregnancy safety widget
     * @return array Widget
     */
    private function buildPregnancyWidget(): array
    {
        return [
            'type' => 'pregnancy',
            'title' => '🤰 ยาปลอดภัยสำหรับคนท้อง',
            'titleEn' => 'Pregnancy-Safe Drugs',
            'icon' => '🤰',
            'isAlert' => true,
            'message' => 'กรุณาตรวจสอบความปลอดภัยของยาก่อนแนะนำ',
            'actions' => [
                ['label' => 'ดูยาที่ปลอดภัย', 'action' => 'view_safe_drugs'],
                ['label' => 'ปรึกษาเภสัชกร', 'action' => 'consult_pharmacist']
            ]
        ];
    }
    
    /**
     * Check for drug names in message
     * @param string $message Message to check
     * @param int $userId User ID
     * @return array Drug widgets
     */
    private function checkForDrugNames(string $message, int $userId): array
    {
        $widgets = [];
        $messageLower = mb_strtolower($message);
        
        try {
            // First, search for drugs that match words in the message
            $matchedDrugs = $this->searchDrugsFromMessage($message);
            
            if (!empty($matchedDrugs)) {
                // Build symptom widget with matched drugs as recommendations
                $widgets[] = [
                    'type' => 'symptom',
                    'title' => 'แนะนำยาจากข้อความ',
                    'titleEn' => 'Drug Recommendations',
                    'icon' => '💊',
                    'keyword' => 'ค้นหาจากข้อความ',
                    'category' => 'search',
                    'recommendations' => $matchedDrugs,
                    'actions' => [
                        ['label' => 'ดูรายละเอียด', 'action' => 'view_recommendations'],
                        ['label' => 'ตรวจสอบยาตีกัน', 'action' => 'check_interactions']
                    ]
                ];
            }
            
            // Also check for exact drug name matches
            $stmt = $this->db->prepare("
                SELECT id, name, sku, price, sale_price, stock, description
                FROM business_items 
                WHERE is_active = 1 
                AND (line_account_id = ? OR line_account_id IS NULL)
                LIMIT 200
            ");
            $stmt->execute([$this->lineAccountId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($products as $product) {
                $nameLower = mb_strtolower($product['name']);
                // Check if product name (at least 3 chars) appears in message
                if (mb_strlen($nameLower) >= 3 && mb_strpos($messageLower, $nameLower) !== false) {
                    $widgets[] = [
                        'type' => 'drug_info',
                        'title' => 'ข้อมูลยา: ' . $product['name'],
                        'titleEn' => 'Drug Info: ' . $product['name'],
                        'icon' => '💊',
                        'drugId' => (int)$product['id'],
                        'drugName' => $product['name'],
                        'sku' => $product['sku'],
                        'price' => (float)($product['sale_price'] ?? $product['price'] ?? 0),
                        'stock' => (int)($product['stock'] ?? 0),
                        'inStock' => ($product['stock'] ?? 0) > 0,
                        'drug' => [
                            'id' => (int)$product['id'],
                            'name' => $product['name'],
                            'sku' => $product['sku'],
                            'price' => (float)($product['sale_price'] ?? $product['price'] ?? 0),
                            'stock' => (int)($product['stock'] ?? 0),
                            'description' => $product['description']
                        ],
                        'actions' => [
                            ['label' => 'ดูรายละเอียด', 'action' => 'view_drug_info'],
                            ['label' => 'ตรวจสอบยาตีกัน', 'action' => 'check_interactions']
                        ]
                    ];
                    
                    // Limit to 2 drug widgets
                    if (count($widgets) >= 3) {
                        break;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer checkForDrugNames error: " . $e->getMessage());
        }
        
        return $widgets;
    }
    
    /**
     * Search drugs from message text
     * Extracts drug names and searches in business_items
     * @param string $message Customer message
     * @return array Matched drugs with full data
     */
    public function searchDrugsFromMessage(string $message): array
    {
        $drugs = [];
        $messageLower = mb_strtolower($message);
        
        // First, try to find exact or close matches with product names in database
        try {
            // Get all active products to match against message
            $stmt = $this->db->prepare("
                SELECT id, name, sku, price, sale_price, stock, description, image_url
                FROM business_items 
                WHERE is_active = 1 
                AND stock > 0
                AND (line_account_id = ? OR line_account_id IS NULL)
                ORDER BY stock DESC
                LIMIT 500
            ");
            $stmt->execute([$this->lineAccountId]);
            $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $matchedProducts = [];
            
            foreach ($allProducts as $product) {
                $productName = mb_strtolower($product['name']);
                $productSku = mb_strtolower($product['sku'] ?? '');
                
                // Extract first word or main name (before space or special chars)
                $mainName = preg_split('/[\s\-\/\(\[]+/u', $productName)[0];
                
                // Check if product name or SKU appears in message
                if (mb_strlen($mainName) >= 3 && mb_strpos($messageLower, $mainName) !== false) {
                    $matchedProducts[] = $product;
                } elseif ($productSku && mb_strlen($productSku) >= 3 && mb_strpos($messageLower, $productSku) !== false) {
                    $matchedProducts[] = $product;
                } else {
                    // Check if any significant word from product name is in message
                    $nameWords = preg_split('/[\s\-\/\(\)\[\]]+/u', $productName);
                    foreach ($nameWords as $word) {
                        $word = trim($word);
                        if (mb_strlen($word) >= 4 && mb_strpos($messageLower, $word) !== false) {
                            $matchedProducts[] = $product;
                            break;
                        }
                    }
                }
            }
            
            // Remove duplicates and limit
            $seenIds = [];
            foreach ($matchedProducts as $product) {
                if (isset($seenIds[$product['id']])) continue;
                $seenIds[$product['id']] = true;
                
                $price = (float)($product['sale_price'] ?? $product['price'] ?? 0);
                $cost = $price * 0.7; // Estimate cost
                $margin = $price > 0 ? round((($price - $cost) / $price) * 100, 1) : null;
                
                $drugs[] = [
                    'id' => (int)$product['id'],
                    'drugId' => (int)$product['id'],
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'price' => $price,
                    'originalPrice' => (float)($product['price'] ?? 0),
                    'costPrice' => $cost,
                    'margin' => $margin,
                    'stock' => (int)($product['stock'] ?? 0),
                    'category' => 'ยาทั่วไป',
                    'dosage' => $product['description'] ?? '',
                    'imageUrl' => $product['image_url']
                ];
                
                if (count($drugs) >= 5) break;
            }
            
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer searchDrugsFromMessage error: " . $e->getMessage());
        }
        
        return $drugs;
    }
    
    /**
    }
    
    /**
     * Check for allergy warnings
     * @param int $userId User ID
     * @return array|null Allergy widget or null
     */
    private function checkAllergyWarnings(int $userId): ?array
    {
        $allergies = $this->getUserAllergies($userId);
        
        if (empty($allergies)) {
            return null;
        }
        
        return [
            'type' => 'allergy_warning',
            'title' => '⚠️ ลูกค้าแพ้ยา',
            'titleEn' => 'Customer Has Drug Allergies',
            'icon' => '🚨',
            'allergies' => $allergies,
            'allergyCount' => count($allergies),
            'isAlert' => true,
            'priority' => 100
        ];
    }
    
    /**
     * Get user's allergies
     * @param int $userId User ID
     * @return array Allergies
     */
    private function getUserAllergies(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT drug_allergies FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['drug_allergies'])) {
                $allergyList = preg_split('/[,\n]+/', $user['drug_allergies']);
                return array_map('trim', array_filter($allergyList));
            }
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getUserAllergies error: " . $e->getMessage());
        }
        
        return [];
    }
    
    /**
     * Get user's current medications
     * @param int $userId User ID
     * @return array Medications
     */
    private function getUserMedications(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT current_medications FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['current_medications'])) {
                $medList = preg_split('/[,\n]+/', $user['current_medications']);
                return array_map('trim', array_filter($medList));
            }
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getUserMedications error: " . $e->getMessage());
        }
        
        return [];
    }


    /**
     * Get quick actions for current consultation stage
     * 
     * Requirements 9.1: Symptom assessment stage actions
     * Requirements 9.2: Drug recommendation stage actions
     * Requirements 9.3: Purchase stage actions
     * Requirements 9.4: Highlight urgent actions when needed
     * Requirements 9.5: Pre-fill relevant data and await confirmation
     * 
     * @param string $stage Current consultation stage
     * @param bool $hasUrgentSymptoms Whether urgent symptoms detected
     * @return array Available actions with labels and handlers
     */
    public function getQuickActions(string $stage, bool $hasUrgentSymptoms = false): array
    {
        $actions = [];
        
        switch ($stage) {
            case self::STAGE_SYMPTOM:
                // Requirements 9.1: Symptom assessment stage actions
                $actions = [
                    [
                        'id' => 'ask_followup',
                        'label' => 'ถามอาการเพิ่มเติม',
                        'labelEn' => 'Ask Follow-up',
                        'icon' => '❓',
                        'action' => 'ask_followup',
                        'template' => 'อาการเป็นมานานแค่ไหนแล้วคะ? มีอาการอื่นร่วมด้วยไหมคะ?',
                        'priority' => 10
                    ],
                    [
                        'id' => 'check_history',
                        'label' => 'ดูประวัติ',
                        'labelEn' => 'Check History',
                        'icon' => '📋',
                        'action' => 'check_history',
                        'priority' => 8
                    ],
                    [
                        'id' => 'suggest_otc',
                        'label' => 'แนะนำยา OTC',
                        'labelEn' => 'Suggest OTC',
                        'icon' => '💊',
                        'action' => 'suggest_otc',
                        'priority' => 9
                    ],
                    [
                        'id' => 'analyze_image',
                        'label' => 'วิเคราะห์รูป',
                        'labelEn' => 'Analyze Image',
                        'icon' => '📷',
                        'action' => 'analyze_image',
                        'priority' => 7
                    ]
                ];
                break;
                
            case self::STAGE_RECOMMENDATION:
                // Requirements 9.2: Drug recommendation stage actions
                $actions = [
                    [
                        'id' => 'send_drug_info',
                        'label' => 'ส่งข้อมูลยา',
                        'labelEn' => 'Send Drug Info',
                        'icon' => '💊',
                        'action' => 'send_drug_info',
                        'priority' => 10
                    ],
                    [
                        'id' => 'check_interactions',
                        'label' => 'ตรวจยาตีกัน',
                        'labelEn' => 'Check Interactions',
                        'icon' => '⚠️',
                        'action' => 'check_interactions',
                        'priority' => 9
                    ],
                    [
                        'id' => 'apply_discount',
                        'label' => 'ให้ส่วนลด',
                        'labelEn' => 'Apply Discount',
                        'icon' => '💰',
                        'action' => 'apply_discount',
                        'priority' => 7
                    ],
                    [
                        'id' => 'compare_drugs',
                        'label' => 'เปรียบเทียบยา',
                        'labelEn' => 'Compare Drugs',
                        'icon' => '📊',
                        'action' => 'compare_drugs',
                        'priority' => 8
                    ]
                ];
                break;
                
            case self::STAGE_PURCHASE:
                // Requirements 9.3: Purchase stage actions
                $actions = [
                    [
                        'id' => 'create_order',
                        'label' => 'สร้างออเดอร์',
                        'labelEn' => 'Create Order',
                        'icon' => '🛒',
                        'action' => 'create_order',
                        'priority' => 10
                    ],
                    [
                        'id' => 'send_payment_link',
                        'label' => 'ส่งลิงก์ชำระเงิน',
                        'labelEn' => 'Send Payment Link',
                        'icon' => '💳',
                        'action' => 'send_payment_link',
                        'priority' => 9
                    ],
                    [
                        'id' => 'schedule_delivery',
                        'label' => 'นัดส่งสินค้า',
                        'labelEn' => 'Schedule Delivery',
                        'icon' => '🚚',
                        'action' => 'schedule_delivery',
                        'priority' => 8
                    ],
                    [
                        'id' => 'apply_points',
                        'label' => 'ใช้แต้มสะสม',
                        'labelEn' => 'Apply Points',
                        'icon' => '⭐',
                        'action' => 'apply_points',
                        'priority' => 7
                    ]
                ];
                break;
                
            case self::STAGE_FOLLOWUP:
                // Follow-up stage actions
                $actions = [
                    [
                        'id' => 'check_progress',
                        'label' => 'ถามความคืบหน้า',
                        'labelEn' => 'Check Progress',
                        'icon' => '📈',
                        'action' => 'check_progress',
                        'template' => 'อาการเป็นอย่างไรบ้างคะ? ดีขึ้นไหมคะ?',
                        'priority' => 10
                    ],
                    [
                        'id' => 'suggest_refill',
                        'label' => 'แนะนำเติมยา',
                        'labelEn' => 'Suggest Refill',
                        'icon' => '🔄',
                        'action' => 'suggest_refill',
                        'priority' => 9
                    ],
                    [
                        'id' => 'schedule_followup',
                        'label' => 'นัดติดตาม',
                        'labelEn' => 'Schedule Follow-up',
                        'icon' => '📅',
                        'action' => 'schedule_followup',
                        'priority' => 8
                    ],
                    [
                        'id' => 'refer_doctor',
                        'label' => 'แนะนำพบแพทย์',
                        'labelEn' => 'Refer to Doctor',
                        'icon' => '🏥',
                        'action' => 'refer_doctor',
                        'priority' => 7
                    ]
                ];
                break;
                
            default:
                // Default actions
                $actions = [
                    [
                        'id' => 'ask_symptoms',
                        'label' => 'ถามอาการ',
                        'labelEn' => 'Ask Symptoms',
                        'icon' => '❓',
                        'action' => 'ask_symptoms',
                        'template' => 'สวัสดีค่ะ มีอาการอะไรให้ช่วยเหลือคะ?',
                        'priority' => 10
                    ],
                    [
                        'id' => 'check_history',
                        'label' => 'ดูประวัติ',
                        'labelEn' => 'Check History',
                        'icon' => '📋',
                        'action' => 'check_history',
                        'priority' => 8
                    ]
                ];
        }
        
        // Requirements 9.4: Add urgent action if symptoms are severe
        if ($hasUrgentSymptoms) {
            array_unshift($actions, [
                'id' => 'recommend_hospital',
                'label' => '🚨 แนะนำพบแพทย์ด่วน',
                'labelEn' => '🚨 Recommend Hospital Visit',
                'icon' => '🏥',
                'action' => 'recommend_hospital',
                'template' => '⚠️ จากอาการที่แจ้งมา แนะนำให้พบแพทย์โดยเร็วค่ะ เพื่อความปลอดภัย กรุณาไปโรงพยาบาลหรือคลินิกใกล้บ้านค่ะ',
                'isUrgent' => true,
                'priority' => 100,
                'highlight' => true
            ]);
        }
        
        // Sort by priority
        usort($actions, function($a, $b) {
            return ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
        });
        
        return [
            'stage' => $stage,
            'stageLabel' => $this->getStageLabelTh($stage),
            'hasUrgentSymptoms' => $hasUrgentSymptoms,
            'actions' => $actions
        ];
    }

    /**
     * Detect if symptoms require hospital referral
     * 
     * Requirements 9.4: Highlight "Recommend Hospital Visit" action if symptoms are severe
     * Property 13: Urgent Symptom Detection
     * 
     * @param int $userId Customer user ID
     * @return array ['needsReferral' => bool, 'reason' => string, 'urgency' => string]
     */
    public function detectUrgency(int $userId): array
    {
        // Get recent messages
        $messages = $this->getRecentMessages($userId, 10);
        
        if (empty($messages)) {
            return [
                'needsReferral' => false,
                'reason' => null,
                'urgency' => 'normal',
                'detectedKeywords' => []
            ];
        }
        
        $detectedKeywords = [];
        $urgencyLevel = 'normal';
        $reason = null;
        
        // Check for urgent keywords
        foreach ($messages as $message) {
            $content = mb_strtolower($message['content'] ?? '');
            
            foreach ($this->urgentKeywords as $keyword) {
                if (mb_stripos($content, mb_strtolower($keyword)) !== false) {
                    $detectedKeywords[] = $keyword;
                }
            }
        }
        
        $detectedKeywords = array_unique($detectedKeywords);
        
        // Determine urgency level based on detected keywords
        if (!empty($detectedKeywords)) {
            // Critical keywords
            $criticalKeywords = [
                'หายใจลำบาก', 'หายใจไม่ออก', 'แน่นหน้าอก', 'เจ็บหน้าอก',
                'ชัก', 'หมดสติ', 'เลือดออก', 'อาเจียนเป็นเลือด',
                'difficulty breathing', 'cant breathe', 'chest pain', 'seizure', 'unconscious'
            ];
            
            $hasCritical = false;
            foreach ($detectedKeywords as $keyword) {
                foreach ($criticalKeywords as $critical) {
                    if (mb_stripos($keyword, $critical) !== false || mb_stripos($critical, $keyword) !== false) {
                        $hasCritical = true;
                        break 2;
                    }
                }
            }
            
            if ($hasCritical) {
                $urgencyLevel = 'critical';
                $reason = 'ตรวจพบอาการฉุกเฉิน: ' . implode(', ', array_slice($detectedKeywords, 0, 3));
            } elseif (count($detectedKeywords) >= 2) {
                $urgencyLevel = 'high';
                $reason = 'ตรวจพบอาการรุนแรงหลายอย่าง: ' . implode(', ', array_slice($detectedKeywords, 0, 3));
            } else {
                $urgencyLevel = 'moderate';
                $reason = 'ตรวจพบอาการที่ควรระวัง: ' . implode(', ', $detectedKeywords);
            }
        }
        
        // Check saved stage for urgent flag
        try {
            $stmt = $this->db->prepare("
                SELECT has_urgent_symptoms FROM consultation_stages WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $stage = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stage && $stage['has_urgent_symptoms'] && $urgencyLevel === 'normal') {
                $urgencyLevel = 'moderate';
                $reason = 'มีประวัติอาการที่ควรระวังก่อนหน้านี้';
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        $needsReferral = in_array($urgencyLevel, ['critical', 'high']);
        
        return [
            'needsReferral' => $needsReferral,
            'reason' => $reason,
            'urgency' => $urgencyLevel,
            'urgencyLabel' => $this->getUrgencyLabel($urgencyLevel),
            'detectedKeywords' => $detectedKeywords,
            'recommendation' => $needsReferral 
                ? 'แนะนำให้พบแพทย์โดยเร็ว' 
                : ($urgencyLevel === 'moderate' ? 'ควรติดตามอาการอย่างใกล้ชิด' : null)
        ];
    }
    
    /**
     * Get urgency level label
     * @param string $level Urgency level
     * @return string Label
     */
    private function getUrgencyLabel(string $level): string
    {
        $labels = [
            'normal' => 'ปกติ',
            'moderate' => 'ควรระวัง',
            'high' => 'รุนแรง',
            'critical' => 'ฉุกเฉิน'
        ];
        return $labels[$level] ?? 'ไม่ระบุ';
    }
    
    /**
     * Get saved consultation stage for a user
     * @param int $userId User ID
     * @return array|null Stage data or null
     */
    public function getSavedStage(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT stage, confidence, signals, has_urgent_symptoms, updated_at
                FROM consultation_stages 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'stage' => $result['stage'],
                    'stageLabel' => $this->getStageLabel($result['stage']),
                    'stageLabelTh' => $this->getStageLabelTh($result['stage']),
                    'confidence' => (float)$result['confidence'],
                    'signals' => json_decode($result['signals'] ?? '[]', true),
                    'hasUrgentSymptoms' => (bool)$result['has_urgent_symptoms'],
                    'updatedAt' => $result['updated_at']
                ];
            }
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer getSavedStage error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Clear consultation stage for a user (e.g., when consultation ends)
     * @param int $userId User ID
     * @return bool Success
     */
    public function clearStage(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM consultation_stages WHERE user_id = ?");
            return $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer clearStage error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Record consultation analytics
     * @param int $userId User ID
     * @param array $data Analytics data
     * @return bool Success
     */
    public function recordAnalytics(int $userId, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO consultation_analytics 
                (user_id, pharmacist_id, communication_type, stage_at_close, 
                 response_time_avg, message_count, ai_suggestions_shown, ai_suggestions_accepted,
                 resulted_in_purchase, purchase_amount, symptom_categories, drugs_recommended, successful_patterns)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            return $stmt->execute([
                $userId,
                $data['pharmacistId'] ?? null,
                $data['communicationType'] ?? null,
                $data['stageAtClose'] ?? null,
                $data['responseTimeAvg'] ?? null,
                $data['messageCount'] ?? null,
                $data['aiSuggestionsShown'] ?? 0,
                $data['aiSuggestionsAccepted'] ?? 0,
                $data['resultedInPurchase'] ?? 0,
                $data['purchaseAmount'] ?? null,
                json_encode($data['symptomCategories'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['drugsRecommended'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($data['successfulPatterns'] ?? [], JSON_UNESCAPED_UNICODE)
            ]);
        } catch (PDOException $e) {
            error_log("ConsultationAnalyzer recordAnalytics error: " . $e->getMessage());
            return false;
        }
    }
}
