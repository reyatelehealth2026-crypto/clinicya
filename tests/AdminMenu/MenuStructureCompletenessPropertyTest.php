<?php
/**
 * Property-Based Test: Menu Structure Completeness
 * 
 * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
 * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
 * 
 * Property: For any menu group in the new structure, the group SHALL contain all menu items 
 * as specified in the requirements, and no menu items SHALL be duplicated across groups.
 */

namespace Tests\AdminMenu;

use PHPUnit\Framework\TestCase;

class MenuStructureCompletenessPropertyTest extends TestCase
{
    /**
     * Expected menu structure based on requirements
     * Maps group keys to required menu items (by URL)
     */
    private array $expectedMenuStructure = [
        // Requirement 2.1: Insights & Overview
        'insights' => [
            '/dashboard',            // Dashboard (consolidated)
            '/triage-analytics',     // Clinical Analytics
            '/drug-interactions',    // Clinical Analytics (Drug Interactions)
            '/activity-logs',        // Audit Logs
        ],
        // Requirement 3.1: Clinical Station
        'clinical' => [
            '/inbox',                // Unified Care Chat
            '/video-call-pro',       // Unified Care Chat
            '/auto-reply',           // Unified Care Chat
            '/pharmacist-dashboard', // Roster & Shifts
            '/pharmacists',          // Roster & Shifts
            '/ai-chat-settings',     // Medical Copilot AI
            '/ai-studio',            // Medical Copilot AI
            '/ai-pharmacy-settings', // Medical Copilot AI
        ],
        // Requirement 4.1: Patient & Journey
        'patient' => [
            '/users',                // EHR
            '/user-tags',            // EHR
            '/members',              // Membership
            '/admin-rewards',        // Membership
            '/admin-points-settings',// Membership
            '/broadcast',            // Care Journey
            '/broadcast-catalog',    // Care Journey
            '/drip-campaigns',       // Care Journey
            '/rich-menu',            // Digital Front Door
            '/dynamic-rich-menu',    // Digital Front Door
            '/liff-settings',        // Digital Front Door
        ],
        // Requirement 5.1: Supply & Revenue
        'supply' => [
            '/shop/orders',              // Billing & Orders
            '/shop/promotions',          // Billing & Orders
            '/shop/products',            // Inventory
            '/shop/categories',          // Inventory
            '/inventory/stock-adjustment',   // Inventory
            '/inventory/stock-movements',    // Inventory
            '/inventory/low-stock',          // Inventory
            '/inventory/product-units',      // Inventory
            '/inventory/purchase-orders',    // Procurement
            '/inventory/goods-receive',      // Procurement
            '/inventory/suppliers',          // Procurement
        ],
        // Requirement 6.1: Facility Setup
        'facility' => [
            '/shop/settings',        // Facility Profile
            '/admin-users2',         // Staff & Roles
            '/line-accounts',        // Integrations
            '/telegram',             // Integrations
            '/ai-settings',          // Integrations
            '/consent-management',   // Consent & PDPA
        ],
    ];

    /**
     * Get the menu sections from header.php
     * This extracts the $menuSections array structure
     */
    private function getMenuSections(): array
    {
        // Define mock globals to prevent errors when including header.php
        // We'll parse the file directly instead
        return $this->parseMenuSectionsFromHeader();
    }

    /**
     * Parse menu sections from header.php file
     */
    private function parseMenuSectionsFromHeader(): array
    {
        $headerPath = __DIR__ . '/../../includes/header.php';
        $content = file_get_contents($headerPath);
        
        // Extract the $menuSections array definition
        // Find the start of menuSections
        $startPattern = '/\$menuSections\s*=\s*\[/';
        if (!preg_match($startPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $this->fail('Could not find $menuSections in header.php');
        }
        
        $startPos = $matches[0][1];
        
        // Find matching closing bracket
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $arrayContent = '';
        $started = false;
        
        for ($i = $startPos; $i < strlen($content); $i++) {
            $char = $content[$i];
            $prevChar = $i > 0 ? $content[$i - 1] : '';
            
            // Handle string detection
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                $inString = false;
            }
            
            if (!$inString) {
                if ($char === '[') {
                    $depth++;
                    $started = true;
                } elseif ($char === ']') {
                    $depth--;
                }
            }
            
            $arrayContent .= $char;
            
            if ($started && $depth === 0) {
                break;
            }
        }
        
        // Now parse the extracted array to get menu structure
        return $this->extractMenuUrls($arrayContent);
    }

    /**
     * Extract menu URLs from the array content
     */
    private function extractMenuUrls(string $arrayContent): array
    {
        $menuSections = [];
        
        // Extract each section
        $sections = ['insights', 'clinical', 'patient', 'supply', 'facility'];
        
        foreach ($sections as $section) {
            $menuSections[$section] = [];
            
            // Find the section in the content
            $sectionPattern = "/'{$section}'\s*=>\s*\[/";
            if (preg_match($sectionPattern, $arrayContent, $matches, PREG_OFFSET_CAPTURE)) {
                $sectionStart = $matches[0][1];
                
                // Extract URLs from this section
                // Look for 'url' => '/...' patterns
                $urlPattern = "/'url'\s*=>\s*'([^']+)'/";
                
                // Find the end of this section (next section or end)
                $nextSectionPos = strlen($arrayContent);
                foreach ($sections as $nextSection) {
                    if ($nextSection !== $section) {
                        $nextPattern = "/'{$nextSection}'\s*=>\s*\[/";
                        if (preg_match($nextPattern, $arrayContent, $nextMatches, PREG_OFFSET_CAPTURE)) {
                            if ($nextMatches[0][1] > $sectionStart && $nextMatches[0][1] < $nextSectionPos) {
                                $nextSectionPos = $nextMatches[0][1];
                            }
                        }
                    }
                }
                
                $sectionContent = substr($arrayContent, $sectionStart, $nextSectionPos - $sectionStart);
                
                if (preg_match_all($urlPattern, $sectionContent, $urlMatches)) {
                    $menuSections[$section] = $urlMatches[1];
                }
            }
        }
        
        return $menuSections;
    }

