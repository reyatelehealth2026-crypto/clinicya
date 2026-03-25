<?php
/**
 * Odoo Customer Dashboard Service - OPTIMIZED VERSION
 *
 * @version 2.1.0 - OPTIMIZED
 * @updated 2026-03-25 - Query optimization, Redis caching, reduced JSON_EXTRACT usage
 */

require_once __DIR__ . '/OdooAPIClient.php';
require_once __DIR__ . '/OdooAPIPool.php';
require_once __DIR__ . '/RedisCache.php';

class OdooCustomerDashboardService
{
    private $db;
    private $lineAccountId;
    private $odooClient;
    private $redisCache;

    /** Cached schema metadata */
    private static $tableExistsCache = [];
    private static $columnExistsCache = [];

    /** Cache TTL settings */
    private const CACHE_TTL_TIMELINE = 30;      // 30 seconds for timeline
    private const CACHE_TTL_SUMMARY = 60;       // 60 seconds for summary
    private const CACHE_TTL_ORDERS = 30;        // 30 seconds for orders
    private const CACHE_TTL_CREDIT = 60;        // 60 seconds for credit
    private const CACHE_TTL_INVOICES = 30;      // 30 seconds for invoices

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->odooClient = null;
        $this->redisCache = RedisCache::getInstance();

