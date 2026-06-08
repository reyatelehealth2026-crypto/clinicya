"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.WebSocketService = void 0;
const BaseService_1 = require("./BaseService");
const logger_1 = require("../utils/logger");
class WebSocketService extends BaseService_1.BaseService {
    webSocketServer = null;
    constructor(prisma) {
        super(prisma);
    }
    setWebSocketServer(server) {
        this.webSocketServer = server;
        logger_1.logger.info('WebSocket server instance set in WebSocketService');
    }
    async broadcastMetricsUpdate(lineAccountId, metrics) {
        if (!this.webSocketServer) {
            logger_1.logger.warn('WebSocket server not available for metrics update broadcast');
            return;
        }
        try {
            const event = {
                type: 'metrics_updated',
                data: metrics,
                lineAccountId,
                timestamp: Date.now(),
            };
            await this.webSocketServer.broadcastDashboardUpdate(event);
            logger_1.logger.info('Dashboard metrics update broadcasted', {
                lineAccountId,
                updatedAt: metrics.updatedAt,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast metrics update', {
                lineAccountId,
                error: String(error),
            });
        }
    }
    async broadcastOrderStatusChange(lineAccountId, orderUpdate) {
        if (!this.webSocketServer) {
            logger_1.logger.warn('WebSocket server not available for order status broadcast');
            return;
        }
        try {
            const event = {
                type: 'order_status_changed',
                data: orderUpdate,
                lineAccountId,
                timestamp: Date.now(),
            };
            await this.webSocketServer.broadcastDashboardUpdate(event);
            logger_1.logger.info('Order status change broadcasted', {
                lineAccountId,
                orderId: orderUpdate.orderId,
                oldStatus: orderUpdate.oldStatus,
                newStatus: orderUpdate.newStatus,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast order status change', {
                lineAccountId,
                orderId: orderUpdate.orderId,
                error: String(error),
            });
        }
    }
    async broadcastPaymentProcessed(lineAccountId, paymentUpdate) {
        if (!this.webSocketServer) {
            logger_1.logger.warn('WebSocket server not available for payment processed broadcast');
            return;
        }
        try {
            const event = {
                type: 'payment_processed',
                data: paymentUpdate,
                lineAccountId,
                timestamp: Date.now(),
            };
            await this.webSocketServer.broadcastDashboardUpdate(event);
            logger_1.logger.info('Payment processed update broadcasted', {
                lineAccountId,
                paymentId: paymentUpdate.paymentId,
                status: paymentUpdate.status,
                amount: paymentUpdate.amount,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast payment processed update', {
                lineAccountId,
                paymentId: paymentUpdate.paymentId,
                error: String(error),
            });
        }
    }
    async broadcastWebhookReceived(lineAccountId, webhookUpdate) {
        if (!this.webSocketServer) {
            logger_1.logger.warn('WebSocket server not available for webhook received broadcast');
            return;
        }
        try {
            const event = {
                type: 'webhook_received',
                data: webhookUpdate,
                lineAccountId,
                timestamp: Date.now(),
            };
            await this.webSocketServer.broadcastDashboardUpdate(event);
            logger_1.logger.info('Webhook received update broadcasted', {
                lineAccountId,
                webhookId: webhookUpdate.webhookId,
                type: webhookUpdate.type,
                status: webhookUpdate.status,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast webhook received update', {
                lineAccountId,
                webhookId: webhookUpdate.webhookId,
                error: String(error),
            });
        }
    }
    getConnectionStats() {
        if (!this.webSocketServer) {
            return null;
        }
        return this.webSocketServer.getConnectionStats();
    }
    isWebSocketAvailable() {
        return this.webSocketServer !== null;
    }
    async broadcastCustomEvent(lineAccountId, eventType, data) {
        if (!this.webSocketServer) {
            logger_1.logger.warn('WebSocket server not available for custom event broadcast');
            return;
        }
        try {
            const event = {
                type: eventType,
                data,
                lineAccountId,
                timestamp: Date.now(),
            };
            await this.webSocketServer.broadcastDashboardUpdate(event);
            logger_1.logger.info('Custom event broadcasted', {
                lineAccountId,
                eventType,
            });
        }
        catch (error) {
            logger_1.logger.error('Failed to broadcast custom event', {
                lineAccountId,
                eventType,
                error: String(error),
            });
        }
    }
    startPeriodicUpdates(intervalMs = 30000) {
        logger_1.logger.info('Starting periodic dashboard updates', { intervalMs });
        return setInterval(async () => {
            try {
                const activeAccounts = await this.prisma.user.findMany({
                    where: { isActive: true },
                    select: { lineAccountId: true },
                    distinct: ['lineAccountId'],
                });
                for (const account of activeAccounts) {
                    const mockMetrics = {
                        orders: {
                            todayCount: Math.floor(Math.random() * 100),
                            todayTotal: Math.floor(Math.random() * 50000),
                            pendingCount: Math.floor(Math.random() * 20),
                            completedCount: Math.floor(Math.random() * 80),
                            averageOrderValue: Math.floor(Math.random() * 1000) + 500,
                        },
                        payments: {
                            pendingSlips: Math.floor(Math.random() * 10),
                            processedToday: Math.floor(Math.random() * 50),
                            matchingRate: Math.floor(Math.random() * 20) + 80,
                            totalAmount: Math.floor(Math.random() * 100000),
                            averageProcessingTime: Math.floor(Math.random() * 30) + 5,
                        },
                        webhooks: {
                            todayCount: Math.floor(Math.random() * 200),
                            successRate: Math.floor(Math.random() * 10) + 90,
                            failedCount: Math.floor(Math.random() * 10),
                            averageResponseTime: Math.floor(Math.random() * 500) + 100,
                        },
                        customers: {
                            totalActive: Math.floor(Math.random() * 1000) + 500,
                            newToday: Math.floor(Math.random() * 20),
                            lineConnected: Math.floor(Math.random() * 800) + 400,
                            averageOrdersPerCustomer: Math.floor(Math.random() * 5) + 2,
                        },
                        updatedAt: new Date().toISOString(),
                    };
                    await this.broadcastMetricsUpdate(account.lineAccountId, mockMetrics);
                }
                logger_1.logger.debug('Periodic dashboard updates completed', {
                    accountsUpdated: activeAccounts.length,
                });
            }
            catch (error) {
                logger_1.logger.error('Failed to send periodic dashboard updates', {
                    error: String(error),
                });
            }
        }, intervalMs);
    }
}
exports.WebSocketService = WebSocketService;
//# sourceMappingURL=WebSocketService.js.map