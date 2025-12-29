<?php
/**
 * Property-Based Test: Triage Protocol Sequence
 * 
 * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
 * **Validates: Requirements 10.3**
 * 
 * Property: For any triage session, the state transitions SHALL follow the sequence:
 * greeting → symptom → duration → severity → associated → allergy → medical_history → current_meds → recommend
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

// Load AIChat modules
if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

class TriageProtocolSequencePropertyTest extends TestCase
{
    /**
     * The expected triage protocol sequence as defined in Requirements 10.3
     */
    private const EXPECTED_SEQUENCE = [
        'greeting',
        'symptom',
        'duration',
        'severity',
        'associated',
        'allergy',
        'medical_history',
        'current_meds',
        'recommend',
    ];
    
    /**
     * State flow mapping (current state => next state)
     */
    private const STATE_FLOW = [
        'greeting' => 'symptom',
        'symptom' => 'duration',
        'duration' => 'severity',
        'severity' => 'associated',
        'associated' => 'allergy',
        'allergy' => 'medical_history',
        'medical_history' => 'current_meds',
        'current_meds' => 'recommend',
        'recommend' => 'confirm',
        'confirm' => 'complete',
    ];
    
    /**
     * Generate random starting states for property testing
     * 
     * @return array Array of test data sets
     */
    public function stateTransitionProvider(): array
    {
        $testCases = [];
        
        // Test each state transition in the protocol sequence
        $states = ['symptom', 'duration', 'severity', 'associated', 'allergy', 'medical_history', 'current_meds'];
        
        // Generate 100 test cases with random states
        for ($i = 0; $i < 100; $i++) {
            $state = $states[array_rand($states)];
            $testCases["state_transition_{$i}"] = [$state];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random symptom messages for testing
     * 
     * @return array Array of test data sets
     */
    public function symptomMessageProvider(): array
    {
        $symptoms = [
            'ปวดหัว',
            'ไข้',
            'ไอ',
            'เจ็บคอ',
            'ท้องเสีย',
            'คลื่นไส้',
            'ปวดท้อง',
            'ปวดกล้ามเนื้อ',
            'หวัด',
            'แพ้อากาศ',
        ];
        
        $testCases = [];
        for ($i = 0; $i < 50; $i++) {
            $symptom = $symptoms[array_rand($symptoms)];
            $testCases["symptom_{$i}"] = [$symptom];
        }
        
        return $testCases;
    }
    
    /**
     * Create a properly mocked PDO that handles prepare() calls
     */
    private function createMockedPdo(): \PDO
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(false);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockStmt->method('fetchColumn')->willReturn(0);
        
        $mockDb = $this->createMock(\PDO::class);
        $mockDb->method('prepare')->willReturn($mockStmt);
        $mockDb->method('query')->willReturn($mockStmt);
        
        return $mockDb;
    }
    
    /**
     * Property Test: State transitions follow the defined protocol sequence
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     * 
     * @dataProvider stateTransitionProvider
     */
    public function testStateTransitionsFollowProtocolSequence(string $currentState): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the current state
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        
        $triageData = $this->buildTriageDataForState($currentState);
        
        $stateProperty->setValue($engine, [
            'id' => 1,
            'state' => $currentState,
            'data' => $triageData,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process an appropriate message for the state
        $message = $this->getAppropriateMessageForState($currentState);
        $result = $engine->process($message);
        
        // Get the expected next state from the protocol
        $expectedNextState = self::STATE_FLOW[$currentState] ?? null;
        
        // Verify the transition follows the protocol
        $this->assertNotNull(
            $expectedNextState,
            "State '{$currentState}' should have a defined next state in the protocol"
        );
        
        $this->assertEquals(
            $expectedNextState,
            $result['state'],
            "State should transition from '{$currentState}' to '{$expectedNextState}' following the protocol sequence. Got '{$result['state']}'"
        );
    }
    
    /**
     * Property Test: Greeting state transitions to symptom state
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     * 
     * @dataProvider symptomMessageProvider
     */
    public function testGreetingTransitionsToSymptomOrDuration(string $symptomMessage): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Process a symptom message from greeting state
        $result = $engine->process($symptomMessage);
        
        // When a symptom is provided in greeting, it can transition to either:
        // - 'symptom' state (if no symptom detected in message)
        // - 'duration' state (if symptom detected and extracted)
        $validNextStates = ['symptom', 'duration'];
        
        $this->assertContains(
            $result['state'],
            $validNextStates,
            "From greeting state with symptom message '{$symptomMessage}', should transition to 'symptom' or 'duration'. Got '{$result['state']}'"
        );
    }
    
    /**
     * Property Test: Full protocol sequence from greeting to recommend
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     */
    public function testFullProtocolSequenceFromGreetingToRecommend(): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Track the sequence of states visited
        $visitedStates = [];
        
        // Step 1: Greeting → Symptom/Duration (with symptom message)
        $result = $engine->process('ปวดหัว');
        $visitedStates[] = $result['state'];
        
        // If we went to duration directly (symptom was extracted), continue from there
        if ($result['state'] === 'duration') {
            // Step 2: Duration → Severity
            $result = $engine->process('2 วัน');
            $visitedStates[] = $result['state'];
            $this->assertEquals('severity', $result['state'], "Should transition to severity after duration");
        } else {
            // Step 2: Symptom → Duration
            $this->assertEquals('duration', $result['state'], "Should transition to duration after symptom");
            $visitedStates[] = $result['state'];
            
            // Step 3: Duration → Severity
            $result = $engine->process('2 วัน');
            $visitedStates[] = $result['state'];
            $this->assertEquals('severity', $result['state'], "Should transition to severity after duration");
        }
        
        // Step 4: Severity → Associated
        $result = $engine->process('5');
        $visitedStates[] = $result['state'];
        $this->assertEquals('associated', $result['state'], "Should transition to associated after severity");
        
        // Step 5: Associated → Allergy
        $result = $engine->process('ไม่มี');
        $visitedStates[] = $result['state'];
        $this->assertEquals('allergy', $result['state'], "Should transition to allergy after associated");
        
        // Step 6: Allergy → Medical History
        $result = $engine->process('ไม่แพ้');
        $visitedStates[] = $result['state'];
        $this->assertEquals('medical_history', $result['state'], "Should transition to medical_history after allergy");
        
        // Step 7: Medical History → Current Meds
        $result = $engine->process('ไม่มี');
        $visitedStates[] = $result['state'];
        $this->assertEquals('current_meds', $result['state'], "Should transition to current_meds after medical_history");
        
        // Step 8: Current Meds → Recommend
        $result = $engine->process('ไม่มี');
        $visitedStates[] = $result['state'];
        $this->assertEquals('recommend', $result['state'], "Should transition to recommend after current_meds");
        
        // Verify the sequence follows the protocol
        $this->assertProtocolSequenceValid($visitedStates);
    }
    
    /**
     * Property Test: Each state in sequence has correct predecessor
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     */
    public function testEachStateHasCorrectPredecessor(): void
    {
        // Define expected predecessors for each state
        $expectedPredecessors = [
            'symptom' => 'greeting',
            'duration' => 'symptom',
            'severity' => 'duration',
            'associated' => 'severity',
            'allergy' => 'associated',
            'medical_history' => 'allergy',
            'current_meds' => 'medical_history',
            'recommend' => 'current_meds',
        ];
        
        foreach ($expectedPredecessors as $state => $expectedPredecessor) {
            // Find the index of current state in the sequence
            $currentIndex = array_search($state, self::EXPECTED_SEQUENCE);
            $predecessorIndex = array_search($expectedPredecessor, self::EXPECTED_SEQUENCE);
            
            $this->assertNotFalse($currentIndex, "State '{$state}' should be in the expected sequence");
            $this->assertNotFalse($predecessorIndex, "Predecessor '{$expectedPredecessor}' should be in the expected sequence");
            
            // Verify predecessor comes before current state
            $this->assertLessThan(
                $currentIndex,
                $predecessorIndex,
                "Predecessor '{$expectedPredecessor}' should come before '{$state}' in the sequence"
            );
            
            // Verify predecessor is immediately before current state
            $this->assertEquals(
                $currentIndex - 1,
                $predecessorIndex,
                "'{$expectedPredecessor}' should be immediately before '{$state}' in the protocol sequence"
            );
        }
    }
    
    /**
     * Property Test: State flow constant matches expected protocol
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     */
    public function testStateFlowMatchesExpectedProtocol(): void
    {
        // Verify each transition in the expected sequence
        for ($i = 0; $i < count(self::EXPECTED_SEQUENCE) - 1; $i++) {
            $currentState = self::EXPECTED_SEQUENCE[$i];
            $expectedNextState = self::EXPECTED_SEQUENCE[$i + 1];
            
            $this->assertArrayHasKey(
                $currentState,
                self::STATE_FLOW,
                "State '{$currentState}' should have a defined transition in STATE_FLOW"
            );
            
            $this->assertEquals(
                $expectedNextState,
                self::STATE_FLOW[$currentState],
                "STATE_FLOW['{$currentState}'] should be '{$expectedNextState}'"
            );
        }
    }
    
    /**
     * Property Test: Random valid inputs produce valid state transitions
     * 
     * **Feature: liff-ai-assistant-integration, Property 15: Triage Protocol Sequence**
     * **Validates: Requirements 10.3**
     */
    public function testRandomValidInputsProduceValidTransitions(): void
    {
        // Generate 50 random test runs
        for ($run = 0; $run < 50; $run++) {
            $mockPdo = $this->createMockedPdo();
            $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
            
            // Random symptom
            $symptoms = ['ปวดหัว', 'ไข้', 'ไอ', 'เจ็บคอ', 'ท้องเสีย'];
            $symptom = $symptoms[array_rand($symptoms)];
            
            // Random duration
            $durations = ['1 วัน', '2 วัน', '3 วัน', '1 สัปดาห์', 'เมื่อวาน'];
            $duration = $durations[array_rand($durations)];
            
            // Random severity (1-10)
            $severity = (string)rand(1, 10);
            
            // Process through the protocol
            $result = $engine->process($symptom);
            $this->assertContains($result['state'], ['symptom', 'duration'], "Run {$run}: After symptom");
            
            // Continue if we're at symptom state
            if ($result['state'] === 'symptom') {
                $result = $engine->process($symptom);
            }
            
            // Now at duration state
            if ($result['state'] === 'duration') {
                $result = $engine->process($duration);
                $this->assertEquals('severity', $result['state'], "Run {$run}: After duration should be severity");
            }
            
            // Now at severity state
            if ($result['state'] === 'severity') {
                $result = $engine->process($severity);
                $this->assertEquals('associated', $result['state'], "Run {$run}: After severity should be associated");
            }
        }
    }
    
    /**
     * Build appropriate triage data based on the current state
     */
    private function buildTriageDataForState(string $state): array
    {
        $baseData = [
            'symptoms' => [],
            'duration' => null,
            'severity' => null,
            'associated_symptoms' => [],
            'allergies' => [],
            'medical_history' => [],
            'current_medications' => [],
            'red_flags' => [],
            'recommendations' => [],
        ];
        
        // Add data progressively based on state
        switch ($state) {
            case 'recommend':
            case 'confirm':
                $baseData['recommendations'] = [['name' => 'Paracetamol', 'price' => 50]];
                // Fall through
            case 'current_meds':
                $baseData['current_medications'] = [];
                // Fall through
            case 'medical_history':
                $baseData['medical_history'] = [];
                // Fall through
            case 'allergy':
                $baseData['allergies'] = [];
                // Fall through
            case 'associated':
                $baseData['associated_symptoms'] = [];
                // Fall through
            case 'severity':
                $baseData['severity'] = 5;
                // Fall through
            case 'duration':
                $baseData['duration'] = '2 วัน';
                // Fall through
            case 'symptom':
                $baseData['symptoms'] = ['ปวดหัว'];
                break;
        }
        
        return $baseData;
    }
    
    /**
     * Get an appropriate message for the given state
     */
    private function getAppropriateMessageForState(string $state): string
    {
        $messages = [
            'symptom' => 'ปวดหัว',
            'duration' => '2 วัน',
            'severity' => '5',
            'associated' => 'ไม่มี',
            'allergy' => 'ไม่แพ้',
            'medical_history' => 'ไม่มี',
            'current_meds' => 'ไม่มี',
            'recommend' => 'ยืนยัน',
            'confirm' => 'ยืนยัน',
        ];
        
        return $messages[$state] ?? 'ไม่มี';
    }
    
    /**
     * Assert that the visited states follow the protocol sequence
     */
    private function assertProtocolSequenceValid(array $visitedStates): void
    {
        // Remove duplicates while preserving order
        $uniqueStates = array_values(array_unique($visitedStates));
        
        // Verify each visited state is in the expected sequence
        foreach ($uniqueStates as $state) {
            $this->assertContains(
                $state,
                self::EXPECTED_SEQUENCE,
                "Visited state '{$state}' should be in the expected protocol sequence"
            );
        }
        
        // Verify states are visited in order
        $lastIndex = -1;
        foreach ($uniqueStates as $state) {
            $currentIndex = array_search($state, self::EXPECTED_SEQUENCE);
            $this->assertGreaterThan(
                $lastIndex,
                $currentIndex,
                "States should be visited in protocol order. State '{$state}' is out of order."
            );
            $lastIndex = $currentIndex;
        }
    }
}
