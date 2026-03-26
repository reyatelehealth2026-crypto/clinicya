<?php
/**
 * Odoo Webhook Handler
 * 
 * Processes webhooks from Odoo ERP and sends LINE notifications.
 * Includes signature verification, idempotency checking, and event routing.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

class OdooWebhookHandler
{
    private $db;
    private $lineAPI;
    private $webhookSecret;
    private $currentEvent = null;
    private $currentDeliveryId = null;
    private $webhookColumns = null;
    private $webhookStatusEnum = null;
    private $tableExistence = [];

    private const RETRY_LIMIT = 3;

    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->webhookSecret = ODOO_WEBHOOK_SECRET;

        if (empty($this->webhookSecret)) {
            error_log('WARNING: ODOO_WEBHOOK_SECRET is not set');
        }
    }

    /**
     * Verify webhook signature using HMAC-SHA256
     * 
     * @param string $payload Request body (JSON string)
     * @param string $signature X-Odoo-Signature header
     * @param int $timestamp X-Odoo-Timestamp header
     * @param array $meta Additional context for structured logging (delivery/event/headers)
     * @return bool True if signature is valid
     */
    public function verifySignature($payload, $signature, $timestamp, array $meta = [])
    {
        if (empty($this->webhookSecret)) {
            error_log('Cannot verify signature: ODOO_WEBHOOK_SECRET not set');
            return false;
        }

        $meta = is_array($meta) ? $meta : [];
        $timestampInt = (int) $timestamp;
        $now = time();
        $deltaSeconds = $now - $timestampInt;
        $absDelta = abs($deltaSeconds);

        $tolerance = defined('ODOO_WEBHOOK_TOLERANCE_SECONDS')
            ? max(0, (int) ODOO_WEBHOOK_TOLERANCE_SECONDS)
            : 300;
        $legacyDriftEnabled = defined('ODOO_WEBHOOK_ALLOW_LEGACY_DRIFT')
            ? (bool) ODOO_WEBHOOK_ALLOW_LEGACY_DRIFT
            : false;
        $legacyDriftSeconds = defined('ODOO_WEBHOOK_LEGACY_DRIFT_SECONDS')
            ? (int) ODOO_WEBHOOK_LEGACY_DRIFT_SECONDS
            : 0;
        $legacyDriftTolerance = defined('ODOO_WEBHOOK_LEGACY_DRIFT_TOLERANCE')
            ? max(0, (int) ODOO_WEBHOOK_LEGACY_DRIFT_TOLERANCE)
            : 60;

        $context = $this->buildSignatureLogContext(
            $signature,
            $timestampInt,
            $meta,
            $deltaSeconds,
            $tolerance,
            $legacyDriftSeconds,
            $legacyDriftTolerance
        );

        $timestampValid = $absDelta <= $tolerance;
        $legacyDriftAccepted = false;

        if (!$timestampValid && $legacyDriftEnabled && $legacyDriftSeconds !== 0) {
            $legacyDelta = abs($absDelta - abs($legacyDriftSeconds));
            if ($legacyDelta <= $legacyDriftTolerance) {
                $legacyDriftAccepted = true;
                $this->logSignatureEvent('legacy_drift_window_hit', array_merge($context, [
                    'legacy_window_delta' => $legacyDelta
                ]));
            }
        }

        if (!$timestampValid && !$legacyDriftAccepted) {
            $this->logSignatureEvent('timestamp_expired', $context);
            error_log('Webhook timestamp expired: ' . $absDelta . ' seconds old');
            return false;
        }

        // Normalize signature format (remove any whitespace)
        $signature = trim($signature);

        // v11.0.1.2.3: Verify HMAC-SHA256 signature
        // Primary format (Odoo v11.0.1.2.3): sha256=hash_hmac('sha256', $payload, $secret)
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->webhookSecret);

        if (defined('ODOO_WEBHOOK_SIGNATURE_DEBUG') && ODOO_WEBHOOK_SIGNATURE_DEBUG) {
            error_log('Signature Debug:');
            error_log('  Secret: ' . substr($this->webhookSecret, 0, 10) . '...');
            error_log('  Payload length: ' . strlen($payload));
            error_log('  Expected: ' . substr($expectedSignature, 0, 30) . '...');
            error_log('  Received: ' . substr($signature, 0, 30) . '...');
            error_log('  Payload preview: ' . substr($payload, 0, 120));
        }

        if (hash_equals($signature, $expectedSignature)) {
            if ($legacyDriftAccepted) {
                $this->logSignatureEvent('payload_signature_accepted_with_drift', $context);
            }
            return true;
        }

        // Fallback format (legacy): sha256=hash_hmac('sha256', timestamp.payload, $secret)
        $legacyData = $timestampInt . '.' . $payload;
        $legacySignature = 'sha256=' . hash_hmac('sha256', $legacyData, $this->webhookSecret);

        if (hash_equals($signature, $legacySignature)) {
            $this->logSignatureEvent('legacy_signature_accepted', $context);
            error_log('Webhook used legacy signature format (timestamp.payload). Please update Odoo module.');
            return true;
        }

        // Log detailed error
        $this->logSignatureEvent('signature_mismatch', array_merge($context, [
            'expected_payload_preview' => $this->maskSignatureValue($expectedSignature),
            'expected_legacy_preview' => $this->maskSignatureValue($legacySignature)
        ]));
        error_log('Webhook signature verification failed: delivery_id=' . ($context['delivery_id'] ?? '-') . ', event=' . ($context['event'] ?? 'unknown'));
        error_log('  Expected (payload only): ' . substr($expectedSignature, 0, 40) . '...');
        error_log('  Expected (legacy): ' . substr($legacySignature, 0, 40) . '...');
        error_log('  Received: ' . substr($signature, 0, 40) . '...');
        error_log('  Payload preview: ' . substr($payload, 0, 100));
        
        return false;
    }

    /**
     * Build structured logging context for webhook signature verification.
     *
     * @param string $signature
     * @param int $timestamp
     * @param array $meta
     * @param int $deltaSeconds
     * @param int $tolerance
     * @param int $legacyDriftSeconds
     * @param int $legacyDriftTolerance
     * @return array
     */
    private function buildSignatureLogContext($signature, $timestamp, array $meta, $deltaSeconds, $tolerance, $legacyDriftSeconds, $legacyDriftTolerance)
    {
        $headers = [];
        if (!empty($meta['headers']) && is_array($meta['headers'])) {
            foreach ($meta['headers'] as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                if (stripos($key, 'signature') !== false && is_string($value)) {
                    $headers[$key] = $this->maskSignatureValue($value);
                } else {
                    $headers[$key] = $value;
                }
            }
        }

        return [
            'delivery_id' => $meta['delivery_id'] ?? null,
            'event' => $meta['event'] ?? null,
            'timestamp' => $timestamp,
            'timestamp_delta' => $deltaSeconds,
            'tolerance' => $tolerance,
            'legacy_drift_seconds' => $legacyDriftSeconds,
            'legacy_drift_tolerance' => $legacyDriftTolerance,
            'env' => defined('ODOO_ENVIRONMENT') ? ODOO_ENVIRONMENT : null,
            'headers' => $headers,
            'source_ip' => $meta['source_ip'] ?? null,
            'line_account_id' => $meta['line_account_id'] ?? null,
            'signature_preview' => $this->maskSignatureValue($signature)
        ];
    }

    /**
     * Mask signature strings before logging.
     *
     * @param string|null $value
     * @return string|null
     */
    private function maskSignatureValue($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $prefix = substr($value, 0, 12);
        $suffix = substr($value, -4);
        return $prefix . '...' . $suffix;
    }

    /**
     * Emit structured signature verification logs.
     *
     * @param string $type
     * @param array $context
     * @return void
     */
    private function logSignatureEvent($type, array $context = [])
    {
        $payload = array_merge(['type' => $type], $context);
        error_log('[WebhookSignature] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Check if webhook is duplicate (idempotency)
     * 
     * @param string $deliveryId X-Odoo-Delivery-Id header
     * @return bool True if duplicate
     */
    public function isDuplicateWebhook($deliveryId)
    {
        try {
            $selectColumns = ['status', 'processed_at'];
            $selectColumns[] = $this->hasWebhookColumn('retry_count')
                ? 'retry_count'
                : '0 AS retry_count';

            $sql = '
                SELECT ' . implode(', ', $selectColumns) . '
                FROM odoo_webhooks_log
                WHERE delivery_id = ?
                LIMIT 1
            ';

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$deliveryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return false;
            }

            $status = strtolower((string) ($row['status'] ?? ''));

            // Allow webhook redelivery to be reprocessed if previous attempt failed/retry.
            if (in_array($status, ['failed', 'retry'], true)) {
                return false;
            }

            // processing/received with same delivery_id should not be processed concurrently.
            if (in_array($status, ['processing', 'received'], true)) {
                return true;
            }

            // success/duplicate/dead_letter are deterministic terminal states.
            return true;
        } catch (Exception $e) {
            error_log('Error checking duplicate webhook: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Register webhook receipt and persist raw metadata for observability.
     *
     * @param string $deliveryId
     * @param string|null $eventType
     * @param array|string $payload
     * @param string|null $signature
     * @param int|string|null $timestamp
     * @param string|null $sourceIp
     * @param int|null $lineAccountId
     * @return array{is_duplicate:bool}
     */
    public function registerWebhookReceipt($deliveryId, $eventType, $payload, $signature = null, $timestamp = null, $sourceIp = null, $lineAccountId = null)
    {
        // 1. Check exact delivery_id duplicate (idempotency)
        if ($this->isDuplicateWebhook($deliveryId)) {
            $this->markDuplicateWebhook($deliveryId);
            return ['is_duplicate' => true];
        }

        // 2. Content-based dedup: same event_type + order_name within 120s window = duplicate
        //    This catches Odoo resending the same event with a new delivery_id
        if ($eventType && $eventType !== 'unknown') {
            $payloadArr = is_string($payload) ? json_decode($payload, true) : $payload;
            if (is_array($payloadArr)) {
                $orderName = $payloadArr['order_name']
                    ?? $payloadArr['data']['order_name']
                    ?? $payloadArr['order_ref']
                    ?? $payloadArr['order']['name']
                    ?? null;
                $payloadHash = hash('sha256', is_string($payload) ? $payload : json_encode($payload));
                if ($orderName && $orderName !== '' && $orderName !== 'null') {
                    try {
                        $dupSql = "SELECT delivery_id FROM odoo_webhooks_log
                            WHERE event_type = ? AND status = 'success'
                              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?
                              AND processed_at >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
                              AND delivery_id != ?
                            LIMIT 1";
                        $dupStmt = $this->db->prepare($dupSql);
                        $dupStmt->execute([$eventType, $orderName, $deliveryId]);
                        if ($dupStmt->fetch()) {
                            // Content duplicate — log and mark as duplicate
                            error_log("Content-duplicate: event={$eventType} order={$orderName} delivery_id={$deliveryId}");
                            $this->logWebhook($deliveryId, $eventType, $payload, 'duplicate', 'Content-duplicate: same event+order within 120s', null, null, null, [
                                'signature' => $signature,
                                'source_ip' => $sourceIp,
                                'received_at' => date('Y-m-d H:i:s'),
                            ]);
                            return ['is_duplicate' => true];
                        }
                    } catch (Exception $e) {
                        // non-critical, continue processing
                        error_log('Content-dedup check failed: ' . $e->getMessage());
                    }
                }
            }
        }

        $currentRetryCount = $this->getRetryCount($deliveryId);
        $currentAttemptCount = $this->getAttemptCount($deliveryId);

        $meta = [
            'signature' => $signature,
            'source_ip' => $sourceIp,
            'webhook_timestamp' => $timestamp,
            'payload_hash' => hash('sha256', is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'line_account_id' => $lineAccountId,
            'attempt_count' => max(1, $currentAttemptCount + 1),
            'retry_count' => max(0, $currentRetryCount),
            'received_at' => date('Y-m-d H:i:s')
        ];

        if (!empty($_SERVER)) {
            $meta['header_json'] = json_encode(array_filter([
                'X-Odoo-Signature' => $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? null,
                'X-Odoo-Timestamp' => $_SERVER['HTTP_X_ODOO_TIMESTAMP'] ?? null,
                'X-Odoo-Delivery-Id' => $_SERVER['HTTP_X_ODOO_DELIVERY_ID'] ?? null,
                'X-Odoo-Event' => $_SERVER['HTTP_X_ODOO_EVENT'] ?? null,
                'X-Line-Account-Id' => $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] ?? null,
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $meta['headers'] = [
                'X-Odoo-Signature' => $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? null,
                'X-Odoo-Timestamp' => $_SERVER['HTTP_X_ODOO_TIMESTAMP'] ?? null,
                'X-Odoo-Delivery-Id' => $_SERVER['HTTP_X_ODOO_DELIVERY_ID'] ?? null,
                'X-Odoo-Event' => $_SERVER['HTTP_X_ODOO_EVENT'] ?? null,
            ];
        }

        $this->logWebhook(
            $deliveryId,
            $eventType ?: 'unknown',
            $payload,
            'received',
            null,
            null,
            null,
            null,
            $meta
        );

        return ['is_duplicate' => false];
    }

    /**
     * Mark webhook as currently processing.
     *
     * @param string $deliveryId
     * @return void
     */
    public function markWebhookProcessing($deliveryId)
    {
        try {
            $status = $this->getSupportedWebhookStatus('processing');
            $setClauses = ['status = ?', 'error_message = NULL', 'processed_at = NOW()'];
            $params = [$status];

            if ($this->hasWebhookColumn('last_error_code')) {
                $setClauses[] = 'last_error_code = NULL';
            }

            if ($this->hasWebhookColumn('processing_started_at')) {
                $setClauses[] = 'processing_started_at = NOW()';
            }

            $params[] = $deliveryId;

            $sql = 'UPDATE odoo_webhooks_log SET ' . implode(', ', $setClauses) . ' WHERE delivery_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {
            error_log('Error marking webhook processing: ' . $e->getMessage());
        }
    }

    /**
     * Mark duplicate webhook delivery.
     *
     * @param string $deliveryId
     * @return void
     */
    public function markDuplicateWebhook($deliveryId)
    {
        try {
            $status = $this->getSupportedWebhookStatus('duplicate');
            $setClauses = ['status = ?', 'processed_at = NOW()'];
            $params = [$status];

            if ($this->hasWebhookColumn('attempt_count')) {
                $setClauses[] = 'attempt_count = COALESCE(attempt_count, 1) + 1';
            }

            $params[] = $deliveryId;

            $sql = 'UPDATE odoo_webhooks_log SET ' . implode(', ', $setClauses) . ' WHERE delivery_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } catch (Exception $e) {
            error_log('Error marking duplicate webhook: ' . $e->getMessage());
        }
    }

    /**
     * Mark webhook as successful.
     *
     * @param string $deliveryId
     * @param string $event
     * @param array $payload
     * @param string|null $lineUserId
     * @param int|string|null $orderId
     * @param int|null $durationMs
     * @return void
     */
    public function markWebhookSuccess($deliveryId, $event, $payload, $lineUserId = null, $orderId = null, $durationMs = null)
    {
        $meta = [];
        if ($durationMs !== null) {
            $meta['process_latency_ms'] = (int) $durationMs;
        }

        $this->logWebhook(
            $deliveryId,
            $event,
            $payload,
            'success',
            null,
            $lineUserId,
            $orderId,
            null,
            $meta
        );
    }

    /**
     * Mark webhook as failed/retry/dead-letter depending on retriable policy.
     *
     * @param string $deliveryId
     * @param string $event
     * @param array|string $payload
     * @param string $errorCode
     * @param string $errorMessage
     * @param bool $retriable
     * @return void
     */
    public function markWebhookFailure($deliveryId, $event, $payload, $errorCode, $errorMessage, $retriable = false)
    {
        $retryCount = 0;
        if ($this->hasWebhookColumn('retry_count')) {
            $retryCount = $this->getRetryCount($deliveryId);
            if ($retriable) {
                $retryCount++;
            }
        }

        $status = $retriable ? 'retry' : 'failed';

        $meta = [];
        if ($this->hasWebhookColumn('retry_count')) {
            $meta['retry_count'] = $retryCount;
        }

        $this->logWebhook(
            $deliveryId,
            $event ?: 'unknown',
            $payload,
            $status,
            $errorMessage,
            null,
            null,
            $errorCode,
            $meta
        );

        if ($retriable && $this->hasWebhookColumn('retry_count') && $retryCount >= self::RETRY_LIMIT) {
            $this->moveWebhookToDeadLetter($deliveryId, $event, $payload, $errorCode, $errorMessage, $retryCount);
        }
    }

    /**
     * Identify whether an error is retriable.
     *
     * @param string $message
     * @return bool
     */
    public function isRetriableError($message)
    {
        $normalized = strtolower((string) $message);
        $keywords = [
            'timeout',
            'temporarily',
            'network',
            'connection reset',
            'connection refused',
            'could not resolve host',
            'too many requests',
            'deadlock',
            'lock wait timeout',
            'service unavailable',
            'gateway timeout',
            'internal server error',
            'curl error'
        ];

        foreach ($keywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move repeatedly failing webhook to dead-letter queue.
     *
     * @param string $deliveryId
     * @param string $event
     * @param array|string $payload
     * @param string $errorCode
     * @param string $errorMessage
     * @param int $retryCount
     * @return void
     */
    private function moveWebhookToDeadLetter($deliveryId, $event, $payload, $errorCode, $errorMessage, $retryCount)
    {
        $this->logWebhook(
            $deliveryId,
            $event ?: 'unknown',
            $payload,
            'dead_letter',
            $errorMessage,
            null,
            null,
            $errorCode,
            ['retry_count' => $retryCount]
        );

        if (!$this->tableExists('odoo_webhook_dlq')) {
            return;
        }

        try {
            $payloadJson = is_string($payload)
                ? $payload
                : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $this->db->prepare("
                INSERT INTO odoo_webhook_dlq 
                (delivery_id, event_type, payload, error_code, error_message, retry_count, failed_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    event_type = VALUES(event_type),
                    payload = VALUES(payload),
                    error_code = VALUES(error_code),
                    error_message = VALUES(error_message),
                    retry_count = VALUES(retry_count),
                    failed_at = NOW()
            ");
            $stmt->execute([
                $deliveryId,
                $event ?: 'unknown',
                $payloadJson,
                $errorCode,
                $errorMessage,
                $retryCount
            ]);
        } catch (Exception $e) {
            error_log('Error inserting webhook DLQ record: ' . $e->getMessage());
        }
    }

    /**
     * Resolve supported status value by current DB enum.
     *
     * @param string $status
     * @return string
     */
    private function getSupportedWebhookStatus($status)
    {
        if ($this->webhookStatusEnum === null) {
            $this->webhookStatusEnum = [];
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'status'");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['Type']) && preg_match("/^enum\\((.*)\\)$/i", $row['Type'], $matches)) {
                    $this->webhookStatusEnum = str_getcsv($matches[1], ',', "'");
                }
            } catch (Exception $e) {
                $this->webhookStatusEnum = [];
            }
        }

        if (empty($this->webhookStatusEnum)) {
            return $status;
        }

        if (in_array($status, $this->webhookStatusEnum, true)) {
            return $status;
        }

        $fallbackMap = [
            'received' => 'success',
            'processing' => 'success',
            'duplicate' => 'success',
            'retry' => 'failed',
            'dead_letter' => 'failed'
        ];

        $fallback = $fallbackMap[$status] ?? 'failed';
        if (in_array($fallback, $this->webhookStatusEnum, true)) {
            return $fallback;
        }

        return $this->webhookStatusEnum[0];
    }

    /**
     * Check if specific column exists in odoo_webhooks_log.
     *
     * @param string $columnName
     * @return bool
     */
    private function hasWebhookColumn($columnName)
    {
        if ($this->webhookColumns === null) {
            $this->webhookColumns = [];
            try {
                $stmt = $this->db->query('SHOW COLUMNS FROM odoo_webhooks_log');
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    if (!empty($row['Field'])) {
                        $this->webhookColumns[$row['Field']] = true;
                    }
                }
            } catch (Exception $e) {
                $this->webhookColumns = [];
            }
        }

        return isset($this->webhookColumns[$columnName]);
    }

    /**
     * Check if DB table exists.
     *
     * @param string $tableName
     * @return bool
     */
    private function tableExists($tableName)
    {
        if (isset($this->tableExistence[$tableName])) {
            return $this->tableExistence[$tableName];
        }

        try {
            $stmt = $this->db->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$tableName]);
            $this->tableExistence[$tableName] = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->tableExistence[$tableName] = false;
        }

        return $this->tableExistence[$tableName];
    }

    /**
     * Get current retry count from webhook log.
     *
     * @param string $deliveryId
     * @return int
     */
    private function getRetryCount($deliveryId)
    {
        if (!$this->hasWebhookColumn('retry_count')) {
            return 0;
        }

        try {
            $stmt = $this->db->prepare('SELECT COALESCE(retry_count, 0) FROM odoo_webhooks_log WHERE delivery_id = ? LIMIT 1');
            $stmt->execute([$deliveryId]);
            $value = $stmt->fetchColumn();
            return (int) ($value ?: 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get current attempt count from webhook log.
     *
     * @param string $deliveryId
     * @return int
     */
    private function getAttemptCount($deliveryId)
    {
        if (!$this->hasWebhookColumn('attempt_count')) {
            return 0;
        }

        try {
            $stmt = $this->db->prepare('SELECT COALESCE(attempt_count, 0) FROM odoo_webhooks_log WHERE delivery_id = ? LIMIT 1');
            $stmt->execute([$deliveryId]);
            $value = $stmt->fetchColumn();
            return (int) ($value ?: 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Find LINE user across all line accounts (shared mode)
     * 
     * @param int $odooPartnerId Odoo partner ID
     * @return array|null User data with line_user_id and channel_access_token
     */
    public function findLineUserAcrossAccounts($odooPartnerId, $fallbackLineUserId = null)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT olu.line_user_id, olu.line_notification_enabled,
                       la.id as line_account_id, la.channel_access_token
                FROM odoo_line_users olu
                LEFT JOIN line_accounts la ON olu.line_account_id = la.id
                WHERE olu.odoo_partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$odooPartnerId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                // Try to find user without line_account_id (shared mode)
                $stmt = $this->db->prepare("
                    SELECT olu.line_user_id, olu.line_notification_enabled,
                           la.id as line_account_id, la.channel_access_token
                    FROM odoo_line_users olu
                    CROSS JOIN line_accounts la
                    WHERE olu.odoo_partner_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$odooPartnerId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // If found but line_user_id is NULL, try to resolve from users table
            if ($user && empty($user['line_user_id'])) {
                $resolveId = $fallbackLineUserId;
                if (!$resolveId) {
                    // Try users table via odoo_partner_id stored in users (if column exists)
                    try {
                        $uStmt = $this->db->prepare("SELECT line_user_id FROM users WHERE line_user_id IS NOT NULL LIMIT 0");
                        $uStmt->execute();
                    } catch (Exception $e2) { /* column check */ }
                }
                if ($resolveId) {
                    $user['line_user_id'] = $resolveId;
                }
            }

            // If no DB record but we have a fallback line_user_id, build a minimal user record
            if (!$user && $fallbackLineUserId) {
                $stmt = $this->db->prepare("
                    SELECT la.id as line_account_id, la.channel_access_token
                    FROM line_accounts la
                    LIMIT 1
                ");
                $stmt->execute();
                $la = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($la) {
                    $user = [
                        'line_user_id'             => $fallbackLineUserId,
                        'line_notification_enabled' => 1,
                        'line_account_id'           => $la['line_account_id'],
                        'channel_access_token'      => $la['channel_access_token'],
                    ];
                }
            }

            return $user ?: null;
        } catch (Exception $e) {
            error_log('Error finding LINE user: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve order_id from all possible payload locations.
     * Odoo payloads vary by event type — order_id can be nested in different places.
     *
     * @param array $data Webhook event data
     * @return int|null Resolved order ID or null
     */
    private function resolveOrderId(array $data)
    {
        // Direct fields
        $candidates = [
            $data['order_id'] ?? null,
            $data['order']['id'] ?? null,
            $data['order']['order_id'] ?? null,
            $data['id'] ?? null,                       // some events put id at root
            $data['sale_order_id'] ?? null,
            $data['source_id'] ?? null,                // delivery events
            $data['origin_id'] ?? null,
            $data['delivery']['order_id'] ?? null,     // delivery.* events
            $data['delivery']['sale_order_id'] ?? null,
            $data['invoice']['order_id'] ?? null,      // invoice.* events
            $data['invoice']['sale_order_id'] ?? null,
            $data['payment']['order_id'] ?? null,      // payment.* events
        ];

        foreach ($candidates as $val) {
            if ($val !== null && $val !== '' && $val !== 'null' && is_numeric($val)) {
                return (int) $val;
            }
        }

        // Fallback: try to look up by order_name in DB if we have one
        $orderName = $data['order_name'] ?? $data['order_ref'] ?? $data['order']['name'] ?? $data['origin'] ?? null;
        if ($orderName && $orderName !== '' && $orderName !== 'null') {
            try {
                $stmt = $this->db->prepare("
                    SELECT order_id FROM odoo_webhooks_log
                    WHERE order_id IS NOT NULL
                      AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$orderName]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && $row['order_id']) {
                    return (int) $row['order_id'];
                }
            } catch (\Exception $e) {
                // non-critical, continue without order_id
            }
        }

        return null;
    }

    /**
     * Process webhook event
     * 
     * @param string $deliveryId Webhook delivery ID
     * @param string $event Event type (e.g., 'order.validated')
     * @param array $data Event data
     * @param array $notify Notification rules
     * @param array $messageTemplate Message templates
     * @return array Processing result
     */
    public function processWebhook($deliveryId, $event, $data, $notify, $messageTemplate)
    {
        // Normalize customer/salesperson ID field
        // Odoo sends 'id' but our DB uses partner_id, so map it
        if (isset($data['customer']['id']) && !isset($data['customer']['partner_id'])) {
            $data['customer']['partner_id'] = $data['customer']['id'];
        }
        if (isset($data['salesperson']['id']) && !isset($data['salesperson']['partner_id'])) {
            $data['salesperson']['partner_id'] = $data['salesperson']['id'];
        }

        // Normalize order_ref field (Odoo sends order_name)
        if (isset($data['order_name']) && !isset($data['order_ref'])) {
            $data['order_ref'] = $data['order_name'];
        }

        $this->currentDeliveryId = $deliveryId;
        $customerPartnerId = $data['customer']['partner_id'] ?? null;
        $customerLineUserIdHint = $data['customer']['line_user_id'] ?? null;

        // Resolve order_id from all possible payload locations
        $orderId = $this->resolveOrderId($data);

        // order.in_delivery: bypass router — call handler directly (sends to ALL, with timeline)
        if ($event === 'order.in_delivery') {
            $sentTo = $this->routeEvent($event, $data, $notify, $messageTemplate);

            $lineUserId = null;
            if ($customerPartnerId) {
                $user = $this->findLineUserAcrossAccounts($customerPartnerId, $customerLineUserIdHint);
                $lineUserId = $user['line_user_id'] ?? $customerLineUserIdHint;
            }
            $lineUserId = $lineUserId ?? $customerLineUserIdHint;

            $this->updateProjectionsFromWebhook($deliveryId, $event, $data, $lineUserId, $orderId);

            return [
                'success'        => true,
                'event'          => $event,
                'routing_result' => ['routed' => $sentTo],
                'sent_to'        => $sentTo,
                'line_user_id'   => $lineUserId,
                'order_id'       => $orderId,
                'payload'        => $data,
                'message'        => 'Webhook processed successfully',
            ];
        }

        // All other events: use NotificationRouter for intelligent routing
        require_once __DIR__ . '/NotificationRouter.php';
        $router = new NotificationRouter($this->db);
        $routingResult = $router->route($deliveryId, $event, $data, $notify);

        // Find LINE user for logging
        $lineUserId = null;
        if ($customerPartnerId) {
            $user = $this->findLineUserAcrossAccounts($customerPartnerId, $customerLineUserIdHint);
            $lineUserId = $user['line_user_id'] ?? $customerLineUserIdHint;
        }

        $this->updateProjectionsFromWebhook($deliveryId, $event, $data, $lineUserId, $orderId);

        return [
            'success' => true,
            'event' => $event,
            'routing_result' => $routingResult,
            'sent_to' => array_merge(
                $routingResult['routed'] ?? [],
                $routingResult['batched'] ?? [],
                $routingResult['queued'] ?? []
            ),
            'line_user_id' => $lineUserId,
            'order_id' => $orderId,
            'payload' => $data,
            'message' => 'Webhook processed successfully'
        ];
    }

    /**
     * Update Customer 360 projection tables from successfully processed webhook.
     *
     * @param string $deliveryId
     * @param string $event
     * @param array $data
     * @param string|null $lineUserId
     * @param int|null $orderId
     * @return void
     */
    private function updateProjectionsFromWebhook($deliveryId, $event, array $data, $lineUserId, $orderId)
    {
        try {
            $this->upsertOrderProjection($deliveryId, $event, $data, $lineUserId, $orderId);
            $this->upsertCustomerProjection($data, $lineUserId);
            $this->upsertFrequentProductsProjection($event, $data, $lineUserId);
            
            // Sync to dedicated Odoo tables (orders, invoices, BDOs) for fast querying
            $this->syncToOdooTables($event, $data, $deliveryId);
        } catch (Exception $e) {
            error_log('Error updating Odoo projection tables: ' . $e->getMessage());
        }
    }

    /**
     * Sync webhook data to dedicated Odoo tables (odoo_orders, odoo_invoices, odoo_bdos)
     * 
     * @param string $event Event type
     * @param array $data Webhook payload
     * @param string $deliveryId Webhook delivery ID
     * @return void
     */
    private function syncToOdooTables($event, array $data, $deliveryId)
    {
        try {
            // Get webhook log ID for tracking
            $webhookId = null;
            $stmt = $this->db->prepare("SELECT id FROM odoo_webhooks_log WHERE delivery_id = ? LIMIT 1");
            $stmt->execute([$deliveryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $webhookId = (int) $row['id'];
            }

            // Initialize sync service and sync data
            require_once __DIR__ . '/OdooSyncService.php';
            $syncService = new OdooSyncService($this->db);
            $success = $syncService->syncWebhook($data, $event, $webhookId);

            // Mark webhook as synced if successful
            if ($success && $webhookId) {
                $updateStmt = $this->db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?");
                $updateStmt->execute([$webhookId]);
            }
        } catch (Exception $e) {
            error_log('[OdooWebhookHandler] Sync to Odoo tables failed: ' . $e->getMessage());
        }
    }

    /**
     * Upsert order projection row.
     */
    private function upsertOrderProjection($deliveryId, $event, array $data, $lineUserId, $orderId)
    {
        if (!$this->tableExists('odoo_order_projection') || empty($orderId)) {
            return;
        }

        $orderName = $data['order_name'] ?? ($data['order_ref'] ?? null);
        $customerName = $data['customer']['name'] ?? ($data['customer_name'] ?? null);
        $customerRef = $data['customer']['ref'] ?? ($data['customer_ref'] ?? null);
        $odooPartnerId = $data['customer']['partner_id'] ?? ($data['customer']['id'] ?? null);
        $latestState = $data['new_state'] ?? ($data['state'] ?? null);
        $latestStateDisplay = $data['new_state_display'] ?? ($data['state_display'] ?? $latestState);
        $amountTotal = isset($data['amount_total']) ? (float) $data['amount_total'] : null;

        $stmt = $this->db->prepare("\n            INSERT INTO odoo_order_projection\n                (order_id, order_name, line_user_id, odoo_partner_id, customer_name, customer_ref,\n                 latest_event_type, latest_state, latest_state_display, amount_total,\n                 source_delivery_id, source_status, last_webhook_at, first_seen_at, updated_at)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', NOW(), NOW(), NOW())\n            ON DUPLICATE KEY UPDATE\n                order_name = VALUES(order_name),\n                line_user_id = VALUES(line_user_id),\n                odoo_partner_id = VALUES(odoo_partner_id),\n                customer_name = VALUES(customer_name),\n                customer_ref = VALUES(customer_ref),\n                latest_event_type = VALUES(latest_event_type),\n                latest_state = VALUES(latest_state),\n                latest_state_display = VALUES(latest_state_display),\n                amount_total = COALESCE(VALUES(amount_total), amount_total),\n                source_delivery_id = VALUES(source_delivery_id),\n                source_status = 'success',\n                last_webhook_at = NOW(),\n                updated_at = NOW()\n        ");

        $stmt->execute([
            (int) $orderId,
            $orderName,
            $lineUserId,
            $odooPartnerId !== null ? (int) $odooPartnerId : null,
            $customerName,
            $customerRef,
            $event,
            $latestState,
            $latestStateDisplay,
            $amountTotal,
            $deliveryId
        ]);
    }

    /**
     * Upsert customer projection row.
     */
    private function upsertCustomerProjection(array $data, $lineUserId)
    {
        if (!$this->tableExists('odoo_customer_projection') || empty($lineUserId)) {
            return;
        }

        $odooPartnerId = $data['customer']['partner_id'] ?? ($data['customer']['id'] ?? null);
        $customerName = $data['customer']['name'] ?? ($data['customer_name'] ?? null);
        $customerRef = $data['customer']['ref'] ?? ($data['customer_ref'] ?? null);

        $creditLimit = isset($data['credit_limit']) ? (float) $data['credit_limit'] : null;
        $creditUsed = isset($data['credit_used']) ? (float) $data['credit_used'] : null;
        $creditRemaining = isset($data['credit_remaining']) ? (float) $data['credit_remaining'] : null;
        $totalDue = isset($data['total_due']) ? (float) $data['total_due'] : null;
        $overdueAmount = isset($data['overdue_amount']) ? (float) $data['overdue_amount'] : null;

        $latestOrder = [
            'order_id' => null,
            'order_name' => null,
            'last_webhook_at' => null,
            'orders_count_30d' => 0,
            'orders_count_90d' => 0,
            'spend_30d' => 0,
            'spend_90d' => 0
        ];

        if ($this->tableExists('odoo_order_projection')) {
            try {
                $summaryStmt = $this->db->prepare("\n                    SELECT\n                        COALESCE(SUM(CASE WHEN last_webhook_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) as orders_count_30d,\n                        COALESCE(SUM(CASE WHEN last_webhook_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END), 0) as orders_count_90d,\n                        COALESCE(SUM(CASE WHEN last_webhook_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN COALESCE(amount_total, 0) ELSE 0 END), 0) as spend_30d,\n                        COALESCE(SUM(CASE WHEN last_webhook_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN COALESCE(amount_total, 0) ELSE 0 END), 0) as spend_90d\n                    FROM odoo_order_projection\n                    WHERE line_user_id = ?\n                ");
                $summaryStmt->execute([$lineUserId]);
                $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);

                if ($summaryRow) {
                    $latestOrder['orders_count_30d'] = (int) ($summaryRow['orders_count_30d'] ?? 0);
                    $latestOrder['orders_count_90d'] = (int) ($summaryRow['orders_count_90d'] ?? 0);
                    $latestOrder['spend_30d'] = (float) ($summaryRow['spend_30d'] ?? 0);
                    $latestOrder['spend_90d'] = (float) ($summaryRow['spend_90d'] ?? 0);
                }

                $latestStmt = $this->db->prepare("\n                    SELECT order_id, order_name, last_webhook_at\n                    FROM odoo_order_projection\n                    WHERE line_user_id = ? AND last_webhook_at IS NOT NULL\n                    ORDER BY last_webhook_at DESC, updated_at DESC\n                    LIMIT 1\n                ");
                $latestStmt->execute([$lineUserId]);
                $latestRow = $latestStmt->fetch(PDO::FETCH_ASSOC);

                if ($latestRow) {
                    $latestOrder['order_id'] = isset($latestRow['order_id']) ? (int) $latestRow['order_id'] : null;
                    $latestOrder['order_name'] = $latestRow['order_name'] ?? null;
                    $latestOrder['last_webhook_at'] = $latestRow['last_webhook_at'] ?? null;
                }
            } catch (Exception $e) {
                error_log('Cannot aggregate customer projection from order projection: ' . $e->getMessage());
            }
        }

        $stmt = $this->db->prepare("\n            INSERT INTO odoo_customer_projection\n                (line_user_id, odoo_partner_id, customer_name, customer_ref,\n                 credit_limit, credit_used, credit_remaining, total_due, overdue_amount,\n                 latest_order_id, latest_order_name, latest_order_at,\n                 orders_count_30d, orders_count_90d, spend_30d, spend_90d, updated_at)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())\n            ON DUPLICATE KEY UPDATE\n                odoo_partner_id = VALUES(odoo_partner_id),\n                customer_name = VALUES(customer_name),\n                customer_ref = VALUES(customer_ref),\n                credit_limit = COALESCE(VALUES(credit_limit), credit_limit),\n                credit_used = COALESCE(VALUES(credit_used), credit_used),\n                credit_remaining = COALESCE(VALUES(credit_remaining), credit_remaining),\n                total_due = COALESCE(VALUES(total_due), total_due),\n                overdue_amount = COALESCE(VALUES(overdue_amount), overdue_amount),\n                latest_order_id = COALESCE(VALUES(latest_order_id), latest_order_id),\n                latest_order_name = COALESCE(VALUES(latest_order_name), latest_order_name),\n                latest_order_at = COALESCE(VALUES(latest_order_at), latest_order_at),\n                orders_count_30d = VALUES(orders_count_30d),\n                orders_count_90d = VALUES(orders_count_90d),\n                spend_30d = VALUES(spend_30d),\n                spend_90d = VALUES(spend_90d),\n                updated_at = NOW()\n        ");

        $stmt->execute([
            $lineUserId,
            $odooPartnerId !== null ? (int) $odooPartnerId : null,
            $customerName,
            $customerRef,
            $creditLimit,
            $creditUsed,
            $creditRemaining,
            $totalDue,
            $overdueAmount,
            $latestOrder['order_id'],
            $latestOrder['order_name'],
            $latestOrder['last_webhook_at'],
            $latestOrder['orders_count_30d'],
            $latestOrder['orders_count_90d'],
            $latestOrder['spend_30d'],
            $latestOrder['spend_90d']
        ]);
    }

    /**
     * Upsert frequent products projection row(s).
     */
    private function upsertFrequentProductsProjection($event, array $data, $lineUserId)
    {
        if (!$this->tableExists('odoo_customer_product_stats') || empty($lineUserId)) {
            return;
        }

        // Only aggregate product stats on key order lifecycle checkpoints.
        if ($event !== 'order.validated') {
            return;
        }

        $orderLines = $data['order_lines'] ?? ($data['order_line'] ?? []);
        if (!is_array($orderLines) || empty($orderLines)) {
            return;
        }

        $odooPartnerId = $data['customer']['partner_id'] ?? ($data['customer']['id'] ?? null);

        foreach ($orderLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productName = trim((string) ($line['product_name'] ?? ($line['name'] ?? '')));
            if ($productName === '') {
                continue;
            }

            $productId = isset($line['product_id']) ? (int) $line['product_id'] : null;
            $productCode = $line['product_code'] ?? null;
            $qty = (float) ($line['product_uom_qty'] ?? ($line['qty'] ?? ($line['quantity'] ?? 0)));
            if ($qty <= 0) {
                $qty = 1.0;
            }

            $amount = isset($line['price_subtotal'])
                ? (float) $line['price_subtotal']
                : ($qty * (float) ($line['price_unit'] ?? 0));

            $stmt = $this->db->prepare("\n                INSERT INTO odoo_customer_product_stats\n                    (line_user_id, odoo_partner_id, product_id, product_code, product_name,\n                     qty_30d, qty_90d, amount_30d, amount_90d, last_purchased_at, updated_at)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n                ON DUPLICATE KEY UPDATE\n                    odoo_partner_id = VALUES(odoo_partner_id),\n                    product_id = COALESCE(VALUES(product_id), product_id),\n                    product_code = COALESCE(VALUES(product_code), product_code),\n                    qty_30d = qty_30d + VALUES(qty_30d),\n                    qty_90d = qty_90d + VALUES(qty_90d),\n                    amount_30d = amount_30d + VALUES(amount_30d),\n                    amount_90d = amount_90d + VALUES(amount_90d),\n                    last_purchased_at = NOW(),\n                    updated_at = NOW()\n            ");

            $stmt->execute([
                $lineUserId,
                $odooPartnerId !== null ? (int) $odooPartnerId : null,
                $productId,
                $productCode,
                $productName,
                $qty,
                $qty,
                $amount,
                $amount
            ]);
        }
    }

    /**
     * Route event to specific handler method
     * 
     * @param string $event Event type (e.g., 'order.picker_assigned')
     * @param array $data Event data
     * @param array $notify Notification rules
     * @param array $messageTemplate Message templates
     * @return array List of recipients notified
     */
    private function routeEvent($event, $data, $notify, $messageTemplate)
    {
        $this->currentEvent = $event;
        // currentDeliveryId is set by processWebhook before calling routeEvent

        // Map event types to handler methods
        $eventHandlers = [
            'order.validated'        => 'handleOrderValidated',
            'order.picker_assigned'  => 'handleOrderPickerAssigned',
            'order.picking'          => 'handleOrderPicking',
            'order.picked'           => 'handleOrderPicked',
            'order.packing'          => 'handleOrderPacking',
            'order.packed'           => 'handleOrderPacked',
            'order.reserved'         => 'handleOrderReserved',
            'order.awaiting_payment' => 'handleOrderAwaitingPayment',
            'order.paid'             => 'handleOrderPaid',
            'order.to_delivery'      => 'handleOrderToDelivery',
            'order.in_delivery'      => 'handleOrderInDelivery',
            'order.delivered'        => 'handleOrderDelivered',
            'delivery.departed'      => 'handleDeliveryDeparted',
            'delivery.completed'     => 'handleDeliveryCompleted',
            'payment.confirmed'      => 'handlePaymentConfirmed',
            'payment.done'           => 'handlePaymentDone',
            'invoice.created'        => 'handleInvoiceCreated',
            'invoice.overdue'        => 'handleInvoiceOverdue',
            'invoice.paid'           => 'handleInvoicePaid',
            'bdo.confirmed'          => 'handleBdoConfirmed',
            'bdo.done'               => 'handleBdoDone',
            'bdo.cancelled'          => 'handleBdoCancelled',
        ];

        $handler = $eventHandlers[$event] ?? null;

        if ($handler && method_exists($this, $handler)) {
            error_log("Webhook routing: $event -> $handler()");
            return $this->$handler($data, $notify, $messageTemplate);
        }

        // Fallback: use message_template if provided, otherwise generate generic message
        error_log("Webhook: no specific handler for event '$event', using fallback");
        return $this->handleGenericEvent($event, $data, $notify, $messageTemplate);
    }

    /**
     * Generic event handler for unmapped events
     */
    private function handleGenericEvent($event, $data, $notify, $messageTemplate)
    {
        // Try message_template first
        if (!empty($messageTemplate['customer']['th'])) {
            $message = $this->replacePlaceholders($messageTemplate['customer']['th'], $data);
        } else {
            // Build a generic notification
            $stateDisplay = $data['new_state_display'] ?? $data['new_state'] ?? $event;
            $orderRef = $data['order_ref'] ?? $data['order_name'] ?? '';
            $message = "📦 อัพเดทสถานะออเดอร์\n\n";
            $message .= "ออเดอร์: {$orderRef}\n";
            $message .= "สถานะ: {$stateDisplay}\n";
        }

        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Replace placeholders in message template
     * 
     * @param string $template Message template
     * @param array $data Event data
     * @return string Message with replaced placeholders
     */
    private function replacePlaceholders($template, $data)
    {
        $placeholders = [
            '{order_name}' => $data['order_name'] ?? '',
            '{order_ref}' => $data['order_ref'] ?? '',
            '{customer_name}' => $data['customer']['name'] ?? '',
            '{salesperson_name}' => $data['salesperson']['name'] ?? '',
            '{amount}' => number_format($data['amount_total'] ?? 0, 2),
            '{state}' => $data['state'] ?? '',
            '{delivery_date}' => $data['delivery_date'] ?? '',
            '{driver_name}' => $data['driver']['name'] ?? '',
            '{vehicle_plate}' => $data['vehicle']['plate'] ?? '',
            '{invoice_number}' => $data['invoice_number'] ?? '',
            '{due_date}' => $data['due_date'] ?? ''
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /**
     * Send LINE message
     * 
     * @param string $lineUserId LINE user ID
     * @param string $channelAccessToken Channel access token
     * @param string $message Message text
     */
    private function sendLineMessage($lineUserId, $channelAccessToken, $message)
    {
        try {
            $url = 'https://api.line.me/v2/bot/message/push';

            $data = [
                'to' => $lineUserId,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message
                    ]
                ]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $channelAccessToken
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log('LINE API error: ' . $response);
            }

            return ['http_status' => $httpCode, 'response' => $response];

        } catch (Exception $e) {
            error_log('Error sending LINE message: ' . $e->getMessage());
            return ['http_status' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send LINE Flex Message
     * 
     * @param string $lineUserId LINE user ID
     * @param string $channelAccessToken Channel access token
     * @param array $flexBubble Flex message bubble
     * @param string $altText Alt text for notification
     */
    private function sendLineFlexMessage($lineUserId, $channelAccessToken, $flexBubble, $altText = 'ข้อความ')
    {
        try {
            $url = 'https://api.line.me/v2/bot/message/push';

            $data = [
                'to' => $lineUserId,
                'messages' => [
                    [
                        'type' => 'flex',
                        'altText' => $altText,
                        'contents' => $flexBubble
                    ]
                ]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $channelAccessToken
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log('LINE API error (Flex): ' . $response);
            }

            return ['http_status' => $httpCode, 'response' => $response];

        } catch (Exception $e) {
            error_log('Error sending LINE Flex message: ' . $e->getMessage());
            return ['http_status' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Log webhook to database
     * 
     * @param string $deliveryId Delivery ID
     * @param string $event Event type
     * @param array $payload Event payload
     * @param string $status Status (success/failed/duplicate)
     * @param string|null $error Error message
     * @param string|null $lineUserId LINE user ID
     * @param int|null $orderId Order ID
     */
    private function logWebhook($deliveryId, $event, $payload, $status, $error = null, $lineUserId = null, $orderId = null, $errorCode = null, array $meta = [])
    {
        try {
            if (is_string($payload)) {
                $decodedPayload = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payloadJson = $payload;
                } else {
                    $payloadJson = json_encode([
                        'raw_payload' => $payload,
                        'json_error' => json_last_error_msg()
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            } else {
                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $resolvedStatus = $this->getSupportedWebhookStatus($status);

            $columns = [
                'delivery_id',
                'event_type',
                'payload',
                'status',
                'error_message',
                'line_user_id',
                'order_id',
                'processed_at'
            ];

            $values = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
            $params = [
                $deliveryId,
                $event,
                $payloadJson,
                $resolvedStatus,
                $error,
                $lineUserId,
                $orderId
            ];

            $optionalValues = [
                'last_error_code' => $errorCode,
                'process_latency_ms' => $meta['process_latency_ms'] ?? null,
                'signature' => $meta['signature'] ?? null,
                'source_ip' => $meta['source_ip'] ?? null,
                'payload_hash' => $meta['payload_hash'] ?? null,
                'webhook_timestamp' => $meta['webhook_timestamp'] ?? null,
                'header_json' => isset($meta['header_json'])
                    ? (is_string($meta['header_json']) ? $meta['header_json'] : json_encode($meta['header_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    : null,
                'notified_targets' => isset($meta['notified_targets'])
                    ? (is_string($meta['notified_targets']) ? $meta['notified_targets'] : json_encode($meta['notified_targets'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    : null,
                'received_at' => $meta['received_at'] ?? null,
                'processing_started_at' => $meta['processing_started_at'] ?? null,
                'attempt_count' => $meta['attempt_count'] ?? null,
                'retry_count' => $meta['retry_count'] ?? null
            ];

            if ($this->hasWebhookColumn('line_account_id') && array_key_exists('line_account_id', $meta)) {
                $optionalValues['line_account_id'] = $meta['line_account_id'];
            }

            foreach ($optionalValues as $column => $value) {
                if (!$this->hasWebhookColumn($column) || $value === null) {
                    continue;
                }

                $columns[] = $column;
                $values[] = '?';
                $params[] = $value;
            }

            $updateClauses = [
                'event_type = VALUES(event_type)',
                'payload = VALUES(payload)',
                'status = VALUES(status)',
                'error_message = VALUES(error_message)',
                'line_user_id = VALUES(line_user_id)',
                'order_id = VALUES(order_id)',
                'processed_at = NOW()'
            ];

            foreach ($columns as $column) {
                if (in_array($column, ['delivery_id', 'processed_at'], true)) {
                    continue;
                }
                if (!in_array($column . ' = VALUES(' . $column . ')', $updateClauses, true)) {
                    $updateClauses[] = $column . ' = VALUES(' . $column . ')';
                }
            }

            $sql = 'INSERT INTO odoo_webhooks_log (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ') '
                . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updateClauses);

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);
            
            // Check if INSERT/UPDATE was successful
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception('Failed to log webhook to database: ' . ($errorInfo[2] ?? 'Unknown PDO error'));
            }
            
        } catch (Exception $e) {
            // Log the error with more context
            error_log('CRITICAL: Error logging webhook (delivery_id=' . $deliveryId . ', event=' . $event . '): ' . $e->getMessage());
            
            // Re-throw exception so webhook endpoint knows it failed
            // This ensures Odoo will retry the webhook
            throw new Exception('Database error: Failed to log webhook - ' . $e->getMessage(), 500);
        }
    }

    // ========================================================================
    // Event Handlers - Order Lifecycle
    // ========================================================================

    /**
     * Handle Order Validated Event
     */
    public function handleOrderValidated($data, $notify, $template)
    {
        $message = "✅ ออเดอร์ได้รับการยืนยันแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "ยอดเงิน: ฿" . number_format($data['amount_total'] ?? 0, 2) . "\n";
        if (!empty($data['expected_date'])) {
            $message .= "วันที่คาดว่าจะส่ง: {$data['expected_date']}\n";
        }
        $message .= "\nเราจะแจ้งให้ทราบเมื่อมีการอัพเดท 📦";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Picker Assigned Event
     */
    public function handleOrderPickerAssigned($data, $notify, $template)
    {
        $pickerName = $data['picker']['name'] ?? 'พนักงาน';
        $stateDisplay = $data['new_state_display'] ?? 'เตรียมจัดสินค้า';
        $message = "👤 มีพนักงานรับออเดอร์แล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: {$stateDisplay}\n";
        if ($pickerName !== 'พนักงาน') {
            $message .= "พนักงาน: {$pickerName}\n";
        }
        $message .= "\nกำลังเตรียมสินค้าให้คุณ 📦";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Picking Event
     */
    public function handleOrderPicking($data, $notify, $template)
    {
        $message = "📦 กำลังจัดเตรียมสินค้า\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: กำลังหยิบสินค้า\n";
        if (!empty($data['progress'])) {
            $message .= "ความคืบหน้า: {$data['progress']}%\n";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Picked Event
     */
    public function handleOrderPicked($data, $notify, $template)
    {
        $message = "✅ หยิบสินค้าเรียบร้อยแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: รอแพ็คสินค้า\n";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Packing Event
     */
    public function handleOrderPacking($data, $notify, $template)
    {
        $message = "📦 กำลังแพ็คสินค้า\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: กำลังบรรจุหีบห่อ\n";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Packed Event
     */
    public function handleOrderPacked($data, $notify, $template)
    {
        $message = "✅ แพ็คสินค้าเรียบร้อยแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: พร้อมจัดส่ง\n";
        if (!empty($data['tracking_number'])) {
            $message .= "เลขพัสดุ: {$data['tracking_number']}\n";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Reserved Event
     */
    public function handleOrderReserved($data, $notify, $template)
    {
        $message = "🔒 จองสินค้าเรียบร้อยแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "สถานะ: จองสินค้าแล้ว\n";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Awaiting Payment Event
     */
    public function handleOrderAwaitingPayment($data, $notify, $template)
    {
        $message = "💰 รอการชำระเงิน\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "ยอดเงิน: ฿" . number_format($data['amount_total'] ?? 0, 2) . "\n";
        if (!empty($data['payment_deadline'])) {
            $message .= "ครบกำหนด: {$data['payment_deadline']}\n";
        }
        $message .= "\nกรุณาชำระเงินเพื่อดำเนินการต่อ";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order Paid Event
     */
    public function handleOrderPaid($data, $notify, $template)
    {
        $message = "✅ ชำระเงินเรียบร้อยแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "ยอดเงิน: ฿" . number_format($data['amount_total'] ?? 0, 2) . "\n";
        $message .= "\nขอบคุณที่ชำระเงิน เราจะดำเนินการจัดส่งโดยเร็ว 🚚";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle Order To Delivery Event
     *
     * This is the ONLY notification sent to the customer for the
     * picking→packing→ready-to-ship flow.  Intermediate events
     * (picker_assigned, picking, picked, packing, packed) are recorded
     * in the DB but their LINE notifications are suppressed by
     * NotificationRouter.  This handler builds a consolidated message
     * that includes a timeline summary of all previous steps.
     */
    public function handleOrderToDelivery($data, $notify, $template)
    {
        $stateDisplay = $data['new_state_display'] ?? 'เตรียมส่ง';
        $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? '');

        // Build consolidated timeline from webhook log
        $timelineSummary = $this->buildConsolidatedTimeline($data);

        $message = "🚚 เตรียมจัดส่งสินค้า\n\n";
        $message .= "ออเดอร์: {$orderRef}\n";
        $message .= "สถานะ: {$stateDisplay}\n";
        if (!empty($data['carrier'])) {
            $message .= "ขนส่ง: {$data['carrier']}\n";
        }
        if (!empty($data['tracking_number'])) {
            $message .= "เลขพัสดุ: {$data['tracking_number']}\n";
        }
        if ($timelineSummary) {
            $message .= "\n" . $timelineSummary;
        }
        $message .= "\nสินค้าจะถูกจัดส่งเร็วๆ นี้ 📦";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Build a consolidated timeline string from previous webhook events
     * for the same order.  Used by handleOrderToDelivery to show a
     * summary of all steps in a single notification.
     */
    private function buildConsolidatedTimeline(array $data): string
    {
        $orderId  = $data['order_id'] ?? ($data['order']['id'] ?? null);
        $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? null);

        if (!$orderId && !$orderRef) {
            return '';
        }

        $eventLabels = [
            'order.validated'        => '🛒 ยืนยันออเดอร์',
            'order.picker_assigned'  => '👤 เตรียมจัดสินค้า',
            'order.picking'          => '📦 กำลังจัดสินค้า',
            'order.picked'           => '✅ จัดสินค้าเสร็จ',
            'order.packing'          => '📦 กำลังแพ็ค',
            'order.packed'           => '✅ แพ็คเสร็จ',
            'order.to_delivery'      => '🚚 เตรียมส่ง',
        ];

        $wantedEvents = array_keys($eventLabels);
        $placeholders = implode(',', array_fill(0, count($wantedEvents), '?'));

        try {
            if ($orderId) {
                $sql = "SELECT event_type, processed_at FROM odoo_webhooks_log
                        WHERE order_id = ?
                          AND event_type IN ({$placeholders})
                          AND status = 'success'
                        ORDER BY processed_at ASC";
                $params = array_merge([(int) $orderId], $wantedEvents);
            } else {
                $sql = "SELECT event_type, processed_at FROM odoo_webhooks_log
                        WHERE (
                            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.order_name')) = ?
                            OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.order_ref')) = ?
                        )
                          AND event_type IN ({$placeholders})
                          AND status = 'success'
                        ORDER BY processed_at ASC";
                $params = array_merge([$orderRef, $orderRef], $wantedEvents);
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return '';
            }

            // De-duplicate: keep only the latest timestamp per event_type
            $seen = [];
            foreach ($rows as $row) {
                $seen[$row['event_type']] = $row['processed_at'];
            }

            $lines = ["ประวัติสถานะล่าสุด:"];
            foreach ($eventLabels as $evt => $label) {
                if (isset($seen[$evt])) {
                    $ts = date('d/m H:i', strtotime($seen[$evt]));
                    $lines[] = "  {$label} - {$ts}";
                }
            }

            return count($lines) > 1 ? implode("\n", $lines) : '';

        } catch (Exception $e) {
            error_log('buildConsolidatedTimeline error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Handle Order In Delivery Event
     * Sends to ALL linked LINE users regardless of notification preference.
     * Includes a 3-step delivery timeline: validated → picking → in_delivery.
     */
    public function handleOrderInDelivery($data, $notify, $template)
    {
        $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? '');
        $message  = "🚚 สินค้าอยู่ระหว่างจัดส่ง\n\nออเดอร์: {$orderRef}";
        if (!empty($data['tracking_number'])) {
            $message .= "\nเลขพัสดุ: {$data['tracking_number']}";
        }
        if (!empty($data['carrier'])) {
            $message .= "\nขนส่ง: {$data['carrier']}";
        }

        return $this->sendInDeliveryNotification($data, $message);
    }

    /**
     * Send order.in_delivery notification to ALL linked LINE users,
     * bypassing line_notification_enabled, with a fixed 3-step timeline.
     */
    private function sendInDeliveryNotification(array $data, string $message): array
    {
        $sentTo = [];
        $eventCode = 'order.in_delivery';

        // Build fixed 3-step timeline
        $now = date('d/m/Y H:i');
        $timeline = $this->buildInDeliveryTimeline($data, $now);

        require_once __DIR__ . '/OdooFlexTemplates.php';
        $flexBubble = null;
        try {
            $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventCode, $data, $message, false, $timeline);
        } catch (Exception $e) {
            error_log('OdooFlexTemplates error (in_delivery): ' . $e->getMessage());
        }

        $customerPartnerId = $data['customer']['partner_id'] ?? null;
        $customerLineHint  = $data['customer']['line_user_id'] ?? null;

        // [TEMPORARILY DISABLED] All LINE notifications to customers are disabled
        if (false && ($customerPartnerId || $customerLineHint)) {
            // Find user — force-enable notification regardless of DB flag
            $user = $this->findLineUserAcrossAccounts($customerPartnerId, $customerLineHint);
            if ($user && !empty($user['line_user_id']) && !empty($user['channel_access_token'])) {
                $t0 = microtime(true);
                $apiResult = null;
                try {
                    if ($flexBubble) {
                        $altText = '🚚 สินค้ากำลังจัดส่ง' . (!empty($data['order_ref']) ? ': ' . $data['order_ref'] : '');
                        $apiResult = $this->sendLineFlexMessage(
                            $user['line_user_id'],
                            $user['channel_access_token'],
                            $flexBubble,
                            $altText
                        );
                    } else {
                        $apiResult = $this->sendLineMessage($user['line_user_id'], $user['channel_access_token'], $message);
                    }
                    $sentTo[] = 'customer';
                } catch (Exception $e) {
                    $apiResult = ['error' => $e->getMessage()];
                    error_log('sendInDeliveryNotification customer error: ' . $e->getMessage());
                }
                $this->logNotification($this->currentDeliveryId, $eventCode, 'customer', $user['line_user_id'], $apiResult, (int)round((microtime(true)-$t0)*1000));
            }
        }

        return $sentTo;
    }

    /**
     * Build 3-step timeline for order.in_delivery:
     * validated → picking (or packed) → in_delivery
     * Pulls real timestamps from odoo_webhooks_log when available.
     */
    private function buildInDeliveryTimeline(array $data, string $fallbackNow): array
    {
        $orderId  = $data['order_id'] ?? null;
        $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? null);

        // The 3 steps we want to show
        $steps = [
            'order.validated'   => ['icon' => '🛒', 'label' => 'ยืนยันออเดอร์',    'ts' => null],
            'order.picking'     => ['icon' => '📦', 'label' => 'เริ่มจัดเตรียม',   'ts' => null],
            'order.in_delivery' => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง',     'ts' => $fallbackNow],
        ];

        // Try to pull real timestamps from webhook log
        if ($orderId || $orderRef) {
            try {
                // order_name is inside payload JSON, not a direct column
                if ($orderId) {
                    $sql = "SELECT event_type, processed_at FROM odoo_webhooks_log
                            WHERE order_id = ?
                              AND event_type IN ('order.validated','order.picking','order.packed','order.in_delivery')
                              AND status = 'success'
                            ORDER BY processed_at ASC";
                    $params = [(int)$orderId];
                } else {
                    // fallback: search order_ref inside payload JSON
                    $sql = "SELECT event_type, processed_at FROM odoo_webhooks_log
                            WHERE (
                                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.order_name')) = ?
                                OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.order_ref')) = ?
                            )
                              AND event_type IN ('order.validated','order.picking','order.packed','order.in_delivery')
                              AND status = 'success'
                            ORDER BY processed_at ASC";
                    $params = [$orderRef, $orderRef];
                }
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $code = $row['event_type'];
                    $ts   = $row['processed_at'] ? date('d/m/Y H:i', strtotime($row['processed_at'])) : null;
                    if ($code === 'order.validated' && $ts) {
                        $steps['order.validated']['ts'] = $ts;
                    } elseif (in_array($code, ['order.picking', 'order.packed']) && $ts) {
                        // Use whichever picking/packing event we find first
                        if ($steps['order.picking']['ts'] === null) {
                            $steps['order.picking']['ts'] = $ts;
                        }
                    } elseif ($code === 'order.in_delivery' && $ts) {
                        $steps['order.in_delivery']['ts'] = $ts;
                    }
                }
            } catch (Exception $e) {
                error_log('buildInDeliveryTimeline DB error: ' . $e->getMessage());
            }
        }

        // Build timeline array for OdooFlexTemplates
        $timeline = [];
        foreach ($steps as $eventCode => $step) {
            $timeline[] = [
                'event_code' => $eventCode,
                'label'      => $step['label'],
                'icon'       => $step['icon'],
                'timestamp'  => $step['ts'] ?? '-',
                'status'     => ($eventCode === 'order.in_delivery') ? 'กำลังดำเนินการ' : 'success',
            ];
        }
        return $timeline;
    }

    /**
     * Handle Order Delivered Event
     */
    public function handleOrderDelivered($data, $notify, $template)
    {
        $message = "✅ จัดส่งสำเร็จแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        if (!empty($data['delivery_time'])) {
            $message .= "เวลาจัดส่ง: {$data['delivery_time']}\n";
        }
        if (!empty($data['receiver_name'])) {
            $message .= "ผู้รับ: {$data['receiver_name']}\n";
        }
        $message .= "\nขอบคุณที่ใช้บริการครับ 🙏";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle BDO Confirmed Event - Payment Request with QR Code
     * 
     * This is the most important handler as it sends payment requests
     * with PromptPay QR codes to customers.
     * 
     * @param array $data BDO event data
     * @param array $notify Notification rules
     * @param array $template Message templates (not used, we use Flex)
     * @return array Sent to list
     */
    public function handleBdoConfirmed($data, $notify, $template)
    {
        try {
            // ── Save BDO context (multi-BDO-safe, stores statement PDF + invoices/CN) ──
            require_once __DIR__ . '/BdoContextManager.php';
            $ctxMgr = new BdoContextManager($this->db);
            $ctxMgr->openContext($data, $this->currentDeliveryId ?? '');

            // 12.1.1 Extract QR Payment data
            $emvcoPayload = $data['payment']['promptpay']['qr_data']['raw_payload'] ?? null;

            if (empty($emvcoPayload)) {
                error_log('BDO Confirmed: No EMVCo payload found');
                return [];
            }

            // 12.1.2 Generate QR Code
            require_once __DIR__ . '/QRCodeGenerator.php';
            $qrGenerator = new QRCodeGenerator();

            $bdoRef = $data['bdo_ref'] ?? 'BDO_' . time();
            $qrResult = $qrGenerator->generatePromptPayQR($emvcoPayload, $bdoRef);

            if (!$qrResult['success']) {
                error_log('BDO Confirmed: QR generation failed - ' . ($qrResult['error'] ?? 'Unknown error'));
                return [];
            }

            // Get full URL for QR code
            $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://cny.re-ya.com';
            $qrCodeUrl = $baseUrl . $qrResult['url'];

            // 12.1.3 Extract invoice URL
            $invoiceUrl = $data['invoice']['pdf_url'] ?? '';

            // 12.1.4 สร้าง Flex Message พร้อม QR
            require_once __DIR__ . '/OdooFlexTemplates.php';
            $flexBubble = OdooFlexTemplates::bdoPaymentRequest($data, $qrCodeUrl);

            // 12.1.5 ส่งให้ลูกค้า
            $sentTo = [];

            // Send to customer if enabled
            // [TEMPORARILY DISABLED] All LINE notifications to customers are disabled
            if (false && $notify['customer'] && !empty($data['customer']['partner_id'])) {
                $user = $this->findLineUserAcrossAccounts($data['customer']['partner_id'], $data['customer']['line_user_id'] ?? null);
                if ($user && $user['line_notification_enabled']) {
                    $this->sendLineFlexMessage(
                        $user['line_user_id'],
                        $user['channel_access_token'],
                        $flexBubble,
                        '💳 แจ้งชำระเงิน - ' . ($data['order_ref'] ?? 'ออเดอร์')
                    );
                    $sentTo[] = 'customer';
                }
            }

            // Send to salesperson if enabled
            if ($notify['salesperson'] && !empty($data['salesperson']['partner_id'])) {
                $user = $this->findLineUserAcrossAccounts($data['salesperson']['partner_id'], $data['salesperson']['line_user_id'] ?? null);
                if ($user && $user['line_notification_enabled']) {
                    // Add salesperson prefix to message
                    $salespersonMessage = "👤 แจ้งเตือนสำหรับเซลล์\n\n";
                    $salespersonMessage .= "BDO ได้รับการยืนยันแล้ว\n";
                    $salespersonMessage .= "ออเดอร์: " . ($data['order_ref'] ?? '') . "\n";
                    $salespersonMessage .= "ลูกค้า: " . ($data['customer']['name'] ?? '') . "\n";
                    $salespersonMessage .= "ยอดเงิน: ฿" . number_format($data['amount_total'] ?? 0, 2);

                    $this->sendLineMessage(
                        $user['line_user_id'],
                        $user['channel_access_token'],
                        $salespersonMessage
                    );
                    $sentTo[] = 'salesperson';
                }
            }

            return $sentTo;

        } catch (Exception $e) {
            error_log('Error in handleBdoConfirmed: ' . $e->getMessage());
            return [];
        }
    }

    public function handleDeliveryDeparted($data, $notify, $template)
    {
        $message = "🚚 การจัดส่งออกเดินทางแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        if (!empty($data['driver']['name'])) {
            $message .= "คนขับ: {$data['driver']['name']}\n";
        }
        if (!empty($data['vehicle']['plate'])) {
            $message .= "ทะเบียนรถ: {$data['vehicle']['plate']}\n";
        }
        if (!empty($data['departure_time'])) {
            $message .= "เวลาออกเดินทาง: {$data['departure_time']}\n";
        }
        if (!empty($data['estimated_arrival'])) {
            $message .= "เวลาถึงโดยประมาณ: {$data['estimated_arrival']}\n";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    public function handleDeliveryCompleted($data, $notify, $template)
    {
        $message = "✅ จัดส่งสำเร็จแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        if (!empty($data['delivery_time'])) {
            $message .= "เวลาจัดส่ง: {$data['delivery_time']}\n";
        }
        if (!empty($data['receiver_name'])) {
            $message .= "ผู้รับ: {$data['receiver_name']}\n";
        }
        if (!empty($data['signature_image'])) {
            $message .= "\n✍️ มีลายเซ็นรับสินค้าแล้ว";
        }
        $message .= "\n\nขอบคุณที่ใช้บริการครับ 🙏";
        
        // DISABLE realtime notification for delivery_completed
        // This event will only be sent via Daily Summary (auto-send at scheduled time)
        // Override notify settings to prevent immediate notification
        $notify['customer'] = false;
        
        return $this->sendNotifications($data, $notify, $message);
    }

    public function handlePaymentConfirmed($data, $notify, $template)
    {
        $message = "💰 ยืนยันการชำระเงินแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "จำนวนเงิน: ฿" . number_format($data['amount'] ?? 0, 2) . "\n";
        $message .= "วิธีชำระ: " . ($data['payment_method'] ?? ($data['payment']['method'] ?? '-')) . "\n";
        $message .= "วันที่: " . ($data['payment_date'] ?? ($data['payment']['date'] ?? '-')) . "\n";
        if (!empty($data['reference']) || !empty($data['payment']['reference'])) {
            $ref = $data['reference'] ?? $data['payment']['reference'];
            $message .= "เลขที่อ้างอิง: {$ref}\n";
        }

        // Update order projection to state=paid so dashboard reflects payment
        // without requiring a separate invoice.paid webhook from Odoo
        $orderId   = $data['order_id']   ?? ($data['data']['payment_id']  ?? null);
        $orderName = $data['order_ref']  ?? ($data['order_name']          ?? null);
        if (empty($orderId) && !empty($data['related_orders'][0])) {
            $orderName = $data['related_orders'][0];
        }
        $lineUserId = $data['customer']['line_user_id'] ?? ($data['line_user_id'] ?? null);

        if ($this->tableExists('odoo_order_projection') && ($orderId || $orderName)) {
            try {
                $where  = [];
                $params = [];
                if ($orderId) {
                    $where[]  = 'order_id = ?';
                    $params[] = (int) $orderId;
                }
                if ($orderName) {
                    $where[]  = 'order_name = ?';
                    $params[] = $orderName;
                }
                $whereClause = implode(' OR ', $where);
                $updateStmt  = $this->db->prepare(
                    "UPDATE odoo_order_projection
                     SET latest_state = 'paid', latest_state_display = 'ชำระเงินแล้ว',
                         updated_at = NOW()
                     WHERE {$whereClause}"
                );
                $updateStmt->execute($params);
            } catch (\Exception $e) {
                error_log('handlePaymentConfirmed projection update error: ' . $e->getMessage());
            }
        }

        return $this->sendNotifications($data, $notify, $message);
    }

    public function handleInvoicePaid($data, $notify, $template)
    {
        $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? '-');
        $customerName = $data['customer']['name'] ?? ($data['customer_name'] ?? '-');
        $amount = isset($data['amount_total']) ? number_format((float) $data['amount_total'], 2) : '0.00';
        $invoiceNumber = $data['invoice_number'] ?? ($data['invoice_ref'] ?? '');

        $message = "📌 แจ้งรับชำระเงินเรียบร้อย\n\n";
        if ($invoiceNumber) {
            $message .= "เลขที่ใบแจ้งหนี้: {$invoiceNumber}\n";
        }
        $message .= "ออเดอร์: {$orderRef}\n";
        $message .= "ลูกค้า: {$customerName}\n";
        $message .= "ยอดเงิน: \u0e3f{$amount}\n";
        $message .= "\nขอบคุณที่ชำระเงิน 🙏";
        return $this->sendNotifications($data, $notify, $message);
    }

    public function handleInvoiceCreated($data, $notify, $template)
    {
        $message = "📄 มีใบแจ้งหนี้ใหม่\n\n";
        $message .= "เลขที่: {$data['invoice_number']}\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "จำนวนเงิน: ฿" . number_format($data['amount_total'], 2) . "\n";
        $message .= "ครบกำหนด: {$data['due_date']}\n";
        if (!empty($data['invoice_url'])) {
            $message .= "\n📥 ดูใบแจ้งหนี้: {$data['invoice_url']}";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    public function handleInvoiceOverdue($data, $notify, $template)
    {
        $message = "⚠️ ใบแจ้งหนี้เกินกำหนดชำระ\n\n";
        $message .= "เลขที่: {$data['invoice_number']}\n";
        $message .= "จำนวนเงิน: ฿" . number_format($data['amount_total'], 2) . "\n";
        $message .= "ครบกำหนด: {$data['due_date']}\n";
        $message .= "เกินมา: {$data['days_overdue']} วัน\n";
        if (!empty($data['late_fee'])) {
            $message .= "ค่าปรับล่าช้า: ฿" . number_format($data['late_fee'], 2) . "\n";
        }
        $message .= "\nกรุณาชำระเงินโดยเร็วที่สุด 🙏";
        if (!empty($data['payment_url'])) {
            $message .= "\n💳 ชำระเงิน: {$data['payment_url']}";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle BDO done event — closes BDO context so stale context is not reused.
     */
    public function handleBdoDone($data, $notify, $template)
    {
        require_once __DIR__ . '/BdoContextManager.php';
        (new BdoContextManager($this->db))->closeContext($data, 'done');

        $bdoRef = $data['bdo_name'] ?? $data['bdo_ref'] ?? ('BDO-' . ($data['bdo_id'] ?? ''));
        $message = "✅ BDO เสร็จสิ้นแล้ว\n\n";
        $message .= "BDO: {$bdoRef}\n";
        if (!empty($data['order_ref'])) $message .= "ออเดอร์: {$data['order_ref']}\n";
        if (isset($data['amount_total'])) $message .= "จำนวนเงิน: ฿" . number_format((float) $data['amount_total'], 2) . "\n";
        if (!empty($data['completion_date'])) $message .= "วันที่: {$data['completion_date']}\n";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle BDO cancelled event — closes BDO context.
     */
    public function handleBdoCancelled($data, $notify, $template)
    {
        require_once __DIR__ . '/BdoContextManager.php';
        (new BdoContextManager($this->db))->closeContext($data, 'cancel');

        $bdoRef = $data['bdo_name'] ?? $data['bdo_ref'] ?? ('BDO-' . ($data['bdo_id'] ?? ''));
        $message = "❌ BDO ถูกยกเลิก\n\n";
        $message .= "BDO: {$bdoRef}\n";
        if (!empty($data['order_ref'])) $message .= "ออเดอร์: {$data['order_ref']}\n";
        if (!empty($data['cancel_reason'])) $message .= "เหตุผล: {$data['cancel_reason']}\n";
        $message .= "\nกรุณาติดต่อเจ้าหน้าที่หากมีข้อสงสัย";
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Handle payment done event
     */
    public function handlePaymentDone($data, $notify, $template)
    {
        $message = "✅ ชำระเงินเรียบร้อยแล้ว\n\n";
        $message .= "ออเดอร์: {$data['order_ref']}\n";
        $message .= "จำนวนเงิน: ฿" . number_format($data['amount'], 2) . "\n";
        $message .= "สถานะ: ชำระครบแล้ว\n";
        if (!empty($data['receipt_url'])) {
            $message .= "\n📄 ดูใบเสร็จ: {$data['receipt_url']}";
        }
        return $this->sendNotifications($data, $notify, $message);
    }

    /**
     * Send notifications to customer and/or salesperson
     */
    private function sendNotifications($data, $notify, $message)
    {
        $sentTo = [];

        $eventCode = $this->currentEvent ?? ($data['event'] ?? 'order.validated');
        $useFlex = in_array($eventCode, [
            'order.validated',
            'order.picker_assigned',
            'order.picking',
            'order.picked',
            'order.packing',
            'order.packed',
            'order.awaiting_payment',
            'order.paid',
            'order.to_delivery',
            'order.in_delivery',
            'order.delivered',
            'invoice.created',
            'invoice.overdue',
            'invoice.paid',
        ], true);

        $flexBubble = null;
        $timelineEvents = [];
        if ($useFlex) {
            try {
                $timelineEvents = $this->getOrderTimelineEventsForNotification($data, $eventCode);
                require_once __DIR__ . '/OdooFlexTemplates.php';
                $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventCode, $data, $message, false, $timelineEvents);
            } catch (Exception $e) {
                error_log('Cannot build Odoo status flex: ' . $e->getMessage());
            }
        }

        $deliveryIdForLog = $this->currentDeliveryId ?? null;

        // Send to customer
        // [TEMPORARILY DISABLED] All LINE notifications to customers are disabled
        if (false && $notify['customer'] && !empty($data['customer']['partner_id'])) {
            $user = $this->findLineUserAcrossAccounts($data['customer']['partner_id'], $data['customer']['line_user_id'] ?? null);
            if ($user && $user['line_notification_enabled']) {
                $t0 = microtime(true);
                $apiResult = null;
                try {
                    if ($flexBubble) {
                        $apiResult = $this->sendLineFlexMessage(
                            $user['line_user_id'],
                            $user['channel_access_token'],
                            $flexBubble,
                            'อัปเดตสถานะออเดอร์'
                        );
                    } else {
                        $apiResult = $this->sendLineMessage($user['line_user_id'], $user['channel_access_token'], $message);
                    }
                    $sentTo[] = 'customer';
                } catch (Exception $e) {
                    $apiResult = ['error' => $e->getMessage()];
                }
                $this->logNotification($deliveryIdForLog, $eventCode, 'customer', $user['line_user_id'], $apiResult, (int)round((microtime(true)-$t0)*1000));
            }
        }

        // Send to salesperson
        if ($notify['salesperson'] && !empty($data['salesperson']['partner_id'])) {
            $user = $this->findLineUserAcrossAccounts($data['salesperson']['partner_id'], $data['salesperson']['line_user_id'] ?? null);
            if ($user && $user['line_notification_enabled']) {
                $t0 = microtime(true);
                $apiResult = null;
                try {
                    if ($flexBubble) {
                        try {
                            require_once __DIR__ . '/OdooFlexTemplates.php';
                            $salesFlexBubble = OdooFlexTemplates::odooStatusUpdate($eventCode, $data, $message, true, $timelineEvents);
                            $apiResult = $this->sendLineFlexMessage(
                                $user['line_user_id'],
                                $user['channel_access_token'],
                                $salesFlexBubble,
                                'แจ้งเตือนสำหรับเซลล์'
                            );
                        } catch (Exception $e) {
                            $salespersonMessage = "👤 แจ้งเตือนสำหรับเซลล์\n\n" . $message;
                            $apiResult = $this->sendLineMessage($user['line_user_id'], $user['channel_access_token'], $salespersonMessage);
                        }
                    } else {
                        $salespersonMessage = "👤 แจ้งเตือนสำหรับเซลล์\n\n" . $message;
                        $apiResult = $this->sendLineMessage($user['line_user_id'], $user['channel_access_token'], $salespersonMessage);
                    }
                    $sentTo[] = 'salesperson';
                } catch (Exception $e) {
                    $apiResult = ['error' => $e->getMessage()];
                }
                $this->logNotification($deliveryIdForLog, $eventCode, 'salesperson', $user['line_user_id'], $apiResult, (int)round((microtime(true)-$t0)*1000));
            }
        }

        return $sentTo;
    }

    /**
     * Log notification attempt to odoo_notification_log
     */
    private function logNotification($deliveryId, $eventType, $recipientType, $lineUserId, $apiResult, $latencyMs)
    {
        if (!$deliveryId) return;
        try {
            $httpStatus = null;
            $errMsg     = null;
            $status     = 'sent';
            if (is_array($apiResult)) {
                $httpStatus = $apiResult['http_status'] ?? ($apiResult['status'] ?? null);
                if (!empty($apiResult['error'])) {
                    $status = 'failed';
                    $errMsg = $apiResult['error'];
                } elseif ($httpStatus && $httpStatus >= 400) {
                    $status = 'failed';
                    $errMsg = json_encode($apiResult);
                }
            }
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id,
                 notification_method, status, line_api_status, line_api_response,
                 error_message, latency_ms, sent_at)
                VALUES (?, ?, ?, ?, 'flex', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $deliveryId, $eventType, $recipientType, $lineUserId,
                $status, $httpStatus,
                $status === 'sent' ? null : json_encode($apiResult),
                $errMsg, $latencyMs,
            ]);
        } catch (Exception $e) {
            error_log('logNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Build compact timeline events from webhook logs for Flex notification.
     */
    private function getOrderTimelineEventsForNotification($data, $eventCode)
    {
        $events = [];

        try {
            $orderId = $data['order_id'] ?? null;
            $orderRef = $data['order_ref'] ?? ($data['order_name'] ?? null);

            $where = [];
            $params = [];

            if (!empty($orderId)) {
                $where[] = 'order_id = ?';
                $params[] = $orderId;
            }

            if (!empty($orderRef)) {
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')) = ?";
                $params[] = $orderRef;
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?";
                $params[] = $orderRef;
            }

            if (!empty($where)) {
                $sql = "SELECT event_type, status, processed_at
                        FROM odoo_webhooks_log
                        WHERE " . implode(' OR ', $where) . "
                        ORDER BY processed_at ASC
                        LIMIT 50";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $events[] = [
                        'event_code' => $row['event_type'] ?? '',
                        'status' => $row['status'] ?? 'success',
                        'timestamp' => !empty($row['processed_at']) ? date('d/m/Y H:i:s', strtotime($row['processed_at'])) : '',
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('Cannot load timeline events: ' . $e->getMessage());
        }

        // Append current event so user always sees the latest status in timeline
        $events[] = [
            'event_code' => $eventCode,
            'status' => 'success',
            'timestamp' => date('d/m/Y H:i:s'),
        ];

        // Keep only recent items to stay within Flex payload limits
        if (count($events) > 8) {
            $events = array_slice($events, -8);
        }

        return $events;
    }

    /**
     * Save BDO context from bdo.confirmed webhook for auto-populating slip uploads.
     * 
     * Stores bdo_id, amount, delivery_type, and QR payload per LINE user
     * so that when a customer sends a slip, Re-Ya can automatically attach
     * the correct bdo_id without requiring manual selection.
     * 
     * @param array $data BDO event data from webhook
     * @return void
     */
    private function saveBdoContext($data)
    {
        try {
            if (!$this->tableExists('odoo_bdo_context')) {
                error_log('[saveBdoContext] odoo_bdo_context table does not exist, skipping');
                return;
            }

            $lineUserId   = $data['customer']['line_user_id'] ?? null;
            $bdoId        = $data['bdo_id'] ?? null;
            $bdoName      = $data['bdo_name'] ?? $data['bdo_ref'] ?? null;
            $amount       = $data['amount_total'] ?? $data['payment']['amount'] ?? null;
            $deliveryType = $data['delivery_type'] ?? null;
            $newState     = $data['new_state'] ?? 'waiting';
            $qrPayload    = $data['payment']['promptpay']['qr_data']['raw_payload'] ?? null;

            if (!$lineUserId || !$bdoId) {
                error_log('[saveBdoContext] Missing line_user_id or bdo_id, skipping');
                return;
            }

            // Save/update statement PDF if available
            $statementPdfPath = null;
            if (!empty($data['statement_pdf']['available']) && !empty($data['statement_pdf']['data'])) {
                try {
                    $pdfDir = __DIR__ . '/../uploads/bdo_statements';
                    if (!is_dir($pdfDir)) {
                        mkdir($pdfDir, 0755, true);
                    }
                    $pdfFilename = 'BDO_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $bdoName ?: $bdoId) . '_Statement.pdf';
                    $pdfPath = $pdfDir . '/' . $pdfFilename;
                    $pdfData = base64_decode($data['statement_pdf']['data']);
                    if ($pdfData && strlen($pdfData) > 100) {
                        file_put_contents($pdfPath, $pdfData);
                        $statementPdfPath = 'uploads/bdo_statements/' . $pdfFilename;
                    }
                } catch (Exception $e) {
                    error_log('[saveBdoContext] Failed to save statement PDF: ' . $e->getMessage());
                }
            }

            $deliveryId = $this->currentDeliveryId;

            $stmt = $this->db->prepare("
                INSERT INTO odoo_bdo_context
                    (line_user_id, bdo_id, bdo_name, amount, delivery_type, state, qr_payload, statement_pdf_path, webhook_delivery_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    bdo_name = COALESCE(VALUES(bdo_name), bdo_name),
                    amount = COALESCE(VALUES(amount), amount),
                    delivery_type = COALESCE(VALUES(delivery_type), delivery_type),
                    state = VALUES(state),
                    qr_payload = COALESCE(VALUES(qr_payload), qr_payload),
                    statement_pdf_path = COALESCE(VALUES(statement_pdf_path), statement_pdf_path),
                    webhook_delivery_id = VALUES(webhook_delivery_id),
                    updated_at = NOW()
            ");

            $stmt->execute([
                $lineUserId,
                (int) $bdoId,
                $bdoName,
                $amount !== null ? (float) $amount : null,
                $deliveryType,
                $newState,
                $qrPayload,
                $statementPdfPath,
                $deliveryId
            ]);

            error_log("[saveBdoContext] Saved: line_user_id=$lineUserId, bdo_id=$bdoId, amount=$amount, delivery_type=$deliveryType");

        } catch (Exception $e) {
            error_log('[saveBdoContext] Error: ' . $e->getMessage());
        }
    }
}
