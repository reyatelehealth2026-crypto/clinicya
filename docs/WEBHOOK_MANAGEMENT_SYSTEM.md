# Webhook Management System Documentation

## Overview

The Webhook Management System provides comprehensive logging, monitoring, and retry capabilities for webhook events in the LINE Telepharmacy Platform. This system is part of the Odoo Dashboard modernization project (Task 9) and ensures reliable webhook processing with detailed tracking and analytics.

## Features

### 1. Webhook Event Logging
- **Detailed Payload Storage**: Complete webhook payloads stored for debugging
- **Event Metadata**: Timestamps, source, status, processing time
- **Error Tracking**: Detailed error messages and stack traces
- **Request/Response Logging**: Full HTTP request and response data

### 2. Webhook Statistics
- **Success Rate Tracking**: Real-time success/failure metrics
- **Performance Metrics**: Average processing time, throughput
- **Event Type Analytics**: Statistics by webhook event type
- **Time-based Aggregation**: Daily, weekly, monthly statistics

### 3. Retry Mechanism
- **Automatic Retry**: Failed webhooks automatically retried with exponential backoff
- **Manual Retry**: Admin interface for manual retry of failed events
- **Retry Limits**: Configurable maximum retry attempts
- **Dead Letter Queue**: Failed events after max retries moved to DLQ

### 4. Monitoring Dashboard
- **Real-time Status**: Live webhook processing status
- **Event Timeline**: Visual timeline of webhook events
- **Filtering & Search**: Advanced filtering by status, type, date range
- **Detailed Event View**: Drill-down into individual webhook events

## Architecture

### Components

```
┌─────────────────────────────────────────────────────────────┐
│                    Webhook Management System                 │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────────┐      ┌──────────────────┐            │
│  │ WebhookLogging   │      │ WebhookIntegration│            │
│  │ Service          │◄─────┤ Helper            │            │
│  │ (classes/)       │      │ (classes/)        │            │
│  └──────────────────┘      └──────────────────┘            │
│           │                          │                       │
│           ▼                          ▼                       │
│  ┌──────────────────────────────────────────────┐          │
│  │         Database Tables                       │          │
│  │  - webhooks_log                              │          │
│  │  - webhook_statistics                        │          │
│  │  - webhook_retry_queue                       │          │
│  └──────────────────────────────────────────────┘          │
│           │                          │                       │
│           ▼                          ▼                       │
│  ┌──────────────────┐      ┌──────────────────┐            │
│  │ API Endpoints    │      │ Cron Jobs        │            │
│  │ (api/)           │      │ (cron/)          │            │
│  │ - webhook-       │      │ - retry_processor│            │
│  │   monitoring.php │      │ - statistics_    │            │
│  │                  │      │   calculator     │            │
│  └──────────────────┘      └──────────────────┘            │
│           │                          │                       │
│           ▼                          ▼                       │
│  ┌──────────────────────────────────────────────┐          │
│  │         Frontend Dashboard                    │          │
│  │  - webhook-monitoring-dashboard.php          │          │
│  │  - Event list with filtering                 │          │
│  │  - Statistics visualization                  │          │
│  │  - Retry interface                           │          │
│  └──────────────────────────────────────────────┘          │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

## Database Schema

### webhooks_log
Stores all webhook events with detailed information.

```sql
CREATE TABLE webhooks_log (
    id VARCHAR(36) PRIMARY KEY,
    line_account_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_source VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'processing', 'success', 'failed', 'retrying') DEFAULT 'pending',
    http_status_code INT NULL,
    processing_time_ms INT NULL,
    error_message TEXT NULL,
    error_details JSON NULL,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_line_account (line_account_id),
    INDEX idx_status (status),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at),
    INDEX idx_next_retry (next_retry_at, status)
);
```

### webhook_statistics
Aggregated statistics for monitoring and analytics.

```sql
CREATE TABLE webhook_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    date_key DATE NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    total_events INT DEFAULT 0,
    successful_events INT DEFAULT 0,
    failed_events INT DEFAULT 0,
    avg_processing_time_ms DECIMAL(10,2) DEFAULT 0,
    success_rate DECIMAL(5,2) GENERATED ALWAYS AS (
        CASE WHEN total_events > 0 
        THEN (successful_events / total_events) * 100 
        ELSE 0 END
    ) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_stats (line_account_id, date_key, event_type),
    INDEX idx_date_key (date_key),
    INDEX idx_line_account (line_account_id)
);
```

### webhook_retry_queue
Queue for managing webhook retries.

```sql
CREATE TABLE webhook_retry_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_log_id VARCHAR(36) NOT NULL,
    retry_attempt INT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    attempted_at TIMESTAMP NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_scheduled (scheduled_at, status),
    INDEX idx_webhook_log (webhook_log_id),
    FOREIGN KEY (webhook_log_id) REFERENCES webhooks_log(id) ON DELETE CASCADE
);
```

## API Endpoints

### GET /api/webhook-monitoring.php?path=logs
Retrieve webhook event logs with pagination and filtering.

**Query Parameters:**
- `page` (int): Page number (default: 1)
- `limit` (int): Records per page (default: 50, max: 100)
- `status` (string): Filter by status (pending, success, failed, retrying)
- `event_type` (string): Filter by event type
- `date_from` (date): Start date filter (YYYY-MM-DD)
- `date_to` (date): End date filter (YYYY-MM-DD)
- `line_account_id` (int): Filter by LINE account

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": "webhook_123",
            "line_account_id": 1,
            "event_type": "order.created",
            "event_source": "line",
            "status": "success",
            "http_status_code": 200,
            "processing_time_ms": 145,
            "retry_count": 0,
            "created_at": "2026-03-17 10:30:00",
            "processed_at": "2026-03-17 10:30:01"
        }
    ],
    "meta": {
        "page": 1,
        "limit": 50,
        "total": 1247,
        "total_pages": 25
    }
}
```

