# Inbox V2 Autocomplete Search Feature

## Overview
Implemented real-time autocomplete search functionality for the Inbox V2 interface that searches conversations on the server immediately as the user types.

## Implementation Date
January 19, 2026

## Changes Made

### 1. Frontend (inbox-v2.php)

#### Autocomplete Dropdown HTML
- Added autocomplete dropdown container after search input (line ~1588-1596)
- Dropdown shows search results with user avatar, name, last message preview, and unread count
- Hidden by default, shows when user types

#### JavaScript Functions

**`performAutocompleteSearch(query)`**
- Triggers immediately when user types (minimum 1 character)
- Cancels previous pending requests to avoid race conditions
- Calls server API: `api/inbox-v2.php?action=search_conversations`
- Shows loading spinner while searching
- Displays results in dropdown

**`displayAutocompleteResults(conversations)`**
- Renders search results in dropdown
- Shows user avatar, display name, last message preview
- Displays unread count badge if > 0
- Shows "ไม่พบผลลัพธ์" (No results) if empty

**`selectAutocompleteResult(userId)`**
- Handles click on autocomplete result
- Loads the selected conversation
- Clears search input and hides dropdown

**Click-outside handler**
- Closes autocomplete dropdown when clicking outside search area

#### Search Behavior
- **Debounce delay**: 150ms (faster than previous 300ms for better UX)
- **Minimum characters**: 1 (searches immediately, no waiting for 2+ chars)
- **Results limit**: 10 conversations per search
- **Search scope**: Name, phone, message content, tags

### 2. Backend (api/inbox-v2.php)

#### New API Endpoint: `search_conversations`

**Method**: GET

**Parameters**:
- `query` (required): Search term
- `limit` (optional): Max results (1-50, default 10)
- `line_account_id`: Current bot/account ID

**Search Fields**:
- User display name
- User phone number
- Message content (searches all messages)
- Tag names

**Response Format**:
```json
{
  "success": true,
  "data": {
    "conversations": [
      {
        "id": 123,
        "user_id": "U1234567890abcdef",
        "display_name": "ชื่อลูกค้า",
        "picture_url": "https://...",
        "phone": "0812345678",
        "last_message_preview": "ข้อความล่าสุด...",
        "last_message_time": "2026-01-19 12:34:56",
        "unread_count": 5
      }
    ],
    "count": 1,
    "query": "search term"
  }
}
```

**SQL Query Features**:
- Uses DISTINCT to avoid duplicate results
- Searches across multiple tables (users, messages, user_tags)
- Orders by last message time (most recent first)
- Includes unread count for each conversation
- Efficient with proper indexes

## User Experience

### Before
- Search only worked on locally loaded conversations (first 50)
- No autocomplete dropdown
- Had to type 2+ characters before server search
- Console showed: `[Search] Found 0 local matches for "j"`

### After
- Searches entire database immediately as user types
- Shows autocomplete dropdown with results
- Works with just 1 character
- Fast response (150ms debounce)
- Visual feedback with avatars and unread counts
- Click to load conversation directly

## Testing

### Manual Testing Steps
1. Open Inbox V2: `inbox-v2.php`
2. Click on search input
3. Type any character (e.g., "j")
4. Autocomplete dropdown should appear with results
5. Type more characters to refine search
6. Click on a result to load that conversation
7. Click outside to close dropdown

### Test Cases
- ✅ Search by customer name (Thai and English)
- ✅ Search by phone number
- ✅ Search by message content
- ✅ Search by tag name
- ✅ Empty query hides dropdown
- ✅ No results shows "ไม่พบผลลัพธ์"
- ✅ Click result loads conversation
- ✅ Click outside closes dropdown
- ✅ Typing cancels previous requests

## Performance Considerations

### Optimizations
- Debounced input (150ms) reduces API calls
- Request cancellation prevents race conditions
- Limited results (10) for fast response
- Efficient SQL with proper indexes
- Reuses existing database connections

### Database Indexes Needed
Ensure these indexes exist for optimal performance:
```sql
-- Users table
CREATE INDEX idx_users_search ON users(line_account_id, display_name, phone);

-- Messages table
CREATE INDEX idx_messages_search ON messages(line_account_id, user_id, content, timestamp);
CREATE INDEX idx_messages_unread ON messages(line_account_id, user_id, is_read, direction);

-- User tags
CREATE INDEX idx_user_tags_search ON user_tags(line_account_id, tag_name);
CREATE INDEX idx_user_tag_assignments ON user_tag_assignments(user_id, tag_id);
```

## Browser Compatibility
- Modern browsers (Chrome, Firefox, Safari, Edge)
- Requires JavaScript enabled
- Uses Fetch API (ES6+)
- Async/await syntax

## Security
- SQL injection protected (prepared statements)
- XSS protected (proper escaping in HTML)
- CSRF not needed (GET request, read-only)
- Line account ID filtering prevents cross-account access

## Future Enhancements
- [ ] Highlight matching text in results
- [ ] Show search history
- [ ] Add keyboard navigation (arrow keys)
- [ ] Cache recent searches
- [ ] Add search filters (date range, status)
- [ ] Full-text search with relevance scoring
- [ ] Search suggestions/autocorrect

## Related Files
- `inbox-v2.php` - Frontend UI and JavaScript
- `api/inbox-v2.php` - Backend API endpoint
- `docs/INBOX_V2_VIDEO_LOCATION_SUPPORT.md` - Previous feature
- `docs/INBOX_V2_LAYOUT_FIX.md` - UI improvements

## Deployment
- **Committed**: January 19, 2026
- **Pushed to**: cny.re-ya.com (origin remote)
- **Status**: ✅ Live in production