        try {
            $this->odooClient = new OdooAPIClient($db, $lineAccountId);
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService: cannot init OdooAPIClient - ' . $e->getMessage());
        }
    }

    /**
     * Build Customer 360 dashboard with optimized caching
     */
    public function buildByLineUserId($lineUserId, array $options = [])
    {
        $ordersLimit = max(1, min((int) ($options['orders_limit'] ?? 10), 50));
        $invoicesLimit = max(1, min((int) ($options['invoices_limit'] ?? 10), 50));
        $timelineLimit = max(1, min((int) ($options['timeline_limit'] ?? 20), 100));
        $topProducts = max(1, min((int) ($options['top_products'] ?? 5), 20));

        // OPTIMIZED: Check Redis cache first
        $cacheKey = "dashboard:{$lineUserId}:" . md5(serialize($options));
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $dashboard = $this->emptyDashboard($lineUserId);

        $link = $this->getLinkByLineUserId($lineUserId);
        if (!$link) {
            $this->redisCache->set($cacheKey, $dashboard, 10);
            return $dashboard;
        }

        $dashboard['linked'] = true;
        $dashboard['link'] = $link;

        $odooPartnerId = (int) ($link['odoo_partner_id'] ?? 0);
        $odooCustomerCode = trim((string) ($link['odoo_customer_code'] ?? ''));

        // Phase 1: Parallel API calls
        $apiResults = $this->fetchApiDataParallel($lineUserId, $ordersLimit, $invoicesLimit);

        $profile = $apiResults['profile'] ?? null;
        $credit = $apiResults['credit'] ?? null;
        $ordersResult = $apiResults['orders'] ?? null;
        $invoicesResult = $apiResults['invoices'] ?? null;

        foreach (['profile', 'credit', 'orders', 'invoices'] as $key) {
            if (isset($apiResults[$key . '_error'])) {
                $dashboard['warnings'][] = "{$key}_api: " . $apiResults[$key . '_error'];
            }
        }

        // Phase 2: Profile
        if ($profile && is_array($profile)) {
            $dashboard['profile'] = $profile;
        }

        // Phase 3: Credit with caching
        $dashboard['credit'] = $this->getCachedCredit($lineUserId, $odooPartnerId, $odooCustomerCode, $credit, $profile);

        // Phase 4: Orders with caching
        $ordersData = $this->getCachedOrders($lineUserId, $odooPartnerId, $odooCustomerCode, $ordersResult, $ordersLimit);
        $dashboard['orders'] = $ordersData;
        $dashboard['latest_order'] = $this->getLatestOrder($ordersData['recent'], $lineUserId, $odooPartnerId);
        if (!empty($ordersData['fallback'])) {
            $dashboard['warnings'][] = $ordersData['fallback'];
        }

        // Phase 5: Invoices with caching
        $invoicesData = $this->getCachedInvoices($lineUserId, $odooPartnerId, $odooCustomerCode, $invoicesResult, $invoicesLimit);
        $dashboard['invoices'] = $invoicesData;
        if (!empty($invoicesData['fallback'])) {
            $dashboard['warnings'][] = $invoicesData['fallback'];
        }

        // Phase 6: Timeline with optimization
        $timelineBundle = $this->getTimelineAndSummaryOptimized(
            $lineUserId,
            $odooPartnerId,
            $dashboard['latest_order']['order_name'] ?? null,
            $dashboard['latest_order']['order_id'] ?? null,
            $timelineLimit
        );
        $dashboard['timeline'] = $timelineBundle['timeline'];
        $dashboard['webhook_summary'] = $timelineBundle['summary'];

        // Phase 7: Frequent products
        $dashboard['frequent_products'] = $this->buildFrequentProducts($lineUserId, $odooPartnerId, $ordersData['recent'], $topProducts);

        // Cache the full dashboard
        $this->redisCache->set($cacheKey, $dashboard, 30);

        return $dashboard;
    }

    // ========================================================================
    // OPTIMIZED: Cached Data Retrieval
    // ========================================================================

    private function getCachedCredit($lineUserId, $odooPartnerId, $odooCustomerCode, $apiCredit, $profile)
    {
        $cacheKey = "credit:{$lineUserId}:{$odooPartnerId}";
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Try webhook fallback if API credit is empty
        try {
            $creditFromWebhook = $this->getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode);
            if ($creditFromWebhook) {
                $apiCredit = $creditFromWebhook;
            }
        } catch (Exception $e) {
            error_log('Credit webhook fallback error: ' . $e->getMessage());
        }

        $result = $this->normalizeCredit($apiCredit, $profile);
        $this->redisCache->set($cacheKey, $result, self::CACHE_TTL_CREDIT);
        return $result;
    }

    private function getCachedOrders($lineUserId, $odooPartnerId, $odooCustomerCode, $apiResult, $limit)
    {
        $cacheKey = "orders:{$lineUserId}:{$odooPartnerId}:{$limit}";
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $normalized = $this->normalizeOrders($apiResult);
        $orders = $normalized['orders'];
        $total = $normalized['total'];
        $fallback = null;

        // Try webhook fallback if empty
        if (empty($orders)) {
            try {
                $webhookOrders = $this->getOrdersFromWebhookOptimized($lineUserId, $odooPartnerId, $limit, $odooCustomerCode);
                if (!empty($webhookOrders['orders'])) {
                    $orders = $webhookOrders['orders'];
                    $total = $webhookOrders['total'];
                    $fallback = 'orders_fallback: ใช้ข้อมูลจาก webhook logs';
                }
            } catch (Exception $e) {
                error_log('Orders webhook fallback error: ' . $e->getMessage());
            }
        }

        // Try projection table
        if (empty($orders) && $this->tableExists('odoo_order_projection')) {
            $projection = $this->getProjectionOrders($lineUserId, $odooPartnerId, $limit);
            if (!empty($projection)) {
                $orders = $projection;
                $total = count($projection);
            }
        }

        $result = ['total' => $total, 'recent' => $orders, 'fallback' => $fallback];
        $this->redisCache->set($cacheKey, $result, self::CACHE_TTL_ORDERS);
        return $result;
    }

    private function getCachedInvoices($lineUserId, $odooPartnerId, $odooCustomerCode, $apiResult, $limit)
    {
        $cacheKey = "invoices:{$lineUserId}:{$odooPartnerId}:{$limit}";
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Try webhook fallback first
        try {
            $webhookInvoices = $this->getInvoicesFromWebhookOptimized($lineUserId, $odooPartnerId, $limit, $odooCustomerCode);
            if (!empty($webhookInvoices['invoices'])) {
                $result = $this->normalizeInvoices($webhookInvoices);
                $result['fallback'] = 'invoices_fallback: ใช้ข้อมูลจาก webhook logs';
                $this->redisCache->set($cacheKey, $result, self::CACHE_TTL_INVOICES);
                return $result;
            }
        } catch (Exception $e) {
            error_log('Invoices webhook fallback error: ' . $e->getMessage());
        }

        $result = $this->normalizeInvoices($apiResult);
        $this->redisCache->set($cacheKey, $result, self::CACHE_TTL_INVOICES);
        return $result;
    }

    // ========================================================================
    // OPTIMIZED: Timeline with single query and better indexing
    // ========================================================================

    /**
     * OPTIMIZED: Uses virtual columns for faster queries instead of JSON_EXTRACT in WHERE
     */
    private function getTimelineAndSummaryOptimized($lineUserId, $odooPartnerId, $orderName = null, $orderId = null, $limit = 20)
    {
        $cacheKey = "timeline:{$lineUserId}:{$odooPartnerId}:{$limit}";
        $cached = $this->redisCache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $emptySummary = [
            'total' => 0, 'success' => 0, 'failed' => 0,
            'retry' => 0, 'dead_letter' => 0, 'duplicate' => 0,
            'last_event_at' => null
        ];

        if (!$this->tableExists('odoo_webhooks_log')) {
            return ['timeline' => [], 'summary' => $emptySummary];
        }

        // OPTIMIZED: Use indexed columns instead of JSON_EXTRACT in WHERE
        // Use the virtual columns v_customer_id instead of JSON_EXTRACT
        $partnerIdStr = (string) $odooPartnerId;
        
        try {
            // Single optimized query using UNION for different match types
            $sql = "
                SELECT 'summary' as row_type, status, COUNT(*) as cnt, MAX(processed_at) as last_event_at, NULL as id, NULL as event_type, NULL as error_message, NULL as payload
                FROM odoo_webhooks_log
                WHERE (line_user_id = ? OR v_customer_id = ?)
                GROUP BY status
                
                UNION ALL
                
                SELECT 'timeline' as row_type, status, 0 as cnt, processed_at as last_event_at, id, event_type, error_message, payload
                FROM odoo_webhooks_log
                WHERE (line_user_id = ? OR v_customer_id = ?)
                  AND status IN ('success', 'failed', 'retry', 'dead_letter')
                ORDER BY processed_at DESC
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$lineUserId, $partnerIdStr, $lineUserId, $partnerIdStr, (int) $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $timeline = [];
            $summary = $emptySummary;
            $lastEventAt = null;
            $hasErrorCode = $this->hasWebhookColumn('odoo_webhooks_log', 'last_error_code');

            foreach ($rows as $row) {
                if ($row['row_type'] === 'summary') {
                    $status = $row['status'] ?? 'unknown';
                    $count = (int) ($row['cnt'] ?? 0);
                    $summary['total'] += $count;
                    if (isset($summary[$status])) {
                        $summary[$status] = $count;
                    }
                    if (!empty($row['last_event_at']) && ($lastEventAt === null || $row['last_event_at'] > $lastEventAt)) {
                        $lastEventAt = $row['last_event_at'];
                    }
                } else {
                    $payload = json_decode($row['payload'] ?? '{}', true);
                    $timeline[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'event_type' => $row['event_type'] ?? null,
                        'status' => $row['status'] ?? null,
                        'processed_at' => $row['last_event_at'] ?? null,
                        'order_name' => $payload['order_name'] ?? null,
                        'state_display' => $payload['new_state_display'] ?? null,
                        'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
                        'error_message' => $row['error_message'] ?? null,
                        'error_code' => $hasErrorCode ? ($row['last_error_code'] ?? null) : null
                    ];
                }
            }
            
            $summary['last_event_at'] = $lastEventAt;

            $result = ['timeline' => $timeline, 'summary' => $summary];
            $this->redisCache->set($cacheKey, $result, self::CACHE_TTL_TIMELINE);
            return $result;

        } catch (Exception $e) {
            error_log('Timeline optimized query error: ' . $e->getMessage());
            return ['timeline' => [], 'summary' => $emptySummary];
        }
    }

    // ========================================================================
    // OPTIMIZED: Webhook queries using virtual columns
    // ========================================================================

    /**
     * OPTIMIZED: Uses v_customer_id virtual column instead of JSON_EXTRACT
     */
    private function getOrdersFromWebhookOptimized($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        try {
            $partnerIdStr = (string) $odooPartnerId;
            
            // OPTIMIZED: Use virtual columns and proper indexes
            $sql = "
                SELECT DISTINCT
                    COALESCE(v_order_name, extracted_order_id) as order_name,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')) as order_id,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')) as state,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as state_display,
                    v_amount_total as amount_total,
                    processed_at as date_order
                FROM odoo_webhooks_log
                WHERE status IN ('success', 'done', 'ok')
                  AND (line_user_id = ? OR v_customer_id = ?)
                  AND (v_order_name IS NOT NULL OR extracted_order_id IS NOT NULL)
                ORDER BY processed_at DESC
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$lineUserId, $partnerIdStr, (int) $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $seen = [];
            $orders = [];
            foreach ($rows as $row) {
                $key = $row['order_id'] ?: $row['order_name'];
                if ($key === null || $key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $orders[] = [
                    'id' => $row['order_id'],
                    'name' => $row['order_name'],
                    'order_id' => $row['order_id'],
                    'order_name' => $row['order_name'],
                    'state' => $row['state'],
                    'state_display' => $row['state_display'],
                    'amount_total' => $row['amount_total'] !== null ? (float) $row['amount_total'] : null,
                    'date_order' => $row['date_order'],
                    'order_lines' => []
                ];
            }

            return ['orders' => $orders, 'total' => count($orders)];
        } catch (Exception $e) {
            error_log('Orders from webhook optimized error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * OPTIMIZED: Invoices using virtual columns
     */
    private function getInvoicesFromWebhookOptimized($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        try {
            $partnerIdStr = (string) $odooPartnerId;
            
            $sql = "
                SELECT DISTINCT
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')) as invoice_number,
                    v_order_name as order_name,
                    v_amount_total as amount_total,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')) as amount_residual,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_date')) as invoice_date,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')) as due_date,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')) as state,
                    processed_at
                FROM odoo_webhooks_log
                WHERE status IN ('success', 'done', 'ok')
                  AND (line_user_id = ? OR v_customer_id = ?)
                  AND (event_type LIKE '%invoice%' OR JSON_EXTRACT(payload, '$.invoice_number') IS NOT NULL)
                ORDER BY processed_at DESC
                LIMIT ?
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$lineUserId, $partnerIdStr, (int) $limit]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $invoices = [];
            foreach ($rows as $row) {
                $state = strtolower((string) ($row['state'] ?? ''));
                $invoices[] = [
                    'invoice_number' => $row['invoice_number'] ?: ($row['order_name'] ?: '-'),
                    'name' => $row['invoice_number'] ?: ($row['order_name'] ?: '-'),
                    'order_name' => $row['order_name'],
                    'amount_total' => $row['amount_total'] ? (float) $row['amount_total'] : null,
                    'amount_residual' => $row['amount_residual'] !== null && $row['amount_residual'] !== ''
                        ? (float) $row['amount_residual']
                        : ($row['amount_total'] ? (float) $row['amount_total'] : 0.0),
                    'invoice_date' => $row['invoice_date'],
                    'due_date' => $row['due_date'],
                    'state' => $row['state'],
                    'state_display' => $row['state'] ?: '-',
                    'is_overdue' => !empty($row['due_date'])
                        && !in_array($state, ['paid', 'cancel', 'cancelled'], true)
                        && strtotime($row['due_date']) < time()
                ];
            }

            return ['invoices' => $invoices, 'total' => count($invoices)];
        } catch (Exception $e) {
            error_log('Invoices from webhook optimized error: ' . $e->getMessage());
            return null;
        }
    }

    // ========================================================================
    // Parallel API Fetch (unchanged from original)
    // ========================================================================

    private function fetchApiDataParallel(string $lineUserId, int $ordersLimit, int $invoicesLimit): array
    {
        $results = [];

        if (!$this->odooClient) {
            return $results;
        }

        try {
            $pool = new OdooAPIPool(
                ODOO_API_KEY,
                ODOO_API_BASE_URL,
                15,
                5
            );

            $pool->add('profile', '/reya/user/profile', ['line_user_id' => $lineUserId]);
            $pool->add('credit', '/reya/credit-status', ['line_user_id' => $lineUserId]);
            $pool->add('orders', '/reya/orders', ['line_user_id' => $lineUserId, 'limit' => $ordersLimit]);
            $pool->add('invoices', '/reya/invoices', ['line_user_id' => $lineUserId, 'limit' => $invoicesLimit]);

            $poolResults = $pool->execute();

            foreach (['profile', 'credit', 'orders', 'invoices'] as $key) {
                $r = $poolResults[$key] ?? null;
                if ($r && ($r['success'] ?? false)) {
                    $results[$key] = $r['data'] ?? [];
                } else {
                    $results[$key] = null;
                    if ($r && isset($r['error'])) {
                        $results[$key . '_error'] = $r['error'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Parallel fetch failed: ' . $e->getMessage());
        }

        return $results;
    }

    // ========================================================================
    // Data Normalization (unchanged)
    // ========================================================================

    private function emptyDashboard(string $lineUserId): array
    {
        return [
            'line_user_id' => $lineUserId,
            'generated_at' => date('c'),
            'linked' => false,
            'link' => null,
            'profile' => null,
            'credit' => [
                'credit_limit' => null,
                'credit_used' => null,
                'credit_remaining' => null,
                'total_due' => null,
                'overdue_amount' => null
            ],
            'latest_order' => null,
            'orders' => ['total' => 0, 'recent' => []],
            'timeline' => [],
            'frequent_products' => [],
            'invoices' => ['total' => 0, 'recent' => []],
            'webhook_summary' => [
                'total' => 0, 'success' => 0, 'failed' => 0,
                'retry' => 0, 'dead_letter' => 0, 'duplicate' => 0,
                'last_event_at' => null
            ],
            'warnings' => []
        ];
    }

    private function normalizeCredit($credit, $profile)
    {
        $credit = is_array($credit) ? $credit : [];
        $profile = is_array($profile) ? $profile : [];

        $creditLimit = $credit['credit_limit'] ?? $profile['credit_limit'] ?? null;
        $creditUsed = $credit['credit_used'] ?? null;
        $creditRemaining = $credit['credit_remaining'] ?? null;

        if ($creditUsed === null && $creditLimit !== null && $creditRemaining !== null) {
            $creditUsed = (float) $creditLimit - (float) $creditRemaining;
        }

        if ($creditRemaining === null && $creditLimit !== null && $creditUsed !== null) {
            $creditRemaining = (float) $creditLimit - (float) $creditUsed;
        }

        return [
            'credit_limit' => $creditLimit !== null ? (float) $creditLimit : null,
            'credit_used' => $creditUsed !== null ? (float) $creditUsed : null,
            'credit_remaining' => $creditRemaining !== null ? (float) $creditRemaining : null,
            'total_due' => isset($credit['total_due'])
                ? (float) $credit['total_due']
                : (isset($profile['total_due']) ? (float) $profile['total_due'] : null),
            'overdue_amount' => isset($credit['overdue_amount']) ? (float) $credit['overdue_amount'] : null
        ];
    }

    private function normalizeOrders($result)
    {
        if (!is_array($result)) {
            return ['orders' => [], 'total' => 0];
        }

        $extractors = [
            fn($r) => isset($r['orders']) && is_array($r['orders'])
                ? [$r['orders'], (int) ($r['total'] ?? $r['total_count'] ?? count($r['orders']))]
                : null,
            fn($r) => isset($r['data']['orders']) && is_array($r['data']['orders'])
                ? [$r['data']['orders'], (int) ($r['meta']['total'] ?? $r['data']['total'] ?? count($r['data']['orders']))]
                : null,
            fn($r) => isset($r['data']) && is_array($r['data']) && isset($r['data'][0])
                ? [$r['data'], count($r['data'])]
                : null,
            fn($r) => isset($r[0]) ? [$r, count($r)] : null,
        ];

        foreach ($extractors as $extractor) {
            $pair = $extractor($result);
            if ($pair !== null) {
                return ['orders' => $pair[0], 'total' => $pair[1]];
            }
        }

        return ['orders' => [], 'total' => 0];
    }

    private function normalizeInvoices($result)
    {
        $invoices = [];
        $total = 0;

        if (!is_array($result)) {
            return ['total' => 0, 'recent' => []];
        }

        if (isset($result['invoices']) && is_array($result['invoices'])) {
            $invoices = $result['invoices'];
            $total = (int) ($result['total'] ?? $result['total_count'] ?? count($invoices));
        } elseif (isset($result['data']['invoices']) && is_array($result['data']['invoices'])) {
            $invoices = $result['data']['invoices'];
            $total = (int) ($result['meta']['total'] ?? $result['data']['total'] ?? count($invoices));
        } elseif (isset($result['data']) && is_array($result['data']) && isset($result['data'][0])) {
            $invoices = $result['data'];
            $total = count($invoices);
        } elseif (isset($result[0])) {
            $invoices = $result;
            $total = count($invoices);
        }

        $normalized = [];
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) continue;

            $amountTotal = isset($invoice['amount_total']) ? (float) $invoice['amount_total'] : 0.0;
            $amountResidual = $invoice['amount_residual'] ?? $amountTotal;
            $state = strtolower((string) ($invoice['state'] ?? ''));
            $isOverdue = isset($invoice['is_overdue'])
                ? (bool) $invoice['is_overdue']
                : (!empty($invoice['due_date']) && !in_array($state, ['paid', 'cancel', 'cancelled'], true) && strtotime($invoice['due_date']) < time());

            $normalized[] = [
                'amount_total' => $amountTotal,
                'amount_residual' => (float) $amountResidual,
                'is_overdue' => $isOverdue
            ] + $invoice;
        }

        return ['total' => $total, 'recent' => $normalized];
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function getLatestOrder(array $orders, $lineUserId, $odooPartnerId)
    {
        if (!empty($orders)) {
            $first = $orders[0];
            return [
                'order_id' => $first['id'] ?? $first['order_id'] ?? null,
                'order_name' => $first['name'] ?? $first['order_name'] ?? null,
                'state' => $first['state'] ?? $first['new_state'] ?? null,
                'state_display' => $first['state_display'] ?? $first['new_state_display'] ?? null,
                'amount_total' => isset($first['amount_total']) ? (float) $first['amount_total'] : null,
                'date_order' => $first['date_order'] ?? $first['create_date'] ?? null,
                'order_lines' => $first['order_lines'] ?? $first['order_line'] ?? []
            ];
        }

        if (!$this->tableExists('odoo_order_projection')) {
            return null;
        }

        try {
            $stmt = $this->db->prepare('
                SELECT order_id, order_name, latest_state, latest_state_display, amount_total, last_webhook_at
                FROM odoo_order_projection
                WHERE line_user_id = ? OR odoo_partner_id = ?
                ORDER BY last_webhook_at DESC
                LIMIT 1
            ');
            $stmt->execute([$lineUserId, (int) $odooPartnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) return null;

            return [
                'order_id' => $row['order_id'] ?? null,
                'order_name' => $row['order_name'] ?? null,
                'state' => $row['latest_state'] ?? null,
                'state_display' => $row['latest_state_display'] ?? null,
                'amount_total' => isset($row['amount_total']) ? (float) $row['amount_total'] : null,
                'date_order' => $row['last_webhook_at'] ?? null,
                'order_lines' => []
            ];
        } catch (Exception $e) {
            error_log('Latest order error: ' . $e->getMessage());
            return null;
        }
    }

    private function getProjectionOrders($lineUserId, $odooPartnerId, $limit)
    {
        try {
            $stmt = $this->db->prepare('
                SELECT order_id as id, order_name as name, latest_state as state, latest_state_display as state_display,
                       amount_total, last_webhook_at as date_order
                FROM odoo_order_projection
                WHERE line_user_id = ? OR odoo_partner_id = ?
                ORDER BY last_webhook_at DESC
                LIMIT ?
            ');
            $stmt->execute([$lineUserId, (int) $odooPartnerId, (int) $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Projection orders error: ' . $e->getMessage());
            return [];
        }
    }

    private function getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        try {
            $partnerIdStr = (string) $odooPartnerId;
            
            $stmt = $this->db->prepare("
                SELECT 
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.credit_limit')) as credit_limit,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.total_due')) as total_due,
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.overdue_amount')) as overdue_amount
                FROM odoo_webhooks_log
                WHERE status IN ('success', 'done', 'ok')
                  AND (line_user_id = ? OR v_customer_id = ?)
                  AND JSON_EXTRACT(payload, '$.customer.credit_limit') IS NOT NULL
                ORDER BY processed_at DESC
                LIMIT 1
            ");
            $stmt->execute([$lineUserId, $partnerIdStr]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return null;

            return [
                'credit_limit' => $row['credit_limit'] ? (float) $row['credit_limit'] : null,
                'total_due' => $row['total_due'] ? (float) $row['total_due'] : null,
                'overdue_amount' => $row['overdue_amount'] ? (float) $row['overdue_amount'] : null
            ];
        } catch (Exception $e) {
            error_log('Credit from webhook error: ' . $e->getMessage());
            return null;
        }
    }

    private function buildFrequentProducts($lineUserId, $odooPartnerId, array $orders, $topProducts)
    {
        $stats = [];

        foreach ($orders as $order) {
            $orderLines = $order['order_lines'] ?? $order['order_line'] ?? $order['lines'] ?? [];
            if (!is_array($orderLines)) continue;

            foreach ($orderLines as $line) {
                if (!is_array($line)) continue;

                $name = trim((string) ($line['product_name'] ?? $line['name'] ?? $line['product']['name'] ?? ''));
                if ($name === '') continue;

                $qty = (float) ($line['product_uom_qty'] ?? $line['qty'] ?? $line['quantity'] ?? 1);
                $amount = (float) ($line['price_subtotal'] ?? ($qty * (float) ($line['price_unit'] ?? 0)));

                if (!isset($stats[$name])) {
                    $stats[$name] = ['product_name' => $name, 'qty' => 0, 'amount' => 0];
                }
                $stats[$name]['qty'] += $qty;
                $stats[$name]['amount'] += $amount;
            }
        }

        if (!empty($stats)) {
            usort($stats, fn($a, $b) => $b['amount'] <=> $a['amount'] ?: $b['qty'] <=> $a['qty']);
            return array_slice(array_values($stats), 0, $topProducts);
        }

        if (!$this->tableExists('odoo_customer_product_stats')) {
            return [];
        }

        try {
            $stmt = $this->db->prepare('
                SELECT product_name, qty_90d as qty, amount_90d as amount, last_purchased_at
                FROM odoo_customer_product_stats
                WHERE line_user_id = ? OR odoo_partner_id = ?
                ORDER BY amount_90d DESC, qty_90d DESC
                LIMIT ?
            ');
            $stmt->execute([$lineUserId, (int) $odooPartnerId, (int) $topProducts]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Frequent products error: ' . $e->getMessage());
            return [];
        }
    }

    private function getLinkByLineUserId($lineUserId)
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM odoo_line_users WHERE line_user_id = ? LIMIT 1');
            $stmt->execute([$lineUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('Link lookup error: ' . $e->getMessage());
            return null;
        }
    }

    private function tableExists($table)
    {
        if (isset(self::$tableExistsCache[$table])) {
            return self::$tableExistsCache[$table];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            self::$tableExistsCache[$table] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            self::$tableExistsCache[$table] = false;
        }

        return self::$tableExistsCache[$table];
    }

    private function hasWebhookColumn($table, $column)
    {
        $key = "{$table}.{$column}";
        if (isset(self::$columnExistsCache[$key])) {
            return self::$columnExistsCache[$key];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            self::$columnExistsCache[$key] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            self::$columnExistsCache[$key] = false;
        }

        return self::$columnExistsCache[$key];
    }
}
