<?php
/**
 * Odoo Webhook Endpoint
 * 
 * Receives webhooks from Odoo ERP and processes them.
 * Must respond within 5 seconds.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';

// Master kill-switch — return 410 Gone when Odoo integration is disabled for this tenant.
if (!defined('ODOO_INTEGRATION_ENABLED') || ODOO_INTEGRATION_ENABLED !== true) {
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'error' => 'Odoo integration is not enabled for this tenant',
        'error_code' => 'ODOO_INTEGRATION_DISABLED'
    ]);
    exit;
}

// Ensure legacy Database singleton is loaded before wrapper usage
if (!class_exists('Database', false)) {
    require_once __DIR__ . '/../../config/database.php';
}

require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/OdooWebhookHandler.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
        'error_code' => 'INVALID_METHOD'
    ]);
    exit;
}

$startedAt = microtime(true);
$handler = null;
$deliveryId = null;
$event = null;
$payload = file_get_contents('php://input');
$payloadForLog = [];

try {
    // Get headers
    $signature = $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? null;
    $timestamp = $_SERVER['HTTP_X_ODOO_TIMESTAMP'] ?? null;
    $deliveryId = $_SERVER['HTTP_X_ODOO_DELIVERY_ID'] ?? null;
    $eventType = $_SERVER['HTTP_X_ODOO_EVENT'] ?? null;
    $lineAccountIdHeader = $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] ?? null;
    $sourceIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    if ($sourceIp && strpos($sourceIp, ',') !== false) {
        $sourceIp = trim(explode(',', $sourceIp)[0]);
    }

    if (!$deliveryId) {
        throw new Exception('Missing X-Odoo-Delivery-Id header', 400);
    }

    // Initialize handler as early as possible so malformed payloads are still logged.
    $pdo = Database::getInstance()->getConnection();
    $handler = new OdooWebhookHandler($pdo);

    // Register receipt first for observability/idempotency (event may still be unknown here).
    $receipt = $handler->registerWebhookReceipt(
        $deliveryId,
        $eventType ?: 'unknown',
        $payload,
        $signature,
        $timestamp,
        $sourceIp,
        $lineAccountIdHeader !== null ? (int) $lineAccountIdHeader : null
    );

    // Check for duplicate (idempotency)
    if (!empty($receipt['is_duplicate'])) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status' => 'duplicate',
            'message' => 'Webhook already processed (duplicate)',
            'delivery_id' => $deliveryId,
            'received_at' => date('c')
        ]);
        exit;
    }

    // Parse request body (handle BOM and encoding issues)
    $payload = trim($payload);
    // Remove UTF-8 BOM if present
    if (substr($payload, 0, 3) === "\xEF\xBB\xBF") {
        $payload = substr($payload, 3);
    }
    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg(), 400);
    }

    $payloadForLog = is_array($data) ? $data : [];

    // Odoo sends event type in header (X-Odoo-Event) or in payload
    $event = $data['event'] ?? $eventType;
    if (!$event) {
        throw new Exception('Missing event type', 400);
    }

    // Validate required signed headers
    if (!$signature) {
        throw new Exception('Missing X-Odoo-Signature header', 400);
    }

    if (!$timestamp) {
        throw new Exception('Missing X-Odoo-Timestamp header', 400);
    }

    $signatureMeta = [
        'delivery_id' => $deliveryId,
        'event' => $event,
        'headers' => [
            'X-Odoo-Signature' => $signature,
            'X-Odoo-Timestamp' => $timestamp,
            'X-Odoo-Delivery-Id' => $deliveryId,
            'X-Odoo-Event' => $eventType,
            'X-Line-Account-Id' => $lineAccountIdHeader,
        ],
        'source_ip' => $sourceIp,
        'line_account_id' => $lineAccountIdHeader !== null ? (int) $lineAccountIdHeader : null,
    ];

    // Verify signature
    if (!$handler->verifySignature($payload, $signature, (int) $timestamp, $signatureMeta)) {
        throw new Exception('Invalid webhook signature', 401);
    }

    $handler->markWebhookProcessing($deliveryId);

    // Extract event data
    // Odoo payload can be nested under 'data' key or at top level
    $eventData = $data['data'] ?? $data;

    // Default: notify customer=true (most events should notify the customer)
    $notify = $data['notify'] ?? ['customer' => true, 'salesperson' => false];
    $messageTemplate = $data['message_template'] ?? [];

    if (defined('ODOO_WEBHOOK_DEBUG_LOG') && ODOO_WEBHOOK_DEBUG_LOG) {
        error_log("Webhook processing: event=$event, customer_id=" . ($eventData['customer']['id'] ?? 'null') .
            ", line_user_id=" . ($eventData['customer']['line_user_id'] ?? 'null'));
    }

    // Process webhook
    $result = $handler->processWebhook($deliveryId, $event, $eventData, $notify, $messageTemplate);

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $handler->markWebhookSuccess(
        $deliveryId,
        $event,
        $result['payload'] ?? $eventData,
        $result['line_user_id'] ?? null,
        $result['order_id'] ?? null,
        $durationMs
    );

    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'success',
        'received_at' => date('c'),
        'delivery_id' => $deliveryId,
        'event' => $event,
        'duration_ms' => $durationMs,
        'sent_to' => $result['sent_to'] ?? []
    ]);

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorCode = resolveWebhookErrorCode($errorMessage, (int) $e->getCode());

    $isRetriable = false;
    if ($handler instanceof OdooWebhookHandler) {
        $isRetriable = $handler->isRetriableError($errorMessage);
    }
    
    // Database errors should always be retriable
    if (strpos($errorMessage, 'Database error') !== false || 
        strpos($errorMessage, 'MySQL server has gone away') !== false ||
        strpos($errorMessage, 'Lost connection') !== false ||
        strpos($errorMessage, 'Failed to log webhook') !== false ||
        $e->getCode() === 500) {
        $isRetriable = true;
    }

    if ($handler instanceof OdooWebhookHandler && !empty($deliveryId)) {
        $handler->markWebhookFailure(
            $deliveryId,
            $event ?: 'unknown',
            !empty($payloadForLog) ? $payloadForLog : ($payload !== '' ? $payload : []),
            $errorCode,
            $errorMessage,
            $isRetriable
        );
    }

    $httpCode = resolveWebhookHttpCode($errorCode, $isRetriable, (int) $e->getCode());

    error_log('Webhook error [' . $errorCode . ']: ' . $errorMessage . ' (delivery_id=' . ($deliveryId ?? '-') . ')');

    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'status' => $isRetriable ? 'retry' : 'failed',
        'error' => $errorMessage,
        'error_code' => $errorCode,
        'retriable' => $isRetriable,
        'delivery_id' => $deliveryId,
        'received_at' => date('c')
    ]);
}

/**
 * Map exception details to stable webhook error code taxonomy.
 *
 * @param string $message
 * @param int $code
 * @return string
 */
