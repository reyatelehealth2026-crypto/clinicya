<?php
/**
 * Pharmacy Image Analyzer Service
 * 
 * Provides AI-powered image analysis for pharmacy consultations including:
 * - Symptom image analysis (rash, wound, skin conditions)
 * - Drug/medicine photo identification
 * - Prescription OCR and drug extraction
 * - Urgency detection for hospital referrals
 * 
 * Uses Google Gemini Vision API for image analysis.
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
 */

class PharmacyImageAnalyzerService
{
    private $db;
    private $lineAccountId;
    private $apiKey;
    private $model;
    
    // API Configuration
    const DEFAULT_MODEL = 'gemini-2.5-flash';
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    const CACHE_EXPIRY_HOURS = 24;
    
    // Severity levels
    const SEVERITY_MILD = 'mild';
    const SEVERITY_MODERATE = 'moderate';
    const SEVERITY_SEVERE = 'severe';
    const SEVERITY_URGENT = 'urgent';
    
    // Urgent conditions that require hospital visit
    private $urgentConditions = [
        'severe allergic reaction',
        'anaphylaxis',
        'difficulty breathing',
        'chest pain',
        'severe bleeding',
        'deep wound',
        'burn',
        'severe infection',
        'high fever',
        'loss of consciousness',
        'severe swelling',
        'อาการแพ้รุนแรง',
        'หายใจลำบาก',
        'เจ็บหน้าอก',
        'เลือดออกมาก',
        'แผลลึก',
        'ไฟไหม้',
        'ติดเชื้อรุนแรง',
        'ไข้สูงมาก',
        'หมดสติ',
        'บวมมาก'
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
            // Try ai_settings table first
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
     * Analyze symptom image (rash, wound, skin condition)
     * 
     * Requirements 1.1: Analyze symptom images and identify possible conditions with severity level
     * 
     * @param string $imageUrl URL of the symptom image
     * @return array ['condition' => string, 'severity' => string, 'urgency' => bool, 'recommendations' => array]
     */
    public function analyzeSymptom(string $imageUrl): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'condition' => null,
                'severity' => null,
                'urgency' => false,
                'recommendations' => []
            ];
        }
        
        // Check cache first
        $imageHash = $this->hashImage($imageUrl);
        $cached = $this->getCachedSymptomAnalysis($imageHash);
        
        if ($cached) {
            return $cached;
        }
        
        // Build prompt for symptom analysis
        $prompt = $this->buildSymptomAnalysisPrompt();
        
        try {
            // Call Gemini Vision API
            $response = $this->callVisionAPI($imageUrl, $prompt);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'API call failed',
                    'condition' => null,
                    'severity' => null,
                    'urgency' => false,
                    'recommendations' => []
                ];
            }
            
            // Parse the response
            $analysis = $this->parseSymptomResponse($response['text']);
            
            // Check urgency
            $urgencyCheck = $this->checkUrgency($analysis);
            $analysis['urgency'] = $urgencyCheck['isUrgent'];
            $analysis['urgencyReason'] = $urgencyCheck['reason'];
            $analysis['urgencyRecommendation'] = $urgencyCheck['recommendation'];
            
            // Cache the result
            $this->cacheSymptomAnalysis($imageHash, $imageUrl, $analysis);
            
            $analysis['success'] = true;
            $analysis['imageHash'] = $imageHash;
            
            return $analysis;
            
        } catch (Exception $e) {
            error_log("PharmacyImageAnalyzer analyzeSymptom error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'condition' => null,
                'severity' => null,
                'urgency' => false,
                'recommendations' => []
            ];
        }
    }
    
    /**
     * Build prompt for symptom analysis
     * @return string
     */
    private function buildSymptomAnalysisPrompt(): string
    {
        return "คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาวิเคราะห์รูปภาพอาการนี้และตอบในรูปแบบ JSON ดังนี้:

{
    \"condition\": \"ชื่ออาการ/โรคที่เป็นไปได้ (ภาษาไทย)\",
    \"conditionEn\": \"Condition name in English\",
    \"description\": \"คำอธิบายอาการที่เห็นในรูป\",
    \"severity\": \"mild/moderate/severe/urgent\",
    \"possibleCauses\": [\"สาเหตุที่เป็นไปได้ 1\", \"สาเหตุที่เป็นไปได้ 2\"],
    \"recommendations\": [
        {\"type\": \"medication\", \"name\": \"ชื่อยา\", \"usage\": \"วิธีใช้\"},
        {\"type\": \"care\", \"instruction\": \"คำแนะนำการดูแล\"}
    ],
    \"warnings\": [\"ข้อควรระวัง\"],
    \"needsDoctor\": true/false,
    \"doctorReason\": \"เหตุผลที่ควรพบแพทย์ (ถ้ามี)\"
}

กรุณาวิเคราะห์อย่างระมัดระวังและให้คำแนะนำที่ปลอดภัย หากอาการรุนแรงหรือไม่แน่ใจ ให้แนะนำพบแพทย์";
    }

    
    /**
     * Parse symptom analysis response from AI
     * @param string $responseText AI response text
     * @return array Parsed analysis
     */
    private function parseSymptomResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($responseText);
        
        if ($json) {
            return [
                'condition' => $json['condition'] ?? 'ไม่สามารถระบุได้',
                'conditionEn' => $json['conditionEn'] ?? null,
                'description' => $json['description'] ?? '',
                'severity' => $this->normalizeSeverity($json['severity'] ?? 'mild'),
                'possibleCauses' => $json['possibleCauses'] ?? [],
                'recommendations' => $json['recommendations'] ?? [],
                'warnings' => $json['warnings'] ?? [],
                'needsDoctor' => $json['needsDoctor'] ?? false,
                'doctorReason' => $json['doctorReason'] ?? null,
                'rawResponse' => $responseText
            ];
        }
        
        // Fallback: parse as plain text
        return [
            'condition' => 'ไม่สามารถวิเคราะห์ได้',
            'conditionEn' => null,
            'description' => $responseText,
            'severity' => self::SEVERITY_MILD,
            'possibleCauses' => [],
            'recommendations' => [],
            'warnings' => ['กรุณาปรึกษาเภสัชกรหรือแพทย์'],
            'needsDoctor' => true,
            'doctorReason' => 'ไม่สามารถวิเคราะห์รูปภาพได้ชัดเจน',
            'rawResponse' => $responseText
        ];
    }
    
    /**
     * Identify drug from photo
     * 
     * Requirements 1.2: Identify drug name, dosage, usage instructions, and contraindications
     * 
     * @param string $imageUrl URL of the drug/medicine photo
     * @return array ['drugName' => string, 'genericName' => string, 'dosage' => string, 'usage' => string, 'contraindications' => array]
     */
    public function identifyDrug(string $imageUrl): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'drugName' => null,
                'genericName' => null,
                'dosage' => null,
                'usage' => null,
                'contraindications' => []
            ];
        }
        
        // Check cache first
        $imageHash = $this->hashImage($imageUrl);
        $cached = $this->getCachedDrugRecognition($imageHash);
        
        if ($cached) {
            return $cached;
        }
        
        // Build prompt for drug identification
        $prompt = $this->buildDrugIdentificationPrompt();
        
        try {
            // Call Gemini Vision API
            $response = $this->callVisionAPI($imageUrl, $prompt);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'API call failed',
                    'drugName' => null,
                    'genericName' => null,
                    'dosage' => null,
                    'usage' => null,
                    'contraindications' => []
                ];
            }
            
            // Parse the response
            $drugInfo = $this->parseDrugResponse($response['text']);
            
            // Try to match with database product
            $matchedProduct = $this->matchDrugToProduct($drugInfo['drugName'], $drugInfo['genericName']);
            $drugInfo['matchedProductId'] = $matchedProduct['id'] ?? null;
            $drugInfo['matchedProductName'] = $matchedProduct['name'] ?? null;
            $drugInfo['inStock'] = $matchedProduct['inStock'] ?? false;
            $drugInfo['price'] = $matchedProduct['price'] ?? null;
            
            // Cache the result
            $this->cacheDrugRecognition($imageHash, $imageUrl, $drugInfo);
            
            $drugInfo['success'] = true;
            $drugInfo['imageHash'] = $imageHash;
            
            return $drugInfo;
            
        } catch (Exception $e) {
            error_log("PharmacyImageAnalyzer identifyDrug error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'drugName' => null,
                'genericName' => null,
                'dosage' => null,
                'usage' => null,
                'contraindications' => []
            ];
        }
    }
    
    /**
     * Build prompt for drug identification
     * @return string
     */
    private function buildDrugIdentificationPrompt(): string
    {
        return "คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาระบุยาจากรูปภาพนี้และตอบในรูปแบบ JSON ดังนี้:

{
    \"drugName\": \"ชื่อการค้าของยา\",
    \"genericName\": \"ชื่อสามัญทางยา\",
    \"manufacturer\": \"บริษัทผู้ผลิต\",
    \"dosageForm\": \"รูปแบบยา (เม็ด/แคปซูล/น้ำ/ครีม)\",
    \"strength\": \"ความแรง (เช่น 500mg)\",
    \"usage\": \"วิธีใช้และขนาดยา\",
    \"indications\": [\"ข้อบ่งใช้ 1\", \"ข้อบ่งใช้ 2\"],
    \"contraindications\": [\"ข้อห้ามใช้ 1\", \"ข้อห้ามใช้ 2\"],
    \"sideEffects\": [\"ผลข้างเคียง 1\", \"ผลข้างเคียง 2\"],
    \"warnings\": [\"คำเตือน\"],
    \"drugCategory\": \"หมวดหมู่ยา (ยาสามัญประจำบ้าน/ยาอันตราย/ยาควบคุมพิเศษ)\",
    \"isPrescriptionRequired\": true/false,
    \"confidence\": 0.0-1.0
}

หากไม่สามารถระบุยาได้ชัดเจน ให้ตั้ง confidence ต่ำและระบุเหตุผล";
    }

    
    /**
     * Parse drug identification response from AI
     * @param string $responseText AI response text
     * @return array Parsed drug info
     */
    private function parseDrugResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($responseText);
        
        if ($json) {
            return [
                'drugName' => $json['drugName'] ?? null,
                'genericName' => $json['genericName'] ?? null,
                'manufacturer' => $json['manufacturer'] ?? null,
                'dosageForm' => $json['dosageForm'] ?? null,
                'strength' => $json['strength'] ?? null,
                'dosage' => $json['strength'] ?? null, // Alias for compatibility
                'usage' => $json['usage'] ?? null,
                'indications' => $json['indications'] ?? [],
                'contraindications' => $json['contraindications'] ?? [],
                'sideEffects' => $json['sideEffects'] ?? [],
                'warnings' => $json['warnings'] ?? [],
                'drugCategory' => $json['drugCategory'] ?? null,
                'isPrescriptionRequired' => $json['isPrescriptionRequired'] ?? false,
                'confidence' => (float)($json['confidence'] ?? 0.5),
                'rawResponse' => $responseText
            ];
        }
        
        // Fallback
        return [
            'drugName' => null,
            'genericName' => null,
            'manufacturer' => null,
            'dosageForm' => null,
            'strength' => null,
            'dosage' => null,
            'usage' => null,
            'indications' => [],
            'contraindications' => [],
            'sideEffects' => [],
            'warnings' => ['ไม่สามารถระบุยาได้ กรุณาปรึกษาเภสัชกร'],
            'drugCategory' => null,
            'isPrescriptionRequired' => true,
            'confidence' => 0.0,
            'rawResponse' => $responseText
        ];
    }
    
    /**
     * OCR prescription and extract drug list
     * 
     * Requirements 1.3: OCR extract drug names, dosages, and create structured drug list
     * Requirements 1.4: Check for drug interactions and allergies
     * 
     * @param string $imageUrl URL of the prescription image
     * @param int|null $userId User ID for allergy/interaction check
     * @return array ['drugs' => array, 'doctor' => string, 'date' => string, 'hospital' => string]
     */
    public function ocrPrescription(string $imageUrl, ?int $userId = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'drugs' => [],
                'doctor' => null,
                'date' => null,
                'hospital' => null
            ];
        }
        
        // Build prompt for prescription OCR
        $prompt = $this->buildPrescriptionOCRPrompt();
        
        try {
            // Call Gemini Vision API
            $response = $this->callVisionAPI($imageUrl, $prompt);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'API call failed',
                    'drugs' => [],
                    'doctor' => null,
                    'date' => null,
                    'hospital' => null
                ];
            }
            
            // Parse the response
            $prescription = $this->parsePrescriptionResponse($response['text']);
            
            // Check drug interactions if we have multiple drugs
            if (count($prescription['drugs']) > 1) {
                $prescription['interactions'] = $this->checkDrugInteractions($prescription['drugs'], $userId);
            } else {
                $prescription['interactions'] = [];
            }
            
            // Check allergies if user ID provided
            if ($userId) {
                $prescription['allergyWarnings'] = $this->checkPrescriptionAllergies($prescription['drugs'], $userId);
            } else {
                $prescription['allergyWarnings'] = [];
            }
            
            // Cache the result
            $imageHash = $this->hashImage($imageUrl);
            $this->cachePrescriptionOCR($imageHash, $imageUrl, $prescription, $userId);
            
            $prescription['success'] = true;
            $prescription['imageHash'] = $imageHash;
            
            return $prescription;
            
        } catch (Exception $e) {
            error_log("PharmacyImageAnalyzer ocrPrescription error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'drugs' => [],
                'doctor' => null,
                'date' => null,
                'hospital' => null
            ];
        }
    }
    
    /**
     * Build prompt for prescription OCR
     * @return string
     */
    private function buildPrescriptionOCRPrompt(): string
    {
        return "คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาอ่านใบสั่งยานี้และแปลงเป็น JSON ดังนี้:

{
    \"doctor\": \"ชื่อแพทย์ผู้สั่งยา\",
    \"hospital\": \"ชื่อโรงพยาบาล/คลินิก\",
    \"date\": \"วันที่ในใบสั่งยา (YYYY-MM-DD)\",
    \"patientName\": \"ชื่อผู้ป่วย (ถ้ามี)\",
    \"diagnosis\": \"การวินิจฉัย (ถ้ามี)\",
    \"drugs\": [
        {
            \"name\": \"ชื่อยา\",
            \"genericName\": \"ชื่อสามัญ (ถ้าทราบ)\",
            \"dosage\": \"ขนาดยา\",
            \"frequency\": \"ความถี่ในการรับประทาน\",
            \"duration\": \"ระยะเวลา\",
            \"quantity\": \"จำนวน\",
            \"instructions\": \"คำแนะนำเพิ่มเติม\"
        }
    ],
    \"notes\": \"หมายเหตุอื่นๆ\",
    \"confidence\": 0.0-1.0
}

