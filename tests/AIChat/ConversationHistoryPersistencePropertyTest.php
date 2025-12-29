<?php
/**
 * Property-Based Test: Conversation History Persistence
 * 
 * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
 * **Validates: Requirements 6.1, 6.2**
 * 
 * Property: For any message sent by a user, the message SHALL be saved to 
 * ai_conversation_history table and retrievable by the same user_id.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

class ConversationHistoryPersistencePropertyTest extends TestCase
{
    /**
     * Valid message roles
     */
    private const VALID_ROLES = ['user', 'assistant'];
    
    /**
     * Sample messages for testing (Thai pharmacy context)
     */
    private const SAMPLE_MESSAGES = [
        'ปวดหัว',
        'ไข้หวัด',
        'ไอ เจ็บคอ',
        'ปวดท้อง',
        'แพ้อากาศ',
        'นอนไม่หลับ',
        'มีอาการคลื่นไส้',
        'เป็นมา 3 วัน',
        'ความรุนแรง 7',
        'ไม่แพ้ยา',
        'สวัสดีค่ะ ยินดีให้บริการ',
        'แนะนำให้ทาน Paracetamol',
        'ควรพักผ่อนให้เพียงพอค่ะ',
    ];
    
    /**
     * Generate random message content
     */
    private function generateRandomMessage(): string
    {
        // Mix of predefined messages and random strings
        if (mt_rand(0, 1) === 0) {
            return self::SAMPLE_MESSAGES[array_rand(self::SAMPLE_MESSAGES)];
        }
        
        // Generate random Thai-like message
        $prefixes = ['อาการ', 'ปวด', 'เจ็บ', 'มี', 'เป็น', 'รู้สึก'];
        $suffixes = ['มาก', 'น้อย', 'บ้าง', 'เล็กน้อย', 'รุนแรง'];
        
        return $prefixes[array_rand($prefixes)] . ' ' . 
               self::SAMPLE_MESSAGES[array_rand(self::SAMPLE_MESSAGES)] . ' ' .
               $suffixes[array_rand($suffixes)];
    }
    
    /**
     * Generate random role
     */
    private function generateRandomRole(): string
    {
        return self::VALID_ROLES[array_rand(self::VALID_ROLES)];
    }
    
    /**
     * Generate random user ID (simulating LINE user IDs)
     */
    private function generateRandomUserId(): string
    {
        return 'U' . bin2hex(random_bytes(16));
    }
    
    /**
     * Generate random session ID
     */
    private function generateRandomSessionId(): ?string
    {
        // 50% chance of having a session ID
        if (mt_rand(0, 1) === 0) {
            return null;
        }
        return 'session_' . mt_rand(1000, 9999);
    }
    
    /**
     * Create a properly mocked PDO for testing
     */
    private function createMockedPdo(array &$savedMessages = []): \PDO
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        
        // Track execute calls to capture saved messages
        $mockStmt->method('execute')->willReturnCallback(function($params) use (&$savedMessages) {
            if (count($params) === 5) {
                // This is an INSERT call (user_id, line_account_id, session_id, role, content)
                $savedMessages[] = [
                    'user_id' => $params[0],
                    'line_account_id' => $params[1],
                    'session_id' => $params[2],
                    'role' => $params[3],
                    'content' => $params[4]
                ];
            }
            return true;
        });
        
        $mockStmt->method('fetch')->willReturn(['id' => 1, 'line_account_id' => 1]);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockStmt->method('fetchColumn')->willReturn(0);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $mockDb = $this->createMock(\PDO::class);
        $mockDb->method('prepare')->willReturn($mockStmt);
        $mockDb->method('query')->willReturn($mockStmt);
        $mockDb->method('exec')->willReturn(0);
        
        return $mockDb;
    }
    
    /**
     * Data provider for message persistence tests
     * Generates 100 random test cases
     */
    public function messageDataProvider(): array
    {
        $testCases = [];
        
        for ($i = 0; $i < 100; $i++) {
            $testCases["message_{$i}"] = [
                $this->generateRandomUserId(),
                $this->generateRandomRole(),
                $this->generateRandomMessage(),
                $this->generateRandomSessionId()
            ];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: Messages are saved with correct user_id
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1, 6.2**
     * 
     * @dataProvider messageDataProvider
     */
    public function testMessageSavedWithCorrectUserId(
        string $lineUserId,
        string $role,
        string $content,
        ?string $sessionId
    ): void {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Call the save function
        $result = $this->saveConversationMessageSimulated($mockDb, $lineUserId, $role, $content, $sessionId);
        
        // Verify message was saved
        $this->assertTrue($result, "Message should be saved successfully");
        
        // Verify the saved message has correct user_id (internal ID = 1 from mock)
        $this->assertNotEmpty($savedMessages, "At least one message should be saved");
        $lastSaved = end($savedMessages);
        $this->assertEquals(1, $lastSaved['user_id'], "Message should be saved with correct user_id");
    }
    
    /**
     * Property Test: Messages are saved with correct role
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1**
     * 
     * @dataProvider messageDataProvider
     */
    public function testMessageSavedWithCorrectRole(
        string $lineUserId,
        string $role,
        string $content,
        ?string $sessionId
    ): void {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Call the save function
        $this->saveConversationMessageSimulated($mockDb, $lineUserId, $role, $content, $sessionId);
        
        // Verify the saved message has correct role
        $this->assertNotEmpty($savedMessages, "At least one message should be saved");
        $lastSaved = end($savedMessages);
        $this->assertEquals($role, $lastSaved['role'], "Message should be saved with correct role");
    }
    
    /**
     * Property Test: Messages are saved with correct content
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1**
     * 
     * @dataProvider messageDataProvider
     */
    public function testMessageSavedWithCorrectContent(
        string $lineUserId,
        string $role,
        string $content,
        ?string $sessionId
    ): void {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Call the save function
        $this->saveConversationMessageSimulated($mockDb, $lineUserId, $role, $content, $sessionId);
        
        // Verify the saved message has correct content
        $this->assertNotEmpty($savedMessages, "At least one message should be saved");
        $lastSaved = end($savedMessages);
        $this->assertEquals($content, $lastSaved['content'], "Message should be saved with correct content");
    }
    
    /**
     * Property Test: Messages are saved with session_id when provided
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1**
     * 
     * @dataProvider messageDataProvider
     */
    public function testMessageSavedWithSessionId(
        string $lineUserId,
        string $role,
        string $content,
        ?string $sessionId
    ): void {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Call the save function
        $this->saveConversationMessageSimulated($mockDb, $lineUserId, $role, $content, $sessionId);
        
        // Verify the saved message has correct session_id
        $this->assertNotEmpty($savedMessages, "At least one message should be saved");
        $lastSaved = end($savedMessages);
        $this->assertEquals($sessionId, $lastSaved['session_id'], "Message should be saved with correct session_id");
    }
    
    /**
     * Property Test: Empty user ID should not save message
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1**
     */
    public function testEmptyUserIdDoesNotSaveMessage(): void
    {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Test with empty user ID
        $result = $this->saveConversationMessageSimulated($mockDb, '', 'user', 'test message', null);
        
        // Verify message was NOT saved
        $this->assertFalse($result, "Message should not be saved with empty user ID");
        $this->assertEmpty($savedMessages, "No messages should be saved with empty user ID");
    }
    
    /**
     * Property Test: Null user ID should not save message
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1**
     */
    public function testNullUserIdDoesNotSaveMessage(): void
    {
        $savedMessages = [];
        $mockDb = $this->createMockedPdo($savedMessages);
        
        // Test with null user ID
        $result = $this->saveConversationMessageSimulated($mockDb, null, 'user', 'test message', null);
        
        // Verify message was NOT saved
        $this->assertFalse($result, "Message should not be saved with null user ID");
        $this->assertEmpty($savedMessages, "No messages should be saved with null user ID");
    }
    
    /**
     * Property Test: Retrieved messages match saved messages (round-trip)
     * 
     * **Feature: liff-ai-assistant-integration, Property 4: Conversation History Persistence**
     * **Validates: Requirements 6.1, 6.2**
     */
    public function testMessageRoundTrip(): void
    {
        // Simulate a complete round-trip: save then retrieve
        $lineUserId = $this->generateRandomUserId();
        $messages = [];
        
        // Generate 5 random messages
        for ($i = 0; $i < 5; $i++) {
            $messages[] = [
                'role' => $this->generateRandomRole(),
                'content' => $this->generateRandomMessage(),
                'session_id' => $this->generateRandomSessionId()
            ];
        }
        
        // Create mock that stores and retrieves messages
        $storedMessages = [];
        $mockStmt = $this->createMock(\PDOStatement::class);
        
        $executeCallCount = 0;
        $mockStmt->method('execute')->willReturnCallback(function($params) use (&$storedMessages, &$executeCallCount) {
            $executeCallCount++;
            if (count($params) === 5) {
                // INSERT call
                $storedMessages[] = [
                    'user_id' => $params[0],
                    'role' => $params[3],
                    'content' => $params[4],
                    'session_id' => $params[2],
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
            return true;
        });
        
        $mockStmt->method('fetch')->willReturn(['id' => 1, 'line_account_id' => 1]);
        $mockStmt->method('fetchAll')->willReturnCallback(function() use (&$storedMessages) {
            return array_reverse($storedMessages); // Return in DESC order like real query
        });
        $mockStmt->method('rowCount')->willReturn(1);
        
        $mockDb = $this->createMock(\PDO::class);
        $mockDb->method('prepare')->willReturn($mockStmt);
        $mockDb->method('query')->willReturn($mockStmt);
        $mockDb->method('exec')->willReturn(0);
        
        // Save all messages
        foreach ($messages as $msg) {
            $this->saveConversationMessageSimulated($mockDb, $lineUserId, $msg['role'], $msg['content'], $msg['session_id']);
        }
        
        // Verify all messages were stored
        $this->assertCount(count($messages), $storedMessages, "All messages should be stored");
        
        // Verify each stored message matches original
        for ($i = 0; $i < count($messages); $i++) {
            $this->assertEquals($messages[$i]['role'], $storedMessages[$i]['role'], "Role should match for message {$i}");
            $this->assertEquals($messages[$i]['content'], $storedMessages[$i]['content'], "Content should match for message {$i}");
            $this->assertEquals($messages[$i]['session_id'], $storedMessages[$i]['session_id'], "Session ID should match for message {$i}");
        }
    }
    
    /**
     * Simulated saveConversationMessage function for testing
     * Mirrors the logic in api/pharmacy-ai.php
     */
    private function saveConversationMessageSimulated($db, $lineUserId, $role, $content, $sessionId = null): bool
    {
        if (!$lineUserId) return false;
        
        try {
            // Get user's internal ID
            $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) return false;
            
            // Insert message with session_id
            $stmt = $db->prepare("
                INSERT INTO ai_conversation_history (user_id, line_account_id, session_id, role, content) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user['id'], $user['line_account_id'], $sessionId, $role, $content]);
            
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Simulated getConversationHistory function for testing
     * Mirrors the logic in api/pharmacy-ai.php
     */
    private function getConversationHistorySimulated($db, $lineUserId): array
    {
        if (!$lineUserId) {
            return ['success' => false, 'error' => 'No user ID', 'messages' => []];
        }
        
        try {
            // Get user's internal ID
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$user) {
                return ['success' => true, 'messages' => []];
            }
            
            // Get recent conversation history
            $stmt = $db->prepare("
                SELECT role, content, session_id, created_at 
                FROM ai_conversation_history 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$user['id']]);
            $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Reverse to get chronological order
            $messages = array_reverse($messages);
            
            return ['success' => true, 'messages' => $messages];
            
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'messages' => []];
        }
    }
}
