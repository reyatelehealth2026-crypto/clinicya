# RealtimeManager Usage Guide

## Overview

The `RealtimeManager` class provides real-time communication for Inbox v2 using WebSocket (Socket.IO) with automatic fallback to polling. It handles connection management, typing indicators, tab visibility changes, and exponential backoff on failures.

## Features

- ✅ WebSocket connection using Socket.IO client
- ✅ Automatic fallback to polling when WebSocket fails
- ✅ Exponential backoff for reconnection (min(3 * 2^N, 30) seconds)
- ✅ Typing indicators (WebSocket only)
- ✅ Tab visibility handling (sync on active, slow polling on inactive)
- ✅ Network status detection (online/offline)
- ✅ Performance tracking (message latency)
- ✅ Graceful cleanup and resource management

## Requirements

### Socket.IO Client Library

Add the Socket.IO client library to your HTML page:

```html
<!-- Socket.IO Client (CDN) -->
<script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>

<!-- Or use local copy -->
<script src="/assets/js/socket.io.min.js"></script>
```

### RealtimeManager Script

```html
<script src="/assets/js/realtime-manager.js"></script>
```

## Basic Usage

### 1. Initialize RealtimeManager

```javascript
// Get auth token from session or cookie
const authToken = document.querySelector('meta[name="auth-token"]')?.content;
const lineAccountId = document.querySelector('meta[name="line-account-id"]')?.content;

// Create RealtimeManager instance
const realtimeManager = new RealtimeManager({
    websocketUrl: 'http://localhost:3000', // WebSocket server URL
    authToken: authToken,
    lineAccountId: lineAccountId,
    
    // Callback for new messages
    onNewMessage: (message) => {
        console.log('New message:', message);
        
        // Update UI - bump conversation to top
        conversationListManager.bumpConversation(message.user_id, message);
        
        // If viewing this conversation, append message
        if (chatPanelManager.currentUserId === message.user_id) {
            chatPanelManager.appendMessage(message);
        }
        
        // Show notification
        showNotification(message);
    },
    
    // Callback for conversation updates
    onConversationUpdate: (data) => {
        console.log('Conversation update:', data);
        
        // Update conversation in list
        conversationListManager.updateConversation(data);
    },
    
    // Callback for typing indicators
    onTyping: (data) => {
        console.log('Typing indicator:', data);
        
        // Show/hide typing indicator
        if (data.is_typing) {
            showTypingIndicator(data.user_id, data.admin_username);
        } else {
            hideTypingIndicator(data.user_id, data.admin_id);
        }
    },
    
    // Callback for connection status changes
    onConnectionChange: (status, type) => {
        console.log(`Connection ${status} via ${type}`);
        
        // Update UI indicator
        updateConnectionIndicator(status, type);
    }
});

// Start real-time updates
realtimeManager.start();
```

### 2. Send Typing Indicators

```javascript
// When user types in message input
messageInput.addEventListener('input', () => {
    const userId = getCurrentConversationUserId();
    realtimeManager.handleTyping(userId);
});

// When user stops typing or sends message
messageInput.addEventListener('blur', () => {
    realtimeManager.stopTyping();
});

sendButton.addEventListener('click', () => {
    realtimeManager.stopTyping();
    // ... send message
});
```

### 3. Get Connection Status

```javascript
const status = realtimeManager.getStatus();
console.log('Connection status:', status);
// {
//   isConnected: true,
//   connectionType: 'websocket',
//   reconnectAttempts: 0,
//   isTabActive: true,
//   averageLatency: 245
// }
```

### 4. Cleanup on Page Unload

```javascript
window.addEventListener('beforeunload', () => {
    realtimeManager.destroy();
});
```

## Integration with inbox-v2.php

### HTML Setup

Add meta tags for configuration:

```html
<head>
    <!-- ... other meta tags ... -->
    <meta name="auth-token" content="<?php echo $_SESSION['auth_token'] ?? ''; ?>">
    <meta name="line-account-id" content="<?php echo $_SESSION['line_account_id'] ?? ''; ?>">
</head>
```

Add Socket.IO client before RealtimeManager:

```html
<!-- Before closing </body> tag -->
<script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
<script src="/assets/js/lru-cache.js"></script>
<script src="/assets/js/conversation-list-manager.js"></script>
<script src="/assets/js/chat-panel-manager.js"></script>
<script src="/assets/js/realtime-manager.js"></script>
```