กรุณาอ่านให้ละเอียดและถูกต้อง หากอ่านไม่ชัดให้ระบุ [อ่านไม่ชัด] และตั้ง confidence ต่ำ";
    }

    
    /**
     * Parse prescription OCR response from AI
     * @param string $responseText AI response text
     * @return array Parsed prescription data
     */
    private function parsePrescriptionResponse(string $responseText): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($responseText);
        
        if ($json) {
            return [
                'doctor' => $json['doctor'] ?? null,
                'hospital' => $json['hospital'] ?? null,
                'date' => $json['date'] ?? null,
                'patientName' => $json['patientName'] ?? null,
                'diagnosis' => $json['diagnosis'] ?? null,
                'drugs' => $json['drugs'] ?? [],
                'notes' => $json['notes'] ?? null,
                'confidence' => (float)($json['confidence'] ?? 0.5),
                'rawResponse' => $responseText
            ];
        }
        
        // Fallback
        return [
            'doctor' => null,
            'hospital' => null,
            'date' => null,
            'patientName' => null,
            'diagnosis' => null,
            'drugs' => [],
            'notes' => 'ไม่สามารถอ่านใบสั่งยาได้ กรุณาส่งรูปที่ชัดเจนกว่านี้',
            'confidence' => 0.0,
            'rawResponse' => $responseText
        ];
    }
    
    /**
     * Check if symptom requires urgent hospital visit
     * 
     * Requirements 1.5: Display warning and recommend hospital visit for urgent symptoms
     * Property 13: Urgent Symptom Detection
     * 
     * @param array $analysisResult Result from analyzeSymptom
     * @return array ['isUrgent' => bool, 'reason' => string, 'recommendation' => string]
     */
    public function checkUrgency(array $analysisResult): array
    {
        $isUrgent = false;
        $reason = null;
        $recommendation = null;
        
        // Check severity level
        $severity = $analysisResult['severity'] ?? self::SEVERITY_MILD;
        if ($severity === self::SEVERITY_SEVERE || $severity === self::SEVERITY_URGENT) {
            $isUrgent = true;
            $reason = 'อาการมีความรุนแรงระดับ ' . $this->getSeverityLabel($severity);
            $recommendation = 'แนะนำให้พบแพทย์โดยเร็วที่สุด';
        }
        
        // Check if doctor is needed
        if (!$isUrgent && ($analysisResult['needsDoctor'] ?? false)) {
            $isUrgent = true;
            $reason = $analysisResult['doctorReason'] ?? 'อาการต้องการการตรวจจากแพทย์';
            $recommendation = 'แนะนำให้พบแพทย์เพื่อตรวจวินิจฉัย';
        }
        
        // Check condition against urgent conditions list
        if (!$isUrgent) {
            $condition = mb_strtolower($analysisResult['condition'] ?? '');
            $conditionEn = strtolower($analysisResult['conditionEn'] ?? '');
            $description = mb_strtolower($analysisResult['description'] ?? '');
            
            foreach ($this->urgentConditions as $urgentCondition) {
                $urgentLower = mb_strtolower($urgentCondition);
                if (mb_strpos($condition, $urgentLower) !== false ||
                    strpos($conditionEn, $urgentLower) !== false ||
                    mb_strpos($description, $urgentLower) !== false) {
                    $isUrgent = true;
                    $reason = 'ตรวจพบอาการที่อาจเป็นอันตราย: ' . $urgentCondition;
                    $recommendation = '⚠️ กรุณาไปพบแพทย์หรือห้องฉุกเฉินทันที';
                    break;
                }
            }
        }
        
        // Check warnings for urgent keywords
        if (!$isUrgent && !empty($analysisResult['warnings'])) {
            foreach ($analysisResult['warnings'] as $warning) {
                $warningLower = mb_strtolower($warning);
                if (mb_strpos($warningLower, 'ฉุกเฉิน') !== false ||
                    mb_strpos($warningLower, 'อันตราย') !== false ||
                    mb_strpos($warningLower, 'รุนแรง') !== false ||
                    strpos($warningLower, 'emergency') !== false ||
                    strpos($warningLower, 'urgent') !== false) {
                    $isUrgent = true;
                    $reason = $warning;
                    $recommendation = 'แนะนำให้พบแพทย์โดยเร็ว';
                    break;
                }
            }
        }
        
        return [
            'isUrgent' => $isUrgent,
            'reason' => $reason,
            'recommendation' => $recommendation,
            'severity' => $severity,
            'severityLabel' => $this->getSeverityLabel($severity)
        ];
    }
    
    /**
     * Get severity label in Thai
     * @param string $severity Severity level
     * @return string Label
     */
    private function getSeverityLabel(string $severity): string
    {
        $labels = [
            self::SEVERITY_MILD => 'เล็กน้อย',
            self::SEVERITY_MODERATE => 'ปานกลาง',
            self::SEVERITY_SEVERE => 'รุนแรง',
            self::SEVERITY_URGENT => 'ฉุกเฉิน'
        ];
        return $labels[$severity] ?? 'ไม่ระบุ';
    }
    
    /**
     * Normalize severity value
     * @param string $severity Raw severity value
     * @return string Normalized severity
     */
    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        
        $mapping = [
            'mild' => self::SEVERITY_MILD,
            'เล็กน้อย' => self::SEVERITY_MILD,
            'น้อย' => self::SEVERITY_MILD,
            'moderate' => self::SEVERITY_MODERATE,
            'ปานกลาง' => self::SEVERITY_MODERATE,
            'กลาง' => self::SEVERITY_MODERATE,
            'severe' => self::SEVERITY_SEVERE,
            'รุนแรง' => self::SEVERITY_SEVERE,
            'มาก' => self::SEVERITY_SEVERE,
            'urgent' => self::SEVERITY_URGENT,
            'ฉุกเฉิน' => self::SEVERITY_URGENT,
            'emergency' => self::SEVERITY_URGENT
        ];
        
        return $mapping[$severity] ?? self::SEVERITY_MILD;
    }

    
    /**
     * Call Gemini Vision API with image
     * @param string $imageUrl Image URL
     * @param string $prompt Text prompt
     * @return array API response
     */
    private function callVisionAPI(string $imageUrl, string $prompt): array
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        // Prepare image data
        $imageData = $this->getImageData($imageUrl);
        
        if (!$imageData['success']) {
            return [
                'success' => false,
                'error' => $imageData['error'] ?? 'Failed to load image'
            ];
        }
        
        // Build request with image
        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'inline_data' => [
                                'mime_type' => $imageData['mimeType'],
                                'data' => $imageData['base64']
                            ]
                        ],
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3, // Lower temperature for more consistent analysis
                'maxOutputTokens' => 2000,
                'topP' => 0.8,
                'topK' => 20
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Connection error: ' . $curlError
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
     * Get image data as base64
     * @param string $imageUrl Image URL
     * @return array ['success' => bool, 'base64' => string, 'mimeType' => string]
     */
    private function getImageData(string $imageUrl): array
    {
        // Handle base64 data URLs directly
        if (strpos($imageUrl, 'data:image/') === 0) {
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageUrl, $matches)) {
                $mimeType = 'image/' . $matches[1];
                $base64Data = $matches[2];
                return [
                    'success' => true,
                    'base64' => $base64Data,
                    'mimeType' => $mimeType
                ];
            }
            return [
                'success' => false,
                'error' => 'รูปแบบ data URL ไม่ถูกต้อง'
            ];
        }
        
        // Handle LINE content URLs
        if (strpos($imageUrl, 'api-data.line.me') !== false) {
            return $this->getLineImageData($imageUrl);
        }
        
        // Handle local file paths (relative or absolute)
        if (strpos($imageUrl, 'http') !== 0) {
            // It's a local path
            $localPath = $imageUrl;
            if (strpos($localPath, '/') === 0) {
                // Absolute path from web root
                $localPath = $_SERVER['DOCUMENT_ROOT'] . $localPath;
            }
            
            if (file_exists($localPath)) {
                $imageContent = file_get_contents($localPath);
                if ($imageContent !== false) {
                    $mimeType = $this->detectMimeType($imageContent, mime_content_type($localPath));
                    return [
                        'success' => true,
                        'base64' => base64_encode($imageContent),
                        'mimeType' => $mimeType
                    ];
                }
            }
            
            return [
                'success' => false,
                'error' => 'ไม่พบไฟล์รูปภาพ: ' . basename($imageUrl)
            ];
        }
        
        // Check if it's a local server URL - try to read directly
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($currentHost) && strpos($imageUrl, $currentHost) !== false) {
            // Extract path from URL
            $parsedUrl = parse_url($imageUrl);
            $localPath = $_SERVER['DOCUMENT_ROOT'] . ($parsedUrl['path'] ?? '');
            
            if (file_exists($localPath)) {
                $imageContent = file_get_contents($localPath);
                if ($imageContent !== false) {
                    $mimeType = $this->detectMimeType($imageContent, mime_content_type($localPath));
                    return [
                        'success' => true,
                        'base64' => base64_encode($imageContent),
                        'mimeType' => $mimeType
                    ];
                }
            }
        }
        
        // Handle regular URLs via HTTP
        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false, // Allow self-signed certs for local dev
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PharmacyImageAnalyzer/1.0)'
        ]);
        
        $imageContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'ไม่สามารถเชื่อมต่อเพื่อดาวน์โหลดรูปภาพ: ' . $curlError
            ];
        }
        
        if ($httpCode === 404) {
            return [
                'success' => false,
                'error' => 'ไม่พบไฟล์รูปภาพ (404): ' . basename($imageUrl)
            ];
        }
        
        if ($httpCode !== 200 || empty($imageContent)) {
            return [
                'success' => false,
                'error' => 'ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP ' . $httpCode . ')'
            ];
        }
        
        // Determine MIME type
        $mimeType = $this->detectMimeType($imageContent, $contentType);
        
        return [
            'success' => true,
            'base64' => base64_encode($imageContent),
            'mimeType' => $mimeType
        ];
    }
    
    /**
     * Get LINE image data with authentication
     * @param string $imageUrl LINE content URL
     * @return array Image data
     */
    private function getLineImageData(string $imageUrl): array
    {
        // Get LINE channel access token
        $accessToken = $this->getLineAccessToken();
        
        if (!$accessToken) {
            return [
                'success' => false,
                'error' => 'LINE access token not configured'
            ];
        }
        
        $ch = curl_init($imageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken
            ],
            CURLOPT_TIMEOUT => 15
        ]);
        
        $imageContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($imageContent)) {
            return [
                'success' => false,
                'error' => 'Failed to download LINE image'
            ];
        }
        
        $mimeType = $this->detectMimeType($imageContent, $contentType);
        
        return [
            'success' => true,
            'base64' => base64_encode($imageContent),
            'mimeType' => $mimeType
        ];
    }
    
    /**
     * Get LINE channel access token
     * @return string|null Access token
     */
    private function getLineAccessToken(): ?string
    {
        try {
            $stmt = $this->db->prepare("
                SELECT channel_access_token 
                FROM line_accounts 
                WHERE id = ? OR id IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['channel_access_token'] ?? null;
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * Detect MIME type from content
     * @param string $content Image content
     * @param string|null $contentType Content-Type header
     * @return string MIME type
     */
    private function detectMimeType(string $content, ?string $contentType = null): string
    {
        // Check content type header first
        if ($contentType) {
            if (strpos($contentType, 'jpeg') !== false || strpos($contentType, 'jpg') !== false) {
                return 'image/jpeg';
            }
            if (strpos($contentType, 'png') !== false) {
                return 'image/png';
            }
            if (strpos($contentType, 'gif') !== false) {
                return 'image/gif';
            }
            if (strpos($contentType, 'webp') !== false) {
                return 'image/webp';
            }
        }
        
        // Check magic bytes
        $header = substr($content, 0, 8);
        
        if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        if (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        if (substr($header, 0, 6) === 'GIF87a' || substr($header, 0, 6) === 'GIF89a') {
            return 'image/gif';
        }
        if (substr($header, 0, 4) === 'RIFF' && substr($content, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        
        // Default to JPEG
        return 'image/jpeg';
    }

    
    /**
     * Extract JSON from AI response text
     * @param string $text Response text
     * @return array|null Parsed JSON or null
     */
    private function extractJson(string $text): ?array
    {
        // Try to find JSON in the response
        // First, try direct parse
        $decoded = json_decode($text, true);
        if ($decoded !== null) {
            return $decoded;
        }
        
        // Try to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)\n?```/', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        
        // Try to find JSON object in text
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded !== null) {
                return $decoded;
            }
        }
        
        return null;
    }
    
    /**
     * Hash image URL for caching
     * @param string $imageUrl Image URL
     * @return string Hash
     */
    private function hashImage(string $imageUrl): string
    {
        return hash('sha256', $imageUrl);
    }
    
    /**
     * Get cached symptom analysis
     * @param string $imageHash Image hash
     * @return array|null Cached result or null
     */
    private function getCachedSymptomAnalysis(string $imageHash): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT analysis_result, is_urgent, created_at
                FROM symptom_analysis_cache
                WHERE image_hash = ? AND expires_at > NOW()
            ");
            $stmt->execute([$imageHash]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $analysis = json_decode($result['analysis_result'], true);
                $analysis['cached'] = true;
                $analysis['cachedAt'] = $result['created_at'];
                return $analysis;
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        return null;
    }
    
    /**
     * Cache symptom analysis result
     * @param string $imageHash Image hash
     * @param string $imageUrl Image URL
     * @param array $analysis Analysis result
     * @return bool Success
     */
    private function cacheSymptomAnalysis(string $imageHash, string $imageUrl, array $analysis): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO symptom_analysis_cache 
                (image_hash, image_url, analysis_result, is_urgent, expires_at)
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
                ON DUPLICATE KEY UPDATE
                    analysis_result = VALUES(analysis_result),
                    is_urgent = VALUES(is_urgent),
                    expires_at = VALUES(expires_at)
            ");
            
            return $stmt->execute([
                $imageHash,
                $imageUrl,
                json_encode($analysis, JSON_UNESCAPED_UNICODE),
                $analysis['urgency'] ?? false ? 1 : 0,
                self::CACHE_EXPIRY_HOURS
            ]);
        } catch (PDOException $e) {
            error_log("PharmacyImageAnalyzer cacheSymptomAnalysis error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cached drug recognition
     * @param string $imageHash Image hash
     * @return array|null Cached result or null
     */
    private function getCachedDrugRecognition(string $imageHash): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT recognition_result, drug_name, generic_name, matched_product_id, created_at
                FROM drug_recognition_cache
                WHERE image_hash = ? AND expires_at > NOW()
            ");
            $stmt->execute([$imageHash]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $drugInfo = json_decode($result['recognition_result'], true);
                $drugInfo['cached'] = true;
                $drugInfo['cachedAt'] = $result['created_at'];
                return $drugInfo;
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        return null;
    }
    
    /**
     * Cache drug recognition result
     * @param string $imageHash Image hash
     * @param string $imageUrl Image URL
     * @param array $drugInfo Drug info
     * @return bool Success
     */
    private function cacheDrugRecognition(string $imageHash, string $imageUrl, array $drugInfo): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO drug_recognition_cache 
                (image_hash, image_url, drug_name, generic_name, matched_product_id, recognition_result, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
                ON DUPLICATE KEY UPDATE
                    drug_name = VALUES(drug_name),
                    generic_name = VALUES(generic_name),
                    matched_product_id = VALUES(matched_product_id),
                    recognition_result = VALUES(recognition_result),
                    expires_at = VALUES(expires_at)
            ");
            
            return $stmt->execute([
                $imageHash,
                $imageUrl,
                $drugInfo['drugName'] ?? null,
                $drugInfo['genericName'] ?? null,
                $drugInfo['matchedProductId'] ?? null,
                json_encode($drugInfo, JSON_UNESCAPED_UNICODE),
                self::CACHE_EXPIRY_HOURS
            ]);
        } catch (PDOException $e) {
            error_log("PharmacyImageAnalyzer cacheDrugRecognition error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cache prescription OCR result
     * @param string $imageHash Image hash
     * @param string $imageUrl Image URL
     * @param array $prescription Prescription data
     * @param int|null $userId User ID
     * @return bool Success
     */
    private function cachePrescriptionOCR(string $imageHash, string $imageUrl, array $prescription, ?int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO prescription_ocr_results 
                (user_id, image_hash, image_url, extracted_drugs, doctor_name, hospital_name, prescription_date, ocr_confidence)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            return $stmt->execute([
                $userId ?? 0,
                $imageHash,
                $imageUrl,
                json_encode($prescription['drugs'] ?? [], JSON_UNESCAPED_UNICODE),
                $prescription['doctor'] ?? null,
                $prescription['hospital'] ?? null,
                $prescription['date'] ?? null,
                $prescription['confidence'] ?? 0.5
            ]);
        } catch (PDOException $e) {
            error_log("PharmacyImageAnalyzer cachePrescriptionOCR error: " . $e->getMessage());
            return false;
        }
    }

    
    /**
     * Match drug name to product in database
     * @param string|null $drugName Drug name
     * @param string|null $genericName Generic name
     * @return array|null Matched product or null
     */
    private function matchDrugToProduct(?string $drugName, ?string $genericName): ?array
    {
        if (empty($drugName) && empty($genericName)) {
            return null;
        }
        
        try {
            // Try exact match first
            $stmt = $this->db->prepare("
                SELECT id, name, price, stock_quantity
                FROM business_items
                WHERE (name LIKE ? OR name LIKE ?)
                AND is_active = 1
                AND (line_account_id = ? OR line_account_id IS NULL)
                LIMIT 1
            ");
            $stmt->execute([
                '%' . ($drugName ?? '') . '%',
                '%' . ($genericName ?? '') . '%',
                $this->lineAccountId
            ]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                return [
                    'id' => (int)$product['id'],
                    'name' => $product['name'],
                    'price' => (float)$product['price'],
                    'inStock' => (int)$product['stock_quantity'] > 0
                ];
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        return null;
    }
    
    /**
     * Check drug interactions for prescription drugs
     * @param array $drugs List of drugs from prescription
     * @param int|null $userId User ID for current medications
     * @return array Interactions found
     */
    private function checkDrugInteractions(array $drugs, ?int $userId = null): array
    {
        $interactions = [];
        $drugNames = array_map(function($d) {
            return $d['name'] ?? $d['genericName'] ?? '';
        }, $drugs);
        
        // Add user's current medications if available
        if ($userId) {
            try {
                $stmt = $this->db->prepare("SELECT current_medications FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && !empty($user['current_medications'])) {
                    $currentMeds = preg_split('/[,\n]+/', $user['current_medications']);
                    $drugNames = array_merge($drugNames, array_map('trim', $currentMeds));
                }
            } catch (PDOException $e) {
                // Ignore
            }
        }
        
        // Check interactions in drug_interactions table
        try {
            foreach ($drugNames as $i => $drug1) {
                for ($j = $i + 1; $j < count($drugNames); $j++) {
                    $drug2 = $drugNames[$j];
                    
                    $stmt = $this->db->prepare("
                        SELECT severity, description, recommendation
                        FROM drug_interactions
                        WHERE (drug1_name LIKE ? AND drug2_name LIKE ?)
                        OR (drug1_name LIKE ? AND drug2_name LIKE ?)
                    ");
                    $stmt->execute([
                        '%' . $drug1 . '%', '%' . $drug2 . '%',
                        '%' . $drug2 . '%', '%' . $drug1 . '%'
                    ]);
                    $interaction = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($interaction) {
                        $interactions[] = [
                            'drug1' => $drug1,
                            'drug2' => $drug2,
                            'severity' => $interaction['severity'],
                            'description' => $interaction['description'],
                            'recommendation' => $interaction['recommendation']
                        ];
                    }
                }
            }
        } catch (PDOException $e) {
            // drug_interactions table might not exist
        }
        
        return $interactions;
    }
    
    /**
     * Check prescription drugs against user allergies
     * @param array $drugs List of drugs from prescription
     * @param int $userId User ID
     * @return array Allergy warnings
     */
    private function checkPrescriptionAllergies(array $drugs, int $userId): array
    {
        $warnings = [];
        
        try {
            // Get user allergies
            $stmt = $this->db->prepare("SELECT drug_allergies FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || empty($user['drug_allergies'])) {
                return [];
            }
            
            $allergies = preg_split('/[,\n]+/', $user['drug_allergies']);
            $allergies = array_map('trim', array_filter($allergies));
            
            foreach ($drugs as $drug) {
                $drugName = mb_strtolower($drug['name'] ?? '');
                $genericName = mb_strtolower($drug['genericName'] ?? '');
                
                foreach ($allergies as $allergy) {
                    $allergyLower = mb_strtolower($allergy);
                    
                    if (mb_strpos($drugName, $allergyLower) !== false ||
                        mb_strpos($genericName, $allergyLower) !== false ||
                        mb_strpos($allergyLower, $drugName) !== false ||
                        mb_strpos($allergyLower, $genericName) !== false) {
                        $warnings[] = [
                            'drug' => $drug['name'] ?? $drug['genericName'],
                            'allergy' => $allergy,
                            'message' => "⚠️ ลูกค้าแพ้ยา {$allergy} - ยา {$drug['name']} อาจไม่ปลอดภัย"
                        ];
                    }
                }
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        return $warnings;
    }
    
    /**
     * Get analysis history for a user
     * @param int $userId User ID
     * @param int $limit Number of records
     * @return array Analysis history
     */
    public function getAnalysisHistory(int $userId, int $limit = 10): array
    {
        $history = [];
        
        try {
            // Get prescription OCR history
            $stmt = $this->db->prepare("
                SELECT 'prescription' as type, image_url, extracted_drugs as result, 
                       doctor_name, hospital_name, prescription_date, created_at
                FROM prescription_ocr_results
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($prescriptions as $p) {
                $history[] = [
                    'type' => 'prescription',
                    'imageUrl' => $p['image_url'],
                    'result' => json_decode($p['result'], true),
                    'doctor' => $p['doctor_name'],
                    'hospital' => $p['hospital_name'],
                    'date' => $p['prescription_date'],
                    'createdAt' => $p['created_at']
                ];
            }
        } catch (PDOException $e) {
            // Table might not exist
        }
        
        // Sort by created_at
        usort($history, function($a, $b) {
            return strtotime($b['createdAt']) - strtotime($a['createdAt']);
        });
        
        return array_slice($history, 0, $limit);
    }
    
    /**
     * Clear expired cache entries
     * @return int Number of entries cleared
     */
    public function clearExpiredCache(): int
    {
        $cleared = 0;
        
        try {
            $stmt = $this->db->prepare("DELETE FROM symptom_analysis_cache WHERE expires_at < NOW()");
            $stmt->execute();
            $cleared += $stmt->rowCount();
            
            $stmt = $this->db->prepare("DELETE FROM drug_recognition_cache WHERE expires_at < NOW()");
            $stmt->execute();
            $cleared += $stmt->rowCount();
        } catch (PDOException $e) {
            // Tables might not exist
        }
        
        return $cleared;
    }
}
