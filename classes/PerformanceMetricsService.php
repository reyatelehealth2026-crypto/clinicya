<?php
/**
 * Performance Metrics Service
 * 
 * Tracks and stores performance metrics for inbox-v2 operations
 * Supports monitoring of page load, conversation switching, message rendering, and API calls
 * 
 * Requirements: 12.1, 12.2, 12.3, 12.4, 12.5
 * 
 * @version 1.0
 */

class PerformanceMetricsService {
    private $db;
    private $lineAccountId;
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID for multi-tenant tracking
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Log a performance metric
     * 
     * @param string $metricType Type of metric: 'page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance', 'cache_hit', 'cache_miss'
     * @param int $durationMs Duration in milliseconds
     * @param string|null $userAgent User agent string
     * @param array|null $operationDetails Additional context about the operation (stored as JSON)
     * @return bool Success status
     * 
     * Requirements: 12.1, 12.2, 12.3, 12.4
     */
    public function logMetric($metricType, $durationMs, $userAgent = null, $operationDetails = null) {
        try {
            // Validate metric type
            $validTypes = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance', 'cache_hit', 'cache_miss'];
            if (!in_array($metricType, $validTypes)) {
                error_log("PerformanceMetricsService: Invalid metric type: $metricType");
                return false;
            }
            
            // Validate duration
            if (!is_numeric($durationMs) || $durationMs < 0) {
                error_log("PerformanceMetricsService: Invalid duration: $durationMs");
                return false;
            }
            
            // Convert operation details to JSON if provided
            $detailsJson = null;
            if ($operationDetails !== null) {
                $detailsJson = json_encode($operationDetails);
                if ($detailsJson === false) {
                    error_log("PerformanceMetricsService: Failed to encode operation details");
                    $detailsJson = null;
                }
            }
            
            // Insert metric
            $stmt = $this->db->prepare("
                INSERT INTO performance_metrics 
                (line_account_id, metric_type, duration_ms, user_agent, operation_details, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $result = $stmt->execute([
                $this->lineAccountId,
                $metricType,
                intval($durationMs),
                $userAgent,
                $detailsJson
            ]);
            
            // Log warning if performance threshold exceeded (Requirements: 12.5)
            $this->checkPerformanceThreshold($metricType, $durationMs, $operationDetails);
            
            return $result;
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Database error - " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if performance threshold is exceeded and log warning
     * 
     * @param string $metricType Type of metric
     * @param int $durationMs Duration in milliseconds
     * @param array|null $operationDetails Operation details for context
     * 
     * Requirements: 12.5
     */
    private function checkPerformanceThreshold($metricType, $durationMs, $operationDetails) {
        $thresholds = [
            'page_load' => 2000,           // 2 seconds
            'conversation_switch' => 1000,  // 1 second
            'message_render' => 200,        // 200ms
            'api_call' => 500,              // 500ms
            'scroll_performance' => 17      // ~60fps = 16.67ms per frame
        ];
        
        if (isset($thresholds[$metricType]) && $durationMs > $thresholds[$metricType]) {
            $context = '';
            if ($operationDetails) {
                $context = ' - Details: ' . json_encode($operationDetails);
            }
            
            error_log(sprintf(
                "PERFORMANCE WARNING: %s exceeded threshold (%dms > %dms)%s",
                $metricType,
                $durationMs,
                $thresholds[$metricType],
                $context
            ));
        }
    }
    
    /**
     * Get performance metrics for a specific type and date range
     * 
     * @param string $metricType Type of metric (optional, null for all types)
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @param int|null $limit Maximum number of records to return
     * @return array Array of metrics
     * 
     * Requirements: 12.1
     */
    public function getMetrics($metricType = null, $startDate = null, $endDate = null, $limit = null) {
        try {
            $query = "SELECT * FROM performance_metrics WHERE 1=1";
            $params = [];
            
            // Filter by LINE account if set
            if ($this->lineAccountId !== null) {
                $query .= " AND line_account_id = ?";
                $params[] = $this->lineAccountId;
            }
            
            // Filter by metric type
            if ($metricType !== null) {
                $query .= " AND metric_type = ?";
                $params[] = $metricType;
            }
            
            // Filter by date range
            if ($startDate !== null) {
                $query .= " AND DATE(created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate !== null) {
                $query .= " AND DATE(created_at) <= ?";
                $params[] = $endDate;
            }
            
            // Order by most recent first
            $query .= " ORDER BY created_at DESC";
            
            // Apply limit
            if ($limit !== null && is_numeric($limit)) {
                $query .= " LIMIT " . intval($limit);
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Error fetching metrics - " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get aggregated statistics for a metric type
     * 
     * @param string $metricType Type of metric
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return array Statistics including average, min, max, count, percentiles
     * 
     * Requirements: 12.1
     */
    public function getMetricStats($metricType, $startDate = null, $endDate = null) {
        try {
            $query = "
                SELECT 
                    COUNT(*) as count,
                    AVG(duration_ms) as average,
                    MIN(duration_ms) as min,
                    MAX(duration_ms) as max
                FROM performance_metrics 
                WHERE metric_type = ?
            ";
            $params = [$metricType];
            
            // Filter by LINE account if set
            if ($this->lineAccountId !== null) {
                $query .= " AND line_account_id = ?";
                $params[] = $this->lineAccountId;
            }
            
            // Filter by date range
            if ($startDate !== null) {
                $query .= " AND DATE(created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate !== null) {
                $query .= " AND DATE(created_at) <= ?";
                $params[] = $endDate;
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate percentiles (p50, p95, p99)
            if ($stats['count'] > 0) {
                $percentiles = $this->calculatePercentiles($metricType, $startDate, $endDate);
                $stats = array_merge($stats, $percentiles);
            } else {
                $stats['p50'] = 0;
                $stats['p95'] = 0;
                $stats['p99'] = 0;
            }
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Error calculating stats - " . $e->getMessage());
            return [
                'count' => 0,
                'average' => 0,
                'min' => 0,
                'max' => 0,
                'p50' => 0,
                'p95' => 0,
                'p99' => 0
            ];
        }
    }
    
    /**
     * Calculate percentiles for a metric type
     * 
     * @param string $metricType Type of metric
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return array Percentiles (p50, p95, p99)
     */
    private function calculatePercentiles($metricType, $startDate = null, $endDate = null) {
        try {
            $query = "
                SELECT duration_ms
                FROM performance_metrics 
                WHERE metric_type = ?
            ";
            $params = [$metricType];
            
            // Filter by LINE account if set
            if ($this->lineAccountId !== null) {
                $query .= " AND line_account_id = ?";
                $params[] = $this->lineAccountId;
            }
            
            // Filter by date range
            if ($startDate !== null) {
                $query .= " AND DATE(created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate !== null) {
                $query .= " AND DATE(created_at) <= ?";
                $params[] = $endDate;
            }
            
            $query .= " ORDER BY duration_ms ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $durations = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $count = count($durations);
            if ($count === 0) {
                return ['p50' => 0, 'p95' => 0, 'p99' => 0];
            }
            
            // Calculate percentile indices
            $p50Index = (int) ceil($count * 0.50) - 1;
            $p95Index = (int) ceil($count * 0.95) - 1;
            $p99Index = (int) ceil($count * 0.99) - 1;
            
            return [
                'p50' => $durations[$p50Index],
                'p95' => $durations[$p95Index],
                'p99' => $durations[$p99Index]
            ];
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Error calculating percentiles - " . $e->getMessage());
            return ['p50' => 0, 'p95' => 0, 'p99' => 0];
        }
    }
    
    /**
     * Get error rate for a specific metric type
     * 
     * @param string $metricType Type of metric
     * @param int $errorThreshold Duration in ms above which is considered an error
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return float Error rate as percentage (0-100)
     * 
     * Requirements: 12.1
     */
    public function getErrorRate($metricType, $errorThreshold, $startDate = null, $endDate = null) {
        try {
            $query = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN duration_ms > ? THEN 1 ELSE 0 END) as errors
                FROM performance_metrics 
                WHERE metric_type = ?
            ";
            $params = [$errorThreshold, $metricType];
            
            // Filter by LINE account if set
            if ($this->lineAccountId !== null) {
                $query .= " AND line_account_id = ?";
                $params[] = $this->lineAccountId;
            }
            
            // Filter by date range
            if ($startDate !== null) {
                $query .= " AND DATE(created_at) >= ?";
                $params[] = $startDate;
            }
            
            if ($endDate !== null) {
                $query .= " AND DATE(created_at) <= ?";
                $params[] = $endDate;
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] == 0) {
                return 0.0;
            }
            
            return ($result['errors'] / $result['total']) * 100;
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Error calculating error rate - " . $e->getMessage());
            return 0.0;
        }
    }
    
    /**
     * Get all metric types with their statistics
     * 
     * @param string|null $startDate Start date (Y-m-d format)
     * @param string|null $endDate End date (Y-m-d format)
     * @return array Array of metric types with their statistics
     * 
     * Requirements: 12.1
     */
    public function getAllMetricStats($startDate = null, $endDate = null) {
        $metricTypes = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance'];
        $results = [];
        
        foreach ($metricTypes as $type) {
            $results[$type] = $this->getMetricStats($type, $startDate, $endDate);
        }
        
        return $results;
    }
    
    /**
     * Clean up old metrics (for maintenance)
     * 
     * @param int $daysToKeep Number of days of metrics to keep
     * @return int Number of records deleted
     */
    public function cleanupOldMetrics($daysToKeep = 30) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM performance_metrics 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            return $stmt->rowCount();
            
        } catch (PDOException $e) {
            error_log("PerformanceMetricsService: Error cleaning up metrics - " . $e->getMessage());
            return 0;
        }
    }
}
