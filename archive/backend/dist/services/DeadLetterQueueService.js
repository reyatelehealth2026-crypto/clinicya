"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DeadLetterQueueService = void 0;
const errors_js_1 = require("../types/errors.js");
class DeadLetterQueueService {
    prisma;
    loggingService;
    notificationService;
    config;
    processingInterval;
    isProcessing = false;
    constructor(prisma, loggingService, notificationService, config) {
        this.prisma = prisma;
        this.loggingService = loggingService;
        this.notificationService = notificationService;
        this.config = {
            maxRetries: 5,
            retryDelayMs: 300000,
            batchSize: 10,
            processingIntervalMs: 60000,
            alertThreshold: 50,
            ...config
        };
    }
    start() {
        if (this.processingInterval) {
            return;
        }
        this.processingInterval = setInterval(() => this.processQueue(), this.config.processingIntervalMs);
        this.loggingService.logEvent('info', 'Dead Letter Queue processor started', {
            config: this.config
        });
    }
    stop() {
        if (this.processingInterval) {
            clearInterval(this.processingInterval);
            this.processingInterval = undefined;
        }
        this.loggingService.logEvent('info', 'Dead Letter Queue processor stopped');
    }
    async addToQueue(operationType, payload, error, attempts, maxAttempts, priority = 'medium', metadata) {
        const messageId = `dlq-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        const now = new Date();
        const message = {
            operationType,
            payload,
            originalError: error.message,
            attempts,
            maxAttempts,
            firstFailedAt: now,
            lastAttemptAt: now,
            nextRetryAt: new Date(now.getTime() + this.config.retryDelayMs),
            status: 'pending',
            priority,
            metadata
        };
        try {
            await this.prisma.deadLetterQueue.create({
                data: {
                    id: messageId,
                    operationType: message.operationType,
                    payload: JSON.stringify(message.payload),
                    originalError: message.originalError,
                    attempts: message.attempts,
                    maxAttempts: message.maxAttempts,
                    firstFailedAt: message.firstFailedAt,
                    lastAttemptAt: message.lastAttemptAt,
                    nextRetryAt: message.nextRetryAt,
                    status: message.status,
                    priority: message.priority,
                    metadata: message.metadata ? JSON.stringify(message.metadata) : null
                }
            });
            await this.loggingService.logEvent('warn', `Operation added to dead letter queue: ${operationType}`, {
                messageId,
                operationType,
                attempts,
                maxAttempts,
                error: error.message,
                priority
            });
            await this.checkAlertThreshold();
            return messageId;
        }
        catch (dbError) {
            await this.loggingService.logEvent('error', 'Failed to add message to dead letter queue', {
                operationType,
                error: error.message,
                dbError: dbError.message
            });
            throw dbError;
        }
    }
    async processQueue() {
        if (this.isProcessing) {
            return;
        }
        this.isProcessing = true;
        try {
            const pendingMessages = await this.getPendingMessages();
            if (pendingMessages.length === 0) {
                return;
            }
            await this.loggingService.logEvent('info', `Processing ${pendingMessages.length} dead letter queue messages`);
            for (const message of pendingMessages) {
                await this.processMessage(message);
            }
        }
        catch (error) {
            await this.loggingService.logEvent('error', 'Error processing dead letter queue', {
                error: error.message
            });
        }
        finally {
            this.isProcessing = false;
        }
    }
    async getPendingMessages() {
        const now = new Date();
        const messages = await this.prisma.deadLetterQueue.findMany({
            where: {
                status: 'pending',
                nextRetryAt: {
                    lte: now
                }
            },
            orderBy: [
                { priority: 'desc' },
                { firstFailedAt: 'asc' }
            ],
            take: this.config.batchSize
        });
        return messages.map(msg => ({
            id: msg.id,
            operationType: msg.operationType,
            payload: JSON.parse(msg.payload),
            originalError: msg.originalError,
            attempts: msg.attempts,
            maxAttempts: msg.maxAttempts,
            firstFailedAt: msg.firstFailedAt,
            lastAttemptAt: msg.lastAttemptAt,
            nextRetryAt: msg.nextRetryAt || undefined,
            status: msg.status,
            priority: msg.priority,
            metadata: msg.metadata ? JSON.parse(msg.metadata) : undefined
        }));
    }
    async processMessage(message) {
        try {
            await this.updateMessageStatus(message.id, 'processing');
            const success = await this.retryOperation(message);
            if (success) {
                await this.updateMessageStatus(message.id, 'resolved');
                await this.loggingService.logEvent('info', `Dead letter queue message resolved: ${message.operationType}`, {
                    messageId: message.id,
                    attempts: message.attempts + 1
                });
            }
            else {
                const newAttempts = message.attempts + 1;
                if (newAttempts >= message.maxAttempts) {
                    await this.updateMessageStatus(message.id, 'failed');
                    await this.loggingService.logEvent('error', `Dead letter queue message permanently failed: ${message.operationType}`, {
                        messageId: message.id,
                        totalAttempts: newAttempts,
                        maxAttempts: message.maxAttempts
                    });
                    await this.notificationService.sendAlert({
                        type: 'system_health',
                        severity: errors_js_1.ErrorSeverity.HIGH,
                        message: `Dead letter queue message permanently failed: ${message.operationType}`,
                        details: {
                            messageId: message.id,
                            operationType: message.operationType,
                            totalAttempts: newAttempts,
                            originalError: message.originalError
                        }
                    });
                }
                else {
                    const nextRetryAt = new Date(Date.now() + this.config.retryDelayMs * Math.pow(2, newAttempts - 1));
                    await this.prisma.deadLetterQueue.update({
                        where: { id: message.id },
                        data: {
                            attempts: newAttempts,
                            lastAttemptAt: new Date(),
                            nextRetryAt,
                            status: 'pending'
                        }
                    });
                }
            }
        }
        catch (error) {
            await this.loggingService.logEvent('error', `Error processing dead letter queue message: ${message.id}`, {
                messageId: message.id,
                error: error.message
            });
            await this.updateMessageStatus(message.id, 'pending');
        }
    }
    async retryOperation(message) {
        try {
            switch (message.operationType) {
                case 'webhook_delivery':
                    return await this.retryWebhookDelivery(message.payload);
                case 'payment_processing':
                    return await this.retryPaymentProcessing(message.payload);
                case 'notification_send':
                    return await this.retryNotificationSend(message.payload);
                case 'data_sync':
                    return await this.retryDataSync(message.payload);
                default:
                    await this.loggingService.logEvent('warn', `Unknown operation type in dead letter queue: ${message.operationType}`);
                    return false;
            }
        }
        catch (error) {
            await this.loggingService.logEvent('error', `Retry operation failed: ${message.operationType}`, {
                messageId: message.id,
                error: error.message
            });
            return false;
        }
    }
    async retryWebhookDelivery(payload) {
        return Math.random() > 0.3;
    }
    async retryPaymentProcessing(payload) {
        return Math.random() > 0.2;
    }
    async retryNotificationSend(payload) {
        return Math.random() > 0.1;
    }
    async retryDataSync(payload) {
        return Math.random() > 0.4;
    }
    async updateMessageStatus(messageId, status) {
        await this.prisma.deadLetterQueue.update({
            where: { id: messageId },
            data: {
                status,
                lastAttemptAt: new Date()
            }
        });
    }
    async checkAlertThreshold() {
        const pendingCount = await this.prisma.deadLetterQueue.count({
            where: { status: 'pending' }
        });
        if (pendingCount >= this.config.alertThreshold) {
            await this.notificationService.sendAlert({
                type: 'system_health',
                severity: errors_js_1.ErrorSeverity.HIGH,
                message: `Dead letter queue threshold exceeded: ${pendingCount} pending messages`,
                details: {
                    pendingCount,
                    threshold: this.config.alertThreshold
                }
            });
        }
    }
    async getQueueStatistics() {
        const [statusCounts, oldestPending, avgRetryTime] = await Promise.all([
            this.prisma.deadLetterQueue.groupBy({
                by: ['status'],
                _count: { status: true }
            }),
            this.prisma.deadLetterQueue.findFirst({
                where: { status: 'pending' },
                orderBy: { firstFailedAt: 'asc' },
                select: { firstFailedAt: true }
            }),
            this.prisma.deadLetterQueue.aggregate({
                _avg: {
                    attempts: true
                },
                where: {
                    status: 'resolved'
                }
            })
        ]);
        const stats = {
            pending: 0,
            processing: 0,
            failed: 0,
            resolved: 0,
            total: 0,
            oldestPending: oldestPending?.firstFailedAt,
            averageRetryTime: avgRetryTime._avg.attempts || 0
        };
        for (const count of statusCounts) {
            stats[count.status] = count._count.status;
            stats.total += count._count.status;
        }
        return stats;
    }
    async manualRetry(messageId) {
        const message = await this.prisma.deadLetterQueue.findUnique({
            where: { id: messageId }
        });
        if (!message) {
            throw new Error(`Dead letter queue message not found: ${messageId}`);
        }
        const dlqMessage = {
            id: message.id,
            operationType: message.operationType,
            payload: JSON.parse(message.payload),
            originalError: message.originalError,
            attempts: message.attempts,
            maxAttempts: message.maxAttempts,
            firstFailedAt: message.firstFailedAt,
            lastAttemptAt: message.lastAttemptAt,
            nextRetryAt: message.nextRetryAt || undefined,
            status: message.status,
            priority: message.priority,
            metadata: message.metadata ? JSON.parse(message.metadata) : undefined
        };
        await this.processMessage(dlqMessage);
        return true;
    }
    async cleanupResolvedMessages(olderThanDays = 7) {
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - olderThanDays);
        const result = await this.prisma.deadLetterQueue.deleteMany({
            where: {
                status: 'resolved',
                lastAttemptAt: {
                    lt: cutoffDate
                }
            }
        });
        await this.loggingService.logEvent('info', `Cleaned up ${result.count} resolved dead letter queue messages`);
        return result.count;
    }
}
exports.DeadLetterQueueService = DeadLetterQueueService;
//# sourceMappingURL=DeadLetterQueueService.js.map