<?php
/**
 * SitemapGenerator - สร้าง XML Sitemap สำหรับ SEO
 * 
 * Requirements: 9.1, 9.3, 9.5
 */

class SitemapGenerator {
    private $db;
    private $baseUrl;
    private $lineAccountId;
    
    public function __construct(PDO $db, string $baseUrl, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Generate complete XML sitemap
     * Requirements: 9.1 - Serve a valid XML sitemap
     * 
     * @return string XML sitemap content
     */
    public function generate(): string {
        $urls = $this->getUrls();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($urls as $url) {
            $xml .= $this->buildUrlEntry($url);
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Build a single URL entry for sitemap
     * 
     * @param array $url URL data
     * @return string XML url entry
     */
    private function buildUrlEntry(array $url): string {
        $entry = "  <url>\n";
        $entry .= "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
        
        if (!empty($url['lastmod'])) {
            $entry .= "    <lastmod>" . htmlspecialchars($url['lastmod']) . "</lastmod>\n";
        }
        
        if (!empty($url['changefreq'])) {
            $entry .= "    <changefreq>" . htmlspecialchars($url['changefreq']) . "</changefreq>\n";
        }
        
        if (isset($url['priority'])) {
            $entry .= "    <priority>" . htmlspecialchars($url['priority']) . "</priority>\n";
        }
        
        $entry .= "  </url>\n";
        
        return $entry;
    }

    
    /**
     * Get all URLs for sitemap
     * Requirements: 9.3, 9.5 - Include landing page and product pages
     * 
     * @return array Array of URL entries
     */
    public function getUrls(): array {
        $urls = [];
        
        // Add landing page (Requirements: 9.3)
        $urls[] = [
            'loc' => $this->baseUrl . '/',
            'lastmod' => $this->getLastModified(),
            'changefreq' => 'weekly',
            'priority' => '1.0'
        ];
        
        // Add static pages
        $staticPages = [
            '/privacy-policy.php' => ['changefreq' => 'monthly', 'priority' => '0.3'],
            '/terms-of-service.php' => ['changefreq' => 'monthly', 'priority' => '0.3']
        ];
        
        foreach ($staticPages as $page => $options) {
            if (file_exists(dirname(__DIR__) . $page)) {
                $urls[] = [
                    'loc' => $this->baseUrl . $page,
                    'lastmod' => date('Y-m-d', filemtime(dirname(__DIR__) . $page)),
                    'changefreq' => $options['changefreq'],
                    'priority' => $options['priority']
                ];
            }
        }
        
        // Add public product pages (Requirements: 9.5)
        $productUrls = $this->getProductUrls();
        $urls = array_merge($urls, $productUrls);
        
        return $urls;
    }
    
    /**
     * Get product URLs for sitemap
     * Requirements: 9.5 - Include product pages if public products exist
     * 
     * @return array Array of product URL entries
     */
    private function getProductUrls(): array {
        $urls = [];
        
        try {
            $sql = "SELECT id, name, updated_at FROM products WHERE is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $sql .= " ORDER BY id ASC LIMIT 1000"; // Limit to prevent huge sitemaps
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($products as $product) {
                $lastmod = !empty($product['updated_at']) 
                    ? date('Y-m-d', strtotime($product['updated_at'])) 
                    : date('Y-m-d');
                    
                $urls[] = [
                    'loc' => $this->baseUrl . '/liff-product-detail.php?id=' . $product['id'],
                    'lastmod' => $lastmod,
                    'changefreq' => 'weekly',
                    'priority' => '0.7'
                ];
            }
        } catch (Exception $e) {
            // Products table might not exist or have different structure
        }
        
        return $urls;
    }

    
    /**
     * Get last modified date for landing page
     * Requirements: 9.3 - Include lastmod date
     * 
     * @return string Date in Y-m-d format
     */
    public function getLastModified(): string {
        // Try to get the most recent update from various sources
        $dates = [];
        
        // Check index.php modification time
        $indexPath = dirname(__DIR__) . '/index.php';
        if (file_exists($indexPath)) {
            $dates[] = filemtime($indexPath);
        }
        
        // Check latest product update
        try {
            $sql = "SELECT MAX(updated_at) FROM products WHERE is_active = 1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $lastProduct = $stmt->fetchColumn();
            
            if ($lastProduct) {
                $dates[] = strtotime($lastProduct);
            }
        } catch (Exception $e) {}
        
        // Check latest FAQ update
        try {
            $stmt = $this->db->query("SELECT MAX(updated_at) FROM landing_faqs WHERE is_active = 1");
            $lastFaq = $stmt->fetchColumn();
            if ($lastFaq) {
                $dates[] = strtotime($lastFaq);
            }
        } catch (Exception $e) {}
        
        // Check latest testimonial update
        try {
            $stmt = $this->db->query("SELECT MAX(approved_at) FROM landing_testimonials WHERE status = 'approved'");
            $lastTestimonial = $stmt->fetchColumn();
            if ($lastTestimonial) {
                $dates[] = strtotime($lastTestimonial);
            }
        } catch (Exception $e) {}
        
        // Return the most recent date, or today if none found
        if (!empty($dates)) {
            return date('Y-m-d', max($dates));
        }
        
        return date('Y-m-d');
    }
    
    /**
     * Get URL count in sitemap
     * 
     * @return int
     */
    public function getUrlCount(): int {
        return count($this->getUrls());
    }
    
    /**
     * Output sitemap with proper headers
     */
    public function output(): void {
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->generate();
    }
    
    /**
     * Validate that generated XML is well-formed
     * 
     * @return bool
     */
    public function isValid(): bool {
        $xml = $this->generate();
        
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        return $doc !== false && empty($errors);
    }
    
    /**
     * Get base URL
     * 
     * @return string
     */
    public function getBaseUrl(): string {
        return $this->baseUrl;
    }
}
