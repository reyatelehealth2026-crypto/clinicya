# Inbox v2 Delta API Documentation

## Overview

The `getConversationsDelta` method provides efficient conversation retrieval with delta updates and cursor-based pagination for the Inbox v2 performance upgrade.

## Method Signature

```php
public function getConversationsDelta(
    int $accountId, 
    int $since = 0, 
    ?string $cursor = null, 
    int $limit = 50
): array
```

## Parameters

- **$accountId** (int, required): LINE account ID to fetch conversations for
- **$since** (int, optional): Unix timestamp for delta updates. Only returns conversations updated after this time. Default: 0 (all conversations)
- **$cursor** (string|null, optional): Pagination cursor (last_message_at timestamp from previous response). Default: null
- **$limit** (int, optional): Number of conversations to return per page. Default: 50, Max: 100

## Return Value

Returns an associative array with the following structure:

```php
[
    'conversations' => [
        [
            'id' => int,                    // User ID
            'display_name' => string,       // Customer name
            'picture_url' => string,        // Profile picture URL
            'last_message_at' => string,    // Last message timestamp (YYYY-MM-DD HH:MM:SS)
            'unread_count' => int,          // Number of unread messages
            'last_message_preview' => string, // First 100 chars of last message
            'assigned_to' => int|null,      // Primary assigned admin ID
            'assignment_status' => string|null, // 'active', 'resolved', or null
            'tags' => [                     // Array of tags
                ['id' => int, 'name' => string, 'color' => string],
                ...
            ],
            'assignees' => [int, ...]       // Array of all assigned admin IDs
        ],
        ...
    ],
    'next_cursor' => string|null,  // Cursor for next page (null if no more results)
    'has_more' => bool,            // Whether there are more results
    'count' => int                 // Number of conversations in this response
]
```

## Features

### 1. Delta Updates (Requirement 7.1, 11.4)

Only fetch conversations that have been updated since a specific timestamp:

```php
$since = time() - (24 * 60 * 60); // Last 24 hours
$result = $inboxService->getConversationsDelta($lineAccountId, $since);
```

This is crucial for:
- Efficient polling for new messages
- Syncing after coming back online
- Reducing server load and bandwidth

### 2. Cursor-Based Pagination (Requirement 7.2)

Uses `last_message_at` timestamp as cursor instead of OFFSET for better performance:

```php
// First page
$page1 = $inboxService->getConversationsDelta($lineAccountId, 0, null, 50);

// Next page
if ($page1['has_more']) {
    $page2 = $inboxService->getConversationsDelta(
        $lineAccountId, 
        0, 
        $page1['next_cursor'], 
        50
    );
}
```

**Benefits over OFFSET:**
- Consistent results even when data changes
- Better performance for large datasets
- No skipped or duplicate records

### 3. Minimal Field Selection (Requirement 7.1)

Only returns necessary fields to reduce payload size:
- No full message content (only 100-char preview)
- No unnecessary user metadata
- Optimized for list display

## Usage Examples

### Example 1: Initial Load

```php
$inboxService = new InboxService($db, $lineAccountId);
$result = $inboxService->getConversationsDelta($lineAccountId, 0, null, 50);

foreach ($result['conversations'] as $conv) {
    echo "{$conv['display_name']}: {$conv['last_message_preview']}\n";
}
```

### Example 2: Polling for Updates

```php
$lastCheck = $_SESSION['last_poll_time'] ?? 0;
$updates = $inboxService->getConversationsDelta($lineAccountId, $lastCheck);

if ($updates['count'] > 0) {
    // New conversations or updates found
    foreach ($updates['conversations'] as $conv) {
        // Bump conversation to top of list
        echo "Updated: {$conv['display_name']}\n";
    }
}

$_SESSION['last_poll_time'] = time();
```

### Example 3: Infinite Scroll

```php
$cursor = $_GET['cursor'] ?? null;
$result = $inboxService->getConversationsDelta($lineAccountId, 0, $cursor, 20);

echo json_encode([
    'success' => true,
    'data' => $result['conversations'],
    'next_cursor' => $result['next_cursor'],
    'has_more' => $result['has_more']
]);
```

## Performance Considerations

1. **Indexed Queries**: Uses indexed columns (`line_account_id`, `last_interaction`) for fast retrieval
2. **Subquery Optimization**: Unread count and message preview use efficient subqueries
3. **Limit Enforcement**: Caps at 100 items per request to prevent overload
4. **No OFFSET**: Cursor-based pagination avoids expensive OFFSET operations

## Database Requirements

Ensure the following indexes exist for optimal performance:

```sql
-- On users table
ALTER TABLE users ADD INDEX idx_account_interaction (line_account_id, last_interaction DESC);

-- On messages table  
ALTER TABLE messages ADD INDEX idx_user_read (user_id, direction, is_read);
ALTER TABLE messages ADD INDEX idx_user_created (user_id, created_at DESC);
```

## Testing

Run the test script to verify implementation:

```bash
php install/test_conversations_delta.php
```

The test validates:
- ✓ Required fields are present
- ✓ Message preview is truncated (max 100 chars)
- ✓ Cursor pagination works without overlap
- ✓ Delta updates filter correctly
- ✓ Limit enforcement
- ✓ Performance (< 500ms)

## API Integration

To use this in the API endpoint (`api/inbox-v2.php`):

```php
case 'getConversations':
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $cursor = $_GET['cursor'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    
    $result = $inboxService->getConversationsDelta(
        $lineAccountId,
        $since,
        $cursor,
        $limit
    );
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    break;
```

## Frontend Integration

JavaScript example for AJAX loading:

```javascript
async function loadConversations(since = 0, cursor = null) {
    const params = new URLSearchParams({
        action: 'getConversations',
        since: since,
        limit: 50
    });
    
    if (cursor) {
        params.append('cursor', cursor);
    }
    
    const response = await fetch(`/api/inbox-v2.php?${params}`);
    const result = await response.json();
    
    return result.data;
}

// Initial load
const conversations = await loadConversations();

// Load more (infinite scroll)
if (conversations.has_more) {
    const moreConversations = await loadConversations(0, conversations.next_cursor);
}

// Poll for updates
setInterval(async () => {
    const updates = await loadConversations(lastPollTime);
    if (updates.count > 0) {
        // Bump updated conversations to top
        updateConversationList(updates.conversations);
    }
    lastPollTime = Math.floor(Date.now() / 1000);
}, 3000);
```

## Related Requirements

- **Requirement 7.1**: Fetch only necessary fields (no full message content) ✓
- **Requirement 7.2**: Use cursor-based pagination instead of offset-based ✓
- **Requirement 11.4**: Support delta updates for syncing after offline ✓

## See Also

- [Inbox v2 Performance Upgrade Spec](.kiro/specs/inbox-v2-performance-upgrade/)
- [InboxService Class](../classes/InboxService.php)
- [Test Script](../install/test_conversations_delta.php)
