"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
require("dotenv/config");
const fastify_1 = __importDefault(require("fastify"));
const http_1 = require("http");
const config_1 = require("@/config/config");
const plugins_1 = require("@/plugins");
const routes_1 = require("@/routes");
const logger_1 = require("@/utils/logger");
const prisma_1 = require("@/utils/prisma");
const server_1 = require("@/websocket/server");
const WebSocketService_1 = require("@/services/WebSocketService");
const fastify = (0, fastify_1.default)({
    logger: {
        level: config_1.config.LOG_LEVEL,
    },
    requestIdHeader: 'x-request-id',
    requestIdLogLabel: 'requestId',
    genReqId: () => {
        return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    },
    serverFactory: (handler) => {
        return (0, http_1.createServer)(handler);
    },
});
let webSocketServer;
let webSocketService;
const start = async () => {
    try {
        await (0, plugins_1.registerPlugins)(fastify);
        await (0, routes_1.registerRoutes)(fastify);
        webSocketServer = new server_1.DashboardWebSocketServer(fastify.server, prisma_1.prisma);
        webSocketService = new WebSocketService_1.WebSocketService(prisma_1.prisma);
        webSocketService.setWebSocketServer(webSocketServer);
        fastify.decorate('webSocketService', webSocketService);
        const updateInterval = webSocketService.startPeriodicUpdates(30000);
        const gracefulShutdown = async (signal) => {
            logger_1.logger.info(`Received ${signal}, shutting down gracefully`);
            try {
                clearInterval(updateInterval);
                if (webSocketServer) {
                    await webSocketServer.shutdown();
                }
                await fastify.close();
                await prisma_1.prisma.$disconnect();
                logger_1.logger.info('Server closed successfully');
                process.exit(0);
            }
            catch (error) {
                logger_1.logger.error('Error during shutdown:', { error: String(error) });
                process.exit(1);
            }
        };
        process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
        process.on('SIGINT', () => gracefulShutdown('SIGINT'));
        process.on('uncaughtException', (error) => {
            logger_1.logger.fatal('Uncaught exception', { error: String(error), stack: error.stack });
            process.exit(1);
        });
        process.on('unhandledRejection', (reason, promise) => {
            logger_1.logger.fatal('Unhandled rejection', { reason: String(reason), promise });
            process.exit(1);
        });
        await fastify.listen({
            port: config_1.config.PORT,
            host: '0.0.0.0'
        });
        logger_1.logger.info(`🚀 Server listening on port ${config_1.config.PORT}`);
        logger_1.logger.info(`📊 Environment: ${config_1.config.NODE_ENV}`);
        logger_1.logger.info(`🔗 API Prefix: ${config_1.config.API_PREFIX}`);
        logger_1.logger.info(`📚 Documentation: http://localhost:${config_1.config.PORT}/docs`);
        logger_1.logger.info(`❤️  Health Check: http://localhost:${config_1.config.PORT}/health`);
        logger_1.logger.info(`🔌 WebSocket Server: Enabled with Redis scaling`);
        logger_1.logger.info(`⚡ Real-time Updates: Every 30 seconds`);
    }
    catch (error) {
        logger_1.logger.error('Error starting server:', { error: String(error) });
        process.exit(1);
    }
};
start();
//# sourceMappingURL=server.js.map