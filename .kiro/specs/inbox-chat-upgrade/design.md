# Design Document: Inbox Chat Upgrade

## Overview

อัพเกรดระบบ Inbox Chat ให้มีประสิทธิภาพสูงขึ้น รองรับ Real-time messaging, Quick Reply Templates, Conversation Assignment, และ Performance Optimization สำหรับการจัดการแชทลูกค้าจำนวนมาก

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Frontend (inbox.php)                      │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐   │
│  │ Conversation │  │    Chat      │  │   Customer Context   │   │
│  │    List      │  │    Panel     │  │       Panel          │   │
│  │              │  │              │  │                      │   │
│  │ - Virtual    │  │ - Messages   │  │ - Profile            │   │
│  │   Scrolling  │  │ - Input      │  │ - Orders             │   │
│  │ - Search     │  │ - Quick      │  │ - Notes              │   │
│  │ - Filters    │  │   Reply      │  │ - Tags               │   │
│  └──────────────┘  └──────────────┘  └──────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      API Layer (api/inbox.php)                   │
├─────────────────────────────────────────────────────────────────┤
│  - GET /conversations     - Paginated list with filters         │
│  - GET /messages/:id      - Paginated messages for conversation │
│  - POST /messages         - Send message                        │
│  - GET /templates         - Quick reply templates               │
│  - POST /assignments      - Assign conversation                 │
│  - GET /analytics         - Response time stats                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Service Layer (classes/)                    │
├─────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  │
│  │ InboxService    │  │ TemplateService │  │ AnalyticsService│  │
│  │                 │  │                 │  │                 │  │
│  │ - getConversations│ - getTemplates  │  │ - getResponseTime│ │
│  │ - getMessages   │  │ - createTemplate│  │ - getSLAStatus  │  │
│  │ - sendMessage   │  │ - fillPlaceholders│ - getStats      │  │
│  │ - searchMessages│  │                 │  │                 │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Database Layer                              │
├─────────────────────────────────────────────────────────────────┤
│  messages (indexed: user_id, created_at, direction)             │
│  quick_reply_templates (new table)                              │
│  conversation_assignments (new table)                           │
│  customer_notes (new table)                                     │
│  message_analytics (new table)                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. InboxService

```php
class InboxService {
    /**
     * Get paginated conversations with filters
     * @param int $accountId LINE account ID
     * @param array $filters ['status', 'tag_id', 'assigned_to', 'search', 'date_from', 'date_to']
     * @param int $page Page number
     * @param int $limit Items per page (default 50)
     * @return array ['conversations' => [], 'total' => int, 'page' => int]
     */
    public function getConversations(int $accountId, array $filters = [], int $page = 1, int $limit = 50): array;
    
    /**
     * Get paginated messages for a conversation
     * @param int $userId User ID
     * @param int $page Page number
     * @param int $limit Messages per page (default 50)
     * @return array ['messages' => [], 'total' => int, 'has_more' => bool]
     */
    public function getMessages(int $userId, int $page = 1, int $limit = 50): array;
    
    /**
     * Search messages across all conversations
     * @param int $accountId LINE account ID
     * @param string $query Search query
     * @return array Matching conversations with highlighted results
     */
    public function searchMessages(int $accountId, string $query): array;
    
    /**
     * Assign conversation to admin
     * @param int $userId Customer user ID
     * @param int $adminId Admin user ID
     * @return bool Success
     */
    public function assignConversation(int $userId, int $adminId): bool;
    
    /**
     * Get conversations assigned to specific admin
     * @param int $adminId Admin user ID
     * @return array Assigned conversations
     */
    public function getAssignedConversations(int $adminId): array;
}
```

### 2. TemplateService

```php
class TemplateService {
    /**
     * Get all quick reply templates
     * @param int $accountId LINE account ID
     * @param string $search Optional search query
     * @return array Templates with usage stats
     */
    public function getTemplates(int $accountId, string $search = ''): array;
    
    /**
     * Create new template
     * @param int $accountId LINE account ID
     * @param string $name Template name
     * @param string $content Template content with placeholders
     * @param string $category Optional category
     * @return int Template ID
     */
    public function createTemplate(int $accountId, string $name, string $content, string $category = ''): int;
    
    /**
     * Fill placeholders in template with customer data
     * @param string $template Template content
     * @param array $customerData Customer data ['name', 'phone', 'email', etc.]
     * @return string Filled template
     */
    public function fillPlaceholders(string $template, array $customerData): string;
    
    /**
     * Record template usage
     * @param int $templateId Template ID
     * @return void
     */
    public function recordUsage(int $templateId): void;
}
```

### 3. AnalyticsService

```php
class AnalyticsService {
    /**
     * Calculate average response time
     * @param int $accountId LINE account ID
     * @param string $period 'day', 'week', 'month'
     * @return float Average response time in seconds
     */
    public function getAverageResponseTime(int $accountId, string $period = 'day'): float;
    
    /**
     * Get conversations exceeding SLA
     * @param int $accountId LINE account ID
     * @param int $slaSeconds SLA threshold in seconds
     * @return array Conversations exceeding SLA
     */
    public function getConversationsExceedingSLA(int $accountId, int $slaSeconds): array;
    
    /**
     * Record response time for a message
     * @param int $messageId Message ID
     * @param int $responseTimeSeconds Response time in seconds
     * @return void
     */
    public function recordResponseTime(int $messageId, int $responseTimeSeconds): void;
    
    /**
     * Get time since last customer message
     * @param int $userId User ID
     * @return int Seconds since last message
     */
    public function getTimeSinceLastMessage(int $userId): int;
}
```

