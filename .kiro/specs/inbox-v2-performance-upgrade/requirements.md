# Requirements Document

## Introduction

ปรับปรุง Inbox v2 ให้มี performance สูงสุด โดยเน้นการใช้ AJAX สำหรับการเปลี่ยนกล่องข้อความโดยไม่ต้องโหลดหน้าใหม่ และให้แชทใหม่เด้งขึ้นไปอยู่บนสุดอัตโนมัติ เพื่อประสบการณ์การใช้งานที่ลื่นไหลและรวดเร็ว

## Glossary

- **Inbox v2**: หน้า inbox-v2.php ที่ใช้สำหรับ Vibe Selling OS v2
- **Conversation Switching**: การเปลี่ยนจากการสนทนาหนึ่งไปยังอีกการสนทนาหนึ่ง
- **AJAX Loading**: การโหลดข้อมูลแบบ asynchronous โดยไม่ reload หน้า
- **Auto-Bump**: การเลื่อนการสนทนาที่มีข้อความใหม่ขึ้นไปอยู่บนสุดของรายการ
- **Optimistic UI**: การแสดงผลทันทีก่อนที่ server จะตอบกลับ
- **Message Cache**: การเก็บข้อความไว้ใน memory เพื่อเข้าถึงได้เร็ว
- **Virtual Scrolling**: เทคนิคการแสดงผลเฉพาะรายการที่มองเห็นเพื่อประหยัด memory
- **Debouncing**: การหน่วงเวลาก่อนทำงานเพื่อลด API calls

## Requirements

### Requirement 1: AJAX-Based Conversation Switching

**User Story:** As an admin, I want to switch between conversations without page reload, so that I can work faster and maintain my workflow state.

#### Acceptance Criteria

1. WHEN an admin clicks on a conversation THEN the system SHALL load messages via AJAX without page reload
2. WHEN switching conversations THEN the system SHALL preserve scroll position in the conversation list
3. WHEN loading a conversation THEN the system SHALL show a loading indicator in the chat panel
4. WHEN a conversation is already cached THEN the system SHALL display it instantly without API call
5. WHEN switching conversations THEN the system SHALL update the browser URL using History API without reload

### Requirement 2: Automatic Conversation Bumping

**User Story:** As an admin, I want new messages to automatically move conversations to the top, so that I can see the most recent activity first.

#### Acceptance Criteria

1. WHEN a new message arrives THEN the system SHALL move that conversation to the top of the list
2. WHEN multiple messages arrive simultaneously THEN the system SHALL sort conversations by most recent message timestamp
3. WHEN a conversation is bumped THEN the system SHALL animate the movement smoothly
4. WHEN the admin is viewing a conversation THEN incoming messages SHALL not cause it to jump away
5. WHEN a conversation is manually pinned THEN the system SHALL keep it at the top regardless of new messages

### Requirement 3: Optimized Message Loading

**User Story:** As an admin with many conversations, I want messages to load quickly, so that I don't waste time waiting.

#### Acceptance Criteria

1. WHEN loading a conversation THEN the system SHALL fetch only the most recent 50 messages initially
2. WHEN scrolling up THEN the system SHALL lazy-load older messages in batches of 50
3. WHEN messages are cached THEN the system SHALL serve them from cache within 50ms
4. WHEN switching back to a recent conversation THEN the system SHALL use cached data if less than 30 seconds old
5. WHEN the cache exceeds 10 conversations THEN the system SHALL evict the least recently used conversation

### Requirement 4: Real-time Updates with WebSocket + Polling Fallback

**User Story:** As an admin, I want to receive new messages in real-time, so that I can respond promptly to customers.

#### Acceptance Criteria

1. WHEN WebSocket is available THEN the system SHALL use WebSocket for real-time updates
2. WHEN WebSocket connection fails THEN the system SHALL automatically fallback to polling
3. WHEN using WebSocket THEN new messages SHALL appear instantly (< 500ms latency)
4. WHEN WebSocket disconnects THEN the system SHALL attempt to reconnect with exponential backoff
5. WHEN reconnecting after disconnection THEN the system SHALL sync missed messages
6. WHEN using polling fallback THEN the system SHALL poll every 3 seconds when active
7. WHEN using polling fallback and tab is inactive THEN the system SHALL reduce polling to every 10 seconds

### Requirement 4.1: Typing Indicators (WebSocket Only)

**User Story:** As an admin, I want to see when other admins are typing in the same conversation, so that I don't send duplicate responses.

#### Acceptance Criteria

1. WHEN an admin types in a conversation THEN the system SHALL broadcast typing indicator to other admins viewing the same conversation
2. WHEN an admin stops typing for 3 seconds THEN the system SHALL clear the typing indicator
3. WHEN multiple admins are typing THEN the system SHALL show all their names
4. WHEN typing indicators are shown THEN they SHALL not interfere with message display
5. WHEN WebSocket is not available THEN typing indicators SHALL be disabled

