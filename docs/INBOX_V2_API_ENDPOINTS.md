# Inbox V2 Performance API Endpoints

## Overview

New API endpoints added to `api/inbox-v2.php` for the Inbox V2 Performance Upgrade feature. These endpoints provide efficient cursor-based pagination, delta updates, and HTTP caching for optimal performance.

## Endpoints

### 1. GET /getConversations

Get conversations with cursor-based pagination and delta updates.

**URL:** `/api/inbox-v2.php?action=getConversations`

**Method:** `GET`

**Parameters:**
- `since` (optional, int): Unix timestamp for delta updates. Only returns conversations updated after this time. Default: 0
- `cursor` (optional, string): Pagination cursor from previous response. Use for loading next page.
- `limit` (optional, int): Number of conversations to return. Min: 1, Max: 100, Default: 50

**Response:**
```json
{
  "success": true,
  "data": {
    "conversations": [
      {
        "id": 123,
        "display_name": "John Doe",
        "picture_url": "https://...",
        "last_message_at": "2024-01-15 10:30:00",
        "unread_count": 3,
        "last_message_preview": "สวัสดีครับ...",
        "assigned_to": [1, 2]
      }
    ],
    "next_cursor": "2024-01-15T10:30:00_123",
    "has_more": true,
    "count": 50
  }
}
```

**HTTP Caching:**
- `ETag`: MD5 hash of response data
- `Cache-Control: private, max-age=30`
- Supports `If-None-Match` header for 304 Not Modified responses

**Use Cases:**
- Initial conversation list load
- Pagination (load more conversations)
- Delta updates (only get conversations updated since last check)

**Example:**
```javascript
// Initial load
fetch('/api/inbox-v2.php?action=getConversations&limit=50')

// Load next page
fetch('/api/inbox-v2.php?action=getConversations&cursor=2024-01-15T10:30:00_123&limit=50')

// Delta updates (last 5 minutes)
const fiveMinutesAgo = Math.floor(Date.now() / 1000) - 300;
fetch(`/api/inbox-v2.php?action=getConversations&since=${fiveMinutesAgo}`)
```

---

### 2. GET /getMessages

Get messages for a conversation with cursor-based pagination.

**URL:** `/api/inbox-v2.php?action=getMessages`

**Method:** `GET`

**Parameters:**
- `user_id` (required, int): User ID of the conversation
- `cursor` (optional, string): Pagination cursor from previous response. Use for loading older messages.
- `limit` (optional, int): Number of messages to return. Min: 1, Max: 100, Default: 50

**Response:**
```json
{
  "success": true,
  "data": {
    "messages": [
      {
        "id": 456,
        "user_id": 123,
        "content": "สวัสดีครับ",
        "direction": "incoming",
        "type": "text",
        "created_at": "2024-01-15 10:30:00",
        "is_read": 0
      }
    ],
    "next_cursor": "456",
    "has_more": true,
    "count": 50
  }
}
```

**HTTP Caching:**
- `ETag`: MD5 hash of response data
- `Cache-Control: private, max-age=30`
- Supports `If-None-Match` header for 304 Not Modified responses

**Use Cases:**
- Load initial messages when opening a conversation
- Load older messages when scrolling up (lazy loading)
- Refresh messages after reconnection

**Example:**
```javascript
// Initial load (most recent 50 messages)
fetch('/api/inbox-v2.php?action=getMessages&user_id=123&limit=50')

// Load older messages
fetch('/api/inbox-v2.php?action=getMessages&user_id=123&cursor=456&limit=50')
```

---

### 3. GET /poll

Poll for delta updates (new messages since last check).

**URL:** `/api/inbox-v2.php?action=poll`

**Method:** `GET`

**Parameters:**
- `since` (required, int): Unix timestamp of last check. Only returns messages after this time.

**Response:**
```json
{
  "success": true,
  "data": {
    "new_messages": [
      {
        "id": 789,
        "user_id": 123,
        "content": "ขอบคุณครับ",
        "direction": "incoming",
        "type": "text",
        "created_at": "2024-01-15 10:35:00",
        "display_name": "John Doe",
        "picture_url": "https://..."
      }
    ],
    "updated_conversations": []
  },
  "timestamp": 1705298100
}
```

**HTTP Caching:**
- `Last-Modified`: Current GMT timestamp
- `Cache-Control: no-cache, must-revalidate`
- Supports `If-Modified-Since` header for 304 Not Modified responses

**Use Cases:**
- Real-time polling fallback when WebSocket is unavailable
- Sync missed messages when tab becomes active
- Background polling for new messages