### GET /api/webhook-monitoring.php?path=stats
Get webhook statistics and metrics.

**Query Parameters:**
- `line_account_id` (int): Filter by LINE account
- `date_from` (date): Start date (default: 7 days ago)
- `date_to` (date): End date (default: today)
- `event_type` (string): Filter by event type

**Response:**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_events": 1247,
            "successful_events": 1189,
            "failed_events": 58,
            "success_rate": 95.35,
            "avg_processing_time_ms": 156.8
        },
        "by_event_type": [
            {
                "event_type": "order.created",
                "total_events": 456,
                "success_rate": 98.2,
                "avg_processing_time_ms": 142.3
            }
        ],
        "daily_stats": [
            {
                "date": "2026-03-17",
                "total_events": 189,
                "successful_events": 182,
                "failed_events": 7,
                "success_rate": 96.3
            }
        ]
    }
}
```

### POST /api/webhook-monitoring.php?path=retry
Manually retry a failed webhook event.

**Request Body:**
```json
{
    "webhook_id": "webhook_123",
    "force": false
}
```

**Response:**
```json
{
    "success": true,
    "message": "Webhook retry scheduled successfully",
    "data": {
        "webhook_id": "webhook_123",
        "retry_attempt": 2,
        "scheduled_at": "2026-03-17 10:35:00"
    }
}
```

### GET /api/webhook-monitoring.php?path=event/:id
Get detailed information about a specific webhook event.

**Response:**
```json
{
    "success": true,
    "data": {
        "id": "webhook_123",
        "line_account_id": 1,
        "event_type": "order.created",
        "event_source": "odoo",
        "payload": {
            "order_id": "SO001",
            "customer_name": "John Doe",
            "total_amount": 1500.00
        },
        "status": "success",
        "http_status_code": 200,
        "processing_time_ms": 145,
        "error_message": null,
        "error_details": null,
        "retry_count": 0,
        "max_retries": 3,
        "created_at": "2026-03-17 10:30:00",
        "processed_at": "2026-03-17 10:30:01",
        "retry_history": []
    }
}
```

## Service Classes

### WebhookLoggingService
Main service for webhook event logging.

**Location:** `classes/WebhookLoggingService.php`

**Key Methods:**
```php
// Log a new webhook event
public function logWebhookEvent(
    int $lineAccountId,
    string $eventType,
    string $eventSource,
    array $payload,
    string $status = 'pending'
): string

// Update webhook status
public function updateWebhookStatus(
    string $webhookId,
    string $status,
    ?int $httpStatusCode = null,
    ?int $processingTimeMs = null,
    ?string $errorMessage = null
): bool

