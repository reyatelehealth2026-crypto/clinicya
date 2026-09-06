<?php
/**
 * API: Symptom Assessment v2.0
 * วิเคราะห์อาการด้วย AI อย่างละเอียด พร้อมแนะนำยาจากฐานข้อมูลจริง
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['symptoms'])) {
        throw new Exception('กรุณาระบุอาการ');
    }
    
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("SELECT gemini_api_key FROM ai_chat_settings WHERE is_enabled = 1 LIMIT 1");
    $stmt->execute();
    $apiKey = $stmt->fetchColumn();
    
    if (!$apiKey) {
        throw new Exception('ไม่พบ API Key');
    }
    
    $engine = new SymptomAssessmentEngine($db, $apiKey);
    $result = $engine->analyze($input);
    
    try {
        $savedId = $engine->saveAssessment($input, $result);
        $result['savedId'] = $savedId;
    } catch (Exception $e) {
        $result['savedId'] = null;
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

class SymptomAssessmentEngine
{
    private $db;
    private $apiKey;
    
    // Mapping อาการ -> keywords ค้นหายา
    private $symptomDrugMap = [
        'ปวดหัว' => ['paracetamol', 'tylenol', 'sara', 'ไทลินอล', 'พาราเซตามอล'],
        'ไข้' => ['paracetamol', 'tylenol', 'sara', 'ไทลินอล', 'ลดไข้'],
        'ไอ' => ['cough', 'ไอ', 'dextromethorphan', 'bromhexine', 'แก้ไอ'],
        'เจ็บคอ' => ['strepsils', 'สเตร็ปซิล', 'throat', 'คอ', 'อม'],
        'น้ำมูก' => ['cetirizine', 'loratadine', 'chlorpheniramine', 'แก้แพ้', 'antihistamine'],
        'ปวดท้อง' => ['antacid', 'omeprazole', 'กรด', 'ท้อง', 'buscopan'],
        'ท้องเสีย' => ['smecta', 'ท้องเสีย', 'carbon', 'loperamide', 'ผงถ่าน'],
        'คลื่นไส้' => ['domperidone', 'motilium', 'คลื่นไส้', 'อาเจียน'],
        'ผื่นคัน' => ['calamine', 'cetirizine', 'loratadine', 'คัน', 'ผื่น', 'แพ้'],
        'เวียนหัว' => ['dimenhydrinate', 'dramamine', 'เวียน', 'มึน'],
        'อ่อนเพลีย' => ['vitamin', 'วิตามิน', 'b complex', 'multivitamin'],
        'ปวดกล้ามเนื้อ' => ['ibuprofen', 'diclofenac', 'muscle', 'กล้ามเนื้อ', 'counterpain']
    ];
    
    private $medicalReferences = [
        'headache' => [
            ['title' => 'Headache - อาการปวดศีรษะ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/headache'],
            ['title' => 'Migraine - ไมเกรน', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/migraine']
        ],
        'fever' => [
            ['title' => 'Fever - ไข้', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/fever'],
            ['title' => 'Influenza - ไข้หวัดใหญ่', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/influenza']
        ],
        'respiratory' => [
            ['title' => 'Common Cold - ไข้หวัด', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/common-cold'],
            ['title' => 'Cough - อาการไอ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/cough'],
            ['title' => 'Pharyngitis - คออักเสบ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/pharyngitis'],
            ['title' => 'Allergic Rhinitis - โรคจมูกอักเสบภูมิแพ้', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/allergic-rhinitis']
        ],
        'digestive' => [
            ['title' => 'Gastritis - กระเพาะอาหารอักเสบ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/gastritis'],
            ['title' => 'Diarrhea - ท้องเสีย', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/diarrhea'],
            ['title' => 'Nausea and Vomiting - คลื่นไส้อาเจียน', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/nausea-and-vomiting'],
            ['title' => 'GERD - กรดไหลย้อน', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/gastroesophageal-reflux-disease']
        ],
        'skin' => [
            ['title' => 'Urticaria - ลมพิษ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/urticaria'],
            ['title' => 'Dermatitis - ผิวหนังอักเสบ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/dermatitis'],
            ['title' => 'Drug Allergy - แพ้ยา', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/drug-allergy']
        ],
        'musculoskeletal' => [
            ['title' => 'Muscle Pain - ปวดกล้ามเนื้อ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/myalgia'],
            ['title' => 'Back Pain - ปวดหลัง', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/back-pain'],
            ['title' => 'Arthritis - ข้ออักเสบ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/arthritis']
        ],
        'neurological' => [
            ['title' => 'Dizziness - เวียนศีรษะ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/dizziness'],
            ['title' => 'Vertigo - บ้านหมุน', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/vertigo'],
            ['title' => 'Insomnia - นอนไม่หลับ', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/insomnia']
        ],
        'general' => [
            ['title' => 'Fatigue - อ่อนเพลีย', 'source' => 'MIMS Thailand', 'url' => 'https://www.mims.com/thailand/disease/info/fatigue']
        ]
    ];
    
    public function __construct($db, $apiKey)
    {
        $this->db = $db;
        $this->apiKey = $apiKey;
    }
    
    public function analyze(array $input)
    {
        $symptoms = $input['symptoms'];
        $severity = intval($input['severity'] ?? 5);
        $duration = $input['duration'] ?? '';
        $age = $input['age'] ?? '';
        $conditions = $input['conditions'] ?? [];
        $allergies = $input['allergies'] ?? '';
        
        // 1. คำนวณความเสี่ยง
        $riskLevel = $this->calculateRiskLevel($symptoms, $severity, $duration, $age, $conditions);
        
        // 2. ค้นหายาจากฐานข้อมูลจริง
        $medications = $this->searchMedicationsFromDB($symptoms, $allergies);
        
        // 3. ดึงแหล่งอ้างอิง
        $references = $this->getRelevantReferences($symptoms);
        
        // 4. เรียก AI วิเคราะห์พร้อมข้อมูลยา
        $aiAnalysis = $this->callGeminiAPI($input, $riskLevel, $medications);
        
        return [
            'success' => true,
            'riskLevel' => $riskLevel,
            'analysis' => $aiAnalysis['analysis'] ?? $this->getDefaultAnalysis($symptoms),
            'possibleCauses' => $aiAnalysis['possibleCauses'] ?? [],
            'recommendation' => $aiAnalysis['recommendation'] ?? $this->getDefaultRecommendation($riskLevel),
            'selfCare' => $aiAnalysis['selfCare'] ?? [],
            'warningSignals' => $aiAnalysis['warningSignals'] ?? [],
            'warning' => $aiAnalysis['warning'] ?? null,
            'followUp' => $aiAnalysis['followUp'] ?? 'หากอาการไม่ดีขึ้นใน 2-3 วัน ควรพบแพทย์',
            'references' => $references,
            'medications' => $medications,
            'medicationAdvice' => $aiAnalysis['medicationAdvice'] ?? [],
            'assessmentId' => uniqid('ASM')
        ];
    }
    
    private function searchMedicationsFromDB($symptoms, $allergies)
    {
        $meds = [];
        $allergiesLower = mb_strtolower($allergies);
        
        try {
            // รวบรวม keywords จากอาการทั้งหมด
            $allKeywords = [];
            foreach ($symptoms as $symptom) {
                if (isset($this->symptomDrugMap[$symptom])) {
                    $allKeywords = array_merge($allKeywords, $this->symptomDrugMap[$symptom]);
                }
            }
            $allKeywords = array_unique($allKeywords);
            
            if (empty($allKeywords)) return [];
            
            // สร้าง query - ใช้คอลัมน์ที่มีอยู่จริงในตาราง products
            $conditions = [];
            $params = [];
            foreach ($allKeywords as $kw) {
                $conditions[] = "(name LIKE ? OR generic_name LIKE ? OR description LIKE ?)";
                $params[] = "%{$kw}%";
                $params[] = "%{$kw}%";
                $params[] = "%{$kw}%";
            }
            
            // ค้นหาจากตาราง business_items
            $sql = "SELECT id, sku, name, generic_name, price, description, usage_instructions
                    FROM business_items 
                    WHERE is_active = 1 AND (" . implode(' OR ', $conditions) . ")
                    ORDER BY price ASC
                    LIMIT 10";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($products as $p) {
                // ตรวจสอบการแพ้ยา
                $nameLower = mb_strtolower($p['name'] . ' ' . ($p['generic_name'] ?? ''));
                if (!empty($allergiesLower) && strpos($nameLower, $allergiesLower) !== false) {
                    continue;
                }
                
                $meds[] = [
                    'sku' => $p['sku'] ?? '',
                    'name' => $p['name'],
                    'genericName' => $p['generic_name'] ?? '',
                    'price' => number_format($p['price']),
                    'priceNum' => floatval($p['price']),
                    'description' => $p['description'] ?? '',
                    'indications' => $p['description'] ?? '', // ใช้ description แทน
                    'dosage' => '',
                    'usage' => $p['usage_instructions'] ?? '',
                    'warnings' => '',
                    'contraindications' => '',
                    'sideEffects' => ''
                ];
            }
            
        } catch (Exception $e) {
            error_log("searchMedicationsFromDB: " . $e->getMessage());
        }
        
        return array_slice($meds, 0, 6);
    }
    
    private function calculateRiskLevel($symptoms, $severity, $duration, $age, $conditions)
    {
        $score = 0;
        
        if ($severity >= 8) $score += 4;
        elseif ($severity >= 6) $score += 2;
        elseif ($severity >= 4) $score += 1;
        
        if (strpos($duration, 'เดือน') !== false) $score += 3;
        elseif (strpos($duration, 'สัปดาห์') !== false) $score += 2;
        elseif (strpos($duration, '4-7') !== false) $score += 1;
        
        $ageNum = intval($age);
        if ($ageNum > 70 || $ageNum < 3) $score += 3;
        elseif ($ageNum > 60 || $ageNum < 10) $score += 1;
        
        $score += min(count($conditions), 3);
        
        $dangerSymptoms = ['เจ็บหน้าอก', 'หายใจลำบาก', 'ชัก', 'หมดสติ', 'เลือดออก', 'แขนขาอ่อนแรง'];
        foreach ($symptoms as $symptom) {
            foreach ($dangerSymptoms as $danger) {
                if (strpos($symptom, $danger) !== false) {
                    $score += 10;
                    break 2;
                }
            }
        }
        
        if ($score >= 8) return 'high';
        if ($score >= 4) return 'medium';
        return 'low';
    }
    
    private function getRelevantReferences($symptoms)
    {
        $refs = [];
        $categories = [];
        
        foreach ($symptoms as $symptom) {
            if (strpos($symptom, 'ปวดหัว') !== false) $categories[] = 'headache';
            if (strpos($symptom, 'ไข้') !== false) $categories[] = 'fever';
            if (strpos($symptom, 'ไอ') !== false || strpos($symptom, 'เจ็บคอ') !== false || strpos($symptom, 'น้ำมูก') !== false) $categories[] = 'respiratory';
            if (strpos($symptom, 'ท้อง') !== false || strpos($symptom, 'คลื่นไส้') !== false) $categories[] = 'digestive';
            if (strpos($symptom, 'ผื่น') !== false || strpos($symptom, 'คัน') !== false) $categories[] = 'skin';
            if (strpos($symptom, 'ปวดกล้ามเนื้อ') !== false || strpos($symptom, 'ปวดหลัง') !== false) $categories[] = 'musculoskeletal';
            if (strpos($symptom, 'เวียนหัว') !== false || strpos($symptom, 'มึน') !== false) $categories[] = 'neurological';
            if (strpos($symptom, 'อ่อนเพลีย') !== false) $categories[] = 'general';
        }
        
        $categories = array_unique($categories);
        if (empty($categories)) $categories[] = 'general';
        
        foreach ($categories as $cat) {
            if (isset($this->medicalReferences[$cat])) {
                $refs = array_merge($refs, $this->medicalReferences[$cat]);
            }
        }
        
        return array_slice($refs, 0, 6);
    }
    
    private function callGeminiAPI($input, $riskLevel, $medications)
    {
        $symptoms = implode(', ', $input['symptoms']);
        $otherSymptoms = $input['otherSymptoms'] ?? '';
        $severity = $input['severity'] ?? 5;
        $duration = $input['duration'] ?? '';
        $timing = $input['timing'] ?? '';
        $triggers = $input['triggers'] ?? '';
        $age = $input['age'] ?? '';
        $gender = $input['gender'] ?? '';
        $weight = $input['weight'] ?? '';
        $conditions = implode(', ', $input['conditions'] ?? []);
        $allergies = $input['allergies'] ?? '';
        $currentMeds = $input['medications'] ?? '';
        
        // สร้างรายการยาที่มีในร้าน
        $medsInfo = "";
        if (!empty($medications)) {
            $medsInfo = "\n\n💊 ยาที่มีในร้านและเหมาะกับอาการ:\n";
            foreach ($medications as $i => $med) {
                $num = $i + 1;
                $medsInfo .= "{$num}. {$med['name']}";
                if (!empty($med['genericName'])) $medsInfo .= " ({$med['genericName']})";
                $medsInfo .= " - ฿{$med['price']}\n";
                if (!empty($med['indications'])) $medsInfo .= "   สรรพคุณ: {$med['indications']}\n";
                if (!empty($med['dosage'])) $medsInfo .= "   ขนาดยา: {$med['dosage']}\n";
            }
        }

        $prompt = <<<PROMPT
คุณเป็นเภสัชกร AI ผู้เชี่ยวชาญ ต้องวิเคราะห์อาการผู้ป่วยอย่างละเอียดที่สุด

📋 ข้อมูลผู้ป่วย:
- เพศ: {$gender}, อายุ: {$age} ปี, น้ำหนัก: {$weight} กก.
- อาการหลัก: {$symptoms}
- อาการเพิ่มเติม: {$otherSymptoms}
- ความรุนแรง: {$severity}/10
- ระยะเวลา: {$duration}
- ช่วงเวลาที่เป็น: {$timing}
- ปัจจัยกระตุ้น: {$triggers}
- โรคประจำตัว: {$conditions}
- แพ้ยา: {$allergies}
- ยาที่ใช้อยู่: {$currentMeds}
- ระดับความเสี่ยง: {$riskLevel}
{$medsInfo}

กรุณาวิเคราะห์และตอบเป็น JSON ดังนี้:
{
    "analysis": "วิเคราะห์อาการอย่างละเอียด 5-8 ประโยค อธิบาย:
    - อาการเหล่านี้บ่งบอกถึงอะไร
    - ความสัมพันธ์ระหว่างอาการต่างๆ
    - ปัจจัยเสี่ยงจากประวัติสุขภาพ
    - ความรุนแรงและความเร่งด่วน",
    
    "possibleCauses": [
        "สาเหตุที่ 1 พร้อมคำอธิบายสั้นๆ",
        "สาเหตุที่ 2 พร้อมคำอธิบายสั้นๆ",
        "สาเหตุที่ 3 พร้อมคำอธิบายสั้นๆ"
    ],
    
    "recommendation": "คำแนะนำการดูแลตัวเองอย่างละเอียด 5-8 ประโยค",
    
    "selfCare": [
        "วิธีดูแลตัวเองข้อ 1",
        "วิธีดูแลตัวเองข้อ 2",
        "วิธีดูแลตัวเองข้อ 3",
        "วิธีดูแลตัวเองข้อ 4"
    ],
    
    "warningSignals": [
        "อาการเตือนที่ต้องพบแพทย์ทันที 1",
        "อาการเตือนที่ต้องพบแพทย์ทันที 2"
    ],
    
    "medicationAdvice": [
        {
            "name": "ชื่อยาที่แนะนำจากรายการข้างต้น",
            "reason": "เหตุผลที่แนะนำยานี้",
            "howToUse": "วิธีใช้ยาอย่างละเอียด",
            "precautions": "ข้อควรระวัง"
        }
    ],
    
    "warning": "คำเตือนสำคัญ (ถ้ามี) หรือ null",
    
    "followUp": "คำแนะนำการติดตามอาการอย่างละเอียด"
}

ตอบเป็น JSON เท่านั้น ใช้ภาษาไทยที่เข้าใจง่าย ให้ข้อมูลถูกต้องตามหลักการแพทย์
PROMPT;

        try {
            $url = GEMINI_API_BASE . '/v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey;
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 2000]
                ]),
                CURLOPT_TIMEOUT => 45
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
                $parsed = json_decode($matches[0], true);
                if ($parsed) return $parsed;
            }
        } catch (Exception $e) {
            error_log("callGeminiAPI: " . $e->getMessage());
        }
        return [];
    }
    
    private function getDefaultAnalysis($symptoms)
    {
        return "จากอาการ " . implode(', ', $symptoms) . " ที่คุณระบุ อาจเกิดได้จากหลายสาเหตุ ควรสังเกตอาการอย่างใกล้ชิดและพักผ่อนให้เพียงพอ หากอาการไม่ดีขึ้นหรือมีอาการรุนแรงขึ้น ควรปรึกษาแพทย์หรือเภสัชกร";
    }
    
    private function getDefaultRecommendation($riskLevel)
    {
        if ($riskLevel === 'high') return "ควรพบแพทย์โดยเร็วที่สุด หรือโทรสายด่วน 1669 หากมีอาการรุนแรง";
        if ($riskLevel === 'medium') return "ควรพักผ่อนและสังเกตอาการอย่างใกล้ชิด หากไม่ดีขึ้นใน 2-3 วัน ควรพบแพทย์";
        return "พักผ่อนให้เพียงพอ ดื่มน้ำมากๆ และรับประทานยาตามอาการ หากไม่ดีขึ้นควรปรึกษาเภสัชกร";
    }
    
    public function saveAssessment($input, $result)
    {
        try {
            // Validate และแปลง age เป็นตัวเลข
            $age = $input['age'] ?? null;
            if ($age === '' || $age === null) {
                $age = null; // ใช้ NULL แทนค่าว่าง
            } else {
                $age = intval($age);
                if ($age <= 0) $age = null;
            }
            
            $stmt = $this->db->prepare(
                "INSERT INTO symptom_assessments (line_user_id, gender, age, symptoms, severity, duration, conditions, allergies, risk_level, analysis, recommendation, assessment_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $input['lineUserId'] ?? null,
                $input['gender'] ?? null,
                $age,
                json_encode($input['symptoms'], JSON_UNESCAPED_UNICODE),
                intval($input['severity'] ?? 5),
                $input['duration'] ?? '',
                json_encode($input['conditions'] ?? [], JSON_UNESCAPED_UNICODE),
                $input['allergies'] ?? '',
                $result['riskLevel'] ?? 'low',
                $result['analysis'] ?? '',
                $result['recommendation'] ?? '',
                $result['assessmentId'] ?? null
            ]);
            return (int) $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("saveAssessment: " . $e->getMessage());
            return null;
        }
    }
}
