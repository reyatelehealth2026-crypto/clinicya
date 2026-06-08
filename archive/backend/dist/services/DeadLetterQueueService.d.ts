import { PrismaClient } from '@prisma/client';
import { LoggingService } from './LoggingService.js';
import { NotificationService } from './NotificationService.js';
export interface DeadLetterMessage {
    id: string;
    operationType: string;
    payload: Record<string, any>;
    originalError: string;
    attempts: number;
    maxAttempts: number;
    firstFailedAt: Date;
    lastAttemptAt: Date;
    nextRetryAt?: Date;
    status: 'pending' | 'processing' | 'failed' | 'resolved';
    priority: 'low' | 'medium' | 'high' | 'critical';
    metadata?: Record<string, any>;
}
export interface DLQConfig {
    maxRetries: number;
    retryDelayMs: number;
    batchSize: number;
    processingIntervalMs: number;
    alertThreshold: number;
}
export declare class DeadLetterQueueService {
    private prisma;
    private loggingService;
    private notificationService;
    private config;
    private processingInterval?;
    private isProcessing;
    constructor(prisma: PrismaClient, loggingService: LoggingService, notificationService: NotificationService, config?: Partial<DLQConfig>);
    start(): void;
    stop(): void;
    addToQueue(operationType: string, payload: Record<string, any>, error: Error, attempts: number, maxAttempts: number, priority?: DeadLetterMessage['priority'], metadata?: Record<string, any>): Promise<string>;
    private processQueue;
    private getPendingMessages;
    private processMessage;
    private retryOperation;
    private retryWebhookDelivery;
    private retryPaymentProcessing;
    private retryNotificationSend;
    private retryDataSync;
    private updateMessageStatus;
    private checkAlertThreshold;
    getQueueStatistics(): Promise<{
        pending: number;
        processing: number;
        failed: number;
        resolved: number;
        total: number;
        oldestPending?: Date;
        averageRetryTime?: number;
    }>;
    manualRetry(messageId: string): Promise<boolean>;
    cleanupResolvedMessages(olderThanDays?: number): Promise<number>;
}
//# sourceMappingURL=DeadLetterQueueService.d.ts.map