### JavaScript Integration

```javascript
// Initialize managers
let conversationListManager;
let chatPanelManager;
let realtimeManager;

document.addEventListener('DOMContentLoaded', () => {
    // Initialize conversation list manager
    conversationListManager = new ConversationListManager();
    
    // Initialize chat panel manager
    chatPanelManager = new ChatPanelManager();
    
    // Initialize realtime manager
    const authToken = document.querySelector('meta[name="auth-token"]')?.content;
    const lineAccountId = document.querySelector('meta[name="line-account-id"]')?.content;
    
    if (authToken && lineAccountId) {
        realtimeManager = new RealtimeManager({
            websocketUrl: '<?php echo WEBSOCKET_URL; ?>',
            authToken: authToken,
            lineAccountId: lineAccountId,
            onNewMessage: handleNewMessage,
            onConversationUpdate: handleConversationUpdate,
            onTyping: handleTypingIndicator,
            onConnectionChange: handleConnectionChange
        });
        
        realtimeManager.start();
    }
    
    // Setup typing indicator on message input
    const messageInput = document.getElementById('message-input');
    if (messageInput) {
        messageInput.addEventListener('input', () => {
            const userId = chatPanelManager.currentUserId;
            if (userId) {
                realtimeManager.handleTyping(userId);
            }
        });
        
        messageInput.addEventListener('blur', () => {
            realtimeManager.stopTyping();
        });
    }
    
    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        if (realtimeManager) {
            realtimeManager.destroy();
        }
    });
});

// Handler functions
function handleNewMessage(message) {
    // Bump conversation to top
    conversationListManager.bumpConversation(message.user_id, message);
    
    // If viewing this conversation, append message
    if (chatPanelManager.currentUserId === message.user_id) {
        chatPanelManager.appendMessage(message);
    }
    
    // Show desktop notification
    if (Notification.permission === 'granted') {
        new Notification('New message', {
            body: message.content,
            icon: message.user_picture_url
        });
    }
    
    // Play sound
    playNotificationSound();
}

function handleConversationUpdate(data) {
    conversationListManager.updateConversation(data);
}

function handleTypingIndicator(data) {
    const typingContainer = document.getElementById(`typing-${data.user_id}`);
    
    if (data.is_typing) {
        if (typingContainer) {
            typingContainer.textContent = `${data.admin_username} is typing...`;
            typingContainer.style.display = 'block';
        }
    } else {
        if (typingContainer) {
            typingContainer.style.display = 'none';
        }
    }
}

function handleConnectionChange(status, type) {
    const indicator = document.getElementById('connection-indicator');
    
    if (indicator) {
        indicator.className = `connection-${status}`;
        indicator.textContent = status === 'connected' 
            ? `Connected (${type})` 
            : status === 'offline'
            ? 'Offline'
            : 'Connecting...';
    }
}

function playNotificationSound() {
    const audio = new Audio('/assets/sounds/notification.mp3');
    audio.play().catch(e => console.log('Could not play sound:', e));
}
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `websocketUrl` | string | `'http://localhost:3000'` | WebSocket server URL |
| `authToken` | string | `null` | Authentication token |
| `lineAccountId` | number | `null` | LINE account ID |
| `onNewMessage` | function | `() => {}` | Callback for new messages |
| `onConversationUpdate` | function | `() => {}` | Callback for conversation updates |
| `onTyping` | function | `() => {}` | Callback for typing indicators |
| `onConnectionChange` | function | `() => {}` | Callback for connection status changes |

## Connection Behavior

### WebSocket (Primary)

1. Attempts to connect using Socket.IO
2. On success: Uses WebSocket for real-time updates
3. On failure: Retries with exponential backoff (up to 5 attempts)
4. After max attempts: Falls back to polling

### Polling (Fallback)

1. Polls `/api/inbox-v2.php?action=poll&since={timestamp}` for updates
2. **Active tab**: Polls every 3 seconds
3. **Inactive tab**: Polls every 10 seconds
4. **On tab active**: Syncs immediately

### Exponential Backoff

Reconnection delay formula: `min(3 * 2^N, 30)` seconds

- Attempt 1: 3 seconds
- Attempt 2: 6 seconds
- Attempt 3: 12 seconds
- Attempt 4: 24 seconds
- Attempt 5+: 30 seconds (max)

## Typing Indicators

### Sending

```javascript
// Start typing
realtimeManager.handleTyping(userId);

