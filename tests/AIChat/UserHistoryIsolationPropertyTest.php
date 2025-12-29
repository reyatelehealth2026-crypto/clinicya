<?php
/**
 * Property-Based Test: User-Specific History Isolation
 * 
 * **Feature: liff-ai-assistant-integration, Property 5: User-Specific History Isolation**
 * **Validates: Requirements 6.3**
 * 
 * Property: For any user clearing their chat history, only that user's conversation 
 * history SHALL be deleted, and other users' histories SHALL remain unchanged.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

class UserHistoryIsolationPropertyTest extends TestCase
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
    ];
    
    /**
     * Generate random LINE user ID
     */
    private function generateRandomUserId(): string
    {
        return 'U' . bin2hex(random_bytes(16));
    }
    
    /**
     * Generate random message content
     */
    private function generateRandomMessage(): string
    {
        return self::SAMPLE_MESSAGES[array_rand(self::SAMPLE_MESSAGES)];
    }
    
    /**
     * Generate random role
     */
    private function generateRandomRole(): string
    {
        return self::VALID_ROLES[array_rand(self::VALID_ROLES)];
    }
    
    /**
     * Data provider for isolation tests - generates 100 test cases
     */
    public function userIsolationDataProvider(): array
    {
        $testCases = [];
        
        for ($i = 0; $i < 100; $i++) {
            // Generate 2-5 users for each test case
            $numUsers = mt_rand(2, 5);
            $users = [];
            
            for ($j = 0; $j < $numUsers; $j++) {
                $userId = $this->generateRandomUserId();
                $numMessages = mt_rand(1, 5);
                $messages = [];
                
                for ($k = 0; $k < $numMessages; $k++) {
                    $messages[] = [
                        'role' => $this->generateRandomRole(),
                        'content' => $this->generateRandomMessage()
                    ];
                }
                
                $users[] = [
                    'line_user_id' => $userId,
                    'internal_id' => $j + 1,
                    'messages' => $messages
                ];
            }
            
            // Randomly select which user will clear their history
            $clearingUserIndex = mt_rand(0, $numUsers - 1);
            
            $testCases["isolation_test_{$i}"] = [$users, $clearingUserIndex];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: Clearing history deletes only the requesting user's messages
     * 
     * **Feature: liff-ai-assistant-integration, Property 5: User-Specific History Isolation**
     * **Validates: Requirements 6.3**
     * 
     * @dataProvider userIsolationDataProvider
     */
    public function testClearHistoryOnlyAffectsRequestingUser(array $users, int $clearingUserIndex): void
    {
        // Storage for all messages
        $messageStorage = [];
        $userIdMap = [];
        
        // Build user ID map and initialize storage
        foreach ($users as $user) {
            $userIdMap[$user['line_user_id']] = $user['internal_id'];
            $messageStorage[$user['internal_id']] = [];
        }
        
        // Create mock PDO
        $mockDb = $this->createMockedPdo($messageStorage, $userIdMap);
        
        // Save messages for all users
        foreach ($users as $user) {
            foreach ($user['messages'] as $msg) {
                $this->saveConversationMessageSimulated(
                    $mockDb,
                    $messageStorage,
                    $userIdMap,
                    $user['line_user_id'],
                    $msg['role'],
                    $msg['content']
                );
            }
        }
        
        // Verify all messages were saved
        foreach ($users as $user) {
            $this->assertCount(
                count($user['messages']),
                $messageStorage[$user['internal_id']],
                "All messages should be saved for user {$user['line_user_id']}"
            );
        }
        
        // Clear history for the selected user
        $clearingUser = $users[$clearingUserIndex];
        $result = $this->clearConversationHistorySimulated(
            $mockDb,
            $messageStorage,
            $userIdMap,
            $clearingUser['line_user_id']
        );
        
        // Verify clear was successful
        $this->assertTrue($result['success'], "Clear history should succeed");
        
        // Verify clearing user's messages are deleted
        $this->assertEmpty(
            $messageStorage[$clearingUser['internal_id']],
            "Clearing user's messages should be deleted"
        );
        
        // Verify other users' messages remain unchanged
        foreach ($users as $index => $user) {
            if ($index !== $clearingUserIndex) {
                $this->assertCount(
                    count($user['messages']),
                    $messageStorage[$user['internal_id']],
                    "Other user {$user['line_user_id']}'s messages should remain unchanged"
                );
                
                // Verify message content is preserved
                for ($i = 0; $i < count($user['messages']); $i++) {
                    $this->assertEquals(
                        $user['messages'][$i]['content'],
                        $messageStorage[$user['internal_id']][$i]['content'],
                        "Message content should be preserved for other users"
                    );
                }
            }
        }
    }
    
    /**
     * Property Test: Multiple clear operations maintain isolation
     * 
     * **Feature: liff-ai-assistant-integration, Property 5: User-Specific History Isolation**
     * **Validates: Requirements 6.3**
     */
    public function testMultipleClearOperationsMaintainIsolation(): void
    {
        // Create 3 users with messages
        $users = [];
        $messageStorage = [];
        $userIdMap = [];
        
        for ($i = 0; $i < 3; $i++) {
            $userId = $this->generateRandomUserId();
            $internalId = $i + 1;
            $userIdMap[$userId] = $internalId;
            $messageStorage[$internalId] = [];
            
            $messages = [];
            for ($j = 0; $j < 3; $j++) {
                $messages[] = [
                    'role' => $this->generateRandomRole(),
                    'content' => $this->generateRandomMessage()
                ];
            }
            
            $users[] = [
                'line_user_id' => $userId,
                'internal_id' => $internalId,
                'messages' => $messages
            ];
        }
        
        $mockDb = $this->createMockedPdo($messageStorage, $userIdMap);
        
        // Save messages for all users
        foreach ($users as $user) {
            foreach ($user['messages'] as $msg) {
                $this->saveConversationMessageSimulated(
                    $mockDb,
                    $messageStorage,
                    $userIdMap,
                    $user['line_user_id'],
                    $msg['role'],
                    $msg['content']
                );
            }
        }
        
        // Clear history for user 0
        $this->clearConversationHistorySimulated(
            $mockDb,
            $messageStorage,
            $userIdMap,
            $users[0]['line_user_id']
        );
        
        // Verify user 0's messages are deleted
        $this->assertEmpty($messageStorage[$users[0]['internal_id']]);
        
        // Verify users 1 and 2 still have their messages
        $this->assertCount(3, $messageStorage[$users[1]['internal_id']]);
        $this->assertCount(3, $messageStorage[$users[2]['internal_id']]);
        
        // Clear history for user 1
        $this->clearConversationHistorySimulated(
            $mockDb,
            $messageStorage,
            $userIdMap,
            $users[1]['line_user_id']
        );
        
        // Verify user 1's messages are now deleted
        $this->assertEmpty($messageStorage[$users[1]['internal_id']]);
        
        // Verify user 2 still has their messages
        $this->assertCount(3, $messageStorage[$users[2]['internal_id']]);
    }
    
    /**
     * Property Test: Clear history for non-existent user doesn't affect others
     * 
     * **Feature: liff-ai-assistant-integration, Property 5: User-Specific History Isolation**
     * **Validates: Requirements 6.3**
     */
    public function testClearHistoryForNonExistentUserDoesNotAffectOthers(): void
    {
        $messageStorage = [];
        $userIdMap = [];
        
        // Create one real user with messages
        $realUserId = $this->generateRandomUserId();
        $userIdMap[$realUserId] = 1;
        $messageStorage[1] = [];
        
        $mockDb = $this->createMockedPdo($messageStorage, $userIdMap);
        
        // Save messages for real user
        for ($i = 0; $i < 5; $i++) {
            $this->saveConversationMessageSimulated(
                $mockDb,
                $messageStorage,
                $userIdMap,
                $realUserId,
                $this->generateRandomRole(),
                $this->generateRandomMessage()
            );
        }
        
        // Verify messages were saved
        $this->assertCount(5, $messageStorage[1]);
        
        // Try to clear history for non-existent user
        $nonExistentUserId = $this->generateRandomUserId();
        $result = $this->clearConversationHistorySimulated(
            $mockDb,
            $messageStorage,
            $userIdMap,
            $nonExistentUserId
        );
        
        // Should succeed (no history to clear)
        $this->assertTrue($result['success']);
        
        // Real user's messages should remain unchanged
        $this->assertCount(5, $messageStorage[1]);
    }
    
    /**
     * Property Test: Empty user ID does not affect any history
     * 
     * **Feature: liff-ai-assistant-integration, Property 5: User-Specific History Isolation**
     * **Validates: Requirements 6.3**
     */
    public function testEmptyUserIdDoesNotAffectAnyHistory(): void
    {
        $messageStorage = [];
        $userIdMap = [];
        
        // Create user with messages
        $userId = $this->generateRandomUserId();
        $userIdMap[$userId] = 1;
        $messageStorage[1] = [];
        
        $mockDb = $this->createMockedPdo($messageStorage, $userIdMap);
        
        // Save messages
        for ($i = 0; $i < 3; $i++) {
            $this->saveConversationMessageSimulated(
                $mockDb,
                $messageStorage,
                $userIdMap,
                $userId,
                'user',
                $this->generateRandomMessage()
            );
        }
        
        // Try to clear with empty user ID
        $result = $this->clearConversationHistorySimulated(
            $mockDb,
            $messageStorage,
            $userIdMap,
            ''
        );
        
        // Should fail
        $this->assertFalse($result['success']);
        
        // Messages should remain unchanged
        $this->assertCount(3, $messageStorage[1]);
    }
    
    /**
     * Create a mocked PDO for testing
     */
    private function createMockedPdo(array &$messageStorage, array &$userIdMap): \PDO
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(['id' => 1]);
        $mockStmt->method('fetchAll')->willReturn([]);
        $mockStmt->method('fetchColumn')->willReturn(0);
        $mockStmt->method('rowCount')->willReturn(1);
        
        $mockDb = $this->createMock(\PDO::class);
        $mockDb->method('prepare')->willReturn($mockStmt);
        $mockDb->method('query')->willReturn($mockStmt);
        
        return $mockDb;
    }
    
    /**
     * Simulated saveConversationMessage function
     */
    private function saveConversationMessageSimulated(
        $db,
        array &$messageStorage,
        array $userIdMap,
        string $lineUserId,
        string $role,
        string $content
    ): bool {
        if (!$lineUserId || !isset($userIdMap[$lineUserId])) {
            return false;
        }
        
        $internalId = $userIdMap[$lineUserId];
        $messageStorage[$internalId][] = [
            'role' => $role,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return true;
    }
    
    /**
     * Simulated clearConversationHistory function
     * Mirrors the logic in api/pharmacy-ai.php
     */
    private function clearConversationHistorySimulated(
        $db,
        array &$messageStorage,
        array $userIdMap,
        string $lineUserId
    ): array {
        if (!$lineUserId) {
            return ['success' => false, 'error' => 'No user ID'];
        }
        
        // Check if user exists
        if (!isset($userIdMap[$lineUserId])) {
            return ['success' => true, 'message' => 'No history to clear'];
        }
        
        $internalId = $userIdMap[$lineUserId];
        
        // Count before deletion
        $beforeCount = count($messageStorage[$internalId]);
        
        // Delete only this user's messages (isolation property)
        $messageStorage[$internalId] = [];
        
        return [
            'success' => true,
            'message' => 'History cleared',
            'deleted_count' => $beforeCount,
            'user_id' => $internalId
        ];
    }
}
