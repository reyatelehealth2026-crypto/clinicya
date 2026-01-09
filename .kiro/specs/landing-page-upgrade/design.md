# Design Document

## Overview

การอัพเกรด Landing Page (index.php) เพื่อปรับปรุง SEO และ UX โดยเพิ่ม Structured Data, Trust Badges, FAQ Section, Testimonials, Performance Optimization และ Admin Settings สำหรับจัดการ content

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Landing Page (index.php)                 │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │ SEO Service │  │ FAQ Service │  │ Testimonial Service │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│         └────────────────┼─────────────────────┘             │
│                          │                                   │
│                    ┌─────▼─────┐                             │
│                    │  Database │                             │
│                    └───────────┘                             │
├─────────────────────────────────────────────────────────────┤
│  Static Files: sitemap.xml, robots.txt                       │
└─────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. LandingSEOService Class

```php
class LandingSEOService {
    public function __construct(PDO $db, int $lineAccountId);
    public function getMetaTags(): array;
    public function getStructuredData(): array;
    public function getCanonicalUrl(): string;
    public function getOpenGraphTags(): array;
    public function getTwitterCardTags(): array;
}
```

### 2. FAQService Class

```php
class FAQService {
    public function __construct(PDO $db, int $lineAccountId);
    public function getActiveFAQs(int $limit = 10): array;
    public function getFAQStructuredData(): array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function reorder(array $ids): bool;
}
```

### 3. TestimonialService Class

```php
class TestimonialService {
    public function __construct(PDO $db, int $lineAccountId);
    public function getApprovedTestimonials(int $limit = 10): array;
    public function getTestimonialStructuredData(): array;
    public function getAverageRating(): float;
    public function getTotalCount(): int;
    public function create(array $data): int;
    public function approve(int $id): bool;
    public function reject(int $id): bool;
}
```

### 4. TrustBadgeService Class

```php
class TrustBadgeService {
    public function __construct(PDO $db, int $lineAccountId);
    public function getBadges(): array;
    public function getCustomerCount(): int;
    public function getOrderCount(): int;
    public function getAverageRating(): float;
    public function getLicenseInfo(): ?array;
    public function getEstablishmentYear(): ?int;
}
```

### 5. SitemapGenerator Class

```php
class SitemapGenerator {
    public function __construct(PDO $db, string $baseUrl);
    public function generate(): string;
    public function getLastModified(): string;
    public function getUrls(): array;
}
```

## Data Models

### FAQ Table

```sql
CREATE TABLE landing_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faq_account (line_account_id),
    INDEX idx_faq_active (is_active, sort_order)
);
```

### Testimonials Table

```sql
CREATE TABLE landing_testimonials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_avatar VARCHAR(255) NULL,
    rating TINYINT DEFAULT 5,
    review_text TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    source VARCHAR(50) NULL COMMENT 'google, facebook, manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    INDEX idx_testimonial_account (line_account_id),
    INDEX idx_testimonial_status (status, created_at)
);
```

### Landing Settings Table

```sql
CREATE TABLE landing_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_landing_setting (line_account_id, setting_key)
);
```

### Landing Settings Keys

| Key | Description | Example Value |
|-----|-------------|---------------|
| meta_keywords | SEO keywords | ร้านยาออนไลน์, เภสัชกร, ส่งยาถึงบ้าน |
| meta_description | SEO description | ร้านยาออนไลน์ครบวงจร... |
| license_number | Pharmacy license | ข.1234/2567 |
| establishment_year | Year founded | 2020 |
| google_map_embed | Map embed URL | https://maps.google.com/... |
| latitude | Location lat | 13.7563 |
| longitude | Location lng | 100.5018 |
| operating_hours | JSON hours | {"mon":"09:00-18:00",...} |


## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: SEO Meta Tags Completeness
*For any* landing page render with valid shop settings, the HTML output should contain canonical URL, keywords meta, robots meta, and Open Graph tags
**Validates: Requirements 1.1, 1.2, 1.3, 1.4**

### Property 2: Structured Data Validity
*For any* landing page render with shop data, the JSON-LD output should be valid JSON containing @type "Pharmacy" with name, description, and telephone fields
**Validates: Requirements 2.1, 2.2**

