<?php
/**
 * Property-Based Test: Template Round-Trip Consistency
 * 
 * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
 * **Validates: Requirements 2.4**
 * 
 * Property: For any valid template data (name, content, category), creating a template 
 * and then retrieving it should return equivalent data.
 */

namespace Tests\InboxChat;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

require_once __DIR__ . '/../../classes/TemplateService.php';

class TemplateRoundTripPropertyTest extends TestCase
{
    private $pdo;
    private $lineAccountId = 1;
    
    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create the quick_reply_templates table
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
    }
    
    protected function tearDown(): void
    {
        $this->pdo = null;
    }
    
    /**
     * Generate random template data for property testing
     * 
     * @return array Array of test data sets
     */
    public function templateDataProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random template configurations for property testing
        for ($i = 0; $i < 100; $i++) {
            $name = $this->generateRandomTemplateName();
            $content = $this->generateRandomTemplateContent();
            $category = $this->generateRandomCategory();
            
            $testCases["template_{$i}"] = [
                $name,
                $content,
                $category
            ];
        }
        
        return $testCases;
    }

    
    /**
     * Property Test: Template round-trip preserves name
     * 
     * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
     * **Validates: Requirements 2.4**
     * 
     * @dataProvider templateDataProvider
     */
    public function testTemplateRoundTripPreservesName(string $name, string $content, string $category): void
    {
        $service = new \TemplateService($this->pdo, $this->lineAccountId);
        
        // Create template
        $templateId = $service->createTemplate($name, $content, $category);
        
        // Retrieve template
        $retrieved = $service->getById($templateId);
        
        // Verify name is preserved
        $this->assertNotNull($retrieved, 'Retrieved template should not be null');
        $this->assertEquals(
            trim($name),
            $retrieved['name'],
            'Template name should be preserved after round-trip'
        );
    }
    
    /**
     * Property Test: Template round-trip preserves content
     * 
     * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
     * **Validates: Requirements 2.4**
     * 
     * @dataProvider templateDataProvider
     */
    public function testTemplateRoundTripPreservesContent(string $name, string $content, string $category): void
    {
        $service = new \TemplateService($this->pdo, $this->lineAccountId);
        
        // Create template
        $templateId = $service->createTemplate($name, $content, $category);
        
        // Retrieve template
        $retrieved = $service->getById($templateId);
        
        // Verify content is preserved
        $this->assertNotNull($retrieved, 'Retrieved template should not be null');
        $this->assertEquals(
            trim($content),
            $retrieved['content'],
            'Template content should be preserved after round-trip'
        );
    }
    
    /**
     * Property Test: Template round-trip preserves category
     * 
     * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
     * **Validates: Requirements 2.4**
     * 
     * @dataProvider templateDataProvider
     */
    public function testTemplateRoundTripPreservesCategory(string $name, string $content, string $category): void
    {
        $service = new \TemplateService($this->pdo, $this->lineAccountId);
        
        // Create template
        $templateId = $service->createTemplate($name, $content, $category);
        
        // Retrieve template
        $retrieved = $service->getById($templateId);
        
        // Verify category is preserved
        $this->assertNotNull($retrieved, 'Retrieved template should not be null');
        $this->assertEquals(
            trim($category),
            $retrieved['category'],
            'Template category should be preserved after round-trip'
        );
    }

    
    /**
     * Property Test: Template round-trip preserves all fields together
     * 
     * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
     * **Validates: Requirements 2.4**
     * 
     * @dataProvider templateDataProvider
     */
    public function testTemplateRoundTripPreservesAllFields(string $name, string $content, string $category): void
    {
        $service = new \TemplateService($this->pdo, $this->lineAccountId);
        
        // Create template
        $templateId = $service->createTemplate($name, $content, $category);
        
        // Retrieve template
        $retrieved = $service->getById($templateId);
        
        // Verify all fields are preserved
        $this->assertNotNull($retrieved, 'Retrieved template should not be null');
        $this->assertEquals(trim($name), $retrieved['name'], 'Name should be preserved');
        $this->assertEquals(trim($content), $retrieved['content'], 'Content should be preserved');
        $this->assertEquals(trim($category), $retrieved['category'], 'Category should be preserved');
        $this->assertEquals($this->lineAccountId, $retrieved['line_account_id'], 'Line account ID should be preserved');
    }
    
    /**
     * Property Test: Template retrieved via getTemplates matches created data
     * 
     * **Feature: inbox-chat-upgrade, Property 2: Template Round-Trip Consistency**
     * **Validates: Requirements 2.4**
     * 
     * @dataProvider templateDataProvider
     */
    public function testTemplateRoundTripViaGetTemplates(string $name, string $content, string $category): void
    {
        $service = new \TemplateService($this->pdo, $this->lineAccountId);
        
        // Create template
        $templateId = $service->createTemplate($name, $content, $category);
        
        // Retrieve all templates
        $templates = $service->getTemplates();
        
        // Find our template
        $found = null;
        foreach ($templates as $template) {
            if ($template['id'] == $templateId) {
                $found = $template;
                break;
            }
        }
        
        // Verify template is found and data matches
        $this->assertNotNull($found, 'Template should be found in getTemplates result');
        $this->assertEquals(trim($name), $found['name'], 'Name should match');
        $this->assertEquals(trim($content), $found['content'], 'Content should match');
        $this->assertEquals(trim($category), $found['category'], 'Category should match');
    }
    
    /**
     * Generate a random template name
     */
    private function generateRandomTemplateName(): string
    {
        $prefixes = ['ทักทาย', 'ขอบคุณ', 'ยืนยัน', 'แจ้งเตือน', 'Welcome', 'Thanks', 'Confirm', 'Order'];
        $suffixes = ['ลูกค้าใหม่', 'สั่งซื้อ', 'จัดส่ง', 'ชำระเงิน', 'New', 'Shipping', 'Payment', 'Complete'];
        $numbers = ['', ' 1', ' 2', ' V2', ' Pro'];
        
        return $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)] . $numbers[array_rand($numbers)];
    }
    
    /**
     * Generate random template content with optional placeholders
     */
    private function generateRandomTemplateContent(): string
    {
        $templates = [
            'สวัสดีค่ะ คุณ{name} ยินดีต้อนรับสู่ร้านของเรา',
            'ขอบคุณสำหรับการสั่งซื้อ Order #{order_id}',
            'สินค้าของคุณกำลังจัดส่ง กรุณาติดต่อ {phone}',
            'Hello {name}, thank you for your order!',
            'Your order #{order_id} has been confirmed.',
            'Please contact us at {email} for any questions.',
            'ขอบคุณที่ใช้บริการค่ะ หากมีข้อสงสัยติดต่อ {phone}',
            'Dear {name}, your prescription is ready for pickup.',
            'สวัสดีค่ะ มีอะไรให้ช่วยเหลือไหมคะ',
            'Thank you for contacting us. We will respond shortly.',
        ];
        
        return $templates[array_rand($templates)];
    }
    
    /**
     * Generate a random category
     */
    private function generateRandomCategory(): string
    {
        $categories = [
            'greeting',
            'order',
            'shipping',
            'payment',
            'support',
            'promotion',
            'ทักทาย',
            'คำสั่งซื้อ',
            'จัดส่ง',
            ''  // Empty category is valid
        ];
        
        return $categories[array_rand($categories)];
    }
}
