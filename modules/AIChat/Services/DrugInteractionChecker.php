<?php
/**
 * DrugInteractionChecker - ตรวจสอบยาตีกันและข้อห้ามใช้
 * Version 2.0 - Professional Drug Interaction Database
 */

namespace Modules\AIChat\Services;

// Self-contained dependency load — callers (e.g. api/pharmacist.php) only require
// global config/database.php, so the namespaced Modules\Core\Database is not yet
// in scope when this file is included. Loading it here makes the file safe to
// drop into any context without forcing the caller to know about the namespace.
require_once __DIR__ . '/../../Core/Database.php';

use Modules\Core\Database;

class DrugInteractionChecker
{
    private Database $db;
    
    // Interaction Severity Levels
    public const SEVERITY_CONTRAINDICATED = 'contraindicated'; // ห้ามใช้ร่วมกัน
    public const SEVERITY_SEVERE = 'severe';                   // รุนแรง
    public const SEVERITY_MODERATE = 'moderate';               // ปานกลาง
    public const SEVERITY_MILD = 'mild';                       // เล็กน้อย
    
    // Built-in Drug Interaction Database
    private const INTERACTIONS = [
        // Warfarin interactions
        ['drug1' => 'warfarin', 'drug2' => 'aspirin', 'severity' => 'severe', 
         'effect' => 'เพิ่มความเสี่ยงเลือดออก', 'recommendation' => 'หลีกเลี่ยงการใช้ร่วมกัน'],
        ['drug1' => 'warfarin', 'drug2' => 'ibuprofen', 'severity' => 'severe',
         'effect' => 'เพิ่มความเสี่ยงเลือดออกในทางเดินอาหาร', 'recommendation' => 'ใช้ paracetamol แทน'],
        ['drug1' => 'warfarin', 'drug2' => 'naproxen', 'severity' => 'severe',
         'effect' => 'เพิ่มความเสี่ยงเลือดออก', 'recommendation' => 'หลีกเลี่ยง NSAIDs ทุกชนิด'],
        ['drug1' => 'warfarin', 'drug2' => 'vitamin k', 'severity' => 'moderate',
         'effect' => 'ลดฤทธิ์ของ warfarin', 'recommendation' => 'หลีกเลี่ยงอาหารที่มี vitamin K สูง'],
         
        // Metformin interactions
        ['drug1' => 'metformin', 'drug2' => 'alcohol', 'severity' => 'severe',
         'effect' => 'เพิ่มความเสี่ยง lactic acidosis', 'recommendation' => 'งดแอลกอฮอล์'],
        ['drug1' => 'metformin', 'drug2' => 'contrast dye', 'severity' => 'severe',
         'effect' => 'เพิ่มความเสี่ยงไตวาย', 'recommendation' => 'หยุดยา 48 ชม. ก่อน-หลังฉีดสี'],

        // Statin interactions
        ['drug1' => 'simvastatin', 'drug2' => 'grapefruit', 'severity' => 'moderate',
         'effect' => 'เพิ่มระดับยาในเลือด เสี่ยงกล้ามเนื้อสลาย', 'recommendation' => 'หลีกเลี่ยงเกรปฟรุต'],
        ['drug1' => 'atorvastatin', 'drug2' => 'erythromycin', 'severity' => 'moderate',
         'effect' => 'เพิ่มระดับยา statin', 'recommendation' => 'ใช้ยาปฏิชีวนะตัวอื่น'],
        
        // ACE Inhibitor interactions
        ['drug1' => 'enalapril', 'drug2' => 'potassium', 'severity' => 'moderate',
         'effect' => 'เพิ่มระดับโพแทสเซียมในเลือด', 'recommendation' => 'ตรวจระดับโพแทสเซียมเป็นระยะ'],
        ['drug1' => 'lisinopril', 'drug2' => 'spironolactone', 'severity' => 'moderate',
         'effect' => 'เพิ่มความเสี่ยง hyperkalemia', 'recommendation' => 'ติดตามระดับโพแทสเซียม'],
        
        // Antibiotic interactions
        ['drug1' => 'ciprofloxacin', 'drug2' => 'antacid', 'severity' => 'moderate',
         'effect' => 'ลดการดูดซึมยาปฏิชีวนะ', 'recommendation' => 'ทานห่างกัน 2 ชั่วโมง'],
        ['drug1' => 'tetracycline', 'drug2' => 'calcium', 'severity' => 'moderate',
         'effect' => 'ลดการดูดซึมยา', 'recommendation' => 'ทานห่างกัน 2-3 ชั่วโมง'],
        ['drug1' => 'metronidazole', 'drug2' => 'alcohol', 'severity' => 'severe',
         'effect' => 'ทำให้คลื่นไส้ อาเจียน ปวดหัวรุนแรง', 'recommendation' => 'งดแอลกอฮอล์ระหว่างใช้ยา'],
        
        // PPI interactions
        ['drug1' => 'omeprazole', 'drug2' => 'clopidogrel', 'severity' => 'moderate',
         'effect' => 'ลดประสิทธิภาพของ clopidogrel', 'recommendation' => 'ใช้ pantoprazole แทน'],
        
        // Thyroid interactions
        ['drug1' => 'levothyroxine', 'drug2' => 'calcium', 'severity' => 'moderate',
         'effect' => 'ลดการดูดซึมยาไทรอยด์', 'recommendation' => 'ทานห่างกัน 4 ชั่วโมง'],
        ['drug1' => 'levothyroxine', 'drug2' => 'iron', 'severity' => 'moderate',
         'effect' => 'ลดการดูดซึมยาไทรอยด์', 'recommendation' => 'ทานห่างกัน 4 ชั่วโมง'],
        
        // Pain medication interactions
        ['drug1' => 'tramadol', 'drug2' => 'ssri', 'severity' => 'severe',
         'effect' => 'เพิ่มความเสี่ยง serotonin syndrome', 'recommendation' => 'หลีกเลี่ยงการใช้ร่วมกัน'],
        ['drug1' => 'codeine', 'drug2' => 'benzodiazepine', 'severity' => 'severe',
         'effect' => 'กดการหายใจ', 'recommendation' => 'ห้ามใช้ร่วมกัน'],
        
        // Common OTC interactions
        ['drug1' => 'paracetamol', 'drug2' => 'alcohol', 'severity' => 'moderate',
         'effect' => 'เพิ่มความเสี่ยงตับเสียหาย', 'recommendation' => 'จำกัดแอลกอฮอล์'],
        ['drug1' => 'ibuprofen', 'drug2' => 'aspirin', 'severity' => 'moderate',
         'effect' => 'ลดฤทธิ์ป้องกันหัวใจของ aspirin', 'recommendation' => 'ทาน aspirin ก่อน 30 นาที'],
    ];
    
