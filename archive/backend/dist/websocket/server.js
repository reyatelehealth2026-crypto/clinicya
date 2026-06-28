"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.DashboardWebSocketServer = void 0;
const socket_io_1 = require("socket.io");
const redis_adapter_1 = require("@socket.io/redis-adapter");
const redis_1 = require("redis");
const jsonwebtoken_1 = __importDefault(require("jsonwebtoken"));
const config_1 = require("../config/config");
const logger_1 = require("../utils/logger");
class DashboardWebSocketServer {
    io;
    prisma;
    redisClient;
    redisSubscriber;
    connections = new Map();
    heartbeatInterval = null;
    constructor(httpServer, prisma) {
        this.prisma = prisma;
        this.io = new socket_io_1.Server(httpServer, {
            cors: {
                origin: process.env.ALLOWED_ORIGINS?.split(',') || ['http://localhost:3000'],
                credentials: true,
                methods: ['GET', 'POST']
            },
            path: '/socket.io/',
            transports: ['websocket', 'polling'],
            pingTimeout: 60000,
            pingInterval: 25000,
            allowEIO3: true
        });
        this.setupRedisAdapter();
        this.setupEventHandlers();
        this.startHeartbeat();
        logger_1.logger.info('Dashboard WebSocket server initialized');
    }
    async setupRedisAdapter() {
        try {
            this.redisClient = (0, redis_1.createClient)({
                url: config_1.config.REDIS_URL,
                retry_strategy: (options) => {
                    if (options.error && options.error.code === 'ECONNREFUSED') {
                        logger_1.logger.error('Redis connection refused');
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
            this.redisSubscriber = this.redisClient.duplicate();
            await this.redisClient.connect();
            await this.redisSubscriber.connect();
            this.io.adapter((0, redis_adapter_1.createAdapter)(this.redisClient, this.redisSubscriber));
            await this.redisSubscriber.subscribe('dashboard_updates', (message) => {
                this.handleDashboardUpdate(message);
            });
            logger_1.logger.info('Redis adapter configured for WebSocket scaling');
        }
        catch (error) {
            logger_1.logger.error('Failed to setup Redis adapter', { error: String(error) });
            throw error;
        }
    }
    setupEventHandlers() {
        this.io.use(this.authenticationMiddleware.bind(this));
        this.io.on('connection', this.handleConnection.bind(this));
    }
    async authenticationMiddleware(socket, next) {
        try {
            const token = socket.handshake.auth.token || socket.handshake.headers.authorization?.replace('Bearer ', '');
            if (!token) {
                return next(new Error('Authentication token required'));
            }
            const payload = jsonwebtoken_1.default.verify(token, config_1.config.JWT_SECRET);
            const user = await this.prisma.user.findFirst({
                where: {
                    id: payload.userId,
                    isActive: true,
                },
            });
            if (!user) {
                return next(new Error('User not found or inactive'));
            }
            const authSocket = socket;
            authSocket.userId = user.id;
            authSocket.username = user.username;
            authSocket.lineAccountId = user.lineAccountId;
            authSocket.role = user.role;
            authSocket.permissions = this.getUserPermissions(user.role);
            logger_1.logger.info('WebSocket authentication successful', {
                userId: user.id,
                username: user.username,
                socketId: socket.id,
            });
            next();
        }
        catch (error) {
            logger_1.logger.error('WebSocket authentication failed', {
                error: String(error),
                socketId: socket.id,
            });
            next(new Error('Authentication failed'));
        }
    }
    handleConnection(socket) {
        logger_1.logger.info('Dashboard WebSocket client connected', {
            userId: socket.userId,
            username: socket.username,
            lineAccountId: socket.lineAccountId,
            socketId: socket.id,
        });
        const room = `dashboard_${socket.lineAccountId}`;
        socket.join(room);
        if (!this.connections.has(socket.lineAccountId)) {
            this.connections.set(socket.lineAccountId, new Set());
        }
        this.connections.get(socket.lineAccountId).add(socket.id);
        socket.emit('connected', {
            userId: socket.userId,
            username: socket.username,
            lineAccountId: socket.lineAccountId,
            permissions: socket.permissions,
            timestamp: Date.now(),
        });
        this.setupSocketEventHandlers(socket);
        socket.on('disconnect', (reason) => {
            this.handleDisconnection(socket, reason);
        });
    }
    setupSocketEventHandlers(socket) {
        socket.on('subscribe_dashboard', (data) => {
            const { metrics = ['all'] } = data;
            metrics.forEach(metric => {
                if (metric === 'all' || ['orders', 'payments', 'webhooks', 'customers'].includes(metric)) {
                    socket.join(`${socket.lineAccountId}_${metric}`);
                }
            });
            socket.emit('subscription_confirmed', {
                metrics,
                timestamp: Date.now(),
            });
            logger_1.logger.info('Dashboard subscription confirmed', {
                userId: socket.userId,
                metrics,
            });
        });
        socket.on('request_dashboard_data', async (data) => {
            try {
                socket.emit('dashboard_data', {
                    metrics: {
                        orders: { todayCount: 0, todayTotal: 0 },
                        payments: { pendingSlips: 0, processedToday: 0 },
                        webhooks: { todayCount: 0, successRate: 100 },
                        customers: { totalActive: 0, newToday: 0 },
                    },
                    timestamp: Date.now(),
                });
            }
            catch (error) {
                logger_1.logger.error('Failed to fetch dashboard data', {
                    userId: socket.userId,
                    error: String(error),
                });
                socket.emit('error', {
                    message: 'Failed to fetch dashboard data',
                    code: 'DASHBOARD_DATA_ERROR',
                });
            }
        });
        socket.on('ping', () => {
            socket.emit('pong', { timestamp: Date.now() });
        });
        socket.on('error', (error) => {
            logger_1.logger.error('Socket error', {
                userId: socket.userId,
                socketId: socket.id,
                error: String(error),
            });
        });
    }
    handleDisconnection(socket, reason) {
        logger_1.logger.info('Dashboard WebSocket client disconnected', {
            userId: socket.userId,
            socketId: socket.id,
            reason,
        });
        if (this.connections.has(socket.lineAccountId)) {
            this.connections.get(socket.lineAccountId).delete(socket.id);
            if (this.connections.get(socket.lineAccountId).size === 0) {
                this.connections.delete(socket.lineAccountId);
            }
        }
    }
    handleDashboardUpdate(message) {
        try {
            const event = JSON.parse(message);
            const room = `dashboard_${event.lineAccountId}`;
            this.io.to(room).emit(event.type, {
                ...event.data,
                timestamp: event.timestamp,
            });
            logger_1.logger.info('Dashboard update broadcasted', {
                type: event.type,
                lineAccountId: event.lineAccountId,
                room,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to handle dashboard update', {
                error: String(error),
                message,
            });
        }
    }
    async broadcastDashboardUpdate(event) {
        try {
            await this.redisClient.publish('dashboard_updates', JSON.stringify(event));
            logger_1.logger.info('Dashboard update published to Redis', {
                type: event.type,
                lineAccountId: event.lineAccountId,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast dashboard update', {
                error: String(error),
                event,
            });
        }
    }
    startHeartbeat() {
        this.heartbeatInterval = setInterval(() => {
            this.io.emit('heartbeat', { timestamp: Date.now() });
            const totalConnections = Array.from(this.connections.values())
                .reduce((sum, sockets) => sum + sockets.size, 0);
            logger_1.logger.debug('WebSocket heartbeat', {
                totalConnections,
                accountsConnected: this.connections.size,
            });
        }, 30000);
    }
    getUserPermissions(role) {
        const rolePermissions = {
            SUPER_ADMIN: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'manage_webhooks',
                'admin_access',
                'manage_users',
                'system_settings',
            ],
            ADMIN: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'manage_webhooks',
                'admin_access',
            ],
            PHARMACIST: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
                'pharmacist_access',
            ],
            STAFF: [
                'view_dashboard',
                'manage_orders',
                'process_payments',
            ],
        };
        return rolePermissions[role] || [];
    }
    getConnectionStats() {
        const totalConnections = Array.from(this.connections.values())
            .reduce((sum, sockets) => sum + sockets.size, 0);
        const connectionsByAccount = Array.from(this.connections.entries())
            .map(([accountId, sockets]) => ({
            accountId,
            connections: sockets.size,
        }));
        return {
            totalConnections,
            accountsConnected: this.connections.size,
            connectionsByAccount,
        };
    }
    async shutdown() {
        logger_1.logger.info('Shutting down Dashboard WebSocket server...');
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }
        this.io.emit('server_shutdown', {
            message: 'Server is shutting down for maintenance',
            timestamp: Date.now(),
        });
        await new Promise(resolve => setTimeout(resolve, 1000));
        const sockets = await this.io.fetchSockets();
        for (const socket of sockets) {
            socket.disconnect(true);
        }
        this.io.close();
        if (this.redisClient) {
            await this.redisClient.quit();
        }
        if (this.redisSubscriber) {
            await this.redisSubscriber.quit();
        }
        logger_1.logger.info('Dashboard WebSocket server shutdown complete');
    }
}
exports.DashboardWebSocketServer = DashboardWebSocketServer;
//# sourceMappingURL=server.js.map