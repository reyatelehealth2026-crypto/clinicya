/**
 * WebSocket Server for Inbox v2 Real-time Updates
 * 
 * Provides real-time messaging using Socket.IO with Redis pub/sub integration.
 * Supports authentication, room management, typing indicators, and graceful shutdown.
 * 
 * Requirements: 4.1, 4.2, 4.4, 4.1.1, 4.1.2
 */

const express = require('express');
const http = require('http');
const socketIO = require('socket.io');
const mysql = require('mysql2/promise');
const redis = require('redis');
require('dotenv').config();

const app = express();
const server = http.createServer(app);

// Socket.IO server with CORS configuration
const io = socketIO(server, {
    cors: {
        origin: process.env.ALLOWED_ORIGINS?.split(',') || '*',
        credentials: true,
        methods: ['GET', 'POST']
    },
    path: '/socket.io/',
    transports: ['websocket', 'polling'],
    pingTimeout: 60000,
    pingInterval: 25000
});

// Redis clients for pub/sub
const redisClient = redis.createClient({
    host: process.env.REDIS_HOST || 'localhost',
    port: parseInt(process.env.REDIS_PORT || '6379'),
    password: process.env.REDIS_PASSWORD || undefined,
    retry_strategy: (options) => {
        if (options.error && options.error.code === 'ECONNREFUSED') {
            console.error('Redis connection refused');
            return new Error('Redis server connection refused');
        }
        if (options.total_retry_time > 1000 * 60 * 60) {
            return new Error('Redis retry time exhausted');
        }
        if (options.attempt > 10) {
            return undefined;
        }
        return Math.min(options.attempt * 100, 3000);
    }
});

const redisSubscriber = redisClient.duplicate();

// MySQL connection pool
const pool = mysql.createPool({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'telepharmacy',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0,
    enableKeepAlive: true,
    keepAliveInitialDelay: 0
});

// Store active connections by LINE account ID
// Structure: Map<lineAccountId, Set<socketId>>
const connections = new Map();

// Store typing indicators by conversation
// Structure: Map<conversationKey, Map<adminId, timestamp>>
const typingIndicators = new Map();

/**
 * Authenticate socket connection using token
 * @param {string} token - Authentication token
 * @returns {Promise<Object|null>} User object or null if invalid
 */
async function authenticateToken(token) {
    if (!token) {
        console.log('No token provided');
        return null;
    }

    try {
        // Verify token with database (session token or JWT)
        const [rows] = await pool.query(
            `SELECT id, username, line_account_id, role 
             FROM admin_users 
             WHERE session_token = ? 
             AND session_expires > NOW()`,
            [token]
        );

        if (rows.length === 0) {
            console.log('Invalid or expired token');
            return null;
        }

        return rows[0];
    } catch (error) {
        console.error('Authentication error:', error);
        return null;
    }
}

/**
 * Get updates since timestamp for sync
 * @param {number} lineAccountId - LINE account ID
 * @param {number} since - Unix timestamp in milliseconds
 * @returns {Promise<Object>} Updates object with new messages
 */
async function getUpdatesSince(lineAccountId, since) {
    try {
        const sinceSeconds = Math.floor(since / 1000);
        
        const [messages] = await pool.query(`
            SELECT 
                m.id, m.user_id, m.content, m.direction, m.type,
                m.created_at, m.is_read,
                u.display_name, u.picture_url
            FROM messages m
            JOIN users u ON u.id = m.user_id
            WHERE u.line_account_id = ?
            AND UNIX_TIMESTAMP(m.created_at) > ?
            AND m.direction = 'incoming'
            ORDER BY m.created_at ASC
        `, [lineAccountId, sinceSeconds]);

        return { 
            new_messages: messages,
            timestamp: Date.now()
        };
    } catch (error) {
        console.error('Get updates error:', error);
        throw error;
    }
}

/**
 * Get conversation key for typing indicators
 * @param {number} lineAccountId - LINE account ID
 * @param {string} userId - User ID
 * @returns {string} Conversation key
 */
function getConversationKey(lineAccountId, userId) {
    return `${lineAccountId}:${userId}`;
}

/**
 * Clean up expired typing indicators
 * Removes indicators older than 5 seconds
 */
function cleanupTypingIndicators() {
    const now = Date.now();
    const timeout = 5000; // 5 seconds

    for (const [conversationKey, admins] of typingIndicators.entries()) {
        for (const [adminId, timestamp] of admins.entries()) {
            if (now - timestamp > timeout) {
                admins.delete(adminId);
            }
        }
        
        // Remove conversation if no active typers
        if (admins.size === 0) {
            typingIndicators.delete(conversationKey);
        }
    }
}

// Clean up typing indicators every 2 seconds
setInterval(cleanupTypingIndicators, 2000);