    // Contraindications by condition
    private const CONTRAINDICATIONS = [
        'เบาหวาน' => ['pseudoephedrine' => 'อาจเพิ่มน้ำตาลในเลือด'],
        'ความดันสูง' => ['pseudoephedrine' => 'อาจเพิ่มความดัน', 'nsaids' => 'อาจเพิ่มความดัน'],
        'หอบหืด' => ['aspirin' => 'อาจกระตุ้นอาการหอบ', 'nsaids' => 'อาจกระตุ้นอาการหอบ', 'beta-blocker' => 'อาจกระตุ้นหลอดลมตีบ'],
        'โรคกระเพาะ' => ['nsaids' => 'ระคายเคืองกระเพาะ', 'aspirin' => 'ระคายเคืองกระเพาะ'],
        'ไต' => ['nsaids' => 'อาจทำให้ไตเสื่อมลง', 'metformin' => 'สะสมในร่างกาย'],
        'ตับ' => ['paracetamol' => 'ใช้ขนาดต่ำ ไม่เกิน 2g/วัน'],
        'ตั้งครรภ์' => ['nsaids' => 'ห้ามใช้ไตรมาส 3', 'warfarin' => 'ห้ามใช้', 'methotrexate' => 'ห้ามใช้'],
        'ให้นมบุตร' => ['codeine' => 'ผ่านน้ำนม', 'aspirin' => 'หลีกเลี่ยง'],
    ];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * ตรวจสอบยาตีกัน
     */
    public function checkInteractions(array $newDrugs, array $currentMedications): array
    {
        $interactions = [];
        
        foreach ($newDrugs as $newDrug) {
            $newDrugLower = mb_strtolower($newDrug['generic_name'] ?? $newDrug['name'] ?? '');
            
            foreach ($currentMedications as $currentMed) {
                $currentMedLower = mb_strtolower($currentMed);
                
                // Check built-in database
                foreach (self::INTERACTIONS as $interaction) {
                    if ($this->matchDrug($newDrugLower, $interaction['drug1']) && 
                        $this->matchDrug($currentMedLower, $interaction['drug2'])) {
                        $interactions[] = [
                            'drug1' => $newDrug['name'] ?? $newDrug,
                            'drug2' => $currentMed,
                            'severity' => $interaction['severity'],
                            'effect' => $interaction['effect'],
                            'recommendation' => $interaction['recommendation'],
                        ];
                    }
                    // Check reverse
                    if ($this->matchDrug($newDrugLower, $interaction['drug2']) && 
                        $this->matchDrug($currentMedLower, $interaction['drug1'])) {
                        $interactions[] = [
                            'drug1' => $newDrug['name'] ?? $newDrug,
                            'drug2' => $currentMed,
                            'severity' => $interaction['severity'],
                            'effect' => $interaction['effect'],
                            'recommendation' => $interaction['recommendation'],
                        ];
                    }
                }
            }
            
            // Check database for additional interactions
            $dbInteractions = $this->checkDatabaseInteractions($newDrugLower, $currentMedications);
            $interactions = array_merge($interactions, $dbInteractions);
        }
        
        return $this->deduplicateInteractions($interactions);
    }
    
