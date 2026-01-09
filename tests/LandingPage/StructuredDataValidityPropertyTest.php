<?php
/**
 * Property-Based Test: Structured Data Validity
 * 
 * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
 * **Validates: Requirements 2.1, 2.2**
 * 
 * Property: For any landing page render with shop data, the JSON-LD output should be 
 * valid JSON containing @type "Pharmacy" with name, description, and telephone fields.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/LandingSEOService.php';

class StructuredDataValidityPropertyTest extends TestCase
{
    /**
     * Generate random shop data for property testing
     * 
     * @return array Array of test data sets
     */
    public function shopDataProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random shop configurations for property testing
        for ($i = 0; $i < 100; $i++) {
            $shopName = $this->generateRandomShopName();
            $description = $this->generateRandomDescription();
            $phone = $this->generateRandomPhone();
            $address = $this->generateRandomAddress();
            
            $testCases["shop_data_{$i}"] = [
                $shopName,
                $description,
                $phone,
                $address
            ];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: Structured data is valid JSON
     * 
     * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
     * **Validates: Requirements 2.1**
     * 
     * @dataProvider shopDataProvider
     */
    public function testStructuredDataIsValidJson(string $shopName, string $description, string $phone, string $address): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $phone, $address);
        
        $structuredData = $seoService->getStructuredData();
        
        // Must be a valid array (which can be encoded to JSON)
        $this->assertIsArray($structuredData, 'Structured data must be an array');
        
        // Must be encodable to valid JSON
        $json = json_encode($structuredData, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'Structured data must be encodable to JSON');
        
        // Must be decodable back to array
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'JSON must be decodable back to array');
    }
    
    /**
     * Property Test: Structured data contains @type "Pharmacy"
     * 
     * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
     * **Validates: Requirements 2.1**
     * 
     * @dataProvider shopDataProvider
     */
    public function testStructuredDataContainsPharmacyType(string $shopName, string $description, string $phone, string $address): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $phone, $address);
        
        $structuredData = $seoService->getStructuredData();
        
        // Must contain @context
        $this->assertArrayHasKey('@context', $structuredData, 'Structured data must contain @context');
        $this->assertEquals('https://schema.org', $structuredData['@context'], '@context must be schema.org');
        
        // Must contain @type "Pharmacy"
        $this->assertArrayHasKey('@type', $structuredData, 'Structured data must contain @type');
        $this->assertEquals('Pharmacy', $structuredData['@type'], '@type must be "Pharmacy"');
    }

    
    /**
     * Property Test: Structured data contains required fields
     * 
     * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
     * **Validates: Requirements 2.2**
     * 
     * @dataProvider shopDataProvider
     */
    public function testStructuredDataContainsRequiredFields(string $shopName, string $description, string $phone, string $address): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $phone, $address);
        
        $structuredData = $seoService->getStructuredData();
        
        // Must contain name
        $this->assertArrayHasKey('name', $structuredData, 'Structured data must contain name');
        $this->assertNotEmpty($structuredData['name'], 'Name must not be empty');
        
        // Must contain description
        $this->assertArrayHasKey('description', $structuredData, 'Structured data must contain description');
        $this->assertNotEmpty($structuredData['description'], 'Description must not be empty');
        
        // Must contain telephone (when provided)
        if (!empty($phone)) {
            $this->assertArrayHasKey('telephone', $structuredData, 'Structured data must contain telephone when phone is provided');
        }
    }
    
    /**
     * Property Test: Structured data name matches shop name
     * 
     * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
     * **Validates: Requirements 2.2**
     * 
     * @dataProvider shopDataProvider
     */
    public function testStructuredDataNameMatchesShopName(string $shopName, string $description, string $phone, string $address): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $phone, $address);
        
        $structuredData = $seoService->getStructuredData();
        
        // Name in structured data must match the shop name
        $this->assertEquals(
            $shopName,
            $structuredData['name'],
            'Structured data name must match shop name'
        );
    }
    
    /**
     * Property Test: Rendered structured data is valid JSON-LD script
     * 
     * **Feature: landing-page-upgrade, Property 2: Structured Data Validity**
     * **Validates: Requirements 2.1, 2.2**
     * 
     * @dataProvider shopDataProvider
     */
    public function testRenderedStructuredDataIsValidJsonLd(string $shopName, string $description, string $phone, string $address): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $phone, $address);
        
        $rendered = $seoService->renderStructuredData();
        
        // Must contain script tag with type application/ld+json
        $this->assertStringContainsString(
            '<script type="application/ld+json">',
            $rendered,
            'Rendered structured data must be in script tag with type application/ld+json'
        );
        
        // Extract JSON from script tag
        preg_match('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $rendered, $matches);
        $this->assertNotEmpty($matches[1], 'Script tag must contain JSON content');
        
        // JSON must be valid
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'JSON-LD content must be valid JSON');
        
        // Must contain @type Pharmacy
        $this->assertEquals('Pharmacy', $decoded['@type'], 'JSON-LD must have @type Pharmacy');
    }
    
    /**
     * Create SEO service with mock data
     */
    private function createSEOServiceWithMockData(string $shopName, string $description, string $phone, string $address): \LandingSEOService
    {
        $mockPdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // Mock statement for shop_settings
        $shopStmt = $this->createMock(\PDOStatement::class);
        $shopStmt->method('execute')->willReturn(true);
        $shopStmt->method('fetch')->willReturn([
            'shop_name' => $shopName,
            'welcome_message' => $description,
            'shop_logo' => '',
            'contact_phone' => $phone,
            'shop_address' => $address,
            'shop_email' => 'test@example.com'
        ]);
        
        // Mock statement for landing_settings
        $landingStmt = $this->createMock(\PDOStatement::class);
        $landingStmt->method('execute')->willReturn(true);
        $landingStmt->method('fetchAll')->willReturn([
            'meta_description' => $description
        ]);
        
        // Mock statement for testimonials
        $testimonialStmt = $this->createMock(\PDOStatement::class);
        $testimonialStmt->method('execute')->willReturn(true);
        $testimonialStmt->method('fetch')->willReturn([
            'avg_rating' => 4.5,
            'review_count' => 10
        ]);
        
        $mockPdo->method('prepare')
            ->willReturnCallback(function($sql) use ($shopStmt, $landingStmt, $testimonialStmt) {
                if (strpos($sql, 'shop_settings') !== false) {
                    return $shopStmt;
                } elseif (strpos($sql, 'landing_settings') !== false) {
                    return $landingStmt;
                } elseif (strpos($sql, 'landing_testimonials') !== false) {
                    return $testimonialStmt;
                }
                return $shopStmt;
            });
        
        return new \LandingSEOService($mockPdo, null);
    }
    
    /**
     * Generate a random shop name
     */
    private function generateRandomShopName(): string
    {
        $prefixes = ['ร้านยา', 'เภสัชกรรม', 'Pharmacy', 'Drug Store', 'Health', 'Care'];
        $suffixes = ['สุขภาพดี', 'ใจดี', 'Plus', 'Pro', 'Center', '24', 'Express'];
        $names = ['ABC', 'XYZ', 'สมชาย', 'สมหญิง', 'Golden', 'Silver', 'Green', 'Blue'];
        
        return $prefixes[array_rand($prefixes)] . ' ' . $names[array_rand($names)] . ' ' . $suffixes[array_rand($suffixes)];
    }
    
    /**
     * Generate a random description
     */
    private function generateRandomDescription(): string
    {
        $descriptions = [
            'ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร',
            'Your trusted online pharmacy with professional consultation',
            'บริการด้านสุขภาพครบวงจร ส่งถึงบ้าน',
            'Quality medicines delivered to your door',
            'เภสัชกรพร้อมให้คำปรึกษา 24 ชั่วโมง',
        ];
        
        return $descriptions[array_rand($descriptions)];
    }
    
    /**
     * Generate a random phone number
     */
    private function generateRandomPhone(): string
    {
        $prefixes = ['02', '081', '082', '083', '084', '085', '086', '087', '088', '089', '091', '092', '093', '094', '095', '096', '097', '098', '099'];
        $prefix = $prefixes[array_rand($prefixes)];
        
        if ($prefix === '02') {
            return $prefix . '-' . rand(100, 999) . '-' . rand(1000, 9999);
        }
        
        return $prefix . '-' . rand(100, 999) . '-' . rand(1000, 9999);
    }
    
    /**
     * Generate a random address
     */
    private function generateRandomAddress(): string
    {
        $streets = ['ถนนสุขุมวิท', 'ถนนพหลโยธิน', 'ถนนรัชดาภิเษก', 'ถนนลาดพร้าว', 'Sukhumvit Road', 'Silom Road'];
        $districts = ['วัฒนา', 'จตุจักร', 'ห้วยขวาง', 'บางรัก', 'Watthana', 'Chatuchak'];
        $provinces = ['กรุงเทพมหานคร', 'Bangkok', 'นนทบุรี', 'Nonthaburi'];
        
        $number = rand(1, 999);
        $street = $streets[array_rand($streets)];
        $district = $districts[array_rand($districts)];
        $province = $provinces[array_rand($provinces)];
        
        return "{$number} {$street}, {$district}, {$province}";
    }
}
