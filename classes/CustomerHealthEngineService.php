<?php
/**
 * Customer Health Engine Service
 * 
 * Provides customer health profiling, communication style classification,
 * allergy management, and medication tracking for pharmacy consultations.
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
 */

class CustomerHealthEngineService
{
    private $db;
    private $lineAccountId;
    
    // Communication types
    const TYPE_DIRECT = 'A';      // Direct, wants quick answers
    const TYPE_CONCERNED = 'B';   // Worried, needs reassurance
    const TYPE_DETAILED = 'C';    // Wants detailed information
    
    // Minimum messages required for classification
    const MIN_MESSAGES_FOR_CLASSIFICATION = 1;
    
    // Classification keywords for each type
    // Note: For wholesale business, focus on HOW they communicate, not WHAT they buy
    private $typeKeywords = [
        'A' => [
            // Type A: Direct, transactional, minimal words
            'positive' => ['รีบ', 'ด่วน', 'เร็ว', 'ตอนนี้', 'ทันที', 'วันนี้', 'พรุ่งนี้', 'asap', 'urgent'],
            'negative' => ['ทำไม', 'อธิบาย', 'รายละเอียด', 'กังวล', 'กลัว', 'ห่วง', 'เปรียบเทียบ', 'ข้อมูล']
        ],
        'B' => [
            // Type B: Concerned, asks about safety, needs reassurance
            'positive' => ['กังวล', 'กลัว', 'ห่วง', 'เป็นอะไร', 'อันตราย', 'ปลอดภัย', 'ผลข้างเคียง', 'แพ้', 'ไม่แน่ใจ', 'ช่วย', 'แนะนำ', 'ขอบคุณ', 'ดีใจ', 'หมอ', 'คุณหมอ', 'เภสัช'],
            'negative' => ['รีบ', 'ด่วน', 'เร็ว']
        ],
        'C' => [
            // Type C: Detail-oriented, wants information, compares options
            'positive' => ['รายละเอียด', 'อธิบาย', 'ทำไม', 'อย่างไร', 'เปรียบเทียบ', 'ข้อมูล', 'วิจัย', 'หลักฐาน', 'ส่วนประกอบ', 'กลไก', 'ต่างกัน', 'ดีกว่า', 'แบบไหน', 'ยี่ห้อ', 'ตัวไหน'],
            'negative' => ['รีบ', 'ด่วน', 'เร็ว']
        ]
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
     * Get customer health profile
     * 
     * Requirements 2.5: Display medical history, allergies, current medications, and communication tips
     * 
     * @param int $userId Customer user ID
     * @return array ['allergies' => array, 'medications' => array, 'conditions' => array, 'communicationType' => string]
     */
    public function getHealthProfile(int $userId): array
    {
        // Get user's basic health info from users table
        $userHealth = $this->getUserHealthData($userId);
        
        // Get allergies
        $allergies = $this->getAllergies($userId);
        
        // Get current medications
        $medications = $this->getMedications($userId);
        
        // Get or create health profile with communication type
        $profile = $this->getOrCreateProfile($userId);
        
        // Get communication tips based on type
        $draftStyle = $this->getDraftStyle($profile['communicationType'] ?? self::TYPE_DIRECT);
        
        return [
            'userId' => $userId,
            'allergies' => $allergies,
            'medications' => $medications,
            'conditions' => $userHealth['conditions'],
            'communicationType' => $profile['communicationType'],
            'communicationTypeLabel' => $this->getTypeLabel($profile['communicationType']),
            'confidence' => $profile['confidence'],
            'tips' => $profile['tips'] ?? $draftStyle['tips'],
            'draftStyle' => $draftStyle,
            'weight' => $userHealth['weight'],
            'height' => $userHealth['height'],
            'bloodType' => $userHealth['bloodType'],
            'hasAllergyWarning' => !empty($allergies),
            'lastAnalyzedAt' => $profile['lastAnalyzedAt'],
            'messageCountAnalyzed' => $profile['messageCountAnalyzed']
        ];
    }
    
    /**
     * Get user's health data from users table
     * @param int $userId User ID
     * @return array Health data
     */
    private function getUserHealthData(int $userId): array
    {
        $data = [
            'conditions' => [],
            'weight' => null,
            'height' => null,
            'bloodType' => null
        ];
        
        try {
            $stmt = $this->db->prepare("
                SELECT line_user_id, weight, height, blood_type, medical_conditions, drug_allergies, current_medications
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $data['weight'] = $user['weight'] ? (float)$user['weight'] : null;
                $data['height'] = $user['height'] ? (float)$user['height'] : null;
                $data['bloodType'] = $user['blood_type'] ?? null;

                // Parse medical conditions (stored as text, comma or newline separated)
                if (!empty($user['medical_conditions'])) {
                    $conditions = preg_split('/[,\n]+/', $user['medical_conditions']);
                    $data['conditions'] = array_map('trim', array_filter($conditions));
                }

                // Overlay data written by the LINE Mini App (user_health_profiles,
                // keyed by line_user_id). The mini app is the current source of truth
                // when the customer fills their health form there; the legacy users.*
                // columns above remain the fallback for older records.
                if (!empty($user['line_user_id'])) {
                    $this->overlayMiniAppHealthProfile($data, (string)$user['line_user_id']);
                }
            }
        } catch (PDOException $e) {
            error_log("CustomerHealthEngine getUserHealthData error: " . $e->getMessage());
        }

        return $data;
    }

    
    /**
     * Get customer's drug allergies
     * 
     * Requirements 2.6: Prominently display allergy warnings during consultation
     * 
     * @param int $userId Customer user ID
     * @return array List of allergies with severity
     */
    public function getAllergies(int $userId): array
    {
        $allergies = [];
        
        try {
            // First, get from users table (text field)
            $stmt = $this->db->prepare("SELECT drug_allergies FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['drug_allergies'])) {
                // Parse text field (comma or newline separated)
                $allergyList = preg_split('/[,\n]+/', $user['drug_allergies']);
                foreach ($allergyList as $allergy) {
                    $allergy = trim($allergy);
                    if (!empty($allergy)) {
                        $allergies[] = [
                            'name' => $allergy,
                            'severity' => 'unknown',
                            'source' => 'user_profile',
                            'isActive' => true
                        ];
                    }
                }
            }
            
            // Try to get from user_allergies table if exists
            try {
                $stmt = $this->db->prepare("
                    SELECT allergy_name, severity, reaction, notes, is_active
                    FROM user_allergies 
                    WHERE user_id = ? AND is_active = 1
                ");
                $stmt->execute([$userId]);
                $detailedAllergies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($detailedAllergies as $allergy) {
                    // Check if already exists from text field
                    $exists = false;
                    foreach ($allergies as &$existing) {
                        if (stripos($existing['name'], $allergy['allergy_name']) !== false ||
                            stripos($allergy['allergy_name'], $existing['name']) !== false) {
                            // Update with more detailed info
                            $existing['severity'] = $allergy['severity'] ?? 'unknown';
                            $existing['reaction'] = $allergy['reaction'] ?? null;
                            $existing['notes'] = $allergy['notes'] ?? null;
                            $existing['source'] = 'detailed';
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $allergies[] = [
                            'name' => $allergy['allergy_name'],
                            'severity' => $allergy['severity'] ?? 'unknown',
                            'reaction' => $allergy['reaction'] ?? null,
                            'notes' => $allergy['notes'] ?? null,
                            'source' => 'detailed',
                            'isActive' => true
                        ];
                    }
                }
            } catch (PDOException $e) {
                // user_allergies table might not exist
            }

            // LINE Mini App allergies (user_drug_allergies, keyed by line_user_id).
            // This is where the mini app writes — without merging it the pharmacist
            // HUD misses allergies the customer entered in the app (safety-critical).
            $this->mergeMiniAppAllergies($allergies, $userId);

        } catch (PDOException $e) {
            error_log("CustomerHealthEngine getAllergies error: " . $e->getMessage());
        }

        return $allergies;
    }
    
    /**
     * Get customer's current medications
     * 
     * Requirements 2.5: Display current medications in health profile
     * 
     * @param int $userId Customer user ID
     * @return array List of current medications
     */
    public function getMedications(int $userId): array
    {
        $medications = [];
        
        try {
            // First, get from users table (text field)
            $stmt = $this->db->prepare("SELECT current_medications FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['current_medications'])) {
                // Parse text field (comma or newline separated)
                $medList = preg_split('/[,\n]+/', $user['current_medications']);
                foreach ($medList as $med) {
                    $med = trim($med);
                    if (!empty($med)) {
                        $medications[] = [
                            'name' => $med,
                            'dosage' => null,
                            'frequency' => null,
                            'source' => 'user_profile',
                            'isActive' => true
                        ];
                    }
                }
            }
            
            // Try to get from user_medications table if exists
            try {
                $stmt = $this->db->prepare("
                    SELECT medication_name, dosage, frequency, start_date, notes, is_active
                    FROM user_medications 
                    WHERE user_id = ? AND is_active = 1
                ");
                $stmt->execute([$userId]);
                $detailedMeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($detailedMeds as $med) {
                    // Check if already exists from text field
                    $exists = false;
                    foreach ($medications as &$existing) {
                        if (stripos($existing['name'], $med['medication_name']) !== false ||
                            stripos($med['medication_name'], $existing['name']) !== false) {
                            // Update with more detailed info
                            $existing['dosage'] = $med['dosage'] ?? null;
                            $existing['frequency'] = $med['frequency'] ?? null;
                            $existing['startDate'] = $med['start_date'] ?? null;
                            $existing['notes'] = $med['notes'] ?? null;
                            $existing['source'] = 'detailed';
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists) {
                        $medications[] = [
                            'name' => $med['medication_name'],
                            'dosage' => $med['dosage'] ?? null,
                            'frequency' => $med['frequency'] ?? null,
                            'startDate' => $med['start_date'] ?? null,
                            'notes' => $med['notes'] ?? null,
                            'source' => 'detailed',
                            'isActive' => true
                        ];
                    }
                }
            } catch (PDOException $e) {
                // user_medications table might not exist
            }
            
            // Also check purchase history for medication patterns
            $purchasedMeds = $this->getRecentPurchasedMedications($userId);
            foreach ($purchasedMeds as $purchasedMed) {
                // Only add if not already in list
                $exists = false;
                foreach ($medications as $existing) {
                    if (stripos($existing['name'], $purchasedMed['name']) !== false ||
                        stripos($purchasedMed['name'], $existing['name']) !== false) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    $medications[] = $purchasedMed;
                }
            }

            // LINE Mini App current medications (user_current_medications, keyed by
            // line_user_id) — merge so meds entered in the app show on the HUD.
            $this->mergeMiniAppMedications($medications, $userId);

        } catch (PDOException $e) {
            error_log("CustomerHealthEngine getMedications error: " . $e->getMessage());
        }

        return $medications;
    }

    /**
     * Resolve a user's LINE user id (used to read the mini-app health tables,
     * which are keyed by line_user_id rather than users.id).
     */
    private function resolveLineUserId(int $userId): ?string
    {
        try {
            $stmt = $this->db->prepare("SELECT line_user_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $lid = $stmt->fetchColumn();
            return ($lid !== false && $lid !== null && $lid !== '') ? (string)$lid : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Overlay weight/height/blood type/conditions from the mini-app health profile
     * (user_health_profiles). Mini-app values take precedence over legacy columns.
     *
     * @param array<string,mixed> $data
     */
    private function overlayMiniAppHealthProfile(array &$data, string $lineUserId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT weight, height, blood_type, medical_conditions
                FROM user_health_profiles
                WHERE line_user_id = ?
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$lineUserId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                return;
            }
            if (!empty($p['weight'])) { $data['weight'] = (float)$p['weight']; }
            if (!empty($p['height'])) { $data['height'] = (float)$p['height']; }
            if (!empty($p['blood_type']) && $p['blood_type'] !== 'unknown') {
                $data['bloodType'] = $p['blood_type'];
            }
            // medical_conditions is JSON in the mini-app table (array of strings or
            // {name:...} objects). Only override when it actually holds entries.
            if (!empty($p['medical_conditions'])) {
                $decoded = json_decode((string)$p['medical_conditions'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $conditions = array_filter(array_map(static function ($c) {
                        if (is_string($c)) {
                            return trim($c);
                        }
                        return is_array($c) ? trim((string)($c['name'] ?? '')) : '';
                    }, $decoded));
                    if (!empty($conditions)) {
                        $data['conditions'] = array_values($conditions);
                    }
                }
            }
        } catch (PDOException $e) {
            // user_health_profiles may not exist on legacy tenants — keep fallback.
        }
    }

    /**
     * Merge allergies written by the LINE Mini App (user_drug_allergies) into the
     * list, deduping by name. Mini-app rows carry structured severity/reaction.
     *
     * @param array<int,array<string,mixed>> $allergies
     */
    private function mergeMiniAppAllergies(array &$allergies, int $userId): void
    {
        try {
            $lid = $this->resolveLineUserId($userId);
            if ($lid === null) {
                return;
            }
            $stmt = $this->db->prepare("
                SELECT drug_name, severity, reaction_type, reaction_notes
                FROM user_drug_allergies
                WHERE line_user_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$lid]);
            // "no allergy" sentinels the mini app may store — must NOT surface as a
            // (false) allergy warning on the HUD.
            $noneTokens = ['ไม่มี', 'ไม่แพ้', 'ไม่มีประวัติแพ้ยา', 'none', 'no', 'n/a', '-'];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)($row['drug_name'] ?? ''));
                if ($name === '' || in_array(mb_strtolower($name), $noneTokens, true)) {
                    continue;
                }
                $exists = false;
                foreach ($allergies as &$existing) {
                    if (stripos($existing['name'], $name) !== false ||
                        stripos($name, $existing['name']) !== false) {
                        $existing['severity'] = $row['severity'] ?: ($existing['severity'] ?? 'unknown');
                        $existing['reaction'] = $row['reaction_type'] ?? ($existing['reaction'] ?? null);
                        $existing['notes']    = $row['reaction_notes'] ?? ($existing['notes'] ?? null);
                        $existing['source']   = 'miniapp';
                        $exists = true;
                        break;
                    }
                }
                unset($existing);
                if (!$exists) {
                    $allergies[] = [
                        'name' => $name,
                        'severity' => $row['severity'] ?: 'unknown',
                        'reaction' => $row['reaction_type'] ?? null,
                        'notes' => $row['reaction_notes'] ?? null,
                        'source' => 'miniapp',
                        'isActive' => true
                    ];
                }
            }
        } catch (PDOException $e) {
            // user_drug_allergies may not exist on legacy tenants.
        }
    }

    /**
     * Merge current medications written by the LINE Mini App
     * (user_current_medications) into the list, deduping by name.
     *
     * @param array<int,array<string,mixed>> $medications
     */
    private function mergeMiniAppMedications(array &$medications, int $userId): void
    {
        try {
            $lid = $this->resolveLineUserId($userId);
            if ($lid === null) {
                return;
            }
            $stmt = $this->db->prepare("
                SELECT medication_name, dosage, frequency, notes
                FROM user_current_medications
                WHERE line_user_id = ? AND is_active = 1
                ORDER BY created_at DESC
            ");
            $stmt->execute([$lid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = trim((string)($row['medication_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $exists = false;
                foreach ($medications as &$existing) {
                    if (stripos($existing['name'], $name) !== false ||
                        stripos($name, $existing['name']) !== false) {
                        $existing['dosage']    = $row['dosage'] ?: ($existing['dosage'] ?? null);
                        $existing['frequency'] = $row['frequency'] ?: ($existing['frequency'] ?? null);
                        $existing['notes']     = $row['notes'] ?? ($existing['notes'] ?? null);
                        $existing['source']    = 'miniapp';
                        $exists = true;
                        break;
                    }
                }
                unset($existing);
                if (!$exists) {
                    $medications[] = [
                        'name' => $name,
                        'dosage' => $row['dosage'] ?? null,
                        'frequency' => $row['frequency'] ?? null,
                        'notes' => $row['notes'] ?? null,
                        'source' => 'miniapp',
                        'isActive' => true
                    ];
                }
            }
        } catch (PDOException $e) {
            // user_current_medications may not exist on legacy tenants.
        }
    }

    
    /**
     * Get recently purchased medications from order history
     * @param int $userId User ID
     * @param int $days Number of days to look back
     * @return array Purchased medications
     */
    private function getRecentPurchasedMedications(int $userId, int $days = 90): array
    {
        $medications = [];
        
        try {
            // Get from transactions/orders with medication category items
            $stmt = $this->db->prepare("
                SELECT DISTINCT bi.name, bi.id as product_id, MAX(t.created_at) as last_purchased
                FROM transactions t
                JOIN transaction_items ti ON t.id = ti.transaction_id
                JOIN business_items bi ON ti.product_id = bi.id
                LEFT JOIN item_categories ic ON bi.category_id = ic.id
                WHERE t.user_id = ? 
                AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND t.status NOT IN ('cancelled', 'failed')
                AND (ic.name LIKE '%ยา%' OR ic.name LIKE '%drug%' OR ic.name LIKE '%medicine%' 
                     OR bi.name LIKE '%ยา%' OR bi.is_prescription = 1)
                GROUP BY bi.id, bi.name
                ORDER BY last_purchased DESC
                LIMIT 10
            ");
            $stmt->execute([$userId, $days]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                $medications[] = [
                    'name' => $row['name'],
                    'productId' => $row['product_id'],
                    'lastPurchased' => $row['last_purchased'],
                    'source' => 'purchase_history',
                    'isActive' => true
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist or have different structure
            try {
                // Try orders table
                $stmt = $this->db->prepare("
                    SELECT DISTINCT bi.name, bi.id as product_id, MAX(o.created_at) as last_purchased
                    FROM orders o
                    JOIN order_items oi ON o.id = oi.order_id
                    JOIN business_items bi ON oi.product_id = bi.id
                    WHERE o.user_id = ? 
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    AND o.status IN ('paid', 'confirmed', 'delivered', 'completed')
                    GROUP BY bi.id, bi.name
                    ORDER BY last_purchased DESC
                    LIMIT 10
                ");
                $stmt->execute([$userId, $days]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($results as $row) {
                    $medications[] = [
                        'name' => $row['name'],
                        'productId' => $row['product_id'],
                        'lastPurchased' => $row['last_purchased'],
                        'source' => 'purchase_history',
                        'isActive' => true
                    ];
                }
            } catch (PDOException $e2) {
                // Ignore
            }
        }
        
        return $medications;
    }
    
    /**
     * Classify customer communication style based on chat history
     * 
     * Requirements 2.1: Classify communication style (Type A/B/C) with minimum 5 messages
     * Property 4: Health Profile Classification Completeness
     * 
     * @param int $userId Customer user ID
     * @param int $minMessages Minimum messages required (default 5)
     * @return array ['type' => string, 'confidence' => float, 'tips' => array]
     */
    public function classifyCustomer(int $userId, int $minMessages = self::MIN_MESSAGES_FOR_CLASSIFICATION): array
    {
        // Get message count
        $messageCount = $this->getMessageCount($userId);
        
        // Get recent messages for analysis
        $messages = $this->getRecentMessages($userId, 50);
        
        // Detect emotion from latest message
        $emotion = 'neutral';
        if (!empty($messages)) {
            $latestMessage = $messages[0]['content'] ?? '';
            $emotion = $this->detectEmotion($latestMessage);
        }
        
        // If not enough messages, return default with low confidence
        if ($messageCount < $minMessages || empty($messages)) {
            return [
                'type' => self::TYPE_DIRECT, // Default type
                'confidence' => 0.0,
                'tips' => $this->getDefaultTips(self::TYPE_DIRECT),
                'messageCount' => $messageCount,
                'minRequired' => $minMessages,
                'insufficientData' => true,
                'emotion' => $emotion
            ];
        }
        
        // Analyze messages to determine type
        $scores = $this->analyzeMessagePatterns($messages);
        
        // Determine type based on highest score
        $type = $this->determineType($scores);
        
        // Calculate confidence (0-1)
        $confidence = $this->calculateConfidence($scores, $type);
        
        // Get tips for this type
        $tips = $this->getDefaultTips($type);
        
        // Save/update profile
        $this->saveProfile($userId, $type, $confidence, $tips, $messageCount);
        
        return [
            'type' => $type,
            'confidence' => round($confidence, 2),
            'tips' => $tips,
            'messageCount' => $messageCount,
            'scores' => $scores,
            'insufficientData' => false,
            'emotion' => $emotion
        ];
    }
    
    /**
     * Detect emotion from message text
     * @param string $message Message text
     * @return string Emotion type
     */
    private function detectEmotion(string $message): string
    {
        if (empty($message)) return 'neutral';
        
        $msg = mb_strtolower($message);
        
        // Angry keywords (strong negative)
        if (preg_match('/โกรธ|โมโห|หัวร้อน|บ้า|เวร|ห่า|สัตว์|ไอ้|อี|แม่ง|เหี้ย|!{2,}|ไม่พอใจ|แย่มาก/u', $msg)) {
            return 'angry';
        }
        
        // Frustrated keywords (mild negative)
        if (preg_match('/หงุดหงิด|รำคาญ|เบื่อ|ช้า|นาน|รอ|ไม่ได้|ไม่ดี|แย่|ผิดหวัง|เสียเวลา/u', $msg)) {
            return 'frustrated';
        }
        
        // Happy keywords (strong positive)
        if (preg_match('/ขอบคุณ|ดีมาก|เยี่ยม|สุดยอด|ชอบ|รัก|ปลื้ม|ดีใจ|ประทับใจ|เก่ง|เจ๋ง|👍|😊|🙏/u', $msg)) {
            return 'happy';
        }
        
        // Satisfied keywords (mild positive)
        if (preg_match('/โอเค|ได้|ดี|เข้าใจ|ตกลง|ok|okay|รับทราบ|เรียบร้อย|ครับ$|ค่ะ$|คะ$/ui', $msg)) {
            return 'satisfied';
        }
        
        // Confused keywords
        if (preg_match('/งง|ไม่เข้าใจ|อะไร|ยังไง|หมายความว่า|\?{2,}|สับสน|ไม่รู้/u', $msg)) {
            return 'confused';
        }
        
        // Worried keywords
        if (preg_match('/กังวล|กลัว|เป็นห่วง|ไม่แน่ใจ|อันตราย|ผลข้างเคียง|ปลอดภัย|แพ้/u', $msg)) {
            return 'worried';
        }
        
        // Urgent keywords
        if (preg_match('/ด่วน|เร่ง|รีบ|ตอนนี้|ทันที|asap|urgent|วันนี้|พรุ่งนี้/ui', $msg)) {
            return 'urgent';
        }
        
        return 'neutral';
    }

    
    /**
     * Get message count for a user
     * @param int $userId User ID
     * @return int Message count
     */
    private function getMessageCount(int $userId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE user_id = ? AND direction = 'incoming'
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    /**
     * Get recent messages for a user
     * @param int $userId User ID
     * @param int $limit Number of messages to retrieve
     * @return array Messages
     */
    private function getRecentMessages(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT content, message_type, created_at
                FROM messages 
                WHERE user_id = ? AND direction = 'incoming' AND message_type = 'text'
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
     * Analyze message patterns to score each communication type
     * @param array $messages Messages to analyze
     * @return array Scores for each type
     */
    private function analyzeMessagePatterns(array $messages): array
    {
        $scores = [
            'A' => 0.0,
            'B' => 0.0,
            'C' => 0.0
        ];
        
        if (empty($messages)) {
            return $scores;
        }
        
        $totalMessages = count($messages);
        $totalLength = 0;
        $questionCount = 0;
        $politeCount = 0; // Count polite expressions
        $comparisonCount = 0; // Count comparison requests
        
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            $totalLength += mb_strlen($content);
            
            // Count questions
            if (preg_match('/[?？]/u', $content)) {
                $questionCount++;
            }
            
            // Count polite expressions (indicates Type B)
            if (preg_match('/ครับ|ค่ะ|คะ|ขอบคุณ|รบกวน|ช่วย/u', $content)) {
                $politeCount++;
            }
            
            // Count comparison/detail requests (indicates Type C)
            if (preg_match('/ต่างกัน|เปรียบเทียบ|แบบไหน|ตัวไหน|ดีกว่า|ยี่ห้อ/u', $content)) {
                $comparisonCount++;
            }
            
            // Score based on keywords
            foreach ($this->typeKeywords as $type => $keywords) {
                foreach ($keywords['positive'] as $keyword) {
                    if (mb_stripos($content, $keyword) !== false) {
                        $scores[$type] += 1.0;
                    }
                }
                foreach ($keywords['negative'] as $keyword) {
                    if (mb_stripos($content, $keyword) !== false) {
                        $scores[$type] -= 0.3;
                    }
                }
            }
        }
        
        // Adjust based on message length patterns (reduced weight)
        $avgLength = $totalLength / $totalMessages;
        
        // Type A: Very short messages (< 15 chars avg) - reduced threshold
        if ($avgLength < 15) {
            $scores['A'] += 1.0;
        }
        // Type C: Long messages (> 100 chars avg) - increased threshold
        elseif ($avgLength > 100) {
            $scores['C'] += 1.5;
        }
        // Medium length - slight boost to B
        elseif ($avgLength >= 30 && $avgLength <= 80) {
            $scores['B'] += 0.5;
        }
        
        // Adjust based on question frequency
        $questionRatio = $questionCount / $totalMessages;
        if ($questionRatio > 0.3) {
            $scores['B'] += 1.0; // Concerned type asks questions
            $scores['C'] += 0.8; // Detail-oriented also asks questions
        }
        
        // Adjust based on politeness (indicates Type B - relationship-focused)
        $politeRatio = $politeCount / $totalMessages;
        if ($politeRatio > 0.5) {
            $scores['B'] += 1.5;
        } elseif ($politeRatio > 0.3) {
            $scores['B'] += 0.8;
        }
        
        // Adjust based on comparison requests (indicates Type C)
        if ($comparisonCount > 0) {
            $scores['C'] += $comparisonCount * 0.8;
        }
        
        // If no strong signals, default to balanced scores
        $totalScore = array_sum($scores);
        if ($totalScore < 1.0) {
            // No clear pattern - return balanced with slight A preference for wholesale
            $scores['A'] = 0.4;
            $scores['B'] = 0.3;
            $scores['C'] = 0.3;
        }
        
        // Normalize scores
        $maxScore = max($scores);
        if ($maxScore > 0) {
            foreach ($scores as $type => $score) {
                $scores[$type] = round($score / $maxScore, 2);
            }
        }
        
        return $scores;
    }
    
    /**
     * Determine communication type based on scores
     * @param array $scores Scores for each type
     * @return string Communication type (A, B, or C)
     */
    private function determineType(array $scores): string
    {
        $maxScore = 0;
        $type = self::TYPE_DIRECT; // Default
        
        foreach ($scores as $t => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $type = $t;
            }
        }
        
        return $type;
    }
    
    /**
     * Calculate confidence score based on score distribution
     * @param array $scores Scores for each type
     * @param string $selectedType Selected type
     * @return float Confidence (0-1)
     */
    private function calculateConfidence(array $scores, string $selectedType): float
    {
        $selectedScore = $scores[$selectedType] ?? 0;
        $otherScores = array_filter($scores, function($key) use ($selectedType) {
            return $key !== $selectedType;
        }, ARRAY_FILTER_USE_KEY);
        
        $maxOther = !empty($otherScores) ? max($otherScores) : 0;
        
        // Confidence is based on how much higher the selected score is
        if ($selectedScore == 0) {
            return 0.0;
        }
        
        // If selected is much higher than others, high confidence
        $diff = $selectedScore - $maxOther;
        
        // Map difference to confidence (0-1)
        // diff of 0.5+ = high confidence (0.8+)
        // diff of 0.2-0.5 = medium confidence (0.5-0.8)
        // diff of 0-0.2 = low confidence (0.3-0.5)
        
        if ($diff >= 0.5) {
            return min(1.0, 0.8 + ($diff - 0.5) * 0.4);
        } elseif ($diff >= 0.2) {
            return 0.5 + ($diff - 0.2) * 1.0;
        } else {
            return 0.3 + $diff * 1.0;
        }
    }

    
    /**
     * Get recommended draft style for customer type
     * 
     * Requirements 2.2, 2.3, 2.4: Different draft styles for each type
     * Property 5: Draft Style Matches Communication Type
     * 
     * @param string $type Communication type (A, B, C)
     * @return array ['maxWords' => int, 'useEmoji' => bool, 'includeDetails' => bool, 'tone' => string]
     */
    public function getDraftStyle(string $type): array
    {
        switch ($type) {
            case self::TYPE_DIRECT: // Type A - Direct
                // Requirements 2.2: Concise responses with clear drug recommendations
                return [
                    'type' => 'A',
                    'typeName' => 'Direct',
                    'typeNameTh' => 'ตรงประเด็น',
                    'maxWords' => 50,
                    'useEmoji' => false,
                    'includeDetails' => false,
                    'includePrice' => true,
                    'tone' => 'professional',
                    'toneTh' => 'มืออาชีพ',
                    'responseStyle' => 'concise',
                    'tips' => [
                        'ตอบสั้น กระชับ ตรงประเด็น',
                        'บอกชื่อยา ราคา วิธีใช้ ชัดเจน',
                        'ไม่ต้องอธิบายรายละเอียดมาก',
                        'เสนอทางเลือกไม่เกิน 2-3 ตัว'
                    ],
                    'sampleOpening' => 'แนะนำ',
                    'sampleClosing' => 'สนใจตัวไหนแจ้งได้เลยค่ะ'
                ];
                
            case self::TYPE_CONCERNED: // Type B - Concerned
                // Requirements 2.3: Empathetic responses with detailed explanations and reassurance
                return [
                    'type' => 'B',
                    'typeName' => 'Concerned',
                    'typeNameTh' => 'ห่วงใย',
                    'maxWords' => 150,
                    'useEmoji' => true,
                    'includeDetails' => true,
                    'includePrice' => false,
                    'tone' => 'empathetic',
                    'toneTh' => 'เห็นอกเห็นใจ',
                    'responseStyle' => 'reassuring',
                    'tips' => [
                        'แสดงความเข้าใจและห่วงใย',
                        'อธิบายความปลอดภัยของยา',
                        'ให้ความมั่นใจว่าอาการจะดีขึ้น',
                        'เปิดโอกาสให้ถามเพิ่มเติม'
                    ],
                    'sampleOpening' => 'เข้าใจความกังวลค่ะ 🙏',
                    'sampleClosing' => 'มีอะไรสงสัยถามได้เลยนะคะ ยินดีช่วยเหลือค่ะ 😊'
                ];
                
            case self::TYPE_DETAILED: // Type C - Detail-oriented
                // Requirements 2.4: Drug comparison tables, dosage charts, and scientific information
                return [
                    'type' => 'C',
                    'typeName' => 'Detail-oriented',
                    'typeNameTh' => 'ใส่ใจรายละเอียด',
                    'maxWords' => 300,
                    'useEmoji' => false,
                    'includeDetails' => true,
                    'includePrice' => true,
                    'includeComparison' => true,
                    'includeScientific' => true,
                    'tone' => 'informative',
                    'toneTh' => 'ให้ข้อมูล',
                    'responseStyle' => 'detailed',
                    'tips' => [
                        'ให้ข้อมูลครบถ้วน ละเอียด',
                        'เปรียบเทียบยาหลายตัว',
                        'อธิบายกลไกการออกฤทธิ์',
                        'แนบข้อมูลทางวิทยาศาสตร์'
                    ],
                    'sampleOpening' => 'ขอให้ข้อมูลเปรียบเทียบดังนี้ค่ะ',
                    'sampleClosing' => 'หากต้องการข้อมูลเพิ่มเติมยินดีค่ะ'
                ];
                
            default:
                // Default to Type A
                return $this->getDraftStyle(self::TYPE_DIRECT);
        }
    }
    
    /**
     * Get default tips for a communication type
     * @param string $type Communication type
     * @return array Tips
     */
    private function getDefaultTips(string $type): array
    {
        $style = $this->getDraftStyle($type);
        return $style['tips'] ?? [];
    }
    
    /**
     * Get type label in Thai
     * @param string $type Communication type
     * @return string Label
     */
    private function getTypeLabel(string $type): string
    {
        $labels = [
            'A' => 'ตรงประเด็น (Type A)',
            'B' => 'ห่วงใย (Type B)',
            'C' => 'ใส่ใจรายละเอียด (Type C)'
        ];
        return $labels[$type] ?? 'ไม่ระบุ';
    }

    
    /**
     * Get or create health profile for a user
     * @param int $userId User ID
     * @return array Profile data
     */
    private function getOrCreateProfile(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT communication_type, confidence, communication_tips, 
                       last_analyzed_at, message_count_analyzed, chronic_conditions
                FROM customer_health_profiles 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($profile) {
                return [
                    'communicationType' => $profile['communication_type'] ?? self::TYPE_DIRECT,
                    'confidence' => (float)($profile['confidence'] ?? 0),
                    'tips' => $profile['communication_tips'] ? json_decode($profile['communication_tips'], true) : null,
                    'lastAnalyzedAt' => $profile['last_analyzed_at'],
                    'messageCountAnalyzed' => (int)($profile['message_count_analyzed'] ?? 0),
                    'chronicConditions' => $profile['chronic_conditions'] ? json_decode($profile['chronic_conditions'], true) : []
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        // Return default profile
        return [
            'communicationType' => self::TYPE_DIRECT,
            'confidence' => 0.0,
            'tips' => null,
            'lastAnalyzedAt' => null,
            'messageCountAnalyzed' => 0,
            'chronicConditions' => []
        ];
    }
    
    /**
     * Save or update health profile
     * @param int $userId User ID
     * @param string $type Communication type
     * @param float $confidence Confidence score
     * @param array $tips Communication tips
     * @param int $messageCount Messages analyzed
     * @return bool Success
     */
    private function saveProfile(int $userId, string $type, float $confidence, array $tips, int $messageCount): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO customer_health_profiles 
                (user_id, communication_type, confidence, communication_tips, last_analyzed_at, message_count_analyzed)
                VALUES (?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    communication_type = VALUES(communication_type),
                    confidence = VALUES(confidence),
                    communication_tips = VALUES(communication_tips),
                    last_analyzed_at = NOW(),
                    message_count_analyzed = VALUES(message_count_analyzed)
            ");
            
            return $stmt->execute([
                $userId,
                $type,
                $confidence,
                json_encode($tips, JSON_UNESCAPED_UNICODE),
                $messageCount
            ]);
        } catch (PDOException $e) {
            error_log("CustomerHealthEngine saveProfile error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if customer has any drug allergies that match a given drug
     * @param int $userId User ID
     * @param string $drugName Drug name to check
     * @return array ['hasAllergy' => bool, 'matchedAllergies' => array]
     */
    public function checkDrugAllergy(int $userId, string $drugName): array
    {
        $allergies = $this->getAllergies($userId);
        $matchedAllergies = [];
        
        foreach ($allergies as $allergy) {
            // Check if drug name matches allergy
            if (mb_stripos($drugName, $allergy['name']) !== false ||
                mb_stripos($allergy['name'], $drugName) !== false) {
                $matchedAllergies[] = $allergy;
            }
        }
        
        return [
            'hasAllergy' => !empty($matchedAllergies),
            'matchedAllergies' => $matchedAllergies,
            'drugName' => $drugName,
            'totalAllergies' => count($allergies)
        ];
    }
    
    /**
     * Get allergy warning message for display
     * @param int $userId User ID
     * @return array|null Warning info or null if no allergies
     */
    public function getAllergyWarning(int $userId): ?array
    {
        $allergies = $this->getAllergies($userId);
        
        if (empty($allergies)) {
            return null;
        }
        
        $allergyNames = array_map(function($a) { return $a['name']; }, $allergies);
        
        return [
            'hasWarning' => true,
            'count' => count($allergies),
            'allergies' => $allergies,
            'allergyNames' => $allergyNames,
            'message' => 'ลูกค้าแพ้ยา: ' . implode(', ', $allergyNames),
            'severity' => $this->getHighestSeverity($allergies)
        ];
    }
    
    /**
     * Get highest severity from allergy list
     * @param array $allergies Allergies
     * @return string Severity level
     */
    private function getHighestSeverity(array $allergies): string
    {
        $severityOrder = ['severe' => 3, 'moderate' => 2, 'mild' => 1, 'unknown' => 0];
        $highest = 'unknown';
        $highestScore = 0;
        
        foreach ($allergies as $allergy) {
            $severity = $allergy['severity'] ?? 'unknown';
            $score = $severityOrder[$severity] ?? 0;
            if ($score > $highestScore) {
                $highestScore = $score;
                $highest = $severity;
            }
        }
        
        return $highest;
    }
    
    /**
     * Update customer's chronic conditions
     * @param int $userId User ID
     * @param array $conditions List of conditions
     * @return bool Success
     */
    public function updateChronicConditions(int $userId, array $conditions): bool
    {
        try {
            // Update in customer_health_profiles
            $stmt = $this->db->prepare("
                INSERT INTO customer_health_profiles (user_id, chronic_conditions)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE chronic_conditions = VALUES(chronic_conditions)
            ");
            $stmt->execute([$userId, json_encode($conditions, JSON_UNESCAPED_UNICODE)]);
            
            // Also update in users table if column exists
            try {
                $stmt = $this->db->prepare("
                    UPDATE users SET medical_conditions = ? WHERE id = ?
                ");
                $stmt->execute([implode(', ', $conditions), $userId]);
            } catch (PDOException $e) {
                // Column might not exist
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("CustomerHealthEngine updateChronicConditions error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Re-analyze customer classification (force refresh)
     * @param int $userId User ID
     * @return array Classification result
     */
    public function reanalyzeCustomer(int $userId): array
    {
        return $this->classifyCustomer($userId, 1); // Use minimum 1 message for re-analysis
    }
    
    /**
     * Get summary for HUD display
     * @param int $userId User ID
     * @return array Summary data
     */
    public function getHUDSummary(int $userId): array
    {
        $profile = $this->getHealthProfile($userId);
        $allergyWarning = $this->getAllergyWarning($userId);
        
        return [
            'userId' => $userId,
            'communicationType' => $profile['communicationType'],
            'communicationLabel' => $profile['communicationTypeLabel'],
            'hasAllergies' => !empty($profile['allergies']),
            'allergyCount' => count($profile['allergies']),
            'allergyWarning' => $allergyWarning,
            'medicationCount' => count($profile['medications']),
            'conditionCount' => count($profile['conditions']),
            'draftStyle' => $profile['draftStyle'],
            'tips' => $profile['tips']
        ];
    }
}
