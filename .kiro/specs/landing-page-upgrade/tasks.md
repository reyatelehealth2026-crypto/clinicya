# Implementation Plan

- [x] 1. Database Migration






  - [x] 1.1 Create migration file for landing page tables

    - Create `database/migration_landing_page.sql` with landing_faqs, landing_testimonials, landing_settings tables
    - Include indexes for performance
    - _Requirements: 4.1, 5.1, 10.1_

  - [x] 1.2 Create migration runner script

    - Create `install/run_landing_page_migration.php`
    - Handle existing tables gracefully
    - _Requirements: 4.1, 5.1, 10.1_


- [x] 2. Core Service Classes




  - [x] 2.1 Implement FAQService class


    - Create `classes/FAQService.php` with CRUD operations
    - Include getActiveFAQs() with limit parameter
    - Include getFAQStructuredData() for JSON-LD
    - _Requirements: 4.1, 4.3, 4.5, 10.3_
  - [ ]* 2.2 Write property test for FAQ CRUD round trip
    - **Property 12: FAQ CRUD Round Trip**
    - **Validates: Requirements 10.3**

  - [x] 2.3 Implement TestimonialService class

    - Create `classes/TestimonialService.php` with CRUD and approval workflow
    - Include getApprovedTestimonials() and getTestimonialStructuredData()
    - Include getAverageRating() and getTotalCount()
    - _Requirements: 5.1, 5.2, 5.5, 10.4_
  - [ ]* 2.4 Write property test for Testimonial CRUD round trip
    - **Property 13: Testimonial CRUD Round Trip**
    - **Validates: Requirements 10.4**

  - [x] 2.5 Implement TrustBadgeService class

    - Create `classes/TrustBadgeService.php`
    - Include getBadges(), getCustomerCount(), getOrderCount(), getAverageRating()
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_
  - [ ]* 2.6 Write property test for Trust Badge graceful degradation
    - **Property 4: Trust Badge Graceful Degradation**
    - **Validates: Requirements 3.1, 3.5**

- [x] 3. SEO Service and Structured Data









  - [x] 3.1 Implement LandingSEOService class

    - Create `classes/LandingSEOService.php`
    - Include getMetaTags(), getCanonicalUrl(), getOpenGraphTags(), getTwitterCardTags()
    - Include getStructuredData() for Pharmacy JSON-LD
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4_
  - [x]* 3.2 Write property test for SEO Meta Tags completeness


    - **Property 1: SEO Meta Tags Completeness**
    - **Validates: Requirements 1.1, 1.2, 1.3, 1.4**
  - [x]* 3.3 Write property test for Structured Data validity


    - **Property 2: Structured Data Validity**
    - **Validates: Requirements 2.1, 2.2**

  - [x] 3.4 Implement SitemapGenerator class


    - Create `classes/SitemapGenerator.php`
    - Include generate() for XML output
    - Include product pages if public products exist
    - _Requirements: 9.1, 9.3, 9.5_
  - [x]* 3.5 Write property test for Sitemap XML validity


    - **Property 10: Sitemap XML Validity**
    - **Validates: Requirements 9.1, 9.3**

- [ ] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.


- [x] 5. Landing Page Components




  - [x] 5.1 Create SEO meta component


    - Create `includes/landing/seo-meta.php`
    - Output canonical, keywords, robots, OG tags, Twitter Card
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_
  - [x] 5.2 Create structured data component


    - Create `includes/landing/structured-data.php`
    - Output Pharmacy JSON-LD with conditional fields
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [x] 5.3 Create trust badges component


    - Create `includes/landing/trust-badges.php`
    - Display license, customer count, rating, years
    - Handle missing data gracefully
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_
  - [x] 5.4 Create FAQ section component


    - Create `includes/landing/faq-section.php`
    - Expandable accordion with FAQPage schema
    - Hide if no FAQs
    - _Requirements: 4.1, 4.3, 4.4, 4.5_
  - [ ]* 5.5 Write property test for FAQ conditional rendering
    - **Property 5: FAQ Section Conditional Rendering**
    - **Validates: Requirements 4.1, 4.3, 4.4**
  - [x] 5.6 Create testimonials component


    - Create `includes/landing/testimonials.php`
    - Carousel for 3+ testimonials, Review schema
    - Hide if no testimonials
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_
  - [ ]* 5.7 Write property test for Testimonials conditional rendering
    - **Property 7: Testimonials Conditional Rendering**
    - **Validates: Requirements 5.1, 5.4, 5.5**

- [x] 6. Update Landing Page (index.php)





  - [x] 6.1 Integrate SEO meta component


    - Include seo-meta.php in head section
    - Replace existing meta tags
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_
  - [x] 6.2 Integrate structured data component


    - Include structured-data.php before closing head
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [x] 6.3 Add trust badges section after hero


    - Include trust-badges.php component
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5_
  - [x] 6.4 Add FAQ section before footer


    - Include faq-section.php component
    - _Requirements: 4.1, 4.3, 4.4, 4.5_
  - [x] 6.5 Add testimonials section


    - Include testimonials.php component after services
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_
  - [x] 6.6 Add floating LINE button


    - Add floating button with scroll behavior
    - Hide if LINE ID not configured
    - _Requirements: 8.1, 8.5_
  - [x] 6.7 Optimize images with lazy loading


    - Add loading="lazy" to all images below fold
    - _Requirements: 6.1_
  - [ ]* 6.8 Write property test for Image lazy loading
    - **Property 9: Image Lazy Loading**
    - **Validates: Requirements 6.1**

- [x] 7. SEO Files





  - [x] 7.1 Create dynamic sitemap.xml handler


    - Create sitemap.php that outputs XML
    - Add .htaccess rewrite for sitemap.xml
    - _Requirements: 9.1, 9.3, 9.5_
  - [x] 7.2 Create dynamic robots.txt handler


    - Create robots.php that outputs text
    - Include Sitemap directive
    - Add .htaccess rewrite for robots.txt
    - _Requirements: 9.2, 9.4_
  - [ ]* 7.3 Write property test for Robots.txt sitemap reference
    - **Property 11: Robots.txt Sitemap Reference**
    - **Validates: Requirements 9.2, 9.4**



- [x] 8. Admin Settings Page





  - [x] 8.1 Create landing settings admin page


    - Create `admin/landing-settings.php`
    - Include SEO settings tab (keywords, description)
    - _Requirements: 10.1, 10.2_
  - [x] 8.2 Add FAQ management tab


    - CRUD interface for FAQ items
    - Drag-drop reordering
    - _Requirements: 10.3_
  - [x] 8.3 Add testimonials management tab


    - List with approve/reject actions
    - Manual add testimonial form
    - _Requirements: 10.4_
  - [x] 8.4 Add trust badges settings tab








    - License number, establishment year fields
    - Custom badge configuration
    - _Requirements: 10.5_
  - [ ]* 8.5 Write property test for Landing Settings round trip
    - **Property 14: Landing Settings Round Trip**
    - **Validates: Requirements 10.2, 10.5**


- [x] 9. Contact Section Enhancement




  - [x] 9.1 Add operating hours display




    - Parse JSON operating hours from settings
    - Display formatted hours
    - _Requirements: 7.1_

  - [x] 9.2 Add clickable phone and LINE links
    - tel: link for phone
    - LINE add friend link

    - _Requirements: 7.2, 7.3_

  - [x] 9.3 Add Google Map embed
    - Display map if coordinates configured
    - _Requirements: 7.4_


- [x] 10. Final Checkpoint - Ensure all tests pass




  - Ensure all tests pass, ask the user if questions arise.
