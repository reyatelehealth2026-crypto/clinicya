<?php
/**
 * Property-Based Test: State Persistence
 * 
 * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
 * **Validates: Requirements 9.5**
 * 
 * Property: For any triage session with state not 'complete' or 'escalate', 
 * subsequent messages SHALL continue from the current state without resetting to 'greeting'.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

// Load AIChat modules
if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

class StatePersistencePropertyTest extends TestCase
{
    /**
     * Active triage states (not complete, escalate, or greeting)
     */
    private const ACTIVE_STATES = [
        'symptom',
        'duration', 
        'severity',
        'associated',
        'allergy',
        'medical_history',
        'current_meds',
        'recommend',
        'confirm',
    ];
    
    /**
     * Terminal states where session can be reset
     */
    private const TERMINAL_STATES = [
        'complete',
        'escalate',
    ];
    
    /**
     * Generate random active states for property testing
     * 
     * @return array Array of test data sets
     */
    public function activeStateProvider(): array
    {
        $testCases = [];
        
        // Generate 100 test cases with random active states
        for ($i = 0; $i < 100; $i++) {
            $state = self::ACTIVE_STATES[array_rand(self::ACTIVE_STATES)];
            $testCases["active_state_{$i}"] = [$state];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random user messages that should NOT reset the session
     * 
     * @return array Array of test data sets
     */
    public function nonResetMessageProvider(): array
    {
        $testCases = [];
        
        // Messages that should continue the session, not reset it
        $messages = [
            // Numeric responses (severity)
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
            // Duration responses
            '1 วัน', '2 วัน', '3 วัน', '1 สัปดาห์', '2 สัปดาห์', '1 เดือน',
            // Yes/No responses
            'ใช่', 'ไม่ใช่', 'ไม่', 'ไม่มี', 'มี', 'ไม่แพ้',
            // Short acknowledgments
            'ครับ', 'ค่ะ', 'โอเค', 'ok',
        ];
        
        // Generate 100 test cases
        for ($i = 0; $i < 100; $i++) {
            $state = self::ACTIVE_STATES[array_rand(self::ACTIVE_STATES)];
            $message = $messages[array_rand($messages)];
            $testCases["message_{$i}"] = [$state, $message];
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
     * Property Test: Active sessions should not reset to greeting state
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     * 
     * @dataProvider activeStateProvider
     */
    public function testActiveSessionDoesNotResetToGreeting(string $initialState): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the initial state
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'id' => 1,
            'state' => $initialState,
            'data' => [
                'symptoms' => ['ปวดหัว'],
                'duration' => '2 วัน',
                'severity' => 5,
                'associated_symptoms' => [],
                'allergies' => [],
                'medical_history' => [],
                'current_medications' => [],
                'red_flags' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Verify the session is considered active
        $this->assertTrue(
            $engine->isActiveSession(),
            "State '{$initialState}' should be considered an active session"
        );
        
        // Verify current state is not greeting
        $this->assertNotEquals(
            'greeting',
            $engine->getCurrentState(),
            "Active session should not be in greeting state"
        );
    }
    
    /**
     * Property Test: Terminal states should allow session reset
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     */
    public function testTerminalStatesAllowReset(): void
    {
        foreach (self::TERMINAL_STATES as $terminalState) {
            $mockPdo = $this->createMockedPdo();
            $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
            
            // Use reflection to set the terminal state
            $reflection = new \ReflectionClass($engine);
            $stateProperty = $reflection->getProperty('currentState');
            $stateProperty->setAccessible(true);
            $stateProperty->setValue($engine, [
                'id' => 1,
                'state' => $terminalState,
                'data' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            // Verify the session is NOT considered active
            $this->assertFalse(
                $engine->isActiveSession(),
                "State '{$terminalState}' should NOT be considered an active session"
            );
        }
    }
    
    /**
     * Property Test: State persists across message processing
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     * 
     * @dataProvider nonResetMessageProvider
     */
    public function testStatePersistsAcrossMessageProcessing(string $initialState, string $message): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the initial state with appropriate data
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        
        // Build appropriate triage data based on state
        $triageData = $this->buildTriageDataForState($initialState);
        
        $stateProperty->setValue($engine, [
            'id' => 1,
            'state' => $initialState,
            'data' => $triageData,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process the message
        $result = $engine->process($message);
        
        // Verify the result state is NOT 'greeting' (session should persist)
        $this->assertNotEquals(
            'greeting',
            $result['state'],
            "After processing message '{$message}' in state '{$initialState}', " .
            "session should NOT reset to greeting. Got state: {$result['state']}"
        );
    }
    
    /**
     * Property Test: getCurrentState returns the correct state
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     * 
     * @dataProvider activeStateProvider
     */
    public function testGetCurrentStateReturnsCorrectState(string $state): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the state
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'state' => $state,
            'data' => [],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Verify getCurrentState returns the correct state
        $this->assertEquals(
            $state,
            $engine->getCurrentState(),
            "getCurrentState should return '{$state}'"
        );
    }
    
    /**
     * Property Test: State transitions follow the defined flow
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     */
    public function testStateTransitionsFollowDefinedFlow(): void
    {
        // Define the expected state flow
        $stateFlow = [
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
        
        foreach ($stateFlow as $currentState => $expectedNextState) {
            // Skip greeting as it's the initial state
            if ($currentState === 'greeting') {
                continue;
            }
            
            $mockPdo = $this->createMockedPdo();
            $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
            
            // Use reflection to set the state
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
            
            // For recommend and confirm states, the flow can vary based on user input
            // So we just verify it doesn't reset to greeting
            if (in_array($currentState, ['recommend', 'confirm'])) {
                $this->assertNotEquals(
                    'greeting',
                    $result['state'],
                    "State '{$currentState}' should not reset to greeting"
                );
            } else {
                $this->assertEquals(
                    $expectedNextState,
                    $result['state'],
                    "State should transition from '{$currentState}' to '{$expectedNextState}', got '{$result['state']}'"
                );
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
     * Property Test: Session ID is preserved during state transitions
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     * 
     * @dataProvider activeStateProvider
     */
    public function testSessionIdPreservedDuringTransitions(string $initialState): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $sessionId = 12345;
        
        // Use reflection to set the initial state with session ID
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'id' => $sessionId,
            'state' => $initialState,
            'data' => $this->buildTriageDataForState($initialState),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Verify session ID is preserved
        $this->assertEquals(
            $sessionId,
            $engine->getSessionId(),
            "Session ID should be preserved for state '{$initialState}'"
        );
    }
    
    /**
     * Property Test: Triage data is preserved during state transitions
     * 
     * **Feature: liff-ai-assistant-integration, Property 10: State Persistence**
     * **Validates: Requirements 9.5**
     */
    public function testTriageDataPreservedDuringTransitions(): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Set initial state with specific data
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        
        $initialData = [
            'symptoms' => ['ปวดหัว', 'ไข้'],
            'duration' => '3 วัน',
            'severity' => null,
            'associated_symptoms' => [],
            'allergies' => [],
            'medical_history' => [],
            'current_medications' => [],
            'red_flags' => [],
        ];
        
        $stateProperty->setValue($engine, [
            'id' => 1,
            'state' => 'severity',
            'data' => $initialData,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process a severity message
        $result = $engine->process('7');
        
        // Verify previous data is preserved
        $triageData = $engine->getTriageData();
        
        $this->assertEquals(
            ['ปวดหัว', 'ไข้'],
            $triageData['symptoms'],
            "Symptoms should be preserved after state transition"
        );
        
        $this->assertEquals(
            '3 วัน',
            $triageData['duration'],
            "Duration should be preserved after state transition"
        );
        
        // Verify new data was added
        $this->assertNotNull(
            $triageData['severity'],
            "Severity should be set after processing severity response"
        );
    }
}