// Stop typing
realtimeManager.stopTyping();
```

### Receiving

```javascript
onTyping: (data) => {
    // data.user_id - Conversation user ID
    // data.admin_id - Admin who is typing
    // data.admin_username - Admin username
    // data.is_typing - true/false
}
```

### Auto-clear

Typing indicators automatically clear after 3 seconds of inactivity.

## Tab Visibility

### Active Tab
- WebSocket: Sends sync request to get missed messages
- Polling: Polls immediately, then every 3 seconds

### Inactive Tab
- WebSocket: Stays connected (server handles it)
- Polling: Slows down to every 10 seconds

## Network Status

### Online
- Attempts to reconnect WebSocket (if using WebSocket)
- Resumes polling immediately (if using polling)

### Offline
- Triggers `onConnectionChange('offline', type)` callback
- Stops attempting connections
- Resumes when back online

## Performance Tracking

The manager tracks message latency:

```javascript
const status = realtimeManager.getStatus();
console.log('Average latency:', status.averageLatency, 'ms');
```

Latency is calculated as: `receiveTime - messageCreatedTime`

## Error Handling

### Connection Errors
- Automatically retries with exponential backoff
- Falls back to polling after max attempts
- Notifies via `onConnectionChange` callback

### Authentication Errors
- WebSocket disconnects immediately
- Falls back to polling (which uses session auth)

### Network Errors
- Detects online/offline events
- Pauses connections when offline
- Resumes when back online

## Best Practices

1. **Always provide callbacks**: Handle new messages, updates, and typing indicators
2. **Clean up on unload**: Call `destroy()` before page unload
3. **Check connection status**: Use `getStatus()` to monitor health
4. **Handle offline gracefully**: Show offline indicator, queue messages
5. **Test both modes**: Test with WebSocket and polling fallback
6. **Monitor latency**: Track `averageLatency` for performance issues

## Troubleshooting

### WebSocket not connecting

1. Check if Socket.IO client is loaded: `typeof io !== 'undefined'`
2. Verify WebSocket server is running: `curl http://localhost:3000/health`
3. Check auth token is valid
4. Check browser console for errors

### Polling not working

1. Verify API endpoint exists: `/api/inbox-v2.php?action=poll`
2. Check server logs for errors
3. Verify session authentication is working

### Typing indicators not showing

1. Typing indicators only work with WebSocket (not polling)
2. Check if WebSocket is connected: `realtimeManager.getStatus().connectionType === 'websocket'`
3. Verify other admin is in the same LINE account

### High latency

1. Check `averageLatency` in status
2. Verify WebSocket server performance
3. Check network conditions
4. Consider using Redis for pub/sub (if not already)

## Example: Complete Integration

See `inbox-v2.php` for a complete working example of RealtimeManager integration with ConversationListManager and ChatPanelManager.

## API Requirements

The RealtimeManager expects the following API endpoint:

### GET /api/inbox-v2.php?action=poll&since={timestamp}

**Response:**
```json
{
    "success": true,
    "data": {
        "new_messages": [
            {
                "id": 123,
                "user_id": 456,
                "content": "Hello",
                "direction": "incoming",
                "type": "text",
                "created_at": "2024-01-15 10:30:00",
                "is_read": 0,
                "display_name": "John Doe",
                "picture_url": "https://..."
            }
        ],
        "conversation_updates": [
            {
                "user_id": 456,
                "last_message_at": "2024-01-15 10:30:00",
                "unread_count": 5
            }
        ]
    }
}
```

## WebSocket Server Requirements

The RealtimeManager expects a Socket.IO server with the following events:

### Client → Server
- `join` - Join room for LINE account
- `typing` - Send typing indicator
- `sync` - Request missed messages
- `ping` - Heartbeat

### Server → Client
- `connected` - Connection confirmation
- `new_message` - New message received
- `conversation_update` - Conversation updated
- `typing` - Typing indicator from other admin
- `sync_response` - Missed messages response
- `pong` - Heartbeat response
- `server_shutdown` - Server shutting down
- `error` - Error occurred

See `websocket-server.js` for the complete server implementation.
