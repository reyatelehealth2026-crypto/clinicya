<?php
/**
 * Property-Based Test: Duration Response Interpretation
 * 
 * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
 * **Validates: Requirements 9.4**
 * 
 * Property: For any duration response (containing "วัน", "สัปดาห์", "เดือน") sent when 
 * triage state is 'duration', the system SHALL save it as duration value and transition to next state.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

// Load AIChat modules
if (function_exists('loadAIChatModule')) {
    loadAIChatModule();
}

class DurationResponsePropertyTest extends TestCase
{
    /**
     * Generate random duration responses with "วัน" (days) pattern
     * 
     * @return array Array of test data sets
     */
    public function dayDurationProvider(): array
    {
        $testCases = [];
        
        // Generate 100 test cases with random day values
        for ($i = 0; $i < 100; $i++) {
            $days = rand(1, 30);
            $testCases["days_{$i}"] = ["{$days} วัน", $days, 'day'];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random duration responses with "สัปดาห์" (weeks) pattern
     * 
     * @return array Array of test data sets
     */
    public function weekDurationProvider(): array
    {
        $testCases = [];
        
        // Generate 50 test cases with random week values
        for ($i = 0; $i < 50; $i++) {
            $weeks = rand(1, 12);
            $testCases["weeks_{$i}"] = ["{$weeks} สัปดาห์", $weeks, 'week'];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random duration responses with "เดือน" (months) pattern
     * 
     * @return array Array of test data sets
     */
    public function monthDurationProvider(): array
    {
        $testCases = [];
        
        // Generate 50 test cases with random month values
        for ($i = 0; $i < 50; $i++) {
            $months = rand(1, 12);
            $testCases["months_{$i}"] = ["{$months} เดือน", $months, 'month'];
        }
        
        return $testCases;
    }
    
    /**
     * Generate various duration response formats
     * 
     * @return array Array of test data sets
     */
    public function durationFormatProvider(): array
    {
        return [
            // Days
            ['1 วัน', 1, 'day'],
            ['2 วัน', 2, 'day'],
            ['7 วัน', 7, 'day'],
            ['14 วัน', 14, 'day'],
            ['30 วัน', 30, 'day'],
            // Weeks
            ['1 สัปดาห์', 1, 'week'],
            ['2 สัปดาห์', 2, 'week'],
            ['3 สัปดาห์', 3, 'week'],
            ['1 อาทิตย์', 1, 'week'],
            ['2 อาทิตย์', 2, 'week'],
            // Months
            ['1 เดือน', 1, 'month'],
            ['2 เดือน', 2, 'month'],
            ['6 เดือน', 6, 'month'],
            // Hours
            ['2 ชั่วโมง', 2, 'hour'],
            ['6 ชั่วโมง', 6, 'hour'],
            ['12 ชั่วโมง', 12, 'hour'],
            // Years
            ['1 ปี', 1, 'year'],
            ['2 ปี', 2, 'year'],
        ];
    }
    
    /**
     * Generate time word responses
     * 
     * @return array Array of test data sets
     */
    public function timeWordProvider(): array
    {
        return [
            ['เมื่อวาน', 1, 'day'],
            ['วันนี้', 0, 'day'],
            ['เมื่อกี้', 0, 'hour'],
            ['เมื่อคืน', 1, 'day'],
            ['สักครู่', 0, 'hour'],
            ['นานแล้ว', 7, 'day'],
        ];
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
     * Property Test: isDurationResponse returns true for valid duration patterns with "วัน"
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider dayDurationProvider
     */
    public function testIsDurationResponseReturnsTrueForDayPatterns(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $this->assertTrue(
            $engine->isDurationResponse($message),
            "Message '{$message}' should be recognized as a duration response"
        );
    }
    
    /**
     * Property Test: isDurationResponse returns true for valid duration patterns with "สัปดาห์"
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider weekDurationProvider
     */
    public function testIsDurationResponseReturnsTrueForWeekPatterns(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $this->assertTrue(
            $engine->isDurationResponse($message),
            "Message '{$message}' should be recognized as a duration response"
        );
    }
    
    /**
     * Property Test: isDurationResponse returns true for valid duration patterns with "เดือน"
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider monthDurationProvider
     */
    public function testIsDurationResponseReturnsTrueForMonthPatterns(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $this->assertTrue(
            $engine->isDurationResponse($message),
            "Message '{$message}' should be recognized as a duration response"
        );
    }
    
    /**
     * Property Test: isDurationResponse returns true for various duration formats
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider durationFormatProvider
     */
    public function testIsDurationResponseHandlesVariousFormats(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $this->assertTrue(
            $engine->isDurationResponse($message),
            "Message '{$message}' should be recognized as a duration response"
        );
    }
    
    /**
     * Property Test: isDurationResponse returns true for time word responses
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider timeWordProvider
     */
    public function testIsDurationResponseHandlesTimeWords(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $this->assertTrue(
            $engine->isDurationResponse($message),
            "Message '{$message}' should be recognized as a duration response"
        );
    }
    
    /**
     * Property Test: isDurationResponse returns false for non-duration text
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     */
    public function testIsDurationResponseReturnsFalseForNonDurationText(): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Non-duration text should not be recognized
        $nonDurationTexts = [
            'ปวดหัว',
            'ไข้',
            'ไอ',
            '5',
            '7',
            'ไม่แพ้',
            'ไม่มี',
            'สวัสดี',
            'รุนแรงมาก',
            'ปานกลาง',
        ];
        
        foreach ($nonDurationTexts as $text) {
            $this->assertFalse(
                $engine->isDurationResponse($text),
                "Text '{$text}' should NOT be recognized as a duration response"
            );
        }
    }
    
    /**
     * Property Test: parseDurationValue correctly parses duration patterns
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider durationFormatProvider
     */
    public function testParseDurationValueCorrectlyParsesDuration(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $result = $engine->parseDurationValue($message);
        
        $this->assertNotNull(
            $result,
            "parseDurationValue should return a result for '{$message}'"
        );
        
        $this->assertEquals(
            $expectedValue,
            $result['value'],
            "Duration value for '{$message}' should be {$expectedValue}"
        );
        
        $this->assertEquals(
            $expectedUnit,
            $result['unit'],
            "Duration unit for '{$message}' should be '{$expectedUnit}'"
        );
    }
    
    /**
     * Property Test: For any duration response in duration state, 
     * the system transitions to severity state
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider dayDurationProvider
     */
    public function testDurationResponseInDurationStateTransitionsToSeverity(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the state to 'duration'
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'state' => 'duration',
            'data' => [
                'symptoms' => ['ปวดหัว'],
                'duration' => null,
                'severity' => null,
                'red_flags' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process the duration message
        $result = $engine->process($message);
        
        // Verify the state transitioned to 'severity'
        $this->assertEquals(
            'severity',
            $result['state'],
            "After processing duration '{$message}', state should transition to 'severity'"
        );
        
        // Verify duration was saved
        $triageData = $engine->getTriageData();
        $this->assertNotNull(
            $triageData['duration'],
            "Duration value should be saved after processing duration response"
        );
    }
    
    /**
     * Property Test: Duration value is preserved in triage data after state transition
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider durationFormatProvider
     */
    public function testDurationValuePreservedInTriageData(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the state to 'duration'
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'state' => 'duration',
            'data' => [
                'symptoms' => ['ปวดหัว'],
                'duration' => null,
                'severity' => null,
                'red_flags' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process the duration message
        $engine->process($message);
        
        // Verify duration was saved correctly
        $triageData = $engine->getTriageData();
        
        // The duration should contain the original message or extracted value
        $this->assertNotEmpty(
            $triageData['duration'],
            "Duration should be saved in triage data"
        );
        
        // Verify the duration contains the expected pattern
        $this->assertStringContainsString(
            (string)$expectedValue,
            $triageData['duration'],
            "Duration '{$triageData['duration']}' should contain the value '{$expectedValue}'"
        );
    }
    
    /**
     * Property Test: Time word responses are correctly interpreted
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     * 
     * @dataProvider timeWordProvider
     */
    public function testTimeWordResponsesTransitionToSeverity(string $message, int $expectedValue, string $expectedUnit): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        // Use reflection to set the state to 'duration'
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'state' => 'duration',
            'data' => [
                'symptoms' => ['ปวดหัว'],
                'duration' => null,
                'severity' => null,
                'red_flags' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process the time word message
        $result = $engine->process($message);
        
        // Verify the state transitioned to 'severity'
        $this->assertEquals(
            'severity',
            $result['state'],
            "After processing time word '{$message}', state should transition to 'severity'"
        );
        
        // Verify duration was saved
        $triageData = $engine->getTriageData();
        $this->assertNotNull(
            $triageData['duration'],
            "Duration value should be saved after processing time word response"
        );
    }
    
    /**
     * Property Test: Symptoms are preserved when processing duration response
     * 
     * **Feature: liff-ai-assistant-integration, Property 9: Duration Response Interpretation**
     * **Validates: Requirements 9.4**
     */
    public function testSymptomsPreservedWhenProcessingDuration(): void
    {
        $mockPdo = $this->createMockedPdo();
        $engine = new \Modules\AIChat\Services\TriageEngine(null, null, $mockPdo);
        
        $initialSymptoms = ['ปวดหัว', 'ไข้'];
        
        // Use reflection to set the state to 'duration' with symptoms
        $reflection = new \ReflectionClass($engine);
        $stateProperty = $reflection->getProperty('currentState');
        $stateProperty->setAccessible(true);
        $stateProperty->setValue($engine, [
            'state' => 'duration',
            'data' => [
                'symptoms' => $initialSymptoms,
                'duration' => null,
                'severity' => null,
                'red_flags' => [],
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Process a duration message
        $engine->process('3 วัน');
        
        // Verify symptoms are preserved
        $triageData = $engine->getTriageData();
        
        $this->assertEquals(
            $initialSymptoms,
            $triageData['symptoms'],
            "Symptoms should be preserved after processing duration response"
        );
    }
}