// Socket.IO connection handler
io.on('connection', async (socket) => {
    console.log('Client attempting connection:', socket.id);

    // Authenticate socket
    const token = socket.handshake.auth.token;
    const user = await authenticateToken(token);

    if (!user) {
        console.log('Authentication failed for socket:', socket.id);
        socket.emit('error', { message: 'Authentication failed' });
        socket.disconnect();
        return;
    }

    // Store user info on socket
    socket.userId = user.id;
    socket.username = user.username;
    socket.lineAccountId = user.line_account_id;

    console.log(`User ${user.username} (${user.id}) connected from account ${user.line_account_id}`);

    // Join room for this LINE account
    const room = `account_${user.line_account_id}`;
    socket.join(room);

    // Track connection
    if (!connections.has(user.line_account_id)) {
        connections.set(user.line_account_id, new Set());
    }
    connections.get(user.line_account_id).add(socket.id);

    console.log(`Socket ${socket.id} joined room ${room}`);
    console.log(`Active connections for account ${user.line_account_id}: ${connections.get(user.line_account_id).size}`);

    // Send connection confirmation
    socket.emit('connected', {
        userId: user.id,
        username: user.username,
        lineAccountId: user.line_account_id,
        timestamp: Date.now()
    });

    /**
     * Handle typing indicator
     * Broadcasts typing status to other admins in the same conversation
     */
    socket.on('typing', (data) => {
        try {
            const { user_id, is_typing } = data;
            
            if (!user_id) {
                console.error('Typing event missing user_id');
                return;
            }

            const conversationKey = getConversationKey(socket.lineAccountId, user_id);
            
            if (is_typing) {
                // Add or update typing indicator
                if (!typingIndicators.has(conversationKey)) {
                    typingIndicators.set(conversationKey, new Map());
                }
                typingIndicators.get(conversationKey).set(socket.userId, Date.now());
            } else {
                // Remove typing indicator
                if (typingIndicators.has(conversationKey)) {
                    typingIndicators.get(conversationKey).delete(socket.userId);
                    if (typingIndicators.get(conversationKey).size === 0) {
                        typingIndicators.delete(conversationKey);
                    }
                }
            }

            // Broadcast to other admins in the same room (excluding sender)
            socket.to(room).emit('typing', {
                user_id: user_id,
                is_typing: is_typing,
                admin_id: socket.userId,
                admin_username: socket.username,
                timestamp: Date.now()
            });

            console.log(`Typing indicator: ${socket.username} ${is_typing ? 'started' : 'stopped'} typing in conversation ${user_id}`);
        } catch (error) {
            console.error('Typing event error:', error);
        }
    });

    /**
     * Handle sync request
     * Returns missed messages when tab becomes active
     */
    socket.on('sync', async (data) => {
        try {
            const { last_check } = data;
            
            if (!last_check) {
                socket.emit('error', { message: 'Missing last_check timestamp' });
                return;
            }

            console.log(`Sync request from ${socket.username} since ${new Date(last_check).toISOString()}`);

            const updates = await getUpdatesSince(socket.lineAccountId, last_check);
            
            socket.emit('sync_response', updates);
            
            console.log(`Sync response sent: ${updates.new_messages.length} new messages`);
        } catch (error) {
            console.error('Sync error:', error);
            socket.emit('error', { message: 'Sync failed', error: error.message });
        }
    });

    /**
     * Handle heartbeat/ping
     * Keeps connection alive
     */
    socket.on('ping', () => {
        socket.emit('pong', { timestamp: Date.now() });
    });

    /**
     * Handle disconnection
     * Cleans up connection tracking and typing indicators
     */
    socket.on('disconnect', (reason) => {
        console.log(`Client disconnected: ${socket.id}, reason: ${reason}`);

        // Remove from connections
        if (connections.has(socket.lineAccountId)) {
            connections.get(socket.lineAccountId).delete(socket.id);

            if (connections.get(socket.lineAccountId).size === 0) {
                connections.delete(socket.lineAccountId);
            }

            console.log(`Active connections for account ${socket.lineAccountId}: ${connections.get(socket.lineAccountId)?.size || 0}`);
        }

        // Clean up typing indicators for this user
        for (const [conversationKey, admins] of typingIndicators.entries()) {
            if (admins.has(socket.userId)) {
                admins.delete(socket.userId);
                
                // Notify others that this user stopped typing
                const [lineAccountId, userId] = conversationKey.split(':');
                if (lineAccountId == socket.lineAccountId) {
                    io.to(`account_${lineAccountId}`).emit('typing', {
                        user_id: userId,
                        is_typing: false,
                        admin_id: socket.userId,
                        admin_username: socket.username,
                        timestamp: Date.now()
                    });
                }
                
                if (admins.size === 0) {
                    typingIndicators.delete(conversationKey);
                }
            }
        }
    });

    /**
     * Handle errors
     */
    socket.on('error', (error) => {
        console.error('Socket error:', error);
    });
});