// Get webhook logs with filtering
public function getWebhookLogs(
    ?int $lineAccountId = null,
    ?string $status = null,
    ?string $eventType = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    int $page = 1,
    int $limit = 50
): array

// Schedule webhook retry
public function scheduleRetry(
    string $webhookId,
    bool $force = false
): bool
```

### WebhookIntegrationHelper
Helper class for webhook integration and processing.

**Location:** `classes/WebhookIntegrationHelper.php`

**Key Methods:**
```php
// Process webhook with automatic logging
public function processWebhook(
    int $lineAccountId,
    string $eventType,
    string $eventSource,
    array $payload,
    callable $handler
): array

// Get webhook statistics
public function getStatistics(
    ?int $lineAccountId = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $eventType = null
): array

// Calculate success rate
public function calculateSuccessRate(
    int $lineAccountId,
    string $dateFrom,
    string $dateTo
): float
```

## Cron Jobs

### webhook_retry_processor.php
Processes failed webhooks from the retry queue.

**Schedule:** Every 5 minutes
```bash
*/5 * * * * php /path/to/cron/webhook_retry_processor.php
```

**Functionality:**
- Fetches pending retries from queue
- Processes webhooks with exponential backoff
- Updates retry status and counts
- Moves to dead letter queue after max retries

### webhook_statistics_calculator.php
Calculates and updates webhook statistics.

**Schedule:** Every hour
```bash
0 * * * * php /path/to/cron/webhook_statistics_calculator.php
```

**Functionality:**
- Aggregates webhook events by date and type
- Calculates success rates and averages
- Updates webhook_statistics table
- Generates daily/weekly/monthly reports

## Frontend Dashboard

### webhook-monitoring-dashboard.php
Admin dashboard for webhook monitoring and management.

**Location:** `webhook-monitoring-dashboard.php`

**Features:**
- Real-time webhook event list
- Advanced filtering (status, type, date range)
- Statistics visualization (charts and graphs)
- Detailed event viewer with payload inspection
- Manual retry interface
- Export functionality (CSV, JSON)

**Access Control:**
- Requires admin or super_admin role
- Multi-account support with account filtering

## Usage Examples

### Logging a Webhook Event

```php
require_once 'classes/WebhookLoggingService.php';

$webhookLogger = new WebhookLoggingService();

// Log incoming webhook
$webhookId = $webhookLogger->logWebhookEvent(
    lineAccountId: 1,
    eventType: 'order.created',
    eventSource: 'odoo',
    payload: [
        'order_id' => 'SO001',
        'customer_name' => 'John Doe',
        'total_amount' => 1500.00
    ],
    status: 'pending'
);

// Process webhook
try {
    // ... webhook processing logic ...
    
    // Update success status
    $webhookLogger->updateWebhookStatus(
        webhookId: $webhookId,
        status: 'success',
        httpStatusCode: 200,
        processingTimeMs: 145
    );
} catch (Exception $e) {
    // Update failed status
    $webhookLogger->updateWebhookStatus(
        webhookId: $webhookId,
        status: 'failed',
        httpStatusCode: 500,
        errorMessage: $e->getMessage()
    );
    
    // Schedule retry
    $webhookLogger->scheduleRetry($webhookId);
}
```

### Using WebhookIntegrationHelper

```php
require_once 'classes/WebhookIntegrationHelper.php';

$webhookHelper = new WebhookIntegrationHelper();

// Process webhook with automatic logging
$result = $webhookHelper->processWebhook(
    lineAccountId: 1,
    eventType: 'order.created',
    eventSource: 'odoo',
    payload: $webhookPayload,
    handler: function($payload) {
        // Your webhook processing logic
        $order = createOrder($payload);
        return ['order_id' => $order->id];
    }
);

if ($result['success']) {
    echo "Webhook processed successfully";
} else {
    echo "Webhook processing failed: " . $result['error'];
}
```

### Retrieving Statistics

```php
require_once 'classes/WebhookIntegrationHelper.php';

$webhookHelper = new WebhookIntegrationHelper();

// Get statistics for the last 7 days
$stats = $webhookHelper->getStatistics(
    lineAccountId: 1,
    dateFrom: date('Y-m-d', strtotime('-7 days')),
    dateTo: date('Y-m-d')
);