function resolveWebhookErrorCode($message, $code = 0)
{
    $normalized = strtolower(trim((string) $message));

    if ($normalized === '') {
        return 'UNKNOWN_ERROR';
    }

    $map = [
        'invalid json' => 'INVALID_JSON',
        'missing x-odoo-signature' => 'MISSING_SIGNATURE',
        'missing x-odoo-timestamp' => 'MISSING_TIMESTAMP',
        'missing x-odoo-delivery-id' => 'MISSING_DELIVERY_ID',
        'invalid webhook signature' => 'INVALID_SIGNATURE',
        'missing event type' => 'MISSING_EVENT_TYPE',
        'method not allowed' => 'INVALID_METHOD'
    ];

    foreach ($map as $needle => $errorCode) {
        if (strpos($normalized, $needle) !== false) {
            return $errorCode;
        }
    }

    if ($code >= 500) {
        return 'PROCESSING_ERROR';
    }

    return 'PROCESSING_ERROR';
}

/**
 * Resolve HTTP status code for webhook failures.
 *
 * @param string $errorCode
 * @param bool $isRetriable
 * @param int $exceptionCode
 * @return int
 */
function resolveWebhookHttpCode($errorCode, $isRetriable = false, $exceptionCode = 0)
{
    $fixedCodes = [
        'INVALID_JSON' => 400,
        'MISSING_SIGNATURE' => 400,
        'MISSING_TIMESTAMP' => 400,
        'MISSING_DELIVERY_ID' => 400,
        'INVALID_SIGNATURE' => 401,
        'MISSING_EVENT_TYPE' => 400,
        'INVALID_METHOD' => 405
    ];

    if (isset($fixedCodes[$errorCode])) {
        return $fixedCodes[$errorCode];
    }

    if ($isRetriable) {
        return 500;
    }

    if ($exceptionCode >= 400 && $exceptionCode < 600) {
        return $exceptionCode;
    }

    return 400;
}
