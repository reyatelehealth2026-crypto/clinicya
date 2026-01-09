<?php
/**
 * Property-Based Test: Sitemap XML Validity
 * 
 * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
 * **Validates: Requirements 9.1, 9.3**
 * 
 * Property: For any sitemap generation, the output should be valid XML containing 
 * at least the landing page URL with proper lastmod date.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../classes/SitemapGenerator.php';

class SitemapXMLValidityPropertyTest extends TestCase
{
    /**
     * Generate random base URLs for property testing
     * 
     * @return array Array of test data sets
     */
    public function baseUrlProvider(): array
    {
        $testCases = [];
        
        // Generate 100 random base URL configurations for property testing
        for ($i = 0; $i < 100; $i++) {
            $baseUrl = $this->generateRandomBaseUrl();
            $testCases["base_url_{$i}"] = [$baseUrl];
        }
        
        return $testCases;
    }
    
    /**
     * Property Test: Sitemap output is valid XML
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.1**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapOutputIsValidXml(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $xml = $sitemapGenerator->generate();
        
        // Must start with XML declaration
        $this->assertStringStartsWith(
            '<?xml version="1.0" encoding="UTF-8"?>',
            $xml,
            'Sitemap must start with XML declaration'
        );
        
        // Must be valid XML
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        $this->assertNotFalse($doc, 'Sitemap must be valid XML');
        $this->assertEmpty($errors, 'Sitemap XML must have no parsing errors');
    }
    
    /**
     * Property Test: Sitemap contains urlset element
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.1**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapContainsUrlsetElement(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $xml = $sitemapGenerator->generate();
        
        // Must contain urlset element with sitemap namespace
        $this->assertStringContainsString(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            $xml,
            'Sitemap must contain urlset element with proper namespace'
        );
        
        // Must close urlset element
        $this->assertStringContainsString(
            '</urlset>',
            $xml,
            'Sitemap must close urlset element'
        );
    }

    
    /**
     * Property Test: Sitemap contains landing page URL
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.3**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapContainsLandingPageUrl(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $xml = $sitemapGenerator->generate();
        
        // Must contain landing page URL
        $expectedUrl = rtrim($baseUrl, '/') . '/';
        $this->assertStringContainsString(
            '<loc>' . htmlspecialchars($expectedUrl) . '</loc>',
            $xml,
            'Sitemap must contain landing page URL'
        );
    }
    
    /**
     * Property Test: Sitemap contains lastmod date
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.3**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapContainsLastmodDate(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $xml = $sitemapGenerator->generate();
        
        // Must contain lastmod element
        $this->assertStringContainsString(
            '<lastmod>',
            $xml,
            'Sitemap must contain lastmod element'
        );
        
        // Extract lastmod date and validate format (YYYY-MM-DD)
        preg_match('/<lastmod>(\d{4}-\d{2}-\d{2})<\/lastmod>/', $xml, $matches);
        $this->assertNotEmpty($matches, 'Sitemap must contain valid lastmod date');
        
        // Validate date format
        $date = $matches[1];
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $date,
            'Lastmod date must be in YYYY-MM-DD format'
        );
        
        // Validate it's a real date
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
        $this->assertNotFalse($dateTime, 'Lastmod must be a valid date');
    }
    
    /**
     * Property Test: Sitemap URLs array contains at least landing page
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.3**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapUrlsContainsLandingPage(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $urls = $sitemapGenerator->getUrls();
        
        // Must have at least one URL (landing page)
        $this->assertNotEmpty($urls, 'Sitemap must contain at least one URL');
        
        // First URL should be landing page
        $this->assertArrayHasKey('loc', $urls[0], 'URL entry must have loc');
        $this->assertArrayHasKey('lastmod', $urls[0], 'URL entry must have lastmod');
        
        // Landing page URL should end with /
        $expectedUrl = rtrim($baseUrl, '/') . '/';
        $this->assertEquals($expectedUrl, $urls[0]['loc'], 'First URL must be landing page');
    }
    
    /**
     * Property Test: Sitemap isValid method returns true for valid sitemap
     * 
     * **Feature: landing-page-upgrade, Property 10: Sitemap XML Validity**
     * **Validates: Requirements 9.1**
     * 
     * @dataProvider baseUrlProvider
     */
    public function testSitemapIsValidMethodReturnsTrue(string $baseUrl): void
    {
        $sitemapGenerator = $this->createSitemapGeneratorWithMockData($baseUrl);
        
        $this->assertTrue(
            $sitemapGenerator->isValid(),
            'Sitemap isValid() must return true for valid sitemap'
        );
    }
    
    /**
     * Create SitemapGenerator with mock data
     */
    private function createSitemapGeneratorWithMockData(string $baseUrl): \SitemapGenerator
    {
        $mockPdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // Mock statement for products query
        $productStmt = $this->createMock(\PDOStatement::class);
        $productStmt->method('execute')->willReturn(true);
        $productStmt->method('fetchAll')->willReturn([]);
        
        // Mock statement for FAQ query
        $faqStmt = $this->createMock(\PDOStatement::class);
        $faqStmt->method('fetchColumn')->willReturn(null);
        
        // Mock statement for testimonials query
        $testimonialStmt = $this->createMock(\PDOStatement::class);
        $testimonialStmt->method('fetchColumn')->willReturn(null);
        
        $mockPdo->method('prepare')
            ->willReturnCallback(function($sql) use ($productStmt) {
                return $productStmt;
            });
        
        $mockPdo->method('query')
            ->willReturnCallback(function($sql) use ($faqStmt, $testimonialStmt) {
                if (strpos($sql, 'landing_faqs') !== false) {
                    return $faqStmt;
                }
                return $testimonialStmt;
            });
        
        return new \SitemapGenerator($mockPdo, $baseUrl, null);
    }
    
    /**
     * Generate a random base URL
     */
    private function generateRandomBaseUrl(): string
    {
        $protocols = ['https', 'http'];
        $domains = [
            'example.com',
            'pharmacy.co.th',
            'drugstore.com',
            'health-shop.net',
            'medicine-online.org',
            'ร้านยา.th',
            'localhost',
            '192.168.1.100'
        ];
        $paths = ['', '/shop', '/pharmacy', '/store'];
        
        $protocol = $protocols[array_rand($protocols)];
        $domain = $domains[array_rand($domains)];
        $path = $paths[array_rand($paths)];
        
        return "{$protocol}://{$domain}{$path}";
    }
}