    /**
     * ตรวจสอบข้อห้ามใช้ตามโรคประจำตัว
     */
    public function checkContraindications(array $drugs, array $medicalConditions): array
    {
        $contraindications = [];
        
        foreach ($drugs as $drug) {
            $drugLower = mb_strtolower($drug['generic_name'] ?? $drug['name'] ?? '');
            
            foreach ($medicalConditions as $condition) {
                $conditionLower = mb_strtolower($condition);
                
                foreach (self::CONTRAINDICATIONS as $condKey => $drugList) {
                    if (mb_strpos($conditionLower, $condKey) !== false) {
                        foreach ($drugList as $contraDrug => $reason) {
                            if ($this->matchDrug($drugLower, $contraDrug)) {
                                $contraindications[] = [
                                    'drug' => $drug['name'] ?? $drug,
                                    'condition' => $condition,
                                    'reason' => $reason,
                                    'severity' => 'warning',
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return $contraindications;
    }
    
    /**
     * ตรวจสอบการแพ้ยา
     */
    public function checkAllergies(array $drugs, array $allergies): array
    {
        $allergyWarnings = [];
        
        // Cross-reactivity groups
        $crossReactivity = [
            'penicillin' => ['amoxicillin', 'ampicillin', 'penicillin v', 'augmentin'],
            'sulfa' => ['sulfamethoxazole', 'sulfasalazine', 'celecoxib'],
            'nsaids' => ['ibuprofen', 'naproxen', 'diclofenac', 'piroxicam', 'meloxicam'],
            'aspirin' => ['aspirin', 'nsaids'],
            'cephalosporin' => ['cephalexin', 'cefixime', 'ceftriaxone'],
        ];
        
        foreach ($drugs as $drug) {
            $drugLower = mb_strtolower($drug['generic_name'] ?? $drug['name'] ?? '');
            
            foreach ($allergies as $allergy) {
                $allergyLower = mb_strtolower($allergy);
                
                // Direct match
                if ($this->matchDrug($drugLower, $allergyLower)) {
                    $allergyWarnings[] = [
                        'drug' => $drug['name'] ?? $drug,
                        'allergy' => $allergy,
                        'type' => 'direct',
                        'severity' => 'contraindicated',
                        'message' => "⛔ ห้ามใช้! คุณแพ้ยา {$allergy}",
                    ];
                    continue;
                }
                
                // Cross-reactivity check
                foreach ($crossReactivity as $group => $members) {
                    if ($this->matchDrug($allergyLower, $group) || in_array($allergyLower, $members)) {
                        foreach ($members as $member) {
                            if ($this->matchDrug($drugLower, $member)) {
                                $allergyWarnings[] = [
                                    'drug' => $drug['name'] ?? $drug,
                                    'allergy' => $allergy,
                                    'type' => 'cross_reactivity',
                                    'severity' => 'severe',
                                    'message' => "⚠️ ระวัง! อาจแพ้ข้ามกลุ่มกับ {$allergy}",
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return $allergyWarnings;
    }

    /**
     * ตรวจสอบขนาดยาสูงสุด
     */
    public function checkMaxDose(string $drugName, float $dose, string $unit, ?array $patientInfo = null): ?array
    {
        $maxDoses = [
            'paracetamol' => ['max' => 4000, 'unit' => 'mg', 'period' => 'day', 'elderly_max' => 3000, 'liver_max' => 2000],
            'ibuprofen' => ['max' => 1200, 'unit' => 'mg', 'period' => 'day', 'elderly_max' => 800],
            'aspirin' => ['max' => 4000, 'unit' => 'mg', 'period' => 'day'],
            'omeprazole' => ['max' => 40, 'unit' => 'mg', 'period' => 'day'],
            'loratadine' => ['max' => 10, 'unit' => 'mg', 'period' => 'day'],
            'cetirizine' => ['max' => 10, 'unit' => 'mg', 'period' => 'day'],
            'diphenhydramine' => ['max' => 300, 'unit' => 'mg', 'period' => 'day'],
        ];
        
        $drugLower = mb_strtolower($drugName);
        
        foreach ($maxDoses as $drug => $limits) {
            if ($this->matchDrug($drugLower, $drug)) {
                $maxDose = $limits['max'];
                
                // Adjust for special populations
                if ($patientInfo) {
                    if (isset($patientInfo['age']) && $patientInfo['age'] >= 65 && isset($limits['elderly_max'])) {
                        $maxDose = $limits['elderly_max'];
                    }
                    if (isset($patientInfo['liver_disease']) && $patientInfo['liver_disease'] && isset($limits['liver_max'])) {
                        $maxDose = $limits['liver_max'];
                    }
                }
                
                if ($dose > $maxDose && $unit === $limits['unit']) {
                    return [
                        'drug' => $drugName,
                        'requested_dose' => $dose,
                        'max_dose' => $maxDose,
                        'unit' => $unit,
                        'message' => "⚠️ ขนาดยา {$drugName} เกินขนาดสูงสุด ({$maxDose}{$unit}/วัน)",
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * สร้างรายงานความปลอดภัย
     */
    public function generateSafetyReport(array $drugs, array $patientData): array
    {
        $report = [
            'safe' => true,
            'interactions' => [],
            'contraindications' => [],
            'allergies' => [],
            'warnings' => [],
            'recommendations' => [],
        ];
        
        // Check interactions
        if (!empty($patientData['current_medications'])) {
            $report['interactions'] = $this->checkInteractions($drugs, $patientData['current_medications']);
            if (!empty($report['interactions'])) {
                foreach ($report['interactions'] as $interaction) {
                    if (in_array($interaction['severity'], ['severe', 'contraindicated'])) {
                        $report['safe'] = false;
                    }
                }
            }
        }
        
        // Check contraindications
        if (!empty($patientData['medical_conditions'])) {
            $report['contraindications'] = $this->checkContraindications($drugs, $patientData['medical_conditions']);
        }
        
        // Check allergies
        if (!empty($patientData['allergies'])) {
            $report['allergies'] = $this->checkAllergies($drugs, $patientData['allergies']);
            if (!empty($report['allergies'])) {
                foreach ($report['allergies'] as $allergy) {
                    if ($allergy['severity'] === 'contraindicated') {
                        $report['safe'] = false;
                    }
                }
            }
        }
        
        // Generate recommendations
        $report['recommendations'] = $this->generateRecommendations($report);
        
        return $report;
    }
    
    /**
     * สร้างคำแนะนำ
     */
    private function generateRecommendations(array $report): array
    {
        $recommendations = [];
        
        if (!empty($report['interactions'])) {
            $recommendations[] = '📋 ควรแจ้งเภสัชกรเรื่องยาที่ทานอยู่ทุกครั้ง';
        }
        
        if (!empty($report['allergies'])) {
            $recommendations[] = '⚠️ มียาที่อาจแพ้ กรุณาปรึกษาเภสัชกรก่อนใช้';
        }
        
        if (!empty($report['contraindications'])) {
            $recommendations[] = '🏥 มีข้อควรระวังเนื่องจากโรคประจำตัว';
        }
        
        return $recommendations;
    }
    
    // Helper methods
    private function matchDrug(string $text, string $drug): bool
    {
        return mb_strpos($text, $drug) !== false;
    }
    
    private function checkDatabaseInteractions(string $drug, array $currentMeds): array
    {
        $interactions = [];
        
        try {
            foreach ($currentMeds as $currentMed) {
                $stmt = $this->db->query(
                    "SELECT * FROM drug_interactions 
                     WHERE (LOWER(drug1_generic) LIKE ? AND LOWER(drug2_generic) LIKE ?)
                        OR (LOWER(drug2_generic) LIKE ? AND LOWER(drug1_generic) LIKE ?)",
                    ["%{$drug}%", "%{$currentMed}%", "%{$drug}%", "%{$currentMed}%"]
                );
                
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $interactions[] = [
                        'drug1' => $row['drug1_name'],
                        'drug2' => $row['drug2_name'],
                        'severity' => $row['severity'],
                        'effect' => $row['description'],
                        'recommendation' => $row['recommendation'],
                    ];
                }
            }
        } catch (\Exception $e) {
            // Table might not exist
        }
        
        return $interactions;
    }
    
    private function deduplicateInteractions(array $interactions): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($interactions as $interaction) {
            $key = $interaction['drug1'] . '|' . $interaction['drug2'];
            $keyReverse = $interaction['drug2'] . '|' . $interaction['drug1'];
            
            if (!isset($seen[$key]) && !isset($seen[$keyReverse])) {
                $unique[] = $interaction;
                $seen[$key] = true;
            }
        }
        
        return $unique;
    }
}
