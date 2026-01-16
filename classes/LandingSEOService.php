<?php
/**
 * LandingSEOService - จัดการ SEO Meta Tags และ Structured Data สำหรับ Landing Page
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4
 */

class LandingSEOService {
    private $db;
    private $lineAccountId;
    private $shopSettings;
    private $landingSettings;
    private $baseUrl;
    
    public function __construct(PDO $db, ?int $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        $this->loadShopSettings();
        $this->loadLandingSettings();
    }
    
    /**
     * Load shop settings from database
     */
    private function loadShopSettings(): void {
        try {
            $sql = "SELECT * FROM shop_settings WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            } else {
                $sql .= " AND line_account_id IS NULL";
            }
            
            $sql .= " ORDER BY line_account_id DESC LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $this->shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $this->shopSettings = [];
        }
    }
    
    /**
     * Load landing page settings from database
     */
    private function loadLandingSettings(): void {
        try {
            $sql = "SELECT setting_key, setting_value FROM landing_settings WHERE 1=1";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            } else {
                $sql .= " AND line_account_id IS NULL";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->landingSettings = $results ?: [];
        } catch (Exception $e) {
            $this->landingSettings = [];
        }
    }
    
    /**
     * Get landing setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getLandingSetting(string $key, $default = null) {
        return $this->landingSettings[$key] ?? $default;
    }
    
    /**
     * Get shop setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getShopSetting(string $key, $default = null) {
        return $this->shopSettings[$key] ?? $default;
    }

    
    /**
     * Get all SEO meta tags
     * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
     * 
     * @return array Array of meta tags
     */
    public function getMetaTags(): array {
        $pageTitle = $this->getLandingSetting('page_title', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
        $description = $this->getLandingSetting('meta_description', 
            $this->getShopSetting('welcome_message', 'ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร'));
        $keywords = $this->getLandingSetting('meta_keywords', 
            'ร้านยาออนไลน์, เภสัชกร, ส่งยาถึงบ้าน, ปรึกษาเภสัชกร, ยา, สุขภาพ');
        
        return [
            'title' => $pageTitle,
            'description' => $description,
            'keywords' => $keywords,
            'robots' => 'index, follow',
            'author' => $pageTitle,
            'canonical' => $this->getCanonicalUrl()
        ];
    }
    
    /**
     * Get canonical URL
     * Requirements: 1.1 - Include canonical URL meta tag
     * 
     * @return string
     */
    public function getCanonicalUrl(): string {
        return $this->baseUrl ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    
    /**
     * Get Open Graph tags
     * Requirements: 1.4 - Include complete Open_Graph tags
     * 
     * @return array
     */
    public function getOpenGraphTags(): array {
        $pageTitle = $this->getLandingSetting('page_title', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
        $description = $this->getLandingSetting('meta_description', 
            $this->getShopSetting('welcome_message', 'ร้านยาออนไลน์ครบวงจร'));
        $shopLogo = $this->getShopSetting('shop_logo', '');
        
        $tags = [
            'og:type' => 'website',
            'og:url' => $this->getCanonicalUrl(),
            'og:title' => $pageTitle,
            'og:description' => $description,
            'og:locale' => 'th_TH',
            'og:site_name' => $pageTitle
        ];
        
        if (!empty($shopLogo)) {
            $imageUrl = $shopLogo;
            // Make absolute URL if relative
            if (strpos($imageUrl, 'http') !== 0) {
                $imageUrl = $this->baseUrl . '/' . ltrim($imageUrl, '/');
            }
            $tags['og:image'] = $imageUrl;
            $tags['og:image:alt'] = $pageTitle;
        }
        
        return $tags;
    }
    
    /**
     * Get Twitter Card tags
     * Requirements: 1.5 - Include Twitter Card meta tags
     * 
     * @return array
     */
    public function getTwitterCardTags(): array {
        $pageTitle = $this->getLandingSetting('page_title', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
        $description = $this->getLandingSetting('meta_description', 
            $this->getShopSetting('welcome_message', 'ร้านยาออนไลน์ครบวงจร'));
        $shopLogo = $this->getShopSetting('shop_logo', '');
        
        $tags = [
            'twitter:card' => 'summary_large_image',
            'twitter:title' => $pageTitle,
            'twitter:description' => $description
        ];
        
        if (!empty($shopLogo)) {
            $imageUrl = $shopLogo;
            if (strpos($imageUrl, 'http') !== 0) {
                $imageUrl = $this->baseUrl . '/' . ltrim($imageUrl, '/');
            }
            $tags['twitter:image'] = $imageUrl;
        }
        
        return $tags;
    }

    
    /**
     * Get Pharmacy structured data (JSON-LD)
     * Requirements: 2.1, 2.2, 2.3, 2.4
     * 
     * @return array JSON-LD structured data
     */
    public function getStructuredData(): array {
        $pageTitle = $this->getLandingSetting('page_title', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
        $description = $this->getLandingSetting('meta_description', 
            $this->getShopSetting('welcome_message', 'ร้านยาออนไลน์ครบวงจร'));
        $phone = $this->getShopSetting('contact_phone', '');
        $address = $this->getShopSetting('shop_address', '');
        $email = $this->getShopSetting('shop_email', '');
        $shopLogo = $this->getShopSetting('shop_logo', '');
        
        // Build base structured data (Requirements: 2.1, 2.2)
        $structuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'Pharmacy',
            'name' => $pageTitle,
            'description' => $description,
            'url' => $this->getCanonicalUrl()
        ];
        
        // Add telephone if available
        if (!empty($phone)) {
            $structuredData['telephone'] = $phone;
        }
        
        // Add email if available
        if (!empty($email)) {
            $structuredData['email'] = $email;
        }
        
        // Add logo if available
        if (!empty($shopLogo)) {
            $logoUrl = $shopLogo;
            if (strpos($logoUrl, 'http') !== 0) {
                $logoUrl = $this->baseUrl . '/' . ltrim($logoUrl, '/');
            }
            $structuredData['logo'] = $logoUrl;
            $structuredData['image'] = $logoUrl;
        }
        
        // Add address if available
        if (!empty($address)) {
            $structuredData['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $address,
                'addressCountry' => 'TH'
            ];
        }
        
        // Add opening hours if configured (Requirements: 2.3)
        $operatingHours = $this->getOperatingHours();
        if (!empty($operatingHours)) {
            $structuredData['openingHours'] = $operatingHours;
        }
        
        // Add geo coordinates if available (Requirements: 2.4)
        $latitude = $this->getLandingSetting('latitude');
        $longitude = $this->getLandingSetting('longitude');
        
        if (!empty($latitude) && !empty($longitude)) {
            $structuredData['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude
            ];
        }
        
        // Add aggregate rating if available
        $aggregateRating = $this->getAggregateRating();
        if ($aggregateRating !== null) {
            $structuredData['aggregateRating'] = $aggregateRating;
        }
        
        return $structuredData;
    }
    
    /**
     * Get operating hours in schema.org format
     * Requirements: 2.3 - Include opening hours if configured
     * 
     * @return array|null
     */
    private function getOperatingHours(): ?array {
        $hoursJson = $this->getLandingSetting('operating_hours');
        
        if (empty($hoursJson)) {
            return null;
        }
        
        $hours = json_decode($hoursJson, true);
        
        if (!is_array($hours) || empty($hours)) {
            return null;
        }
        
        $dayMap = [
            'mon' => 'Mo',
            'tue' => 'Tu',
            'wed' => 'We',
            'thu' => 'Th',
            'fri' => 'Fr',
            'sat' => 'Sa',
            'sun' => 'Su'
        ];
        
        $openingHours = [];
        foreach ($hours as $day => $time) {
            if (isset($dayMap[strtolower($day)]) && !empty($time) && $time !== 'closed') {
                $openingHours[] = $dayMap[strtolower($day)] . ' ' . $time;
            }
        }
        
        return !empty($openingHours) ? $openingHours : null;
    }
    
    /**
     * Get aggregate rating from testimonials
     * 
     * @return array|null
     */
    private function getAggregateRating(): ?array {
        try {
            $sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as review_count 
                    FROM landing_testimonials 
                    WHERE status = 'approved'";
            $params = [];
            
            if ($this->lineAccountId !== null) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['review_count'] > 0 && $result['avg_rating'] > 0) {
                return [
                    '@type' => 'AggregateRating',
                    'ratingValue' => number_format((float)$result['avg_rating'], 1),
                    'bestRating' => '5',
                    'worstRating' => '1',
                    'ratingCount' => (int)$result['review_count']
                ];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
        
        return null;
    }

    
    /**
     * Render all meta tags as HTML
     * 
     * @return string HTML meta tags
     */
    public function renderMetaTags(): string {
        $html = '';
        $metaTags = $this->getMetaTags();
        
        // Title
        if (!empty($metaTags['title'])) {
            $html .= '<title>' . htmlspecialchars($metaTags['title']) . '</title>' . "\n";
        }
        
        // Canonical URL (Requirements: 1.1)
        if (!empty($metaTags['canonical'])) {
            $html .= '<link rel="canonical" href="' . htmlspecialchars($metaTags['canonical']) . '">' . "\n";
        }
        
        // Description
        if (!empty($metaTags['description'])) {
            $html .= '<meta name="description" content="' . htmlspecialchars($metaTags['description']) . '">' . "\n";
        }
        
        // Keywords (Requirements: 1.2)
        if (!empty($metaTags['keywords'])) {
            $html .= '<meta name="keywords" content="' . htmlspecialchars($metaTags['keywords']) . '">' . "\n";
        }
        
        // Robots (Requirements: 1.3)
        if (!empty($metaTags['robots'])) {
            $html .= '<meta name="robots" content="' . htmlspecialchars($metaTags['robots']) . '">' . "\n";
        }
        
        // Author
        if (!empty($metaTags['author'])) {
            $html .= '<meta name="author" content="' . htmlspecialchars($metaTags['author']) . '">' . "\n";
        }
        
        return $html;
    }
    
    /**
     * Render Open Graph tags as HTML
     * Requirements: 1.4
     * 
     * @return string HTML Open Graph meta tags
     */
    public function renderOpenGraphTags(): string {
        $html = '';
        $ogTags = $this->getOpenGraphTags();
        
        foreach ($ogTags as $property => $content) {
            $html .= '<meta property="' . htmlspecialchars($property) . '" content="' . htmlspecialchars($content) . '">' . "\n";
        }
        
        return $html;
    }
    
    /**
     * Render Twitter Card tags as HTML
     * Requirements: 1.5
     * 
     * @return string HTML Twitter Card meta tags
     */
    public function renderTwitterCardTags(): string {
        $html = '';
        $twitterTags = $this->getTwitterCardTags();
        
        foreach ($twitterTags as $name => $content) {
            $html .= '<meta name="' . htmlspecialchars($name) . '" content="' . htmlspecialchars($content) . '">' . "\n";
        }
        
        return $html;
    }

    
    /**
     * Render structured data as JSON-LD script tag
     * Requirements: 2.1, 2.2, 2.3, 2.4
     * 
     * @return string HTML script tag with JSON-LD
     */
    public function renderStructuredData(): string {
        $structuredData = $this->getStructuredData();
        
        if (empty($structuredData)) {
            return '';
        }
        
        $json = json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        
        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }
    
    /**
     * Render all SEO elements (meta tags, OG tags, Twitter cards, structured data)
     * 
     * @return string Complete HTML for SEO
     */
    public function renderAll(): string {
        $html = '';
        $html .= "<!-- SEO Meta Tags -->\n";
        $html .= $this->renderMetaTags();
        $html .= "\n<!-- Favicon -->\n";
        $html .= $this->renderFaviconTags();
        $html .= "\n<!-- Open Graph Tags -->\n";
        $html .= $this->renderOpenGraphTags();
        $html .= "\n<!-- Twitter Card Tags -->\n";
        $html .= $this->renderTwitterCardTags();
        $html .= "\n<!-- Structured Data -->\n";
        $html .= $this->renderStructuredData();
        
        return $html;
    }
    
    /**
     * Get shop name
     * 
     * @return string
     */
    public function getShopName(): string {
        return $this->getShopSetting('shop_name', 'LINE Telepharmacy');
    }
    
    /**
     * Get page title
     * 
     * @return string
     */
    public function getPageTitle(): string {
        return $this->getLandingSetting('page_title', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
    }
    
    /**
     * Get app name
     * 
     * @return string
     */
    public function getAppName(): string {
        return $this->getLandingSetting('app_name', 
            $this->getShopSetting('shop_name', 'LINE Telepharmacy'));
    }
    
    /**
     * Get favicon URL
     * 
     * @return string
     */
    public function getFaviconUrl(): string {
        $faviconUrl = $this->getLandingSetting('favicon_url', '');
        
        // Return empty if not set
        if (empty($faviconUrl)) {
            return '';
        }
        
        // Make absolute URL if relative
        if (strpos($faviconUrl, 'http') !== 0) {
            return $this->baseUrl . '/' . ltrim($faviconUrl, '/');
        }
        
        return $faviconUrl;
    }
    
    /**
     * Render favicon link tags
     * 
     * @return string HTML link tags for favicon
     */
    public function renderFaviconTags(): string {
        $faviconUrl = $this->getFaviconUrl();
        
        if (empty($faviconUrl)) {
            return '';
        }
        
        $html = '';
        $html .= '<link rel="icon" type="image/x-icon" href="' . htmlspecialchars($faviconUrl) . '">' . "\n";
        $html .= '<link rel="shortcut icon" type="image/x-icon" href="' . htmlspecialchars($faviconUrl) . '">' . "\n";
        $html .= '<link rel="apple-touch-icon" href="' . htmlspecialchars($faviconUrl) . '">' . "\n";
        
        return $html;
    }
    
    /**
     * Get shop description
     * 
     * @return string
     */
    public function getShopDescription(): string {
        return $this->getLandingSetting('meta_description', 
            $this->getShopSetting('welcome_message', 'ร้านยาออนไลน์ครบวงจร'));
    }
    
    /**
     * Check if structured data has opening hours
     * 
     * @return bool
     */
    public function hasOpeningHours(): bool {
        return $this->getOperatingHours() !== null;
    }
    
    /**
     * Check if structured data has geo coordinates
     * 
     * @return bool
     */
    public function hasGeoCoordinates(): bool {
        $latitude = $this->getLandingSetting('latitude');
        $longitude = $this->getLandingSetting('longitude');
        return !empty($latitude) && !empty($longitude);
    }
}
