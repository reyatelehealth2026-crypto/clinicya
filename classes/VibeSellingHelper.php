<?php
/**
 * Vibe Selling OS v2 Helper Class
 * 
 * Provides utility functions for checking v2 status and graceful fallback
 * 
 * Requirements: 10.6 - Graceful fallback to v1 when disabled
 * 
 * @package VibeSelling
 * @version 2.0
 */

class VibeSellingHelper
{
    private static $instance = null;
    private $db;
    private $settings = [];
    private $settingsLoaded = false;
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Get singleton instance
     * 
     * @param PDO $db Database connection
     * @return VibeSellingHelper
     */
    public static function getInstance(PDO $db): VibeSellingHelper
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }
    
    /**
     * Load settings from database
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return void
     */
    private function loadSettings(?int $lineAccountId = null): void
    {
        if ($this->settingsLoaded) {
            return;
        }
        
        try {
            // Check if table exists
            $tableExists = $this->db->query("SHOW TABLES LIKE 'vibe_selling_settings'")->rowCount() > 0;
            
            if (!$tableExists) {
                $this->settings = [
                    'v2_enabled' => '0',
                    'auto_switch_on_error' => '1',
                    'show_v2_badge' => '1'
                ];
                $this->settingsLoaded = true;
                return;
            }
            
            $stmt = $this->db->prepare("
                SELECT setting_key, setting_value FROM vibe_selling_settings 
                WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL)
            ");
            $stmt->execute([$lineAccountId, $lineAccountId]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Set defaults if not found
            if (!isset($this->settings['v2_enabled'])) {
                $this->settings['v2_enabled'] = '0';
            }
            if (!isset($this->settings['auto_switch_on_error'])) {
                $this->settings['auto_switch_on_error'] = '1';
            }
            if (!isset($this->settings['show_v2_badge'])) {
                $this->settings['show_v2_badge'] = '1';
            }
            
            $this->settingsLoaded = true;
        } catch (PDOException $e) {
            error_log("VibeSellingHelper: Error loading settings - " . $e->getMessage());
            $this->settings = [
                'v2_enabled' => '0',
                'auto_switch_on_error' => '1',
                'show_v2_badge' => '1'
            ];
            $this->settingsLoaded = true;
        }
    }
    
    /**
     * Check if Vibe Selling OS v2 is enabled
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function isV2Enabled(?int $lineAccountId = null): bool
    {
        $this->loadSettings($lineAccountId);
        return $this->settings['v2_enabled'] === '1';
    }
    
    /**
     * Check if performance upgrade features are enabled
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function isPerformanceUpgradeEnabled(?int $lineAccountId = null): bool
    {
        $this->loadSettings($lineAccountId);
        return ($this->settings['performance_upgrade_enabled'] ?? '0') === '1';
    }
    
    /**
     * Check if WebSocket real-time updates are enabled
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function isWebSocketEnabled(?int $lineAccountId = null): bool
    {
        $this->loadSettings($lineAccountId);
        return ($this->settings['websocket_enabled'] ?? '0') === '1';
    }
    
    /**
     * Check if user is in A/B test group for performance features
     * Uses consistent hashing to ensure same user always gets same result
     * 
     * @param int $userId User ID
     * @param int $percentage Percentage of users to include (0-100)
     * @return bool
     */
    public function isInPerformanceTestGroup(int $userId, int $percentage = 10): bool
    {
        if ($percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }
        
        // Use consistent hashing to determine group
        $hash = crc32("performance_test_{$userId}");
        $bucket = $hash % 100;
        
        return $bucket < $percentage;
    }
    
    /**
     * Check if performance features should be used for this user
     * Combines feature flag and A/B test logic
     * 
     * @param int $userId User ID
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function shouldUsePerformanceFeatures(int $userId, ?int $lineAccountId = null): bool
    {
        // Check if performance upgrade is enabled
        if (!$this->isPerformanceUpgradeEnabled($lineAccountId)) {
            return false;
        }
        
        // Get rollout percentage
        $this->loadSettings($lineAccountId);
        $rolloutPercentage = intval($this->settings['performance_rollout_percentage'] ?? '100');
        
        // Check if user is in test group
        return $this->isInPerformanceTestGroup($userId, $rolloutPercentage);
    }
    
    /**
     * Check if auto-switch on error is enabled
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function isAutoSwitchEnabled(?int $lineAccountId = null): bool
    {
        $this->loadSettings($lineAccountId);
        return $this->settings['auto_switch_on_error'] === '1';
    }
    
    /**
     * Check if v2 badge should be shown
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function shouldShowV2Badge(?int $lineAccountId = null): bool
    {
        $this->loadSettings($lineAccountId);
        return $this->settings['show_v2_badge'] === '1' && $this->isV2Enabled($lineAccountId);
    }
    
    /**
     * Get the appropriate inbox URL based on v2 status
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return string
     */
    public function getInboxUrl(?int $lineAccountId = null): string
    {
        return $this->isV2Enabled($lineAccountId) ? 'inbox-v2.php' : 'inbox.php';
    }
    
    /**
     * Check if v2 services are available
     * 
     * @return bool
     */
    public function areServicesAvailable(): bool
    {
        $requiredServices = [
            'classes/DrugPricingEngineService.php',
            'classes/CustomerHealthEngineService.php',
            'classes/PharmacyImageAnalyzerService.php',
            'classes/PharmacyGhostDraftService.php'
        ];
        
        foreach ($requiredServices as $servicePath) {
            $fullPath = __DIR__ . '/../' . basename($servicePath);
            if (!file_exists($fullPath)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if v2 database tables exist
     * 
     * @return bool
     */
    public function areTablesAvailable(): bool
    {
        $requiredTables = [
            'customer_health_profiles',
            'symptom_analysis_cache',
            'drug_recognition_cache',
            'prescription_ocr_results',
            'pharmacy_ghost_learning',
            'consultation_stages'
        ];
        
        try {
            foreach ($requiredTables as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$table}'")->rowCount();
                if ($result === 0) {
                    return false;
                }
            }
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Check if v2 is fully ready (enabled + services + tables)
     * 
     * @param int|null $lineAccountId LINE account ID
     * @return bool
     */
    public function isV2Ready(?int $lineAccountId = null): bool
    {
        return $this->isV2Enabled($lineAccountId) 
            && $this->areServicesAvailable() 
            && $this->areTablesAvailable();
    }
    
    /**
     * Get setting value
     * 
     * @param string $key Setting key
     * @param int|null $lineAccountId LINE account ID
     * @return string|null
     */
    public function getSetting(string $key, ?int $lineAccountId = null): ?string
    {
        $this->loadSettings($lineAccountId);
        return $this->settings[$key] ?? null;
    }
    
    /**
     * Redirect to appropriate inbox based on v2 status
     * Useful for graceful fallback
     * 
     * @param int|null $lineAccountId LINE account ID
     * @param bool $checkReady Also check if v2 is fully ready
     * @return void
     */
    public function redirectToInbox(?int $lineAccountId = null, bool $checkReady = true): void
    {
        $useV2 = $checkReady ? $this->isV2Ready($lineAccountId) : $this->isV2Enabled($lineAccountId);
        $url = $useV2 ? 'inbox-v2.php' : 'inbox.php';
        
        // Preserve query string
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        if (!empty($queryString)) {
            $url .= '?' . $queryString;
        }
        
        header("Location: {$url}");
        exit;
    }
    
    /**
     * Handle v2 error with graceful fallback
     * 
     * @param Exception $e The exception that occurred
     * @param int|null $lineAccountId LINE account ID
     * @return bool True if fallback was triggered
     */
    public function handleV2Error(Exception $e, ?int $lineAccountId = null): bool
    {
        error_log("VibeSellingHelper: V2 Error - " . $e->getMessage());
        
        if ($this->isAutoSwitchEnabled($lineAccountId)) {
            // Log the fallback
            error_log("VibeSellingHelper: Auto-switching to v1 due to error");
            
            // Redirect to v1
            $queryString = $_SERVER['QUERY_STRING'] ?? '';
            $url = 'inbox.php';
            if (!empty($queryString)) {
                $url .= '?' . $queryString;
            }
            
            header("Location: {$url}");
            exit;
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Reset singleton instance (useful for testing)
     * 
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