**Example:**
```javascript
// Poll for new messages (last 3 seconds)
const lastCheck = Math.floor(Date.now() / 1000) - 3;
fetch(`/api/inbox-v2.php?action=poll&since=${lastCheck}`)
  .then(res => res.json())
  .then(data => {
    if (data.success && data.data.new_messages.length > 0) {
      // Handle new messages
      data.data.new_messages.forEach(msg => {
        // Bump conversation to top
        // Append message if viewing this conversation
      });
    }
  });
```

---

## Performance Features

### 1. Cursor-Based Pagination

Instead of using `OFFSET` which becomes slow with large datasets, these endpoints use cursor-based pagination:

- **getConversations**: Uses `last_message_at` timestamp + `id` as cursor
- **getMessages**: Uses message `id` as cursor

**Benefits:**
- Consistent performance regardless of page number
- No duplicate or missing items when data changes
- Efficient database queries with indexed columns

### 2. Delta Updates

The `since` parameter allows fetching only data that changed after a specific timestamp:

- Reduces data transfer
- Minimizes server processing
- Enables efficient polling

### 3. HTTP Caching

All endpoints implement HTTP caching headers:

- **ETag**: Content-based cache validation
- **Last-Modified**: Time-based cache validation
- **Cache-Control**: Cache behavior directives

**Benefits:**
- Reduces bandwidth usage
- Faster response times for unchanged data
- 304 Not Modified responses when data hasn't changed

### 4. Limit Validation

All endpoints enforce reasonable limits:

- Minimum: 1 item
- Maximum: 100 items
- Default: 50 items

This prevents excessive data transfer and server load.

---

## Error Handling

All endpoints return consistent error responses:

```json
{
  "success": false,
  "error": "Error message here"
}
```

**Common HTTP Status Codes:**
- `200 OK`: Success
- `304 Not Modified`: Cached data is still valid
- `400 Bad Request`: Missing or invalid parameters
- `405 Method Not Allowed`: Wrong HTTP method
- `500 Internal Server Error`: Server error

---

## Integration with Frontend

These endpoints are designed to work with the frontend performance upgrade components:

1. **ConversationListManager** uses `getConversations` for:
   - Initial conversation list load
   - Infinite scroll pagination
   - Delta updates for conversation bumping

2. **ChatPanelManager** uses `getMessages` for:
   - Loading conversation messages
   - Lazy loading older messages
   - Message caching

3. **RealtimeManager** uses `poll` for:
   - Polling fallback when WebSocket unavailable
   - Syncing missed messages
   - Background updates

---

## Testing

Test script available at: `install/test_inbox_v2_api_endpoints.php`

**Tests:**
1. GET /getConversations - Initial load
2. GET /getConversations - Cursor pagination
3. GET /getConversations - Delta updates
4. GET /getMessages - Initial load
5. GET /getMessages - Cursor pagination
6. GET /poll - Delta updates
7. HTTP Caching - ETag validation
8. Error handling - Missing parameters
9. Limit validation - Max enforcement

---

## Requirements Validated

These endpoints satisfy the following requirements from the Inbox V2 Performance Upgrade spec:

- **Requirement 7.1**: Minimal field selection in API responses
- **Requirement 7.2**: Cursor-based pagination instead of offset-based
- **Requirement 7.4**: HTTP caching headers (ETag, Last-Modified)
- **Requirement 4.3**: Efficient polling for real-time updates
- **Requirement 11.4**: Delta updates for syncing missed messages

---

## Migration Required

Before using these endpoints, run the performance migration:

```bash
php install/run_inbox_v2_performance_migration.php
```

This creates the necessary database indexes for optimal query performance.

---

## Related Files

- **API Implementation**: `api/inbox-v2.php`
- **Service Layer**: `classes/InboxService.php`
- **Database Migration**: `database/migration_inbox_v2_performance.sql`
- **Migration Runner**: `install/run_inbox_v2_performance_migration.php`
- **Test Script**: `install/test_inbox_v2_api_endpoints.php`
- **Frontend Components**: 
  - `assets/js/conversation-list-manager.js`
  - `assets/js/chat-panel-manager.js`
  - `assets/js/lru-cache.js`

---

## Next Steps

1. ✅ Backend API endpoints implemented
2. ⏭️ Frontend integration (Phase 3-5 of tasks)
3. ⏭️ WebSocket server setup (Phase 3)
4. ⏭️ Virtual scrolling implementation (Phase 4)
5. ⏭️ Performance monitoring (Phase 6)

---

## Notes

- All endpoints require `line_account_id` parameter (from session or request)
- Endpoints use the existing `InboxService` methods implemented in task 5
- HTTP caching is implemented but requires client support for full benefit
- Cursor values are opaque strings - clients should not parse or modify them
