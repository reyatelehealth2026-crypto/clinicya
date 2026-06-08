"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.CacheInvalidationService = void 0;
const logger_1 = require("@/utils/logger");
class CacheInvalidationService {
    cacheService;
    eventHandlers = new Map();
    constructor(cacheService) {
        this.cacheService = cacheService;
        this.setupDefaultHandlers();
    }
    async invalidate(event) {
        logger_1.logger.info('Processing cache invalidation event', {
            type: event.type,
            resourceId: event.resourceId,
            resourceType: event.resourceType,
            affectedKeys: event.affectedCacheKeys.length,
        });
        const invalidationPromises = event.affectedCacheKeys.map(key => this.cacheService.delete(key));
        await Promise.allSettled(invalidationPromises);
        const handlers = this.eventHandlers.get(event.type) || [];
        const handlerPromises = handlers.map(handler => handler(event));
        await Promise.allSettled(handlerPromises);
        logger_1.logger.info('Cache invalidation completed', {
            type: event.type,
            resourceId: event.resourceId,
            invalidatedKeys: event.affectedCacheKeys.length,
        });
    }
    onEvent(eventType, handler) {
        if (!this.eventHandlers.has(eventType)) {
            this.eventHandlers.set(eventType, []);
        }
        this.eventHandlers.get(eventType).push(handler);
    }
    setupDefaultHandlers() {
        this.onEvent('order_updated', async (event) => {
            const patterns = [
                `dashboard:metrics:*`,
                `orders:*`,
                `orders:${event.resourceId}:*`,
                `customer:${event.metadata?.['customerId']}:orders:*`,
            ];
            for (const pattern of patterns) {
                await this.cacheService.invalidatePattern(pattern);
            }
        });
        this.onEvent('payment_processed', async (event) => {
            const patterns = [
                `dashboard:metrics:*`,
                `payments:*`,
                `orders:${event.metadata?.['orderId']}:*`,
            ];
            for (const pattern of patterns) {
                await this.cacheService.invalidatePattern(pattern);
            }
        });
        this.onEvent('webhook_received', async () => {
            const patterns = [
                `dashboard:metrics:*`,
                `webhooks:stats:*`,
            ];
            for (const pattern of patterns) {
                await this.cacheService.invalidatePattern(pattern);
            }
        });
        this.onEvent('customer_updated', async (event) => {
            const patterns = [
                `customers:*`,
                `customer:${event.resourceId}:*`,
                `dashboard:metrics:customers:*`,
            ];
            for (const pattern of patterns) {
                await this.cacheService.invalidatePattern(pattern);
            }
        });
    }
    static createOrderUpdateEvent(orderId, customerId, metadata) {
        return {
            type: 'order_updated',
            resourceId: orderId,
            resourceType: 'order',
            affectedCacheKeys: [
                `orders:${orderId}`,
                `orders:${orderId}:details`,
                `orders:${orderId}:timeline`,
            ],
            timestamp: new Date(),
            metadata: { customerId, ...metadata },
        };
    }
    static createPaymentProcessedEvent(paymentId, orderId, metadata) {
        return {
            type: 'payment_processed',
            resourceId: paymentId,
            resourceType: 'payment',
            affectedCacheKeys: [
                `payments:${paymentId}`,
                `payments:pending`,
                `payments:processed`,
            ],
            timestamp: new Date(),
            metadata: { orderId, ...metadata },
        };
    }
    static createWebhookReceivedEvent(webhookId, metadata) {
        return {
            type: 'webhook_received',
            resourceId: webhookId,
            resourceType: 'webhook',
            affectedCacheKeys: [
                `webhooks:${webhookId}`,
                `webhooks:recent`,
                `webhooks:stats`,
            ],
            timestamp: new Date(),
            ...(metadata && { metadata }),
        };
    }
    static createCustomerUpdatedEvent(customerId, metadata) {
        return {
            type: 'customer_updated',
            resourceId: customerId,
            resourceType: 'customer',
            affectedCacheKeys: [
                `customer:${customerId}`,
                `customer:${customerId}:profile`,
                `customer:${customerId}:orders`,
            ],
            timestamp: new Date(),
            ...(metadata && { metadata }),
        };
    }
}
exports.CacheInvalidationService = CacheInvalidationService;
//# sourceMappingURL=CacheInvalidationService.js.map