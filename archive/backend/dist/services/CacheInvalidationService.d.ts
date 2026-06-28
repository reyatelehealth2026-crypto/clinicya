import { CacheService } from './CacheService';
export interface CacheInvalidationEvent {
    type: 'order_updated' | 'payment_processed' | 'webhook_received' | 'customer_updated';
    resourceId: string;
    resourceType: string;
    affectedCacheKeys: string[];
    timestamp: Date;
    metadata?: Record<string, any>;
}
export declare class CacheInvalidationService {
    private cacheService;
    private eventHandlers;
    constructor(cacheService: CacheService);
    invalidate(event: CacheInvalidationEvent): Promise<void>;
    onEvent(eventType: CacheInvalidationEvent['type'], handler: (event: CacheInvalidationEvent) => Promise<void>): void;
    private setupDefaultHandlers;
    static createOrderUpdateEvent(orderId: string, customerId?: string, metadata?: Record<string, any>): CacheInvalidationEvent;
    static createPaymentProcessedEvent(paymentId: string, orderId?: string, metadata?: Record<string, any>): CacheInvalidationEvent;
    static createWebhookReceivedEvent(webhookId: string, metadata?: Record<string, any>): CacheInvalidationEvent;
    static createCustomerUpdatedEvent(customerId: string, metadata?: Record<string, any>): CacheInvalidationEvent;
}
//# sourceMappingURL=CacheInvalidationService.d.ts.map