// Subscribe to Redis channel for new messages from PHP
redisSubscriber.subscribe('inbox_updates', (err) => {
    if (err) {
        console.error('Failed to subscribe to inbox_updates:', err);
    } else {
        console.log('Subscribed to inbox_updates channel');
    }
});

redisSubscriber.on('message', (channel, message) => {
    if (channel === 'inbox_updates') {
        try {
            const data = JSON.parse(message);
            const { line_account_id, message: messageData, unread_count } = data;

            // Broadcast to all admins in this LINE account
            const room = `account_${line_account_id}`;
            
            io.to(room).emit('new_message', messageData);

            // Also emit conversation update
            io.to(room).emit('conversation_update', {
                user_id: messageData.user_id,
                last_message_at: messageData.created_at,
                last_message_preview: messageData.content?.substring(0, 100),
                unread_count: unread_count,
                timestamp: Date.now()
            });

            console.log(`Broadcasted new message to room ${room}: ${messageData.id}`);
        } catch (error) {
            console.error('Error processing Redis message:', error);
        }
    }
});

redisSubscriber.on('error', (err) => {
    console.error('Redis subscriber error:', err);
});

// Health check endpoint
app.get('/health', (req, res) => {
    const health = {
        status: 'ok',
        timestamp: Date.now(),
        uptime: process.uptime(),
        connections: {
            total: io.engine.clientsCount,
            byAccount: Array.from(connections.entries()).map(([accountId, sockets]) => ({
                accountId,
                count: sockets.size
            }))
        },
        redis: redisClient.connected ? 'connected' : 'disconnected',
        database: pool ? 'connected' : 'disconnected'
    };

    res.json(health);
});

// Status endpoint
app.get('/status', (req, res) => {
    res.json({
        status: 'running',
        version: '1.0.0',
        timestamp: Date.now(),
        clients: io.engine.clientsCount,
        rooms: io.sockets.adapter.rooms.size,
        typingIndicators: typingIndicators.size
    });
});

// Start server
const PORT = process.env.WEBSOCKET_PORT || 3000;
const HOST = process.env.WEBSOCKET_HOST || '0.0.0.0';

server.listen(PORT, HOST, () => {
    console.log(`WebSocket server running on ${HOST}:${PORT}`);
    console.log(`Environment: ${process.env.NODE_ENV || 'development'}`);
    console.log(`Allowed origins: ${process.env.ALLOWED_ORIGINS || '*'}`);
});

// Graceful shutdown handling
let isShuttingDown = false;

async function gracefulShutdown(signal) {
    if (isShuttingDown) {
        console.log('Shutdown already in progress...');
        return;
    }

    isShuttingDown = true;
    console.log(`\n${signal} received, starting graceful shutdown...`);

    // Stop accepting new connections
    server.close(() => {
        console.log('HTTP server closed');
    });

    // Notify all connected clients
    io.emit('server_shutdown', {
        message: 'Server is shutting down',
        timestamp: Date.now()
    });

    // Give clients time to receive the message
    await new Promise(resolve => setTimeout(resolve, 1000));

    // Close all socket connections
    const sockets = await io.fetchSockets();
    console.log(`Closing ${sockets.length} socket connections...`);
    
    for (const socket of sockets) {
        socket.disconnect(true);
    }

    // Close Socket.IO
    io.close(() => {
        console.log('Socket.IO server closed');
    });

    // Close database pool
    try {
        await pool.end();
        console.log('Database pool closed');
    } catch (error) {
        console.error('Error closing database pool:', error);
    }

    // Close Redis connections
    try {
        await redisClient.quit();
        await redisSubscriber.quit();
        console.log('Redis connections closed');
    } catch (error) {
        console.error('Error closing Redis connections:', error);
    }

    console.log('Graceful shutdown complete');
    process.exit(0);
}

// Handle shutdown signals
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));

// Handle uncaught errors
process.on('uncaughtException', (error) => {
    console.error('Uncaught exception:', error);
    gracefulShutdown('UNCAUGHT_EXCEPTION');
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('Unhandled rejection at:', promise, 'reason:', reason);
});

// Log startup info
console.log('='.repeat(60));
console.log('WebSocket Server for Inbox v2');
console.log('='.repeat(60));
console.log(`Node version: ${process.version}`);
console.log(`Platform: ${process.platform}`);
console.log(`Memory: ${Math.round(process.memoryUsage().heapUsed / 1024 / 1024)}MB`);
console.log('='.repeat(60));