    /**
     * Property Test: Each menu group contains all required menu items
     * 
     * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
     * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
     */
    public function testAllRequiredMenuItemsExistInGroups(): void
    {
        $actualMenuSections = $this->getMenuSections();
        
        foreach ($this->expectedMenuStructure as $groupKey => $expectedUrls) {
            $this->assertArrayHasKey(
                $groupKey,
                $actualMenuSections,
                "Menu group '{$groupKey}' should exist in menu structure"
            );
            
            $actualUrls = $actualMenuSections[$groupKey];
            
            foreach ($expectedUrls as $expectedUrl) {
                $this->assertContains(
                    $expectedUrl,
                    $actualUrls,
                    "Menu group '{$groupKey}' should contain URL '{$expectedUrl}'"
                );
            }
        }
    }

    /**
     * Property Test: No menu items are duplicated across groups
     * 
     * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
     * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
     */
    public function testNoMenuItemsDuplicatedAcrossGroups(): void
    {
        $actualMenuSections = $this->getMenuSections();
        
        $allUrls = [];
        $duplicates = [];
        
        foreach ($actualMenuSections as $groupKey => $urls) {
            foreach ($urls as $url) {
                if (isset($allUrls[$url])) {
                    $duplicates[] = "URL '{$url}' appears in both '{$allUrls[$url]}' and '{$groupKey}'";
                } else {
                    $allUrls[$url] = $groupKey;
                }
            }
        }
        
        $this->assertEmpty(
            $duplicates,
            "Menu items should not be duplicated across groups:\n" . implode("\n", $duplicates)
        );
    }

    /**
     * Property Test: All 5 main menu groups exist
     * 
     * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
     * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
     */
    public function testAllFiveMenuGroupsExist(): void
    {
        $actualMenuSections = $this->getMenuSections();
        
        $requiredGroups = ['insights', 'clinical', 'patient', 'supply', 'facility'];
        
        foreach ($requiredGroups as $group) {
            $this->assertArrayHasKey(
                $group,
                $actualMenuSections,
                "Required menu group '{$group}' should exist"
            );
        }
    }

    /**
     * Data provider for group completeness testing
     */
    public function menuGroupProvider(): array
    {
        return [
            'insights_group' => ['insights', 'Insights & Overview', ['2.1']],
            'clinical_group' => ['clinical', 'Clinical Station', ['3.1']],
            'patient_group' => ['patient', 'Patient & Journey', ['4.1']],
            'supply_group' => ['supply', 'Supply & Revenue', ['5.1']],
            'facility_group' => ['facility', 'Facility Setup', ['6.1']],
        ];
    }

    /**
     * Property Test: Each group has minimum required items
     * 
     * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
     * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
     * 
     * @dataProvider menuGroupProvider
     */
    public function testEachGroupHasMinimumRequiredItems(string $groupKey, string $groupName, array $requirements): void
    {
        $actualMenuSections = $this->getMenuSections();
        $expectedUrls = $this->expectedMenuStructure[$groupKey];
        
        $this->assertArrayHasKey($groupKey, $actualMenuSections);
        
        $actualCount = count($actualMenuSections[$groupKey]);
        $expectedCount = count($expectedUrls);
        
        $this->assertGreaterThanOrEqual(
            $expectedCount,
            $actualCount,
            "Group '{$groupName}' should have at least {$expectedCount} menu items (has {$actualCount}). Requirements: " . implode(', ', $requirements)
        );
    }

    /**
     * Property Test: For any randomly selected required URL, it exists in exactly one group
     * 
     * **Feature: admin-menu-restructure, Property 3: Menu Structure Completeness**
     * **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**
     */
    public function testRandomRequiredUrlExistsInExactlyOneGroup(): void
    {
        $actualMenuSections = $this->getMenuSections();
        
        // Collect all required URLs
        $allRequiredUrls = [];
        foreach ($this->expectedMenuStructure as $groupKey => $urls) {
            foreach ($urls as $url) {
                $allRequiredUrls[] = ['url' => $url, 'expectedGroup' => $groupKey];
            }
        }
        
        // Test 100 random selections (property-based testing approach)
        for ($i = 0; $i < 100; $i++) {
            $randomIndex = array_rand($allRequiredUrls);
            $testCase = $allRequiredUrls[$randomIndex];
            $url = $testCase['url'];
            $expectedGroup = $testCase['expectedGroup'];
            
            // Count how many groups contain this URL
            $foundInGroups = [];
            foreach ($actualMenuSections as $groupKey => $urls) {
                if (in_array($url, $urls)) {
                    $foundInGroups[] = $groupKey;
                }
            }
            
            $this->assertCount(
                1,
                $foundInGroups,
                "URL '{$url}' should exist in exactly one group. Found in: " . implode(', ', $foundInGroups)
            );
            
            $this->assertEquals(
                $expectedGroup,
                $foundInGroups[0],
                "URL '{$url}' should be in group '{$expectedGroup}', but found in '{$foundInGroups[0]}'"
            );
        }
    }
}
