<?php
/**
 * Property-Based Test: Template Placeholder Replacement
 * 
 * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
 * **Validates: Requirements 2.3**
 * 
 * Property: For any template string containing placeholders like {name}, {phone}, {email} 
 * and any customer data object, calling fillPlaceholders should replace all placeholders 
 * with corresponding customer values, and no placeholder syntax should remain in the output.
 */

namespace Tests\InboxChat;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/TemplateService.php';

class TemplatePlaceholderReplacementPropertyTest extends TestCase
{
    private $pdo;
    private $service;
    private $lineAccountId = 1;
    
    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create the quick_reply_templates table (required by TemplateService)
        $this->pdo->exec("
            CREATE TABLE quick_reply_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL,
                name VARCHAR(100) NOT NULL,
                content TEXT NOT NULL,
                category VARCHAR(50) DEFAULT '',
                usage_count INTEGER DEFAULT 0,
                last_used_at DATETIME NULL,
                created_by INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $this->service = new \TemplateService($this->pdo, $this->lineAccountId);
    }
    
    protected function tearDown(): void
    {
        $this->pdo = null;
        $this->service = null;
    }
    
    /**
     * Generate random test data for property testing
     * 
     * @return array Array of test data sets [template, customerData]
     */
    public function placeholderDataProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random template and customer data combinations
        for ($i = 0; $i < 100; $i++) {
            $template = $this->generateRandomTemplate();
            $customerData = $this->generateRandomCustomerData();
            
            $testCases["placeholder_test_{$i}"] = [
                $template,
                $customerData
            ];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: All placeholders are replaced with customer data
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     * 
     * @dataProvider placeholderDataProvider
     */
    public function testAllPlaceholdersAreReplaced(string $template, array $customerData): void
    {
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // Verify no placeholder syntax remains in output
        $this->assertStringNotContainsString('{name}', $result, 'No {name} placeholder should remain');
        $this->assertStringNotContainsString('{phone}', $result, 'No {phone} placeholder should remain');
        $this->assertStringNotContainsString('{email}', $result, 'No {email} placeholder should remain');
        $this->assertStringNotContainsString('{order_id}', $result, 'No {order_id} placeholder should remain');
    }
    
    /**
     * Property Test: Placeholders are replaced with correct values
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     * 
     * @dataProvider placeholderDataProvider
     */
    public function testPlaceholdersReplacedWithCorrectValues(string $template, array $customerData): void
    {
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // Always assert that result is a string (baseline assertion)
        $this->assertIsString($result, 'Result should always be a string');
        
        // If template contained {name} and customer has name, result should contain the name
        if (strpos($template, '{name}') !== false && !empty($customerData['name'])) {
            $this->assertStringContainsString(
                $customerData['name'],
                $result,
                'Result should contain customer name when template has {name} placeholder'
            );
        }
        
        // If template contained {phone} and customer has phone, result should contain the phone
        if (strpos($template, '{phone}') !== false && !empty($customerData['phone'])) {
            $this->assertStringContainsString(
                $customerData['phone'],
                $result,
                'Result should contain customer phone when template has {phone} placeholder'
            );
        }
        
        // If template contained {email} and customer has email, result should contain the email
        if (strpos($template, '{email}') !== false && !empty($customerData['email'])) {
            $this->assertStringContainsString(
                $customerData['email'],
                $result,
                'Result should contain customer email when template has {email} placeholder'
            );
        }
        
        // If template contained {order_id} and customer has order_id, result should contain the order_id
        if (strpos($template, '{order_id}') !== false && !empty($customerData['order_id'])) {
            $this->assertStringContainsString(
                $customerData['order_id'],
                $result,
                'Result should contain order_id when template has {order_id} placeholder'
            );
        }
    }
    
    /**
     * Property Test: Non-placeholder text is preserved
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     * 
     * @dataProvider placeholderDataProvider
     */
    public function testNonPlaceholderTextPreserved(string $template, array $customerData): void
    {
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // Extract static parts of template (parts without placeholders)
        $staticParts = preg_split('/\{(name|phone|email|order_id)\}/', $template);
        
        // Each static part should be preserved in the result
        foreach ($staticParts as $part) {
            if (!empty($part)) {
                $this->assertStringContainsString(
                    $part,
                    $result,
                    "Static text '$part' should be preserved in result"
                );
            }
        }
    }
    
    /**
     * Property Test: Result length is correct after replacement
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     * 
     * @dataProvider placeholderDataProvider
     */
    public function testResultLengthIsCorrect(string $template, array $customerData): void
    {
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // Calculate expected length
        $expectedLength = strlen($template);
        
        // Adjust for each placeholder replacement
        $placeholders = [
            '{name}' => $customerData['name'] ?? '',
            '{phone}' => $customerData['phone'] ?? '',
            '{email}' => $customerData['email'] ?? '',
            '{order_id}' => $customerData['order_id'] ?? ''
        ];
        
        foreach ($placeholders as $placeholder => $value) {
            $count = substr_count($template, $placeholder);
            $expectedLength -= $count * strlen($placeholder);
            $expectedLength += $count * strlen($value);
        }
        
        $this->assertEquals(
            $expectedLength,
            strlen($result),
            'Result length should match expected length after placeholder replacement'
        );
    }
    
    /**
     * Property Test: Empty customer data results in empty placeholder values
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     */
    public function testEmptyCustomerDataReplacesWithEmpty(): void
    {
        $template = 'Hello {name}, your phone is {phone}, email is {email}, order #{order_id}';
        $customerData = []; // Empty data
        
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // All placeholders should be replaced with empty strings
        $this->assertStringNotContainsString('{name}', $result);
        $this->assertStringNotContainsString('{phone}', $result);
        $this->assertStringNotContainsString('{email}', $result);
        $this->assertStringNotContainsString('{order_id}', $result);
        
        // Result should be the template with placeholders removed
        $this->assertEquals('Hello , your phone is , email is , order #', $result);
    }
    
    /**
     * Property Test: Multiple occurrences of same placeholder are all replaced
     * 
     * **Feature: inbox-chat-upgrade, Property 1: Template Placeholder Replacement**
     * **Validates: Requirements 2.3**
     */
    public function testMultiplePlaceholderOccurrencesReplaced(): void
    {
        $template = 'Dear {name}, we confirm {name} has ordered. Contact {name} at {phone} or {phone}.';
        $customerData = [
            'name' => 'John Doe',
            'phone' => '0812345678'
        ];
        
        $result = $this->service->fillPlaceholders($template, $customerData);
        
        // Count occurrences of replaced values
        $nameCount = substr_count($result, 'John Doe');
        $phoneCount = substr_count($result, '0812345678');
        
        $this->assertEquals(3, $nameCount, 'All 3 {name} placeholders should be replaced');
        $this->assertEquals(2, $phoneCount, 'All 2 {phone} placeholders should be replaced');
        
        // No placeholders should remain
        $this->assertStringNotContainsString('{name}', $result);
        $this->assertStringNotContainsString('{phone}', $result);
    }
    
    /**
     * Generate a random template with various placeholder combinations
     */
    private function generateRandomTemplate(): string
    {
        $templates = [
            // Thai templates
            'สวัสดีค่ะ คุณ{name} ยินดีต้อนรับสู่ร้านของเรา',
            'ขอบคุณสำหรับการสั่งซื้อ Order #{order_id}',
            'สินค้าของคุณกำลังจัดส่ง กรุณาติดต่อ {phone}',
            'คุณ{name} สามารถติดต่อเราได้ที่ {email}',
            'ยืนยันคำสั่งซื้อ #{order_id} สำหรับคุณ{name}',
            'กรุณาติดต่อ {phone} หรือ {email} หากมีข้อสงสัย',
            
            // English templates
            'Hello {name}, thank you for your order!',
            'Your order #{order_id} has been confirmed.',
            'Please contact us at {email} for any questions.',
            'Dear {name}, your prescription is ready for pickup.',
            'Contact {name} at {phone} or {email}',
            'Order #{order_id} for {name} is being processed.',
            
            // Mixed templates
            'สวัสดี {name}! Order #{order_id} confirmed.',
            '{name} - {phone} - {email} - #{order_id}',
            'Thank you {name}! ติดต่อ {phone}',
            
            // Templates with no placeholders
            'สวัสดีค่ะ มีอะไรให้ช่วยเหลือไหมคะ',
            'Thank you for contacting us.',
            
            // Templates with all placeholders
            'Dear {name}, Order #{order_id}, Phone: {phone}, Email: {email}',
            
            // Templates with repeated placeholders
            '{name} {name} {name}',
            'Call {phone} or {phone}',
        ];
        
        return $templates[array_rand($templates)];
    }
    
    /**
     * Generate random customer data
     */
    private function generateRandomCustomerData(): array
    {
        $names = ['สมชาย', 'สมหญิง', 'John Doe', 'Jane Smith', 'ประยุทธ์', 'Maria Garcia', ''];
        $phones = ['0812345678', '0891234567', '0923456789', '0634567890', ''];
        $emails = ['test@example.com', 'user@pharmacy.com', 'customer@shop.co.th', ''];
        $orderIds = ['ORD001', 'ORD12345', '2024-001', 'PO-789', ''];
        
        // Randomly decide which fields to include
        $data = [];
        
        if (rand(0, 10) > 2) { // 80% chance to include name
            $data['name'] = $names[array_rand($names)];
        }
        
        if (rand(0, 10) > 2) { // 80% chance to include phone
            $data['phone'] = $phones[array_rand($phones)];
        }
        
        if (rand(0, 10) > 3) { // 70% chance to include email
            $data['email'] = $emails[array_rand($emails)];
        }
        
        if (rand(0, 10) > 3) { // 70% chance to include order_id
            $data['order_id'] = $orderIds[array_rand($orderIds)];
        }
        
        return $data;
    }
}
