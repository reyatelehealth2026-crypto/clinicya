<?php
/**
 * Property-Based Test: SEO Meta Tags Completeness
 * 
 * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
 * 
 * Property: For any landing page render with valid shop settings, the HTML output 
 * should contain canonical URL, keywords meta, robots meta, and Open Graph tags.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/LandingSEOService.php';

class SEOMetaTagsCompletenessPropertyTest extends TestCase
{
    private $mockDb;
    
    protected function setUp(): void
    {
        // Create mock PDO that returns configurable shop settings
        $this->mockDb = $this->createMock(PDO::class);
    }
    
    /**
     * Generate random shop settings for property testing
     * 
     * @return array Array of test data sets
     */
    public function shopSettingsProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random shop configurations for property testing
        for ($i = 0; $i < 100; $i++) {
            $shopName = $this->generateRandomShopName();
            $description = $this->generateRandomDescription();
            $keywords = $this->generateRandomKeywords();
            
            $testCases["shop_config_{$i}"] = [
                $shopName,
                $description,
                $keywords
            ];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: Meta tags contain canonical URL
     * 
     * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
     * **Validates: Requirements 1.1**
     * 
     * @dataProvider shopSettingsProvider
     */
    public function testMetaTagsContainCanonicalUrl(string $shopName, string $description, string $keywords): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $keywords);
        
        $metaTags = $seoService->getMetaTags();
        
        // Canonical URL must be present
        $this->assertArrayHasKey('canonical', $metaTags, 'Meta tags must include canonical URL');
        $this->assertNotEmpty($metaTags['canonical'], 'Canonical URL must not be empty');
        
        // Canonical URL should be a valid URL format
        $this->assertMatchesRegularExpression(
            '/^https?:\/\//',
            $metaTags['canonical'],
            'Canonical URL must start with http:// or https://'
        );
    }
    
    /**
     * Property Test: Meta tags contain keywords
     * 
     * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
     * **Validates: Requirements 1.2**
     * 
     * @dataProvider shopSettingsProvider
     */
    public function testMetaTagsContainKeywords(string $shopName, string $description, string $keywords): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $keywords);
        
        $metaTags = $seoService->getMetaTags();
        
        // Keywords must be present
        $this->assertArrayHasKey('keywords', $metaTags, 'Meta tags must include keywords');
        $this->assertNotEmpty($metaTags['keywords'], 'Keywords must not be empty');
    }
    
    /**
     * Property Test: Meta tags contain robots directive
     * 
     * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
     * **Validates: Requirements 1.3**
     * 
     * @dataProvider shopSettingsProvider
     */
    public function testMetaTagsContainRobotsDirective(string $shopName, string $description, string $keywords): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $keywords);
        
        $metaTags = $seoService->getMetaTags();
        
        // Robots directive must be present
        $this->assertArrayHasKey('robots', $metaTags, 'Meta tags must include robots directive');
        $this->assertEquals('index, follow', $metaTags['robots'], 'Robots directive must be "index, follow"');
    }

    
    /**
     * Property Test: Open Graph tags are complete
     * 
     * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
     * **Validates: Requirements 1.4**
     * 
     * @dataProvider shopSettingsProvider
     */
    public function testOpenGraphTagsAreComplete(string $shopName, string $description, string $keywords): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $keywords);
        
        $ogTags = $seoService->getOpenGraphTags();
        
        // Required OG tags must be present
        $requiredOgTags = ['og:type', 'og:url', 'og:locale', 'og:site_name'];
        
        foreach ($requiredOgTags as $tag) {
            $this->assertArrayHasKey($tag, $ogTags, "Open Graph tag '{$tag}' must be present");
            $this->assertNotEmpty($ogTags[$tag], "Open Graph tag '{$tag}' must not be empty");
        }
        
        // og:type should be 'website'
        $this->assertEquals('website', $ogTags['og:type'], 'og:type must be "website"');
        
        // og:locale should be Thai
        $this->assertEquals('th_TH', $ogTags['og:locale'], 'og:locale must be "th_TH"');
    }
    
    /**
     * Property Test: Rendered meta tags HTML contains all required elements
     * 
     * **Feature: landing-page-upgrade, Property 1: SEO Meta Tags Completeness**
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
     * 
     * @dataProvider shopSettingsProvider
     */
    public function testRenderedMetaTagsContainAllElements(string $shopName, string $description, string $keywords): void
    {
        $seoService = $this->createSEOServiceWithMockData($shopName, $description, $keywords);
        
        $renderedMeta = $seoService->renderMetaTags();
        $renderedOg = $seoService->renderOpenGraphTags();
        
        // Check canonical link is present (Req 1.1)
        $this->assertStringContainsString(
            'rel="canonical"',
            $renderedMeta,
            'Rendered HTML must contain canonical link'
        );
        
        // Check keywords meta is present (Req 1.2)
        $this->assertStringContainsString(
            'name="keywords"',
            $renderedMeta,
            'Rendered HTML must contain keywords meta tag'
        );
        
        // Check robots meta is present (Req 1.3)
        $this->assertStringContainsString(
            'name="robots"',
            $renderedMeta,
            'Rendered HTML must contain robots meta tag'
        );
        
        // Check OG tags are present (Req 1.4)
        $this->assertStringContainsString(
            'property="og:type"',
            $renderedOg,
            'Rendered HTML must contain og:type'
        );
        $this->assertStringContainsString(
            'property="og:url"',
            $renderedOg,
            'Rendered HTML must contain og:url'
        );
    }
    
    /**
     * Create SEO service with mock data
     */
    private function createSEOServiceWithMockData(string $shopName, string $description, string $keywords): \LandingSEOService
    {
        // Create a mock PDO that returns our test data
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
            'contact_phone' => '0812345678',
            'shop_address' => 'Bangkok, Thailand',
            'shop_email' => 'test@example.com'
        ]);
        
        // Mock statement for landing_settings
        $landingStmt = $this->createMock(\PDOStatement::class);
        $landingStmt->method('execute')->willReturn(true);
        $landingStmt->method('fetchAll')->willReturn([
            'meta_keywords' => $keywords,
            'meta_description' => $description
        ]);
        
        // Mock statement for testimonials (aggregate rating)
        $testimonialStmt = $this->createMock(\PDOStatement::class);
        $testimonialStmt->method('execute')->willReturn(true);
        $testimonialStmt->method('fetch')->willReturn([
            'avg_rating' => 4.5,
            'review_count' => 10
        ]);
        
        // Configure prepare to return appropriate statements
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
     * Generate random keywords
     */
    private function generateRandomKeywords(): string
    {
        $allKeywords = [
            'ร้านยาออนไลน์', 'เภสัชกร', 'ส่งยาถึงบ้าน', 'ปรึกษาเภสัชกร',
            'ยา', 'สุขภาพ', 'pharmacy', 'medicine', 'health', 'drug store',
            'ร้านขายยา', 'ยาสามัญ', 'อาหารเสริม', 'วิตามิน'
        ];
        
        // Pick 3-6 random keywords
        $count = rand(3, 6);
        $selected = array_rand(array_flip($allKeywords), $count);
        
        return implode(', ', $selected);
    }
}