## Data Models

### New Tables

```sql
-- Quick Reply Templates
CREATE TABLE quick_reply_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(50) DEFAULT '',
    usage_count INT DEFAULT 0,
    last_used_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account (line_account_id),
    INDEX idx_category (category)
);

-- Conversation Assignments
CREATE TABLE conversation_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Customer user ID',
    assigned_to INT NOT NULL COMMENT 'Admin user ID',
    assigned_by INT NULL COMMENT 'Who assigned',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'resolved', 'transferred') DEFAULT 'active',
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_user (user_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status)
);

-- Customer Notes
CREATE TABLE customer_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NOT NULL,
    note TEXT NOT NULL,
    is_pinned TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_admin (admin_id)
);

-- Message Analytics
CREATE TABLE message_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    user_id INT NOT NULL,
    admin_id INT NULL,
    response_time_seconds INT NULL COMMENT 'Time to respond in seconds',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
);
```

### Indexes for Existing Tables

```sql
-- Add indexes to messages table for performance
ALTER TABLE messages 
    ADD INDEX idx_user_direction (user_id, direction),
    ADD INDEX idx_account_created (line_account_id, created_at DESC),
    ADD INDEX idx_is_read (is_read, direction);

-- Add indexes to users table
ALTER TABLE users
    ADD INDEX idx_account_last_msg (line_account_id, last_message_at DESC);
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Template Placeholder Replacement
*For any* template string containing placeholders like {name}, {phone}, {email} and any customer data object, calling fillPlaceholders should replace all placeholders with corresponding customer values, and no placeholder syntax should remain in the output.
**Validates: Requirements 2.3**

### Property 2: Template Round-Trip Consistency
*For any* valid template data (name, content, category), creating a template and then retrieving it should return equivalent data.
**Validates: Requirements 2.4**

### Property 3: Assignment Filter Correctness
*For any* admin ID and any set of conversations with various assignments, filtering by that admin should return only conversations where assigned_to equals that admin ID.
**Validates: Requirements 3.3**

### Property 4: Customer Note Round-Trip
*For any* valid note data (user_id, admin_id, note text), saving a note and then retrieving notes for that user should include the saved note with identical content.
**Validates: Requirements 4.5**

### Property 5: Search Result Relevance
*For any* search query and any set of conversations, all returned results should contain the query string in at least one of: customer name, message content, or tag name.
**Validates: Requirements 5.1**

### Property 6: Status Filter Correctness
*For any* status filter value and any set of conversations, all returned conversations should have a status matching the filter.
**Validates: Requirements 5.2**

### Property 7: Tag Filter Correctness
*For any* tag ID filter and any set of customers, all returned customers should have that tag assigned.
**Validates: Requirements 5.3**

### Property 8: Date Range Filter Correctness
*For any* date range (from, to) and any set of conversations, all returned conversations should have last_message_at within the specified range (inclusive).
**Validates: Requirements 5.4**

### Property 9: Average Response Time Calculation
*For any* set of response times, the calculated average should equal the sum of all response times divided by the count.
**Validates: Requirements 6.1**

### Property 10: SLA Violation Detection
*For any* conversation with a response time and any SLA threshold, the conversation should be flagged as exceeding SLA if and only if response_time > sla_threshold.
**Validates: Requirements 6.2**

### Property 11: Response Time Recording
*For any* admin response to a customer message, the system should record a response_time equal to the difference between the admin message timestamp and the preceding customer message timestamp.
**Validates: Requirements 6.4**

### Property 12: Time Since Last Message Calculation
*For any* conversation with messages, the time since last customer message should equal current_time minus the timestamp of the most recent incoming message.
**Validates: Requirements 6.5**

### Property 13: Message Pagination Limit
*For any* conversation and any page request, the returned messages should contain at most the specified limit (default 50).
**Validates: Requirements 11.3**

## Error Handling

1. **Database Connection Errors**: Return 503 Service Unavailable with retry-after header
2. **Invalid User/Conversation**: Return 404 Not Found
3. **Permission Denied**: Return 403 Forbidden when admin tries to access unassigned conversation
4. **Rate Limiting**: Return 429 Too Many Requests when polling too frequently
5. **LINE API Errors**: Queue message for retry, show pending status to user

## Testing Strategy

### Unit Tests
- Test TemplateService.fillPlaceholders with various placeholder combinations
- Test AnalyticsService calculations with known data sets
- Test filter logic in InboxService

### Property-Based Tests
- Use PHPUnit with data providers for property-based testing
- Generate random templates, customer data, and verify placeholder replacement
- Generate random conversations and verify filter correctness
- Generate random response times and verify average calculation

### Integration Tests
- Test full flow: receive message → display in inbox → send reply → record analytics
- Test assignment workflow: assign → filter → reassign
- Test search across multiple conversations

### Performance Tests
- Load test with 1000+ conversations
- Measure query times with large message tables
- Test virtual scrolling with many items
