<?php
/**
 * Property-Based Test: Message Pagination Limit
 * 
 * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
 * **Validates: Requirements 11.3**
 * 
 * Property: For any conversation and any page request, the returned messages 
 * should contain at most the specified limit (default 50).
 */

namespace Tests\InboxChat;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/InboxService.php';

class MessagePaginationLimitPropertyTest extends TestCase
{
    private $pdo;
    private $lineAccountId = 1;
    
    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create the messages table
        $this->pdo->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                line_account_id INTEGER NOT NULL,
                direction VARCHAR(20) DEFAULT 'incoming',
                message_type VARCHAR(50) DEFAULT 'text',
                content TEXT,
                is_read INTEGER DEFAULT 0,
                sent_by INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create users table (needed for foreign key reference)
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL,
                line_user_id VARCHAR(100),
                display_name VARCHAR(255),
                picture_url TEXT,
                phone VARCHAR(50),
                email VARCHAR(255),
                is_blocked INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_interaction DATETIME
            )
        ");
    }
    
    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    
    /**
     * Generate test data with various message counts and limit values
     * 
     * @return array Array of test data sets [messageCount, limit, page]
     */
    public function paginationDataProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random pagination configurations for property testing
        for ($i = 0; $i < 100; $i++) {
            // Random message count (0 to 200)
            $messageCount = rand(0, 200);
            
            // Random limit (1 to 100, as the service caps at 100)
            $limit = rand(1, 100);
            
            // Random page (1 to 5)
            $page = rand(1, 5);
            
            $testCases["pagination_{$i}"] = [
                $messageCount,
                $limit,
                $page
            ];
        }
        
        // Add edge cases
        $testCases['empty_conversation'] = [0, 50, 1];
        $testCases['exactly_limit'] = [50, 50, 1];
        $testCases['less_than_limit'] = [25, 50, 1];
        $testCases['more_than_limit'] = [100, 50, 1];
        $testCases['default_limit'] = [75, 50, 1];
        $testCases['small_limit'] = [100, 10, 1];
        $testCases['large_limit_capped'] = [200, 150, 1]; // Should be capped to 100
        $testCases['second_page'] = [100, 50, 2];
        $testCases['last_page_partial'] = [75, 50, 2]; // Should return 25
        
        return $testCases;
    }
    
    /**
     * Property Test: Returned messages count should never exceed the specified limit
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     * 
     * @dataProvider paginationDataProvider
     */
    public function testReturnedMessagesNeverExceedLimit(int $messageCount, int $limit, int $page): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert random messages for this user
        $this->insertMessages($userId, $messageCount);
        
        // Create service and get messages
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, $page, $limit);
        
        // The effective limit is capped at 100 by the service
        $effectiveLimit = min($limit, 100);
        
        // Property: returned messages should never exceed the limit
        $messageCount = count($result['messages']);
        $this->assertLessThanOrEqual(
            $effectiveLimit,
            $messageCount,
            "Returned messages count ({$messageCount}) should not exceed limit ({$effectiveLimit})"
        );
    }

    
    /**
     * Property Test: Returned limit in response matches effective limit
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     * 
     * @dataProvider paginationDataProvider
     */
    public function testResponseLimitMatchesEffectiveLimit(int $messageCount, int $limit, int $page): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert random messages for this user
        $this->insertMessages($userId, $messageCount);
        
        // Create service and get messages
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, $page, $limit);
        
        // The effective limit is capped at 100 by the service
        $effectiveLimit = max(1, min($limit, 100));
        
        // Property: response limit should match effective limit
        $this->assertEquals(
            $effectiveLimit,
            $result['limit'],
            "Response limit should match effective limit (capped at 100)"
        );
    }
    
    /**
     * Property Test: Default limit of 50 is applied when not specified
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     */
    public function testDefaultLimitIsFifty(): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert more than 50 messages
        $this->insertMessages($userId, 100);
        
        // Create service and get messages without specifying limit
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, 1); // No limit specified
        
        // Property: default limit should be 50
        $this->assertEquals(50, $result['limit'], "Default limit should be 50");
        $this->assertLessThanOrEqual(50, count($result['messages']), "Messages should not exceed default limit of 50");
    }
    
    /**
     * Property Test: has_more flag is correct based on remaining messages
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     * 
     * @dataProvider paginationDataProvider
     */
    public function testHasMoreFlagIsCorrect(int $messageCount, int $limit, int $page): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert messages
        $this->insertMessages($userId, $messageCount);
        
        // Create service and get messages
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, $page, $limit);
        
        // Calculate expected has_more
        $effectiveLimit = max(1, min($limit, 100));
        $offset = ($page - 1) * $effectiveLimit;
        $expectedHasMore = ($offset + $effectiveLimit) < $messageCount;
        
        // Property: has_more should correctly indicate if more messages exist
        $this->assertEquals(
            $expectedHasMore,
            $result['has_more'],
            "has_more flag should be " . ($expectedHasMore ? 'true' : 'false') . 
            " for {$messageCount} messages, limit {$effectiveLimit}, page {$page}"
        );
    }

    
    /**
     * Property Test: Total count is accurate regardless of pagination
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     * 
     * @dataProvider paginationDataProvider
     */
    public function testTotalCountIsAccurate(int $messageCount, int $limit, int $page): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert messages
        $this->insertMessages($userId, $messageCount);
        
        // Create service and get messages
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, $page, $limit);
        
        // Property: total should equal the actual message count
        $this->assertEquals(
            $messageCount,
            $result['total'],
            "Total count should equal actual message count"
        );
    }
    
    /**
     * Property Test: Page number in response matches requested page
     * 
     * **Feature: inbox-chat-upgrade, Property 13: Message Pagination Limit**
     * **Validates: Requirements 11.3**
     * 
     * @dataProvider paginationDataProvider
     */
    public function testPageNumberMatchesRequest(int $messageCount, int $limit, int $page): void
    {
        // Create a test user
        $userId = $this->createTestUser();
        
        // Insert messages
        $this->insertMessages($userId, $messageCount);
        
        // Create service and get messages
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        $result = $service->getMessages($userId, $page, $limit);
        
        // Page should be at least 1 (service enforces this)
        $expectedPage = max(1, $page);
        
        // Property: page in response should match requested page
        $this->assertEquals(
            $expectedPage,
            $result['page'],
            "Page number in response should match requested page"
        );
    }
    
    /**
     * Create a test user and return the user ID
     */
    private function createTestUser(): int
    {
        static $userCounter = 0;
        $userCounter++;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (line_account_id, line_user_id, display_name, created_at)
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $this->lineAccountId,
            'U' . str_pad($userCounter, 32, '0', STR_PAD_LEFT),
            'Test User ' . $userCounter
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Insert random messages for a user
     */
    private function insertMessages(int $userId, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        
        $directions = ['incoming', 'outgoing'];
        $messageTypes = ['text', 'image', 'sticker'];
        
        $stmt = $this->pdo->prepare("
            INSERT INTO messages (user_id, line_account_id, direction, message_type, content, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now', '-' || ? || ' minutes'))
        ");
        
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                $userId,
                $this->lineAccountId,
                $directions[array_rand($directions)],
                $messageTypes[array_rand($messageTypes)],
                'Test message ' . ($i + 1) . ' - ' . bin2hex(random_bytes(8)),
                rand(0, 1),
                $count - $i // Older messages have higher minute offset
            ]);
        }
    }
}