### Requirement 5: Conversation List Performance

**User Story:** As an admin with hundreds of conversations, I want the conversation list to scroll smoothly, so that I can navigate efficiently.

#### Acceptance Criteria

1. WHEN the conversation list has more than 100 items THEN the system SHALL use virtual scrolling
2. WHEN scrolling the conversation list THEN the system SHALL maintain 60fps performance
3. WHEN rendering conversation items THEN the system SHALL render only visible items plus 10 buffer items
4. WHEN searching or filtering THEN the system SHALL debounce input by 300ms to reduce API calls
5. WHEN the conversation list updates THEN the system SHALL use incremental DOM updates instead of full re-render

### Requirement 6: Message Rendering Optimization

**User Story:** As an admin viewing long conversations, I want messages to render quickly, so that I can read and respond without lag.

#### Acceptance Criteria

1. WHEN displaying messages THEN the system SHALL use virtual scrolling for conversations with more than 100 messages
2. WHEN rendering images THEN the system SHALL lazy-load images outside the viewport
3. WHEN rendering Flex Messages THEN the system SHALL cache rendered HTML for reuse
4. WHEN scrolling through messages THEN the system SHALL maintain smooth 60fps scrolling
5. WHEN new messages arrive THEN the system SHALL append them without re-rendering existing messages

### Requirement 7: Network Request Optimization

**User Story:** As a system administrator, I want to minimize server load, so that the system scales efficiently.

#### Acceptance Criteria

1. WHEN fetching conversations THEN the system SHALL request only necessary fields (no full message content)
2. WHEN fetching messages THEN the system SHALL use cursor-based pagination instead of offset-based
3. WHEN multiple API calls are needed THEN the system SHALL batch them into a single request when possible
4. WHEN the same data is requested multiple times THEN the system SHALL use HTTP caching headers (ETag, Last-Modified)
5. WHEN sending messages THEN the system SHALL use optimistic UI updates and queue for retry on failure

### Requirement 8: Memory Management

**User Story:** As an admin who keeps the inbox open all day, I want it to not slow down over time, so that I can work efficiently.

#### Acceptance Criteria

1. WHEN the message cache exceeds 500 messages THEN the system SHALL evict oldest messages
2. WHEN the conversation cache exceeds 10 conversations THEN the system SHALL evict least recently used
3. WHEN DOM nodes exceed 1000 THEN the system SHALL remove off-screen nodes from DOM
4. WHEN event listeners are no longer needed THEN the system SHALL remove them to prevent memory leaks
5. WHEN the page has been open for more than 4 hours THEN the system SHALL suggest a refresh to clear memory

### Requirement 9: Loading States and Feedback

**User Story:** As an admin, I want clear feedback when data is loading, so that I know the system is working.

#### Acceptance Criteria

1. WHEN switching conversations THEN the system SHALL show a skeleton loader in the chat panel
2. WHEN loading more messages THEN the system SHALL show a loading spinner at the top of the message list
3. WHEN sending a message THEN the system SHALL show a sending indicator on the message
4. WHEN a message fails to send THEN the system SHALL show a retry button
5. WHEN the network is slow THEN the system SHALL show a "slow connection" warning

### Requirement 10: Keyboard Navigation Enhancement

**User Story:** As a power user, I want to navigate conversations with keyboard, so that I can work faster without using the mouse.

#### Acceptance Criteria

1. WHEN pressing Up/Down arrows THEN the system SHALL navigate to previous/next conversation
2. WHEN pressing Enter on a conversation THEN the system SHALL open it in the chat panel
3. WHEN pressing Ctrl+K THEN the system SHALL open quick search for conversations
4. WHEN pressing Escape THEN the system SHALL close any open modal or return focus to conversation list
5. WHEN pressing Ctrl+F THEN the system SHALL focus the search input

### Requirement 11: Offline Support

**User Story:** As an admin with unstable internet, I want to continue working when offline, so that I don't lose productivity.

#### Acceptance Criteria

1. WHEN the network is offline THEN the system SHALL show an offline indicator
2. WHEN offline THEN the system SHALL allow viewing cached conversations and messages
3. WHEN offline THEN the system SHALL queue outgoing messages for sending when back online
4. WHEN coming back online THEN the system SHALL automatically send queued messages
5. WHEN offline for more than 5 minutes THEN the system SHALL show a reconnect button

### Requirement 12: Performance Monitoring

**User Story:** As a developer, I want to monitor performance metrics, so that I can identify and fix bottlenecks.

#### Acceptance Criteria

1. WHEN the page loads THEN the system SHALL measure and log Time to Interactive (TTI)
2. WHEN switching conversations THEN the system SHALL measure and log conversation load time
3. WHEN rendering messages THEN the system SHALL measure and log render time
4. WHEN API calls are made THEN the system SHALL measure and log response times
5. WHEN performance degrades THEN the system SHALL log warnings to help debugging

