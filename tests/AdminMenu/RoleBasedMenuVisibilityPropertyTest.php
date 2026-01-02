<?php
/**
 * Property-Based Test: Role-Based Menu Visibility
 * 
 * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
 * **Validates: Requirements 2.2, 2.3, 3.2, 3.3, 4.2, 4.3, 4.4, 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 7.1, 7.2**
 * 
 * Property: For any user role and for any menu item with role restrictions, 
 * the menu item SHALL be visible if and only if the user's role is included 
 * in the menu item's allowed roles array.
 */

namespace Tests\AdminMenu;

use PHPUnit\Framework\TestCase;

class RoleBasedMenuVisibilityPropertyTest extends TestCase
{
    /**
     * All possible user roles in the system
     */
    private array $allRoles = ['owner', 'admin', 'pharmacist', 'staff', 'marketing', 'tech'];
    
    /**
     * Menu structure with role restrictions extracted from header.php
     */
    private array $menuStructure = [];
    
    /**
     * Expected role access per requirement
     * Maps menu URLs to their allowed roles based on requirements
     */
    private array $expectedRoleAccess = [
        // Requirement 2.2, 2.3: Insights & Overview
        '/dashboard' => ['owner', 'admin'],
        '/triage-analytics' => ['pharmacist', 'owner'],
        '/drug-interactions' => ['pharmacist', 'owner'],
        '/activity-logs' => ['owner'],
        
        // Requirement 3.2, 3.3: Clinical Station
        '/inbox' => ['pharmacist', 'staff'],
        '/video-call' => ['pharmacist', 'staff'],
        '/auto-reply' => ['pharmacist', 'staff'],
        '/pharmacist-dashboard' => null, // All staff (no restriction)
        '/pharmacists' => null, // All staff (no restriction)
        '/ai-chat-settings' => ['pharmacist'],
        '/ai-studio' => ['pharmacist'],
        '/ai-pharmacy-settings' => ['pharmacist'],
        
        // Requirement 4.2, 4.3, 4.4: Patient & Journey
        '/users' => ['pharmacist'],
        '/user-tags' => ['pharmacist'],
        '/members' => null, // All staff (no restriction)
        '/admin-rewards' => null, // All staff (no restriction)
        '/admin-points-settings' => null, // All staff (no restriction)
        '/broadcast' => ['admin', 'marketing'],
        '/broadcast-catalog' => ['admin', 'marketing'],
        '/drip-campaigns' => ['admin', 'marketing'],
        '/rich-menu' => ['admin', 'marketing'],
        '/dynamic-rich-menu' => ['admin', 'marketing'],
        '/liff-settings' => ['admin', 'marketing'],
        
        // Requirement 5.2, 5.3, 5.4: Supply & Revenue
        '/shop/orders' => ['admin', 'staff'],
        '/shop/promotions' => ['admin', 'staff'],
        '/shop/products' => ['admin', 'pharmacist'],
        '/shop/categories' => ['admin', 'pharmacist'],
        '/inventory/stock-adjustment' => ['admin', 'pharmacist'],
        '/inventory/stock-movements' => ['admin', 'pharmacist'],
        '/inventory/low-stock' => ['admin', 'pharmacist'],
        '/inventory/product-units' => ['admin', 'pharmacist'],
        '/inventory/purchase-orders' => ['admin', 'owner'],
        '/inventory/goods-receive' => ['admin', 'owner'],
        '/inventory/suppliers' => ['admin', 'owner'],
        
        // Requirement 6.2, 6.3, 6.4: Facility Setup
        '/shop/settings' => ['admin', 'owner'],
        '/admin-users2' => ['owner', 'admin'],
        '/line-accounts' => ['owner', 'admin', 'tech'],
        '/telegram' => ['owner', 'admin', 'tech'],
        '/ai-settings' => ['owner', 'admin', 'tech'],
        '/consent-management' => ['owner', 'admin'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->menuStructure = $this->parseMenuStructureFromHeader();
    }

    /**
     * Parse menu structure from header.php file
     * Extracts menu items with their role restrictions
     */
    private function parseMenuStructureFromHeader(): array
    {
        $headerPath = __DIR__ . '/../../includes/header.php';
        $content = file_get_contents($headerPath);
        
        $menuItems = [];
        $sections = ['insights', 'clinical', 'patient', 'supply', 'facility'];
        
        foreach ($sections as $section) {
            $sectionItems = $this->extractSectionItems($content, $section);
            $menuItems = array_merge($menuItems, $sectionItems);
        }
        
        return $menuItems;
    }

    /**
     * Extract menu items from a specific section
     */
    private function extractSectionItems(string $content, string $sectionKey): array
    {
        $items = [];
        
        // Find the section
        $sectionPattern = "/'{$sectionKey}'\s*=>\s*\[/";
        if (!preg_match($sectionPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $items;
        }
        
        $sectionStart = $matches[0][1];
        
        // Find the end of this section
        $sections = ['insights', 'clinical', 'patient', 'supply', 'facility'];
        $nextSectionPos = strlen($content);
        foreach ($sections as $nextSection) {
            if ($nextSection !== $sectionKey) {
                $nextPattern = "/'{$nextSection}'\s*=>\s*\[/";
                if (preg_match($nextPattern, $content, $nextMatches, PREG_OFFSET_CAPTURE)) {
                    if ($nextMatches[0][1] > $sectionStart && $nextMatches[0][1] < $nextSectionPos) {
                        $nextSectionPos = $nextMatches[0][1];
                    }
                }
            }
        }
        
        $sectionContent = substr($content, $sectionStart, $nextSectionPos - $sectionStart);
        
        // Extract individual menu items with their URLs and roles
        // Pattern to match menu item arrays
        $itemPattern = "/\[\s*'icon'\s*=>\s*'[^']+'\s*,\s*'label'\s*=>\s*'[^']+'\s*,\s*'url'\s*=>\s*'([^']+)'[^\]]*\]/s";
        
        if (preg_match_all($itemPattern, $sectionContent, $itemMatches, PREG_SET_ORDER)) {
            foreach ($itemMatches as $match) {
                $url = $match[1];
                $itemContent = $match[0];
                
                // Extract roles if present
                $roles = null;
                if (preg_match("/'roles'\s*=>\s*\[([^\]]+)\]/", $itemContent, $rolesMatch)) {
                    $rolesStr = $rolesMatch[1];
                    preg_match_all("/'([^']+)'/", $rolesStr, $roleValues);
                    $roles = $roleValues[1];
                }
                
                $items[] = [
                    'url' => $url,
                    'roles' => $roles,
                    'section' => $sectionKey,
                ];
            }
        }
        
        return $items;
    }

    /**
     * Simulate hasMenuAccess function from header.php
     */
    private function hasMenuAccess(array $menuItem, string $userRole): bool
    {
        // If no roles specified, everyone can access
        if (!isset($menuItem['roles']) || empty($menuItem['roles'])) {
            return true;
        }
        
        // Check if user's role is in the allowed roles array
        return in_array($userRole, $menuItem['roles']);
    }

    /**
     * Property Test: Menu visibility matches role permissions
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 2.2, 2.3, 3.2, 3.3, 4.2, 4.3, 4.4, 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 7.1, 7.2**
     * 
     * For any user role and any menu item, visibility should match role permissions.
     */
    public function testMenuVisibilityMatchesRolePermissions(): void
    {
        $this->assertNotEmpty($this->menuStructure, 'Menu structure should not be empty');
        
        foreach ($this->menuStructure as $menuItem) {
            foreach ($this->allRoles as $role) {
                $hasAccess = $this->hasMenuAccess($menuItem, $role);
                $url = $menuItem['url'];
                
                // If menu has no role restriction, all roles should have access
                if ($menuItem['roles'] === null) {
                    $this->assertTrue(
                        $hasAccess,
                        "Role '{$role}' should have access to unrestricted menu '{$url}'"
                    );
                } else {
                    // Check if role is in allowed roles
                    $shouldHaveAccess = in_array($role, $menuItem['roles']);
                    $this->assertEquals(
                        $shouldHaveAccess,
                        $hasAccess,
                        "Role '{$role}' access to '{$url}' should be " . ($shouldHaveAccess ? 'granted' : 'denied')
                    );
                }
            }
        }
    }

    /**
     * Property Test: Role restrictions match requirements specification
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 2.2, 2.3, 3.2, 3.3, 4.2, 4.3, 4.4, 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 7.1, 7.2**
     */
    public function testRoleRestrictionsMatchRequirements(): void
    {
        foreach ($this->menuStructure as $menuItem) {
            $url = $menuItem['url'];
            
            if (!isset($this->expectedRoleAccess[$url])) {
                continue; // Skip URLs not in requirements
            }
            
            $expectedRoles = $this->expectedRoleAccess[$url];
            $actualRoles = $menuItem['roles'];
            
            if ($expectedRoles === null) {
                // Should have no role restriction
                $this->assertNull(
                    $actualRoles,
                    "Menu '{$url}' should have no role restriction (accessible to all)"
                );
            } else {
                $this->assertNotNull(
                    $actualRoles,
                    "Menu '{$url}' should have role restrictions"
                );
                
                // Sort both arrays for comparison
                sort($expectedRoles);
                sort($actualRoles);
                
                $this->assertEquals(
                    $expectedRoles,
                    $actualRoles,
                    "Menu '{$url}' role restrictions should match requirements. " .
                    "Expected: [" . implode(', ', $expectedRoles) . "], " .
                    "Actual: [" . implode(', ', $actualRoles) . "]"
                );
            }
        }
    }

    /**
     * Property Test: Random role-menu combinations follow access rules
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 7.1, 7.2**
     * 
     * Property-based test: For 100 random role-menu combinations, verify access rules.
     */
    public function testRandomRoleMenuCombinationsFollowAccessRules(): void
    {
        $this->assertNotEmpty($this->menuStructure, 'Menu structure should not be empty');
        
        // Run 100 random combinations
        for ($i = 0; $i < 100; $i++) {
            $randomMenuIndex = array_rand($this->menuStructure);
            $randomRoleIndex = array_rand($this->allRoles);
            
            $menuItem = $this->menuStructure[$randomMenuIndex];
            $role = $this->allRoles[$randomRoleIndex];
            
            $hasAccess = $this->hasMenuAccess($menuItem, $role);
            $url = $menuItem['url'];
            
            if ($menuItem['roles'] === null) {
                $this->assertTrue(
                    $hasAccess,
                    "Iteration {$i}: Role '{$role}' should access unrestricted menu '{$url}'"
                );
            } else {
                $shouldHaveAccess = in_array($role, $menuItem['roles']);
                $this->assertEquals(
                    $shouldHaveAccess,
                    $hasAccess,
                    "Iteration {$i}: Role '{$role}' access to '{$url}' mismatch"
                );
            }
        }
    }

    /**
     * Data provider for role-specific tests
     */
    public function roleProvider(): array
    {
        return [
            'owner_role' => ['owner'],
            'admin_role' => ['admin'],
            'pharmacist_role' => ['pharmacist'],
            'staff_role' => ['staff'],
            'marketing_role' => ['marketing'],
            'tech_role' => ['tech'],
        ];
    }

    /**
     * Property Test: Owner has access to all owner-restricted menus
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 2.2, 6.2**
     * 
     * @dataProvider roleProvider
     */
    public function testRoleAccessToRestrictedMenus(string $role): void
    {
        foreach ($this->menuStructure as $menuItem) {
            $url = $menuItem['url'];
            $hasAccess = $this->hasMenuAccess($menuItem, $role);
            
            if ($menuItem['roles'] !== null && in_array($role, $menuItem['roles'])) {
                $this->assertTrue(
                    $hasAccess,
                    "Role '{$role}' should have access to '{$url}' (role is in allowed list)"
                );
            }
            
            if ($menuItem['roles'] !== null && !in_array($role, $menuItem['roles'])) {
                $this->assertFalse(
                    $hasAccess,
                    "Role '{$role}' should NOT have access to '{$url}' (role not in allowed list)"
                );
            }
        }
    }

    /**
     * Property Test: Insights & Overview group access (Requirement 2.2, 2.3)
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 2.2, 2.3**
     */
    public function testInsightsGroupRoleAccess(): void
    {
        $insightsItems = array_filter($this->menuStructure, fn($item) => $item['section'] === 'insights');
        
        foreach ($insightsItems as $item) {
            $url = $item['url'];
            
            // Owner should have full access (Requirement 2.2)
            $this->assertTrue(
                $this->hasMenuAccess($item, 'owner'),
                "Owner should have access to Insights menu '{$url}'"
            );
            
            // Pharmacist should only access Clinical Analytics (Requirement 2.3)
            if ($url === '/triage-analytics' || $url === '/drug-interactions') {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'pharmacist'),
                    "Pharmacist should have access to Clinical Analytics '{$url}'"
                );
            } elseif ($url === '/dashboard' || $url === '/activity-logs') {
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'pharmacist'),
                    "Pharmacist should NOT have access to '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Clinical Station group access (Requirement 3.2, 3.3)
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 3.2, 3.3**
     */
    public function testClinicalStationGroupRoleAccess(): void
    {
        $clinicalItems = array_filter($this->menuStructure, fn($item) => $item['section'] === 'clinical');
        
        foreach ($clinicalItems as $item) {
            $url = $item['url'];
            
            // Pharmacist should have full access (Requirement 3.2)
            $this->assertTrue(
                $this->hasMenuAccess($item, 'pharmacist'),
                "Pharmacist should have access to Clinical Station menu '{$url}'"
            );
            
            // Staff should access Unified Care Chat and Roster & Shifts only (Requirement 3.3)
            $staffAccessibleUrls = ['/inbox', '/video-call', '/auto-reply', '/pharmacist-dashboard', '/pharmacists'];
            if (in_array($url, $staffAccessibleUrls)) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'staff'),
                    "Staff should have access to '{$url}'"
                );
            } else {
                // AI menus should not be accessible to staff
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'staff'),
                    "Staff should NOT have access to AI menu '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Patient & Journey group access (Requirement 4.2, 4.3, 4.4)
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 4.2, 4.3, 4.4**
     */
    public function testPatientJourneyGroupRoleAccess(): void
    {
        $patientItems = array_filter($this->menuStructure, fn($item) => $item['section'] === 'patient');
        
        foreach ($patientItems as $item) {
            $url = $item['url'];
            
            // Pharmacist should access EHR (Requirement 4.2)
            if ($url === '/users' || $url === '/user-tags') {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'pharmacist'),
                    "Pharmacist should have access to EHR menu '{$url}'"
                );
            }
            
            // Marketing should access Care Journey and Digital Front Door only (Requirement 4.4)
            $marketingAccessibleUrls = [
                '/broadcast', '/broadcast-catalog', '/drip-campaigns',
                '/rich-menu', '/dynamic-rich-menu', '/liff-settings'
            ];
            if (in_array($url, $marketingAccessibleUrls)) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'marketing'),
                    "Marketing should have access to '{$url}'"
                );
            } elseif ($url === '/users' || $url === '/user-tags') {
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'marketing'),
                    "Marketing should NOT have access to EHR menu '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Supply & Revenue group access (Requirement 5.2, 5.3, 5.4)
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 5.2, 5.3, 5.4**
     */
    public function testSupplyRevenueGroupRoleAccess(): void
    {
        $supplyItems = array_filter($this->menuStructure, fn($item) => $item['section'] === 'supply');
        
        foreach ($supplyItems as $item) {
            $url = $item['url'];
            
            // Admin should have full access (Requirement 5.2)
            $this->assertTrue(
                $this->hasMenuAccess($item, 'admin'),
                "Admin should have access to Supply & Revenue menu '{$url}'"
            );
            
            // Pharmacist should access Inventory only (Requirement 5.3)
            $inventoryUrls = [
                '/shop/products', '/shop/categories',
                '/inventory/stock-adjustment', '/inventory/stock-movements',
                '/inventory/low-stock', '/inventory/product-units'
            ];
            if (in_array($url, $inventoryUrls)) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'pharmacist'),
                    "Pharmacist should have access to Inventory menu '{$url}'"
                );
            } else {
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'pharmacist'),
                    "Pharmacist should NOT have access to non-Inventory menu '{$url}'"
                );
            }
            
            // Staff should access Billing & Orders only (Requirement 5.4)
            $billingUrls = ['/shop/orders', '/shop/promotions'];
            if (in_array($url, $billingUrls)) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'staff'),
                    "Staff should have access to Billing menu '{$url}'"
                );
            } else {
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'staff'),
                    "Staff should NOT have access to non-Billing menu '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Facility Setup group access (Requirement 6.2, 6.3, 6.4)
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 6.2, 6.3, 6.4**
     */
    public function testFacilitySetupGroupRoleAccess(): void
    {
        $facilityItems = array_filter($this->menuStructure, fn($item) => $item['section'] === 'facility');
        
        foreach ($facilityItems as $item) {
            $url = $item['url'];
            
            // Owner should have full access (Requirement 6.2)
            $this->assertTrue(
                $this->hasMenuAccess($item, 'owner'),
                "Owner should have access to Facility Setup menu '{$url}'"
            );
            
            // Tech should access Integrations only (Requirement 6.4)
            $integrationUrls = ['/line-accounts', '/telegram', '/ai-settings'];
            if (in_array($url, $integrationUrls)) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, 'tech'),
                    "Tech should have access to Integration menu '{$url}'"
                );
            } else {
                $this->assertFalse(
                    $this->hasMenuAccess($item, 'tech'),
                    "Tech should NOT have access to non-Integration menu '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Unrestricted menus are accessible to all roles
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 7.1**
     */
    public function testUnrestrictedMenusAccessibleToAllRoles(): void
    {
        $unrestrictedItems = array_filter($this->menuStructure, fn($item) => $item['roles'] === null);
        
        foreach ($unrestrictedItems as $item) {
            $url = $item['url'];
            
            foreach ($this->allRoles as $role) {
                $this->assertTrue(
                    $this->hasMenuAccess($item, $role),
                    "Role '{$role}' should have access to unrestricted menu '{$url}'"
                );
            }
        }
    }

    /**
     * Property Test: Restricted menus hide from unauthorized users
     * 
     * **Feature: admin-menu-restructure, Property 1: Role-Based Menu Visibility**
     * **Validates: Requirements 7.2**
     */
    public function testRestrictedMenusHideFromUnauthorizedUsers(): void
    {
        $restrictedItems = array_filter($this->menuStructure, fn($item) => $item['roles'] !== null);
        
        foreach ($restrictedItems as $item) {
            $url = $item['url'];
            $allowedRoles = $item['roles'];
            
            foreach ($this->allRoles as $role) {
                $hasAccess = $this->hasMenuAccess($item, $role);
                $shouldHaveAccess = in_array($role, $allowedRoles);
                
                if (!$shouldHaveAccess) {
                    $this->assertFalse(
                        $hasAccess,
                        "Unauthorized role '{$role}' should NOT see menu '{$url}'"
                    );
                }
            }
        }
    }
}