### Property 3: Conditional Structured Data Fields
*For any* landing page render, if opening hours are configured then the structured data should include openingHours, otherwise it should be omitted
**Validates: Requirements 2.3, 2.4**

### Property 4: Trust Badge Graceful Degradation
*For any* trust badge configuration, if a badge's required data is missing then that specific badge should not appear in the HTML output
**Validates: Requirements 3.1, 3.5**

### Property 5: FAQ Section Conditional Rendering
*For any* landing page render, if FAQ items exist then the FAQ section should appear with FAQPage schema, otherwise the FAQ section should be hidden
**Validates: Requirements 4.1, 4.3, 4.4**

### Property 6: FAQ Count Constraints
*For any* FAQ query, the returned items should be between 0 and 10, and if items exist the count should be at least 3 (or all items if less than 3 exist)
**Validates: Requirements 4.5**

### Property 7: Testimonials Conditional Rendering
*For any* landing page render, if approved testimonials exist then the testimonials section should appear with Review schema, otherwise it should be hidden
**Validates: Requirements 5.1, 5.4, 5.5**

### Property 8: Testimonial Required Fields
*For any* displayed testimonial, the output should contain customer_name, rating (1-5), and review_text
**Validates: Requirements 5.2**

### Property 9: Image Lazy Loading
*For any* img tag in the landing page output (except above-the-fold images), the tag should include loading="lazy" attribute
**Validates: Requirements 6.1**

### Property 10: Sitemap XML Validity
*For any* sitemap generation, the output should be valid XML containing at least the landing page URL with proper lastmod date
**Validates: Requirements 9.1, 9.3**

### Property 11: Robots.txt Sitemap Reference
*For any* robots.txt generation, the output should contain "Sitemap:" directive pointing to sitemap.xml
**Validates: Requirements 9.2, 9.4**

### Property 12: FAQ CRUD Round Trip
*For any* FAQ item created through FAQService, retrieving it by ID should return the same question and answer
**Validates: Requirements 10.3**

### Property 13: Testimonial CRUD Round Trip
*For any* testimonial created through TestimonialService, retrieving it by ID should return the same customer_name, rating, and review_text
**Validates: Requirements 10.4**

### Property 14: Landing Settings Round Trip
*For any* setting saved through landing_settings table, retrieving it by key should return the same value
**Validates: Requirements 10.2, 10.5**

## Error Handling

| Error Scenario | Handling Strategy |
|----------------|-------------------|
| Database connection failure | Display cached/static content, log error |
| Missing shop settings | Use default values from config |
| Invalid JSON in settings | Return empty array, log warning |
| Missing image files | Display placeholder image |
| Invalid structured data | Omit invalid sections, render valid parts |

## Testing Strategy

### Unit Testing
- Test each service class method independently
- Mock database connections for isolated testing
- Test edge cases: empty data, null values, special characters

### Property-Based Testing
Using PHPUnit with data providers for property-based testing:

- **LandingSEOServiceTest**: Test meta tag generation with various shop configurations
- **FAQServiceTest**: Test CRUD operations and query constraints
- **TestimonialServiceTest**: Test approval workflow and data integrity
- **SitemapGeneratorTest**: Test XML validity and URL inclusion
- **StructuredDataTest**: Test JSON-LD generation and validation

### Integration Testing
- Test full page render with database
- Test admin settings save and reflect on landing page
- Test sitemap.xml and robots.txt endpoints

## File Structure

```
├── classes/
│   ├── LandingSEOService.php
│   ├── FAQService.php
│   ├── TestimonialService.php
│   ├── TrustBadgeService.php
│   └── SitemapGenerator.php
├── database/
│   └── migration_landing_page.sql
├── install/
│   └── run_landing_page_migration.php
├── admin/
│   └── landing-settings.php
├── includes/
│   └── landing/
│       ├── seo-meta.php
│       ├── structured-data.php
│       ├── trust-badges.php
│       ├── faq-section.php
│       └── testimonials.php
├── sitemap.xml (dynamic)
├── robots.txt (dynamic)
└── index.php (updated)
```