echo "Total Events: " . $stats['summary']['total_events'] . "\n";
echo "Success Rate: " . $stats['summary']['success_rate'] . "%\n";
echo "Avg Processing Time: " . $stats['summary']['avg_processing_time_ms'] . "ms\n";
```

## Configuration

### Retry Settings

Configure retry behavior in `config/config.php`:

```php
// Webhook retry configuration
define('WEBHOOK_MAX_RETRIES', 3);
define('WEBHOOK_RETRY_DELAY_BASE', 60); // seconds
define('WEBHOOK_RETRY_DELAY_MULTIPLIER', 2); // exponential backoff

// Retry delays: 60s, 120s, 240s
```

### Statistics Calculation

Configure statistics calculation in `config/config.php`:

```php
// Webhook statistics configuration
define('WEBHOOK_STATS_RETENTION_DAYS', 90);
define('WEBHOOK_STATS_AGGREGATION_INTERVAL', 3600); // 1 hour
```

## Monitoring and Alerts

### Key Metrics to Monitor

1. **Success Rate**: Should be > 95%
2. **Average Processing Time**: Should be < 500ms
3. **Failed Events**: Monitor for spikes
4. **Retry Queue Size**: Should remain low
5. **Dead Letter Queue**: Investigate all entries

### Alert Thresholds

```php
// Configure in monitoring system
$alertThresholds = [
    'success_rate_min' => 95.0,
    'avg_processing_time_max' => 500,
    'failed_events_threshold' => 50,
    'retry_queue_size_max' => 100
];
```

## Troubleshooting

### High Failure Rate

1. Check error messages in webhook logs
2. Verify external service availability
3. Review payload validation logic
4. Check network connectivity

### Slow Processing

1. Review processing time metrics
2. Check database query performance
3. Optimize webhook handler logic
4. Consider async processing

### Retry Queue Buildup

1. Check retry processor cron job status
2. Verify max retries configuration
3. Review dead letter queue entries
4. Investigate recurring failures

## Security Considerations

### Webhook Validation

Always validate webhook signatures:

```php
// Validate LINE webhook signature
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');
$expectedSignature = hash_hmac('sha256', $payload, LINE_CHANNEL_SECRET);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

### Access Control

- Webhook endpoints should validate API keys
- Admin dashboard requires authentication
- Sensitive payload data should be encrypted
- Implement rate limiting on webhook endpoints

## Performance Optimization

### Database Indexes

Ensure proper indexes exist:

```sql
-- Critical indexes for performance
CREATE INDEX idx_webhook_status_retry ON webhooks_log(status, next_retry_at);
CREATE INDEX idx_webhook_created_account ON webhooks_log(created_at, line_account_id);
CREATE INDEX idx_stats_date_account ON webhook_statistics(date_key, line_account_id);
```

### Caching

Implement caching for frequently accessed data:

```php
// Cache webhook statistics
$cacheKey = "webhook_stats_{$lineAccountId}_{$dateFrom}_{$dateTo}";
$stats = $cache->get($cacheKey);

if (!$stats) {
    $stats = $webhookHelper->getStatistics($lineAccountId, $dateFrom, $dateTo);
    $cache->set($cacheKey, $stats, 300); // 5 minutes
}
```

## Future Enhancements

### Planned Features

1. **Real-time Monitoring**: WebSocket-based live updates
2. **Advanced Analytics**: ML-based anomaly detection
3. **Webhook Replay**: Ability to replay historical webhooks
4. **Custom Alerting**: Configurable alert rules and notifications
5. **Webhook Transformation**: Pre-processing and transformation rules
6. **Multi-region Support**: Distributed webhook processing

## Related Documentation

- [API Documentation](/docs/API_CUSTOMER_MANAGEMENT.md)
- [Architecture Overview](/docs/ARCHITECTURE.md)
- [Deployment Guide](/DEPLOYMENT_GUIDE_TH.md)

## Support

For issues or questions about the Webhook Management System:

1. Check the troubleshooting section above
2. Review webhook logs in the admin dashboard
3. Check cron job execution logs
4. Contact the development team with specific error messages

---

**Last Updated:** May 17, 2026  
**Version:** 1.0.0  
**Status:** Production Ready ✅
