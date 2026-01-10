<?php
/**
 * Property-Based Test: Search Result Relevance
 * 
 * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
 * **Validates: Requirements 5.1**
 * 
 * Property: For any search query and any set of conversations, all returned results 
 * should contain the query string in at least one of: customer name, message content, or tag name.
 */

namespace Tests\InboxChat;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/InboxService.php';

class SearchResultRelevancePropertyTest extends TestCase
{
    private $pdo;
    private $lineAccountId = 1;
    
    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create required tables
        $this->createTables();
        
        // Seed test data
        $this->seedTestData();
    }
    
    protected function tearDown(): void
    {
        $this->pdo = null;
    }
    
    private function createTables(): void
    {
        // Users table
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL,
                line_user_id VARCHAR(50),
                display_name VARCHAR(100),
                picture_url VARCHAR(255),
                phone VARCHAR(20),
                email VARCHAR(100),
                is_blocked INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_interaction DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Messages table
        $this->pdo->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                direction VARCHAR(10) NOT NULL,
                message_type VARCHAR(20) DEFAULT 'text',
                content TEXT,
                is_read INTEGER DEFAULT 0,
                sent_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // User tags table
        $this->pdo->exec("
            CREATE TABLE user_tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL,
                name VARCHAR(50) NOT NULL,
                color VARCHAR(20) DEFAULT '#3B82F6'
            )
        ");
        
        // User tag assignments table
        $this->pdo->exec("
            CREATE TABLE user_tag_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                UNIQUE(user_id, tag_id)
            )
        ");
        
        // Conversation assignments table
        $this->pdo->exec("
            CREATE TABLE conversation_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                assigned_to INTEGER NOT NULL,
                assigned_by INTEGER,
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) DEFAULT 'active',
                resolved_at DATETIME
            )
        ");
        
        // Admin users table
        $this->pdo->exec("
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) NOT NULL
            )
        ");
    }
    
    private function seedTestData(): void
    {
        // Create users with various names
        $users = [
            ['สมชาย ใจดี', 'U001'],
            ['สมหญิง รักเรียน', 'U002'],
            ['John Smith', 'U003'],
            ['Jane Doe', 'U004'],
            ['ร้านยา เภสัชกร', 'U005'],
            ['Customer Support', 'U006'],
            ['VIP Member Gold', 'U007'],
            ['ลูกค้าประจำ', 'U008'],
        ];
        
        foreach ($users as $user) {
            $stmt = $this->pdo->prepare("
                INSERT INTO users (line_account_id, display_name, line_user_id, last_interaction)
                VALUES (?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$this->lineAccountId, $user[0], $user[1]]);
        }
        
        // Create messages with various content
        $messages = [
            [1, 'incoming', 'สวัสดีครับ ต้องการสอบถามเรื่องยา'],
            [1, 'outgoing', 'สวัสดีค่ะ ยินดีให้บริการค่ะ'],
            [2, 'incoming', 'มียาแก้ปวดหัวไหมคะ'],
            [2, 'outgoing', 'มีค่ะ มี Paracetamol และ Ibuprofen'],
            [3, 'incoming', 'Hello, I need prescription refill'],
            [3, 'outgoing', 'Sure, please provide your prescription number'],
            [4, 'incoming', 'Order status inquiry for #12345'],
            [4, 'outgoing', 'Your order is being processed'],
            [5, 'incoming', 'ต้องการสั่งยาเบาหวาน'],
            [5, 'outgoing', 'กรุณาส่งใบสั่งยาค่ะ'],
            [6, 'incoming', 'Delivery tracking question'],
            [7, 'incoming', 'VIP discount inquiry'],
            [8, 'incoming', 'ขอบคุณสำหรับบริการดีๆ'],
        ];
        
        foreach ($messages as $msg) {
            $stmt = $this->pdo->prepare("
                INSERT INTO messages (line_account_id, user_id, direction, content, created_at)
                VALUES (?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$this->lineAccountId, $msg[0], $msg[1], $msg[2]]);
        }
        
        // Create tags
        $tags = [
            'VIP',
            'ลูกค้าประจำ',
            'Pharmacy',
            'New Customer',
            'Priority',
            'เบาหวาน',
            'Prescription',
        ];
        
        foreach ($tags as $tag) {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_tags (line_account_id, name)
                VALUES (?, ?)
            ");
            $stmt->execute([$this->lineAccountId, $tag]);
        }
        
        // Assign tags to users
        $tagAssignments = [
            [1, 1], // User 1 has VIP tag
            [2, 2], // User 2 has ลูกค้าประจำ tag
            [3, 3], // User 3 has Pharmacy tag
            [4, 4], // User 4 has New Customer tag
            [5, 6], // User 5 has เบาหวาน tag
            [7, 1], // User 7 has VIP tag
            [7, 5], // User 7 also has Priority tag
            [3, 7], // User 3 also has Prescription tag
        ];
        
        foreach ($tagAssignments as $assignment) {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_tag_assignments (user_id, tag_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$assignment[0], $assignment[1]]);
        }
    }

    
    /**
     * Generate random search queries for property testing
     * 
     * @return array Array of test data sets
     */
    public function searchQueryProvider(): array
    {
        $testCases = [];
        
        // Test with name-based searches
        $nameQueries = ['สมชาย', 'John', 'Jane', 'VIP', 'Customer', 'ร้านยา', 'ลูกค้า'];
        foreach ($nameQueries as $i => $query) {
            $testCases["name_search_{$i}"] = [$query];
        }
        
        // Test with message content searches
        $contentQueries = ['ยา', 'สวัสดี', 'prescription', 'order', 'Hello', 'Paracetamol', 'เบาหวาน'];
        foreach ($contentQueries as $i => $query) {
            $testCases["content_search_{$i}"] = [$query];
        }
        
        // Test with tag-based searches
        $tagQueries = ['VIP', 'Pharmacy', 'Priority', 'ลูกค้าประจำ', 'Prescription'];
        foreach ($tagQueries as $i => $query) {
            $testCases["tag_search_{$i}"] = [$query];
        }
        
        // Test with partial matches
        $partialQueries = ['สม', 'Jo', 'ยาแก้', 'order', 'Cust'];
        foreach ($partialQueries as $i => $query) {
            $testCases["partial_search_{$i}"] = [$query];
        }
        
        // Generate additional random queries from existing data
        $randomQueries = $this->generateRandomSearchQueries();
        foreach ($randomQueries as $i => $query) {
            $testCases["random_search_{$i}"] = [$query];
        }
        
        return $testCases;
    }
    
    /**
     * Generate random search queries based on existing data patterns
     */
    private function generateRandomSearchQueries(): array
    {
        $queries = [];
        
        // Common Thai words
        $thaiWords = ['สวัสดี', 'ขอบคุณ', 'ยา', 'สั่ง', 'ต้องการ', 'บริการ'];
        
        // Common English words
        $englishWords = ['Hello', 'Order', 'Customer', 'Support', 'Delivery', 'VIP'];
        
        // Mix of both
        $allWords = array_merge($thaiWords, $englishWords);
        
        // Generate 20 random queries
        for ($i = 0; $i < 20; $i++) {
            $queries[] = $allWords[array_rand($allWords)];
        }
        
        return $queries;
    }
    
    /**
     * Property Test: All search results contain the query in name, content, or tag
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     * 
     * @dataProvider searchQueryProvider
     */
    public function testSearchResultsContainQueryInNameContentOrTag(string $query): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Perform search
        $results = $service->searchMessages($query);
        
        // For each result, verify the query appears in at least one of: name, content, or tag
        foreach ($results as $result) {
            $foundMatch = false;
            $matchLocations = [];
            
            // Check display name (case-insensitive)
            if (isset($result['display_name']) && 
                stripos($result['display_name'], $query) !== false) {
                $foundMatch = true;
                $matchLocations[] = 'display_name';
            }
            
            // Check matched content
            if (isset($result['matched_content']) && 
                stripos($result['matched_content'], $query) !== false) {
                $foundMatch = true;
                $matchLocations[] = 'matched_content';
            }
            
            // Check matches array for tag matches
            if (isset($result['matches']) && is_array($result['matches'])) {
                foreach ($result['matches'] as $match) {
                    if (isset($match['content']) && 
                        stripos($match['content'], $query) !== false) {
                        $foundMatch = true;
                        $matchLocations[] = 'matches[' . $match['type'] . ']';
                    }
                }
            }
            
            $this->assertTrue(
                $foundMatch,
                sprintf(
                    "Search result for user_id=%d should contain query '%s' in name, content, or tag. " .
                    "display_name='%s', matched_content='%s'",
                    $result['user_id'] ?? 'unknown',
                    $query,
                    $result['display_name'] ?? '',
                    $result['matched_content'] ?? ''
                )
            );
        }
    }

    
    /**
     * Property Test: Search results are non-empty for known data
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testSearchReturnsResultsForKnownData(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Test searches that should definitely return results
        $knownQueries = [
            'สมชาย' => 'name match',
            'John' => 'name match',
            'ยา' => 'content match',
            'prescription' => 'content match',
            'VIP' => 'tag match',
        ];
        
        foreach ($knownQueries as $query => $expectedType) {
            $results = $service->searchMessages($query);
            
            $this->assertNotEmpty(
                $results,
                "Search for '{$query}' ({$expectedType}) should return results"
            );
        }
    }
    
    /**
     * Property Test: Empty search returns empty results
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testEmptySearchReturnsEmptyResults(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Empty string
        $results = $service->searchMessages('');
        $this->assertEmpty($results, 'Empty search should return empty results');
        
        // Whitespace only
        $results = $service->searchMessages('   ');
        $this->assertEmpty($results, 'Whitespace-only search should return empty results');
    }
    
    /**
     * Property Test: Search with no matches returns empty results
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testSearchWithNoMatchesReturnsEmpty(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Search for something that definitely doesn't exist
        $results = $service->searchMessages('XYZNONEXISTENT12345');
        
        $this->assertEmpty(
            $results,
            'Search for non-existent term should return empty results'
        );
    }
    
    /**
     * Property Test: Search results are unique by user
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testSearchResultsAreUniqueByUser(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Search for a common term that might match multiple messages from same user
        $results = $service->searchMessages('สวัสดี');
        
        // Extract user IDs
        $userIds = array_map(function($r) { return $r['user_id']; }, $results);
        
        // Check for uniqueness
        $uniqueUserIds = array_unique($userIds);
        
        $this->assertEquals(
            count($uniqueUserIds),
            count($userIds),
            'Search results should be unique by user (no duplicate users)'
        );
    }
    
    /**
     * Property Test: All match types are correctly identified
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testMatchTypesAreCorrectlyIdentified(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Test name match
        $results = $service->searchMessages('สมชาย');
        $this->assertNotEmpty($results);
        $hasNameMatch = false;
        foreach ($results as $result) {
            if (isset($result['matches'])) {
                foreach ($result['matches'] as $match) {
                    if ($match['type'] === 'name') {
                        $hasNameMatch = true;
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($hasNameMatch, 'Name search should identify name match type');
        
        // Test message match
        $results = $service->searchMessages('Paracetamol');
        $this->assertNotEmpty($results);
        $hasMessageMatch = false;
        foreach ($results as $result) {
            if (isset($result['matches'])) {
                foreach ($result['matches'] as $match) {
                    if ($match['type'] === 'message') {
                        $hasMessageMatch = true;
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($hasMessageMatch, 'Content search should identify message match type');
        
        // Test tag match
        $results = $service->searchMessages('Pharmacy');
        $this->assertNotEmpty($results);
        $hasTagMatch = false;
        foreach ($results as $result) {
            if (isset($result['matches'])) {
                foreach ($result['matches'] as $match) {
                    if ($match['type'] === 'tag') {
                        $hasTagMatch = true;
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($hasTagMatch, 'Tag search should identify tag match type');
    }
    
    /**
     * Property Test: Case-insensitive search works correctly
     * 
     * **Feature: inbox-chat-upgrade, Property 5: Search Result Relevance**
     * **Validates: Requirements 5.1**
     */
    public function testCaseInsensitiveSearch(): void
    {
        $service = new \InboxService($this->pdo, $this->lineAccountId);
        
        // Search with different cases
        $lowerResults = $service->searchMessages('john');
        $upperResults = $service->searchMessages('JOHN');
        $mixedResults = $service->searchMessages('John');
        
        // All should return the same user
        $this->assertNotEmpty($lowerResults, 'Lowercase search should find results');
        $this->assertNotEmpty($upperResults, 'Uppercase search should find results');
        $this->assertNotEmpty($mixedResults, 'Mixed case search should find results');
        
        // Extract user IDs and compare
        $lowerIds = array_column($lowerResults, 'user_id');
        $upperIds = array_column($upperResults, 'user_id');
        $mixedIds = array_column($mixedResults, 'user_id');
        
        sort($lowerIds);
        sort($upperIds);
        sort($mixedIds);
        
        $this->assertEquals($lowerIds, $upperIds, 'Case should not affect search results');
        $this->assertEquals($lowerIds, $mixedIds, 'Case should not affect search results');
    }
}
