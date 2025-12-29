<?php
/**
 * Property-Based Test: Emergency Alert Display
 * 
 * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
 * **Validates: Requirements 3.2**
 * 
 * Property: For any critical red flag detected, the LIFF_AI_Assistant SHALL display 
 * an emergency alert containing at least one emergency contact number (1669, 1323, or 1367).
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

// Load AIChat modules
if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

class EmergencyAlertDisplayPropertyTest extends TestCase
{
    private $redFlagDetector;
    
    /**
     * Required emergency contact numbers per Requirements 3.2
     */
    private const REQUIRED_EMERGENCY_NUMBERS = ['1669', '1323', '1367'];
    
    protected function setUp(): void
    {
        $this->redFlagDetector = new \Modules\AIChat\Services\RedFlagDetector();
    }
    
    /**
     * Generate critical red flag messages for property testing
     * These messages MUST trigger critical severity and emergency alert display
     * 
     * @return array Array of test data sets [message, expected_symptoms]
     */
    public function criticalRedFlagMessageProvider(): array
    {
        $testCases = [];
        
        // Chest pain patterns - CRITICAL
        $chestPainPatterns = [
            'เจ็บหน้าอก' => 'chest pain',
            'แน่นหน้าอก' => 'chest tightness',
            'เจ็บแน่นหน้าอก' => 'chest pain and tightness',
            'chest pain' => 'chest pain',
            'รู้สึกเจ็บหน้าอกมาก' => 'severe chest pain',
        ];
        
        foreach ($chestPainPatterns as $pattern => $desc) {
            $testCases["chest_pain_{$desc}"] = [$pattern];
        }
        
        // Breathing difficulty patterns - CRITICAL
        $breathingPatterns = [
            'หายใจไม่ออก' => 'cannot breathe',
            'หายใจลำบาก' => 'difficulty breathing',
            'หอบหนัก' => 'severe wheezing',
            'หายใจไม่ทัน' => 'shortness of breath',
        ];
        
        foreach ($breathingPatterns as $pattern => $desc) {
            $testCases["breathing_{$desc}"] = [$pattern];
        }
        
        // Seizure/unconsciousness patterns - CRITICAL
        $seizurePatterns = [
            'ชัก' => 'seizure',
            'หมดสติ' => 'unconscious',
            'เป็นลม' => 'fainted',
            'ไม่รู้สึกตัว' => 'unresponsive',
            'seizure' => 'seizure_en',
        ];
        
        foreach ($seizurePatterns as $pattern => $desc) {
            $testCases["seizure_{$desc}"] = [$pattern];
        }
        
        // Stroke symptoms - CRITICAL
        $strokePatterns = [
            'แขนขาอ่อนแรง' => 'limb weakness',
            'พูดไม่ชัด' => 'slurred speech',
            'หน้าเบี้ยว' => 'facial drooping',
            'ปากเบี้ยว' => 'mouth drooping',
            'stroke' => 'stroke_en',
        ];
        
        foreach ($strokePatterns as $pattern => $desc) {
            $testCases["stroke_{$desc}"] = [$pattern];
        }
        
        // Severe allergic reaction - CRITICAL
        $allergyPatterns = [
            'แพ้รุนแรง' => 'severe allergy',
            'บวมปาก' => 'mouth swelling',
            'บวมลิ้น' => 'tongue swelling',
            'anaphylaxis' => 'anaphylaxis_en',
        ];
        
        foreach ($allergyPatterns as $pattern => $desc) {
            $testCases["allergy_{$desc}"] = [$pattern];
        }
        
        // Bleeding patterns - CRITICAL
        $bleedingPatterns = [
            'อาเจียนเป็นเลือด' => 'vomiting blood',
            'อาเจียนเลือด' => 'blood vomit',
            'ถ่ายเป็นเลือด' => 'bloody stool',
            'ถ่ายดำ' => 'black stool',
        ];
        
        foreach ($bleedingPatterns as $pattern => $desc) {
            $testCases["bleeding_{$desc}"] = [$pattern];
        }
        
        // Mental health crisis - CRITICAL
        $mentalHealthPatterns = [
            'ฆ่าตัวตาย' => 'suicide',
            'อยากตาย' => 'want to die',
            'ไม่อยากมีชีวิต' => 'dont want to live',
            'ทำร้ายตัวเอง' => 'self harm',
        ];
        
        foreach ($mentalHealthPatterns as $pattern => $desc) {
            $testCases["mental_health_{$desc}"] = [$pattern];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random critical messages with context
     * 
     * @return array Array of test data sets
     */
    public function randomCriticalMessageProvider(): array
    {
        $testCases = [];
        $criticalPatterns = [
            'เจ็บหน้าอก', 'หายใจไม่ออก', 'ชัก', 'หมดสติ', 
            'แขนขาอ่อนแรง', 'พูดไม่ชัด', 'อาเจียนเป็นเลือด',
            'แพ้รุนแรง', 'บวมปาก', 'ฆ่าตัวตาย'
        ];
        
        $prefixes = ['วันนี้', 'เมื่อกี้', 'ตอนนี้', 'พอดี', 'รู้สึก', 'มีอาการ', 'เริ่ม'];
        $suffixes = ['มากครับ', 'ค่ะ', 'ช่วยด้วย', 'ทำยังไงดี', 'รุนแรง', ''];
        
        // Generate 100 random combinations for property testing
        for ($i = 0; $i < 100; $i++) {
            $pattern = $criticalPatterns[array_rand($criticalPatterns)];
            $prefix = $prefixes[array_rand($prefixes)];
            $suffix = $suffixes[array_rand($suffixes)];
            
            $message = "{$prefix}{$pattern}{$suffix}";
            $testCases["random_critical_{$i}"] = [$message];
        }
        
        return $testCases;
    }

    /**
     * Property Test: Critical red flags MUST trigger emergency contacts availability
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * For any critical red flag detected, emergency contacts MUST be available
     * and contain at least one of the required numbers (1669, 1323, 1367)
     * 
     * @dataProvider criticalRedFlagMessageProvider
     */
    public function testCriticalRedFlagsProvideEmergencyContacts(string $message): void
    {
        // Detect red flags
        $flags = $this->redFlagDetector->detect($message);
        
        // Verify critical flag is detected
        $this->assertNotEmpty(
            $flags,
            "Message '{$message}' should trigger red flag detection"
        );
        
        $this->assertTrue(
            $this->redFlagDetector->isCritical($flags),
            "Message '{$message}' should be detected as CRITICAL severity"
        );
        
        // Get emergency contacts
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        
        // Verify emergency contacts are available
        $this->assertNotEmpty(
            $contacts,
            "Emergency contacts MUST be available when critical red flag is detected"
        );
        
        // Extract contact numbers
        $numbers = array_column($contacts, 'number');
        
        // Verify at least one required emergency number is present
        $hasRequiredNumber = false;
        foreach (self::REQUIRED_EMERGENCY_NUMBERS as $requiredNumber) {
            if (in_array($requiredNumber, $numbers)) {
                $hasRequiredNumber = true;
                break;
            }
        }
        
        $this->assertTrue(
            $hasRequiredNumber,
            "Emergency contacts MUST contain at least one of: " . implode(', ', self::REQUIRED_EMERGENCY_NUMBERS)
        );
    }
    
    /**
     * Property Test: Emergency contacts MUST contain ALL required numbers
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * @dataProvider criticalRedFlagMessageProvider
     */
    public function testEmergencyContactsContainAllRequiredNumbers(string $message): void
    {
        // Detect red flags
        $flags = $this->redFlagDetector->detect($message);
        
        // Skip if not critical (this test focuses on critical cases)
        if (!$this->redFlagDetector->isCritical($flags)) {
            $this->markTestSkipped("Message '{$message}' is not critical, skipping");
        }
        
        // Get emergency contacts
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        $numbers = array_column($contacts, 'number');
        
        // Verify ALL required emergency numbers are present
        foreach (self::REQUIRED_EMERGENCY_NUMBERS as $requiredNumber) {
            $this->assertContains(
                $requiredNumber,
                $numbers,
                "Emergency contacts MUST include {$requiredNumber}"
            );
        }
    }
    
    /**
     * Property Test: Random critical messages MUST provide emergency contacts
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * @dataProvider randomCriticalMessageProvider
     */
    public function testRandomCriticalMessagesProvideEmergencyContacts(string $message): void
    {
        // Detect red flags
        $flags = $this->redFlagDetector->detect($message);
        
        // Verify critical flag is detected
        $this->assertNotEmpty(
            $flags,
            "Random critical message '{$message}' should trigger red flag detection"
        );
        
        $this->assertTrue(
            $this->redFlagDetector->isCritical($flags),
            "Random critical message '{$message}' should be CRITICAL"
        );
        
        // Get emergency contacts
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        
        // Verify emergency contacts contain required numbers
        $numbers = array_column($contacts, 'number');
        
        $this->assertContains('1669', $numbers, "Emergency contacts MUST include 1669");
        $this->assertContains('1323', $numbers, "Emergency contacts MUST include 1323");
        $this->assertContains('1367', $numbers, "Emergency contacts MUST include 1367");
    }
    
    /**
     * Property Test: Emergency contact structure is valid for display
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * Each emergency contact MUST have name, number, and description for proper display
     */
    public function testEmergencyContactStructureIsValidForDisplay(): void
    {
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        
        $this->assertNotEmpty($contacts, "Emergency contacts should not be empty");
        
        foreach ($contacts as $index => $contact) {
            // Verify required fields for display
            $this->assertArrayHasKey(
                'name',
                $contact,
                "Contact at index {$index} MUST have 'name' field for display"
            );
            
            $this->assertArrayHasKey(
                'number',
                $contact,
                "Contact at index {$index} MUST have 'number' field for display"
            );
            
            $this->assertArrayHasKey(
                'description',
                $contact,
                "Contact at index {$index} MUST have 'description' field for display"
            );
            
            // Verify fields are not empty
            $this->assertNotEmpty(
                $contact['name'],
                "Contact name at index {$index} MUST not be empty"
            );
            
            $this->assertNotEmpty(
                $contact['number'],
                "Contact number at index {$index} MUST not be empty"
            );
            
            $this->assertNotEmpty(
                $contact['description'],
                "Contact description at index {$index} MUST not be empty"
            );
        }
    }
    
    /**
     * Property Test: Critical flags MUST have action recommendations
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * For any critical red flag, the flag MUST include an action recommendation
     * that references emergency contact
     * 
     * @dataProvider criticalRedFlagMessageProvider
     */
    public function testCriticalFlagsHaveActionRecommendations(string $message): void
    {
        $flags = $this->redFlagDetector->detect($message);
        
        // Get critical flags only
        $criticalFlags = array_filter($flags, function($flag) {
            return ($flag['severity'] ?? '') === 'critical';
        });
        
        $this->assertNotEmpty(
            $criticalFlags,
            "Message '{$message}' should have at least one critical flag"
        );
        
        foreach ($criticalFlags as $flag) {
            // Verify action field exists
            $this->assertArrayHasKey(
                'action',
                $flag,
                "Critical flag MUST have 'action' field with recommendation"
            );
            
            $this->assertNotEmpty(
                $flag['action'],
                "Critical flag action MUST not be empty"
            );
            
            // Verify action mentions emergency contact or medical attention
            $actionLower = mb_strtolower($flag['action']);
            $hasEmergencyReference = (
                strpos($actionLower, '1669') !== false ||
                strpos($actionLower, '1323') !== false ||
                strpos($actionLower, '1367') !== false ||
                strpos($actionLower, 'แพทย์') !== false ||
                strpos($actionLower, 'โรงพยาบาล') !== false ||
                strpos($actionLower, 'ฉุกเฉิน') !== false
            );
            
            $this->assertTrue(
                $hasEmergencyReference,
                "Critical flag action MUST reference emergency contact or medical attention. Got: {$flag['action']}"
            );
        }
    }
    
    /**
     * Property Test: Warning message for critical flags contains emergency info
     * 
     * **Feature: liff-ai-assistant-integration, Property 3: Emergency Alert Display**
     * **Validates: Requirements 3.2**
     * 
     * @dataProvider criticalRedFlagMessageProvider
     */
    public function testWarningMessageContainsEmergencyInfo(string $message): void
    {
        $flags = $this->redFlagDetector->detect($message);
        
        // Skip if not critical
        if (!$this->redFlagDetector->isCritical($flags)) {
            $this->markTestSkipped("Message '{$message}' is not critical");
        }
        
        // Build warning message
        $warningMessage = $this->redFlagDetector->buildWarningMessage($flags);
        
        $this->assertNotEmpty(
            $warningMessage,
            "Warning message for critical flags MUST not be empty"
        );
        
        // Verify warning message mentions emergency
        $this->assertStringContainsString(
            'ฉุกเฉิน',
            $warningMessage,
            "Warning message for critical flags MUST mention 'ฉุกเฉิน' (emergency)"
        );
    }
}
