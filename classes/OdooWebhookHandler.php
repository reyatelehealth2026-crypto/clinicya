<?php
/**
 * Odoo Webhook Handler - Signature Verification Update
 * 
 * This file contains the updated verifySignature method that supports
 * both new (standard HMAC-SHA256 on payload) and legacy (timestamp.payload)
 * signature formats.
 * 
 * CHANGELOG:
 * - Added dual-format signature verification
 * - New format: sha256=HMAC-SHA256(payload, secret) - tried first
 * - Legacy format: sha256=HMAC-SHA256(timestamp.payload, secret) - fallback
 * - Added detailed logging for signature format detection
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
     * Supports dual signature formats for zero-downtime migration:
     * - NEW format (Odoo v16+): sha256=hash_hmac('sha256', $payload, $secret)
     * - LEGACY format: sha256=hash_hmac('sha256', timestamp.payload, $secret)
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

        // ============================================================
        // DUAL-FORMAT SIGNATURE VERIFICATION
        // ============================================================
        
        $signatureFormatUsed = null;
        $isValid = false;

        // --- FORMAT 1: NEW STANDARD (Odoo v16+) ---
        // Format: sha256=hash_hmac('sha256', $payload, $secret)
        // The payload is signed directly without timestamp concatenation
        $expectedSignatureNew = 'sha256=' . hash_hmac('sha256', $payload, $this->webhookSecret);

        if (hash_equals($signature, $expectedSignatureNew)) {
            $signatureFormatUsed = 'new_standard';
            $isValid = true;
        }

        // --- FORMAT 2: LEGACY (Odoo v11-v15) ---
        // Format: sha256=hash_hmac('sha256', timestamp.payload, $secret)
        // The timestamp is concatenated with the payload using a dot separator
        if (!$isValid) {
            $legacyData = $timestampInt . '.' . $payload;
            $legacySignature = 'sha256=' . hash_hmac('sha256', $legacyData, $this->webhookSecret);

            if (hash_equals($signature, $legacySignature)) {
                $signatureFormatUsed = 'legacy_timestamp_payload';
                $isValid = true;
            }
        }

        // --- Debug Logging ---
        if (defined('ODOO_WEBHOOK_SIGNATURE_DEBUG') && ODOO_WEBHOOK_SIGNATURE_DEBUG) {
            error_log('Signature Debug:');
            error_log('  Secret: ' . substr($this->webhookSecret, 0, 10) . '...');
            error_log('  Payload length: ' . strlen($payload));
            error_log('  Expected (new): ' . substr($expectedSignatureNew, 0, 30) . '...');
            if (!$isValid || $signatureFormatUsed === 'legacy_timestamp_payload') {
                error_log('  Expected (legacy): ' . substr($legacySignature ?? '', 0, 30) . '...');
            }
            error_log('  Received: ' . substr($signature, 0, 30) . '...');
            error_log('  Format detected: ' . ($signatureFormatUsed ?? 'none'));
            error_log('  Payload preview: ' . substr($payload, 0, 120));
        }

        // --- Log Result ---
        if ($isValid) {
            $logContext = array_merge($context, [
                'signature_format' => $signatureFormatUsed,
                'timestamp_delta' => $deltaSeconds
            ]);
            
            if ($legacyDriftAccepted) {
                $this->logSignatureEvent('signature_accepted_with_drift', $logContext);
            } else {
                $this->logSignatureEvent('signature_accepted', $logContext);
            }
            
            if ($signatureFormatUsed === 'legacy_timestamp_payload') {
                error_log('Webhook used LEGACY signature format (timestamp.payload). Consider updating Odoo module.');
            }
            
            return true;
        }

        // --- Signature Mismatch: Log detailed error ---
        $this->logSignatureEvent('signature_mismatch', array_merge($context, [
            'expected_new_preview' => $this->maskSignatureValue($expectedSignatureNew),
            'expected_legacy_preview' => isset($legacySignature) ? $this->maskSignatureValue($legacySignature) : null
        ]));
        
        error_log('Webhook signature verification failed: delivery_id=' . ($context['delivery_id'] ?? '-') . ', event=' . ($context['event'] ?? 'unknown'));
        error_log('  Expected (new): ' . substr($expectedSignatureNew, 0, 40) . '...');
        error_log('  Expected (legacy): ' . substr($legacySignature ?? 'N/A', 0, 40) . '...');
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

    // ===================================================================
    // Additional methods would go here (findLineUserAcrossAccounts, 
    // routeEvent, processWebhook, etc.)
    // These are omitted for brevity as they don't relate to signature verification
    // ===================================================================
}
